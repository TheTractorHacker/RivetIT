<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleEngine;

require_once __DIR__ . '/../../includes/event_bus.php';

$event_rules_engine = static function () use ($mysqli): ?RuleEngine {
    return class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules')
        ? new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli)) : null;
};

/** The raw form values of the chosen action, in the shape each action's validate() expects. */
$event_rules_action_input = static function (string $action): array {
    $p = $_POST;
    switch ($action) {
        case 'create_ticket':
            return ['subject' => $p['cfg_subject'] ?? '', 'details' => $p['cfg_details'] ?? '', 'priority' => $p['cfg_priority'] ?? 'Low'];
        case 'send_webhook':
            return ['url' => $p['cfg_url'] ?? '', 'secret' => $p['cfg_secret'] ?? ''];
        case 'notify_user':
            return ['message' => $p['cfg_message'] ?? ''];
        case 'start_workflow':
            return ['template_id' => intval($p['cfg_template_id'] ?? 0)];
        case 'set_ticket_field':
            return ['status' => $p['cfg_sf_status'] ?? '', 'priority' => $p['cfg_sf_priority'] ?? '', 'category_id' => intval($p['cfg_sf_category'] ?? 0), 'assignee' => $p['cfg_sf_assignee'] ?? ''];
        case 'add_ticket_note':
            return ['note' => $p['cfg_note'] ?? ''];
        case 'assign_ticket':
            return ['mode' => $p['cfg_as_mode'] ?? 'user', 'user_id' => intval($p['cfg_as_user'] ?? 0), 'pool' => is_array($p['cfg_as_pool'] ?? null) ? $p['cfg_as_pool'] : []];
        case 'send_mail':
            return ['to_type' => $p['cfg_mail_to'] ?? '', 'address' => $p['cfg_mail_address'] ?? '', 'user_id' => intval($p['cfg_mail_user'] ?? 0), 'subject' => $p['cfg_mail_subject'] ?? '', 'body' => $p['cfg_mail_body'] ?? ''];
        case 'create_task':
            return ['name' => $p['cfg_task_name'] ?? '', 'assignee_id' => intval($p['cfg_task_assignee'] ?? 0), 'due_days' => intval($p['cfg_task_due_days'] ?? 0)];
    }

    return [];
};

if (isset($_POST['save_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    $engine = $event_rules_engine();
    if (!$engine) {
        flash_alert('Run the database update first.', 'error');
        redirect();
    }
    $id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : null;
    $back = $id ? "event_rules.php?edit=$id" : 'event_rules.php';
    // Condition rows. A form from before the builder existed (field + value only) still works: the operator defaults to "equals".
    $rows = [];
    $fields = (array) ($_POST['cond_field'] ?? []);
    $ops = (array) ($_POST['cond_op'] ?? []);
    $values = (array) ($_POST['cond_value'] ?? []);
    $groups = (array) ($_POST['cond_group'] ?? []);
    foreach ($fields as $i => $f) {
        $g = (string) ($groups[$i] ?? '');
        $rows[] = ['field' => is_scalar($f) ? (string) $f : '', 'op' => is_scalar($ops[$i] ?? 'eq') ? (string) ($ops[$i] ?? 'eq') : 'eq', 'value' => is_scalar($values[$i] ?? '') ? (string) ($values[$i] ?? '') : '', 'group' => in_array($g, ['A', 'B', 'C'], true) ? $g : ''];
    }
    try {
        $model = ConditionEvaluator::fromForm((string) ($_POST['cond_mode'] ?? 'all'), $rows, array_map('strval', (array) ($_POST['group_mode'] ?? [])));
        $action = (string) ($_POST['action_type'] ?? '');
        $saved = $engine->save($id ?: null, (string) ($_POST['rule_name'] ?? ''), (string) ($_POST['trigger_event'] ?? ''), $model, $action, $event_rules_action_input($action), isset($_POST['is_enabled']),
            intval($_POST['rule_priority'] ?? 100), isset($_POST['stop_on_match']), intval($_POST['rate_limit'] ?? RuleEngine::DEFAULT_RATE));
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect($back);
    }
    logAction('Automation', $id ? 'Edit' : 'Create', "$session_name " . ($id ? 'edited' : 'created') . " event rule $saved");
    rivetAudit($id ? 'automation.rule_updated' : 'automation.rule_created', (int) $session_user_id, 'automation_rule', $saved, $id ? 'update' : 'create', 'Event rule ' . ($id ? 'updated' : 'created'));
    flash_alert('Rule saved.');
    redirect('event_rules.php');
}

if (isset($_POST['toggle_event_rule'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole();
    if ($engine = $event_rules_engine()) {
        $id = intval($_POST['rule_id'] ?? 0);
        $rule = $engine->find($id);
        if ($rule) {
            mysqli_query($mysqli, 'UPDATE automation_rules SET is_enabled = ' . ($rule['is_enabled'] ? 0 : 1) . ' WHERE rule_id = ' . $id);
            logAction('Automation', 'Edit', "$session_name turned event rule $id " . ($rule['is_enabled'] ? 'off' : 'on'));
            rivetAudit('automation.rule_toggled', (int) $session_user_id, 'automation_rule', $id, 'update', 'Event rule turned ' . ($rule['is_enabled'] ? 'off' : 'on'));
        }
    }
    redirect('event_rules.php');
}

if (isset($_GET['delete_event_rule'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    validateAdminRole();
    if ($engine = $event_rules_engine()) {
        $id = intval($_GET['delete_event_rule']);
        mysqli_query($mysqli, 'DELETE FROM automation_rules WHERE rule_id = ' . $id);
        logAction('Automation', 'Delete', "$session_name deleted event rule $id");
        rivetAudit('automation.rule_deleted', (int) $session_user_id, 'automation_rule', $id, 'delete', 'Event rule deleted');
    }
    flash_alert('Rule deleted.');
    redirect('event_rules.php');
}
