<?php
/* Department Portal Odoo sign-in: browser state + PKCE, server-to-server exchange. */
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/odoo_portal.php';

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https') {
        http_response_code(400);
        exit('HTTPS is required for single sign-on.');
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

$portal = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT config_client_portal_enable FROM settings WHERE company_id = 1 LIMIT 1")) ?: [];
$odoo = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT odoo_integration_id, base_url, database_name, enabled, sso_enabled,
        sso_client_id, sso_secret_enc, sso_company_id
     FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1")) ?: [];
$issuer = portalOdooBaseUrl((string) ($odoo['base_url'] ?? ''));
$clientId = trim((string) ($odoo['sso_client_id'] ?? ''));
$companyId = (int) ($odoo['sso_company_id'] ?? 0);
$enabled = (int) ($portal['config_client_portal_enable'] ?? 0) === 1
    && (int) ($odoo['enabled'] ?? 0) === 1
    && (int) ($odoo['sso_enabled'] ?? 0) === 1
    && $issuer !== null && $clientId !== '' && $companyId > 0
    && !empty($odoo['database_name']) && !empty($odoo['sso_secret_enc']);
$callback = 'https://' . $config_base_url . '/client/login_odoo.php';
$pending = null;

try {
    if (!$enabled) {
        throw new RuntimeException('disabled');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new RuntimeException('invalid_request');
    }
    if (!isset($_GET['code']) && !isset($_GET['state']) && !isset($_GET['error'])) {
        if ($_GET !== []) {
            throw new RuntimeException('invalid_request');
        }
        $state = portalOdooBase64Url(random_bytes(32));
        $verifier = portalOdooBase64Url(random_bytes(32));
        $_SESSION['portal_odoo_pending'] = [
            'state' => $state, 'verifier' => $verifier,
            'created' => time(), 'issuer' => $issuer,
            'integration_id' => (int) $odoo['odoo_integration_id'],
        ];
        $query = http_build_query([
            'client_id' => $clientId, 'redirect_uri' => $callback,
            'state' => $state,
            'code_challenge' => portalOdooBase64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $issuer . '/rivetit/sso/authorize?' . $query, true, 303);
        exit;
    }

    // Consume the browser transaction before contacting Odoo. A callback from
    // a different browser or a replay has no usable pending verifier.
    $pending = $_SESSION['portal_odoo_pending'] ?? null;
    unset($_SESSION['portal_odoo_pending']);
    $state = $_GET['state'] ?? null;
    $code = $_GET['code'] ?? null;
    if (!portalOdooPendingValid($pending, $state, $issuer,
        (int) $odoo['odoo_integration_id'], time())) {
        throw new RuntimeException('browser_binding');
    }
    if (isset($_GET['error']) || !is_string($code) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $code)) {
        throw new RuntimeException('provider_denied');
    }
    $secret = decryptSetting((string) $odoo['sso_secret_enc']);
    if (strlen($secret) < 32) {
        throw new RuntimeException('invalid_config');
    }
    $identity = portalOdooTokenExchange($issuer, (string) $odoo['database_name'], $clientId, $secret, $code,
        (string) $pending['verifier'], $callback);
    if (!portalOdooIdentityValid($identity, $issuer, (string) $odoo['database_name'], $companyId)) {
        throw new RuntimeException('identity_mismatch');
    }
    $account = portalOdooEligibleAccount($mysqli, (int) $odoo['odoo_integration_id'],
        $identity['employee_id']);
    if ($account === null) {
        throw new RuntimeException('account_ineligible');
    }

    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['client_logged_in'] = true;
    $_SESSION['client_id'] = (int) $account['contact_client_id'];
    $_SESSION['user_id'] = (int) $account['user_id'];
    $_SESSION['user_type'] = 2;
    $_SESSION['logged'] = true;
    $_SESSION['contact_id'] = (int) $account['contact_id'];
    $_SESSION['csrf_token'] = randomString(32);
    $_SESSION['login_method'] = 'odoo';
    $session_user_id = (int) $account['user_id'];
    $session_ip = sanitizeInput(getIP());
    $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');
    $correlationId = substr(hash('sha256', (string) $pending['state']), 0, 16);
    logAction('Client Login', 'Success', 'Department contact signed in through Odoo (' . $correlationId . ')',
        (int) $account['contact_client_id'], $session_user_id);
    header('Location: /client/', true, 303);
    exit;
} catch (Throwable $error) {
    unset($_SESSION['portal_odoo_pending']);
    $session_user_id = 0;
    $session_ip = sanitizeInput(getIP());
    $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');
    $correlationId = is_array($pending) && is_string($pending['state'] ?? null)
        ? substr(hash('sha256', $pending['state']), 0, 16) : 'none';
    $reason = $error instanceof RuntimeException ? $error->getMessage() : 'validation_failed';
    $safeReasons = ['disabled', 'invalid_request', 'browser_binding', 'provider_denied',
        'invalid_config', 'provider_unavailable', 'provider_rejected', 'invalid_provider_response',
        'identity_mismatch', 'account_ineligible'];
    logAction('Client Login', 'Failed', 'Odoo sign-in failed (' . $correlationId . '): '
        . (in_array($reason, $safeReasons, true) ? $reason : 'provider_or_validation_error'));
    $_SESSION['odoo_login_error'] = match ($reason) {
        'browser_binding', 'provider_denied', 'provider_rejected' =>
            'Odoo sign-in expired or was interrupted. Start again from Odoo in this browser.',
        'account_ineligible' =>
            'No active Department Portal login is linked to this Odoo employee. Contact your administrator.',
        'disabled', 'invalid_config', 'identity_mismatch' =>
            'Odoo sign-in is not ready for this account. Contact your administrator.',
        default => 'Odoo sign-in could not complete. Please retry or contact your administrator.',
    };
    header('Location: /login.php', true, 303);
    exit;
}
