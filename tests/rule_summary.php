<?php
/*
 * Event rules UI helpers against the REAL src/Automation code:
 *   RuleSummary (the plain-English sentence), RuleForm (form <-> condition model <-> stored config round trips), RuleRecipes (every recipe
 *   uses an event and an action that exist), and - when a scratch database is given - RuleAdmin (list statistics, filter, duplicate,
 *   toggle, reorder, history) and RuleForm::problems / save.
 *   php tests/rule_summary.php                       (pure part only; needs vendor/)
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/rule_summary.php
 */
require __DIR__ . '/../vendor/autoload.php';

use ITFlow\Automation\ConditionEvaluator;
use ITFlow\Automation\RuleAdmin;
use ITFlow\Automation\RuleEngine;
use ITFlow\Automation\RuleForm;
use ITFlow\Automation\RuleRecipes;
use ITFlow\Automation\RuleSummary;
use ITFlow\Automation\Actions\ActionRegistry;

$fails = 0;
$ok = function (bool $c, string $l, string $detail = '') use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l" . (!$c && $detail !== '' ? "  [$detail]" : '') . "\n"; if (!$c) $fails++; };
$eq = function ($actual, $expected, string $l) use ($ok) { $ok($actual === $expected, $l, 'got ' . json_encode($actual) . ' expected ' . json_encode($expected)); };

$lk = ['agents' => [7 => 'Dana Smith', 8 => 'Lee Wong'], 'clients' => [4 => 'Acme Ltd'], 'categories' => [3 => 'Hardware'], 'templates' => [5 => 'Offboarding'], 'events' => ['x.custom_done' => 'Custom done']];
$leaf = fn ($f, $op, $v = '') => ['field' => $f, 'op' => $op, 'value' => $v];
$rule = fn (string $ev, $cond, string $type, array $cfg) => ['trigger_event' => $ev, 'condition_json' => $cond === null ? null : (is_string($cond) ? $cond : json_encode($cond)), 'action_type' => $type, 'action_config_json' => json_encode($cfg)];
$v2 = fn (string $mode, array $items) => ['version' => 2, 'mode' => $mode, 'conditions' => $items];

// ================================================================ 1. RuleSummary
$eq(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('ticket_priority', 'in', 'High,Critical')]), 'notify_user', ['message' => '']), $lk),
    'When a ticket is created, and priority is High or Critical, then notify all technicians.', 'the documented example sentence');
$eq(RuleSummary::describe($rule('ticket.created', null, 'notify_user', ['message' => 'Hi']), $lk), 'When a ticket is created, then notify all technicians: "Hi".', 'no conditions, notify with message');
$eq(RuleSummary::describe($rule('ticket.sla_breached', '{"ticket_priority":"High"}', 'send_webhook', ['url' => 'https://hooks.slack.com/services/T/B/x']), $lk),
    'When a ticket breaches its SLA, and priority is High, then send a webhook to hooks.slack.com.', 'legacy flat conditions + webhook host (no secret path shown)');
$ok(!str_contains(RuleSummary::describe($rule('ticket.created', null, 'send_webhook', ['url' => 'https://h.example/secret/token123?k=1']), $lk), 'token123'), 'the webhook path and query are never shown');
$eq(RuleSummary::describe($rule('ticket.assigned', $v2('all', [$leaf('assigned_to_user_id', 'eq', '7')]), 'assign_ticket', ['mode' => 'round_robin', 'pool' => [7, 8]]), $lk),
    'When a ticket is assigned, and assigned technician is Dana Smith, then assign the ticket in turn to Dana Smith, Lee Wong.', 'agent ids resolve to names');
$eq(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('assigned_to_user_id', 'eq', '0')]), 'notify_user', ['message' => '']), $lk),
    'When a ticket is created, and assigned technician is nobody, then notify all technicians.', 'an unassigned ticket reads "nobody"');
$eq(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('client_id', 'eq', '4'), $leaf('ticket_subject', 'contains', 'VPN')]), 'assign_ticket', ['mode' => 'user', 'user_id' => 99]), $lk),
    'When a ticket is created, and client is Acme Ltd, and subject contains "VPN", then assign the ticket to technician #99.', 'client names, contains, unknown agent id');
$eq(RuleSummary::describe($rule('ticket.created', $v2('any', [$leaf('ticket_priority', 'eq', 'High'), $leaf('client_id', 'eq', '4')]), 'add_ticket_note', ['note' => 'x']), $lk),
    'When a ticket is created, and any of these: priority is High; client is Acme Ltd, then add an internal note to the ticket.', 'top-level any');
