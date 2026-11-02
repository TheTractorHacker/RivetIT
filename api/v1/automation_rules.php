<?php
// Event rules (Administration > Event rules) and their run log. ADMINISTRATORS ONLY, user API token only (never an integration client).
//
// GET    /api/v1/automation_rules                    list (q, trigger_event, action_type, enabled=0|1, limit<=200, offset)
// POST   /api/v1/automation_rules                    create   {name, trigger_event, conditions?, action_type, action_config, is_enabled?, priority?, stop_on_match?, rate_limit_per_min?}
// GET    /api/v1/automation_rules/{id}               one rule (+ its last run)
// PUT    /api/v1/automation_rules/{id}               replace a rule (same body as create)
// PATCH  /api/v1/automation_rules/{id}               change some fields (omitted fields, including action_config, are kept)
// DELETE /api/v1/automation_rules/{id}               delete (its run log rows stay)
// GET    /api/v1/automation_rules/runs               run log (rule_id, status, event, failed=1, limit<=200, offset)
// GET    /api/v1/automation_rules/runs/{run_id}      one run
// POST   /api/v1/automation_rules/retry/{run_id}     run a FAILED run again by hand (same event, new run, source "manual")
// GET    /api/v1/automation_rules/meta               the events, actions and risky-action switches the editor offers
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';
require_once $DOCUMENT_ROOT . '/includes/event_bus.php';
require_once $DOCUMENT_ROOT . '/includes/event_catalog_ext.php';

use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleAdmin;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleSummary;

api_mobile_require_user_token();
if (!api_mobile_is_admin()) {
    api_error(403, 'Insufficient permissions: automation rules are for administrators.');
}
if (!rivetTableExists($mysqli, 'automation_rules')) {
    api_error(503, 'Run the database update first.');
}
api_mobile_audit_context();
$uid = intval($api_user_id);
$engine = new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli));

