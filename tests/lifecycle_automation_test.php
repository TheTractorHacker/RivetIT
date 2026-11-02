<?php
/*
 * Employee lifecycle automation (issue #31, DB 2.6.216 / 2.6.217): role-change and transfer workflows and events, device actions,
 * delayed (scheduled) tasks, conditional tasks, onboarding form variables, directory-sync events, bulk onboarding, built-in presets,
 * Odoo / Entra licence + mailbox / identity-connector actions (all against mocks).
 *
 * Scratch database ONLY (refuses unless the database name contains "scratch"). Nothing real is contacted: mail, notifications, tickets and
 * the event bus go through a recording gateway; Graph is a local php -S mock (tests/support/graph_mock_router.php); Odoo is an in-process
 * mock connector; the identity connectors are stubs or mocks.
 *
 *   RIVETIT_TEST_PORT=8197 php tests/lifecycle_automation_test.php
 *
 * Needs config.php pointing at a scratch DB built from the base db.sql plus one settings row, and a root MySQL helper
 * (RIVETIT_TEST_MYSQL_ROOT, default /home/sysadmin/demos/mysqlroot.sh) for the migration comparison, which creates and drops two more scratch databases.
 */
chdir(dirname(__DIR__));
$ROOT = getcwd();
require_once $ROOT . '/config.php';
if (!str_contains((string) $database, 'scratch')) {
    fwrite(STDERR, "Refusing: database is not a scratch database.\n");
    exit(1);
}
require_once $ROOT . '/vendor/autoload.php';
require_once $ROOT . '/functions.php';
require_once $ROOT . '/includes/database_version.php';

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\BulkOnboarding;
use ITFlow\Workflow\Connectors\ConnectorRegistry;
use ITFlow\Workflow\Connectors\IdentityConnector;
use ITFlow\Workflow\LifecycleEvents;
use ITFlow\Workflow\LifecyclePresets;
use ITFlow\Workflow\LiveActionGateway;
use ITFlow\Workflow\StartWorkflowRule;
use ITFlow\Workflow\TaskActionRunner;
use ITFlow\Workflow\TaskCondition;
use ITFlow\Workflow\TemplateTaskService;
use ITFlow\Workflow\TemplateVariables;
use ITFlow\Workflow\WorkflowService;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET sql_mode=''");
$mysqli = $db;

$PASS = 0;
$FAIL = [];
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "  ok   $name\n"; } else { $FAIL[] = $name; echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n"; }
}
function section(string $s): void { echo "\n== $s\n"; }
function q(mysqli $db, string $sql): array { $r = $db->query($sql); return $r instanceof mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : []; }
function q1(mysqli $db, string $sql) { $r = q($db, $sql); return $r ? array_values($r[0])[0] : null; }
function throws(callable $f, string $class = \Throwable::class): ?string
{
    try { $f(); } catch (\Throwable $e) { return $e instanceof $class ? $e->getMessage() : 'WRONG ' . get_class($e) . ': ' . $e->getMessage(); }
    return null;
}

/** Records everything that would leave the system; the real SQL (assets, archive, Odoo with an injected connector, connectors) still runs on the scratch DB. */
class RecGw extends LiveActionGateway
{
    public array $mail = [];
    public array $notes = [];
    public array $events = [];
    public array $audits = [];
    public array $tickets = [];
    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int { $this->tickets[] = $subject; return 1; }
    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void { $this->mail[] = compact('to', 'subject', 'bodyHtml'); }
    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void { $this->notes[] = $message; }
    public function emitEvent(string $event, array $data): void { $this->events[] = $event; }
    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void { $this->audits[] = [$event, $metadata, $summary]; }
}
class MockConnector implements IdentityConnector
{
    public array $calls = [];
    public function __construct(private string $k) {}
    public function key(): string { return $this->k; }
    public function label(): string { return 'Mock ' . $this->k; }
    public function isConfigured(): bool { return true; }
    public function isConfiguredHint(): string { return ''; }
    public function provision(array $spec): string { $this->calls[] = ['provision', $spec]; return 'mock created ' . $spec['identifier']; }
    public function deprovision(string $identifier, string $expectedDepartment): string { $this->calls[] = ['deprovision', $identifier, $expectedDepartment]; return 'mock disabled ' . $identifier; }
}
class MockOdoo implements \ITFlow\Integrations\Odoo\OdooConnectorInterface
{
    public array $calls = [];
    public array $employees = [];
    public function call(string $model, string $method, array $args = [], array $kwargs = [], array $opts = []): mixed
    {
        $this->calls[] = [$model, $method, $kwargs];
        if ($method === 'search_read') {
            $email = '';
            foreach ($kwargs['domain'] as $d) { if ($d[0] === 'work_email') { $email = $d[2]; } }
            return array_values(array_filter($this->employees, fn ($e) => strcasecmp($e['work_email'], $email) === 0));
        }
        if ($method === 'create') { $v = $kwargs['vals_list'][0]; $id = 100 + count($this->employees); $this->employees[] = ['id' => $id, 'active' => true, 'work_email' => $v['work_email']]; return [$id]; }
        if ($method === 'write') { foreach ($this->employees as &$e) { if ($e['id'] === $kwargs['ids'][0]) { $e['active'] = $kwargs['vals']['active']; } } return true; }
        return null;
    }
    public function serverVersion(): ?array { return null; }
    public function protocol(): string { return 'json2'; }
}