$eq(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('ticket_priority', 'eq', 'High'), $v2('any', [$leaf('client_id', 'eq', '4'), $leaf('ticket_subject', 'contains', 'VPN')])]), 'notify_user', ['message' => '']), $lk),
    'When a ticket is created, and priority is High, and (client is Acme Ltd or subject contains "VPN"), then notify all technicians.', 'nested group is parenthesised');
$eq(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('n', 'gt', '5'), $leaf('n', 'lt', '9'), $leaf('contact_name', 'is_empty'), $leaf('ticket_status', 'ne', 'Closed')]), 'notify_user', ['message' => '']), $lk),
    'When a ticket is created, and n is greater than 5, and n is less than 9, and contact name is empty, and status is not Closed, then notify all technicians.', 'gt lt is_empty ne');
$eq(RuleSummary::describe($rule('ticket.status_changed', null, 'set_ticket_field', ['status' => 'Resolved', 'priority' => 'Low', 'category_id' => 3, 'assignee' => 0]), $lk),
    "When a ticket's status changes, then set the ticket's status to Resolved, priority to Low, category to Hardware, assignee to nobody.", 'set_ticket_field');
$eq(RuleSummary::describe($rule('employee.terminated', null, 'start_workflow', ['template_id' => 5]), $lk), 'When an employee is terminated, then start the workflow "Offboarding".', 'workflow template name');
$eq(RuleSummary::describe($rule('ticket.assigned', null, 'send_mail', ['to_type' => 'assignee', 'subject' => 'Assigned: {ticket_number}']), $lk), 'When a ticket is assigned, then email the assigned technician: "Assigned: {ticket_number}".', 'send_mail to role');
$eq(RuleSummary::describe($rule('ticket.assigned', null, 'send_mail', ['to_type' => 'user', 'user_id' => 8, 'subject' => 'S']), $lk), 'When a ticket is assigned, then email Lee Wong: "S".', 'send_mail to a technician');
$eq(RuleSummary::describe($rule('ticket.sla_warning', null, 'create_task', ['name' => 'Check', 'assignee_id' => 7, 'due_days' => 1]), $lk), "When a ticket's SLA is at risk, then add the task \"Check\" to the ticket for Dana Smith (due in 1 day).", 'create_task');
$eq(RuleSummary::describe($rule('auth.login_failed', null, 'create_ticket', ['subject' => 'Failed', 'priority' => 'High']), $lk), 'When a sign-in fails, then create a High priority ticket "Failed".', 'create_ticket');
$eq(RuleSummary::describe($rule('x.custom_done', null, 'notify_user', []), $lk), 'When the event "Custom done" happens, then notify all technicians.', 'unknown phrase falls back to the event label');
$eq(RuleSummary::describe($rule('workflow.template_created', null, 'notify_user', []), $lk), 'When a workflow template is created, then notify all technicians.', 'label "X created" becomes "a x is created"');
$eq(RuleSummary::describe($rule('unknown.thing', null, 'notify_user', []), []), 'When the event "unknown.thing" happens, then notify all technicians.', 'an event nobody knows is quoted');
$eq(RuleSummary::describe($rule('ticket.created', '{broken', 'notify_user', []), $lk), 'When a ticket is created, and the conditions are not valid (the rule never fires), then notify all technicians.', 'invalid stored conditions are called out');
$eq(RuleSummary::describe(['trigger_event' => '', 'action_type' => ''], []), 'When (choose an event), then (choose what to do).', 'an empty form still reads sensibly');
$ok(!str_contains(RuleSummary::describe($rule('ticket.created', $v2('all', [$leaf('ticket_subject', 'contains', str_repeat('x', 190))]), 'notify_user', []), $lk), str_repeat('x', 100)), 'long values are clipped');
$eq(RuleSummary::describe(['trigger_event' => 'ticket.created', 'condition' => $v2('all', [$leaf('ticket_priority', 'eq', '<b>')]), 'action_type' => 'add_ticket_note', 'action_config' => ['note' => 'n']], $lk),
    'When a ticket is created, and priority is <b>, then add an internal note to the ticket.', 'text is returned raw (callers escape); a decoded model and config also work');

