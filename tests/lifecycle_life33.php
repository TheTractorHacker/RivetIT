<?php
/*
 * Lifecycle follow-ups (issues #31 / #33 / #30), DB 2.6.242-2.6.243, on a SCRATCH database only:
 *   1. Okta connector (create / suspend / add to groups) against an in-process Okta mock: default off, never deletes, department check, no secrets in messages.
 *   2. HR-initiated portal requests: validation, scoping, pending-until-approved, approve starts the run, reject / cancel start nothing, audit, notifications.
 *   3. Legacy ticket rules in the unified rules list: states, one-click convert, switch over, behaviour of the copy.
 *   4. Migration 2.6.242 / 2.6.243: re-running changes nothing.
 * Nothing real is contacted: no Okta, no mail, no chat, no Odoo.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=.. RIVETIT_TEST_DB_PASS=.. php tests/lifecycle_life33.php
 * config.php must point at the same scratch database.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch\n"); exit(2); }
chdir(dirname(__DIR__));
$ROOT = getcwd();
require_once $ROOT . '/config.php';
if ($database !== getenv('RIVETIT_TEST_DB_NAME')) { fwrite(STDERR, "Refusing: config.php must point at the scratch database\n"); exit(2); }
require_once $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/functions.php';
require_once $ROOT . '/includes/database_version.php';

use ITFlow\Automation\LegacyRuleImporter;
use ITFlow\Automation\RuleEngine;
use ITFlow\Portal\HrRequests;
use ITFlow\Workflow\Connectors\ConnectorRegistry;
use ITFlow\Workflow\Connectors\OktaConnector;
use ITFlow\Workflow\LiveActionGateway;
use ITFlow\Workflow\TemplateTaskService;
use ITFlow\Workflow\WorkflowService;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");
$mysqli = $db;

$PASS = 0; $FAIL = [];
function ok(bool $c, string $n, string $d = ''): void { global $PASS, $FAIL; if ($c) { $PASS++; echo "  ok   $n\n"; } else { $FAIL[] = $n; echo "  FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n"; } }
function section(string $s): void { echo "\n== $s\n"; }
function mk(mysqli $db, string $sql): int { $db->query($sql); return (int) $db->insert_id; }
function q1(mysqli $db, string $sql) { $r = $db->query($sql); $row = $r ? $r->fetch_row() : null; return $row[0] ?? null; }
function q(mysqli $db, string $sql): array { $r = $db->query($sql); return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : []; }
function esc(mysqli $db, string $s): string { return $db->real_escape_string($s); }
function throws(callable $f): ?string { try { $f(); } catch (\Throwable $e) { return $e->getMessage(); } return null; }
function newContact(mysqli $db, string $name, string $email, int $client, array $extra = []): int
{
    $cols = $extra + ['contact_name' => $name, 'contact_email' => $email, 'contact_client_id' => $client, 'contact_employee_type' => 'employee', 'contact_employment_status' => 'active'];
    $set = [];
    foreach ($cols as $k => $v) { $set[] = "$k=" . ($v === null ? 'NULL' : "'" . esc($db, (string) $v) . "'"); }
    return mk($db, 'INSERT INTO contacts SET ' . implode(', ', $set) . ', contact_created_at=NOW()');
}

/** Records what would leave the system; real SQL still runs on the scratch DB. */
class RecGw extends LiveActionGateway
{
    public array $audits = [];
    public array $notes = [];
    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int { return 1; }
    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void {}
    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void { $this->notes[] = $message; }
    public function emitEvent(string $event, array $data): void {}
    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void { $this->audits[] = [$event, $summary, $metadata]; }
}

/** An in-process stand-in for the parts of the Okta Management API the connector uses. Records every request. */
class MockOkta
{
    public array $log = [];
    public array $users = [];   // login => ['id','status','profile']
    public array $groups = [];  // id => name
    public array $members = []; // groupId => [userId,...]
    public array $sessionsCleared = [];
    public int $failStatus = 0;
    private int $n = 0;
    public function __construct(public string $token) {}
    public function addUser(string $login, string $status, string $dept): string
    {
        $id = '00u' . str_pad((string) ++$this->n, 12, 'x');
        $this->users[$login] = ['id' => $id, 'status' => $status, 'profile' => ['login' => $login, 'email' => $login, 'department' => $dept]];
        return $id;
    }
    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $p = parse_url($url);
        $path = $p['path'];
        parse_str($p['query'] ?? '', $qs);
        $this->log[] = [$method, $path . (isset($p['query']) ? '?' . $p['query'] : ''), $body, $headers];
        if ($this->failStatus) { return ['status' => $this->failStatus, 'body' => json_encode(['errorSummary' => 'boom ' . $this->token])]; }
        if (!in_array('Authorization: SSWS ' . $this->token, $headers, true)) { return ['status' => 401, 'body' => '{"errorSummary":"Invalid token provided"}']; }
        $j = fn ($d, $s = 200) => ['status' => $s, 'body' => json_encode($d)];
        if ($method === 'GET' && preg_match('#^/api/v1/users/([^/]+)$#', $path, $m)) {
            $u = $this->users[rawurldecode($m[1])] ?? null;
            return $u ? $j($u) : $j(['errorSummary' => 'Not found'], 404);
        }
        if ($method === 'POST' && $path === '/api/v1/users') {
            $b = json_decode((string) $body, true);
            $login = $b['profile']['login'];
            $id = $this->addUser($login, ($qs['activate'] ?? 'true') === 'false' ? 'STAGED' : 'PROVISIONED', $b['profile']['department'] ?? '');
            foreach ($b['groupIds'] ?? [] as $g) { $this->members[$g][] = $id; }
            return $j($this->users[$login]);
        }
        if ($method === 'POST' && preg_match('#^/api/v1/users/([^/]+)/lifecycle/(\w+)$#', $path, $m)) {
            foreach ($this->users as $l => &$u) {
                if ($u['id'] === $m[1]) {
                    if ($m[2] === 'suspend') { if ($u['status'] !== 'ACTIVE') { return $j(['errorSummary' => 'bad state'], 400); } $u['status'] = 'SUSPENDED'; }
                    if ($m[2] === 'activate') { $u['status'] = 'ACTIVE'; }
                    return $j([]);
                }
            }
            return $j([], 404);
        }
        if ($method === 'DELETE' && preg_match('#^/api/v1/users/([^/]+)/sessions$#', $path, $m)) { $this->sessionsCleared[] = $m[1]; return ['status' => 204, 'body' => '']; }
        if ($method === 'GET' && $path === '/api/v1/groups') {
            $out = [];
            foreach ($this->groups as $id => $name) { if (stripos($name, (string) ($qs['q'] ?? '')) === 0) { $out[] = ['id' => $id, 'profile' => ['name' => $name]]; } }
            return $j($out);
        }
        if ($method === 'PUT' && preg_match('#^/api/v1/groups/([^/]+)/users/([^/]+)$#', $path, $m)) {
            if (!isset($this->groups[$m[1]])) { return $j([], 404); }
            if (!in_array($m[2], $this->members[$m[1]] ?? [], true)) { $this->members[$m[1]][] = $m[2]; }
            return ['status' => 204, 'body' => ''];
        }
        return $j(['errorSummary' => 'unmocked ' . $method . ' ' . $path], 500);
    }
    public function count(string $method, string $pathPrefix = ''): int
    {
        return count(array_filter($this->log, fn ($l) => $l[0] === $method && str_starts_with($l[1], $pathPrefix)));
    }
}

