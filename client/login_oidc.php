<?php
/* Department Portal OpenID Connect sign-in (authorization code + PKCE). */

header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../includes/oidc_portal.php';

if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) !== 'https') {
        http_response_code(400);
        exit('HTTPS is required for single sign-on.');
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_client_portal_enable,
    config_oidc_enabled, config_oidc_issuer, config_oidc_client_id, config_oidc_client_secret
    FROM settings WHERE company_id = 1 LIMIT 1")) ?: [];
$issuer = trim((string) ($settings['config_oidc_issuer'] ?? ''));
$clientId = trim((string) ($settings['config_oidc_client_id'] ?? ''));
$enabled = (int) ($settings['config_client_portal_enable'] ?? 0) === 1
    && (int) ($settings['config_oidc_enabled'] ?? 0) === 1
    && $clientId !== '' && !empty($settings['config_oidc_client_secret']);
$callback = 'https://' . $config_base_url . '/client/login_oidc.php';

try {
    if (!$enabled) {
        throw new RuntimeException('disabled');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        throw new RuntimeException('invalid_request');
    }

    if (!isset($_GET['code']) && !isset($_GET['error']) && !isset($_GET['state'])) {
        $document = portalOidcDiscovery($issuer);
        $state = portalOidcBase64Url(random_bytes(32));
        $nonce = portalOidcBase64Url(random_bytes(32));
        $verifier = portalOidcBase64Url(random_bytes(32));
        $_SESSION['portal_oidc_pending'] = [
            'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
            'created' => time(), 'issuer' => $issuer,
        ];
        $query = http_build_query([
            'response_type' => 'code', 'client_id' => $clientId,
            'redirect_uri' => $callback, 'scope' => 'openid profile',
            'state' => $state, 'nonce' => $nonce,
            'code_challenge' => portalOidcBase64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $document['authorization_endpoint'] . '?' . $query);
        exit;
    }

    // Consume the browser transaction before any external network request.
    $pending = $_SESSION['portal_oidc_pending'] ?? null;
    unset($_SESSION['portal_oidc_pending']);
    $state = $_GET['state'] ?? null;
    $code = $_GET['code'] ?? null;
    if (!is_array($pending) || !is_string($state) || strlen($state) !== 43
        || !hash_equals((string) ($pending['state'] ?? ''), $state)
        || time() - (int) ($pending['created'] ?? 0) > 300
        || ($pending['issuer'] ?? null) !== $issuer) {
        throw new RuntimeException('browser_binding');
    }
    if (isset($_GET['error']) || !is_string($code) || strlen($code) < 8 || strlen($code) > 4096) {
        throw new RuntimeException('provider_denied');
    }
    $document = portalOidcDiscovery($issuer);
    $secret = decryptSetting((string) $settings['config_oidc_client_secret']);
    if ($secret === '') {
        throw new RuntimeException('invalid_config');
    }
    $method = portalOidcTokenAuthMethod($document);
    $fields = [
        'grant_type' => 'authorization_code', 'code' => $code,
        'redirect_uri' => $callback, 'client_id' => $clientId,
        'code_verifier' => $pending['verifier'],
    ];
    if ($method === 'client_secret_post') {
        $fields['client_secret'] = $secret;
    }
    $token = portalOidcHttp($document['token_endpoint'], $fields, null,
        $method === 'client_secret_basic' ? [$clientId, $secret] : null);
    if (!is_string($token['id_token'] ?? null) || !is_string($token['access_token'] ?? null)
        || !is_string($token['token_type'] ?? null)
        || strcasecmp($token['token_type'], 'Bearer') !== 0) {
        throw new RuntimeException('invalid_token_response');
    }
    $keys = portalOidcHttp($document['jwks_uri']);
    $subject = portalOidcValidateToken($token['id_token'], $keys, $issuer, $clientId,
        $pending['nonce'], $token['access_token']);
    $userinfo = portalOidcHttp($document['userinfo_endpoint'], null, $token['access_token']);
    if (($userinfo['sub'] ?? null) !== $subject) {
        throw new RuntimeException('userinfo_mismatch');
    }
    $account = portalOidcEligibleAccount($mysqli, $issuer, $subject);
    if ($account === null) {
        throw new RuntimeException('account_ineligible');
    }

    // Remove an old agent, preview or portal identity before establishing the
    // exact Department Portal session contract.
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['client_logged_in'] = true;
    $_SESSION['client_id'] = (int) $account['contact_client_id'];
    $_SESSION['user_id'] = (int) $account['user_id'];
    $_SESSION['user_type'] = 2;
    $_SESSION['logged'] = true;
    $_SESSION['contact_id'] = (int) $account['contact_id'];
    $_SESSION['csrf_token'] = randomString(32);
    $_SESSION['login_method'] = 'oidc';
    $session_user_id = (int) $account['user_id'];
    $session_ip = sanitizeInput(getIP());
    $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');
    logAction('Client Login', 'Success', 'Department contact signed in through OpenID Connect',
        (int) $account['contact_client_id'], $session_user_id);
    header('Location: /client/', true, 303);
    exit;
} catch (Throwable $error) {
    unset($_SESSION['portal_oidc_pending']);
    $session_user_id = 0;
    $session_ip = sanitizeInput(getIP());
    $session_user_agent = sanitizeInput($_SERVER['HTTP_USER_AGENT'] ?? '');
    $reason = $error instanceof RuntimeException ? $error->getMessage() : 'validation_failed';
    // Never log codes, tokens, credentials or raw provider responses.
    $safeReasons = ['disabled', 'invalid_request', 'browser_binding', 'provider_denied',
        'invalid_config', 'invalid_token_response', 'userinfo_mismatch', 'account_ineligible'];
    // Only our own fixed messages and the JWT library's key/signature/expiry messages are logged, never provider data.
    $detail = ($error instanceof RuntimeException || strpos(get_class($error), 'Firebase\\JWT\\') === 0
        || $error instanceof UnexpectedValueException || $error instanceof DomainException)
        ? substr(preg_replace('/[^A-Za-z0-9 .,:_-]/', '', $error->getMessage()), 0, 120) : '';
    logAction('Client Login', 'Failed', 'OpenID Connect sign-in failed: '
        . (in_array($reason, $safeReasons, true) ? $reason
            : 'provider_or_validation_error' . ($detail !== '' ? " ($detail)" : '')));
    $_SESSION['oidc_login_error'] = 'Single sign-on could not complete. Please try again or contact your administrator.';
    header('Location: /login.php', true, 303);
    exit;
}