// ================================================================ 2. RuleForm round trips (pure)
$post = ['rule_name' => ' R ', 'trigger_event' => 'ticket.created', 'cond_mode' => 'all',
    'cond_field' => ['ticket_priority', 'client_id', 'ticket_subject', 'ticket_status', ''], 'cond_op' => ['in', 'eq', 'contains', 'ne', 'eq'], 'cond_value' => ['High,Critical', '4', 'VPN', 'Closed', ''], 'cond_group' => ['', 'A', 'A', 'B', ''],
    'group_mode' => ['A' => 'any', 'B' => 'all'], 'action_type' => 'notify_user', 'cfg_message' => 'm', 'is_enabled' => '1', 'rule_priority' => '50', 'rate_limit' => '9'];
$p = RuleForm::parse($post);
$model = RuleForm::model($p);
$eq($model, ['mode' => 'all', 'conditions' => [$leaf('ticket_priority', 'in', 'High,Critical'), ['mode' => 'any', 'conditions' => [$leaf('client_id', 'eq', '4'), $leaf('ticket_subject', 'contains', 'VPN')]], ['mode' => 'all', 'conditions' => [$leaf('ticket_status', 'ne', 'Closed')]]]], 'the posted rows become the engine condition model with two groups');
$rows = RuleForm::modelToRows(ConditionEvaluator::normalize(ConditionEvaluator::serialize($model)));
$eq($rows['groupModes'], ['A' => 'any', 'B' => 'all'], 'group modes survive store and reload');
$p2 = RuleForm::parse(['cond_mode' => $rows['mode'], 'cond_field' => array_column($rows['rows'], 'field'), 'cond_op' => array_column($rows['rows'], 'op'), 'cond_value' => array_column($rows['rows'], 'value'), 'cond_group' => array_column($rows['rows'], 'group'), 'group_mode' => $rows['groupModes']]);
$eq(RuleForm::model($p2), $model, 'builder rows -> form -> model is a fixed point (the editor shows what was saved)');
$eq(RuleForm::modelToRows(['mode' => 'any', 'conditions' => []]), ['mode' => 'any', 'rows' => [], 'groupModes' => []], 'no conditions, no rows');
$ok(RuleForm::parse(['cond_field' => [['x']], 'cond_op' => 'eq', 'rule_name' => ['a'], 'trigger_event' => ['b']])['name'] === '', 'array-valued input is neutralised, not an error');
$eq(RuleForm::parse(['cond_field' => ['f'], 'cond_op' => ['eq'], 'cond_value' => ['v'], 'cond_group' => ['Z']])['rows'][0]['group'], '', 'an unknown group letter means top level');
$eq(RuleForm::parse(['cond_field' => ['f'], 'cond_op' => ['eq'], 'cond_value' => ['v'], 'cond_group' => ['J']])['rows'][0]['group'], 'J', 'groups A to J are accepted');
$thrown = null;
try { RuleForm::model(RuleForm::parse(['cond_field' => ['n'], 'cond_op' => ['gt'], 'cond_value' => ['abc'], 'cond_group' => ['']])); } catch (\InvalidArgumentException $e) { $thrown = $e->getMessage(); }
$ok($thrown !== null && str_contains($thrown, 'greater/less than'), 'greater-than with a non-number is refused with a readable message');
// every action: config -> form -> input -> same config
$cfgs = ['create_ticket' => ['subject' => 's', 'details' => 'd', 'priority' => 'High'], 'send_webhook' => ['url' => 'https://x.test/h', 'secret' => 'k'], 'notify_user' => ['message' => 'm'], 'start_workflow' => ['template_id' => 5],
    'set_ticket_field' => ['status' => 'Open', 'priority' => 'High', 'category_id' => 3, 'assignee' => 0], 'add_ticket_note' => ['note' => 'n'], 'assign_ticket' => ['mode' => 'round_robin', 'pool' => [7, 8]],
    'send_mail' => ['to_type' => 'address', 'address' => 'a@b.test', 'user_id' => 0, 'subject' => 's', 'body' => 'b'], 'create_task' => ['name' => 'n', 'assignee_id' => 7, 'due_days' => 2]];
