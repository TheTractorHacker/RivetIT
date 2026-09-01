<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_module_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_module_enable_itdoc = intval($_POST['config_module_enable_itdoc'] ?? 0);
    $config_module_enable_ticketing = intval($_POST['config_module_enable_ticketing'] ?? 0);
    $config_module_enable_accounting = intval($_POST['config_module_enable_accounting'] ?? 0);
    $config_module_enable_ticket_charges = intval($_POST['config_module_enable_ticket_charges'] ?? 0);
    $config_module_enable_kb = intval($_POST['config_module_enable_kb'] ?? 0);
    $config_module_enable_live_chat = intval($_POST['config_module_enable_live_chat'] ?? 0);
    $config_module_enable_payroll = intval($_POST['config_module_enable_payroll'] ?? 0);
    $config_client_portal_enable = intval($_POST['config_client_portal_enable'] ?? 0);

    mysqli_query($mysqli,"UPDATE settings SET config_module_enable_itdoc = $config_module_enable_itdoc, config_module_enable_ticketing = $config_module_enable_ticketing, config_module_enable_accounting = $config_module_enable_accounting, config_module_enable_ticket_charges = $config_module_enable_ticket_charges, config_module_enable_kb = $config_module_enable_kb, config_module_enable_live_chat = $config_module_enable_live_chat, config_module_enable_payroll = $config_module_enable_payroll, config_client_portal_enable = $config_client_portal_enable WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited module settings");

    flash_alert("Module Settings updated");

    redirect();

}
