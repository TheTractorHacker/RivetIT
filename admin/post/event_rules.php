<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use RivetCore\Automation\AutomationRuleStore;

require_once __DIR__ . '/../../includes/event_bus.php';

$event_rules_store = static function () use ($mysqli): ?AutomationRuleStore {
    return class_exists(AutomationRuleStore::class) && rivetTableExists($mysqli, 'automation_rules') ? new AutomationRuleStore(rivetCoreDb($mysqli)) : null;
};

if (isset($_POST['save_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $store = $event_rules_store();
    if (!$store) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $conditions = [];
    $fields = (array) ($_POST['cond_field'] ?? []);
    $values = (array) ($_POST['cond_value'] ?? []);
    foreach ($fields as $i => $f) {
        $f = trim((string) $f);
        if ($f !== '') {
            $conditions[$f] = (string) ($values[$i] ?? '');
        }
    }
    $config = [
        'subject' => $_POST['cfg_subject'] ?? '', 'details' => $_POST['cfg_details'] ?? '', 'priority' => $_POST['cfg_priority'] ?? 'Low',
        'url' => $_POST['cfg_url'] ?? '', 'secret' => $_POST['cfg_secret'] ?? '', 'message' => $_POST['cfg_message'] ?? '',
    ];
    $id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : null;
    if (($_POST['action_type'] ?? '') === 'send_webhook' && !rivetWebhookUrlIsSafe((string) ($_POST['cfg_url'] ?? ''))) {
        flash_alert('The webhook URL must be an http(s) address that resolves to a public address.', 'error');
        redirect($id ? "event_rules.php?edit=$id" : 'event_rules.php');
    }
    try {
        $saved = $store->save($id ?: null, (string) ($_POST['rule_name'] ?? ''), (string) ($_POST['trigger_event'] ?? ''), $conditions, (string) ($_POST['action_type'] ?? ''), $config, isset($_POST['is_enabled']));
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect($id ? "event_rules.php?edit=$id" : 'event_rules.php');
    }
    logAction('Automation', $id ? 'Edit' : 'Create', "$session_name " . ($id ? 'edited' : 'created') . " event rule $saved");
    rivetAudit($id ? 'automation.rule_updated' : 'automation.rule_created', (int) $session_user_id, 'automation_rule', $saved, $id ? 'update' : 'create', 'Event rule ' . ($id ? 'updated' : 'created'));
    flash_alert('Rule saved.');
    redirect('event_rules.php');
}

if (isset($_POST['toggle_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();
    if ($store = $event_rules_store()) {
        $id = intval($_POST['rule_id'] ?? 0);
        $rule = $store->find($id);
        if ($rule) {
            $store->setEnabled($id, !$rule['is_enabled']);
            logAction('Automation', 'Edit', "$session_name turned event rule $id " . ($rule['is_enabled'] ? 'off' : 'on'));
            rivetAudit('automation.rule_toggled', (int) $session_user_id, 'automation_rule', $id, 'update', 'Event rule turned ' . ($rule['is_enabled'] ? 'off' : 'on'));
        }
    }
    redirect('event_rules.php');
}

if (isset($_GET['delete_event_rule'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    validateAdminRole();
    if ($store = $event_rules_store()) {
        $id = intval($_GET['delete_event_rule']);
        $store->delete($id);
        logAction('Automation', 'Delete', "$session_name deleted event rule $id");
        rivetAudit('automation.rule_deleted', (int) $session_user_id, 'automation_rule', $id, 'delete', 'Event rule deleted');
    }
    flash_alert('Rule deleted.');
    redirect('event_rules.php');
}
