<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

if (!$config_outlook_cal_tenant_id || !$config_outlook_cal_client_id) {
    flash_alert("Outlook Calendar Sync is not configured. Ask your admin to complete the setup under Admin → Settings → Calendar Sync.", 'error');
    header("Location: /agent/user/user_integrations.php");
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['outlook_oauth_state'] = $state;

$callback_url = "https://$config_base_url/agent/outlook_calendar_callback.php";

$auth_url = "https://login.microsoftonline.com/" . rawurlencode($config_outlook_cal_tenant_id) . "/oauth2/v2.0/authorize?" . http_build_query([
    'client_id'     => $config_outlook_cal_client_id,
    'response_type' => 'code',
    'redirect_uri'  => $callback_url,
    'response_mode' => 'query',
    'scope'         => 'Calendars.ReadWrite offline_access',
    'state'         => $state,
    'prompt'        => 'select_account',
]);

header("Location: $auth_url");
exit;
