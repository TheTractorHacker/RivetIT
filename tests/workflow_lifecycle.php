<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Employee lifecycle workflows against the REAL src/Workflow code on a scratch database:
 *   dependencies (validation, cycles, blocked/unblock), due dates, approvals, automated actions (success, retry, failure fallback),
 *   dry-run purity, idempotent auto-start, reminders (once per task per state), lifecycle event detection, migration idempotence.
 * Needs a THROWAWAY database that holds this app's schema (import db.sql and run the database updates); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/workflow_lifecycle.php
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
use ITFlow\Workflow\{ActionGateway, DependencyGraph, DueDateResolver, LifecycleEvents, ReminderService, StartWorkflowRule, TaskActionRunner, TemplateTaskService, WorkflowService, LiveActionGateway};
use ITFlow\Workflow\Actions\{Placeholders, ActionInterface, AbstractAction};

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;
$throws = function (callable $f, string $cls = \DomainException::class): ?string { try { $f(); } catch (\Throwable $e) { return $e instanceof $cls ? $e->getMessage() : 'WRONG:' . get_class($e) . ':' . $e->getMessage(); } return null; };

/** Records every side effect instead of doing it. */
class FakeGateway implements ActionGateway {
    public array $calls = [];
    public bool $failMail = false;
    public int $mailFailuresLeft = 0;
    public function createTicket(string $s, string $d, string $p, int $c, string $src): int { $this->calls[] = ['ticket', $s, $d, $p, $c]; return 42; }
    public function queueMail(string $to, string $n, string $s, string $b): void {
        if ($this->failMail || $this->mailFailuresLeft-- > 0) { throw new RuntimeException('smtp down'); }
        $this->calls[] = ['mail', $to, $n, $s, $b];
    }
    public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void { $this->calls[] = ['notify', $u, $m]; }
    public function emitEvent(string $ev, array $d): void { $this->calls[] = ['event', $ev, $d]; }
    public function audit(string $ev, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void { $this->calls[] = ['audit', $ev]; }
    public function disablePortalLogin(int $c): string { $this->calls[] = ['disable', $c]; return 'portal login disabled (archived)'; }
    public function count(string $kind): int { return count(array_filter($this->calls, fn($c) => $c[0] === $kind)); }
}

// ---------------------------------------------------------------- fixtures
foreach (['workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates', 'automation_rules'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM contacts WHERE contact_id >= 900"); $q("DELETE FROM users WHERE user_id >= 900"); $q("DELETE FROM clients WHERE client_id = 900");
$q("INSERT IGNORE INTO user_roles (role_id, role_name, role_description, role_is_admin) VALUES (1, 'Test Admin', 'fixture', 1), (2, 'Test Technician', 'fixture', 0)");
$q("INSERT INTO clients SET client_id = 900, client_name = 'Test Dept', client_currency_code = 'USD'");
$q("INSERT INTO users SET user_id = 900, user_name = 'Approver Alice', user_email = 'alice@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$q("INSERT INTO users SET user_id = 901, user_name = 'Plain Bob', user_email = 'bob@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$q("INSERT INTO users SET user_id = 902, user_name = 'Manager Mia', user_email = 'mia@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO users SET user_id = 904, user_name = 'Admin Ada', user_email = 'ada@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO users SET user_id = 903, user_name = 'Portal Pat', user_email = 'pat@x.test', user_password = 'x', user_type = 2, user_status = 1, user_role_id = 0");
$q("INSERT INTO contacts SET contact_id = 900, contact_name = 'Mia <b>Manager</b>', contact_email = 'mia@emp.test', contact_client_id = 900, contact_user_id = 902");
$q("INSERT INTO contacts SET contact_id = 901, contact_name = 'Erin O\\'Hire', contact_email = 'erin@emp.test', contact_client_id = 900, contact_manager_id = 900, contact_start_date = '2030-03-10', contact_expected_end_date = '2030-09-01', contact_user_id = 903");
$q("INSERT INTO contacts SET contact_id = 902, contact_name = 'No Mail', contact_email = '', contact_client_id = 900");
$q("INSERT INTO contacts SET contact_id = 903, contact_name = 'Agent Linked', contact_email = 'al@emp.test', contact_client_id = 900, contact_user_id = 901");
$gw = new FakeGateway();
$svc = new WorkflowService($mysqli, $gw);
$tts = new TemplateTaskService($mysqli);
$tpl = function (string $name, string $type = 'onboarding') use ($q, $mysqli) { $q("INSERT INTO workflow_templates SET name = '$name', type = '$type'"); return (int) mysqli_insert_id($mysqli); };
$runTasks = function (int $runId) use ($q) { $o = []; $r = $q("SELECT * FROM workflow_run_tasks WHERE run_id = $runId ORDER BY sort_order, run_task_id"); while ($row = mysqli_fetch_assoc($r)) { $o[$row['title']] = $row; } return $o; };
$statusOf = fn(int $runId) => $one("SELECT status FROM workflow_runs WHERE run_id = $runId");

// ---------------------------------------------------------------- 1. dependency graph (pure)
$ok(DependencyGraph::parse('3, 5,x,5,-2,0') === [3, 5], 'depends_on parses to unique positive ids');
$ok(DependencyGraph::format([4, 4, 2]) === '4,2' && DependencyGraph::format([]) === null, 'depends_on formats back to a list, empty is NULL');
$ok(DependencyGraph::validate([1 => [], 2 => [1], 3 => [1, 2]]) === null, 'a diamond-free chain is valid');
$ok(DependencyGraph::validate([1 => [2], 2 => [1]]) !== null, 'a two-task loop is rejected');
$ok(DependencyGraph::validate([1 => [2], 2 => [3], 3 => [1]]) !== null, 'a three-task loop is rejected');
$ok(DependencyGraph::validate([1 => [1]]) !== null, 'a task depending on itself is rejected');
$ok(DependencyGraph::validate([1 => [99]]) !== null, 'a dependency outside the template is rejected');

// ---------------------------------------------------------------- 2. template task service (validation against the DB)
$t1 = $tpl('Onboard A');
$a = $tts->add($t1, ['title' => 'Create account']);
$b = $tts->add($t1, ['title' => 'Order laptop', 'depends_on' => [$a]]);
$c = $tts->add($t1, ['title' => 'Ship laptop', 'depends_on' => [$b]]);
$ok($one("SELECT depends_on FROM workflow_template_tasks WHERE template_task_id = $c") == (string) $b, 'a task saves its dependency');
$ok($throws(fn() => $tts->update($t1, $a, ['title' => 'Create account', 'depends_on' => [$c]]), \InvalidArgumentException::class) !== null, 'closing a loop through the editor is rejected');
$tOther = $tpl('Other');
$other = $tts->add($tOther, ['title' => 'Elsewhere']);
$ok($throws(fn() => $tts->add($t1, ['title' => 'X', 'depends_on' => [$other]]), \InvalidArgumentException::class) !== null, 'a dependency on a task of another template is rejected');
$ok($throws(fn() => $tts->add($t1, ['title' => '']), \InvalidArgumentException::class) !== null, 'a task needs a title');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Act', 'task_type' => 'action', 'action_type' => 'disable_contact_login']), \InvalidArgumentException::class) !== null, 'disabling a login is refused in an onboarding template');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Act', 'task_type' => 'action', 'action_type' => 'send_mail', 'action_config' => ['to' => 'address', 'address' => 'nope', 'subject' => 's', 'body' => 'b']]), \InvalidArgumentException::class) !== null, 'a mail action with a bad address is refused');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Act', 'task_type' => 'action', 'action_type' => 'notify_user', 'action_config' => ['message' => 'Hi {{nope}}']]), \InvalidArgumentException::class) !== null, 'an unknown placeholder is refused');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Appr', 'task_type' => 'approval']), \InvalidArgumentException::class) !== null, 'an approval task needs an approver');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Due', 'due_offset_days' => 'soon']), \InvalidArgumentException::class) !== null, 'a non-numeric due offset is refused');
$ok($throws(fn() => $tts->add($t1, ['title' => 'Asg', 'assignee_user_id' => 903]), \InvalidArgumentException::class) !== null, 'a portal login cannot be an assignee');
$tts->delete($t1, $b);
$ok($one("SELECT depends_on FROM workflow_template_tasks WHERE template_task_id = $c") === null, 'deleting a task removes it from other tasks dependency lists');

