<?php
/*
 * Entra account actions in lifecycle workflows (entra_disable_account, entra_create_account, entra_add_to_groups) and the Graph write
 * methods behind them, against the REAL src/ code, a scratch database and a LOCAL mock of Graph (tests/mock/graph_mock.php).
 * Never contacts Microsoft. Proves: the setting gate blocks every write (nothing leaves the machine), dry run sends nothing, create is
 * idempotent, disable revokes sessions, a missing permission is classified as admin consent missing, the temporary password reaches
 * only the assigned technician once and appears in no log, audit event, task result or mock request log.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/entra_actions.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Integrations\Microsoft\{GraphClient, GraphException, EntraAccountService, GraphClientFactory};
use ITFlow\Workflow\{LiveActionGateway, TaskActionRunner, TemplateTaskService, WorkflowService};

function encryptSetting(string $p): string { return $p === '' ? '' : 'ENC2:' . strrev($p); }
function decryptSetting(string $c): string { return str_starts_with($c, 'ENC2:') ? strrev(substr($c, 5)) : $c; }

mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
date_default_timezone_set('UTC');
$fail = 0;
$ok = function (bool $c, string $l) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fail++; };
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

/** The live gateway with audit/notify captured (the real ones need the whole web app), pointed at the mock. */
class TGw extends LiveActionGateway {
    public array $audits = [];
    public array $notes = [];
    public function audit(string $e, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void { $this->audits[] = ['event' => $e, 'summary' => $sum, 'meta' => $meta]; }
    public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void { $this->notes[] = $m; }
}

// ---- mock Graph on a random localhost port
$port = random_int(20000, 40000);
$state = sys_get_temp_dir() . '/graph_mock_entra_' . getmypid() . '.json';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/mock/graph_mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_STATE' => $state] + $_ENV + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $state) { proc_terminate($proc); foreach ([$state, "$state.log", "$state.lock"] as $f) @unlink($f); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$base = "http://127.0.0.1:$port";
$mock = function (array $s) use ($base) { $c = stream_context_create(['http' => ['method' => 'POST', 'content' => json_encode($s), 'header' => 'Content-Type: application/json']]); return json_decode(file_get_contents("$base/__state", false, $c), true); };
$mlog = fn () => json_decode(file_get_contents("$base/__log"), true);
$writes = fn () => array_values(array_filter($mlog(), fn ($e) => $e['method'] !== 'GET' && !str_contains($e['path'], 'oauth2')));
$reset = fn () => $mock(['__reset' => 1]);
$mstate = fn () => json_decode((string) file_get_contents($state), true) ?: [];
$opts = ['graph_base' => "$base/v1.0", 'authority' => $base, 'sleep' => function (float $s): void {}];

// ---- fixtures
foreach (['workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates', 'microsoft_integrations'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM contacts WHERE contact_id >= 950"); $q("DELETE FROM users WHERE user_id >= 950"); $q("DELETE FROM clients WHERE client_id = 950");
$q("DELETE FROM settings WHERE company_id = 1"); $q("INSERT INTO settings SET company_id = 1, config_current_database_version = '2.6.140'");
$q("INSERT INTO clients SET client_id = 950, client_name = 'Entra Dept', client_currency_code = 'USD'");
$q("INSERT INTO users SET user_id = 950, user_name = 'Tech Tina', user_email = 'tina@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$q("INSERT INTO users SET user_id = 951, user_name = 'Admin Ada', user_email = 'ada@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO contacts SET contact_id = 950, contact_name = 'Erin New', contact_email = 'erin.new@emp.test', contact_client_id = 950");
$q("INSERT INTO contacts SET contact_id = 951, contact_name = 'Olly Leaver', contact_email = 'olly@emp.test', contact_client_id = 950");
$q("INSERT INTO contacts SET contact_id = 952, contact_name = 'Ghost', contact_email = 'ghost@emp.test', contact_client_id = 950");
$q("INSERT INTO contacts SET contact_id = 953, contact_name = 'No Mail', contact_email = '', contact_client_id = 950");
$q("INSERT INTO microsoft_integrations SET tenant_id = 'ok-tenant', client_id = 'client-1', client_secret_enc = '" . encryptSetting('secret-1') . "', enabled = 1");
$G1 = '11111111-1111-1111-1111-111111111111'; $G2 = '22222222-2222-2222-2222-222222222222';

$gw = new TGw($db, $opts);
$svc = new WorkflowService($db, $gw);
$tts = new TemplateTaskService($db);
$tpl = function (string $name, string $type) use ($q, $db) { $q("INSERT INTO workflow_templates SET name = '$name', type = '$type'"); return (int) $db->insert_id; };
$taskOf = fn (int $run) => $db->query("SELECT * FROM workflow_run_tasks WHERE run_id = $run ORDER BY sort_order, run_task_id LIMIT 1")->fetch_assoc();
$setAllow = fn (int $v) => $q("UPDATE settings SET config_entra_allow_writes = $v WHERE company_id = 1");

$onT = $tpl('Entra onboarding', 'onboarding');
$createCfg = ['upn' => '{{employee_email}}', 'display_name' => '{{employee_name}}', 'enabled' => 0, 'groups' => "$G1\n$G2"];
$tts->add($onT, ['title' => 'Create Entra account', 'task_type' => 'action', 'action_type' => 'entra_create_account', 'action_config' => $createCfg, 'assignee_user_id' => 950]);
$grpT = $tpl('Entra groups', 'onboarding');
$tts->add($grpT, ['title' => 'Add to groups', 'task_type' => 'action', 'action_type' => 'entra_add_to_groups', 'action_config' => ['groups' => $G1]]);
$offT = $tpl('Entra offboarding', 'offboarding');
$tts->add($offT, ['title' => 'Disable Entra account', 'task_type' => 'action', 'action_type' => 'entra_disable_account', 'action_config' => ['revoke_sessions' => 1]]);

// ---------------------------------------------------------------- template validation
$throws = function (callable $f): ?string { try { $f(); } catch (\InvalidArgumentException $e) { return $e->getMessage(); } return null; };
$ok($throws(fn () => $tts->add($offT, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_create_account', 'action_config' => []])) !== null, 'create-account is refused in an offboarding template');
$ok($throws(fn () => $tts->add($onT, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_disable_account', 'action_config' => []])) !== null, 'disable-account is refused in an onboarding template');
$ok($throws(fn () => $tts->add($grpT, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_add_to_groups', 'action_config' => ['groups' => 'not-a-guid']])) !== null, 'a group that is not an Object ID (GUID) is refused');
$ok($throws(fn () => $tts->add($grpT, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_add_to_groups', 'action_config' => ['groups' => '']])) !== null, 'add-to-groups needs at least one group');
$ok(isset(TaskActionRunner::labels()['entra_disable_account'], TaskActionRunner::labels()['entra_create_account'], TaskActionRunner::labels()['entra_add_to_groups']), 'the three actions are registered');

// ---------------------------------------------------------------- 1. the setting gate (off by default)
$reset();
$ok((int) $one("SELECT config_entra_allow_writes FROM settings WHERE company_id = 1") === 0 && GraphClientFactory::writesAllowed($db) === false, "'Allow RivetIT to change Entra accounts' is off by default");
$direct = new GraphClient('ok-tenant', 'client-1', 'secret-1', null, "$base/v1.0", $base, ['sleep' => fn ($s) => null]);
$err = null;
try { $direct->createUser(['userPrincipalName' => 'a@b.test', 'displayName' => 'A', 'mailNickname' => 'a', 'password' => 'Zz9!zzzzzzzzzzzzzzzz']); } catch (GraphException $e) { $err = $e; }
$ok($err !== null && $err->errorCode === GraphException::WRITES_DISABLED && !str_contains($err->getMessage(), 'Zz9'), 'GraphClient refuses createUser while writes are not allowed (and the message has no password)');
foreach (['setAccountEnabled' => ['u', false], 'revokeSignInSessions' => ['u'], 'addGroupMember' => [$G1, 'u'], 'removeGroupMember' => [$G1, 'u']] as $m => $args) {
    $e2 = null; try { $direct->$m(...$args); } catch (GraphException $e) { $e2 = $e; }
    $ok($e2 !== null && $e2->errorCode === GraphException::WRITES_DISABLED, "GraphClient::$m is blocked while writes are not allowed");
}
$ok($mlog() === [], 'the blocked calls sent nothing at all (not even a token request)');
$mock(['accounts' => ['olly@emp.test' => ['id' => 'acct-olly', 'mail' => 'olly@emp.test', 'enabled' => true, 'displayName' => 'Olly Leaver', 'department' => 'Entra Dept']]]);
foreach ([[$onT, 950], [$grpT, 950], [$offT, 951]] as [$tid, $cid]) {
    $run = $svc->startRun($tid, $cid, 951);
    $t = $taskOf($run);
    $ok($t['status'] === 'action_failed' && $t['attempts'] == TaskActionRunner::MAX_ATTEMPTS && str_contains((string) $t['last_error'], 'Allow RivetIT to change Entra accounts') && str_contains((string) $t['last_error'], 'nothing was sent'), "setting off: {$t['action_type']} retried, then falls back to a manual task naming the setting");
}
$ok($mlog() === [], 'setting off: nothing was sent to Graph by any of the three actions');
$ok(count($gw->notes) === 3, 'the run owner is told about each manual fallback');

// ---------------------------------------------------------------- 2. dry run sends nothing
$setAllow(1);
$reset();
$snapshot = fn () => [$one('SELECT COUNT(*) FROM workflow_runs'), $one('SELECT COUNT(*) FROM workflow_run_tasks'), $one('SELECT COUNT(*) FROM workflow_task_log')];
$before = $snapshot(); $gw->audits = [];
$pv = $svc->previewRun($onT, 950);
$pv2 = $svc->previewRun($offT, 951);
$what = $pv['tasks'][0]['what']; $what2 = $pv2['tasks'][0]['what'];
$ok($mlog() === [] && $snapshot() === $before && $gw->audits === [], 'dry run (writes ON): no Graph request, no rows, no audit events');
$ok(str_contains($what, 'create user erin.new@emp.test') && str_contains($what, 'Erin New') && str_contains($what, 'disabled') && str_contains($what, '2 group(s)') && str_contains($what, 'Would send'), 'the preview says what would be sent for create (UPN, name, disabled, groups)');
$ok(str_contains($what2, 'accountEnabled=false for olly@emp.test') && str_contains($what2, 'revokeSignInSessions') && str_contains($what2, 'never deleted'), 'the preview says what would be sent for disable');
$ok(!preg_match('/password[^.]*[A-Za-z0-9!#$%*+=?@-]{12,}/', $what), 'the preview contains no password');
$setAllow(0);
$pv3 = $svc->previewRun($onT, 950);
$ok(count(array_filter($pv3['warnings'], fn ($w) => str_contains($w, 'Allow RivetIT to change Entra accounts'))) === 1, 'dry run warns when the setting is off');
$setAllow(1);

// ---------------------------------------------------------------- 3. disable: disables sign-in AND revokes sessions
$reset();
$mock(['accounts' => ['olly@emp.test' => ['id' => 'acct-olly', 'mail' => 'olly@emp.test', 'enabled' => true, 'displayName' => 'Olly Leaver', 'department' => 'Entra Dept']]]);
$gw->audits = [];
$run = $svc->startRun($offT, 951, 951);
$t = $taskOf($run);
$st = $mstate();
$ok($t['status'] === 'completed' && $st['accounts']['olly@emp.test']['enabled'] === false, 'offboarding: the account is disabled and the task completes');
$ok(($st['revoked'] ?? []) === ['acct-olly'], 'offboarding: sign-in sessions were revoked');
$ok(count($writes()) === 2 && $writes()[0]['method'] === 'PATCH' && $writes()[1]['method'] === 'POST' && str_ends_with($writes()[1]['path'], '/revokeSignInSessions') && !in_array('DELETE', array_column($writes(), 'method'), true), 'exactly a PATCH and a revoke were sent; nothing was deleted');
$evs = array_column($gw->audits, 'event');
$ok(in_array('entra.account_disabled', $evs, true) && in_array('entra.sessions_revoked', $evs, true), 'each Graph change is audited');
// repeat: already disabled -> still safe, sessions revoked again, no error
$run2 = $svc->startRun($offT, 951, 951, false);
$ok($taskOf($run2)['status'] === 'completed' && ($mstate()['accounts']['olly@emp.test']['enabled'] === false), 'running disable again on a disabled account is harmless');
// unknown person: must not look done
$q("UPDATE contacts SET contact_email = 'nobody@emp.test' WHERE contact_id = 952");
$run3 = $svc->startRun($offT, 952, 951);
$t3 = $taskOf($run3);
$ok($t3['status'] === 'action_failed' && str_contains((string) $t3['last_error'], 'no Entra account found'), 'no Entra account found: the task becomes manual instead of looking done');
$run4 = $svc->startRun($offT, 953, 951);
$ok($taskOf($run4)['status'] === 'action_failed' && str_contains((string) $taskOf($run4)['last_error'], 'no usable email'), 'a person with no email address cannot be matched: manual task');

// ---------------------------------------------------------------- 4. create: idempotent, secret handling
$reset(); $gw->audits = [];
$run = $svc->startRun($onT, 950, 951);
$t = $taskOf($run);
$st = $mstate();
$pw = $st['last_password'] ?? '';
$acct = $st['accounts']['erin.new@emp.test'] ?? null;
$ok($t['status'] === 'completed' && $acct !== null && $acct['enabled'] === false && $acct['force_change'] === true && $acct['has_password'] === true && ($st['creates'] ?? 0) === 1, 'create: account made disabled, with a password, forced change at first sign-in');
$ok(strlen($pw) >= 16 && preg_match('/[A-Z]/', $pw) && preg_match('/[a-z]/', $pw) && preg_match('/\d/', $pw) && preg_match('/[^A-Za-z0-9]/', $pw), 'the temporary password is long and uses all four character classes');
$ok(count($st['groups'][$G1] ?? []) === 1 && count($st['groups'][$G2] ?? []) === 1, 'create: the new user was added to both groups');
$secretRow = $db->query("SELECT secret_result_enc, secret_user_id, secret_expires_at FROM workflow_run_tasks WHERE run_task_id = {$t['run_task_id']}")->fetch_assoc();
$ok($secretRow['secret_user_id'] == 950 && str_starts_with((string) $secretRow['secret_result_enc'], 'ENC2:') && !str_contains($secretRow['secret_result_enc'], $pw) && $secretRow['secret_expires_at'] > date('Y-m-d H:i:s'), 'the password is stored encrypted for the assigned technician, with an expiry');
// nowhere else
$dump = json_encode([$db->query('SELECT * FROM workflow_task_log')->fetch_all(MYSQLI_ASSOC), $gw->audits, $gw->notes, $db->query("SELECT last_error, action_config, instructions FROM workflow_run_tasks")->fetch_all(MYSQLI_ASSOC), $mlog(), $db->query('SELECT * FROM email_queue')->fetch_all(MYSQLI_ASSOC), $db->query('SELECT * FROM logs')->fetch_all(MYSQLI_ASSOC)]);
$ok($dump !== false && !str_contains($dump, $pw) && !str_contains($dump, strrev($pw)), 'the password is in no task log, audit event, notification, mail, request log or task result');
$ok(in_array('entra.account_created', array_column($gw->audits, 'event'), true) && count(array_filter($gw->audits, fn ($a) => $a['event'] === 'entra.group_member_added')) === 2, 'create and each group add are audited');
// reveal: only the assigned technician, once
$ok($svc->revealTaskSecret((int) $t['run_task_id'], 951) === null && $one("SELECT secret_result_enc IS NOT NULL FROM workflow_run_tasks WHERE run_task_id = {$t['run_task_id']}") == 1, 'an administrator who is not the assigned technician cannot read it (and it is not consumed)');
$ok($svc->revealTaskSecret((int) $t['run_task_id'], 0) === null, 'no user id, no secret');
$got = $svc->revealTaskSecret((int) $t['run_task_id'], 950);
$ok($got === $pw, 'the assigned technician gets the exact password the mock received');
$ok($svc->revealTaskSecret((int) $t['run_task_id'], 950) === null && $one("SELECT secret_result_enc FROM workflow_run_tasks WHERE run_task_id = {$t['run_task_id']}") === null, 'it can be read only once, then it is erased');
$ok(in_array('workflow.secret_revealed', array_column($gw->audits, 'event'), true) && !str_contains(json_encode($gw->audits), $pw), 'reading it is audited, without the secret');
// expiry
$q("UPDATE workflow_run_tasks SET secret_result_enc = '" . encryptSetting('Expired-Secret-1!') . "', secret_user_id = 950, secret_expires_at = NOW() - INTERVAL 1 MINUTE WHERE run_task_id = {$t['run_task_id']}");
$ok($svc->revealTaskSecret((int) $t['run_task_id'], 950) === null, 'an expired secret is never shown');
$q("UPDATE workflow_run_tasks SET secret_result_enc = '" . encryptSetting('Old-Secret-1!') . "', secret_user_id = 950, secret_expires_at = NOW() - INTERVAL 1 MINUTE WHERE run_task_id = {$t['run_task_id']}");
(new \ITFlow\Workflow\ReminderService($db, $gw))->run();
$ok($one("SELECT secret_result_enc FROM workflow_run_tasks WHERE run_task_id = {$t['run_task_id']}") === null, 'the reminder cron erases an expired, unread secret');
// idempotent: same person again
$run2 = $svc->startRun($onT, 950, 951, false);
$t2 = $taskOf($run2);
$st = $mstate();
$res = $db->query("SELECT detail FROM workflow_task_log WHERE run_task_id = {$t2['run_task_id']} AND event = 'action_ok'")->fetch_row()[0] ?? '';
$ok($t2['status'] === 'completed' && ($st['creates'] ?? 0) === 1 && str_contains($res, 'already exists') && $one("SELECT secret_result_enc FROM workflow_run_tasks WHERE run_task_id = {$t2['run_task_id']}") === null, 'create is idempotent: an existing UPN creates nothing and sets no password');
$ok(count($st['groups'][$G1]) === 1 && str_contains($res, 'no groups changed'), 'an existing account gets no group changes from a create task (IT-9)');
// enabled flag
$cEnabled = $tts->add($onT, ['title' => 'Create enabled', 'task_type' => 'action', 'action_type' => 'entra_create_account', 'action_config' => ['enabled' => 1, 'upn' => 'enabled.user@emp.test', 'display_name' => 'Enabled U'], 'assignee_user_id' => 950]);
$q("UPDATE workflow_template_tasks SET sort_order = 99 WHERE template_task_id = $cEnabled");
$q("DELETE FROM workflow_template_tasks WHERE workflow_template_id = $onT AND template_task_id <> $cEnabled");
$run5 = $svc->startRun($onT, 950, 951, false);
$ok(($mstate()['accounts']['enabled.user@emp.test']['enabled'] ?? null) === true, 'a task set to "create enabled" creates an enabled account');
// no recipient -> secret not kept, said so
$q("UPDATE workflow_template_tasks SET action_config = '" . json_encode(['upn' => 'orphan@emp.test', 'display_name' => 'Orphan']) . "', assignee_user_id = NULL WHERE template_task_id = $cEnabled");
$run6 = $svc->startRun($onT, 950, null, false);
$res6 = $db->query("SELECT detail FROM workflow_task_log WHERE run_id = $run6 AND event = 'action_ok'")->fetch_row()[0] ?? '';
$ok(str_contains($res6, 'could NOT be saved') && $one("SELECT secret_result_enc FROM workflow_run_tasks WHERE run_id = $run6") === null, 'with nobody to show it to the password is not kept, and the result says to reset it');

// ---------------------------------------------------------------- 5. add to groups
$reset();
$mock(['accounts' => ['erin.new@emp.test' => ['id' => 'acct-erin', 'mail' => 'erin.new@emp.test', 'enabled' => false, 'displayName' => 'Erin New', 'department' => 'Entra Dept']]]);
$run = $svc->startRun($grpT, 950, 951);
$ok($taskOf($run)['status'] === 'completed' && ($mstate()['groups'][$G1] ?? []) === ['acct-erin'], 'add-to-groups adds the user');
$run = $svc->startRun($grpT, 950, 951, false);
$ok($taskOf($run)['status'] === 'completed' && ($mstate()['groups'][$G1] ?? []) === ['acct-erin'], 'add-to-groups twice leaves one membership');
// mail lookup path (UPN differs from the contact email)
$mock(['accounts' => ['erin.new@emp.test' => ['id' => 'acct-erin', 'mail' => 'erin.new@emp.test', 'enabled' => true, 'displayName' => 'E', 'department' => 'Entra Dept'], 'e.new@corp.onmicrosoft.com' => ['id' => 'acct-erin2', 'mail' => 'e.same@emp.test', 'enabled' => true, 'displayName' => 'E2', 'department' => 'Entra Dept']]]);
$q("UPDATE contacts SET contact_email = 'e.same@emp.test' WHERE contact_id = 952");
$g = $svc->startRun($offT, 952, 951);
$ok($taskOf($g)['status'] === 'completed' && $mstate()['accounts']['e.new@corp.onmicrosoft.com']['enabled'] === false && $mstate()['accounts']['erin.new@emp.test']['enabled'] === true, 'a user whose UPN differs from the contact email is found by mail, and only that one is disabled');

// ---------------------------------------------------------------- 6. permission missing is classified as admin consent missing
$reset(); $mock(['write_forbidden' => true, 'accounts' => ['olly@emp.test' => ['id' => 'acct-olly', 'mail' => 'olly@emp.test', 'enabled' => true, 'displayName' => 'O', 'department' => 'Entra Dept']]]);
$run = $svc->startRun($offT, 951, 951, false);
$t = $taskOf($run);
$ok($t['status'] === 'action_failed' && stripos((string) $t['last_error'], 'admin consent missing') !== false && str_contains((string) $t['last_error'], 'User.ReadWrite.All'), 'a 403 on a write is "Admin consent missing for User.ReadWrite.All" and the task falls back to manual');
$direct = new GraphClient('ok-tenant', 'client-1', 'secret-1', null, "$base/v1.0", $base, ['allow_writes' => true, 'sleep' => fn ($s) => null]);
$e3 = null; try { $direct->addGroupMember($G1, 'acct-olly'); } catch (GraphException $e) { $e3 = $e; }
$ok($e3 !== null && $e3->errorCode === GraphException::CONSENT_MISSING && str_contains($e3->getMessage(), 'Group.ReadWrite.All') && stripos($e3->getMessage(), 'admin consent missing') !== false, 'a group write 403 names Group.ReadWrite.All');
$e4 = null; try { $direct->createUser(['userPrincipalName' => 'x@emp.test', 'displayName' => 'X', 'mailNickname' => 'x', 'password' => 'Qq7!qqqqqqqqqqqqqqqq']); } catch (GraphException $e) { $e4 = $e; }
$ok($e4 !== null && $e4->errorCode === GraphException::CONSENT_MISSING && !str_contains($e4->getMessage(), 'Qq7!'), 'a create 403 is classified and never echoes the password');

// ---------------------------------------------------------------- 7. retry rules, throttling
$reset(); $mock(['throttle' => 1, 'retry_after' => 0, 'accounts' => ['olly@emp.test' => ['id' => 'acct-olly', 'mail' => 'olly@emp.test', 'enabled' => true, 'displayName' => 'O', 'department' => 'Entra Dept']]]);
$run = $svc->startRun($offT, 951, 951, false);
$ok($taskOf($run)['status'] === 'completed', 'a throttled (429) write is retried by the client and succeeds');
$reset();
$run = $svc->startRun($offT, 952, 951, false);
$q("UPDATE contacts SET contact_email = 'ghost@emp.test' WHERE contact_id = 952");
$ok($one("SELECT attempts FROM workflow_run_tasks WHERE run_id = $run") == TaskActionRunner::MAX_ATTEMPTS, 'a failing Entra action is attempted MAX_ATTEMPTS times, then handed to a person');
$retried = $svc->retryAction((int) $taskOf($run)['run_task_id'], 951);
$ok($retried === false && $one("SELECT status FROM workflow_run_tasks WHERE run_id = $run") === 'action_failed', 'the manual Retry button re-runs it through the same path');

// ---------------------------------------------------------------- 8. service units
$ok(EntraAccountService::cleanGroupIds("$G1, $G1\n" . strtoupper($G2) . "\nbogus") === [$G1, $G2], 'group ids are validated, lower-cased and de-duplicated');
$pws = array_map(fn () => EntraAccountService::generatePassword(), range(1, 50));
$ok(count(array_unique($pws)) === 50 && min(array_map('strlen', $pws)) === 20, 'generated passwords are unique and 20 characters');
$ok(EntraAccountService::mailNickname('first.last+tag@x.test') === 'first.lasttag', 'mailNickname is derived from the UPN');
$sql = file_get_contents(__DIR__ . '/../admin/database_updates.php');
$ok(str_contains($sql, 'config_entra_allow_writes') && str_contains($sql, 'secret_result_enc') && str_contains(file_get_contents(__DIR__ . '/../db.sql'), 'config_entra_allow_writes'), 'the migration and db.sql carry the new columns');

echo $fail === 0 ? "\nAll Entra account action checks passed\n" : "\n$fail check(s) FAILED\n";
exit($fail === 0 ? 0 : 1);