$suffix = substr(md5((string) getmypid() . microtime()), 0, 6);
$cleanup = [];

// ===================================================================================================================================
section('Migration 2.6.242 / 2.6.243');
ok(LATEST_DATABASE_VERSION === '2.6.243', 'LATEST_DATABASE_VERSION is 2.6.243 (range 242-246)');
ok(q1($db, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='hr_workflow_requests'") == 1, 'hr_workflow_requests exists');
foreach (['config_portal_hr_requests', 'config_lifecycle_okta_actions', 'config_lifecycle_okta_domain', 'config_lifecycle_okta_token'] as $col) {
    ok(q1($db, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings' AND COLUMN_NAME='$col'") == 1, "settings.$col exists");
}
ok(q1($db, "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings' AND COLUMN_NAME='config_lifecycle_okta_actions'") === '0' && q1($db, "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='settings' AND COLUMN_NAME='config_portal_hr_requests'") === '0', 'both switches default OFF');
shell_exec('cd ' . escapeshellarg($ROOT . '/scripts') . ' && php update_cli.php --update_db 2>&1'); // bring the scratch DB to the tip first (other tests may leave it lower)
$before = q1($db, "SELECT config_current_database_version FROM settings WHERE company_id=1");
$out = shell_exec('cd ' . escapeshellarg($ROOT . '/scripts') . ' && php update_cli.php --update_db 2>&1');
ok($before === '2.6.243', 'sanity: scratch DB is at 2.6.243', (string) $before);
ok( q1($db, "SELECT config_current_database_version FROM settings WHERE company_id=1") === '2.6.243', 're-running the updater at 2.6.243 changes nothing');

// ===================================================================================================================================
section('Okta connector: gate, domain, create, suspend, groups');
ok(OktaConnector::validDomain('example.okta.com') && OktaConnector::validDomain('Corp-1.oktapreview.com'), 'valid Okta host names accepted');
foreach (['https://example.okta.com', 'example.okta.com/path', 'example.okta.com:8443', 'user@example.okta.com', 'localhost', '127.0.0.1', '', 'a b.okta.com', '-x.okta.com'] as $bad) {
    ok(!OktaConnector::validDomain($bad), 'invalid domain refused: "' . $bad . '"');
}
$TOKEN = 'SECRET-OKTA-TOKEN-' . $suffix;
$mock = new MockOkta($TOKEN);
$mock->groups = ['00gAAAAAAAAAAAA1' => 'Staff', '00gAAAAAAAAAAAA2' => 'VPN Users', '00gAAAAAAAAAAAA3' => 'Dup', '00gAAAAAAAAAAAA4' => 'Dup'];
$allowed = false;
$audits = [];
$okta = new OktaConnector('example.okta.com', $TOKEN, function () use (&$allowed) { return $allowed; }, $mock, function ($e, $a, $s, $m) use (&$audits) { $audits[] = [$e, $a, $s, $m]; });
$spec = ['identifier' => 'New.Person@Example.test', 'display_name' => 'New Person', 'enabled' => false, 'groups' => ['Staff', 'VPN Users'], 'department' => 'Sales'];
ok(!$okta->isConfigured() && str_contains($okta->isConfiguredHint(), 'is off'), 'default OFF: not configured, the hint names the setting');
$errs = [throws(fn () => $okta->provision($spec)), throws(fn () => $okta->deprovision('a@b.test', 'Sales')), throws(fn () => $okta->addToGroups('a@b.test', ['Staff'], 'Sales'))];
ok(!in_array(null, $errs, true) && count($mock->log) === 0, 'OFF: create / disable / add-to-group all refuse and NOTHING is sent to Okta');
$allowed = true;
$noTok = new OktaConnector('example.okta.com', '', fn () => true, $mock);
ok(!$noTok->isConfigured() && str_contains($noTok->isConfiguredHint(), 'not set up'), 'no token saved: not configured');
$badDom = new OktaConnector('http://evil', $TOKEN, fn () => true, $mock);
ok(!$badDom->isConfigured(), 'a malformed domain is not configured');
ok(count($mock->log) === 0, 'still nothing sent');

$msg = $okta->provision($spec);
$post = array_values(array_filter($mock->log, fn ($l) => $l[0] === 'POST' && str_starts_with($l[1], '/api/v1/users?')))[0] ?? null;
$pb = json_decode((string) ($post[2] ?? '{}'), true);
ok(str_contains($post[1] ?? '', 'activate=false') && $pb['profile']['login'] === 'new.person@example.test' && $pb['profile']['firstName'] === 'New' && $pb['profile']['lastName'] === 'Person' && $pb['profile']['department'] === 'Sales', 'create: STAGED user, login lower-cased, name split, department set');
ok(!isset($pb['credentials']) && !str_contains((string) $post[2], 'password'), 'create: no password or credentials are sent');
ok($pb['groupIds'] === ['00gAAAAAAAAAAAA1', '00gAAAAAAAAAAAA2'], 'create: group names resolved to ids');
ok($mock->users['new.person@example.test']['status'] === 'STAGED' && $mock->count('POST', '/api/v1/users/') === 0, 'create: user stays STAGED, no activation call');
ok(str_contains($msg, 'staged') && !str_contains($msg, $TOKEN), 'create message is plain and token-free');
$msg2 = $okta->provision($spec);
ok(str_contains($msg2, 'already exists') && $mock->count('POST', '/api/v1/users?') === 1, 'create is idempotent: a second call creates nothing');
$spec2 = ['identifier' => 'second@example.test', 'display_name' => 'Cher', 'enabled' => true, 'groups' => [], 'department' => 'Sales'];
$okta->provision($spec2);
$act = array_values(array_filter($mock->log, fn ($l) => str_contains($l[1], '/lifecycle/activate')))[0] ?? null;
ok($act && str_contains($act[1], 'sendEmail=false') && $mock->users['second@example.test']['status'] === 'ACTIVE', 'enabled: activated with sendEmail=false (Okta mails nobody)');
ok(json_decode($mock->log[array_key_last(array_filter($mock->log, fn ($l) => $l[0] === 'POST' && str_starts_with($l[1], '/api/v1/users?')))][2], true)['profile']['lastName'] === 'Cher', 'a one-word name still gets a last name (Okta requires one)');
ok(throws(fn () => $okta->provision(['identifier' => 'not-an-email', 'display_name' => 'X'] + $spec)) !== null, 'a non-email account name is refused');
ok(throws(fn () => $okta->provision(['groups' => ['Dup']] + ['identifier' => 'dupgrp@example.test'] + $spec)) !== null && !isset($mock->users['dupgrp@example.test']), 'an ambiguous group name refuses BEFORE the user is created');
ok(throws(fn () => $okta->provision(['groups' => ['Nope']] + ['identifier' => 'nogrp@example.test'] + $spec)) !== null && !isset($mock->users['nogrp@example.test']), 'an unknown group refuses before the user is created');
ok(count(array_filter($audits, fn ($a) => $a[0] === 'okta.user_created')) === 2, 'two creates audited (okta.user_created)');

// suspend
$aId = $mock->addUser('leaver@example.test', 'ACTIVE', 'Sales');
$r = $okta->deprovision('Leaver@example.test', 'sales');
ok($mock->users['leaver@example.test']['status'] === 'SUSPENDED' && in_array($aId, $mock->sessionsCleared, true), 'disable: ACTIVE user suspended and sessions cleared (department compared case-insensitively)');
ok($mock->count('DELETE', '/api/v1/users/' . $aId) === 1 && $mock->count('DELETE') === 1, 'the only DELETE is the sessions endpoint: the user is never deleted');
$n = $mock->count('POST', '/api/v1/users/' . $aId . '/lifecycle/suspend');
$r2 = $okta->deprovision('leaver@example.test', 'Sales');
ok(str_contains($r2, 'already suspended') && $mock->count('POST', '/api/v1/users/' . $aId . '/lifecycle/suspend') === $n && count($mock->sessionsCleared) === 2, 'disable is idempotent: no second suspend, sessions cleared again');
$mock->addUser('other.dept@example.test', 'ACTIVE', 'Finance');
$logN = count($mock->log);
ok(str_contains((string) throws(fn () => $okta->deprovision('other.dept@example.test', 'Sales')), 'not in this person\'s department') && $mock->users['other.dept@example.test']['status'] === 'ACTIVE' && count($mock->log) === $logN + 1, 'department mismatch: refused after one read, nothing changed');
$mock->addUser('nodept@example.test', 'ACTIVE', '');
ok(throws(fn () => $okta->deprovision('nodept@example.test', 'Sales')) !== null && $mock->users['nodept@example.test']['status'] === 'ACTIVE', 'an Okta account with no department attribute fails closed');
ok(throws(fn () => $okta->deprovision('x@example.test', '')) !== null, 'a person with no department fails closed');
ok(str_contains((string) throws(fn () => $okta->deprovision('ghost@example.test', 'Sales')), 'no Okta user'), 'unknown account is a failure, not a silent success');
$mock->addUser('staged@example.test', 'STAGED', 'Sales');
ok(str_contains((string) throws(fn () => $okta->deprovision('staged@example.test', 'Sales')), 'status STAGED') && $mock->users['staged@example.test']['status'] === 'STAGED', 'a status that cannot be suspended is refused and named');

// groups
$gId = $mock->addUser('grp@example.test', 'ACTIVE', 'Sales');
$gr = $okta->addToGroups('grp@example.test', ['Staff', '00gAAAAAAAAAAAA2'], 'Sales');
ok(in_array($gId, $mock->members['00gAAAAAAAAAAAA1'], true) && in_array($gId, $mock->members['00gAAAAAAAAAAAA2'], true) && str_contains($gr, '2 group'), 'add to groups: by name and by id');
$okta->addToGroups('grp@example.test', ['Staff'], 'Sales');
ok(count(array_keys($mock->members['00gAAAAAAAAAAAA1'], $gId, true)) === 1, 'adding twice leaves one membership');
$put = $mock->count('PUT');
ok(throws(fn () => $okta->addToGroups('grp@example.test', ['Staff', 'Dup'], 'Sales')) !== null && $mock->count('PUT') === $put, 'one ambiguous name among several: nothing is added at all');
ok(throws(fn () => $okta->addToGroups('other.dept@example.test', ['Staff'], 'Sales')) !== null && empty(array_intersect([$mock->users['other.dept@example.test']['id']], $mock->members['00gAAAAAAAAAAAA1'])), 'groups: department mismatch refused');
ok(throws(fn () => $okta->addToGroups('grp@example.test', [], 'Sales')) !== null, 'groups: an empty list refuses');
ok(count($mock->members['00gAAAAAAAAAAAA1']) >= 1 && $mock->count('DELETE', '/api/v1/groups') === 0, 'groups: nobody is ever removed from a group');

// errors never leak the token
$mock->failStatus = 500;
$e = (string) throws(fn () => $okta->deprovision('grp@example.test', 'Sales'));
ok(str_contains($e, 'HTTP 500') && !str_contains($e, $TOKEN), 'an Okta error message is summarised and the token is scrubbed from it');
$mock->failStatus = 429; ok(str_contains((string) throws(fn () => $okta->deprovision('grp@example.test', 'Sales')), 'rate limiting'), '429 is reported as rate limiting');
$mock->failStatus = 0;
$wrong = new OktaConnector('example.okta.com', 'wrong-token', fn () => true, $mock);
$e401 = (string) throws(fn () => $wrong->deprovision('grp@example.test', 'Sales'));
ok(str_contains($e401, '401') && !str_contains($e401, 'wrong-token'), 'a rejected token is reported without echoing it');
$sent = json_encode($mock->log);
ok(substr_count($sent, 'SSWS ' . $TOKEN) > 0, 'sanity: the token was sent in the Authorization header');
$leaks = array_filter($mock->log, fn ($l) => str_contains($l[1], $TOKEN) || str_contains((string) $l[2], $TOKEN));
ok(!$leaks, 'the token is never in a URL or a body');
ok(!str_contains(json_encode($audits), $TOKEN), 'the token is never in an audit record');

// ===================================================================================================================================
section('Okta through the workflow: gateway wiring, setting off by default, action types');
$tplSvc = new TemplateTaskService($db);
$t1 = mk($db, "INSERT INTO workflow_templates SET name='Okta on $suffix', type='onboarding', is_active=1");
$tplSvc->add($t1, ['title' => 'Okta account', 'task_type' => 'action', 'action_type' => 'connector_provision', 'action_config' => ['system' => 'okta', 'enabled' => 0, 'groups' => "Staff\nVPN Users"]]);
$tplSvc->add($t1, ['title' => 'Okta groups', 'task_type' => 'action', 'action_type' => 'connector_add_groups', 'action_config' => ['system' => 'okta', 'groups' => '00gAAAAAAAAAAAA2']]);
$t2 = mk($db, "INSERT INTO workflow_templates SET name='Okta off $suffix', type='offboarding', is_active=1");
$tplSvc->add($t2, ['title' => 'Okta disable', 'task_type' => 'action', 'action_type' => 'connector_deprovision', 'action_config' => ['system' => 'okta']]);
ok(str_contains((string) throws(fn () => $tplSvc->add($t1, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'connector_add_groups', 'action_config' => ['system' => 'okta', 'groups' => '']])), 'at least one group'), 'add-to-groups needs at least one group');
ok(str_contains((string) throws(fn () => $tplSvc->add($t1, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'connector_add_groups', 'action_config' => ['system' => 'nope', 'groups' => 'a']])), 'Choose the identity system'), 'add-to-groups needs a known system');
ok(isset(\ITFlow\Workflow\TaskActionRunner::labelsFor('onboarding')['connector_add_groups']) && isset(\ITFlow\Workflow\TaskActionRunner::labelsFor('role_change')['connector_add_groups']) && !isset(\ITFlow\Workflow\TaskActionRunner::labelsFor('offboarding')['connector_add_groups']), 'add-to-groups is offered on onboarding / role change / transfer, not offboarding');

$dept = mk($db, "INSERT INTO clients SET client_name='Okta Dept $suffix', client_currency_code='USD', client_net_terms=30, client_created_at=NOW()");
$cleanup[] = "DELETE FROM clients WHERE client_id=$dept";
$person = newContact($db, 'Olive Okta', "olive.$suffix@example.test", $dept, ['contact_department' => 'Sales']);
// the person's department placeholder comes from the department (client) name; align the mock accounts with it
$deptName = "Okta Dept $suffix";
$mock2 = new MockOkta($TOKEN);
$mock2->groups = ['00gAAAAAAAAAAAA1' => 'Staff', '00gAAAAAAAAAAAA2' => 'VPN Users'];
$db->query("UPDATE settings SET config_lifecycle_okta_actions=0, config_lifecycle_okta_domain=NULL, config_lifecycle_okta_token=NULL WHERE company_id=1");
$gw0 = new RecGw($db, ['okta_http' => $mock2]);
$run0 = (new WorkflowService($db, $gw0))->startRun($t1, $person, null);
ok(q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$run0 AND title='Okta account'") === 'action_failed' && str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$run0 AND title='Okta account'"), 'not connected'), 'no domain / token saved: the stub refuses, the task becomes a manual task');
$db->query("UPDATE settings SET config_lifecycle_okta_domain='example.okta.com', config_lifecycle_okta_token='" . esc($db, encryptSetting($TOKEN)) . "', config_lifecycle_okta_actions=0 WHERE company_id=1");
$gw1 = new RecGw($db, ['okta_http' => $mock2]);
$run1 = (new WorkflowService($db, $gw1))->startRun($t1, $person, null);
ok(q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$run1 AND title='Okta account'") === 'action_failed' && str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$run1 AND title='Okta account'"), 'is off') && count($mock2->log) === 0, 'domain + token saved but "Allow workflows to change Okta" is OFF: refused, nothing sent');
$pre = (new WorkflowService($db, $gw1))->previewRun($t1, $person);
ok(!str_contains(json_encode($pre), $TOKEN), 'the dry-run preview never contains the token');
$db->query("UPDATE settings SET config_lifecycle_okta_actions=1 WHERE company_id=1");
$mock2->addUser("olive.$suffix@example.test", 'ACTIVE', $deptName);
$gw2 = new RecGw($db, ['okta_http' => $mock2]);
$run2 = (new WorkflowService($db, $gw2))->startRun($t1, $person, null);
$s1 = q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$run2 AND title='Okta account'");
$s2 = q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$run2 AND title='Okta groups'");
$ctx = (string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$run2 AND title='Okta groups'");
ok($s1 === 'completed', 'switch ON: the create task runs through the gateway (existing account: nothing created)', (string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$run2 AND title='Okta account'"));
ok($s2 === 'completed' && in_array($mock2->users["olive.$suffix@example.test"]['id'], $mock2->members['00gAAAAAAAAAAAA2'] ?? [], true), 'switch ON: add-to-groups ran against the mock with the placeholder resolved', $ctx);
$run3 = (new WorkflowService($db, $gw2))->startRun($t2, $person, null);
ok(q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$run3 AND title='Okta disable'") === 'completed' && $mock2->users["olive.$suffix@example.test"]['status'] === 'SUSPENDED', 'switch ON: offboarding suspends the account');
ok(count(array_filter($gw2->audits, fn ($a) => str_starts_with($a[0], 'okta.'))) >= 2 && !str_contains(json_encode($gw2->audits), $TOKEN), 'okta.* audit events were written, without the token');
$logText = json_encode(q($db, "SELECT * FROM workflow_task_log WHERE run_id IN ($run1,$run2,$run3)"));
ok(!str_contains($logText, $TOKEN), 'the run activity log never contains the token');
$reg = new ConnectorRegistry();
ok(!$reg->get('ad')->isConfigured() && !$reg->get('vpn')->isConfigured() && !$reg->get('google')->isConfigured() && !$reg->get('okta')->isConfigured(), 'AD / VPN / Google (and Okta without settings) are still not-connected stubs');
$db->query("UPDATE settings SET config_lifecycle_okta_actions=0, config_lifecycle_okta_domain=NULL, config_lifecycle_okta_token=NULL WHERE company_id=1");

// ===================================================================================================================================
section('HR-initiated portal requests');
$d1 = mk($db, "INSERT INTO clients SET client_name='HR Dept1 $suffix', client_currency_code='USD', client_net_terms=30, client_created_at=NOW()");
$d2 = mk($db, "INSERT INTO clients SET client_name='HR Dept2 $suffix', client_currency_code='USD', client_net_terms=30, client_created_at=NOW()");
$mgr = newContact($db, 'Mona Manager', "mona.$suffix@example.test", $d1);
$r1 = newContact($db, 'Rita Report', "rita.$suffix@example.test", $d1, ['contact_manager_id' => $mgr, 'contact_title' => 'Analyst']);
$r2 = newContact($db, 'Ron Report', "ron.$suffix@example.test", $d1, ['contact_manager_id' => $mgr, 'contact_title' => 'Clerk']);
$peer = newContact($db, 'Pete Peer', "pete.$suffix@example.test", $d1, ['contact_title' => 'Clerk']);
$adm = newContact($db, 'Ada Admin', "ada.$suffix@example.test", $d1, ['contact_primary' => 1]);
$emp = newContact($db, 'Eve Employee', "eve.$suffix@example.test", $d1);
$foreign = newContact($db, 'Fay Foreign', "fay.$suffix@example.test", $d2, ['contact_title' => 'Clerk']);
$gone = newContact($db, 'Gus Gone', "gus.$suffix@example.test", $d1, ['contact_manager_id' => $mgr, 'contact_employment_status' => 'terminated']);
$cleanup[] = "DELETE FROM clients WHERE client_id IN ($d1,$d2)";

// agents and roles
$mod = (int) q1($db, "SELECT module_id FROM modules WHERE module_name='module_client'");
if (!$mod) { $mod = mk($db, "INSERT INTO modules SET module_name='module_client'"); $cleanup[] = "DELETE FROM modules WHERE module_id=$mod"; }
$roleAdmin = mk($db, "INSERT INTO user_roles SET role_name='HRT admin $suffix', role_is_admin=1, role_type=1");
$roleHr = mk($db, "INSERT INTO user_roles SET role_name='HRT edit $suffix', role_is_admin=0, role_type=1");
$roleView = mk($db, "INSERT INTO user_roles SET role_name='HRT view $suffix', role_is_admin=0, role_type=1");
$db->query("INSERT INTO user_role_permissions SET user_role_id=$roleHr, module_id=$mod, user_role_permission_level=2");
$db->query("INSERT INTO user_role_permissions SET user_role_id=$roleView, module_id=$mod, user_role_permission_level=1");
$cleanup[] = "DELETE FROM user_role_permissions WHERE user_role_id IN ($roleHr,$roleView)";
$cleanup[] = "DELETE FROM user_roles WHERE role_id IN ($roleAdmin,$roleHr,$roleView)";
function newUser(mysqli $db, string $name, int $role, string $suffix): int { return mk($db, "INSERT INTO users SET user_name='$name $suffix', user_email='" . strtolower(str_replace(' ', '.', $name)) . ".$suffix@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role"); }
$uAdmin = newUser($db, 'HRT Admin', $roleAdmin, $suffix);
$uHr = newUser($db, 'HRT Hr', $roleHr, $suffix);
$uHr2 = newUser($db, 'HRT Hr2', $roleHr, $suffix); // may only access department 2
$uView = newUser($db, 'HRT View', $roleView, $suffix);
$db->query("INSERT INTO user_client_permissions SET user_id=$uHr2, client_id=$d2");
$cleanup[] = "DELETE FROM user_client_permissions WHERE user_id IN ($uHr2)";
$cleanup[] = "DELETE FROM users WHERE user_id IN ($uAdmin,$uHr,$uHr2,$uView)";

// templates
$tOn = mk($db, "INSERT INTO workflow_templates SET name='HRT onboarding $suffix', type='onboarding', is_active=1");
$tOff = mk($db, "INSERT INTO workflow_templates SET name='HRT offboarding $suffix', type='offboarding', is_active=1");
$tRole = mk($db, "INSERT INTO workflow_templates SET name='HRT role $suffix', type='role_change', is_active=1");
$tOnOld = mk($db, "INSERT INTO workflow_templates SET name='HRT onboarding archived $suffix', type='onboarding', is_active=1, archived_at=NOW()");
foreach ([$tOn, $tOff, $tRole] as $t) { $tplSvc->add($t, ['title' => 'Checklist item', 'task_type' => 'manual']); }
$cleanup[] = "DELETE FROM workflow_template_tasks WHERE workflow_template_id IN ($tOn,$tOff,$tRole,$tOnOld)";
$cleanup[] = "DELETE FROM workflow_templates WHERE workflow_template_id IN ($tOn,$tOff,$tRole,$tOnOld)";

$audit = []; $notified = [];
$hr = new HrRequests($db, function ($e, $a, $t, $i, $ac, $s, $m = []) use (&$audit) { $audit[] = [$e, $a, $t, $i, $ac, $s, $m]; }, function ($u, $msg, $url, $c, $rid) use (&$notified) { $notified[] = [$u, $msg, $url, $c, $rid]; }, new RecGw($db));
$today = date('Y-m-d');
$in30 = date('Y-m-d', strtotime('+30 days'));

// feature gate
ok(!$hr->canRequest(false, $mgr, $d1, false) && !$hr->canRequest(false, $adm, $d1, true), 'setting OFF: nobody may request');
ok($hr->canRequest(true, $mgr, $d1, false) && $hr->canRequest(true, $adm, $d1, true) && !$hr->canRequest(true, $emp, $d1, false) && !$hr->canRequest(true, 0, $d1, true), 'setting ON: managers and department administrators only; an ordinary employee and a missing contact never');
ok(HrRequests::available($db), 'table present');

// subjects
$ids = fn ($rows) => array_column($rows, 'contact_id');
$subM = $hr->subjects($mgr, $d1, false);
ok($ids($subM) == [$r1, $r2] || (count($subM) === 2 && in_array($r1, $ids($subM)) && in_array($r2, $ids($subM))), 'a manager sees only their direct reports');
ok(!in_array($gone, $ids($subM)) && !in_array($mgr, $ids($subM)) && !in_array($foreign, $ids($subM)), 'never a leaver, themselves or another department');
$subA = $hr->subjects($adm, $d1, true);
ok(in_array($peer, $ids($subA)) && in_array($r1, $ids($subA)) && !in_array($adm, $ids($subA)) && !in_array($foreign, $ids($subA)) && !in_array($gone, $ids($subA)), 'a department administrator sees the active people of their department, not themselves, not other departments');

// validation (pure)
$vOn = HrRequests::validate('onboarding', ['name' => 'New Hire', 'email' => "nh.$suffix@example.test", 'start_date' => $in30, 'manager_id' => $mgr, 'title' => 'Dev', 'notes' => '<b>hi</b>'], [$mgr], [], $mgr);
ok(!$vOn['errors'] && $vOn['clean']['type'] === 'onboarding' && $vOn['clean']['notes'] === 'hi', 'onboarding validates (HTML stripped from notes)');
ok(count(HrRequests::validate('onboarding', ['name' => '', 'email' => 'bad', 'start_date' => '2020-01-01', 'manager_id' => 999999], [$mgr], [], $mgr)['errors']) >= 3, 'onboarding: name, email, date, manager all checked');
ok(HrRequests::validate('wipe', [], [], [], 0)['errors'] !== [], 'an unknown type is refused');
$vOff = HrRequests::validate('offboarding', ['subject_contact_id' => $r1, 'effective_date' => $in30], [], [$r1, $r2], $mgr);
ok(!$vOff['errors'] && $vOff['clean']['subject_contact_id'] === $r1, 'offboarding validates');
ok(HrRequests::validate('offboarding', ['subject_contact_id' => $peer, 'effective_date' => $in30], [], [$r1, $r2], $mgr)['errors'] !== [], 'offboarding a person who is not one of your reports is refused');
ok(HrRequests::validate('offboarding', ['subject_contact_id' => $r1, 'effective_date' => '2026-02-30'], [], [$r1], $mgr)['errors'] !== [], 'an impossible date is refused');
ok(HrRequests::validate('offboarding', ['subject_contact_id' => $r1, 'effective_date' => date('Y-m-d', strtotime('+3 years'))], [], [$r1], $mgr)['errors'] !== [], 'a date three years out is refused');
$vRole = HrRequests::validate('role_change', ['subject_contact_id' => $r1, 'effective_date' => $in30, 'new_title' => 'Senior Analyst'], [], [$r1], $mgr, [$r1 => 'Analyst']);
ok(!$vRole['errors'] && $vRole['clean']['new_title'] === 'Senior Analyst', 'role change validates');
ok(HrRequests::validate('role_change', ['subject_contact_id' => $r1, 'effective_date' => $in30, 'new_title' => 'analyst'], [], [$r1], $mgr, [$r1 => 'Analyst'])['errors'] !== [], 'a role change to the same title is refused');
ok(HrRequests::validate('role_change', ['subject_contact_id' => $r1, 'effective_date' => $in30, 'new_title' => ''], [], [$r1], $mgr, [$r1 => 'Analyst'])['errors'] !== [], 'a role change needs the new title');

// submit
$beforeContacts = (int) q1($db, 'SELECT COUNT(*) FROM contacts');
$beforeRuns = (int) q1($db, 'SELECT COUNT(*) FROM workflow_runs');
$s1 = $hr->submit($vOn['clean'], $d1, $mgr);
ok($s1['status'] === 'created' && $s1['request_id'] > 0, 'onboarding request stored');
ok((int) q1($db, 'SELECT COUNT(*) FROM contacts') === $beforeContacts && (int) q1($db, 'SELECT COUNT(*) FROM workflow_runs') === $beforeRuns, 'PENDING means nothing happened: no person created, no run started');
$row = $hr->get($s1['request_id']);
ok($row['hr_status'] === 'pending' && (int) $row['hr_client_id'] === $d1 && (int) $row['hr_requested_by_contact_id'] === $mgr && $row['hr_subject_name'] === 'New Hire' && $row['hr_effective_date'] === $in30, 'stored in the requester\'s own department with the requester recorded');
ok(count(array_filter($audit, fn ($a) => $a[0] === 'hr_request.submitted' && $a[3] === $s1['request_id'])) === 1, 'submission audited');
$notifiedIds = array_unique(array_column($notified, 0));
ok(in_array($uAdmin, $notifiedIds) && in_array($uHr, $notifiedIds) && !in_array($uView, $notifiedIds) && !in_array($uHr2, $notifiedIds), 'HR agents are notified: admin and Departments-edit, within department access; not a view-only role, not an agent restricted to another department');
ok(str_contains($notified[0][2], 'hr_requests.php?request_id=' . $s1['request_id']), 'the notification links to the request');
$dup = $hr->submit($vOn['clean'], $d1, $mgr);
ok($dup['status'] === 'duplicate' && $dup['request_id'] === $s1['request_id'] && (int) q1($db, "SELECT COUNT(*) FROM hr_workflow_requests WHERE hr_client_id=$d1 AND hr_type='onboarding' AND hr_status='pending'") === 1, 'the same pending onboarding is not stored twice');
$s2 = $hr->submit($vOff['clean'], $d1, $mgr);
$s3 = $hr->submit($vRole['clean'], $d1, $mgr);
ok($s2['status'] === 'created' && $s3['status'] === 'created', 'offboarding and role change stored');
$forged = $hr->submit(['type' => 'offboarding', 'subject_contact_id' => $foreign, 'effective_date' => $in30, 'notes' => ''], $d1, $mgr);
ok($forged['status'] === 'error' && (int) q1($db, "SELECT COUNT(*) FROM hr_workflow_requests WHERE hr_subject_contact_id=$foreign") === 0, 'submit() itself refuses a person from another department (defence in depth behind validate)');
ok($hr->submit(['type' => 'bogus'], $d1, $mgr)['status'] === 'error', 'submit() refuses an unknown type');
ok(count($hr->mine($mgr, $d1)) === 3 && count($hr->mine($adm, $d1)) === 0 && count($hr->mine($mgr, $d2)) === 0, 'My requests are scoped to the requester and their department');

// cancel
$sc = $hr->submit(['type' => 'offboarding', 'subject_contact_id' => $r2, 'effective_date' => $in30, 'notes' => ''], $d1, $mgr);
ok(!$hr->cancel($sc['request_id'], $adm, $d1) && !$hr->cancel($sc['request_id'], $mgr, $d2), 'another contact (or another department) cannot withdraw someone else\'s request');
ok($hr->cancel($sc['request_id'], $mgr, $d1) && $hr->get($sc['request_id'])['hr_status'] === 'cancelled', 'the requester can withdraw a pending request');
ok(!$hr->cancel($sc['request_id'], $mgr, $d1), 'a withdrawn request cannot be withdrawn twice');
ok(count(array_filter($audit, fn ($a) => $a[0] === 'hr_request.cancelled')) === 1, 'withdrawal audited');

// who may decide / queue scoping
ok($hr->canDecide($uAdmin, $d1) && $hr->canDecide($uHr, $d1) && !$hr->canDecide($uView, $d1) && !$hr->canDecide($uHr2, $d1) && $hr->canDecide($uHr2, $d2), 'canDecide: admin, Departments edit; not view-only; not outside department access');
ok($hr->allowedClients($uAdmin) === null && $hr->allowedClients($uHr) === null && $hr->allowedClients($uHr2) === [$d2], 'allowedClients: admin / unrestricted = all, restricted = listed');
$qAll = array_column($hr->queue(null, 'pending'), 'hr_request_id');
$qHr2 = array_column($hr->queue($hr->allowedClients($uHr2), 'pending'), 'hr_request_id');
ok(in_array($s1['request_id'], $qAll) && !in_array($s1['request_id'], $qHr2) && !in_array($sc['request_id'], $qAll), 'queue: pending only, and an agent restricted to another department does not see it');

// decisions: unauthorised
foreach ([[$uView, 'view-only role'], [$uHr2, 'agent restricted to another department']] as [$u, $why]) {
    ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $u, true, $tOn)), 'cannot decide') && $hr->get($s1['request_id'])['hr_status'] === 'pending', "decide refused for $why; request stays pending");
}
ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $uHr, false, 0, '')), 'Say why'), 'reject needs a reason');
ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $uHr, true, $tOff)), 'Choose the workflow'), 'a template of the wrong type is refused');
ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $uHr, true, $tOnOld)), 'Choose the workflow'), 'an archived template is refused');
ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $uHr, true, 0)), 'Choose the workflow'), 'no template chosen is refused');
ok($hr->get($s1['request_id'])['hr_status'] === 'pending' && (int) q1($db, 'SELECT COUNT(*) FROM contacts') === $beforeContacts, 'all refusals left the request pending and created nothing');
ok(str_contains((string) throws(fn () => $hr->decide(999999, $uHr, true, $tOn)), 'does not exist'), 'unknown request id');