// ---------------------------------------------------------------- 3. legacy template behaves exactly like the flat engine
$tl = $tpl('Legacy');
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = $tl, title = 'One', sort_order = 0");
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = $tl, title = 'Two', sort_order = 1, required = 0");
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = $tl, title = 'Three', sort_order = 2");
$run = $svc->startRun($tl, 901, 900);
$rt = $runTasks($run);
$ok(count($rt) === 3 && count(array_filter($rt, fn($t) => $t['status'] === 'pending' && $t['task_type'] === 'manual' && $t['due_at'] === null && $t['depends_on'] === null && $t['attempts'] == 0)) === 3, 'legacy template: all tasks pending, manual, no due date, no dependencies');
$ok($gw->calls === [] || $gw->count('mail') === 0, 'legacy template sends nothing');
$svc->completeTask((int) $rt['One']['run_task_id'], 900);
$ok($statusOf($run) === 'in_progress', 'run stays in progress while a required task is pending');
$svc->skipTask((int) $rt['Three']['run_task_id'], 'not needed', 900);
$ok($statusOf($run) === 'completed_with_exceptions', 'required tasks resolved with a skip -> completed_with_exceptions (optional task never gates)');
$svc->reopenTask((int) $rt['Three']['run_task_id']);
$ok($statusOf($run) === 'in_progress', 'reopening un-completes the run');
$svc->completeTask((int) $rt['Three']['run_task_id'], 900);
$ok($statusOf($run) === 'completed', 'all required done -> completed');
$svc->completeTask((int) $rt['Three']['run_task_id'], 900);
$ok($statusOf($run) === 'completed', 'completing twice is harmless');
$svc->cancelRun($run);
$ok($statusOf($run) === 'cancelled', 'cancel works');
$ok($svc->taskInRun((int) $rt['One']['run_task_id'], $run) && !$svc->taskInRun((int) $rt['One']['run_task_id'], $run + 999), 'a task id only belongs to its own run');