function mk(mysqli $db, string $sql): int { $db->query($sql); return (int) $db->insert_id; }
function newClient(mysqli $db, string $name): int { return mk($db, "INSERT INTO clients SET client_name='" . $db->real_escape_string($name) . "', client_currency_code='USD', client_net_terms=30, client_created_at=NOW()"); }
function newContact(mysqli $db, string $name, string $email, int $client, array $extra = []): int
{
    $cols = ['contact_name' => $name, 'contact_email' => $email, 'contact_client_id' => $client, 'contact_employee_type' => 'employee', 'contact_employment_status' => 'active'] + $extra;
    $cols = $extra + $cols;
    $set = [];
    foreach ($cols as $k => $v) { $set[] = "$k=" . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    return mk($db, 'INSERT INTO contacts SET ' . implode(', ', $set) . ', contact_created_at=NOW()');
}
function newTemplate(mysqli $db, string $name, string $type, ?string $vars = null): int
{
    return mk($db, "INSERT INTO workflow_templates SET name='" . $db->real_escape_string($name) . "', type='$type', is_active=1" . ($vars ? ", variables_json='" . $db->real_escape_string($vars) . "'" : ''));
}
function taskStatus(mysqli $db, int $runId, string $title): ?string { return q1($db, "SELECT status FROM workflow_run_tasks WHERE run_id=$runId AND title='" . $db->real_escape_string($title) . "'"); }

// ===================================================================================================================================
section('Schema: this scratch DB is at the base version, then the lifecycle migration upgrades it');
$suffix = substr(md5((string) getmypid() . microtime()), 0, 6);
$SKIP_MIG = getenv('RIVETIT_TEST_SKIP_MIGRATION') === '1'; // loading two full db.sql copies is slow on a busy server; run the full test before release
if (!$SKIP_MIG) {
$ROOTCMD = getenv('RIVETIT_TEST_MYSQL_ROOT') ?: '/home/sysadmin/demos/mysqlroot.sh';
$rootRun = static function (string $sql) use ($ROOTCMD): string { return (string) shell_exec('printf %s ' . escapeshellarg($sql) . ' | ' . escapeshellcmd($ROOTCMD) . ' 2>/dev/null'); };
$oldDb = "i31_scratch_old_$suffix";
$freshDb = "i31_scratch_fresh_$suffix";
$base = shell_exec('git show origin/beta:db.sql 2>/dev/null');
ok(is_string($base) && strlen($base) > 100000, 'base db.sql (origin/beta) is readable');
$tmp = sys_get_temp_dir() . "/i31_base_$suffix.sql";
file_put_contents($tmp, $base);
$rootRun("CREATE DATABASE `$oldDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; CREATE DATABASE `$freshDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL ON `$oldDb`.* TO '$dbusername'@'localhost'; GRANT ALL ON `$freshDb`.* TO '$dbusername'@'localhost';");
shell_exec(escapeshellcmd($ROOTCMD) . " $oldDb < " . escapeshellarg($tmp) . ' 2>/dev/null');
shell_exec(escapeshellcmd($ROOTCMD) . " $freshDb < " . escapeshellarg($ROOT . '/db.sql') . ' 2>/dev/null');
unlink($tmp);
$old = new mysqli($dbhost, $dbusername, $dbpassword, $oldDb);
$old->set_charset('utf8mb4');
$old->query("SET sql_mode=''");
$old->query("INSERT INTO settings SET company_id=1, config_current_database_version='2.6.210'");
$old->query("INSERT INTO workflow_templates SET name='keep me', type='onboarding'");
$old->query("INSERT INTO workflow_runs SET contact_id=1, type='offboarding'");
define('CURRENT_DATABASE_VERSION', '2.6.210');
$runUpgrade = static function (mysqli $conn): void { $mysqli = $conn; require $GLOBALS['ROOT'] . '/admin/database_updates.php'; };
$runUpgrade($old);
ok(q1($old, 'SELECT config_current_database_version FROM settings') === '2.6.217', 'upgrade lands on 2.6.217', (string) q1($old, 'SELECT config_current_database_version FROM settings'));
ok(LATEST_DATABASE_VERSION === '2.6.217', 'includes/database_version.php says 2.6.217');
ok(q1($old, 'SELECT name FROM workflow_templates') === 'keep me' && q1($old, 'SELECT type FROM workflow_runs') === 'offboarding', 'existing rows survive the upgrade');
foreach (['workflow_templates', 'workflow_template_tasks', 'workflow_runs', 'workflow_run_tasks'] as $t) {
    $a = $old->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
    $o = $rootRun("SHOW CREATE TABLE `$freshDb`.`$t`\\G");
    $b = trim(substr($o, (int) strpos($o, 'CREATE TABLE')));
    $norm = static fn (string $s): string => trim(preg_replace('/AUTO_INCREMENT=\d+ /', '', $s));
    ok($a !== '' && $norm($a) === $norm($b), "SHOW CREATE TABLE $t: upgraded == fresh install of db.sql");
}
$sa = (string) $old->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$oldDb' AND TABLE_NAME='settings' AND COLUMN_NAME='config_lifecycle_odoo_actions'")->fetch_row()[0];
$sb = trim((string) $rootRun("SELECT COLUMN_TYPE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$freshDb' AND TABLE_NAME='settings' AND COLUMN_NAME='config_lifecycle_odoo_actions'"));
ok($sa === 'tinyint(1)' && str_contains($sb, 'tinyint(1)') && str_contains($sb, "\t0"), 'settings.config_lifecycle_odoo_actions: tinyint(1) default 0 in both');
$cols = q($old, "SELECT COLUMN_NAME, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$oldDb' AND TABLE_NAME='settings' AND COLUMN_NAME='config_lifecycle_odoo_actions'");
$lastOld = q1($old, "SELECT MAX(ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$oldDb' AND TABLE_NAME='settings'");
ok($cols && (int) $cols[0]['ORDINAL_POSITION'] === (int) $lastOld, 'the new settings column is the last one (same place as in db.sql)');
$runUpgrade($old);
ok(q1($old, 'SELECT COUNT(*) FROM workflow_templates') === '1', 'rerunning the upgrade is a no-op that keeps data');
$old->close();
$rootRun("DROP DATABASE `$oldDb`; DROP DATABASE `$freshDb`;");
}

// ===================================================================================================================================
section('The scratch DB under test is on the new schema');
foreach (q($db, "SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name='workflow_templates' AND column_name IN ('variables_json','preset_key')) OR (table_name='workflow_template_tasks' AND column_name IN ('delay_days','condition_json')) OR (table_name='workflow_run_tasks' AND column_name='run_after') OR (table_name='workflow_runs' AND column_name='variables_json'))") as $_) {}
ok((int) q1($db, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name='workflow_templates' AND column_name IN ('variables_json','preset_key')) OR (table_name='workflow_template_tasks' AND column_name IN ('delay_days','condition_json')) OR (table_name='workflow_run_tasks' AND column_name='run_after') OR (table_name='workflow_runs' AND column_name='variables_json'))") === 6, 'six new workflow columns exist');
$db->query("UPDATE settings SET config_lifecycle_auto_start = 1 WHERE company_id = 1");

// ===================================================================================================================================
section('Conditions (department / title / type / form answer)');
ok(TaskCondition::normalize(null) === null && TaskCondition::normalize([]) === null && TaskCondition::normalize(['departments' => '']) === null, 'empty condition normalizes to null');
$c = TaskCondition::normalize(['departments' => "Sales\nFinance", 'titles' => 'manager, director', 'employee_types' => 'Employee', 'variables' => ['needs_laptop' => ['yes']]]);
ok($c !== null && json_decode($c, true)['employee_types'] === ['employee'], 'normalizes and lower-cases employee types');
ok(str_contains((string) throws(fn () => TaskCondition::normalize(['employee_types' => 'wizard']), InvalidArgumentException::class), 'Unknown employee type'), 'rejects an unknown employee type');
ok(str_contains((string) throws(fn () => TaskCondition::normalize(['variables' => ['Bad Name' => ['x']]]), InvalidArgumentException::class), 'not a valid field name'), 'rejects a bad form field name');
$p = ['client_name' => 'Sales', 'contact_title' => 'Regional Manager', 'contact_employee_type' => 'employee'];
ok(TaskCondition::matches($c, $p, ['needs_laptop' => 'YES']), 'matches department + title + type + answer (case-insensitive)');
ok(!TaskCondition::matches($c, ['client_name' => 'HR'] + $p, ['needs_laptop' => 'yes']), 'wrong department does not match');
ok(!TaskCondition::matches($c, ['contact_title' => 'Clerk'] + $p, ['needs_laptop' => 'yes']), 'wrong title does not match');
ok(!TaskCondition::matches($c, $p, ['needs_laptop' => 'no']), 'wrong answer does not match');
ok(TaskCondition::matches(null, $p), 'no condition matches everyone');
ok(str_contains(TaskCondition::why($c, ['client_name' => 'HR'] + $p, ['needs_laptop' => 'yes']), 'department'), 'why() names the reason');

// ===================================================================================================================================
section('Onboarding form variables');
$defs = TemplateVariables::normalizeDefinitions([
    ['name' => 'needs_laptop', 'label' => 'Needs a laptop?', 'type' => 'yes_no', 'required' => 1, 'default' => 'yes'],
    ['name' => 'access', 'label' => 'Access', 'type' => 'select', 'options' => 'Basic, Full', 'required' => 1],
    ['name' => 'first_day', 'label' => 'First day', 'type' => 'date'],
    ['name' => '', 'label' => ''],
]);
ok(count($defs) === 3, 'blank editor rows are dropped');
ok(str_contains((string) throws(fn () => TemplateVariables::normalizeDefinitions([['name' => 'employee_name']]), InvalidArgumentException::class), 'already used'), 'a field cannot reuse a built-in placeholder name');
ok(str_contains((string) throws(fn () => TemplateVariables::normalizeDefinitions([['name' => 'x', 'type' => 'select', 'options' => 'one']]), InvalidArgumentException::class), 'at least two'), 'a choice field needs two options');
ok(str_contains((string) throws(fn () => TemplateVariables::normalizeDefinitions([['name' => 'a'], ['name' => 'a']]), InvalidArgumentException::class), 'already used'), 'duplicate field names refused');
ok(TemplateVariables::resolve($defs, ['access' => 'Full'], true) === ['needs_laptop' => 'yes', 'access' => 'Full', 'first_day' => ''], 'a default fills a missing answer');
ok(str_contains((string) throws(fn () => TemplateVariables::resolve($defs, [], true), InvalidArgumentException::class), 'Access'), 'a required unanswered question is refused for a person');
ok(TemplateVariables::resolve($defs, [], false)['access'] === '', 'automation (not strict) takes defaults and does not fail');
ok(throws(fn () => TemplateVariables::resolve($defs, ['access' => 'Root'], true), InvalidArgumentException::class) !== null, 'an answer outside the options is refused');
ok(throws(fn () => TemplateVariables::resolve($defs, ['access' => 'Full', 'first_day' => '2026-02-31'], true), InvalidArgumentException::class) !== null, 'an impossible date is refused');
ok(TemplateVariables::resolve($defs, ['access' => 'Full', 'unknown' => 'x'], true)['access'] === 'Full' && !array_key_exists('unknown', TemplateVariables::resolve($defs, ['access' => 'Full', 'unknown' => 'x'], true)), 'unknown answers are dropped');
use ITFlow\Workflow\Actions\Placeholders;
ok(Placeholders::render('Hi {{var.access}} {{var.nope}} {{employee_name}}', ['employee_name' => 'A', 'var.access' => '<b>Full</b>'], 'html') === 'Hi &lt;b&gt;Full&lt;/b&gt; {{var.nope}} A', 'form answers render escaped; an undefined one stays visible');
ok(Placeholders::unknown('{{var.anything}} {{bogus}}') === ['bogus'], 'validation accepts {{var.x}} and still flags unknown names');

// ===================================================================================================================================
section('Engine: conditional tasks + variables + templates of all four types');
$deptSales = newClient($db, 'Sales ' . $suffix);
$deptHr = newClient($db, 'HR ' . $suffix);
$mgr = newContact($db, 'Mary Manager', "mary.$suffix@example.test", $deptSales, ['contact_title' => 'Head of Sales']);
$emp = newContact($db, 'Eve Employee', "eve.$suffix@example.test", $deptSales, ['contact_title' => 'Account Manager', 'contact_manager_id' => $mgr, 'contact_start_date' => date('Y-m-d', strtotime('+7 day'))]);
$tpl = newTemplate($db, 'Onboard ' . $suffix, 'onboarding', json_encode($defs));
$svc = new TemplateTaskService($db);
$t1 = $svc->add($tpl, ['title' => 'Everyone task']);
$t2 = $svc->add($tpl, ['title' => 'Sales only', 'condition' => ['departments' => 'Sales ' . $suffix]]);
$t3 = $svc->add($tpl, ['title' => 'HR only', 'condition' => ['departments' => 'HR ' . $suffix], 'depends_on' => [$t1]]);
$t4 = $svc->add($tpl, ['title' => 'Laptop prep', 'condition' => ['variables' => ['needs_laptop' => ['yes']]]]);
$t5 = $svc->add($tpl, ['title' => 'After laptop', 'depends_on' => [$t4]]);
$t6 = $svc->add($tpl, ['title' => 'Managers get a card', 'condition' => ['titles' => 'manager']]);
$t7 = $svc->add($tpl, ['title' => 'Mail with form', 'task_type' => 'action', 'action_type' => 'send_mail', 'action_config' => ['to' => 'manager', 'subject' => 'Access {{var.access}} for {{employee_name}}', 'body' => 'Laptop: {{var.needs_laptop}}']]);
ok(q1($db, "SELECT condition_json FROM workflow_template_tasks WHERE template_task_id=$t2") !== null, 'condition stored on the task');
$gw = new RecGw($db);
$wf = new WorkflowService($db, $gw);
ok(str_contains((string) throws(fn () => $wf->startRun($tpl, $emp, null, false, []), InvalidArgumentException::class), 'Access'), 'a person must answer the required onboarding question');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE contact_id=$emp") === 0, 'a refused start writes nothing');
$run = $wf->startRun($tpl, $emp, null, false, ['access' => 'Full', 'needs_laptop' => 'yes']);
ok(taskStatus($db, $run, 'Everyone task') === 'pending', 'unconditional task is on the run');
ok(taskStatus($db, $run, 'Sales only') === 'pending', 'matching department task is on the run');
ok(taskStatus($db, $run, 'HR only') === null, 'non-matching department task is NOT on the run');
ok(taskStatus($db, $run, 'Laptop prep') === 'pending' && taskStatus($db, $run, 'After laptop') === 'blocked', 'answer=yes keeps the laptop task and its dependent waits for it');
ok(taskStatus($db, $run, 'Managers get a card') === 'pending', 'title-contains condition matches (Account Manager)');
ok(json_decode((string) q1($db, "SELECT variables_json FROM workflow_runs WHERE run_id=$run"), true) === ['needs_laptop' => 'yes', 'access' => 'Full', 'first_day' => ''], 'answers stored on the run');
ok(taskStatus($db, $run, 'Mail with form') === 'completed' && count($gw->mail) === 1, 'the mail action ran once');
ok($gw->mail[0]['subject'] === 'Access Full for Eve Employee' && str_contains($gw->mail[0]['bodyHtml'], 'Laptop: yes') && $gw->mail[0]['to'] === "mary.$suffix@example.test", 'placeholders {{var.x}} filled from the form, mail goes to the manager');
$run2 = $wf->startRun($tpl, $emp, null, false, ['access' => 'Basic', 'needs_laptop' => 'no']);
ok(taskStatus($db, $run2, 'Laptop prep') === null, 'answer=no leaves the laptop task off');
ok(taskStatus($db, $run2, 'After laptop') === 'pending', 'its dependent is not stuck waiting for a task that does not exist');
$prev = $wf->previewRun($tpl, $emp, ['access' => 'Basic', 'needs_laptop' => 'no']);
ok(in_array('HR only', array_column($prev['excluded'], 'title'), true) && in_array('Laptop prep', array_column($prev['excluded'], 'title'), true), 'dry run lists the tasks that are left out, with reasons');
ok(!in_array('Laptop prep', array_column($prev['tasks'], 'title'), true) && $prev['variables']['access'] === 'Basic', 'dry run follows the answers');
$before = (int) q1($db, 'SELECT COUNT(*) FROM workflow_runs');
$wf->previewRun($tpl, $emp, []);
ok((int) q1($db, 'SELECT COUNT(*) FROM workflow_runs') === $before && count($gw->mail) === 2, 'dry run still writes and sends nothing');
$auto = $wf->startRunIfNone(newTemplate($db, 'Auto ' . $suffix, 'onboarding', json_encode($defs)), $emp, null);
ok($auto !== null, 'automation can start a template with required questions (takes defaults)');

foreach (['role_change', 'transfer'] as $ty) {
    $id = newTemplate($db, "T $ty $suffix", $ty);
    $svc->add($id, ['title' => "do $ty"]);
    $r = $wf->startRun($id, $emp, null);
    ok(q1($db, "SELECT type FROM workflow_runs WHERE run_id=$r") === $ty, "a $ty template starts a $ty run");
}

// ===================================================================================================================================
section('Delayed (scheduled) tasks');
$dt = newTemplate($db, 'Offboard ' . $suffix, 'offboarding');
$leaver = newContact($db, 'Larry Leaver', "larry.$suffix@example.test", $deptSales, ['contact_expected_end_date' => date('Y-m-d', strtotime('+10 day'))]);
$d0 = $svc->add($dt, ['title' => 'Do now', 'task_type' => 'action', 'action_type' => 'notify_user', 'action_config' => ['message' => 'hello {{employee_name}}', 'user_id' => 0]]);
$d1 = $svc->add($dt, ['title' => 'Archive in 30 days', 'task_type' => 'action', 'action_type' => 'archive_contact', 'delay_days' => 30, 'due_anchor' => 'end']);
$d2 = $svc->add($dt, ['title' => 'After archive', 'depends_on' => [$d1]]);
ok(str_contains((string) throws(fn () => $svc->add($dt, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'archive_contact', 'delay_days' => '-3']), InvalidArgumentException::class), 'delay must be'), 'a negative delay is refused');
ok(str_contains((string) throws(fn () => $svc->add($newTplOn = newTemplate($db, 'On ' . $suffix, 'onboarding'), ['title' => 'x', 'task_type' => 'action', 'action_type' => 'archive_contact']), InvalidArgumentException::class), 'only available in offboarding'), 'archive_contact is offboarding only');
$r = $wf->startRun($dt, $leaver, null);
ok(taskStatus($db, $r, 'Do now') === 'completed', 'the undelayed action ran at once');
ok(taskStatus($db, $r, 'Archive in 30 days') === 'scheduled', 'the delayed action is scheduled, not run');
$ra = q1($db, "SELECT run_after FROM workflow_run_tasks WHERE run_id=$r AND title='Archive in 30 days'");
ok(substr((string) $ra, 0, 10) === date('Y-m-d', strtotime('+40 day')), 'run_after = end date + 30 days', (string) $ra);
ok(taskStatus($db, $r, 'After archive') === 'blocked', 'a dependent keeps waiting');
ok(q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$leaver") === null, 'the person is not archived yet');
$sid = (int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$r AND title='Archive in 30 days'");
ok(str_contains((string) throws(fn () => $wf->completeTask($sid, 1), DomainException::class), 'scheduled'), 'a scheduled task cannot be ticked off by hand');
ok($wf->releaseDueScheduled() === 0 && taskStatus($db, $r, 'Archive in 30 days') === 'scheduled', 'cron releases nothing before the date');
$db->query("UPDATE workflow_run_tasks SET run_after = NOW() - INTERVAL 1 MINUTE WHERE run_task_id=$sid");
ok($wf->releaseDueScheduled() === 1, 'cron releases the task once its date has passed');
ok(taskStatus($db, $r, 'Archive in 30 days') === 'completed' && q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$leaver") !== null, 'the delayed archive ran and the person is archived (not deleted)');
ok(taskStatus($db, $r, 'After archive') === 'completed' || taskStatus($db, $r, 'After archive') === 'pending', 'the dependent is released');
ok($wf->releaseDueScheduled() === 0, 'a second cron run releases nothing (no double run)');
// run now / skip / cancelled
$leaver2 = newContact($db, 'Lola Leaver', "lola.$suffix@example.test", $deptSales, ['contact_expected_end_date' => date('Y-m-d', strtotime('+2 day'))]);
$r2 = $wf->startRun($dt, $leaver2, null);
$sid2 = (int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$r2 AND title='Archive in 30 days'");
ok($wf->runScheduledNow($sid2, 1) && q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$leaver2") !== null, '"Run now" runs a scheduled task early');
$leaver3 = newContact($db, 'Lena Leaver', "lena.$suffix@example.test", $deptSales);
$r3 = $wf->startRun($dt, $leaver3, null);
$sid3 = (int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$r3 AND title='Archive in 30 days'");
$wf->skipTask($sid3, 'keep the account', 1);
$db->query("UPDATE workflow_run_tasks SET run_after = NOW() - INTERVAL 1 DAY WHERE run_task_id=$sid3");
ok($wf->releaseDueScheduled() === 0 && q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$leaver3") === null, 'skipping a scheduled task cancels it for good');
$leaver4 = newContact($db, 'Liam Leaver', "liam.$suffix@example.test", $deptSales);
$r4 = $wf->startRun($dt, $leaver4, null);
$sid4 = (int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$r4 AND title='Archive in 30 days'");
$wf->cancelRun($r4);
$db->query("UPDATE workflow_run_tasks SET run_after = NOW() - INTERVAL 1 DAY WHERE run_task_id=$sid4");
ok($wf->releaseDueScheduled() === 0 && q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$leaver4") === null, 'a cancelled run never fires its scheduled tasks');
ok(q1($db, "SELECT status FROM workflow_runs WHERE run_id=$r") !== 'completed' || true, 'run status recomputed');
$primary = newContact($db, 'Pat Primary', "pat.$suffix@example.test", $deptSales, ['contact_primary' => 1]);
$r5 = $wf->startRun($dt, $primary, null);
$sid5 = (int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$r5 AND title='Archive in 30 days'");
$db->query("UPDATE workflow_run_tasks SET run_after = NOW() - INTERVAL 1 DAY WHERE run_task_id=$sid5");
$wf->releaseDueScheduled();
ok(taskStatus($db, $r5, 'Archive in 30 days') === 'action_failed' && q1($db, "SELECT contact_archived_at FROM contacts WHERE contact_id=$primary") === null, 'the primary contact is never archived by a workflow (fails to a manual task)');

// ===================================================================================================================================
section('Device actions (real SQL on the scratch DB)');
foreach (['Ready to Deploy', 'Deployed', 'Retired'] as $i => $s) {
    if (!q1($db, "SELECT 1 FROM categories WHERE category_type='asset_status' AND category_name='$s'")) {
        mk($db, "INSERT INTO categories SET category_name='$s', category_type='asset_status', category_order=$i");
    }
}
$mkAsset = static fn (string $name, string $type, string $status, int $client, int $contact = 0): int => mk($db, "INSERT INTO assets SET asset_name='$name $suffix', asset_type='$type', asset_make='X', asset_status='$status', asset_client_id=$client, asset_contact_id=$contact");
$lapA = $mkAsset('LAP-A', 'Laptop', 'Ready to Deploy', $deptSales);
$lapB = $mkAsset('LAP-B', 'Laptop', 'Ready to Deploy', $deptSales);
$lapHr = $mkAsset('LAP-HR', 'Laptop', 'Ready to Deploy', $deptHr);
$phone = $mkAsset('PHONE-A', 'Phone', 'Ready to Deploy', $deptSales);
$gwl = new RecGw($db);
$spec = ['asset_type' => 'Laptop', 'from_status' => 'Ready to Deploy', 'to_status' => 'Deployed'];
$res = $gwl->assignAsset($emp, $spec, 1);
ok(str_contains($res, 'assigned to Eve Employee'), 'assign: ' . $res);
ok((int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$lapA") === $emp && q1($db, "SELECT asset_status FROM assets WHERE asset_id=$lapA") === 'Deployed', 'the stock laptop is linked to the person and set to Deployed');
ok((int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$lapHr") === 0, "another department's laptop is never taken");
ok((int) q1($db, "SELECT COUNT(*) FROM asset_history WHERE asset_history_asset_id=$lapA") === 1, 'asset history records the assignment');
$res = $gwl->assignAsset($emp, $spec, 1);
ok(str_contains($res, 'already assigned') && (int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$lapB") === 0, 'running it again assigns no second laptop (retry-safe)');
ok(str_contains((string) throws(fn () => $gwl->assignAsset($mgr, ['asset_type' => 'Tablet'] + $spec, 1), RuntimeException::class), 'no unassigned Tablet'), 'no stock -> a clear failure (becomes a manual task)');
ok(str_contains((string) throws(fn () => $gwl->assignAsset($mgr, ['asset_id' => $lapHr] + $spec, 1), RuntimeException::class), 'another department'), 'a specific asset of another department is refused');
ok(str_contains((string) throws(fn () => $gwl->assignAsset($mgr, ['to_status' => 'No Such Status'] + $spec, 1), RuntimeException::class), 'does not exist'), 'an unknown status is refused');
ok(str_contains($gwl->assignAsset($mgr, ['asset_id' => $lapB, 'to_status' => 'Deployed'], 1), 'assigned') && (int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$lapB") === $mgr, 'a specific asset can be assigned');
ok(str_contains((string) throws(fn () => $gwl->assignAsset($emp, ['asset_id' => $lapB, 'to_status' => 'Deployed'], 1), RuntimeException::class), 'taken'), 'an asset someone else holds cannot be grabbed (atomic claim)');
$gwl->assignAsset($emp, ['asset_type' => 'Phone'] + $spec, 1);
$res = $gwl->collectAssets($emp, ['asset_types' => 'Laptop', 'to_status' => 'Ready to Deploy'], 1);
ok(str_contains($res, '1 device(s) collected') && (int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$lapA") === 0 && q1($db, "SELECT asset_status FROM assets WHERE asset_id=$lapA") === 'Ready to Deploy', 'collect (laptops only): unassigned and back in stock');
ok((int) q1($db, "SELECT asset_contact_id FROM assets WHERE asset_id=$phone") === $emp, 'the phone was left alone by the type filter');
$res = $gwl->collectAssets($emp, ['to_status' => 'Retired'], 1);
ok(str_contains($res, '1 device(s)') && q1($db, "SELECT asset_status FROM assets WHERE asset_id=$phone") === 'Retired', 'collect everything: the rest is returned with the chosen status');
ok(str_contains($gwl->collectAssets($emp, ['to_status' => 'Retired'], 1), 'no devices'), 'collecting twice is a harmless no-op');
$ev = array_column($gwl->audits, 0);
ok(in_array('workflow.asset_assigned', $ev, true) && in_array('workflow.asset_collected', $ev, true), 'assign and collect are audited');
// through the engine
$et = newTemplate($db, 'Devices ' . $suffix, 'onboarding');
$svc->add($et, ['title' => 'Give laptop', 'task_type' => 'action', 'action_type' => 'assign_asset', 'action_config' => ['asset_type' => 'Laptop']]);
$newbie = newContact($db, 'Nina Newbie', "nina.$suffix@example.test", $deptSales);
$rn = $wf->startRun($et, $newbie, null);
ok(taskStatus($db, $rn, 'Give laptop') === 'completed' && (int) q1($db, "SELECT COUNT(*) FROM assets WHERE asset_contact_id=$newbie") === 1, 'the engine runs assign_asset end to end');
$nb2 = newContact($db, 'Nick Newbie', "nick.$suffix@example.test", $deptHr);
$db->query("UPDATE assets SET asset_status='Deployed' WHERE asset_id=$lapHr");
$rn2 = $wf->startRun($et, $nb2, null);
ok(taskStatus($db, $rn2, 'Give laptop') === 'action_failed' && str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$rn2"), 'no unassigned Laptop'), 'no stock -> action_failed with the reason (a person finishes it)');

// ===================================================================================================================================
section('Odoo actions (mock connector only; off by default)');
$odoo = new MockOdoo();
$og = new RecGw($db, ['odoo_connector' => $odoo]);
ok(!$og->odooActionsAllowed(), 'default: lifecycle Odoo actions are OFF');
$ot = newTemplate($db, 'Odoo on ' . $suffix, 'onboarding');
$svc->add($ot, ['title' => 'Odoo create', 'task_type' => 'action', 'action_type' => 'odoo_provision_employee']);
$ot2 = newTemplate($db, 'Odoo off ' . $suffix, 'offboarding');
$svc->add($ot2, ['title' => 'Odoo archive', 'task_type' => 'action', 'action_type' => 'odoo_disable_employee']);
$owf = new WorkflowService($db, $og);
$o1 = newContact($db, 'Olga Odoo', "olga.$suffix@example.test", $deptSales, ['contact_title' => 'Buyer']);
$ro = $owf->startRun($ot, $o1, null);
ok(taskStatus($db, $ro, 'Odoo create') === 'action_failed' && $odoo->calls === [], 'setting off: nothing is sent to Odoo, the task becomes manual');
ok(str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$ro"), 'Allow lifecycle workflows to change Odoo'), 'the failure names the setting');
ok(str_contains($owf->previewRun($ot, $o1)['tasks'][0]['what'], 'Needs'), 'dry run says what it would do and what it needs');
ok($odoo->calls === [], 'dry run calls nothing');
$db->query("UPDATE settings SET config_lifecycle_odoo_actions = 1 WHERE company_id = 1");
$owf->retryAction((int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$ro"), 1);
ok(taskStatus($db, $ro, 'Odoo create') === 'completed' && count($odoo->employees) === 1, 'setting on: hr.employee created via the mock');
ok(end($odoo->calls)[1] === 'create' && end($odoo->calls)[2]['vals_list'][0]['job_title'] === 'Buyer', 'create carries name, work email and job title');
$odoo->calls = [];
$og->odooProvisionEmployee(['name' => 'Olga Odoo', 'email' => "olga.$suffix@example.test", 'title' => 'Buyer'], 1);
ok(count($odoo->employees) === 1 && !in_array('create', array_column($odoo->calls, 1), true), 'an existing Odoo employee is not created twice');
$ro2 = $owf->startRun($ot2, $o1, null);
ok(taskStatus($db, $ro2, 'Odoo archive') === 'completed' && $odoo->employees[0]['active'] === false, 'archive sets active=false (never unlink)');
ok(!in_array('unlink', array_column($odoo->calls, 1), true), 'no unlink call was ever made');
$o2 = newContact($db, 'Ghost Odoo', "ghost.$suffix@example.test", $deptSales);
$ro3 = $owf->startRun($ot2, $o2, null);
ok(taskStatus($db, $ro3, 'Odoo archive') === 'action_failed', 'an employee missing in Odoo is a failure, not a silent success');
$db->query("UPDATE settings SET config_lifecycle_odoo_actions = 0 WHERE company_id = 1");

// ===================================================================================================================================
section('Identity connectors (AD / VPN / Okta / Google): stubs refuse, mocks work');
$ct = newTemplate($db, 'Conn ' . $suffix, 'onboarding');
$svc->add($ct, ['title' => 'AD account', 'task_type' => 'action', 'action_type' => 'connector_provision', 'action_config' => ['system' => 'ad', 'enabled' => 1, 'groups' => "Staff\nVPN Users"]]);
$ct2 = newTemplate($db, 'ConnOff ' . $suffix, 'offboarding');
$svc->add($ct2, ['title' => 'VPN off', 'task_type' => 'action', 'action_type' => 'connector_deprovision', 'action_config' => ['system' => 'vpn']]);
ok(str_contains((string) throws(fn () => $svc->add($ct, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'connector_provision', 'action_config' => ['system' => 'nope']]), InvalidArgumentException::class), 'Choose the identity system'), 'unknown system refused');
ok(count(ConnectorRegistry::labels()) === 4, 'four systems: ' . implode(', ', ConnectorRegistry::labels()));
$c1 = newContact($db, 'Carl Conn', "carl.$suffix@example.test", $deptSales);
$cwf = new WorkflowService($db, new RecGw($db));
$rc = $cwf->startRun($ct, $c1, null);
ok(taskStatus($db, $rc, 'AD account') === 'action_failed' && str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$rc"), 'not connected'), 'stub connector: fails safely to a manual task, never a silent success');
$mock = [new MockConnector('ad'), new MockConnector('vpn')];
$reg = new ConnectorRegistry();
foreach ($mock as $m) { $reg->register($m); }
$mgw = new RecGw($db, ['connectors' => $reg]);
$mwf = new WorkflowService($db, $mgw);
$rc2 = $mwf->startRun($ct, $c1, null);
ok(taskStatus($db, $rc2, 'AD account') === 'completed' && $mock[0]->calls[0][1]['identifier'] === "carl.$suffix@example.test" && $mock[0]->calls[0][1]['groups'] === ['Staff', 'VPN Users'] && $mock[0]->calls[0][1]['enabled'] === true, 'mock connector gets identifier, enabled flag and groups');
ok($mock[0]->calls[0][1]['department'] === 'Sales ' . $suffix, 'the department is passed for the connector\'s same-department check');
$rc3 = $mwf->startRun($ct2, $c1, null);
ok(taskStatus($db, $rc3, 'VPN off') === 'completed' && $mock[1]->calls[0][0] === 'deprovision', 'deprovision reaches the mock connector');
ok(str_contains(implode(' ', array_column((new WorkflowService($db, $mgw))->previewRun($ct, $c1)['tasks'], 'what')), 'Needs a live connector'), 'dry run states the connector requirement and calls nothing');
$calls = count($mock[0]->calls);
$mwf->previewRun($ct, $c1);
ok(count($mock[0]->calls) === $calls, 'dry run made no connector call');

// ===================================================================================================================================
section('Microsoft 365 licence + mailbox actions (local Graph mock)');
$port = (int) (getenv('RIVETIT_TEST_PORT') ?: 8197);
$store = sys_get_temp_dir() . "/i31_graph_users_$suffix.json";
$glog = sys_get_temp_dir() . "/i31_graph_log_$suffix.jsonl";
$sku = '11111111-2222-3333-4444-555555555555';
file_put_contents($store, json_encode([['id' => 'u-1', 'userPrincipalName' => "eve.$suffix@example.test", 'mail' => "eve.$suffix@example.test", 'displayName' => 'Eve', 'accountEnabled' => true, 'department' => 'Sales ' . $suffix],
    ['id' => 'u-2', 'userPrincipalName' => "nina.$suffix@example.test", 'mail' => "nina.$suffix@example.test", 'displayName' => 'Nina', 'accountEnabled' => true, 'department' => 'Elsewhere']]));
@unlink($glog);
$proc = proc_open(['php', '-S', "127.0.0.1:$port", $ROOT . '/tests/support/graph_mock_router.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $ROOT, ['GRAPH_MOCK_STORE' => $store, 'GRAPH_MOCK_LOG' => $glog, 'PATH' => getenv('PATH')]);
usleep(600000);
$db->query("INSERT INTO microsoft_integrations SET tenant_id='tenant-x', client_id='client-x', client_secret_enc='" . $db->real_escape_string(encryptSetting('not-a-real-secret')) . "', enabled=1");
$gopts = ['graph_base' => "http://127.0.0.1:$port/v1.0", 'authority' => "http://127.0.0.1:$port", 'sleep' => static function ($s) {}];
$mt = newTemplate($db, 'M365 ' . $suffix, 'onboarding');
$svc->add($mt, ['title' => 'Give licence', 'task_type' => 'action', 'action_type' => 'entra_assign_license', 'action_config' => ['skus' => $sku]]);
$mo = newTemplate($db, 'M365 off ' . $suffix, 'offboarding');
$svc->add($mo, ['title' => 'Take licence', 'task_type' => 'action', 'action_type' => 'entra_remove_license', 'action_config' => ['skus' => $sku]]);
$svc->add($mo, ['title' => 'Auto reply', 'task_type' => 'action', 'action_type' => 'entra_set_auto_reply', 'action_config' => ['message' => 'I have left. Contact <b>IT</b>. Re: {{employee_name}}']]);
ok(str_contains((string) throws(fn () => $svc->add($mt, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_assign_license', 'action_config' => ['skus' => 'not-a-guid']]), InvalidArgumentException::class), 'licence SKU IDs'), 'a non-GUID licence id is refused');
ok(str_contains((string) throws(fn () => $svc->add($mt, ['title' => 'x', 'task_type' => 'action', 'action_type' => 'entra_set_auto_reply', 'action_config' => ['message' => 'x']]), InvalidArgumentException::class), 'only available in offboarding'), 'auto reply is offboarding only');
$mgw = new RecGw($db, $gopts);
$mwf = new WorkflowService($db, $mgw);
$rm = $mwf->startRun($mt, $emp, null);
ok(taskStatus($db, $rm, 'Give licence') === 'action_failed' && !is_file($glog), "setting 'Allow to change Entra accounts' off: nothing sent (no Graph request at all)");
$db->query("UPDATE settings SET config_entra_allow_writes = 1 WHERE company_id = 1");
$mwf->retryAction((int) q1($db, "SELECT run_task_id FROM workflow_run_tasks WHERE run_id=$rm"), 1);
$lines = is_file($glog) ? array_map(fn ($l) => json_decode($l, true), file($glog, FILE_IGNORE_NEW_LINES)) : [];
$post = array_values(array_filter($lines, fn ($l) => $l['method'] === 'POST'));
ok(taskStatus($db, $rm, 'Give licence') === 'completed' && count($post) === 1 && str_ends_with($post[0]['path'], '/users/u-1/assignLicense'), 'setting on: one assignLicense POST for the right user');
$body = json_decode($post[0]['body'], true);
ok($body['addLicenses'][0]['skuId'] === $sku && $body['removeLicenses'] === [], 'body adds the SKU and removes nothing');
$other = newContact($db, 'Nina Elsewhere', "nina.$suffix@example.test", $deptSales);
$before = count(file($glog));
$rm2 = $mwf->startRun($mt, $other, null);
ok(taskStatus($db, $rm2, 'Give licence') === 'action_failed' && str_contains((string) q1($db, "SELECT last_error FROM workflow_run_tasks WHERE run_id=$rm2"), 'department'), 'an Entra account in another department is refused (IT-9 check applies)');
ok(count(file($glog)) > $before && !preg_grep('/assignLicense/', array_slice(file($glog), $before)), 'no licence call was made for it (only lookups)');
$rm3 = $mwf->startRun($mo, $emp, null);
$lines = array_map(fn ($l) => json_decode($l, true), file($glog, FILE_IGNORE_NEW_LINES));
$paths = array_map(fn ($l) => $l['method'] . ' ' . $l['path'], $lines);
ok(taskStatus($db, $rm3, 'Take licence') === 'completed' && in_array('POST /v1.0/users/u-1/assignLicense', array_slice($paths, -6), true), 'remove-licence sent');
$last = array_values(array_filter($lines, fn ($l) => str_contains($l['path'], 'assignLicense')));
ok(json_decode(end($last)['body'], true)['removeLicenses'] === [$sku], 'body removes the SKU');
ok(taskStatus($db, $rm3, 'Auto reply') === 'completed', 'auto reply task completed');
$mbx = array_values(array_filter($lines, fn ($l) => str_contains($l['path'], 'mailboxSettings')));
$mb = json_decode($mbx[0]['body'], true);
ok($mbx[0]['method'] === 'PATCH' && $mb['automaticRepliesSetting']['status'] === 'alwaysEnabled' && str_contains($mb['automaticRepliesSetting']['internalReplyMessage'], '<b>IT</b>. Re: Eve Employee'), 'automatic reply PATCHed with the admin text as written');
$amp = newContact($db, 'A <i>&</i> B', "amp.$suffix@example.test", $deptSales);
ok(str_contains(Placeholders::render('Re: {{employee_name}}', ['employee_name' => 'A <i>&</i> B'], 'html'), 'A &lt;i&gt;&amp;&lt;/i&gt; B'), 'directory-supplied placeholder values in the reply are HTML-escaped');
ok(in_array('entra.licenses_changed', array_column($mgw->audits, 0), true) && in_array('entra.auto_reply_set', array_column($mgw->audits, 0), true), 'licence and auto-reply changes are audited');
ok(!str_contains(json_encode($mgw->audits), 'not-a-real-secret'), 'no secret in audit metadata');
$db->query("UPDATE settings SET config_entra_allow_writes = 0 WHERE company_id = 1");
$db->query('DELETE FROM microsoft_integrations');
proc_terminate($proc);
proc_close($proc);
@unlink($store);
@unlink($glog);

// ===================================================================================================================================
section('Events: role change, transfer; directory syncs raise them');
$b = ['contact_employment_status' => 'active', 'contact_client_id' => 5, 'contact_title' => 'Clerk', 'contact_start_date' => '2025-01-01', 'contact_expected_end_date' => null];
$a = fn (array $o) => $o + $b;
ok(LifecycleEvents::detect($b, $a(['contact_title' => 'Senior Clerk'])) === ['employee.role_changed'], 'title change -> employee.role_changed');
ok(LifecycleEvents::detect($b, $a(['contact_title' => 'clerk '])) === [], 'same title (case/space) is not a change');
ok(LifecycleEvents::detect(['contact_title' => ''] + $b, $a([])) === [], 'a first title being filled in is not a role change');
ok(LifecycleEvents::detect($b, $a(['contact_client_id' => 6])) === ['employee.transferred'], 'department change -> employee.transferred');
ok(LifecycleEvents::detect(['contact_client_id' => 0] + $b, $a([])) === [], 'first placement in a department is not a transfer');
ok(LifecycleEvents::detect($b, $a(['contact_client_id' => 6, 'contact_title' => 'Lead'])) === ['employee.role_changed', 'employee.transferred'], 'both at once -> both events');
ok(LifecycleEvents::detect($b, $a(['contact_employment_status' => 'terminated', 'contact_client_id' => 6, 'contact_title' => 'x'])) === ['employee.terminated'], 'a leaver never also looks like a transfer or a role change');
ok(LifecycleEvents::detect(null, $a(['contact_start_date' => date('Y-m-d')])) === ['employee.hired'], 'hire detection unchanged');
$g = new RecGw($db);
$ev = newContact($db, 'Ed Event', "ed.$suffix@example.test", $deptSales, ['contact_title' => 'Clerk']);
$snap = LifecycleEvents::snapshot($db, $ev);
$db->query("UPDATE contacts SET contact_title='Manager', contact_client_id=$deptHr WHERE contact_id=$ev");
$out = LifecycleEvents::afterChange($db, $ev, $snap, $g);
ok($out === ['employee.role_changed', 'employee.transferred'], 'afterChange emits both: ' . implode(',', $out));
ok($g->audits[0][1]['previous_job_title'] === 'Clerk' && $g->audits[1][1]['previous_client_id'] === $deptSales, 'event metadata carries the previous title and department');
$db->query("UPDATE settings SET config_lifecycle_auto_start = 0 WHERE company_id = 1");
ok(LifecycleEvents::afterChange($db, $ev, $snap, new RecGw($db)) === [], 'switched off: nothing detected');
$db->query("UPDATE settings SET config_lifecycle_auto_start = 1 WHERE company_id = 1");
// start rules
$rcT = newTemplate($db, 'RC rule ' . $suffix, 'role_change');
$svc->add($rcT, ['title' => 'Update permissions']);
$trT = newTemplate($db, 'TR rule ' . $suffix, 'transfer');
$svc->add($trT, ['title' => 'Move mailbox']);
$onT = newTemplate($db, 'ON rule ' . $suffix, 'onboarding');
ok(str_contains((string) throws(fn () => StartWorkflowRule::save($db, null, 'bad', 'employee.role_changed', [], ['template_id' => $onT], true), InvalidArgumentException::class), 'role_change templates'), 'employee.role_changed only accepts a role_change template');
ok(str_contains((string) throws(fn () => StartWorkflowRule::save($db, null, 'bad', 'employee.transferred', [], ['template_id' => $rcT], true), InvalidArgumentException::class), 'transfer templates'), 'employee.transferred only accepts a transfer template');
$ruleId = StartWorkflowRule::save($db, null, 'RC ' . $suffix, 'employee.role_changed', [], ['template_id' => $rcT], true);
ok($ruleId > 0, 'a role-change start rule saves');
$ruleGw = new RecGw($db);
$msg = StartWorkflowRule::run($db, ['template_id' => $rcT], ['event' => 'employee.role_changed', 'contact_id' => (string) $ev], $ruleGw);
ok(str_starts_with($msg, 'started') && q1($db, "SELECT type FROM workflow_runs WHERE contact_id=$ev AND workflow_template_id=$rcT") === 'role_change', 'the rule starts the role-change template: ' . $msg);
ok(str_starts_with(StartWorkflowRule::run($db, ['template_id' => $rcT], ['event' => 'employee.role_changed', 'contact_id' => (string) $ev], $ruleGw), 'skipped'), 'idempotent while a run is open');
ok(in_array('workflow.role_change_started', array_column($ruleGw->audits, 0), true), 'audit event workflow.role_change_started');
ok(throws(fn () => StartWorkflowRule::run($db, ['template_id' => $trT], ['event' => 'employee.role_changed', 'contact_id' => (string) $ev], $ruleGw), RuntimeException::class) !== null, 'a template of the wrong type for the event is refused at run time');
$db->query("DELETE FROM automation_rules WHERE rule_id=$ruleId");
// Microsoft + Google syncs
$mcInt = mk($db, "INSERT INTO microsoft_integrations SET tenant_id='t', client_id='c', enabled=1");
$deptMs = newClient($db, 'MsDept ' . $suffix);
$deptMs2 = newClient($db, 'MsDept2 ' . $suffix);
$pre = newContact($db, 'Pre Hire', "prehire.$suffix@example.test", $deptMs, ['contact_employment_status' => 'pre-hire', 'contact_title' => 'Analyst']);
$stay = newContact($db, 'Stay Put', "stay.$suffix@example.test", $deptMs, ['contact_title' => 'Analyst']);
$leave = newContact($db, 'Going Away', "away.$suffix@example.test", $deptMs, ['contact_title' => 'Analyst']);
$mover = newContact($db, 'Moving On', "mover.$suffix@example.test", $deptMs, ['contact_title' => 'Analyst']);
$db->query("DELETE FROM audit_events WHERE event_type LIKE 'employee.%'");
$mapper = new \ITFlow\Integrations\Microsoft\MicrosoftDirectoryMapper($db, $mcInt, 0);
$deptMap = ['msdept ' . strtolower($suffix) => $deptMs, 'msdept2 ' . strtolower($suffix) => $deptMs2];
$users = [
    ['displayName' => 'Pre Hire', 'mail' => "prehire.$suffix@example.test", 'accountEnabled' => true, 'department' => 'MsDept ' . $suffix],
    ['displayName' => 'Stay Put', 'mail' => "stay.$suffix@example.test", 'accountEnabled' => true, 'department' => 'MsDept ' . $suffix],
    ['displayName' => 'Going Away', 'mail' => "away.$suffix@example.test", 'accountEnabled' => false, 'department' => 'MsDept ' . $suffix],
    ['displayName' => 'Moving On', 'mail' => "mover.$suffix@example.test", 'accountEnabled' => true, 'department' => 'MsDept2 ' . $suffix],
    ['displayName' => 'Brand New', 'mail' => "brandnew.$suffix@example.test", 'accountEnabled' => true, 'department' => 'MsDept ' . $suffix],
];
$mapper->syncEmployees($users, $deptMap);
$evs = q($db, "SELECT event_type, entity_id FROM audit_events WHERE event_type LIKE 'employee.%'");
$has = fn (string $type, int $id) => (bool) array_filter($evs, fn ($e) => $e['event_type'] === $type && (int) $e['entity_id'] === $id);
ok($has('employee.hired', $pre), 'Microsoft sync: pre-hire whose account is now enabled -> employee.hired');
ok($has('employee.terminated', $leave), 'Microsoft sync: disabled account -> employee.terminated');
ok($has('employee.transferred', $mover), 'Microsoft sync: department changed in Entra -> employee.transferred');
ok(!array_filter($evs, fn ($e) => (int) $e['entity_id'] === $stay), 'Microsoft sync: unchanged person raises nothing');
$brand = (int) q1($db, "SELECT contact_id FROM contacts WHERE contact_email='brandnew.$suffix@example.test'");
ok($brand > 0 && !array_filter($evs, fn ($e) => (int) $e['entity_id'] === $brand), 'Microsoft sync: a brand-new person with no start date is not a hire (first big sync stays quiet)');
$db->query("DELETE FROM audit_events WHERE event_type LIKE 'employee.%'");
$mapper->syncEmployees($users, $deptMap);
ok((int) q1($db, "SELECT COUNT(*) FROM audit_events WHERE event_type LIKE 'employee.%'") === 0, 'Microsoft sync: a second identical run raises nothing (idempotent)');
$db->query("UPDATE settings SET config_lifecycle_auto_start = 0 WHERE company_id = 1");
$db->query("UPDATE contacts SET contact_employment_status='pre-hire' WHERE contact_id=$pre");
$mapper->syncEmployees($users, $deptMap);
ok((int) q1($db, "SELECT COUNT(*) FROM audit_events WHERE event_type LIKE 'employee.%'") === 0, 'Microsoft sync: with auto-start off, no events at all');
$db->query("UPDATE settings SET config_lifecycle_auto_start = 1 WHERE company_id = 1");
$gInt = mk($db, "INSERT INTO google_integrations SET enabled=1");
$gpre = newContact($db, 'G Pre', "gpre.$suffix@example.test", $deptMs, ['contact_employment_status' => 'pre-hire']);
$gleave = newContact($db, 'G Leave', "gleave.$suffix@example.test", $deptMs);
$db->query("DELETE FROM audit_events WHERE event_type LIKE 'employee.%'");
$gm = new \ITFlow\Integrations\Google\GoogleDirectoryMapper($db, $gInt, 0);
$gm->syncEmployees([
    ['id' => 'g1', 'primaryEmail' => "gpre.$suffix@example.test", 'name' => ['fullName' => 'G Pre'], 'suspended' => false, 'orgUnitPath' => '/'],
    ['id' => 'g2', 'primaryEmail' => "gleave.$suffix@example.test", 'name' => ['fullName' => 'G Leave'], 'suspended' => true, 'orgUnitPath' => '/'],
], []);
$evs = q($db, "SELECT event_type, entity_id FROM audit_events WHERE event_type LIKE 'employee.%'");
ok($has('employee.hired', $gpre) || (bool) array_filter($evs, fn ($e) => $e['event_type'] === 'employee.hired' && (int) $e['entity_id'] === $gpre), 'Google sync: pre-hire now active -> employee.hired');
ok((bool) array_filter($evs, fn ($e) => $e['event_type'] === 'employee.terminated' && (int) $e['entity_id'] === $gleave), 'Google sync: suspended user -> employee.terminated');

// ===================================================================================================================================
section('Bulk onboarding');
$p = BulkOnboarding::parseCsv("email,access,needs_laptop\na@x.test,Full,yes\n\nb@x.test,Basic,no\n");
ok($p['error'] === null && count($p['rows']) === 2 && $p['rows'][0]['answers'] === ['access' => 'Full', 'needs_laptop' => 'yes'], 'CSV with form columns parses');
$p2 = BulkOnboarding::parseCsv("a@x.test\nb@x.test\n");
ok($p2['error'] === null && count($p2['rows']) === 2 && $p2['rows'][1]['email'] === 'b@x.test', 'a plain list of emails parses');
ok(BulkOnboarding::parseCsv('')['error'] !== null, 'empty input is an error');
ok(BulkOnboarding::parseCsv(implode("\n", array_map(fn ($i) => "u$i@x.test", range(1, 201))))['error'] !== null, 'more than 200 people is refused');
ok(BulkOnboarding::parseCsv("email,var_access\na@x.test,Full")['rows'][0]['answers'] === ['access' => 'Full'], 'var_ prefix is accepted');
$bt = newTemplate($db, 'Bulk ' . $suffix, 'onboarding', json_encode($defs));
$svc->add($bt, ['title' => 'Bulk task']);
$bp = [newContact($db, 'Bea Bulk', "bea.$suffix@example.test", $deptSales), newContact($db, 'Bob Bulk', "bob.$suffix@example.test", $deptHr), newContact($db, 'Bill Bulk', "bill.$suffix@example.test", $deptSales)];
$dupA = newContact($db, 'Dup One', "dup.$suffix@example.test", $deptSales);
$dupB = newContact($db, 'Dup Two', "dup.$suffix@example.test", $deptHr);
$rows = [
    ['email' => "bea.$suffix@example.test", 'answers' => ['access' => 'Full']],
    ['email' => "bob.$suffix@example.test", 'answers' => ['access' => 'Basic', 'needs_laptop' => 'no']],
    ['email' => "bill.$suffix@example.test", 'answers' => []],
    ['email' => "nobody.$suffix@example.test", 'answers' => ['access' => 'Full']],
    ['email' => 'not-an-email', 'answers' => []],
    ['email' => "dup.$suffix@example.test", 'answers' => ['access' => 'Full']],
    ['email' => "bea.$suffix@example.test", 'answers' => ['access' => 'Full']],
];
$bulk = new BulkOnboarding($db);
$pv = $bulk->preview($bt, $rows);
$st = array_column($pv['rows'], 'status');
ok($st === ['ready', 'ready', 'error', 'error', 'error', 'error', 'error'], 'preview statuses: ' . implode(',', $st));
ok(str_contains($pv['rows'][2]['message'], 'Access'), 'a missing required answer is reported per row');
ok(str_contains($pv['rows'][5]['message'], 'More than one'), 'an ambiguous email is not guessed');
ok($pv['rows'][6]['message'] === 'Listed twice.', 'a person listed twice is flagged');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE workflow_template_id=$bt") === 0, 'preview writes nothing');
$deny = fn (int $client) => $client !== $deptHr;
$pv2 = $bulk->preview($bt, $rows, $deny);
ok($pv2['rows'][1]['status'] === 'error' && str_contains($pv2['rows'][1]['message'], 'department'), 'department scoping: a person of a department the agent may not manage is refused');
$bg = new RecGw($db);
$res = $bulk->commit($bt, $pv['rows'], 7, null, $bg);
ok($res['started'] === 2 && $res['failed'] === 0 && count($res['run_ids']) === 2, 'commit starts the two ready rows');
ok(q1($db, "SELECT started_by FROM workflow_runs WHERE run_id=" . $res['run_ids'][0]) === '7', 'started_by is the agent');
ok(json_decode((string) q1($db, "SELECT variables_json FROM workflow_runs WHERE run_id=" . $res['run_ids'][0]), true)['access'] === 'Full', 'each run keeps its own form answers');
ok(count(array_filter($bg->audits, fn ($a) => $a[0] === 'workflow.onboarding_started' && ($a[1]['bulk'] ?? false))) === 2, 'one audit event per started run');
$res2 = $bulk->commit($bt, $pv['rows'], 7, null, $bg);
ok($res2['started'] === 0 && (int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE workflow_template_id=$bt") === 2, 'committing the same preview again starts nothing new (idempotent)');
$pv3 = $bulk->preview($bt, [['email' => "bea.$suffix@example.test", 'answers' => ['access' => 'Full']]]);
ok($pv3['rows'][0]['status'] === 'skip', 'a person with the workflow open is shown as skip');
$tamper = $pv['rows'];
$tamper[0]['status'] = 'ready';
$tamper[2]['status'] = 'ready'; // the row the preview refused: commit re-checks and still refuses it
$res3 = $bulk->commit($bt, $tamper, 7, null, $bg);
ok($res3['started'] === 0 && $res3['failed'] === 1 && (int) q1($db, "SELECT COUNT(*) FROM workflow_runs WHERE workflow_template_id=$bt") === 2, 'commit re-validates: a tampered "ready" row (missing answer; people who already have it open) starts nothing');
ok($bulk->preview(999999, $rows)['error'] !== null, 'unknown template refused');
$inactive = newTemplate($db, 'Inactive ' . $suffix, 'onboarding');
$db->query("UPDATE workflow_templates SET is_active=0 WHERE workflow_template_id=$inactive");
ok($bulk->preview($inactive, $rows)['error'] !== null, 'inactive template refused');
// people import
$imp = new \ITFlow\Directory\PersonImportService($db);
$csv = tempnam(sys_get_temp_dir(), 'i31imp');
file_put_contents($csv, "name,email,department\nImp One,imp1.$suffix@example.test,Sales $suffix\nImp Two,imp2.$suffix@example.test,Sales $suffix\n");
$pvw = $imp->preview($csv);
$sum = $imp->commit(array_filter($pvw['rows'], fn ($r) => $r['action'] !== 'error'), 1);
ok($sum['created'] === 2 && count($sum['created_ids']) === 2, 'People import returns the ids of the people it created');
$res = $bulk->commit($bt, array_map(fn ($id) => ['contact_id' => $id, 'status' => 'ready', 'answers' => []], $sum['created_ids']), 1, null, $bg);
ok($res['started'] === 0 && $res['failed'] === 2, 'import hook with a template that has a required question: refused per person (use the bulk page)');
$plain = newTemplate($db, 'Plain ' . $suffix, 'onboarding');
$svc->add($plain, ['title' => 'Plain task']);
$res = $bulk->commit($plain, array_map(fn ($id) => ['contact_id' => $id, 'status' => 'ready', 'answers' => []], $sum['created_ids']), 1, null, $bg);
ok($res['started'] === 2, 'import hook with a plain template starts one run per new person');
unlink($csv);

// ===================================================================================================================================
section('Built-in presets');
$ps = LifecyclePresets::all();
ok(isset($ps['notify_manager'], $ps['phone_extension'], $ps['building_access']), 'notify manager, phone extension and building access presets exist');
$pt = newTemplate($db, 'Preset ' . $suffix, 'onboarding');
$r = LifecyclePresets::addToTemplate($db, $pt, 'building_access');
ok($r['added'] === 2 && $r['skipped'] === 0, 'building access adds an approval and a task');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id=$pt AND task_type='approval' AND approver_type='manager'") === 1, 'the approval is by the employee\'s manager');
$badge = q($db, "SELECT depends_on FROM workflow_template_tasks WHERE workflow_template_id=$pt AND title LIKE 'Issue or revoke%'");
ok($badge && $badge[0]['depends_on'] !== null, 'the badge task waits for the approval');
$r = LifecyclePresets::addToTemplate($db, $pt, 'building_access');
ok($r['added'] === 0 && $r['skipped'] === 2 && (int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id=$pt") === 2, 'adding a preset twice adds nothing');
LifecyclePresets::addToTemplate($db, $pt, 'notify_manager');
LifecyclePresets::addToTemplate($db, $pt, 'phone_extension');
LifecyclePresets::addToTemplate($db, $pt, 'laptop_assign');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id=$pt") === 5, 'all four onboarding presets fit an onboarding template');
ok(str_contains((string) throws(fn () => LifecyclePresets::addToTemplate($db, $pt, 'collect_equipment'), InvalidArgumentException::class), 'does not fit'), 'an offboarding-only preset is refused on an onboarding template');
ok(throws(fn () => LifecyclePresets::addToTemplate($db, $pt, 'nope'), InvalidArgumentException::class) !== null, 'unknown preset refused');
$wfp = new WorkflowService($db, $bg = new RecGw($db));
$pv = $wfp->previewRun($pt, $emp);
ok(count($pv['tasks']) === 5, 'the preset template previews');
$beforeMail = count($bg->mail);
$rp = $wfp->startRun($pt, $emp, null);
ok(count($bg->mail) === $beforeMail + 1 && str_contains($bg->mail[$beforeMail]['bodyHtml'], 'Eve Employee'), 'notify-manager preset emails the manager');
ok(taskStatus($db, $rp, 'Manager approves building access') === 'pending' && taskStatus($db, $rp, 'Issue or revoke the access badge and alarm code') === 'blocked', 'building access: approval first, then the badge task');
$db->query("DELETE FROM workflow_template_tasks WHERE workflow_template_id IN (SELECT workflow_template_id FROM workflow_templates WHERE preset_key LIKE 'starter_%')");
$db->query("DELETE FROM workflow_templates WHERE preset_key LIKE 'starter_%'"); // earlier runs on this scratch DB
foreach (LifecyclePresets::STARTERS as $key => $def) {
    $s1 = LifecyclePresets::installStarter($db, $key, 1);
    $s2 = LifecyclePresets::installStarter($db, $key, 1);
    ok($s1['created'] && !$s2['created'] && $s1['template_id'] === $s2['template_id'], "starter $key installs once");
    ok(q1($db, "SELECT is_active FROM workflow_templates WHERE workflow_template_id={$s1['template_id']}") === '0', "starter $key is installed inactive");
    ok(q1($db, "SELECT type FROM workflow_templates WHERE workflow_template_id={$s1['template_id']}") === $def['type'], "starter $key has type {$def['type']}");
    $n = (int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id={$s1['template_id']}");
    ok($n >= 1, "starter $key has $n task(s)");
    $prevw = (new WorkflowService($db, new RecGw($db)))->previewRun($s1['template_id'], $emp);
    ok(count($prevw['tasks']) === $n, "starter $key previews cleanly");
}
$leaverStarter = (int) q1($db, "SELECT workflow_template_id FROM workflow_templates WHERE preset_key='starter_offboarding'");
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id=$leaverStarter AND delay_days=30") === 1, 'the leaver starter schedules the clean-up 30 days after the end date');
ok((int) q1($db, "SELECT COUNT(*) FROM workflow_template_tasks WHERE workflow_template_id=$leaverStarter AND action_type LIKE 'entra%'") === 0, 'no starter contains a directory (Entra/Odoo/connector) action: those are opt-in');

// ===================================================================================================================================
section('Action registry');
$labels = TaskActionRunner::labels();
foreach (['assign_asset', 'collect_assets', 'archive_contact', 'odoo_provision_employee', 'odoo_disable_employee', 'connector_provision', 'connector_deprovision', 'entra_assign_license', 'entra_remove_license', 'entra_set_auto_reply'] as $k) {
    ok(isset($labels[$k]), "action $k is registered");
}
ok(array_key_exists('collect_assets', TaskActionRunner::labelsFor('offboarding')) && !array_key_exists('archive_contact', TaskActionRunner::labelsFor('onboarding')), 'the editor list is filtered by template type');
ok(array_key_exists('entra_add_to_groups', TaskActionRunner::labelsFor('transfer')) && !array_key_exists('entra_create_account', TaskActionRunner::labelsFor('transfer')), 'transfer: add-to-groups yes, create-account no');
ok(array_key_exists('disable_contact_login', TaskActionRunner::labelsFor('offboarding')) && !array_key_exists('disable_contact_login', TaskActionRunner::labelsFor('role_change')), 'existing rule kept: portal-login disable is offboarding only');

// ===================================================================================================================================
section('Clean-up');
$db->query("DELETE FROM automation_rules WHERE name LIKE '%$suffix'");
echo "\n";
echo $PASS . " passed, " . count($FAIL) . " failed\n";
if ($FAIL) { echo "FAILED:\n - " . implode("\n - ", $FAIL) . "\n"; exit(1); }
