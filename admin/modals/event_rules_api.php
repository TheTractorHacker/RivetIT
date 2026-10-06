<?php

/*
 * JSON endpoint of the Event rules page (admin/event_rules.php, js/event_rules.js). Admin only (modal_header.php gates /admin/modals/).
 * Reads (GET): event, recent, history. Everything that changes or evaluates something is POST with the session CSRF token:
 * preview, check, test, run_now, toggle, duplicate, reorder. Nothing here runs an action except run_now, which is limited to rules whose
 * action is harmless to repeat (SAFE_TO_RUN); test and check never write, queue or send anything (RuleEngine::test() runs actions in dry-run mode).
 * Saving and deleting stay in admin/post/event_rules.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/modal_header.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/event_bus.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/event_catalog_ext.php';

use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\EventPayloads;
use ITFlow\Automation\RuleAdmin;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleForm;
use ITFlow\Automation\RuleSummary;

const EVENT_RULES_SAFE_TO_RUN = ['notify_user'];

$reply = static function (array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
};
$fail = static fn (string $msg, int $code = 400) => $reply(['ok' => false, 'error' => $msg], $code);

if (!class_exists(RuleEngine::class) || !rivetTableExists($mysqli, 'automation_rules')) {
    $fail('Run the database update first.', 503);
}
$a = (string) ($_GET['a'] ?? $_POST['a'] ?? '');
$is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if (in_array($a, ['preview', 'check', 'test', 'run_now', 'toggle', 'duplicate', 'reorder'], true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (!$is_post || !is_string($token) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $fail('Your session token is no longer valid. Reload the page and try again.', 403);
    }
}
$engine = new RuleEngine($mysqli, new \ITFlow\Workflow\LiveActionGateway($mysqli));
$lookups = RuleAdmin::lookups($mysqli);
$catalog_labels = [];
foreach (rivetEventCatalogData()['events'] as $e) {
    $catalog_labels[$e['id']] = $e['label'];
}
$slookups = RuleAdmin::summaryLookups($lookups, $catalog_labels);

/** The context of an event for matching, from the chosen sample. @return array{0:array<string,string>|null,1:string} context and a label (or an error text as the label when null) */
$sample_context = static function (string $event) use ($mysqli): array {
    $source = (string) ($_POST['source'] ?? 'sample');
    $data = null;
    $label = '';
    if ($source === 'ticket') {
        $tid = intval($_POST['sample_ticket'] ?? 0);
        $row = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT ticket_prefix, ticket_number, ticket_subject FROM tickets WHERE ticket_id = ' . $tid . ' AND ticket_archived_at IS NULL'));
        if ($row) {
            $base = EventPayloads::ticket($mysqli, $tid);
            $data = $base + array_diff_key(EventPayloads::sample($mysqli, $event), $base);
            $label = 'ticket ' . $row[0] . $row[1];
        }
    } elseif ($source === 'audit') {
        $aid = intval($_POST['sample_audit'] ?? 0);
        $stmt = mysqli_prepare($mysqli, 'SELECT * FROM audit_events WHERE audit_id = ? AND event_type = ?');
        mysqli_stmt_bind_param($stmt, 'is', $aid, $event);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if ($row) {
            $data = EventPayloads::fromAuditRow($row);
            $label = 'audit entry #' . $aid;
        }
    } elseif ($source === 'synthetic') {
        $raw = (string) ($_POST['synthetic_json'] ?? '');
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && mb_strlen($raw) <= 20000) {
            $data = $decoded;
            $label = 'your own event data';
        } else {
            return [null, 'The event data must be a JSON object of at most 20,000 characters.'];
        }
    } else {
        $data = EventPayloads::sample($mysqli, $event);
        $label = 'a sample event';
    }
    if ($data === null) {
        return [null, 'Choose a sample event.'];
    }
    unset($data['_chain']);

    return [EventPayloads::context($event, $data), $label];
};