// ---------------------------------------------------------------- 4. dependencies on a run: blocked / unblock
$t2 = $tpl('Chain');
$x = $tts->add($t2, ['title' => 'Step 1']);
$y = $tts->add($t2, ['title' => 'Step 2', 'depends_on' => [$x]]);
$z = $tts->add($t2, ['title' => 'Step 3', 'depends_on' => [$x, $y]]);
$run = $svc->startRun($t2, 901, 900);
$rt = $runTasks($run);
$ok($rt['Step 1']['status'] === 'pending' && $rt['Step 2']['status'] === 'blocked' && $rt['Step 3']['status'] === 'blocked', 'tasks with unmet dependencies start blocked');
$ok(DependencyGraph::parse($rt['Step 3']['depends_on']) === [(int) $rt['Step 1']['run_task_id'], (int) $rt['Step 2']['run_task_id']], 'run task dependencies are translated to run task ids');
$ok(str_contains((string) $throws(fn() => $svc->completeTask((int) $rt['Step 2']['run_task_id'], 900)), 'cannot be completed'), 'a blocked task cannot be completed');
$ok(str_contains((string) $throws(fn() => $svc->skipTask((int) $rt['Step 2']['run_task_id'], 'x', 900)), 'cannot be skipped'), 'a blocked task cannot be skipped');
$svc->completeTask((int) $rt['Step 1']['run_task_id'], 900);
$rt = $runTasks($run);
$ok($rt['Step 2']['status'] === 'pending' && $rt['Step 3']['status'] === 'blocked', 'completing a dependency unblocks the task that was waiting only on it');
$svc->skipTask((int) $rt['Step 2']['run_task_id'], 'n/a', 900);
$ok($runTasks($run)['Step 3']['status'] === 'pending', 'skipping a dependency unblocks too');
$svc->reopenTask((int) $rt['Step 1']['run_task_id']);
$rt = $runTasks($run);
$ok($rt['Step 3']['status'] === 'blocked', 'reopening a dependency blocks its dependents again');
$svc->completeTask((int) $rt['Step 1']['run_task_id'], 900);
$svc->completeTask((int) $rt['Step 3']['run_task_id'], 900);
$ok($statusOf($run) === 'completed_with_exceptions', 'dependency chain runs to completion');

// ---------------------------------------------------------------- 5. due dates
$ok(DueDateResolver::resolve(null, 'run', null, null, '2030-01-01 10:00:00') === null, 'no offset -> no due date');
$ok(DueDateResolver::resolve(3, 'run', null, null, '2030-01-01 10:00:00') === '2030-01-04 10:00:00', 'run anchor keeps the time of day');
$ok(DueDateResolver::resolve(-2, 'start', '2030-03-10', null, '2030-01-01 10:00:00') === '2030-03-08 17:00:00', 'start anchor, negative offset = before the start date');
$ok(DueDateResolver::resolve(0, 'end', '2030-03-10', '2030-09-01', '2030-01-01 10:00:00') === '2030-09-01 17:00:00', 'end anchor is the end date');
$ok(DueDateResolver::resolve(5, 'end', '2030-03-10', null, '2030-01-01 10:00:00') === '2030-01-06 10:00:00', 'a missing end date falls back to the run start');
$ok(DueDateResolver::state('2030-01-01 00:00:00', strtotime('2030-01-03')) === 'overdue' && DueDateResolver::state('2030-01-03 12:00:00', strtotime('2030-01-03')) === 'due_soon' && DueDateResolver::state('2030-02-01 00:00:00', strtotime('2030-01-03')) === 'later' && DueDateResolver::state(null, 1) === null, 'overdue / due soon / later / none');
$t3 = $tpl('Dates');
$tts->add($t3, ['title' => 'Before start', 'due_offset_days' => '-2', 'due_anchor' => 'start', 'assignee_user_id' => 901]);
$tts->add($t3, ['title' => 'After end', 'due_offset_days' => '1', 'due_anchor' => 'end']);
$tts->add($t3, ['title' => 'No due']);
$run = $svc->startRun($t3, 901, 900);
$rt = $runTasks($run);
$ok($rt['Before start']['due_at'] === '2030-03-08 17:00:00' && (int) $rt['Before start']['assignee_user_id'] === 901 && $rt['After end']['due_at'] === '2030-09-02 17:00:00' && $rt['No due']['due_at'] === null, 'run tasks get resolved due dates and assignee from the template');

