<?php

/*
 * RivetIT - POST handler for Administration > Single sign-on provider (OpenID Connect provider for the Quality system, issue #55).
 *
 * Reached through admin/post.php, which loads it only for administrators and only when the Referer is admin/sso_provider.php.
 * Every action: CSRF check, admin check, audit event. Nothing here ever handles or shows a secret: client secrets live on the Integration
 * clients page, and the signing key's private half never leaves the server.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integration\IntegrationAuth;
use ITFlow\Oidc\KeyStore;
use ITFlow\Oidc\OidcAudit;
use ITFlow\Oidc\OidcConfig;

$sso_admin_only = static function () use ($session_is_admin) {
    if (empty($session_is_admin)) {
        flash_alert('Administrators only.', 'danger');
        redirect('/admin/sso_provider.php');
    }
};

if (isset($_POST['save_sso_settings'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $sso_admin_only();

    $enabled = !empty($_POST['enabled']);
    $age = intval($_POST['max_auth_age'] ?? 0);
    $idTtl = intval($_POST['id_token_ttl'] ?? 0);
    $atTtl = intval($_POST['access_token_ttl'] ?? 0);
    if ($age < OidcConfig::MAX_AGE_MIN || $age > OidcConfig::MAX_AGE_MAX
        || $idTtl < OidcConfig::ID_TOKEN_TTL_MIN || $idTtl > OidcConfig::ID_TOKEN_TTL_MAX
        || $atTtl < OidcConfig::ACCESS_TOKEN_TTL_MIN || $atTtl > OidcConfig::ACCESS_TOKEN_TTL_MAX) {
        flash_alert('One of the numbers is outside its allowed range. Nothing was saved.', 'danger');
        redirect('/admin/sso_provider.php');
    }
    if ($enabled && OidcConfig::issuer() === null) {
        flash_alert('The server base URL is not set, so single sign-on cannot be switched on.', 'danger');
        redirect('/admin/sso_provider.php');
    }
    OidcConfig::saveSettings($mysqli, $enabled, $age, $idTtl, $atTtl, intval($session_user_id));
    logAction('SSO Provider', 'Settings', "$session_name set single sign-on " . ($enabled ? 'on' : 'off') . "; maximum portal sign-in age $age min, ID token $idTtl s, access token $atTtl s");
    OidcAudit::record($mysqli, 'admin', 'ok', $enabled ? 'enabled' : 'disabled', 0, 0, "by user #" . intval($session_user_id));
    flash_alert('Single sign-on settings saved. Single sign-on is <strong>' . ($enabled ? 'on' : 'off') . '</strong>.');
    redirect('/admin/sso_provider.php');
}

if (isset($_POST['save_sso_client'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $sso_admin_only();

    $cid = intval($_POST['client_id'] ?? 0);
    $st = $mysqli->prepare("SELECT client_name, client_scopes FROM integration_clients WHERE client_id = ? AND client_status = 'active'");
    $st->bind_param('i', $cid);
    $st->execute();
    $client = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$client || !in_array(IntegrationAuth::SSO_SCOPE, IntegrationAuth::parseScopes((string) $client['client_scopes']), true)) {
        flash_alert('That client is not active or does not have the sso capability.', 'danger');
        redirect('/admin/sso_provider.php');
    }

    $uris = [];
    foreach (preg_split('/\r?\n/', (string) ($_POST['redirect_uris'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $why = OidcConfig::validateRedirectUri($line);
        if ($why !== null) {
            flash_alert('A redirect URI was not accepted: ' . $why, 'danger');
            redirect('/admin/sso_provider.php');
        }
        $uris[$line] = $line;
    }
    if (count($uris) > 10) {
        flash_alert('At most 10 redirect URIs.', 'danger');
        redirect('/admin/sso_provider.php');
    }
    $login = trim((string) ($_POST['login_url'] ?? ''));
    if ($login !== '') {
        $why = OidcConfig::validateLoginUrl($login, $mysqli);
        if ($why !== null) {
            flash_alert('The login address was not accepted: ' . $why, 'danger');
            redirect('/admin/sso_provider.php');
        }
    }
    if ($uris === [] && $login !== '') {
        flash_alert('Add at least one redirect URI before setting a login address.', 'danger');
        redirect('/admin/sso_provider.php');
    }
    $profile = !empty($_POST['allow_profile']);
    OidcConfig::saveClient($mysqli, $cid, implode("\n", $uris), $login === '' ? null : $login, $profile);
    logAction('SSO Provider', 'Client Update', "$session_name updated the single sign-on registration of {$client['client_name']}: " . count($uris) . ' redirect URI(s), login address ' . ($login === '' ? 'none' : 'set') . ', display name ' . ($profile ? 'shared' : 'not shared'), 0, $cid);
    OidcAudit::record($mysqli, 'admin', 'ok', 'client_registration_saved', $cid, 0, 'by user #' . intval($session_user_id));
    flash_alert('Registration saved for <strong>' . nullable_htmlentities($client['client_name']) . '</strong>.');
    redirect('/admin/sso_provider.php');
}

if (isset($_POST['rotate_sso_key'])) {
    validateCSRFToken($_POST['csrf_token'] ?? null);
    $sso_admin_only();

    try {
        $rotated = KeyStore::rotate($mysqli, intval($session_user_id));
    } catch (Throwable $e) {
        error_log('OIDC key rotation failed: ' . $e::class);
        flash_alert('The signing key could not be rotated. Nothing was changed.', 'danger');
        redirect('/admin/sso_provider.php');
    }
    logAction('SSO Provider', 'Key Rotate', "$session_name rotated the single sign-on signing key; new key id {$rotated['kid']}, previous key(s) stay published for {$rotated['publish_until_seconds']} seconds");
    OidcAudit::record($mysqli, 'admin', 'ok', 'key_rotated', 0, 0, 'new kid ' . $rotated['kid'] . ' by user #' . intval($session_user_id));
    flash_alert('Signing key rotated. New key id <code>' . nullable_htmlentities($rotated['kid']) . '</code>. The old key stays published for 15 minutes.');
    redirect('/admin/sso_provider.php');
}
