<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/event_bus.php';

// The "Enable automation rules" switch (Administration > Automation). Reads and writes the same place the event bus reads.
if (isset($_POST['set_automation_enabled'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    if (class_exists('\RivetMSP\Core\CoreBridge')) {
        flash_alert('Automation is switched with the RivetMSP Core settings in this edition.', 'error');
        redirect('automation.php');
    }
    $on = isset($_POST['automation_enabled']);
    \ITFlow\Automation\RuleEngine::setSwitch($mysqli, $on);
    logAction('Automation', 'Edit', "$session_name turned automation rules " . ($on ? 'on' : 'off'));
    rivetAudit('settings.automation_enabled_changed', (int) $session_user_id, 'settings', 'config_automation_enabled', 'update', 'Automation rules turned ' . ($on ? 'on' : 'off'));
    flash_alert('Automation rules are now ' . ($on ? 'on' : 'off') . '.');
    redirect('automation.php');
}