// ---------------------------------------------------------------- 6. approvals
$t4 = $tpl('Approvals');
$ap1 = $tts->add($t4, ['title' => 'Manager OK', 'task_type' => 'approval', 'approver_type' => 'manager']);
$ap2 = $tts->add($t4, ['title' => 'Alice OK', 'task_type' => 'approval', 'approver_type' => 'user', 'approver_user_id' => 900, 'depends_on' => [$ap1]]);
$ap3 = $tts->add($t4, ['title' => 'Role OK', 'task_type' => 'approval', 'approver_type' => 'role', 'approver_role_id' => 1, 'depends_on' => [$ap2]]);
$tts->add($t4, ['title' => 'Provision', 'depends_on' => [$ap3]]);
$gw->calls = [];
$run = $svc->startRun($t4, 901, 900);
$rt = $runTasks($run);
$ok($rt['Manager OK']['status'] === 'pending' && $rt['Manager OK']['approval_status'] === 'pending' && $rt['Alice OK']['status'] === 'blocked', 'an approval task waits (pending) and later ones are blocked behind it');
$ok(count(array_filter($gw->calls, fn($c) => $c[0] === 'notify' && $c[1] === 902)) === 1, 'the employee\'s manager (with a login) is notified once that the approval is ready');
$id = (int) $rt['Manager OK']['run_task_id'];
$ok(str_contains((string) $throws(fn() => $svc->completeTask($id, 900)), 'needs an approval'), 'an approval task cannot be ticked off by hand');
$ok(str_contains((string) $throws(fn() => $svc->skipTask($id, 'x', 901, false)), 'administrator'), 'a non-administrator cannot skip an approval');
$ok(!$svc->canDecide($id, 901) && $svc->canDecide($id, 902) && $svc->canDecide($id, 904), 'only the manager and administrators can decide the manager approval');
$ok($throws(fn() => $svc->approveTask($id, 901, 'sneaky')) === 'You are not an approver for this task.', 'a non-approver cannot approve');
$svc->approveTask($id, 902, 'looks fine');
$r1 = $runTasks($run)['Manager OK'];
$ok($r1['status'] === 'completed' && (int) $r1['approved_by'] === 902 && $r1['approved_at'] !== null && $r1['approval_comment'] === 'looks fine', 'approval records who, when and the comment and completes the task');
$ok($runTasks($run)['Alice OK']['status'] === 'pending', 'approving unblocks the next task');
$ok($throws(fn() => $svc->approveTask($id, 902, '')) !== null, 'an approval cannot be decided twice');
$id2 = (int) $runTasks($run)['Alice OK']['run_task_id'];
$gw->calls = [];
$svc->rejectTask($id2, 900, 'budget not approved');
$r2 = $runTasks($run)['Alice OK'];
$ok($r2['status'] === 'rejected' && $r2['approval_status'] === 'rejected' && (int) $r2['approved_by'] === 900 && $r2['approval_comment'] === 'budget not approved', 'a rejection is recorded with who and why');
$ok($statusOf($run) === 'paused', 'a rejection pauses the run');
$ok($gw->count('notify') >= 1 && $gw->count('audit') >= 1, 'a rejection notifies and is audited');
$ok(str_contains((string) $throws(fn() => $svc->completeTask($id2, 900)), 'rejected'), 'a rejected task cannot be completed');
$ok(str_contains((string) $throws(fn() => $svc->skipTask($id2, 'x', 901, false)), 'administrator'), 'a rejected approval cannot be skipped by a non-administrator');
$svc->reopenTask($id2);
$ok($statusOf($run) === 'in_progress' && $runTasks($run)['Alice OK']['approval_status'] === 'pending' && $runTasks($run)['Alice OK']['status'] === 'pending', 'reopening a rejected approval resumes the run and asks again');
$svc->approveTask($id2, 900, '');
$id3 = (int) $runTasks($run)['Role OK']['run_task_id'];
$ok($svc->canDecide($id3, 902) && !$svc->canDecide($id3, 900), 'a role approver: anyone holding the role (Mia is an Accountant), not others');
$svc->rejectTask($id3, 902, 'no');
$svc->skipTask($id3, 'override', 904, true);
$ok($runTasks($run)['Role OK']['status'] === 'skipped' && $statusOf($run) === 'in_progress' && $runTasks($run)['Provision']['status'] === 'pending', 'an administrator can skip a rejected approval; the run resumes and the next task unblocks');
$ok((int) $one("SELECT COUNT(*) FROM workflow_task_log WHERE run_id = $run AND event IN ('approval_approved','approval_rejected','approval_overridden')") === 5, 'every decision is in the task log');

// ---------------------------------------------------------------- 7. action tasks
$t5 = $tpl('Actions');
$a1 = $tts->add($t5, ['title' => 'Welcome mail', 'task_type' => 'action', 'action_type' => 'send_mail', 'action_config' => ['to' => 'employee', 'subject' => 'Welcome {{employee_name}}', 'body' => "Hi {{employee_name}},\nstart {{start_date}}"]]);
$a2 = $tts->add($t5, ['title' => 'Ticket', 'task_type' => 'action', 'action_type' => 'create_ticket', 'depends_on' => [$a1], 'action_config' => ['subject' => 'Set up {{employee_name}}', 'details' => 'Dept {{department}}', 'priority' => 'High']]);
$a3 = $tts->add($t5, ['title' => 'Tell team', 'task_type' => 'action', 'action_type' => 'notify_user', 'action_config' => ['message' => '{{employee_name}} joins']]);
$a4 = $tts->add($t5, ['title' => 'Hook', 'task_type' => 'action', 'action_type' => 'send_webhook', 'action_config' => ['event' => 'workflow.hired_hook']]);
$a5 = $tts->add($t5, ['title' => 'Manual after', 'depends_on' => [$a2]]);
$gw->calls = [];
$run = $svc->startRun($t5, 901, 900);
$rt = $runTasks($run);
$ok($rt['Welcome mail']['status'] === 'completed' && $rt['Ticket']['status'] === 'completed' && $rt['Tell team']['status'] === 'completed' && $rt['Hook']['status'] === 'completed', 'ready action tasks run at start, and a dependent action runs right after its dependency');
$ok($rt['Manual after']['status'] === 'pending', 'a manual task behind an action is unblocked when the action completes');
$mail = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'mail'))[0] ?? null;
$ok($mail && $mail[1] === 'erin@emp.test' && $mail[3] === "Welcome Erin O'Hire" && str_contains($mail[4], "Erin O&#039;Hire") && str_contains($mail[4], '2030-03-10') && str_contains($mail[4], '<br'), 'mail is templated, addressed to the employee, body escaped, subject plain');
$tk = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'ticket'))[0] ?? null;
$ok($tk && $tk[1] === "Set up Erin O'Hire" && $tk[3] === 'High' && $tk[4] === 900 && str_contains($tk[2], 'Test Dept'), 'ticket action uses the department and priority');
$ev = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'event' && $c[1] !== 'workflow.task_completed'))[0] ?? null; // task completions are events too (automation engine)
$ok($ev && $ev[1] === 'workflow.hired_hook' && $ev[2]['contact_id'] === 901, 'webhook action emits the configured event on the bus (no URL in the task)');
$ok((int) $one("SELECT COUNT(*) FROM workflow_task_log WHERE run_id = $run AND event = 'action_ok'") === 4 && $gw->count('audit') >= 4, 'each execution is in workflow_task_log and the audit trail');
$ok($rt['Welcome mail']['attempts'] == 1 && $rt['Welcome mail']['last_error'] === null, 'attempt counter is 1 on first-time success');