switch ($a) {
    // ------------------------------------------------------------------ what the chosen event offers (fields, placeholders, sample values)
    case 'event':
        $event = (string) ($_GET['event'] ?? '');
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
            $fail('Unknown event.');
        }
        $def = \RivetCore\Webhooks\EventCatalog::get($event);
        $info = $def ? $def->toArray() : null;
        if ($info === null) {
            foreach (rivetEventCatalogData()['events'] as $e) {
                if ($e['id'] === $event) {
                    $info = $e + ['payloadFields' => []];
                }
            }
        }
        // The context keys an event really carries: the newest real audit entry of this type when there is one, else the sample.
        $stmt = mysqli_prepare($mysqli, 'SELECT * FROM audit_events WHERE event_type = ? ORDER BY audit_id DESC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $event);
        mysqli_stmt_execute($stmt);
        $audit = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        $ctx = EventPayloads::context($event, $audit && !RuleEngine::eventHasTicket($event) ? EventPayloads::fromAuditRow($audit) : EventPayloads::sample($mysqli, $event));
        $fields = [];
        foreach ((array) ($info['payloadFields'] ?? []) as $f) {
            $fields[$f['path']] = ['path' => $f['path'], 'type' => $f['type'], 'description' => $f['description'], 'sample' => isset($ctx[$f['path']]) ? mb_substr((string) $ctx[$f['path']], 0, 80) : null];
        }
        foreach ($ctx as $k => $v) {
            if (!isset($fields[$k]) && !str_starts_with((string) $k, '_chain')) {
                $fields[$k] = ['path' => (string) $k, 'type' => is_numeric($v) ? 'number' : 'string', 'description' => '', 'sample' => mb_substr((string) $v, 0, 80)];
            }
        }
        $reply(['ok' => true, 'id' => $event, 'label' => $info['label'] ?? $event, 'description' => $info['description'] ?? '', 'severity' => $info['severity'] ?? 'info',
            'group' => $info['group'] ?? '', 'groupLabel' => $info['groupLabel'] ?? '', 'known' => $info !== null, 'ticket' => RuleEngine::eventHasTicket($event),
            'fields' => array_values($fields), 'context' => array_map(static fn ($v) => mb_substr((string) $v, 0, 200), $ctx)]);

        // no break: reply() exits
    case 'recent':
        $event = (string) ($_GET['event'] ?? '');
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
            $fail('Unknown event.');
        }
        $tickets = [];
        if (RuleEngine::eventHasTicket($event)) {
            $res = mysqli_query($mysqli, 'SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject FROM tickets WHERE ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 25');
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $tickets[] = ['id' => (int) $r['ticket_id'], 'label' => $r['ticket_prefix'] . $r['ticket_number'] . ' - ' . mb_substr((string) $r['ticket_subject'], 0, 60)];
            }
        }
        $audit = [];
        $stmt = mysqli_prepare($mysqli, 'SELECT audit_id, created_at, summary FROM audit_events WHERE event_type = ? ORDER BY audit_id DESC LIMIT 25');
        mysqli_stmt_bind_param($stmt, 's', $event);
        mysqli_stmt_execute($stmt);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
            $audit[] = ['id' => (int) $r['audit_id'], 'label' => $r['created_at'] . ' - ' . mb_substr((string) $r['summary'], 0, 80)];
        }
        mysqli_stmt_close($stmt);
        $reply(['ok' => true, 'tickets' => $tickets, 'audit' => $audit, 'sample' => json_encode(EventPayloads::sample($mysqli, $event), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);

        // no break
    // ------------------------------------------------------------------ live sentence + section by section problems of the unsaved form
    case 'preview':
        $parsed = RuleForm::parse($_POST);
        $problems = RuleForm::problems($mysqli, $parsed);
        $model = null;
        try {
            $model = RuleForm::model($parsed);
        } catch (\InvalidArgumentException $e) {
        }
        $cfg = null;
        try {
            $act = ActionRegistry::get($parsed['action']);
            $cfg = $act ? $act->validate($mysqli, $parsed['input'], trim($parsed['trigger'])) : null;
        } catch (\InvalidArgumentException $e) {
        }
        // Describe what was typed even when it is not valid yet (the sentence is a guide, the problems list is the verdict).
        $rule = ['trigger_event' => trim($parsed['trigger']), 'condition' => $model ?? ['mode' => 'all', 'conditions' => []], 'action_type' => $parsed['action'], 'action_config' => $cfg ?? array_filter(RuleForm::actionInput($parsed['action'], $_POST), static fn ($v) => $v !== '' && $v !== 0 && $v !== [])];
        if ($parsed['action'] === 'set_ticket_field' && $cfg === null) {
            $rule['action_config'] = array_filter(['status' => $parsed['input']['status'] ?? '', 'priority' => $parsed['input']['priority'] ?? ''], static fn ($v) => $v !== '');
        }
        $reply(['ok' => true, 'valid' => !$problems, 'summary' => RuleSummary::describe($rule, $slookups), 'problems' => $problems ?: new stdClass()]);

        // no break
    // ------------------------------------------------------------------ "Check against recent events": conditions only, nothing runs
    case 'check':
        $parsed = RuleForm::parse($_POST);
        $event = trim($parsed['trigger']);
        if (!preg_match('/^[a-z0-9_.]{1,150}$/', $event)) {
            $fail('Choose the event first.');
        }
        try {
            $model = RuleForm::model($parsed);
        } catch (\InvalidArgumentException $e) {
            $fail($e->getMessage());
        }
        $raw = ConditionEvaluator::serialize($model);
        $items = [];
        if (RuleEngine::eventHasTicket($event)) {
            $res = mysqli_query($mysqli, 'SELECT ticket_id FROM tickets WHERE ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 10');
            $extras = EventPayloads::sample($mysqli, $event);
            while ($res && ($r = mysqli_fetch_row($res))) {
                $base = EventPayloads::ticket($mysqli, (int) $r[0]);
                $items[] = ['label' => 'Ticket ' . ($base['ticket_number'] ?? '#' . $r[0]) . ' - ' . mb_substr((string) ($base['ticket_subject'] ?? ''), 0, 60), 'ctx' => EventPayloads::context($event, $base + array_diff_key($extras, $base))];
            }
            $basis = 'the 10 most recent tickets, shown as if each had just triggered this event';
        } else {
            $stmt = mysqli_prepare($mysqli, 'SELECT * FROM audit_events WHERE event_type = ? ORDER BY audit_id DESC LIMIT 10');
            mysqli_stmt_bind_param($stmt, 's', $event);
            mysqli_stmt_execute($stmt);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC) as $r) {
                $items[] = ['label' => $r['created_at'] . ' - ' . mb_substr((string) $r['summary'], 0, 80), 'ctx' => EventPayloads::context($event, EventPayloads::fromAuditRow($r))];
            }
            mysqli_stmt_close($stmt);
            $basis = 'the 10 most recent ' . $event . ' entries in the audit trail';
        }
        $out = [];
        $matched = 0;
        foreach ($items as $it) {
            $ex = ConditionEvaluator::explain($raw, $it['ctx']);
            $matched += $ex['matched'] ? 1 : 0;
            $out[] = ['label' => $it['label'], 'matched' => $ex['matched'], 'failed' => array_values(array_map(static fn ($d) => trim($d['text']), array_filter($ex['details'], static fn ($d) => !$d['ok'] && !str_starts_with(trim($d['text']), 'group'))))];
        }
        $reply(['ok' => true, 'basis' => $basis, 'total' => count($out), 'matched' => $matched, 'items' => $out]);

        // no break
    // ------------------------------------------------------------------ dry run (never executes the action)
    case 'test':
        $id = intval($_POST['rule_id'] ?? 0);
        if (isset($_POST['rule_name']) || isset($_POST['action_type'])) { // the unsaved editor form
            $parsed = RuleForm::parse($_POST);
            try {
                $rule = RuleForm::ruleFromForm($mysqli, $parsed, $id);
            } catch (\InvalidArgumentException $e) {
                $reply(['ok' => false, 'error' => $e->getMessage(), 'problems' => RuleForm::problems($mysqli, $parsed)], 422);
            }
        } else {
            $rule = $id ? $engine->find($id) : null;
            if ($rule === null) {
                $fail('That rule does not exist.', 404);
            }
            $cfg = json_decode((string) ($rule['action_config_json'] ?? ''), true);
            $rule['action_config'] = is_array($cfg) ? $cfg : [];
        }
        $event = (string) $rule['trigger_event'];
        [$context, $label] = $sample_context($event);
        if ($context === null) {
            $fail($label);
        }
        $result = $engine->test($rule, $event, $context);
        logAction('Automation', 'Test', "$session_name tested event rule $id (dry run)");
        $result['ok'] = true;
        $result['sample'] = $label;
        $result['event'] = $event;
        $result['summary'] = RuleSummary::describe($rule, $slookups);
        $result['can_run'] = $id > 0 && in_array($rule['action_type'], EVENT_RULES_SAFE_TO_RUN, true);
        $reply($result);

        // no break
    // ------------------------------------------------------------------ really run a harmless rule once (explicit, confirmed in the page)
    case 'run_now':
        $id = intval($_POST['rule_id'] ?? 0);
        $rule = $id ? $engine->find($id) : null;
        if ($rule === null) {
            $fail('That rule does not exist.', 404);
        }
        if (!in_array($rule['action_type'], EVENT_RULES_SAFE_TO_RUN, true)) {
            $fail('Only rules that notify people can be run for real from here. Use the dry run for this rule.', 403);
        }
        if (!(int) $rule['is_enabled']) {
            $fail('Turn the rule on first: a rule that is off does not run.');
        }
        $event = (string) $rule['trigger_event'];
        [$context, $label] = $sample_context($event);
        if ($context === null) {
            $fail($label);
        }
        if (!ConditionEvaluator::matches($rule['condition_json'], $context)) {
            $fail('The conditions do not match this sample, so the rule would not run. Nothing was done.');
        }
        $result = $engine->run($id, $event, $context);
        logAction('Automation', 'Test', "$session_name ran event rule $id for real from the test drawer ({$result['status']})");
        $reply(['ok' => true, 'status' => $result['status'], 'message' => $result['message']]);

        // no break
    case 'history':
        $id = intval($_GET['rule_id'] ?? 0);
        $rule = $engine->find($id);
        if ($rule === null) {
            $fail('That rule does not exist.', 404);
        }
        $h = RuleAdmin::history($mysqli, $id, !empty($_GET['failed']), 50, intval($_GET['offset'] ?? 0));
        $h['ok'] = true;
        $h['rule'] = ['id' => $id, 'name' => $rule['name'], 'trigger' => $rule['trigger_event'], 'rate' => (int) ($rule['rate_limit_per_min'] ?? RuleEngine::DEFAULT_RATE)];
        $reply($h);

        // no break
    case 'toggle':
        $id = intval($_POST['rule_id'] ?? 0);
        $on = RuleAdmin::toggle($mysqli, $id, isset($_POST['enabled']) ? $_POST['enabled'] === '1' : null);
        if ($on === null) {
            $fail('That rule no longer exists.', 404);
        }
        logAction('Automation', 'Edit', "$session_name turned event rule $id " . ($on ? 'on' : 'off'));
        rivetAudit('automation.rule_toggled', (int) $session_user_id, 'automation_rule', $id, 'update', 'Event rule turned ' . ($on ? 'on' : 'off'));
        $reply(['ok' => true, 'enabled' => $on]);

        // no break
    case 'duplicate':
        $id = intval($_POST['rule_id'] ?? 0);
        $new = RuleAdmin::duplicate($mysqli, $id);
        if ($new === null) {
            $fail('That rule no longer exists.', 404);
        }
        logAction('Automation', 'Create', "$session_name duplicated event rule $id as $new (off)");
        rivetAudit('automation.rule_created', (int) $session_user_id, 'automation_rule', $new, 'create', 'Event rule duplicated from #' . $id);
        $reply(['ok' => true, 'id' => $new]);

        // no break
    case 'reorder':
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        if (!RuleAdmin::reorder($mysqli, $ids)) {
            $fail('Order only matters between rules for the same event: drag a rule within its own event.');
        }
        logAction('Automation', 'Edit', "$session_name reordered event rules " . implode(',', $ids));
        $reply(['ok' => true]);
}
$fail('Unknown request.', 404);
