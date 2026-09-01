<?php

/*
 * ITFlow - QuickBooks Online OAuth2 callback.
 *
 * Validates the session state, exchanges the authorization code for tokens at
 * Intuit's token endpoint, captures the realmId (QBO company id) and stores the
 * encrypted tokens on the accounting_integrations row.
 *
 * Mirrors oauth_microsoft_mail_callback.php (state+expiry+hash_equals, curl
 * token exchange, encryptSetting on tokens).
 */

require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../functions.php";
require_once __DIR__ . "/../includes/check_login.php";
require_once __DIR__ . "/../includes/accounting_functions.php";

$settings_accounting_path = '/admin/settings_accounting.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect($settings_accounting_path);
}

$state    = sanitizeInput($_GET['state'] ?? '');
$code     = $_GET['code'] ?? '';
$realm_id = sanitizeInput($_GET['realmId'] ?? '');
$error    = sanitizeInput($_GET['error'] ?? '');
$error_description = sanitizeInput($_GET['error_description'] ?? '');

$session_state         = $_SESSION['qbo_oauth_state'] ?? '';
$session_state_expires = intval($_SESSION['qbo_oauth_state_expires_at'] ?? 0);
unset($_SESSION['qbo_oauth_state'], $_SESSION['qbo_oauth_state_expires_at']);

if (!empty($error)) {
    $msg = "QuickBooks OAuth authorization failed: $error";
    if (!empty($error_description)) {
        $msg .= " ($error_description)";
    }
    flash_alert($msg, 'error');
    redirect($settings_accounting_path);
}

if (empty($state) || empty($code) || empty($session_state) || !hash_equals($session_state, $state) || time() > $session_state_expires) {
    flash_alert("QuickBooks OAuth callback validation failed. Please try connecting again.", 'error');
    redirect($settings_accounting_path);
}

if (empty($realm_id)) {
    flash_alert("QuickBooks OAuth callback did not include a company (realm) id.", 'error');
    redirect($settings_accounting_path);
}

$integration = getAccountingIntegration($mysqli);
if (!$integration || empty($integration['accounting_client_id'])) {
    flash_alert("QuickBooks Client ID / Secret are not configured.", 'error');
    redirect($settings_accounting_path);
}

$accounting_id = intval($integration['accounting_id']);
$client_id     = (string) $integration['accounting_client_id'];
$client_secret = decryptSetting((string) ($integration['accounting_client_secret'] ?? ''));

if (empty($client_secret)) {
    flash_alert("QuickBooks Client Secret is not configured.", 'error');
    redirect($settings_accounting_path);
}

if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}
$redirect_uri = $base_url . '/admin/oauth_quickbooks_callback.php';

// Exchange the authorization code for tokens (HTTP Basic auth with client creds).
$ch = curl_init('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'grant_type'   => 'authorization_code',
    'code'         => $code,
    'redirect_uri' => $redirect_uri,
], '', '&'));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$raw_body  = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw_body === false || $http_code < 200 || $http_code >= 300) {
    $reason = !empty($curl_err) ? $curl_err : "HTTP $http_code";
    flash_alert("QuickBooks OAuth token exchange failed: $reason", 'error');
    redirect($settings_accounting_path);
}

$json = json_decode($raw_body, true);
if (!is_array($json) || empty($json['refresh_token']) || empty($json['access_token'])) {
    flash_alert("QuickBooks OAuth token exchange failed: refresh token or access token missing.", 'error');
    redirect($settings_accounting_path);
}

$access_token  = (string) $json['access_token'];
$refresh_token = (string) $json['refresh_token'];
$expires_at    = date('Y-m-d H:i:s', time() + intval($json['expires_in'] ?? 3600));

$realm_id_esc      = mysqli_real_escape_string($mysqli, $realm_id);
$access_token_esc  = mysqli_real_escape_string($mysqli, encryptSetting($access_token));
$refresh_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($refresh_token));
$expires_at_esc    = mysqli_real_escape_string($mysqli, $expires_at);

mysqli_query($mysqli, "UPDATE accounting_integrations SET
    accounting_provider = 'quickbooks_online',
    accounting_realm_id = '$realm_id_esc',
    accounting_access_token = '$access_token_esc',
    accounting_refresh_token = '$refresh_token_esc',
    accounting_token_expires_at = '$expires_at_esc',
    accounting_enabled = 1,
    accounting_connected_at = NOW()
    WHERE accounting_id = $accounting_id
");

accountingLog($mysqli, $accounting_id, 'integration', $accounting_id, 'connected', "Connected to QuickBooks company (realm) $realm_id");
logAction("Settings", "Edit", "$session_name connected QuickBooks Online (realm $realm_id)");
flash_alert("QuickBooks Online connected successfully. Company (realm) id: " . nullable_htmlentities($realm_id));
redirect($settings_accounting_path);