// HTML in a name must be escaped in mail bodies
$ok(Placeholders::render('{{employee_name}}', ['employee_name' => '<script>x</script>'], 'html') === '&lt;script&gt;x&lt;/script&gt;', 'placeholders escape HTML in html mode');
$ok(Placeholders::render("{{employee_name}}", ['employee_name' => "a\r\nBcc: x"], 'text') === 'a Bcc: x', 'placeholders strip control characters in text mode');

// failure: retries, last_error, fallback to action_failed, manual completion
$t6 = $tpl('Failing');
$f1 = $tts->add($t6, ['title' => 'Mail it', 'task_type' => 'action', 'action_type' => 'send_mail', 'action_config' => ['to' => 'employee', 'subject' => 's', 'body' => 'b']]);
$tts->add($t6, ['title' => 'After mail', 'depends_on' => [$f1]]);
$gw->failMail = true; $gw->calls = [];
$run = $svc->startRun($t6, 901, 900);
$rt = $runTasks($run);
$ok($rt['Mail it']['status'] === 'action_failed' && (int) $rt['Mail it']['attempts'] === TaskActionRunner::MAX_ATTEMPTS && $rt['Mail it']['last_error'] === 'smtp down', 'a failing action retries, then falls back to action_failed with the error');
$ok($rt['After mail']['status'] === 'blocked' && $statusOf($run) === 'in_progress', 'the failed task still blocks its dependents and the run stays open');
$ok((int) $one("SELECT COUNT(*) FROM workflow_task_log WHERE run_id = $run AND event = 'action_error'") === 3, 'every failed attempt is logged');
$ok($gw->count('notify') >= 1, 'a failed action notifies the run owner');
$ok($throws(fn() => $svc->retryAction((int) $rt['After mail']['run_task_id'], 900)) !== null, 'only automated tasks can be retried');
$gw->failMail = false;
$ok($svc->retryAction((int) $rt['Mail it']['run_task_id'], 900) === true && $runTasks($run)['Mail it']['status'] === 'completed' && $runTasks($run)['After mail']['status'] === 'pending', 'an agent can retry a failed action; success unblocks dependents');
$run = $svc->startRun($t6, 901, 900, false);
$gw->failMail = true;
$run2 = $svc->startRun($t6, 902, 900);   // contact with no email address: fails for a data reason
$gw->failMail = false;
$id = (int) $runTasks($run2)['Mail it']['run_task_id'];
$ok($runTasks($run2)['Mail it']['status'] === 'action_failed', 'no email address on file -> the action fails instead of silently doing nothing');
$svc->completeTask($id, 900);
$ok($runTasks($run2)['Mail it']['status'] === 'completed' && $runTasks($run2)['After mail']['status'] === 'pending' && (int) $one("SELECT COUNT(*) FROM workflow_task_log WHERE run_task_id = $id AND event = 'manual_complete'") === 1, 'a failed action can be completed by hand (logged)');
$flaky = new FakeGateway(); $flaky->mailFailuresLeft = 2;
$svcFlaky = new WorkflowService($mysqli, $flaky);
$run3 = $svcFlaky->startRun($t6, 901, 900);
$ok($runTasks($run3)['Mail it']['status'] === 'completed' && (int) $runTasks($run3)['Mail it']['attempts'] === 3 && $flaky->count('mail') === 1, 'a transient failure succeeds on a retry within the attempt budget');
// an action never runs twice on its own
$before = $gw->count('mail'); $svc->advance($run3);
$ok($runTasks($run3)['Mail it']['attempts'] == 3, 'advance() never re-runs a finished action');
// unknown action_type stored in a template -> fails, does not crash
$q("UPDATE workflow_template_tasks SET action_type = 'teleport' WHERE template_task_id = $f1");
$run4 = $svc->startRun($t6, 901, 900);
$ok($runTasks($run4)['Mail it']['status'] === 'action_failed' && (int) $runTasks($run4)['Mail it']['attempts'] === 1, 'an unknown action fails once (no pointless retries) and falls back to manual');