// reject
$rej = $hr->decide($s3['request_id'], $uHr, false, 0, 'Not this quarter');
ok($rej['status'] === 'rejected' && $hr->get($s3['request_id'])['hr_status'] === 'rejected' && $hr->get($s3['request_id'])['hr_decision_comment'] === 'Not this quarter' && (int) $hr->get($s3['request_id'])['hr_decided_by_user_id'] === $uHr, 'reject: status, reason and decider recorded');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE contact_id=$r1") === 0 && q1($db, "SELECT contact_title FROM contacts WHERE contact_id=$r1") === 'Analyst', 'reject starts nothing and the person is unchanged');
ok(str_contains((string) throws(fn () => $hr->decide($s3['request_id'], $uHr, true, $tRole)), 'already rejected'), 'a decided request cannot be decided again');
ok(count(array_filter($audit, fn ($a) => $a[0] === 'hr_request.rejected')) === 1, 'rejection audited');

// approve onboarding
$ap = $hr->decide($s1['request_id'], $uHr, true, $tOn, 'ok');
$newId = $ap['contact_id'];
ok($ap['status'] === 'approved' && $newId > 0 && $ap['run_id'] > 0, 'approve onboarding: a run starts');
$newRow = $db->query("SELECT * FROM contacts WHERE contact_id=$newId")->fetch_assoc();
ok($newRow['contact_client_id'] == $d1 && $newRow['contact_employment_status'] === 'pre-hire' && (int) $newRow['contact_manager_id'] === $mgr && $newRow['contact_start_date'] === $in30, 'the new hire is a pre-hire in the requester\'s department with the named manager and start date');
ok((int) q1($db, "SELECT workflow_template_id FROM workflow_runs WHERE run_id={$ap['run_id']}") === $tOn && (int) q1($db, "SELECT contact_id FROM workflow_runs WHERE run_id={$ap['run_id']}") === $newId, 'the run uses the template HR chose');
$rowA = $hr->get($s1['request_id']);
ok($rowA['hr_status'] === 'approved' && (int) $rowA['hr_run_id'] === $ap['run_id'] && (int) $rowA['hr_template_id'] === $tOn && (int) $rowA['hr_decided_by_user_id'] === $uHr, 'request row records run, template and decider');
ok(count(array_filter($audit, fn ($a) => $a[0] === 'hr_request.approved' && $a[6]['run_id'] === $ap['run_id'])) === 1, 'approval audited with the run id');
ok(str_contains((string) throws(fn () => $hr->decide($s1['request_id'], $uAdmin, true, $tOn)), 'already approved'), 'a second approval is refused (no second run)');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE contact_id=$newId") === 1, 'exactly one run exists for the new hire');