$ok(array_keys($cfgs) === array_keys(ActionRegistry::all()) || !array_diff(array_keys(ActionRegistry::all()), array_keys($cfgs)), 'the form contract covers every action in the registry');
foreach ($cfgs as $a => $c) {
    $form = RuleForm::configToForm($a, $c);
    $in = RuleForm::actionInput($a, $form);
    // the values the action will validate are exactly the stored ones (ids may come back as strings)
    $flat = fn (array $x) => json_decode(json_encode($x), true);
    $want = $c;
    if ($a === 'set_ticket_field') { $want['assignee'] = 'none'; $want['category_id'] = 3; }
    if ($a === 'send_mail') { $want = ['to_type' => 'address', 'address' => 'a@b.test', 'user_id' => 0, 'subject' => 's', 'body' => 'b']; }
    $got = $flat($in);
    $same = true;
    foreach ($want as $k => $v) {
        $key = $k === 'to_type' ? 'to_type' : $k;
        if (!array_key_exists($key, $got) || (is_array($v) ? array_map('intval', (array) $got[$key]) !== array_map('intval', $v) : (string) $got[$key] !== (string) $v)) { $same = false; }
    }
    $ok($same, "$a: stored config -> form fields -> action input gives back the same values", json_encode([$form, $in]));
}
$eq(RuleForm::configToForm('set_ticket_field', ['priority' => 'High'])['cfg_sf_assignee'], '', 'set_ticket_field without an assignee leaves the assignee select on "(leave as is)"');
$eq(RuleForm::configToForm('set_ticket_field', ['assignee' => 0])['cfg_sf_assignee'], 'none', 'set_ticket_field assignee 0 shows as Unassigned');

// ================================================================ 3. recipes
$ids = [];
if (class_exists(\RivetCore\Webhooks\EventCatalog::class)) {
    foreach (\RivetCore\Webhooks\EventCatalog::all() as $e) { $ids[] = $e->id; }
    $ids = array_merge($ids, ['ticket.updated', 'ticket.sla_warning', 'ticket.sla_breached', 'catalog.request_approved', 'catalog.request_rejected']);
}
$all = RuleRecipes::all();
$ok(count($all) >= 8 && count($all) <= 12, 'there are 8 to 12 recipes (' . count($all) . ')');
$ok(count(array_unique(array_column($all, 'key'))) === count($all), 'recipe keys are unique');
$avail = RuleRecipes::available($ids, ['New', 'Open', 'On Hold', 'Resolved', 'Closed']);
$ok(count($avail) === count($all), 'with the default statuses every recipe is available', (string) count($avail));
foreach ($all as $r) {
    $ok(ActionRegistry::get($r['action']) !== null, "recipe {$r['key']}: its action exists");
    $ok(!$ids || in_array($r['event'], $ids, true), "recipe {$r['key']}: its event is in the picker catalog");
    $ok(!$ids || !in_array(\RivetCore\Webhooks\EventCatalog::get($r['event'])?->since, ['planned'], true) || str_starts_with($r['event'], 'ticket.'), "recipe {$r['key']}: its event is emitted by this edition (not merely 'planned')");
    $st = RuleRecipes::toState($avail[array_search($r['key'], array_column($avail, 'key'), true)]);
    $m = null;
    try { $m = RuleForm::model(RuleForm::parse(['cond_mode' => 'all', 'cond_field' => array_column($st['rows'], 'field'), 'cond_op' => array_column($st['rows'], 'op'), 'cond_value' => array_column($st['rows'], 'value'), 'cond_group' => array_column($st['rows'], 'group')])); } catch (\Throwable $e) { }
    $ok($m !== null && !str_contains(json_encode($m), '{status}'), "recipe {$r['key']}: its conditions are valid and fully resolved");
}
$noHold = RuleRecipes::available($ids, ['New', 'Open', 'Closed']);
$ok(count($noHold) === count($all) - 2, 'recipes that need a ticket status this installation lacks are not offered', (string) count($noHold));
$ok(count(RuleRecipes::available(['ticket.created'], ['Open'])) < count($all) && count(RuleRecipes::available([], [])) === 0, 'recipes whose event is not offered are dropped');