// ---------------------------------------------------------------- 8. dry run purity
$t7 = $tpl('Offboard', 'offboarding');
$o1 = $tts->add($t7, ['title' => 'Manager sign-off', 'task_type' => 'approval', 'approver_type' => 'manager', 'due_offset_days' => '-1', 'due_anchor' => 'end']);
$o2 = $tts->add($t7, ['title' => 'Disable login', 'task_type' => 'action', 'action_type' => 'disable_contact_login', 'depends_on' => [$o1]]);
$o3 = $tts->add($t7, ['title' => 'Mail HR', 'task_type' => 'action', 'action_type' => 'send_mail', 'depends_on' => [$o2], 'action_config' => ['to' => 'address', 'address' => 'hr@x.test', 'subject' => 'Leaver {{employee_name}}', 'body' => 'bye']]);
$tts->add($t7, ['title' => 'Collect laptop', 'assignee_user_id' => 901, 'default_owner' => 'IT']);
$tables = ['workflow_runs', 'workflow_run_tasks', 'workflow_task_log', 'email_queue', 'tickets', 'notifications', 'audit_events', 'integration_jobs', 'webhook_queue', 'users', 'contacts', 'automation_rules'];
$snap = function () use ($tables, $one) { $o = []; foreach ($tables as $t) { $o[$t] = $one("SELECT COUNT(*) FROM $t") . '/' . $one("SELECT COALESCE(MAX(UNIX_TIMESTAMP(NOW())),0)") ; } $o['arch'] = $one("SELECT COUNT(*) FROM users WHERE user_archived_at IS NOT NULL"); return $o; };
$before = $snap(); $gw->calls = [];
$pv = $svc->previewRun($t7, 901);
$ok($snap() === $before && $gw->calls === [], 'dry run writes no rows anywhere, and sends/queues/notifies/audits nothing');
$ok(array_column($pv['tasks'], 'title') === ['Manager sign-off', 'Collect laptop', 'Disable login', 'Mail HR'] || array_column($pv['tasks'], 'title') === ['Collect laptop', 'Manager sign-off', 'Disable login', 'Mail HR'], 'preview orders tasks by dependency wave');
$byTitle = array_column($pv['tasks'], null, 'title');
$ok($byTitle['Manager sign-off']['due_at'] === '2030-08-31 17:00:00' && $byTitle['Disable login']['starts'] === 'blocked' && $byTitle['Collect laptop']['starts'] === 'ready', 'preview resolves due dates and what starts blocked');
$ok(str_contains($byTitle['Disable login']['what'], 'nothing is deleted') && str_contains($byTitle['Mail HR']['what'], 'hr@x.test') && str_contains($byTitle['Mail HR']['what'], 'Leaver Erin O\'Hire') && str_contains($byTitle['Manager sign-off']['what'], 'Mia'), 'preview says what each action would do, with placeholders filled');
$ok($byTitle['Collect laptop']['assignee'] === 'Plain Bob', 'preview resolves the assignee');
$ok($throws(fn() => $svc->previewRun($t7, 424242), \InvalidArgumentException::class) !== null, 'preview of an unknown person is an error');

// ---------------------------------------------------------------- 9. disable_contact_login (live gateway code, real DB)
$live = new LiveActionGateway($mysqli);
$res = $live->disablePortalLogin(901);
$ok($res === 'portal login disabled (archived)' && $one("SELECT user_archived_at IS NOT NULL FROM users WHERE user_id = 903") == 1, 'disable_contact_login archives the portal login');
$ok($live->disablePortalLogin(901) === 'the portal login was already disabled', 'disabling twice is harmless');
$ok(str_contains($live->disablePortalLogin(902), 'nothing to disable'), 'a person with no login is not a failure');
$ok($throws(fn() => $live->disablePortalLogin(903), \RuntimeException::class) !== null && $one("SELECT user_archived_at IS NULL FROM users WHERE user_id = 901") == 1, 'an agent account linked to a contact is never touched');
$ok($one("SELECT COUNT(*) FROM users WHERE user_id = 903") == 1 && $one("SELECT COUNT(*) FROM contacts WHERE contact_id = 901") == 1, 'nothing is deleted');

// ---------------------------------------------------------------- 10. lifecycle detection (pure) and idempotent auto-start
$T = '2030-03-01';
$new = fn($status, $start, $end = null) => ['contact_employment_status' => $status, 'contact_start_date' => $start, 'contact_expected_end_date' => $end];
$ok(LifecycleEvents::detect(null, $new('active', '2030-03-10'), $T) === ['employee.hired'], 'new person starting within 30 days -> hired');
$ok(LifecycleEvents::detect(null, $new('active', '2020-01-01'), $T) === [], 'a long-standing employee imported now is not a hire');
$ok(LifecycleEvents::detect(null, $new('active', null), $T) === [], 'a new person with no start date is not a hire');
$ok(LifecycleEvents::detect($new('pre-hire', '2030-03-10'), $new('active', '2030-03-10'), $T) === ['employee.hired'], 'pre-hire -> active is a hire');
$ok(LifecycleEvents::detect($new('leave', null), $new('active', null), $T) === [], 'returning from leave is not a hire');
$ok(LifecycleEvents::detect($new('active', null), $new('terminated', null), $T) === ['employee.terminated'], 'status -> terminated is a departure');
$ok(LifecycleEvents::detect($new('active', null), $new('termination_pending', null, '2030-04-01'), $T) === ['employee.terminated'], 'status and end date together raise one event');
$ok(LifecycleEvents::detect($new('active', null), $new('active', null, '2030-04-01'), $T) === ['employee.terminated'], 'an end date set on an active person is a departure');
$ok(LifecycleEvents::detect($new('termination_pending', null), $new('terminated', null, '2030-04-01'), $T) === [], 'pending -> terminated does not repeat the event');
$ok(LifecycleEvents::detect($new('active', null), $new('active', null), $T) === [], 'no change, no event');

