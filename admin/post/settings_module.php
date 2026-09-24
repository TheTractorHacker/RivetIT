<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_module_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_module_enable_itdoc = intval($_POST['config_module_enable_itdoc'] ?? 0);
    $config_module_enable_ticketing = intval($_POST['config_module_enable_ticketing'] ?? 0);
    $config_module_enable_kb = intval($_POST['config_module_enable_kb'] ?? 0);
    $config_module_enable_live_chat = intval($_POST['config_module_enable_live_chat'] ?? 0);
    $config_client_portal_enable = intval($_POST['config_client_portal_enable'] ?? 0);

    // Training (LMS): only written once the 2.6.91 column exists ($config_training_schema_ready,
    // includes/load_global_settings.php), so saving this page on an unmigrated install still works.
    $old_training = intval($config_module_enable_training ?? 0);          // loaded from settings BEFORE the POST value
    $config_module_enable_training = intval($_POST['config_module_enable_training'] ?? 0);
    $training_sql = !empty($config_training_schema_ready) ? ", config_module_enable_training = $config_module_enable_training" : "";

    // Invoicing/Accounting, Ticket-Charges Billing, Payroll, and CRM are not
    // offered as toggles on this fork - always saved off so a stale/replayed
    // form (or the underlying settings columns from the upstream schema)
    // can't silently re-enable them.
    mysqli_query($mysqli,"UPDATE settings SET config_module_enable_itdoc = $config_module_enable_itdoc, config_module_enable_ticketing = $config_module_enable_ticketing, config_module_enable_accounting = 0, config_module_enable_ticket_charges = 0, config_module_enable_kb = $config_module_enable_kb, config_module_enable_live_chat = $config_module_enable_live_chat, config_module_enable_payroll = 0, config_module_enable_crm = 0, config_client_portal_enable = $config_client_portal_enable$training_sql WHERE company_id = 1");

    if (!empty($config_training_schema_ready) && $old_training !== $config_module_enable_training) {
        \ITFlow\Audit\AuditService::record('training.module_toggled', $session_user_id, 'settings', 1, $config_module_enable_training ? 'enabled' : 'disabled');
    }

    logAction("Settings", "Edit", "$session_name edited module settings");

    flash_alert("Module Settings updated");

    redirect();

}