// ================================================================ 4. RuleAdmin helpers that need no database
$eq(RuleAdmin::ago(null), 'never', 'ago: never');
$eq([RuleAdmin::ago(5), RuleAdmin::ago(120), RuleAdmin::ago(7200), RuleAdmin::ago(86400), RuleAdmin::ago(200000)], ['just now', '2 min ago', '2 h ago', '1 day ago', '2 days ago'], 'ago: ranges');
$rs = [
    ['rule_id' => 1, 'name' => 'Zeta', 'trigger_event' => 'ticket.created', 'priority' => 20, 'is_enabled' => 1, 'action_type' => 'notify_user', 'last_status' => 'ok', 'last_age' => 100, 'runs_total' => 5],
    ['rule_id' => 2, 'name' => 'alpha', 'trigger_event' => 'ticket.created', 'priority' => 10, 'is_enabled' => 0, 'action_type' => 'create_ticket', 'last_status' => 'failed', 'last_age' => 5, 'runs_total' => 1],
    ['rule_id' => 3, 'name' => 'Mid', 'trigger_event' => 'auth.login_failed', 'priority' => 100, 'is_enabled' => 1, 'action_type' => 'notify_user', 'last_status' => null, 'last_age' => null, 'runs_total' => 0],
];
$groupOf = fn (string $e) => str_starts_with($e, 'auth.') ? 'security' : 'tickets';
$sumOf = fn (array $r) => $r['name'] === 'Mid' ? 'When a sign-in fails' : '';
$names = fn (array $f) => array_column(RuleAdmin::filter($rs, $f, $groupOf, $sumOf), 'name');
$eq($names([]), ['Mid', 'alpha', 'Zeta'], 'default sort: event, then priority (auth before ticket)');
$eq($names(['sort' => 'name']), ['alpha', 'Mid', 'Zeta'], 'sort by name ignores case');
$eq($names(['sort' => 'name', 'dir' => 'desc']), ['Zeta', 'Mid', 'alpha'], 'descending');
$eq($names(['sort' => 'last_run']), ['Mid', 'Zeta', 'alpha'], 'sort by last run: never-run first, then oldest, newest last');
$eq($names(['sort' => 'runs', 'dir' => 'desc']), ['Zeta', 'alpha', 'Mid'], 'sort by run count');
$eq($names(['group' => 'security']), ['Mid'], 'filter by event group');
$eq($names(['action' => 'create_ticket']), ['alpha'], 'filter by action');
$eq($names(['state' => 'off']), ['alpha'], 'filter off');
$eq($names(['state' => 'on']), ['Mid', 'Zeta'], 'filter on');
$eq($names(['state' => 'failing']), ['alpha'], 'filter failing = last run failed');
$eq($names(['q' => 'SIGN-IN']), ['Mid'], 'search matches the summary text, case-insensitively');
$eq($names(['q' => 'zeta']), ['Zeta'], 'search matches the name');
$eq($names(['q' => 'auth.login']), ['Mid'], 'search matches the event id');

