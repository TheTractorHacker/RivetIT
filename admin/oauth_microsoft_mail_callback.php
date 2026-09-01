<?php

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$settings_mail_path = '/admin/settings_mail.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    flash_alert("Admin access required.", 'error');
    redirect($settings_mail_path);
}

$state = sanitizeInput($_GET['state'] ?? '');
$code = $_GET['code'] ?? '';
$error = sanitizeInput($_GET['error'] ?? '');
$error_description = sanitizeInput($_GET['error_description'] ?? '');

$session_state = $_SESSION['mail_oauth_state'] ?? '';
$session_state_expires = intval($_SESSION['mail_oauth_state_expires_at'] ?? 0);
// Multi-mailbox support: if the connect flow was started from Admin > Mailboxes
// (oauth_connect_mailbox_microsoft), this holds which mailbox row to store tokens on.
$mail_oauth_mailbox_id = intval($_SESSION['mail_oauth_mailbox_id'] ?? 0);
// Set by whichever flow started the redirect (admin/post/mailbox.php uses Microsoft Graph;
// admin/post/settings_mail.php's legacy flow still uses the Office 365 Exchange Online API) -
// the token exchange below must request the SAME scope that was actually consented to.
$mail_oauth_scope = (string) ($_SESSION['mail_oauth_scope'] ?? '');

unset($_SESSION['mail_oauth_state'], $_SESSION['mail_oauth_state_expires_at'], $_SESSION['mail_oauth_mailbox_id'], $_SESSION['mail_oauth_scope']);

if (!empty($error)) {
    $msg = "Microsoft OAuth authorization failed: $error";
    if (!empty($error_description)) {
        $msg .= " ($error_description)";
    }

    flash_alert($msg, 'error');
    redirect($settings_mail_path);
}

if (empty($state) || empty($code) || empty($session_state) || !hash_equals($session_state, $state) || time() > $session_state_expires) {
    flash_alert("Microsoft OAuth callback validation failed. Please try connecting again.", 'error');
    redirect($settings_mail_path);
}

if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($config_mail_oauth_tenant_id)) {
    flash_alert("Microsoft OAuth settings are incomplete. Please fill Client ID, Client Secret, and Tenant ID.", 'error');
    redirect($settings_mail_path);
}

if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}

$redirect_uri = $base_url . '/admin/oauth_microsoft_mail_callback.php';
$token_url = 'https://login.microsoftonline.com/' . rawurlencode($config_mail_oauth_tenant_id) . '/oauth2/v2.0/token';
// Fall back to the legacy scope for old bookmarked/in-flight links that predate mail_oauth_scope.
$scope = $mail_oauth_scope !== '' ? $mail_oauth_scope : 'offline_access openid profile https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send';

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id' => $config_mail_oauth_client_id,
    'client_secret' => $config_mail_oauth_client_secret,
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'scope' => $scope,
], '', '&'));
curl_setopt($ch, CURLOPT_TIMEOUT, 20);

$raw_body = curl_exec($ch);
$curl_err = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($raw_body === false || $http_code < 200 || $http_code >= 300) {
    $reason = !empty($curl_err) ? $curl_err : "HTTP $http_code";
    flash_alert("Microsoft OAuth token exchange failed: $reason", 'error');
    redirect($settings_mail_path);
}

$json = json_decode($raw_body, true);
if (!is_array($json) || empty($json['refresh_token']) || empty($json['access_token'])) {
    flash_alert("Microsoft OAuth token exchange failed: refresh token or access token missing.", 'error');
    redirect($settings_mail_path);
}

$refresh_token = (string) $json['refresh_token'];
$access_token = (string) $json['access_token'];
$expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

$refresh_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($refresh_token));
$access_token_esc = mysqli_real_escape_string($mysqli, encryptSetting($access_token));
$expires_at_esc = mysqli_real_escape_string($mysqli, $expires_at);

// Multi-mailbox support: if this flow was started from Admin > Mailboxes, store the
// tokens on that mailbox row instead of the legacy global settings row.
if (!empty($mail_oauth_mailbox_id)) {
    $mailbox_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT mailbox_id, mailbox_name FROM mailboxes WHERE mailbox_id = $mail_oauth_mailbox_id LIMIT 1"));

    if (!$mailbox_row) {
        flash_alert("Microsoft OAuth connected, but the mailbox could not be found to store the token.", 'error');
        redirect('/admin/mailbox.php');
    }

    mysqli_query($mysqli, "UPDATE mailboxes SET
        mailbox_type = 'microsoft_oauth',
        mailbox_oauth_refresh_token_enc = '$refresh_token_esc',
        mailbox_oauth_access_token_enc = '$access_token_esc',
        mailbox_oauth_access_token_expires_at = '$expires_at_esc'
        WHERE mailbox_id = $mail_oauth_mailbox_id
    ");

    logAction("Mailbox", "Edit", "$session_name completed Microsoft OAuth connect flow for mailbox {$mailbox_row['mailbox_name']}");
    flash_alert("Microsoft OAuth connected successfully for mailbox <strong>" . nullable_htmlentities($mailbox_row['mailbox_name']) . "</strong>. Token expires at $expires_at.");
    redirect('/admin/mailbox.php');
}

mysqli_query($mysqli, "UPDATE settings SET
    config_imap_provider = 'microsoft_oauth',
    config_smtp_provider = 'microsoft_oauth',
    config_mail_oauth_refresh_token = '$refresh_token_esc',
    config_mail_oauth_access_token = '$access_token_esc',
    config_mail_oauth_access_token_expires_at = '$expires_at_esc'
    WHERE company_id = 1
");

logAction("Settings", "Edit", "$session_name completed Microsoft OAuth connect flow for mail settings");
flash_alert("Microsoft OAuth connected successfully. Token expires at $expires_at.");
redirect($settings_mail_path);