// approve offboarding
$ap2 = $hr->decide($s2['request_id'], $uAdmin, true, $tOff);
ok($ap2['status'] === 'approved' && $ap2['contact_id'] === $r1 && $ap2['run_id'] > 0, 'approve offboarding: a run starts for the named person');
ok(q1($db, "SELECT contact_expected_end_date FROM contacts WHERE contact_id=$r1") === $in30, 'the last working day is set as the expected end date (it anchors the template\'s due dates)');
ok(str_contains((string) q1($db, "SELECT notes FROM workflow_runs WHERE run_id={$ap2['run_id']}"), 'HR request #' . $s2['request_id']), 'the run notes say where it came from');

// approve role change (title untouched)
$s4 = $hr->submit(['type' => 'role_change', 'subject_contact_id' => $r2, 'effective_date' => $in30, 'new_title' => 'Lead Clerk', 'notes' => 'promotion'], $d1, $mgr);
$ap4 = $hr->decide($s4['request_id'], $uHr, true, $tRole);
ok($ap4['status'] === 'approved' && $ap4['contact_id'] === $r2 && q1($db, "SELECT contact_title FROM contacts WHERE contact_id=$r2") === 'Clerk', 'approve role change: run starts, the job title is NOT changed behind HR\'s back');
ok(str_contains((string) q1($db, "SELECT notes FROM workflow_runs WHERE run_id={$ap4['run_id']}"), 'Lead Clerk') && str_contains((string) q1($db, "SELECT notes FROM workflow_runs WHERE run_id={$ap4['run_id']}"), 'promotion'), 'the run notes carry the new title and the requester\'s notes');