/** A rule row as the API shows it. The webhook signing secret is never returned. */
function api_automation_rule_json(array $r): array {
    $cfg = json_decode((string) ($r['action_config_json'] ?? ''), true);
    $cfg = is_array($cfg) ? $cfg : [];
    if ($r['action_type'] === 'send_webhook') {
        $cfg['has_secret'] = ($cfg['secret'] ?? '') !== '';
        unset($cfg['secret']);
    }
    $model = ConditionEvaluator::normalize($r['condition_json'] ?? null);
    $rule = ['rule_id' => (int) $r['rule_id'], 'name' => $r['name'], 'trigger_event' => $r['trigger_event'], 'conditions' => $model, 'conditions_valid' => $model !== null,
        'action_type' => $r['action_type'], 'action_config' => (object) $cfg, 'is_enabled' => (bool) $r['is_enabled'], 'priority' => (int) ($r['priority'] ?? 100),
        'stop_on_match' => (bool) ($r['stop_on_match'] ?? 0), 'rate_limit_per_min' => (int) ($r['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE), 'created_at' => $r['created_at'],
        'imported_from_legacy_rule_id' => isset($r['source_legacy_id']) && $r['source_legacy_id'] !== null ? (int) $r['source_legacy_id'] : null];
    $rule['summary'] = RuleSummary::describe($r + ['condition_json' => $r['condition_json'] ?? null]);

    return $rule;
}

/** Body values to the engine's save() arguments. Anything invalid is a 422 with the reason. @return array{0:string,1:string,2:array,3:string,4:array,5:bool,6:int,7:bool,8:int} */
function api_automation_rule_args(array $b, ?array $current): array {
    $name = array_key_exists('name', $b) ? (string) $b['name'] : (string) ($current['name'] ?? '');
    $trigger = array_key_exists('trigger_event', $b) ? (string) $b['trigger_event'] : (string) ($current['trigger_event'] ?? '');
    $actionType = array_key_exists('action_type', $b) ? (string) $b['action_type'] : (string) ($current['action_type'] ?? '');
    if (array_key_exists('conditions', $b)) {
        $c = $b['conditions'];
        if ($c === null || $c === [] || $c === (object) []) {
            $model = ['mode' => 'all', 'conditions' => []];
        } elseif (is_array($c)) {
            $model = ConditionEvaluator::normalize(['version' => 2, 'mode' => $c['mode'] ?? 'all', 'conditions' => $c['conditions'] ?? []]);
            if ($model === null) {
                api_error(422, 'conditions is not valid: use {"mode":"all|any","conditions":[{"field","op","value"}...]} (ops eq, ne, in, contains, gt, lt, is_empty; groups one level deep; at most 20 rows).');
            }
        } else {
            api_error(422, 'conditions must be an object.');
        }
    } else {
        $model = ConditionEvaluator::normalize($current['condition_json'] ?? null) ?? ['mode' => 'all', 'conditions' => []];
    }
    if (array_key_exists('action_config', $b)) {
        $input = is_array($b['action_config']) ? $b['action_config'] : null;
        if ($input === null) {
            api_error(422, 'action_config must be an object.');
        }
    } else {
        $input = json_decode((string) ($current['action_config_json'] ?? ''), true);
        $input = is_array($input) ? $input : [];
        if ($current !== null && $actionType !== $current['action_type']) {
            api_error(422, 'action_config is required when action_type changes.');
        }
        // stored configs are the validated form; for the actions whose stored shape differs from the input shape, convert back
        $input = \ITFlow\Automation\RuleForm::storedToInput($actionType, $input);
    }
    $enabled = array_key_exists('is_enabled', $b) ? (bool) $b['is_enabled'] : (bool) ($current['is_enabled'] ?? false);
    $priority = array_key_exists('priority', $b) ? (int) $b['priority'] : (int) ($current['priority'] ?? 100);
    $stop = array_key_exists('stop_on_match', $b) ? (bool) $b['stop_on_match'] : (bool) ($current['stop_on_match'] ?? false);
    $rate = array_key_exists('rate_limit_per_min', $b) ? (int) $b['rate_limit_per_min'] : (int) ($current['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE);

    return [$name, $trigger, $model, $actionType, $input, $enabled, $priority, $stop, $rate];
}

$segment_sub = is_string($sub) ? str_replace('-', '_', $sub) : null;

// ------------------------------------------------------------------ meta
if ($id === null && $segment_sub === 'meta') {
    if ($method !== 'GET') api_error(405, 'Method not allowed');
    $events = array_map(static fn (array $e): array => ['id' => $e['id'], 'label' => $e['label'], 'group' => $e['group'], 'description' => $e['description']], rivetEventCatalogData()['events']);
    $actions = [];
    foreach (ActionRegistry::all() as $k => $a) {
        $actions[] = ['key' => $k, 'label' => $a->label(), 'needs_ticket_event' => in_array($k, ActionRegistry::TICKET_ACTIONS, true), 'risky' => in_array($k, ActionRegistry::RISKY_ACTIONS, true)];
    }
    api_response(200, ['events' => $events, 'actions' => $actions, 'condition_ops' => array_keys(ConditionEvaluator::OPERATORS), 'enrichment_fields' => \ITFlow\Automation\PayloadEnricher::FIELDS,
        'max_attempts' => RuleEngine::MAX_ATTEMPTS, 'switches' => ['run_script' => \ITFlow\Automation\Actions\RunScriptAction::enabled($mysqli), 'create_odoo_user' => \ITFlow\Automation\Actions\CreateOdooUserAction::enabled($mysqli)]]);
}

// ------------------------------------------------------------------ run log
if ($segment_sub === 'runs') {
    if ($method !== 'GET') api_error(405, 'Method not allowed');
    if ($id !== null) {
        $one = RuleAdmin::runs($mysqli, ['run_id' => $id], 1, 0);
        if (!$one['rows']) api_error(404, 'Run not found');
        api_response(200, ['run' => $one['rows'][0]]);
    }
    $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $r = RuleAdmin::runs($mysqli, ['rule_id' => intval($_GET['rule_id'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''), 'event' => (string) ($_GET['event'] ?? ''), 'failed_only' => !empty($_GET['failed'])], $limit, $offset);
    api_response(200, ['runs' => $r['rows'], 'total' => $r['total'], 'counts' => (object) $r['counts'], 'limit' => $limit, 'offset' => $offset]);
}
if ($segment_sub === 'retry') {
    if ($method !== 'POST') api_error(405, 'Method not allowed');
    if ($id === null) api_error(400, 'POST /automation_rules/retry/{run_id}');
    try {
        $res = $engine->rerun($id, $uid);
    } catch (\InvalidArgumentException $e) {
        api_error(409, $e->getMessage());
    }
    logAction('Automation', 'Edit', "API user $uid ran event rule run #$id again: {$res['status']}");
    api_response(200, ['status' => $res['status'], 'ok' => $res['ok'], 'message' => $res['message'], 'run_id' => $res['run_id'], 'retry_of_run_id' => $id]);
}

// ------------------------------------------------------------------ rules
if ($id === null && $segment_sub !== null) {
    api_error(404, 'Not found');
}
if ($id === null) {
    if ($method === 'GET') {
        $where = ['1=1'];
        $types = '';
        $vals = [];
        if (($q = trim((string) ($_GET['q'] ?? ''))) !== '') {
            $where[] = '(name LIKE ? OR trigger_event LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $types .= 'ss';
            array_push($vals, $like, $like);
        }
        if (($t = trim((string) ($_GET['trigger_event'] ?? ''))) !== '') {
            $where[] = 'trigger_event = ?';
            $types .= 's';
            $vals[] = $t;
        }
        if (($t = trim((string) ($_GET['action_type'] ?? ''))) !== '') {
            $where[] = 'action_type = ?';
            $types .= 's';
            $vals[] = $t;
        }
        if (isset($_GET['enabled']) && in_array($_GET['enabled'], ['0', '1'], true)) {
            $where[] = 'is_enabled = ' . (int) $_GET['enabled'];
        }
        $limit = max(1, min(200, intval($_GET['limit'] ?? 50)));
        $offset = max(0, intval($_GET['offset'] ?? 0));
        $sql = 'FROM automation_rules WHERE ' . implode(' AND ', $where);
        $stmt = mysqli_prepare($mysqli, "SELECT COUNT(*) $sql");
        if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $total = (int) (mysqli_fetch_row(mysqli_stmt_get_result($stmt))[0] ?? 0);
        mysqli_stmt_close($stmt);
        $stmt = mysqli_prepare($mysqli, "SELECT * $sql ORDER BY trigger_event, priority, rule_id LIMIT $limit OFFSET $offset");
        if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$vals);
        mysqli_stmt_execute($stmt);
        $rows = array_map('api_automation_rule_json', mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC));
        mysqli_stmt_close($stmt);
        api_response(200, ['rules' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
    }
    if ($method === 'POST') {
        $b = api_mobile_json_body();
        [$name, $trigger, $model, $actionType, $input, $enabled, $priority, $stop, $rate] = api_automation_rule_args($b, null);
        try {
            $newId = $engine->save(null, $name, $trigger, $model, $actionType, $input, $enabled, $priority, $stop, $rate);
        } catch (\InvalidArgumentException $e) {
            api_error(422, $e->getMessage());
        }
        logAction('Automation', 'Create', "API user $uid created event rule #$newId '$name'");
        rivetAudit('automation.rule_created', $uid, 'automation_rule', $newId, 'create', "Event rule '$name' created through the API", ['trigger' => $trigger, 'action' => $actionType]);
        api_response(201, ['rule' => api_automation_rule_json($engine->find($newId))]);
    }
    api_error(405, 'Method not allowed');
}

$rule = $engine->find($id);
if ($rule === null) api_error(404, 'Rule not found');
if ($segment_sub !== null) {
    api_error(404, 'Not found');
}
if ($method === 'GET') {
    $out = api_automation_rule_json($rule);
    $last = RuleAdmin::runs($mysqli, ['rule_id' => $id], 1, 0);
    $out['last_run'] = $last['rows'][0] ?? null;
    $out['run_counts'] = (object) $last['counts'];
    api_response(200, ['rule' => $out]);
}
if ($method === 'PUT' || $method === 'PATCH') {
    $b = api_mobile_json_body();
    if ($method === 'PUT') {
        foreach (['name', 'trigger_event', 'action_type', 'action_config'] as $req) {
            if (!array_key_exists($req, $b)) api_error(422, "PUT needs $req (use PATCH to change only some fields).");
        }
        $current = null;
    } else {
        $current = $rule;
    }
    [$name, $trigger, $model, $actionType, $input, $enabled, $priority, $stop, $rate] = api_automation_rule_args($b, $current);
    try {
        $engine->save($id, $name, $trigger, $model, $actionType, $input, $enabled, $priority, $stop, $rate);
    } catch (\InvalidArgumentException $e) {
        api_error(422, $e->getMessage());
    }
    logAction('Automation', 'Edit', "API user $uid changed event rule #$id '$name'");
    rivetAudit('automation.rule_updated', $uid, 'automation_rule', $id, 'update', "Event rule '$name' changed through the API", ['trigger' => $trigger, 'action' => $actionType]);
    api_response(200, ['rule' => api_automation_rule_json($engine->find($id))]);
}
if ($method === 'DELETE') {
    $stmt = mysqli_prepare($mysqli, 'DELETE FROM automation_rules WHERE rule_id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    logAction('Automation', 'Delete', "API user $uid deleted event rule #$id '{$rule['name']}'");
    rivetAudit('automation.rule_deleted', $uid, 'automation_rule', $id, 'delete', "Event rule '{$rule['name']}' deleted through the API", []);
    api_response(200, ['deleted' => true, 'rule_id' => $id]);
}
api_error(405, 'Method not allowed');