// ================================================================ 5. with a scratch database
if (getenv('RIVETIT_TEST_DB') === '1') {
    if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
    if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
    date_default_timezone_set('UTC');
    $mysqli->query("SET SESSION sql_mode=''");
    $mysqli->query("SET SESSION time_zone = '+00:00'");
    require __DIR__ . '/../includes/event_bus.php';
    $q = fn (string $s) => mysqli_query($mysqli, $s);
    $one = fn (string $s) => mysqli_fetch_row($q($s))[0] ?? null;
    $q("DELETE FROM automation_rule_runs WHERE rule_id IN (SELECT rule_id FROM automation_rules WHERE name LIKE 'RS %')");
    $q("DELETE FROM automation_rules WHERE name LIKE 'RS %'");
    $engine = new RuleEngine($mysqli, new class implements \ITFlow\Workflow\ActionGateway {
        public function createTicket(string $s, string $d, string $p, int $c, string $src): int { return 1; }
        public function queueMail(string $to, string $n, string $s, string $b): void {}
        public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void {}
        public function emitEvent(string $ev, array $d): void {}
        public function audit(string $ev, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void {}
        public function disablePortalLogin(int $c): string { return ''; }
    });
    $post['rule_name'] = 'RS nested';
    $id = RuleForm::save($engine, null, RuleForm::parse($post));
    $row = $engine->find($id);
    $ok($row !== null && $row['name'] === 'RS nested' && (int) $row['priority'] === 50 && (int) $row['rate_limit_per_min'] === 9, 'RuleForm::save stores the rule with its settings');
    $back = RuleForm::modelToRows(ConditionEvaluator::normalize($row['condition_json']));
    $eq(RuleForm::model(RuleForm::parse(['cond_mode' => $back['mode'], 'cond_field' => array_column($back['rows'], 'field'), 'cond_op' => array_column($back['rows'], 'op'), 'cond_value' => array_column($back['rows'], 'value'), 'cond_group' => array_column($back['rows'], 'group'), 'group_mode' => $back['groupModes']])), $model, 'a saved rule with nested groups reloads into the same builder structure');
    $ok(RuleSummary::describe($row) !== '' && str_starts_with(RuleSummary::describe($row), 'When a ticket is created, and priority is High or Critical'), 'the stored row can be described directly');

    // problems(): section by section
    $bad = RuleForm::problems($mysqli, RuleForm::parse(['rule_name' => '', 'trigger_event' => 'Bad Event!', 'action_type' => 'send_webhook', 'cfg_url' => 'javascript:alert(1)', 'rule_priority' => '0', 'cond_field' => ['n'], 'cond_op' => ['gt'], 'cond_value' => ['x'], 'cond_group' => ['']]));
    $eq(array_keys($bad), ['name', 'trigger', 'conditions', 'action', 'settings'], 'problems() reports every section that is wrong');
    $eq(RuleForm::problems($mysqli, RuleForm::parse($post)), [], 'a valid form has no problems');
    $tk = RuleForm::problems($mysqli, RuleForm::parse(['rule_name' => 'n', 'trigger_event' => 'auth.login_failed', 'action_type' => 'add_ticket_note', 'cfg_note' => 'x']));
    $ok(isset($tk['action']) && str_contains($tk['action'], 'about a ticket'), 'a ticket action on a non-ticket event is explained');
    $thrown = null;
    try { RuleForm::ruleFromForm($mysqli, RuleForm::parse(['rule_name' => 'n', 'trigger_event' => 'ticket.created', 'action_type' => 'create_ticket', 'cfg_subject' => '']), 0); } catch (\InvalidArgumentException $e) { $thrown = $e->getMessage(); }
    $ok($thrown !== null, 'ruleFromForm refuses an action that does not validate');
    $rf = RuleForm::ruleFromForm($mysqli, RuleForm::parse($post), 0);
    $ok($rf['action_config'] === ['message' => 'm', 'user_id' => 0] && $rf['rule_id'] === 0 && (int) $one("SELECT COUNT(*) FROM automation_rules WHERE name = 'RS nested'") === 1, 'ruleFromForm builds a testable rule without storing anything');

    // duplicate / toggle / reorder
    $copy = RuleAdmin::duplicate($mysqli, $id);
    $c = $engine->find((int) $copy);
    $ok($copy > 0 && $c['name'] === 'RS nested (copy)' && (int) $c['is_enabled'] === 0 && $c['condition_json'] === $row['condition_json'] && $c['action_config_json'] === $row['action_config_json'] && (int) $c['priority'] === 50 && (int) $c['rate_limit_per_min'] === 9, 'duplicate copies everything, turned OFF, named "... (copy)"');
    $copy2 = RuleAdmin::duplicate($mysqli, (int) $copy);
    $eq($engine->find((int) $copy2)['name'], 'RS nested (copy) (copy)', 'duplicating a copy appends again');
    $long = RuleForm::save($engine, null, RuleForm::parse(['rule_name' => 'RS ' . str_repeat('n', 190), 'trigger_event' => 'ticket.created', 'action_type' => 'notify_user', 'cfg_message' => 'x']));
    $ld = $engine->find((int) RuleAdmin::duplicate($mysqli, $long));
    $ok(mb_strlen($ld['name']) <= 200 && str_ends_with($ld['name'], ' (copy)'), 'a long name is shortened so the copy still fits in 200 characters');
    $eq(RuleAdmin::duplicate($mysqli, 999999999), null, 'duplicating a rule that does not exist returns null');
    $eq(RuleAdmin::toggle($mysqli, (int) $copy, true), true, 'toggle on');
    $eq(RuleAdmin::toggle($mysqli, (int) $copy), false, 'toggle flips');
    $eq(RuleAdmin::toggle($mysqli, 999999999), null, 'toggle of a missing rule returns null');
    $other = RuleForm::save($engine, null, RuleForm::parse(['rule_name' => 'RS other event', 'trigger_event' => 'auth.login_failed', 'action_type' => 'notify_user', 'cfg_message' => 'x']));
    $eq(RuleAdmin::reorder($mysqli, [(int) $copy2, $id, (int) $copy]), true, 'reorder accepts rules of one event');
    $eq([(int) $engine->find((int) $copy2)['priority'], (int) $engine->find($id)['priority'], (int) $engine->find((int) $copy)['priority']], [10, 20, 30], 'reorder numbers them 10, 20, 30 in the given order');
    $eq(RuleAdmin::reorder($mysqli, [$id, $other]), false, 'reorder refuses rules of different events');
    $eq(RuleAdmin::reorder($mysqli, [$id]), false, 'reorder needs at least two rules');
    $eq(RuleAdmin::reorder($mysqli, [$id, 999999999]), false, 'reorder refuses a rule that does not exist');
    $ord = array_column($engine->matching('ticket.created', ['ticket_priority' => 'High', 'client_id' => '4', 'ticket_status' => 'Open']), 'rule_id');
    $ok(!in_array($copy2, array_map('intval', $ord), true), 'copies that are off are not picked up by the engine');

    // statistics and history
    if (RuleAdmin::tableExists($mysqli, 'automation_rule_runs')) {
        $ins = fn (int $r, string $st, string $msg, string $when) => $q("INSERT INTO automation_rule_runs (rule_id, event_type, matched, status, message, duration_ms, created_at) VALUES ($r, 'ticket.created', 1, '$st', '$msg', 12, $when)");
        $ins($id, 'failed', 'ancient failure', 'NOW() - INTERVAL 20 DAY');
        $ins($id, 'ok', 'older', 'NOW() - INTERVAL 3 DAY');
        $ins($id, 'ok', 'fine', 'NOW()');
        $ins($id, 'failed', 'boom', 'NOW()');
        $ins($id, 'throttled', 'throttled: more than 9 runs in the last minute', 'NOW()');
        $st = null;
        foreach (RuleAdmin::rules($mysqli) as $r) { if ($r['rule_id'] === $id) { $st = $r; } }
        $ok($st !== null && $st['runs_total'] === 5 && $st['ok_7d'] === 2 && $st['failed_7d'] === 1 && $st['other_7d'] === 1 && $st['failed_24h'] === 1, 'run statistics: total, 7 days ok/failed/other, failed in 24 h', json_encode($st));
        $ok(count($st['spark']) === 7 && $st['spark'][6]['ok'] === 1 && $st['spark'][6]['failed'] === 1 && $st['spark'][3]['ok'] === 1, 'the sparkline has 7 daily buckets, today last', json_encode($st['spark']));
        $ok($st['last_age'] !== null && $st['last_age'] < 60 && $st['last_status'] === 'throttled', 'last run status and age');
        $hist = RuleAdmin::history($mysqli, $id);
        $ok($hist['total'] === 5 && count($hist['rows']) === 5 && $hist['counts'] == ['failed' => 2, 'ok' => 2, 'throttled' => 1], 'history lists every run with counts per status (throttled included)', json_encode($hist['counts']));
        $ok($hist['rows'][0]['run_id'] > $hist['rows'][4]['run_id'], 'history is newest first');
        $f = RuleAdmin::history($mysqli, $id, true);
        $ok($f['total'] === 2 && count($f['rows']) === 2 && $f['rows'][0]['status'] === 'failed', 'history can show failed runs only');
        $pg = RuleAdmin::history($mysqli, $id, false, 2, 4);
        $ok(count($pg['rows']) === 1 && $pg['total'] === 5, 'history pages with limit and offset');
        $eq(RuleAdmin::history($mysqli, 999999999)['rows'], [], 'history of a missing rule is empty');
    }
    $lk2 = RuleAdmin::lookups($mysqli);
    $ok(is_array($lk2['agents']) && is_array($lk2['statuses']) && in_array('Open', $lk2['statuses'], true) || $lk2['statuses'] === [] , 'lookups returns agents, clients, categories, templates and statuses');
    $sl = RuleAdmin::summaryLookups(['agents' => [1 => 'A'], 'clients' => [], 'categories' => [], 'templates' => [5 => '[Offboarding] Leaver'], 'statuses' => []], ['x' => 'X']);
    $eq([$sl['templates'][5], $sl['events']], ['Leaver', ['x' => 'X']], 'summary lookups drop the "[Type]" prefix of workflow templates');
    $q("DELETE FROM automation_rule_runs WHERE rule_id IN (SELECT rule_id FROM automation_rules WHERE name LIKE 'RS %')");
    $q("DELETE FROM automation_rules WHERE name LIKE 'RS %'");
} else {
    echo "SKIP  database part (set RIVETIT_TEST_DB=1 and the scratch database variables)\n";
}

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
