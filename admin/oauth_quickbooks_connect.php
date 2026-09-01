<?php

/*
 * ITFlow - Start the QuickBooks Online OAuth2 connect flow.
 *
 * Builds the Intuit authorize URL and redirects the admin to it. Mirrors the
 * session state+expiry pattern used by oauth_microsoft_mail_callback.php.
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

$integration = getAccountingIntegration($mysqli);
if (!$integration || empty($integration['accounting_client_id'])) {
    flash_alert("Enter and save your QuickBooks Client ID and Client Secret before connecting.", 'error');
    redirect($settings_accounting_path);
}

$client_id = (string) $integration['accounting_client_id'];

// Base URL for the callback (prefer the BASE_URL constant if defined).
if (defined('BASE_URL') && !empty(BASE_URL)) {
    $base_url = rtrim((string) BASE_URL, '/');
} else {
    $base_url = 'https://' . rtrim((string) $config_base_url, '/');
}
$redirect_uri = $base_url . '/admin/oauth_quickbooks_callback.php';

// CSRF-style state token with a short expiry, validated in the callback.
$state = bin2hex(random_bytes(20));
$_SESSION['qbo_oauth_state'] = $state;
$_SESSION['qbo_oauth_state_expires_at'] = time() + 600; // 10 minutes

$authorize_url = 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query([
    'client_id'     => $client_id,
    'response_type' => 'code',
    'scope'         => 'com.intuit.quickbooks.accounting',
    'redirect_uri'  => $redirect_uri,
    'state'         => $state,
], '', '&');

redirect($authorize_url);
