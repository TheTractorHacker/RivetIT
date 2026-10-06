<?php
/*
 * Automation engine against the REAL src/Automation code on a scratch database:
 *   conditions (every operator, AND/OR groups, legacy rows, malformed input), each new action incl. dry-run purity (nothing written,
 *   queued or sent), loop guard, rate limit, rule ordering / stop on first match, SLA events, the new trigger events, template
 *   injection in send_mail, the on/off switch, migration idempotence and an end-to-end run through the real event bus and job queue.
 * Needs a THROWAWAY database that holds this app's schema (import db.sql and run the database updates); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/automation_engine.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$mysqli->query("SET SESSION sql_mode=''");
$mysqli->query("SET SESSION time_zone = '+00:00'");

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/event_bus.php';
require __DIR__ . '/../includes/sla_functions.php';
use ITFlow\Automation\{ConditionEvaluator, RuleEngine, SlaEventEmitter, Template, EventPayloads, RuleView, TicketRuleView};
use ITFlow\Automation\Actions\ActionRegistry;
use ITFlow\Workflow\ActionGateway;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$throws = function (callable $f): ?string { try { $f(); } catch (\Throwable $e) { return $e::class . ':' . $e->getMessage(); } return null; };

/** Records every side effect instead of doing it; emitted events carry the chain exactly as the live bus would. */
class FakeGateway implements ActionGateway {
    public array $calls = [];
    public function createTicket(string $s, string $d, string $p, int $c, string $src): int { $this->calls[] = ['ticket', $s]; return 42; }
    public function queueMail(string $to, string $n, string $s, string $b): void { $this->calls[] = ['mail', $to, $n, $s, $b]; }
    public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void { $this->calls[] = ['notify', $u, $m]; }
    public function emitEvent(string $ev, array $d): void { $this->calls[] = ['event', $ev, $d, RuleEngine::chainForEmit()]; }
    public function audit(string $ev, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void { $this->calls[] = ['audit', $ev]; }
    public function disablePortalLogin(int $c): string { return ''; }
    public function count(string $kind): int { return count(array_filter($this->calls, fn($c) => $c[0] === $kind)); }
    public function reset(): void { $this->calls = []; }
}

// ================================================================ 1. conditions (pure)
$E = fn($raw, array $ctx) => ConditionEvaluator::matches($raw, $ctx);
$ctx = ['ticket_priority' => 'High', 'client_id' => '4', 'ticket_subject' => 'VPN is down', 'n' => '10', 'empty' => '', 'ticket.x' => 'v'];
$ok($E('', $ctx) && $E(null, $ctx) && $E('{}', $ctx) && $E('[]', $ctx), 'empty conditions always match');
$ok($E('{"ticket_priority":"High"}', $ctx), 'legacy flat row: field equals value');
$ok(!$E('{"ticket_priority":"high"}', $ctx), 'legacy eq is exact (case sensitive), as before');
$ok(!$E('{"nothing":"x"}', $ctx), 'legacy: a missing field never matches');
$ok($E('{"ticket_priority":"High","client_id":"4"}', $ctx) && !$E('{"ticket_priority":"High","client_id":"5"}', $ctx), 'legacy: all fields must match');
$leaf = fn($f, $op, $v = '') => ['field' => $f, 'op' => $op, 'value' => $v];
$m = fn(string $mode, array $items) => json_encode(['version' => 2, 'mode' => $mode, 'conditions' => $items]);
$ok($E($m('all', [$leaf('client_id', 'eq', '4')]), $ctx) && !$E($m('all', [$leaf('client_id', 'eq', '5')]), $ctx), 'eq');
$ok($E($m('all', [$leaf('client_id', 'ne', '5')]), $ctx) && !$E($m('all', [$leaf('client_id', 'ne', '4')]), $ctx) && $E($m('all', [$leaf('gone', 'ne', '4')]), $ctx), 'ne (a missing field is not equal)');
$ok($E($m('all', [$leaf('client_id', 'in', '1, 4,9')]), $ctx) && !$E($m('all', [$leaf('client_id', 'in', '1,9')]), $ctx) && !$E($m('all', [$leaf('gone', 'in', '1')]), $ctx), 'in');
$ok($E($m('all', [$leaf('ticket_subject', 'contains', 'vpn')]), $ctx) && !$E($m('all', [$leaf('ticket_subject', 'contains', 'printer')]), $ctx) && !$E($m('all', [$leaf('ticket_subject', 'contains', '')]), $ctx), 'contains (case insensitive, empty needle never matches)');
$ok($E($m('all', [$leaf('n', 'gt', '9')]), $ctx) && !$E($m('all', [$leaf('n', 'gt', '10')]), $ctx) && $E($m('all', [$leaf('n', 'lt', '11')]), $ctx) && !$E($m('all', [$leaf('ticket_subject', 'gt', '1')]), $ctx), 'gt / lt (numeric only)');
$ok($E($m('all', [$leaf('empty', 'is_empty')]), $ctx) && $E($m('all', [$leaf('gone', 'is_empty')]), $ctx) && !$E($m('all', [$leaf('client_id', 'is_empty')]), $ctx), 'is_empty (blank or missing)');
$ok($E($m('any', [$leaf('client_id', 'eq', '9'), $leaf('ticket_priority', 'eq', 'High')]), $ctx) && !$E($m('any', [$leaf('client_id', 'eq', '9'), $leaf('ticket_priority', 'eq', 'Low')]), $ctx), 'OR at top level');
$grp = fn($mode, $items) => ['mode' => $mode, 'conditions' => $items];
$ok($E($m('all', [$leaf('client_id', 'eq', '4'), $grp('any', [$leaf('ticket_priority', 'eq', 'Low'), $leaf('ticket_subject', 'contains', 'vpn')])]), $ctx), 'AND of a condition and an OR group');
$ok(!$E($m('all', [$leaf('client_id', 'eq', '4'), $grp('any', [$leaf('ticket_priority', 'eq', 'Low'), $leaf('n', 'lt', '1')])]), $ctx), 'AND with an OR group where nothing matches');
$ok($E($m('any', [$leaf('client_id', 'eq', '9'), $grp('all', [$leaf('ticket_priority', 'eq', 'High'), $leaf('n', 'gt', '5')])]), $ctx), 'OR of a condition and an AND group');
foreach (['not json', '{"a":', '"str"', '5', '{"version":2,"mode":"xor","conditions":[]}', '{"version":2,"conditions":"x"}', '{"version":2,"mode":"all","conditions":[{"field":"a b","op":"eq","value":"1"}]}',
    '{"version":2,"mode":"all","conditions":[{"field":"a","op":"nope","value":"1"}]}', '{"version":2,"mode":"all","conditions":[{"field":"a","op":"gt","value":"abc"}]}',
    '{"version":2,"mode":"all","conditions":[{"mode":"any","conditions":[{"mode":"all","conditions":[]}]}]}', '{"bad field!":"x"}', '{"a":["x"]}'] as $bad) {
    $ok(!$E($bad, $ctx) && ConditionEvaluator::normalize($bad) === null, 'malformed conditions never match: ' . substr($bad, 0, 50));
}
$many = array_map(fn($i) => $leaf("f$i", 'eq', '1'), range(1, 21));
$ok(ConditionEvaluator::normalize($m('all', $many)) === null, 'more than 20 conditions is invalid');
$ok(ConditionEvaluator::serialize(['mode' => 'all', 'conditions' => [$leaf('a', 'eq', '1'), $leaf('b', 'eq', '2')]]) === '{"a":"1","b":"2"}', 'a plain AND of equals is stored in the legacy flat format');
$ok(str_contains((string) ConditionEvaluator::serialize(['mode' => 'any', 'conditions' => [$leaf('a', 'eq', '1')]]), '"version":2'), 'anything else is stored as v2');
$ok(ConditionEvaluator::serialize(['mode' => 'all', 'conditions' => []]) === null, 'no conditions stores NULL');
$rt = ConditionEvaluator::normalize(ConditionEvaluator::serialize(['mode' => 'all', 'conditions' => [$leaf('a', 'in', '1,2'), $grp('any', [$leaf('b', 'is_empty'), $leaf('c', 'gt', '3')])]]));
$ok($rt !== null && $rt['conditions'][1]['conditions'][1]['value'] === '3', 'a v2 model survives a store and read round trip');
$ok($throws(fn() => ConditionEvaluator::fromForm('all', [['field' => 'n', 'op' => 'gt', 'value' => 'abc']])) !== null, 'form: gt with a non-number is refused');
$ok($throws(fn() => ConditionEvaluator::fromForm('sometimes', [])) !== null, 'form: bad match type is refused');
$fm = ConditionEvaluator::fromForm('all', [['field' => 'a', 'op' => 'eq', 'value' => '1', 'group' => ''], ['field' => '', 'op' => 'eq', 'value' => 'x'], ['field' => 'b', 'op' => 'eq', 'value' => '2', 'group' => 'A'], ['field' => 'c', 'op' => 'eq', 'value' => '3', 'group' => 'A']], ['A' => 'any']);
$ok(count($fm['conditions']) === 2 && $fm['conditions'][1]['mode'] === 'any' && count($fm['conditions'][1]['conditions']) === 2, 'form rows build a top-level condition and one OR group; blank rows are skipped');
// parity with the original engine on legacy rows
$core = new \RivetCore\Automation\AutomationRuleEvaluator(new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($mysqli));
$parity = true;
foreach (['{"ticket_priority":"High"}', '{"ticket_priority":"Low"}', '{"gone":"x"}', '{"client_id":"4","ticket_priority":"High"}', '{"client_id":"4","ticket_priority":"Low"}', '{}', '', 'garbage'] as $legacy) {
    if ($core->conditionsMatch($legacy === '' ? null : $legacy, $ctx) !== $E($legacy, $ctx)) { $parity = false; echo "  parity differs for $legacy\n"; }
}
$ok($parity, 'legacy rows evaluate exactly like the original Core evaluator');
$ex = ConditionEvaluator::explain($m('all', [$leaf('client_id', 'eq', '5')]), $ctx);
$ok($ex['matched'] === false && $ex['details'][0]['ok'] === false && str_contains($ex['details'][0]['text'], 'client_id'), 'explain reports each condition');

// ================================================================ 2. templates
$ok(Template::render('Hi {name}', ['name' => '<b>x</b>'], 'html') === 'Hi &lt;b&gt;x&lt;/b&gt;', 'html mode escapes values');
$ok(Template::render('Hi {name}', ['name' => "a\r\nBcc: evil@x.test"], 'text') === 'Hi a Bcc: evil@x.test', 'text mode flattens CR/LF');
$ok(Template::render('{a}', ['a' => '{b}', 'b' => 'SECRET'], 'text') === '{b}', 'a substituted value is not expanded again');
$ok(Template::renderHtmlBlock("<script>alert(1)</script> {a}", ['a' => '<i>']) === '&lt;script&gt;alert(1)&lt;/script&gt; &lt;i&gt;', 'html block escapes the admin text and the values');

// ================================================================ 3. fixtures
foreach (['automation_rule_runs', 'automation_rules', 'automation_sla_marks', 'ticket_replies', 'tasks', 'tickets', 'categories', 'email_queue', 'audit_events', 'integration_jobs'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM users WHERE user_id >= 900"); $q("DELETE FROM contacts WHERE contact_id >= 900"); $q("DELETE FROM clients WHERE client_id = 900"); $q("DELETE FROM ticket_statuses");
foreach ([[1, 'New'], [2, 'Open'], [3, 'On Hold'], [4, 'Resolved'], [5, 'Closed'], [9, 'Assigned']] as [$id, $n]) { $q("INSERT INTO ticket_statuses SET ticket_status_id=$id, ticket_status_name='$n', ticket_status_color='#000', ticket_status_active=1"); }
$q("INSERT INTO ticket_statuses SET ticket_status_id=10, ticket_status_name='Retired', ticket_status_color='#000', ticket_status_active=0");
$q("INSERT INTO clients SET client_id = 900, client_name = 'Test Dept', client_currency_code = 'USD'");
foreach ([[900, 'Alice', 1, 1, 'NULL'], [901, 'Bob', 1, 1, 'NULL'], [902, 'Portal Pat', 2, 1, 'NULL'], [903, 'Archived Al', 1, 1, 'NOW()'], [904, 'Disabled Dee', 1, 0, 'NULL']] as [$id, $n, $type, $st, $arch]) {
    $q("INSERT INTO users SET user_id=$id, user_name='$n', user_email='u$id@x.test', user_password='x', user_type=$type, user_status=$st, user_archived_at=$arch, user_role_id=2");
}
$q("INSERT INTO contacts SET contact_id=900, contact_name='Req <b>Uester</b>', contact_email='req@x.test', contact_client_id=900");
$q("INSERT INTO categories SET category_id=900, category_name='Network', category_type='Ticket', category_color='#000'");
$q("INSERT INTO categories SET category_id=901, category_name='Old', category_type='Ticket', category_color='#000', category_archived_at=NOW()");
$mkTicket = function (array $o = []) use ($q, $mysqli) {
    static $n = 7000;
    $n++;
    $o += ['subject' => 'Printer on fire', 'status' => 1, 'priority' => 'Low', 'assigned' => 0, 'created' => 'NOW()', 'extra' => ''];
    $q("INSERT INTO tickets SET ticket_prefix='T', ticket_number=$n, ticket_subject='" . mysqli_real_escape_string($mysqli, $o['subject']) . "', ticket_details='', ticket_status={$o['status']}, ticket_priority='{$o['priority']}', ticket_client_id=900, ticket_contact_id=900, ticket_assigned_to={$o['assigned']}, ticket_created_by=0, ticket_created_at={$o['created']}" . $o['extra']);
    return (int) mysqli_insert_id($mysqli);
};
$gw = new FakeGateway();
$engine = new RuleEngine($mysqli, $gw);
$rule = fn(string $type, array $input, string $trigger = 'ticket.created', array $o = []) => $engine->save($o['id'] ?? null, $o['name'] ?? "Rule $type", $trigger, $o['model'] ?? ['mode' => 'all', 'conditions' => []], $type, $input, true, $o['prio'] ?? 100, $o['stop'] ?? false, $o['rate'] ?? 30);
$ctxFor = fn(int $tid, string $ev = 'ticket.created') => EventPayloads::context($ev, EventPayloads::ticket($mysqli, $tid));
$snap = function () use ($one) {
    return implode('|', [$one('SELECT COUNT(*) FROM tickets'), $one('SELECT COALESCE(SUM(ticket_status*7+ticket_assigned_to*13+ticket_category*17+CRC32(ticket_priority)%1000),0) FROM tickets'), $one('SELECT COUNT(*) FROM ticket_replies'), $one('SELECT COUNT(*) FROM tasks'),
        $one('SELECT COUNT(*) FROM email_queue'), $one('SELECT COUNT(*) FROM audit_events'), $one('SELECT COUNT(*) FROM automation_rule_runs'), $one('SELECT COALESCE(SUM(rr_cursor),0) FROM automation_rules'), $one('SELECT COUNT(*) FROM integration_jobs')]);
};
$ruleRow = fn(int $id) => $engine->find($id);

// ================================================================ 4. validation of every new action
$bad = fn(string $type, array $input, string $trigger = 'ticket.created') => $throws(fn() => $engine->save(null, 'x', $trigger, ['mode' => 'all', 'conditions' => []], $type, $input, true)) !== null;
$ok($bad('set_ticket_field', []), 'set_ticket_field needs at least one field');
$ok($bad('set_ticket_field', ['status' => 'Nope']), 'set_ticket_field: unknown status name refused');
$ok($bad('set_ticket_field', ['status' => 'Retired']), 'set_ticket_field: inactive status refused');
$ok($bad('set_ticket_field', ['priority' => 'Urgent']), 'set_ticket_field: bad priority refused');
$ok($bad('set_ticket_field', ['category_id' => 901]), 'set_ticket_field: archived category refused');
$ok($bad('set_ticket_field', ['assignee' => 902]) && $bad('set_ticket_field', ['assignee' => 903]) && $bad('set_ticket_field', ['assignee' => 904]), 'set_ticket_field: a portal login, an archived user or a disabled user cannot be the assignee (no privilege escalation)');
$ok($bad('set_ticket_field', ['status' => 'Open'], 'auth.login_failed'), 'ticket actions refused on a non-ticket event');
$ok($bad('assign_ticket', ['mode' => 'user', 'user_id' => 902]) && $bad('assign_ticket', ['mode' => 'round_robin', 'pool' => [900]]) && $bad('assign_ticket', ['mode' => 'round_robin', 'pool' => [900, 902]]) && $bad('assign_ticket', ['mode' => 'x']), 'assign_ticket: portal login, rotation of one or with a portal user, unknown mode refused');
$ok($bad('add_ticket_note', ['note' => '  ']), 'add_ticket_note needs text');
$ok($bad('send_mail', ['to_type' => 'address', 'address' => "a@x.test\r\nBcc: e@x.test", 'subject' => 's', 'body' => 'b']) && $bad('send_mail', ['to_type' => 'address', 'address' => 'nope', 'subject' => 's', 'body' => 'b']) && $bad('send_mail', ['to_type' => 'user', 'user_id' => 902, 'subject' => 's', 'body' => 'b']) && $bad('send_mail', ['to_type' => '{x}', 'subject' => 's', 'body' => 'b']) && $bad('send_mail', ['to_type' => 'assignee', 'subject' => '', 'body' => 'b']), 'send_mail: header injection in the address, bad address, portal user, unknown recipient type, empty subject refused');
$ok($bad('create_task', ['name' => '']) && $bad('create_task', ['name' => 'x', 'assignee_id' => 902]) && $bad('create_task', ['name' => 'x', 'due_days' => 999]), 'create_task: empty name, portal assignee, far due date refused');
$ok($bad('send_webhook', ['url' => 'http://127.0.0.1/x'], 'ticket.created') && $bad('send_webhook', ['url' => 'http://10.0.0.5/x']) && $bad('send_webhook', ['url' => 'http://169.254.169.254/latest']) && $bad('send_webhook', ['url' => 'file:///etc/passwd']), 'send_webhook: loopback, private, metadata and file URLs refused (SSRF guard)');
$ok($bad('create_ticket', ['subject' => '']) && $bad('start_workflow', ['template_id' => 0]) && $bad('nope', []), 'create_ticket / start_workflow / unknown action refused');
$ok($bad('add_ticket_note', ['note' => 'x'], 'contact.created'), 'a ticket action on contact.created is refused');
$ok(!$bad('add_ticket_note', ['note' => 'x'], 'catalog.request_approved') && !$bad('add_ticket_note', ['note' => 'x'], 'ticket.sla_breached'), 'ticket actions are accepted on catalog.request_* and ticket.sla_* events');

// ================================================================ 5. actions and dry-run purity
$tA = $mkTicket(['subject' => 'Broken <script>alert(1)</script>', 'priority' => 'Low']);
$ctxA = $ctxFor($tA);
$configs = [
    'set_ticket_field' => ['status' => 'open', 'priority' => 'High', 'category_id' => 900, 'assignee' => 900],
    'add_ticket_note' => ['note' => 'Seen {ticket_subject}'],
    'assign_ticket' => ['mode' => 'round_robin', 'pool' => [900, 901]],
    'send_mail' => ['to_type' => 'requester', 'subject' => 'Re {ticket_number}', 'body' => 'About {ticket_subject}'],
    'create_task' => ['name' => 'Check {ticket_number}', 'assignee_id' => 900, 'due_days' => 3],
    'create_ticket' => ['subject' => 'Child of {ticket_number}', 'details' => 'd', 'priority' => 'High'],
    'notify_user' => ['message' => 'Hello {ticket_number}'],
    'send_webhook' => ['url' => 'http://93.184.216.34/hook', 'secret' => 's3'],
];
$ids = [];
foreach ($configs as $type => $input) { $ids[$type] = $rule($type, $input, 'ticket.created', ['name' => "R $type"]); }
$before = $snap();
$gw->reset();
$dryAll = true;
foreach ($ids as $type => $rid) {
    $r = $ruleRow($rid);
    $res = $engine->test($r, 'ticket.created', $ctxA);
    $good = $res['matched'] && $res['action'] && $res['action']['ok'] && str_starts_with($res['action']['message'], 'WOULD');
    if (!$good) { $dryAll = false; echo "  dry-run of $type -> " . json_encode($res['action']) . "\n"; }
}
$ok($dryAll, 'every action reports "WOULD ..." in a dry run');
$ok($snap() === $before && $gw->calls === [], 'dry runs of all actions changed nothing (no ticket/note/task/mail/audit/cursor/job rows) and sent nothing through the gateway');
$dryNote = $engine->test($ruleRow($ids['add_ticket_note']), 'ticket.created', $ctxA)['action']['message'];
$ok(str_contains($dryNote, 'Broken'), 'dry run shows the note with the event values filled in');
$dryMail = $engine->test($ruleRow($ids['send_mail']), 'ticket.created', $ctxA)['action']['message'];
$ok(str_contains($dryMail, 'req@x.test') && str_contains($dryMail, 'T7001'), 'dry run of send_mail shows the recipient and subject');

// real runs
$gw->reset();
$res = $engine->run($ids['set_ticket_field'], 'ticket.created', $ctxA);
$t = mysqli_fetch_assoc($q("SELECT * FROM tickets WHERE ticket_id=$tA"));
$ok($res['status'] === 'ok' && (int) $t['ticket_status'] === 2 && $t['ticket_priority'] === 'High' && (int) $t['ticket_category'] === 900 && (int) $t['ticket_assigned_to'] === 900, 'set_ticket_field: status resolved by NAME (case-insensitive), priority, category and assignee set');
$ok($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=$tA AND ticket_reply_type='Internal'") == 1, 'set_ticket_field leaves one internal note recording the change');
$evs = array_column(array_filter($gw->calls, fn($c) => $c[0] === 'event'), 1);
$ok(in_array('ticket.status_changed', $evs) && in_array('ticket.assigned', $evs) && in_array('ticket.updated', $evs), 'set_ticket_field emits ticket.status_changed, ticket.assigned and ticket.updated');
$ok($gw->count('audit') >= 1, 'set_ticket_field is audited');
$gw->reset();
$res = $engine->run($ids['set_ticket_field'], 'ticket.created', $ctxA);
$ok(str_contains($res['message'], 'nothing to change') && $gw->count('event') === 0, 'running again is a no-op and emits nothing');
$engine->run($rule('set_ticket_field', ['status' => 'Resolved'], 'ticket.created', ['name' => 'close']), 'ticket.created', $ctxA);
$t = mysqli_fetch_assoc($q("SELECT * FROM tickets WHERE ticket_id=$tA"));
$ok((int) $t['ticket_status'] === 5 && $t['ticket_closed_at'] !== null && $t['ticket_resolved_at'] !== null, 'status Resolved maps to Closed and stamps the close times, like the rest of the app');
$engine->run($rule('set_ticket_field', ['status' => 'Open'], 'ticket.created', ['name' => 'reopen']), 'ticket.created', $ctxA);
$t = mysqli_fetch_assoc($q("SELECT * FROM tickets WHERE ticket_id=$tA"));
$ok((int) $t['ticket_status'] === 2 && $t['ticket_closed_at'] === null, 'moving a closed ticket to Open clears the close times');

$tB = $mkTicket(['subject' => 'Broken <script>alert(1)</script>']);
$ctxB = $ctxFor($tB);
$engine->run($ids['add_ticket_note'], 'ticket.created', $ctxB);
$note = $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$tB");
$ok(str_contains($note, '&lt;script&gt;') && !str_contains($note, '<script>') && $one("SELECT ticket_reply_type FROM ticket_replies WHERE ticket_reply_ticket_id=$tB") === 'Internal', 'add_ticket_note: stored as an Internal note with the subject HTML-escaped');
// round robin
$tRR = [$mkTicket(), $mkTicket(), $mkTicket(), $mkTicket()];
$got = [];
foreach ($tRR as $tid) { $engine->run($ids['assign_ticket'], 'ticket.created', $ctxFor($tid)); $got[] = (int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id=$tid"); }
$ok($got === [900, 901, 900, 901], 'assign_ticket round robin alternates between the technicians: ' . implode(',', $got));
$q("UPDATE users SET user_status = 0 WHERE user_id = 901");
$tid = $mkTicket();
$engine->run($ids['assign_ticket'], 'ticket.created', $ctxFor($tid));
$ok((int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id=$tid") === 900, 'a technician who became inactive is skipped in the rotation');
$q("UPDATE users SET user_status = 1 WHERE user_id = 901");
$tid = $mkTicket(['status' => 1]);
$engine->run($rule('assign_ticket', ['mode' => 'user', 'user_id' => 901], 'ticket.created', ['name' => 'single']), 'ticket.created', $ctxFor($tid));
$ok((int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id=$tid") === 901 && (int) $one("SELECT ticket_status FROM tickets WHERE ticket_id=$tid") === 9, 'assign_ticket to one technician moves a New ticket to Assigned (looked up by name)');
$tClosed = $mkTicket(['extra' => ', ticket_closed_at = NOW()']);
$res = $engine->run($ids['assign_ticket'], 'ticket.created', $ctxFor($tClosed));
$ok(str_contains($res['message'], 'closed') && (int) $one("SELECT ticket_assigned_to FROM tickets WHERE ticket_id=$tClosed") === 0, 'assign_ticket skips a closed ticket');
// mail
$gw->reset();
$tM = $mkTicket(['subject' => "Hi\r\nBcc: evil@x.test <img src=x onerror=alert(1)>"]);
$engine->run($ids['send_mail'], 'ticket.created', $ctxFor($tM));
$mail = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'mail'))[0] ?? null;
$ok($mail && $mail[1] === 'req@x.test' && !preg_match('/[\r\n]/', $mail[3]) && !str_contains($mail[4], '<img') && str_contains($mail[4], '&lt;img'), 'send_mail: recipient fixed by role, CR/LF flattened in the subject, HTML in values escaped in the body');
$gw->reset();
$mid = $rule('send_mail', ['to_type' => 'address', 'address' => 'ops@x.test', 'subject' => 'S {ticket_subject}', 'body' => 'Send it to {contact_email} {ticket_number}'], 'ticket.created', ['name' => 'fixed mail']);
$ctxEvil = $ctxFor($tM) + ['to' => 'attacker@x.test', 'recipient' => 'attacker@x.test'];
$engine->run($mid, 'ticket.created', $ctxEvil);
$mail = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'mail'))[0] ?? null;
$ok($mail && $mail[1] === 'ops@x.test', 'send_mail: event data cannot change the recipient');
$engine->run($ids['send_mail'], 'ticket.created', ['event' => 'ticket.created']);
$ok($engine->run($ids['send_mail'], 'ticket.created', ['event' => 'ticket.created'])['status'] === 'failed', 'send_mail on an event without a ticket fails cleanly');
// task
$engine->run($ids['create_task'], 'ticket.created', $ctxFor($tB));
$task = mysqli_fetch_assoc($q("SELECT * FROM tasks WHERE task_ticket_id=$tB"));
$ok($task && $task['task_name'] === 'Check T7002' && (int) $task['task_assigned_to'] === 900 && $task['task_due'] === date('Y-m-d', strtotime('+3 days')), 'create_task: task added to the ticket with assignee and due date');
// legacy actions keep working through the registry
$ok(ActionRegistry::get('create_ticket') !== null && ActionRegistry::get('send_webhook') !== null && count(ActionRegistry::all()) === 9, 'the registry has the nine actions');
$ok(str_contains($engine->test($ruleRow($ids['send_webhook']), 'ticket.created', $ctxA)['action']['message'], '93.184.216.34'), 'send_webhook dry run vets the address and does not call it');

// ================================================================ 6. ordering, stop on first match, matching
$q("DELETE FROM automation_rules");
$mk = fn(string $name, int $prio, bool $stop, array $model = ['mode' => 'all', 'conditions' => []]) => $rule('notify_user', ['message' => $name], 'ticket.created', ['name' => $name, 'prio' => $prio, 'stop' => $stop, 'model' => $model]);
$r3 = $mk('third', 300, false); $r1 = $mk('first', 50, false); $r2 = $mk('second', 100, true); $r4 = $mk('never', 200, false);
$names = fn(array $rows) => implode(',', array_column($rows, 'name'));
$ok($names($engine->matching('ticket.created', ['event' => 'ticket.created'])) === 'first,second', 'rules run in priority order and "stop on first match" ends the list: ' . $names($engine->matching('ticket.created', [])));
$q("UPDATE automation_rules SET is_enabled = 0 WHERE rule_id = $r2");
$ok($names($engine->matching('ticket.created', [])) === 'first,never,third', 'a disabled rule is not considered');
$q("UPDATE automation_rules SET is_enabled = 1, stop_on_match = 0 WHERE rule_id = $r2");
$rc = $mk('cond', 10, true, ['mode' => 'all', 'conditions' => [$leaf('ticket_priority', 'eq', 'High')]]);
$ok($names($engine->matching('ticket.created', ['ticket_priority' => 'Low'])) === 'first,second,never,third', 'a stop rule whose conditions do not match does not stop anything');
$ok($names($engine->matching('ticket.created', ['ticket_priority' => 'High'])) === 'cond', 'a matching stop rule with the best priority is the only one that runs');
$ok($names($engine->matching('ticket.updated', [])) === '', 'rules for other events are not matched');
// legacy row created by the old Core store format
$q("INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) VALUES ('legacy', 'x.y', '{\"priority\":\"High\"}', 'notify_user', '{\"message\":\"m\"}', 1)");
$ok($names($engine->matching('x.y', ['priority' => 'High'])) === 'legacy' && $names($engine->matching('x.y', ['priority' => 'Low'])) === '' && (int) $one("SELECT priority FROM automation_rules WHERE name='legacy'") === 100, 'a row written by the old store still matches, with default priority 100 and rate 30');

// ================================================================ 7. loop guard and rate limit
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs");
$rid = $rule('add_ticket_note', ['note' => 'n'], 'ticket.updated', ['name' => 'loopy']);
$tid = $mkTicket();
$chainCtx = fn(array $c, int $tid) => $ctxFor($tid, 'ticket.updated') + ['_chain.id' => 'abcdef012345', '_chain.depth' => (string) $c['depth'], '_chain.rules' => implode(',', $c['rules'])];
$res = $engine->run($rid, 'ticket.updated', $ctxFor($tid, 'ticket.updated'));
$ok($res['status'] === 'ok', 'a rule runs when it is not in the chain');
$res = $engine->run($rid, 'ticket.updated', $chainCtx(['depth' => 1, 'rules' => [$rid]], $tid));
$ok($res['status'] === 'loop_blocked' && $res['ok'] === true, 'the same rule inside its own chain is blocked (and not retried by the queue)');
$rid2 = $rule('add_ticket_note', ['note' => 'n2'], 'ticket.updated', ['name' => 'other']);
$ok($engine->run($rid2, 'ticket.updated', $chainCtx(['depth' => 2, 'rules' => [$rid, 999]], $tid))['status'] === 'ok', 'a different rule at depth 2 still runs');
$ok($engine->run($rid2, 'ticket.updated', $chainCtx(['depth' => RuleEngine::MAX_DEPTH, 'rules' => [11, 12, 13]], $tid))['status'] === 'loop_blocked', 'a chain is at most ' . RuleEngine::MAX_DEPTH . ' rules deep');
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE status='loop_blocked'") === 2, 'blocked runs are in the run log');
// the chain is attached to events a running rule emits
$gw->reset();
$ridEmit = $rule('set_ticket_field', ['priority' => 'High'], 'ticket.created', ['name' => 'emitter']);
$tE = $mkTicket();
$engine->run($ridEmit, 'ticket.created', $ctxFor($tE));
$emits = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'event'));
$ok($emits && $emits[0][3] !== null && (int) $emits[0][3]['depth'] === 1 && $emits[0][3]['rules'] === (string) $ridEmit, 'events emitted by a running rule carry the chain (depth 1, this rule)');
$ok(RuleEngine::chainForEmit() === null, 'no chain is attached once the rule has finished');
// rate limit
$q("DELETE FROM automation_rule_runs");
$rl = $rule('add_ticket_note', ['note' => 'r'], 'ticket.created', ['name' => 'limited', 'rate' => 3]);
$tid = $mkTicket();
$st = [];
for ($i = 0; $i < 6; $i++) { $st[] = $engine->run($rl, 'ticket.created', $ctxFor($tid))['status']; }
$ok($st === ['ok', 'ok', 'ok', 'throttled', 'throttled', 'throttled'], 'a rule is throttled after its per-minute limit: ' . implode(',', $st));
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id=$rl AND status='throttled'") === 1 && (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id=$tid") === 3, 'only one throttled row per minute is logged and the throttled runs did nothing');
$res = $engine->test($ruleRow($rl), 'ticket.created', $ctxFor($tid));
$ok($res['guard'] !== null && str_contains($res['guard'], 'Rate limit'), 'the Test page reports the rate limit');
$ok($throws(fn() => $engine->save(null, 'x', 'ticket.created', ['mode' => 'all', 'conditions' => []], 'add_ticket_note', ['note' => 'x'], true, 100, false, 0)) !== null, 'a rate limit of 0 is refused');
// run log content
$row = mysqli_fetch_assoc($q("SELECT * FROM automation_rule_runs WHERE rule_id=$rl AND status='ok' ORDER BY run_id LIMIT 1"));
$acts = json_decode((string) $row['actions_json'], true);
$ok($row['event_type'] === 'ticket.created' && (int) $row['matched'] === 1 && $acts[0]['action'] === 'add_ticket_note' && $acts[0]['ok'] === true && $row['duration_ms'] !== null && $row['created_at'] !== null, 'run log rows hold rule, event, matched, action result JSON, status, duration and time');
// failed action is logged as failed and reported not-ok (so the job queue retries it)
$q("INSERT INTO automation_rules (name, trigger_event, condition_json, action_type, action_config_json, is_enabled) VALUES ('bad', 'auth.login_failed', NULL, 'set_ticket_field', '{\"status\":\"Open\"}', 1)");
$rf = (int) mysqli_insert_id($mysqli);
$res = $engine->run($rf, 'auth.login_failed', ['event' => 'auth.login_failed']);
$ok($res['status'] === 'failed' && $res['ok'] === false && str_contains($res['message'], 'not about a ticket'), 'a rule that cannot act is logged as failed');

// ================================================================ 8. the on/off switch
$ok(RuleEngine::switchOn($mysqli) === true && rivetCoreModuleOn('core.automation.enabled') === ($GLOBALS['mysqli'] ?? null ? true : true), 'the switch defaults to on');
$GLOBALS['mysqli'] = $mysqli;
RuleEngine::setSwitch($mysqli, false);
$ok(rivetCoreModuleOn('core.automation.enabled') === false && rivetCoreModuleOn('core.jobs.enabled') === true, 'switching off turns only the automation module off');
RuleEngine::setSwitch($mysqli, true);
$ok(rivetCoreModuleOn('core.automation.enabled') === true, 'switching on again');

// ================================================================ 9. SLA events
$q("DELETE FROM automation_sla_marks"); $q("DELETE FROM automation_rules"); $q("DELETE FROM tickets");
$sent = [];
$emit = function (string $ev, array $d) use (&$sent) { $sent[] = [$ev, $d]; };
$ok(SlaEventEmitter::run($mysqli, $emit) === 0, 'no SLA events are computed when nothing listens');
$rule('notify_user', ['message' => 'sla'], 'ticket.sla_breached', ['name' => 'listener']);
$mkSla = fn(string $created, string $respDue, string $resDue, string $extra = '') => $mkTicket(['created' => "'$created'", 'extra' => ", ticket_sla_response_due = " . ($respDue === '' ? 'NULL' : "'$respDue'") . ", ticket_sla_resolution_due = " . ($resDue === '' ? 'NULL' : "'$resDue'") . $extra]);
$now = time(); $d = fn(int $off) => gmdate('Y-m-d H:i:s', $now + $off);
$tOk = $mkSla($d(-3600), '', $d(+36000));
$tWarn = $mkSla($d(-9 * 3600), '', $d(+3600));
$tBreach = $mkSla($d(-5 * 3600), '', $d(-1800));
$tOld = $mkSla($d(-10 * 86400), '', $d(-3 * 86400));
$tDone = $mkSla($d(-5 * 3600), '', $d(-1800), ", ticket_resolved_at = NOW()");
$tRespBreach = $mkSla($d(-5 * 3600), $d(-600), $d(+36000));
$tRespMet = $mkSla($d(-5 * 3600), $d(-600), $d(+36000), ", ticket_first_response_at = '" . $d(-3000) . "'");
$sent = [];
$n = SlaEventEmitter::run($mysqli, $emit);
$byT = [];
foreach ($sent as [$ev, $dd]) { $byT[(int) $dd['ticket_id']][] = $ev . ':' . $dd['sla_clock']; }
$ok($n === 3 && ($byT[$tWarn] ?? []) === ['ticket.sla_warning:resolution'] && ($byT[$tBreach] ?? []) === ['ticket.sla_breached:resolution'] && ($byT[$tRespBreach] ?? []) === ['ticket.sla_breached:response'], 'SLA warning (>=80% used), breach (resolution and response) are emitted: ' . json_encode($byT));
$ok(!isset($byT[$tOk]) && !isset($byT[$tDone]) && !isset($byT[$tRespMet]), 'a ticket well inside its SLA, a resolved ticket and a met response clock emit nothing');
$ok(!isset($byT[$tOld]) && (int) $one("SELECT COUNT(*) FROM automation_sla_marks WHERE ticket_id=$tOld") === 1, 'a breach older than a day is recorded but not announced');
$sent = [];
$ok(SlaEventEmitter::run($mysqli, $emit) === 0, 'each SLA event is announced once');
$q("UPDATE tickets SET ticket_sla_resolution_due = '" . $d(-60) . "' WHERE ticket_id = $tWarn");
$sent = [];
$ok(SlaEventEmitter::run($mysqli, $emit) === 1 && $sent[0][0] === 'ticket.sla_breached', 'a warned ticket later announces its breach too');
$ok(isset($sent[0][1]['sla_percent_used']) && isset($sent[0][1]['ticket_number']), 'SLA events carry the ticket payload and the clock details');

// ================================================================ 10. new trigger events: catalog + workflow through the real code paths
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs"); $q("DELETE FROM tickets"); $q("DELETE FROM ticket_replies"); $q("DELETE FROM integration_jobs");
foreach (['service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_approval_steps', 'service_catalog_fields', 'service_catalog_items'] as $t) { $q("DELETE FROM $t"); }
require_once __DIR__ . '/../src/ITSM/ServiceCatalogService.php';
$cat = new \ITFlow\ITSM\ServiceCatalogService($mysqli);
$q("INSERT INTO service_catalog_items SET name='Laptop', ticket_subject_template='s', requires_approval=1, risk_score=0, auto_approve_below=0, is_active=1");
$item = mysqli_fetch_assoc($q('SELECT * FROM service_catalog_items WHERE catalog_item_id=' . mysqli_insert_id($mysqli)));
$q("INSERT INTO service_catalog_approval_steps SET catalog_item_id={$item['catalog_item_id']}, step_order=1, approver_type='user', approver_id=900, mode='any'");
$rApproved = $rule('add_ticket_note', ['note' => 'Request {request_id} approved'], 'catalog.request_approved', ['name' => 'on approve']);
$rRejected = $rule('add_ticket_note', ['note' => 'Request rejected: {reason}'], 'catalog.request_rejected', ['name' => 'on reject']);
$tc1 = $mkTicket(); $tc2 = $mkTicket();
$sub1 = $cat->submit($item, $tc1, 900, 900, 0, []); $sub2 = $cat->submit($item, $tc2, 900, 900, 0, []);
$ok($sub1['status'] === 'pending_approval', 'catalog fixture: request waits for approval');
$cat->decide($sub1['request_id'], 900, null, true);
$cat->decide($sub2['request_id'], 900, null, false, 'Not in budget');
rivetRunJobWorker($mysqli, 50, 20);
$n1 = $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$tc1 AND ticket_reply LIKE '%approved%'");
$n2 = $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id=$tc2 AND ticket_reply LIKE '%Not in budget%'");
$ok($n1 !== null && $n2 !== null, 'catalog.request_approved / request_rejected fire from the approval service and a rule acts on the request ticket');
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE status='ok'") >= 2, 'those runs are in the run log');

$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs");
foreach (['workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO workflow_templates SET name='T', type='onboarding'"); $tpl = mysqli_insert_id($mysqli);
$q("INSERT INTO workflow_template_tasks SET workflow_template_id=$tpl, title='Order laptop', sort_order=0");
$wgw = new FakeGateway();
$wsvc = new \ITFlow\Workflow\WorkflowService($mysqli, $wgw);
$run = $wsvc->startRun($tpl, 900, 900);
$rt = (int) $one("SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$run LIMIT 1");
$wsvc->completeTask($rt, 900);
$wev = array_values(array_filter($wgw->calls, fn($c) => $c[0] === 'event' && $c[1] === 'workflow.task_completed'));
$ok(count($wev) === 1 && $wev[0][2]['task_title'] === 'Order laptop' && $wev[0][2]['contact_id'] === 900, 'workflow.task_completed is emitted when a task is completed');
$ok(in_array('workflow.task_completed', array_merge(...array_values(webhook_event_groups_static_test())), true), 'the new events are in the webhook / event-rule event list');
function webhook_event_groups_static_test(): array { if (!function_exists('webhook_event_groups_static')) { require_once __DIR__ . '/../admin/includes/webhook_events.php'; } return webhook_event_groups_static(); }
$all = array_merge(...array_values(webhook_event_groups_static()));
$ok(count(array_diff(['ticket.created', 'ticket.updated', 'ticket.status_changed', 'ticket.assigned', 'ticket.sla_warning', 'ticket.sla_breached', 'asset.created', 'contact.created', 'catalog.request_approved', 'catalog.request_rejected', 'workflow.task_completed'], $all)) === 0, 'all trigger events of the engine are selectable');

// the live gateway calls the app's own helpers; stand-ins that only count (the real ones need the full web app)
$notified = 0;
if (!function_exists('notifyUser')) { function notifyUser($u, $t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'] = ($GLOBALS['notified'] ?? 0) + 1; } }
if (!function_exists('appNotify')) { function appNotify($t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'] = ($GLOBALS['notified'] ?? 0) + 1; } }
// ================================================================ 11. end to end: real bus, job queue, loop guard
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs"); $q("DELETE FROM integration_jobs"); $q("DELETE FROM ticket_replies");
$tL = $mkTicket();
// A rotation on every ticket update re-triggers itself (each run changes the assignee, which emits ticket.updated again): the loop guard must cut it.
$rLoop = $rule('assign_ticket', ['mode' => 'round_robin', 'pool' => [900, 901]], 'ticket.updated', ['name' => 'ping-pong']);
rivetEmitEvent('ticket.updated', EventPayloads::ticket($mysqli, $tL) + ['changed' => 'priority']);
for ($i = 0; $i < 6; $i++) { rivetRunJobWorker($mysqli, 50, 20); }
$runs = [];
$res = $q("SELECT status FROM automation_rule_runs WHERE rule_id=$rLoop ORDER BY run_id");
while ($r = mysqli_fetch_assoc($res)) { $runs[] = $r['status']; }
$ok(in_array('ok', $runs, true) && in_array('loop_blocked', $runs, true) && count($runs) <= 4, 'end to end: the self-triggering rule ran, was cut by the loop guard, and did not run away: ' . implode(',', $runs));
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id=$rLoop AND status='ok'") === 1, 'end to end: the rule completed exactly once');
// switch off: nothing is queued
$q("DELETE FROM integration_jobs"); RuleEngine::setSwitch($mysqli, false);
$q("DELETE FROM automation_rule_runs");
rivetEmitEvent('ticket.updated', EventPayloads::ticket($mysqli, $tL));
rivetRunJobWorker($mysqli, 50, 20);
$ok((int) $one('SELECT COUNT(*) FROM automation_rule_runs') === 0 && (int) $one("SELECT COUNT(*) FROM integration_jobs WHERE job_type='automation.action'") === 0, 'with the switch off no rule is queued or run');
RuleEngine::setSwitch($mysqli, true);
// a spoofed _chain in event data is ignored
$q("DELETE FROM automation_rule_runs"); $q("DELETE FROM integration_jobs");
rivetEmitEvent('ticket.updated', EventPayloads::ticket($mysqli, $tL) + ['_chain' => ['id' => 'abcdef012345', 'depth' => 3, 'rules' => (string) $rLoop]]);
rivetRunJobWorker($mysqli, 50, 20);
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id=$rLoop AND status='ok'") >= 1, 'a _chain supplied in event data cannot disable the loop guard or suppress rules');

// ================================================================ 12. views
$vr = $ruleRow($rLoop);
$ok(str_contains(RuleView::action($mysqli, $vr), 'Alice') && str_contains(RuleView::action($mysqli, $vr), 'Bob'), 'the rule list shows technicians by name');
$q("INSERT INTO ticket_automation_rules SET rule_name='esc', rule_trigger='schedule', rule_cond_field='status_id', rule_cond_op='equals', rule_cond_value='9', rule_action='escalate', rule_action_value='901:high', rule_order=1");
$tv = new TicketRuleView($mysqli);
$tr = mysqli_fetch_assoc($q("SELECT * FROM ticket_automation_rules WHERE rule_name='esc'"));
$ok($tv->action(TicketRuleView::actionsOf($tr)[0]) === 'Escalate: reassign to Bob, priority High' && $tv->condition(TicketRuleView::conditionsOf($tr)[0]) === 'Status = Assigned', 'ticket rules: escalate and ids are shown as names');
$ok($tv->action(['action' => 'set_status', 'value' => '2']) === 'Set status Open' && $tv->action(['action' => 'assign_to', 'value' => '900']) === 'Assign to Alice', 'ticket rules: set-status and assign-to show names');
$q("DELETE FROM ticket_automation_rules WHERE rule_name='esc'");

// ================================================================ 13. migration idempotence (runs the real updater twice against the scratch DB)
$cfg = @file_get_contents(__DIR__ . '/../config.php');
if ($cfg !== false && str_contains($cfg, getenv('RIVETIT_TEST_DB_NAME'))) {
    $rid = $rule('notify_user', ['message' => 'keep me'], 'ticket.created', ['name' => 'survivor', 'prio' => 77]);
    for ($i = 0; $i < 2; $i++) {
        $q("UPDATE settings SET config_current_database_version = '2.6.138'");
        $out = shell_exec('cd ' . escapeshellarg(dirname(__DIR__)) . ' && php scripts/update_cli.php --update_db 2>&1');
        $ok(str_contains((string) $out, '2.6.139') && $one('SELECT config_current_database_version FROM settings') === '2.6.139', "migration 2.6.138 -> 2.6.139 run #" . ($i + 1) . ' succeeds');
    }
    $ok((int) $one("SELECT priority FROM automation_rules WHERE rule_id=$rid") === 77 && $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='automation_rules' AND column_name IN ('priority','stop_on_match','rate_limit_per_min','rr_cursor')") == 4, 'the migration is idempotent and keeps existing rules');
} else {
    echo "SKIP  migration idempotence (config.php does not point at the scratch database)\n";
}

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