$q("UPDATE settings SET config_lifecycle_auto_start = 0");
$ok(LifecycleEvents::enabled($mysqli) === false, 'auto-start is off by default');
$gw->calls = [];
$ok(LifecycleEvents::afterChange($mysqli, 901, null, $gw) === [] && $gw->calls === [], 'with the setting off nothing is detected or emitted');
$q("UPDATE settings SET config_lifecycle_auto_start = 1");
$ev = LifecycleEvents::afterChange($mysqli, 901, null, $gw);
$ok($ev === [] , 'new person with a far-future start date is not a hire (window)');
$q("UPDATE contacts SET contact_start_date = CURDATE() + INTERVAL 5 DAY WHERE contact_id = 901");
$ev = LifecycleEvents::afterChange($mysqli, 901, null, $gw);
$ok($ev === ['employee.hired'] && $gw->count('audit') === 1, 'with the setting on a hire is detected and recorded as an audit event (which feeds the bus)');

$q("INSERT INTO workflow_templates SET name = 'Inactive', type = 'onboarding', is_active = 0"); $inactive = (int) mysqli_insert_id($mysqli);
$ok($throws(fn() => StartWorkflowRule::save($mysqli, null, 'r', 'employee.hired', [], ['template_id' => $inactive]), \InvalidArgumentException::class) !== null, 'a rule cannot start an inactive template');
$ok($throws(fn() => StartWorkflowRule::save($mysqli, null, 'r', 'employee.hired', [], ['template_id' => $t7]), \InvalidArgumentException::class) !== null, 'employee.hired cannot start an offboarding template');
$ok($throws(fn() => StartWorkflowRule::save($mysqli, null, '', 'employee.hired', [], ['template_id' => $tl]), \InvalidArgumentException::class) !== null, 'a rule needs a name');
$ruleId = StartWorkflowRule::save($mysqli, null, 'Onboard new hires', 'employee.hired', ['employment_status' => 'active'], ['template_id' => $tl], true);
$stored = mysqli_fetch_assoc($q("SELECT * FROM automation_rules WHERE rule_id = $ruleId"));
$ok($stored['action_type'] === 'start_workflow' && json_decode($stored['action_config_json'], true) === ['template_id' => $tl], 'a start_workflow rule is stored (the widened enum accepts it)');
StartWorkflowRule::save($mysqli, $ruleId, 'Renamed', 'employee.hired', [], ['template_id' => $tl], false);
$ok($one("SELECT name FROM automation_rules WHERE rule_id = $ruleId") === 'Renamed' && $one("SELECT is_enabled FROM automation_rules WHERE rule_id = $ruleId") == 0, 'a start_workflow rule can be edited');

$cfg = ['template_id' => $tl];
$ctx = ['event' => 'employee.hired', 'contact_id' => '901', 'entity_type' => 'contact', 'entity_id' => '901'];
$q("UPDATE settings SET config_lifecycle_auto_start = 0");
$ok(str_starts_with(StartWorkflowRule::run($mysqli, $cfg, $ctx, $gw), 'skipped'), 'the action does nothing while auto-start is off');
$q("UPDATE settings SET config_lifecycle_auto_start = 1");
$q("DELETE FROM workflow_runs WHERE contact_id = 901 AND workflow_template_id = $tl");
$m1 = StartWorkflowRule::run($mysqli, $cfg, $ctx, $gw);
$m2 = StartWorkflowRule::run($mysqli, $cfg, $ctx, $gw);
$ok(str_starts_with($m1, 'started') && str_starts_with($m2, 'skipped') && (int) $one("SELECT COUNT(*) FROM workflow_runs WHERE contact_id = 901 AND workflow_template_id = $tl AND status IN ('in_progress','paused')") === 1, 'start_workflow is idempotent: one open run per person and template');
$q("UPDATE workflow_runs SET status = 'paused' WHERE contact_id = 901 AND workflow_template_id = $tl");
$ok(str_starts_with(StartWorkflowRule::run($mysqli, $cfg, $ctx, $gw), 'skipped'), 'a paused run still counts as open');
$q("UPDATE workflow_runs SET status = 'completed' WHERE contact_id = 901 AND workflow_template_id = $tl");
$ok(str_starts_with(StartWorkflowRule::run($mysqli, $cfg, $ctx, $gw), 'started'), 'once the earlier run is finished a new event may start another');
$ok(str_starts_with(StartWorkflowRule::run($mysqli, $cfg, ['event' => 'ticket.created', 'entity_type' => 'ticket', 'entity_id' => '901'], $gw), 'skipped'), 'an event that is not about a contact never starts a workflow');
$ok($throws(fn() => StartWorkflowRule::run($mysqli, ['template_id' => $t7], $ctx, $gw), \RuntimeException::class) !== null, 'a template type that does not fit the event fails the rule run');
// two concurrent-looking starts: a second service instance sees the first
$a = (new WorkflowService($mysqli, $gw))->startRunIfNone($t2, 903, null);
$b = (new WorkflowService($mysqli, $gw))->startRunIfNone($t2, 903, null);
$ok($a > 0 && $b === null, 'startRunIfNone returns null when a run is open');

