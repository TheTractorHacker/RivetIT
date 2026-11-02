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

// The switches for the risky rule actions (both OFF by default) and how early certificate.expiring is raised. Administrators only.
if (isset($_POST['set_automation_extras'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    $cols = mysqli_query($mysqli, "SHOW COLUMNS FROM settings LIKE 'config_automation_cert_days'");
    if (!$cols || mysqli_num_rows($cols) === 0) {
        flash_alert('Run the database update first.', 'error');
        redirect('automation.php');
    }
    $run_script = isset($_POST['automation_run_script_enabled']) ? 1 : 0;
    $odoo_user = isset($_POST['automation_odoo_user_enabled']) ? 1 : 0;
    $cert_days = max(1, min(365, intval($_POST['automation_cert_days'] ?? 30)));
    mysqli_query($mysqli, "UPDATE settings SET config_automation_run_script_enabled = $run_script, config_automation_odoo_user_enabled = $odoo_user, config_automation_cert_days = $cert_days WHERE company_id = 1");
    logAction('Automation', 'Edit', "$session_name set automation safety switches: run scripts " . ($run_script ? 'on' : 'off') . ', Odoo users ' . ($odoo_user ? 'on' : 'off') . ", certificate warning $cert_days days");
    rivetAudit('settings.automation_switches_changed', (int) $session_user_id, 'settings', 'config_automation_run_script_enabled', 'update', 'Automation action switches changed', ['run_script' => $run_script, 'odoo_user' => $odoo_user, 'cert_days' => $cert_days]);
    flash_alert('Automation action switches saved.');
    redirect('automation.php');
}

// Import legacy ticket rules as (disabled) event rules where an equivalent exists. The legacy rules are never changed.
if (isset($_POST['import_legacy_rules'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    $ids = array_map('intval', (array) ($_POST['legacy_ids'] ?? []));
    $engine = new \ITFlow\Automation\RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli));
    $made = 0;
    $skipped = [];
    foreach (\ITFlow\Automation\LegacyRuleImporter::import($mysqli, $engine, $ids) as $r) {
        if ($r['ok']) {
            $made++;
            logAction('Automation', 'Create', "$session_name imported legacy ticket rule #{$r['legacy_id']} as event rule #{$r['rule_id']} (switched off)");
            rivetAudit('automation.rule_created', (int) $session_user_id, 'automation_rule', $r['rule_id'], 'create', 'Event rule imported from legacy ticket rule #' . $r['legacy_id'], ['legacy_rule_id' => $r['legacy_id']]);
        } else {
            $skipped[] = '#' . $r['legacy_id'] . ': ' . $r['message'];
        }
    }
    flash_alert($made . ' rule' . ($made === 1 ? '' : 's') . ' imported as event rules (switched off; review and turn them on, then turn the old rule off).' . ($skipped ? ' Not imported: ' . implode(' ', array_map('htmlspecialchars', $skipped)) : ''), $made ? 'success' : 'error');
    redirect('automation.php?tab=ticket');
}

// Run a failed rule run again from the Run log.
if (isset($_POST['rerun_automation_run'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();

    $run_id = intval($_POST['run_id'] ?? 0);
    $engine = new \ITFlow\Automation\RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli));
    try {
        $res = $engine->rerun($run_id, (int) $session_user_id);
        logAction('Automation', 'Edit', "$session_name ran event rule run #$run_id again: {$res['status']}");
        flash_alert('Run #' . $run_id . ' repeated: ' . htmlspecialchars($res['status']) . ' - ' . htmlspecialchars($res['message']), $res['status'] === 'ok' ? 'success' : 'error');
    } catch (\InvalidArgumentException $e) {
        flash_alert(htmlspecialchars($e->getMessage()), 'error');
    }
    redirect('automation.php?tab=log');
}
