<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// Validate CSRF state
if (empty($_GET['state']) || empty($_SESSION['outlook_oauth_state']) || !hash_equals($_SESSION['outlook_oauth_state'], $_GET['state'])) {
    flash_alert("Invalid OAuth state. Please try connecting again.", 'error');
    header("Location: /agent/user/user_integrations.php");
    exit;
}
unset($_SESSION['outlook_oauth_state']);

if (!empty($_GET['error'])) {
    $desc = htmlspecialchars($_GET['error_description'] ?? $_GET['error'], ENT_QUOTES, 'UTF-8');
    flash_alert("Microsoft returned an error: $desc", 'error');
    header("Location: /agent/user/user_integrations.php");
    exit;
}

if (empty($_GET['code'])) {
    flash_alert("No authorization code received from Microsoft.", 'error');
    header("Location: /agent/user/user_integrations.php");
    exit;
}

$callback_url = "https://$config_base_url/agent/outlook_calendar_callback.php";

// Exchange authorization code for tokens
$ch = curl_init("https://login.microsoftonline.com/" . rawurlencode($config_outlook_cal_tenant_id) . "/oauth2/v2.0/token");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => $config_outlook_cal_client_id,
        'client_secret' => $config_outlook_cal_client_secret,
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => $callback_url,
        'scope'         => 'Calendars.ReadWrite offline_access',
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($response['refresh_token'])) {
    $err = $response['error_description'] ?? 'No refresh token returned';
    flash_alert("Failed to connect Outlook: $err", 'error');
    header("Location: /agent/user/user_integrations.php");
    exit;
}

// Encrypted with the site's canonical vault key (not the current session's key)
// since a token stored here is later read by whichever session is saving a
// ticket appointment for this user, not necessarily this user's own session.
$canonical_key = getCanonicalVaultKey($mysqli);
$access_token  = mysqli_real_escape_string($mysqli, encryptCredentialEntryWithKey($response['access_token'], $canonical_key));
$refresh_token = mysqli_real_escape_string($mysqli, encryptCredentialEntryWithKey($response['refresh_token'], $canonical_key));
$expires_at    = date('Y-m-d H:i:s', time() + intval($response['expires_in'] ?? 3600));

mysqli_query($mysqli, "UPDATE users SET
    user_outlook_access_token  = '$access_token',
    user_outlook_refresh_token = '$refresh_token',
    user_outlook_token_expires = '$expires_at'
    WHERE user_id = $session_user_id");

logAction("User", "Edit", "Connected Outlook Calendar");
flash_alert("Outlook Calendar connected successfully. Scheduled tickets will now sync automatically.");
header("Location: /agent/user/user_integrations.php");
exit;