// ---------------------------------------------------------------- 11. reminders: once per task per state
$q("DELETE FROM workflow_task_log"); $q("DELETE FROM workflow_run_tasks"); $q("DELETE FROM workflow_runs");
$t8 = $tpl('Reminders');
$tts->add($t8, ['title' => 'Soon', 'due_offset_days' => '0', 'assignee_user_id' => 901]);
$tts->add($t8, ['title' => 'Later', 'due_offset_days' => '10']);
$tts->add($t8, ['title' => 'Nodue']);
$tts->add($t8, ['title' => 'Behind', 'due_offset_days' => '0', 'depends_on' => []]);
$run = $svc->startRun($t8, 901, 900);
$rt = $runTasks($run);
$q("UPDATE workflow_run_tasks SET due_at = NOW() + INTERVAL 5 HOUR WHERE title = 'Soon'");
$q("UPDATE workflow_run_tasks SET due_at = NOW() - INTERVAL 3 HOUR, assignee_user_id = NULL WHERE title = 'Behind'");
$q("UPDATE workflow_run_tasks SET due_at = NOW() + INTERVAL 10 DAY WHERE title = 'Later'");
$rem = new ReminderService($mysqli, $gw);
$gw->calls = [];
$r = $rem->run();
$notes = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'notify'));
$ok($r['due_soon'] === 1 && $r['overdue'] === 1 && count($notes) === 2, 'one due-soon and one overdue reminder, nothing for later or undated tasks');
$ok($notes[0][1] === 901 || $notes[1][1] === 901, 'the assignee is reminded');
$ok(count(array_filter($notes, fn($n) => $n[1] === 900)) === 1, 'an unassigned task reminds whoever started the run');
$gw->calls = [];
$r = $rem->run(); $r2 = $rem->run();
$ok($r['due_soon'] === 0 && $r['overdue'] === 0 && $r2['overdue'] === 0 && $gw->count('notify') === 0, 'running again repeats nothing (once per task per state)');
$q("UPDATE workflow_run_tasks SET due_at = NOW() - INTERVAL 1 HOUR WHERE title = 'Soon'");
$r = $rem->run();
$ok($r['overdue'] === 1 && $r['due_soon'] === 0, 'a task reminded as due soon is reminded once more when it goes overdue');
$r = $rem->run();
$ok($r['overdue'] === 0, 'and then never again');
$q("UPDATE workflow_run_tasks SET reminder_state = NULL, reminded_at = NULL");
$q("UPDATE workflow_run_tasks SET status = 'completed' WHERE title = 'Soon'");
$q("UPDATE workflow_runs SET status = 'paused'");
$gw->calls = [];
$r = $rem->run();
$ok($r['overdue'] === 0 && $r['due_soon'] === 0 && $gw->count('notify') === 0, 'completed tasks and paused runs are not chased');
$q("UPDATE workflow_runs SET status = 'in_progress'");
$q("UPDATE workflow_run_tasks SET status = 'running', running_since = NOW() - INTERVAL 1 HOUR WHERE title = 'Later'");
$r = $rem->run();
$ok($r['recovered'] === 1 && $one("SELECT status FROM workflow_run_tasks WHERE title = 'Later'") === 'action_failed', 'an action stuck in running is handed to a person');

// ---------------------------------------------------------------- 12. migration idempotence
$src = file_get_contents(__DIR__ . '/../admin/database_updates.php');
$start = strpos($src, "if (\$rivetit_db_version() == '2.6.133') {");
$ok($start !== false, 'migration 2.6.134 block exists, gated on 2.6.133');
$code = substr($src, $start);
$colsBefore = $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('workflow_template_tasks','workflow_run_tasks','workflow_runs','settings')");
$rivetit_db_version = fn() => '2.6.133';
$runMigration = function () use ($code, $mysqli, &$rivetit_db_version) { eval($code . ' return true;'); };
$runMigration(); $runMigration();
$colsAfter = $one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name IN ('workflow_template_tasks','workflow_run_tasks','workflow_runs','settings')");
$ok($colsBefore === $colsAfter && $one("SELECT config_current_database_version FROM settings WHERE company_id = 1") === '2.6.134', 'running the migration twice changes nothing and lands on 2.6.134');
preg_match('/LATEST_DATABASE_VERSION", "([0-9.]+)"/', (string) file_get_contents(__DIR__ . '/../includes/database_version.php'), $_lv);
$ok(isset($_lv[1]) && version_compare($_lv[1], '2.6.134', '>='), 'LATEST_DATABASE_VERSION is 2.6.134 or later');
$dbsql = file_get_contents(__DIR__ . '/../db.sql');
$ok(str_contains($dbsql, 'CREATE TABLE `workflow_task_log`') && str_contains($dbsql, '`approval_status` enum') && str_contains($dbsql, "'notify_user','start_workflow'"), 'db.sql carries the new tables and columns');

echo $fails === 0 ? "\nAll lifecycle workflow checks passed\n" : "\n$fails check(s) FAILED\n";
exit($fails === 0 ? 0 : 1);