// failure releases the claim
$mgr2 = newContact($db, 'Max Mgr', "max.$suffix@example.test", $d1);
$s5 = $hr->submit(['type' => 'onboarding', 'name' => 'Late Hire', 'email' => "late.$suffix@example.test", 'start_date' => $in30, 'manager_id' => $mgr2, 'title' => '', 'notes' => ''], $d1, $adm);
$db->query("UPDATE contacts SET contact_archived_at=NOW() WHERE contact_id=$mgr2");
$err = (string) throws(fn () => $hr->decide($s5['request_id'], $uHr, true, $tOn));
ok(str_contains($err, 'no longer active') && $hr->get($s5['request_id'])['hr_status'] === 'pending' && $hr->get($s5['request_id'])['hr_decided_by_user_id'] === null, 'manager left since the request: approval fails with a reason and the request is back to pending (claim released)');
ok((int) q1($db, "SELECT COUNT(*) FROM contacts WHERE contact_email='late.$suffix@example.test'") === 0, 'and no person was created');
$s6 = $hr->submit(['type' => 'offboarding', 'subject_contact_id' => $emp, 'effective_date' => $in30, 'notes' => ''], $d1, $adm);
$db->query("UPDATE contacts SET contact_employment_status='terminated' WHERE contact_id=$emp");
ok(str_contains((string) throws(fn () => $hr->decide($s6['request_id'], $uHr, true, $tOff)), 'no longer active') && $hr->get($s6['request_id'])['hr_status'] === 'pending', 'offboarding someone who already left is refused, request stays pending');
// two agents race: the second claim loses
$s7 = $hr->submit(['type' => 'offboarding', 'subject_contact_id' => $peer, 'effective_date' => $in30, 'notes' => ''], $d1, $adm);
$a1 = $hr->decide($s7['request_id'], $uHr, true, $tOff);
$a2 = throws(fn () => $hr->decide($s7['request_id'], $uAdmin, true, $tOff));
ok($a1['status'] === 'approved' && $a2 !== null && (int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE contact_id=$peer AND workflow_template_id=$tOff") === 1, 'two agents deciding the same request: one run, the loser is told');

// the department comes from the stored request, not from anything posted
ok((int) $hr->get($s1['request_id'])['hr_client_id'] === $d1, 'sanity: department is stored server-side');

// ===================================================================================================================================
section('Legacy ticket rules in the unified list');
$engine = new RuleEngine($db, new RecGw($db));
$db->query("DELETE FROM ticket_automation_rules");
$db->query("DELETE FROM automation_rules WHERE source_legacy_id IS NOT NULL");
$lg1 = mk($db, "INSERT INTO ticket_automation_rules SET rule_name='Offline notify $suffix', rule_enabled=1, rule_trigger='asset_offline', rule_cond_field='client_id', rule_cond_op='equals', rule_cond_value='$d1', rule_action='notify_assignee', rule_action_value=''");
$lg2 = mk($db, "INSERT INTO ticket_automation_rules SET rule_name='Old tickets $suffix', rule_enabled=1, rule_trigger='schedule', rule_cond_field='age_hours', rule_cond_op='greater_than', rule_cond_value='48', rule_action='set_priority', rule_action_value='High'");
$lg3 = mk($db, "INSERT INTO ticket_automation_rules SET rule_name='Off rule $suffix', rule_enabled=0, rule_trigger='asset_online', rule_cond_field='client_id', rule_cond_op='equals', rule_cond_value='$d1', rule_action='notify_assignee', rule_action_value=''");
$u = [];
foreach (LegacyRuleImporter::unifiedRows($db) as $x) { $u[$x['legacy_id']] = $x; }
ok(count($u) === 3 && $u[$lg1]['state'] === 'convertible' && $u[$lg2]['state'] === 'legacy_only' && $u[$lg3]['state'] === 'convertible', 'unified list: all legacy rules listed with a state (convertible / stays legacy)');
ok($u[$lg2]['reason'] !== '' && str_contains($u[$lg2]['reason'], 'keeps running'), 'a rule with no equivalent says why and that it keeps running');
$res = LegacyRuleImporter::import($db, $engine, [$lg1]);
ok($res[0]['ok'] && $res[0]['rule_id'] > 0, 'one-click convert creates the event rule');
$newRule = $db->query("SELECT * FROM automation_rules WHERE rule_id={$res[0]['rule_id']}")->fetch_assoc();
ok((int) $newRule['is_enabled'] === 0 && $newRule['trigger_event'] === 'asset.offline' && (int) $newRule['source_legacy_id'] === $lg1, 'the copy is switched OFF, listens to the equivalent event and remembers its source');
ok((int) q1($db, "SELECT rule_enabled FROM ticket_automation_rules WHERE rule_id=$lg1") === 1, 'the legacy rule is untouched and still running after the convert');
$st = LegacyRuleImporter::unifiedRows($db);
$state1 = array_values(array_filter($st, fn ($x) => $x['legacy_id'] === $lg1))[0]['state'];
ok($state1 === 'converted_waiting', 'state after convert: copy ready, switched off');
ok(LegacyRuleImporter::import($db, $engine, [$lg1])[0]['ok'] === false && (int) q1($db, "SELECT COUNT(*) FROM automation_rules WHERE source_legacy_id=$lg1") === 1, 'converting twice makes no second copy');
ok(LegacyRuleImporter::import($db, $engine, [$lg2])[0]['ok'] === false, 'a rule with no equivalent cannot be converted');
ok(LegacyRuleImporter::switchOver($db, $lg2)['ok'] === false, 'switch over needs a converted rule');
// behaviour: the copy matches the same asset events the legacy conditions match
$ctxIn = ['asset_id' => 5, 'client_id' => $d1, 'hostname' => 'pc1', 'integration_id' => 1, 'rmm_status' => 'offline'];
$ctxOut = ['asset_id' => 5, 'client_id' => $d2, 'hostname' => 'pc1', 'integration_id' => 1, 'rmm_status' => 'offline'];
ok($engine->matching('asset.offline', $ctxIn) === [] , 'while the copy is OFF it matches nothing');
$sw = LegacyRuleImporter::switchOver($db, $lg1);
ok($sw['ok'] && (int) q1($db, "SELECT is_enabled FROM automation_rules WHERE rule_id={$sw['rule_id']}") === 1 && (int) q1($db, "SELECT rule_enabled FROM ticket_automation_rules WHERE rule_id=$lg1") === 0, 'switch over: the copy is ON and the legacy rule is OFF, together');
ok(count($engine->matching('asset.offline', $ctxIn)) === 1 && $engine->matching('asset.offline', $ctxOut) === [], 'behaviour preserved: the copy fires for the department the legacy condition named, and only that one');
$state1 = array_values(array_filter(LegacyRuleImporter::unifiedRows($db), fn ($x) => $x['legacy_id'] === $lg1))[0]['state'];
ok($state1 === 'switched', 'state after the hand-over: switched over');
ok((int) q1($db, "SELECT rule_enabled FROM ticket_automation_rules WHERE rule_id=$lg2") === 1, 'unrelated legacy rules are never touched');
// legacy rule disabled before conversion
$rr = LegacyRuleImporter::import($db, $engine, [$lg3]);
$sw3 = LegacyRuleImporter::switchOver($db, $lg3);
ok($rr[0]['ok'] && $sw3['ok'], 'a legacy rule that was already off can be converted and switched over');
$db->query("DELETE FROM automation_rules WHERE source_legacy_id IS NOT NULL");
$db->query("DELETE FROM ticket_automation_rules");

// ===================================================================================================================================
foreach (array_reverse($cleanup) as $sql) { $db->query($sql); }
$db->query("DELETE FROM hr_workflow_requests WHERE hr_client_id IN ($d1,$d2)");
$db->query("DELETE FROM workflow_task_log WHERE run_id IN (SELECT run_id FROM workflow_runs WHERE contact_id IN (SELECT contact_id FROM contacts WHERE contact_client_id IN ($d1,$d2,$dept)))");
$db->query("DELETE FROM workflow_run_tasks WHERE run_id IN (SELECT run_id FROM workflow_runs WHERE contact_id IN (SELECT contact_id FROM contacts WHERE contact_client_id IN ($d1,$d2,$dept)))");
$db->query("DELETE FROM workflow_runs WHERE contact_id IN (SELECT contact_id FROM contacts WHERE contact_client_id IN ($d1,$d2,$dept))");
$db->query("DELETE FROM contacts WHERE contact_client_id IN ($d1,$d2,$dept)");
$db->query("DELETE FROM workflow_template_tasks WHERE workflow_template_id IN ($t1,$t2)");
$db->query("DELETE FROM workflow_templates WHERE workflow_template_id IN ($t1,$t2)");

echo "\n$PASS passed, " . count($FAIL) . " failed\n";
if ($FAIL) { echo "FAILED: " . implode('; ', $FAIL) . "\n"; exit(1); }
