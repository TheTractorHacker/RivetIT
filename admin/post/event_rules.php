<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Automation\RuleForm;
use ITFlow\Automation\RuleEngine;

require_once __DIR__ . '/../../includes/event_bus.php';

$event_rules_engine = static function () use ($mysqli): ?RuleEngine {
    return class_exists(RuleEngine::class) && rivetTableExists($mysqli, 'automation_rules')
        ? new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli)) : null;
};

/** Keeps what the administrator typed when a save fails, so the editor can show it again with the problems (bounded: it lives in the session). */
$event_rules_draft = static function (array $post): array {
    unset($post['csrf_token'], $post['save_event_rule'], $post['save_and_test']);
    foreach (['cond_field', 'cond_op', 'cond_value', 'cond_group'] as $k) {
        if (isset($post[$k]) && is_array($post[$k])) {
            $post[$k] = array_slice($post[$k], 0, 60, true);
        }
    }
    array_walk_recursive($post, static function (&$v) {
        $v = is_string($v) ? mb_substr($v, 0, 6000) : $v;
    });

    return $post;
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
    $back = $id ? "event_rules.php?edit=$id" : 'event_rules.php?new=1';
    $parsed = RuleForm::parse($_POST);
    try {
        $saved = RuleForm::save($engine, $id ?: null, $parsed);
    } catch (\InvalidArgumentException $e) {
        // Keep the input: the editor shows it again with every problem listed (the first one is what save() refused).
        $problems = RuleForm::problems($mysqli, $parsed);
        $_SESSION['event_rule_draft'] = ['rule_id' => $id ?: 0, 'post' => $event_rules_draft($_POST), 'errors' => $problems ?: ['form' => $e->getMessage()], 'message' => $e->getMessage()];
        flash_alert($e->getMessage(), 'error');
        redirect($back . '&draft=1');
    }
    logAction('Automation', $id ? 'Edit' : 'Create', "$session_name " . ($id ? 'edited' : 'created') . " event rule $saved");
    rivetAudit($id ? 'automation.rule_updated' : 'automation.rule_created', (int) $session_user_id, 'automation_rule', $saved, $id ? 'update' : 'create', 'Event rule ' . ($id ? 'updated' : 'created'));
    flash_alert('Rule saved.');
    unset($_SESSION['event_rule_draft']);
    redirect(($_POST['save_event_rule'] ?? '') === 'test' ? "event_rules.php?edit=$saved&test=1" : 'event_rules.php');
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
