<?php
/*
 * Employee self-service portal against the REAL src/Portal/EmployeeHome.php on a scratch database:
 *   - scoping: employee A never sees B's tickets, devices, approvals, checklist, profile (and not another department's)
 *   - the home-section setting parser, "Mine" device lookup for report-a-problem
 *   - onboarding request: validation (pure), who may ask, creation in the requester's department, idempotence (no duplicate contact
 *     or run), template vs ticket fallback, manager approval task honoured
 *   - migration 2.6.137 idempotence (settings columns exist once, defaults right)
 * Needs a THROWAWAY database that holds this app's schema (import db.sql + database updates); it never reads config.php:
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/employee_portal.php
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($mysqli->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$mysqli->query("SET SESSION sql_mode=''");

require __DIR__ . '/../vendor/autoload.php';
use ITFlow\Portal\EmployeeHome as H;

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => mysqli_fetch_row($q($sql))[0] ?? null;

foreach (['workflow_task_log', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates', 'service_catalog_request_approvals', 'service_catalog_requests', 'service_catalog_items', 'tickets', 'assets'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM contacts WHERE contact_id >= 800"); $q("DELETE FROM clients WHERE client_id IN (800, 801)"); $q("DELETE FROM users WHERE user_id >= 800");
$q("INSERT INTO clients SET client_id = 800, client_name = 'Dept A'");
$q("INSERT INTO clients SET client_id = 801, client_name = 'Dept B'");
// 800 manager (Mia), 801 employee A (reports to 800), 802 employee B (reports to 800), 803 other-department contact, 804 plain employee with no reports
$q("INSERT INTO contacts SET contact_id = 800, contact_name = 'Mia Manager', contact_email = 'mia@a.test', contact_client_id = 800");
$q("INSERT INTO contacts SET contact_id = 801, contact_name = 'Alice A', contact_email = 'alice@a.test', contact_client_id = 800, contact_manager_id = 800, contact_title = 'Analyst'");
$q("INSERT INTO contacts SET contact_id = 802, contact_name = 'Bob B', contact_email = 'bob@a.test', contact_client_id = 800, contact_manager_id = 800");
$q("INSERT INTO contacts SET contact_id = 803, contact_name = 'Carl C', contact_email = 'carl@b.test', contact_client_id = 801");
$q("INSERT INTO contacts SET contact_id = 804, contact_name = 'Dee D', contact_email = 'dee@a.test', contact_client_id = 800");
$q("INSERT INTO users SET user_id = 800, user_name = 'Agent', user_email = 'ag@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$q("INSERT INTO ticket_statuses SET ticket_status_id = 1, ticket_status_name = 'New', ticket_status_color = '#000', ticket_status_active = 1 ON DUPLICATE KEY UPDATE ticket_status_name = 'New'");

$mkTicket = function (int $client, int $contact, string $subj, bool $closed = false) use ($q, $mysqli) {
    static $n = 7000; $n++;
    $q("INSERT INTO tickets SET ticket_prefix = 'T', ticket_number = $n, ticket_subject = '" . mysqli_real_escape_string($mysqli, $subj) . "', ticket_status = 1, ticket_client_id = $client, ticket_contact_id = $contact" . ($closed ? ", ticket_closed_at = NOW()" : ''));
    return mysqli_insert_id($mysqli);
};
$mkAsset = function (int $client, int $contact, string $name) use ($q, $mysqli) {
    $q("INSERT INTO assets SET asset_name = '$name', asset_type = 'Laptop', asset_client_id = $client, asset_contact_id = $contact, asset_make = 'Dell', asset_serial = 'SN-$name'");
    return mysqli_insert_id($mysqli);
};
$h = new H($mysqli);

// ---------------------------------------------------------------- sections setting
$all = array_keys(H::SECTIONS);
$ok(H::parseSections(null) === $all, 'NULL (not migrated) means every section on');
$ok(H::parseSections('') === [], 'an empty value means every section off');
$ok(H::parseSections('devices, bogus ,requests,devices') === ['devices', 'requests'], 'parse drops unknown keys and duplicates, keeps order');
$ok(H::formatSections(['requests', '<script>', 'training']) === 'requests,training', 'format only keeps known keys');

// ---------------------------------------------------------------- my open requests / scoping
$tA = $mkTicket(800, 801, 'Alice open'); $mkTicket(800, 801, 'Alice closed', true);
$tB = $mkTicket(800, 802, 'Bob open'); $tC = $mkTicket(801, 803, 'Carl open');
$mine = $h->myOpenRequests(801, 800);
$ok(count($mine) === 1 && (int) $mine[0]['ticket_id'] === $tA, 'A sees only her own open ticket (not closed, not B\'s, not another department\'s)');
$ok(array_column($h->myOpenRequests(802, 800), 'ticket_id') == [$tB], 'B sees only B');
$ok($h->myOpenRequests(801, 801) === [], 'A\'s contact id with another department\'s client id returns nothing');
$ok($h->myOpenRequests(0, 800) === [] && $h->myOpenRequests(801, 0) === [], 'contact 0 (admin preview) / client 0 return nothing');

// ---------------------------------------------------------------- devices
$aA = $mkAsset(800, 801, 'laptopA'); $aB = $mkAsset(800, 802, 'laptopB'); $aFree = $mkAsset(800, 0, 'spare'); $aC = $mkAsset(801, 803, 'laptopC');
$d = $h->myDevices(801, 800);
$ok(count($d) === 1 && (int) $d[0]['asset_id'] === $aA, 'A sees only A\'s device');
$ok($h->myDevices(0, 800) === [], 'contact 0 sees none of the unassigned assets');
$ok(!array_key_exists('asset_notes', $d[0]) && !array_key_exists('asset_ip', $d[0]) && !array_key_exists('asset_uri', $d[0]), 'device rows carry no notes, IP or internal URI columns');
$ok($h->myDevice($aA, 801, 800) !== null, 'report-a-problem lookup finds A\'s own device');
$ok($h->myDevice($aB, 801, 800) === null && $h->myDevice($aFree, 801, 800) === null && $h->myDevice($aC, 801, 800) === null && $h->myDevice($aA, 801, 801) === null, 'report-a-problem lookup refuses B\'s, an unassigned, another department\'s device, and a wrong client');
$q("UPDATE assets SET asset_archived_at = NOW() WHERE asset_id = $aA");
$ok($h->myDevices(801, 800) === [] && $h->myDevice($aA, 801, 800) === null, 'archived devices are not listed');

// ---------------------------------------------------------------- approvals
$q("INSERT INTO service_catalog_items SET catalog_item_id = 900, name = 'Laptop', ticket_subject_template = 's', is_active = 1");
$tk = $mkTicket(800, 801, 'Needs approval');
$q("INSERT INTO service_catalog_requests SET request_id = 900, catalog_item_id = 900, ticket_id = $tk, client_id = 800, contact_id = 801, status = 'pending_approval', current_step = 1, field_values = '[]'");
$q("INSERT INTO service_catalog_request_approvals SET request_id = 900, step_order = 1, step_mode = 'any', approver_contact_id = 800, status = 'pending'");
$ok(count($h->waitingOnMe(800, 800)) === 1, 'the manager sees the approval waiting on her');
$ok($h->waitingOnMe(801, 800) === [] && $h->waitingOnMe(802, 800) === [] && $h->waitingOnMe(800, 801) === [], 'A, B, and the manager under another department see no approvals');

// ---------------------------------------------------------------- checklist
$q("INSERT INTO workflow_templates SET workflow_template_id = 900, name = 'Onboard', type = 'onboarding', is_active = 1");
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = 900, title = 'Sign handbook', default_owner = 'Employee', sort_order = 1, task_type = 'manual', instructions = 'INTERNAL NOTE'");
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = 900, title = 'Manager sign-off', sort_order = 2, task_type = 'approval', approver_type = 'manager'");
$q("INSERT INTO workflow_template_tasks SET workflow_template_id = 900, title = 'Create mailbox', sort_order = 3, task_type = 'action', action_type = 'notify_user', action_config = '{\"message\":\"hi\"}'");
$svc = new \ITFlow\Workflow\WorkflowService($mysqli, new class implements \ITFlow\Workflow\ActionGateway {
    public function createTicket(string $s, string $d, string $p, int $c, string $src): int { return 1; }
    public function queueMail(string $to, string $n, string $s, string $b): void {}
    public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void {}
    public function emitEvent(string $ev, array $d): void {}
    public function audit(string $ev, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void {}
    public function disablePortalLogin(int $c): string { return ''; }
});
$ok($h->myChecklist(801, 800) === null, 'no checklist while no run exists');
$run = $svc->startRun(900, 801, null);
$cl = $h->myChecklist(801, 800);
$ok($cl !== null && $cl['total'] === 2 && $cl['done'] === 0, 'A sees her run: automated (action) tasks are left out');
$ok(!array_key_exists('instructions', $cl['tasks'][0]) && !array_key_exists('action_config', $cl['tasks'][0]) && !array_key_exists('last_error', $cl['tasks'][0]), 'checklist rows carry no instructions, automation config or errors');
$ok($h->myChecklist(802, 800) === null && $h->myChecklist(801, 801) === null && $h->myChecklist(0, 800) === null, 'B, wrong department and contact 0 see no checklist');
$q("UPDATE workflow_runs SET status = 'cancelled' WHERE run_id = $run");
$ok($h->myChecklist(801, 800) === null, 'a cancelled run is not shown');

// ---------------------------------------------------------------- profile facts
$p = $h->profileFacts(801, 800);
$ok($p !== null && $p['manager_name'] === 'Mia Manager' && $p['contact_title'] === 'Analyst', 'profile facts: manager name and title');
$ok($h->profileFacts(801, 801) === null && $h->profileFacts(0, 800) === null, 'profile facts refuse a wrong department and contact 0');

// ---------------------------------------------------------------- who may request onboarding
$ok($h->isManager(800, 800) === true && $h->isManager(801, 800) === false, 'manager = has direct reports');
$ok($h->canRequestOnboarding(true, 800, 800, false) === true, 'a manager may request when the feature is on');
$ok($h->canRequestOnboarding(false, 800, 800, true) === false, 'nobody may request while the feature is off');
$ok($h->canRequestOnboarding(true, 801, 800, false) === false && $h->canRequestOnboarding(true, 804, 800, false) === false, 'ordinary employees may not');
$ok($h->canRequestOnboarding(true, 804, 800, true) === true, 'a department administrator may');
$ok($h->canRequestOnboarding(true, 0, 800, true) === false, 'contact 0 (admin preview) may not');

// ---------------------------------------------------------------- validation (pure)
$mgrs = [800, 801];
$good = ['name' => '  New <b>Hire</b> ', 'email' => 'new.hire@home.test', 'start_date' => '2026-11-02', 'manager_id' => '801', 'title' => 'Dev', 'notes' => 'Needs <i>laptop</i>'];
$v = H::validateOnboarding($good, $mgrs, 800, '2026-10-05');
$ok($v['errors'] === [] && $v['clean']['name'] === 'New Hire' && $v['clean']['manager_id'] === 801 && $v['clean']['notes'] === 'Needs laptop', 'a good request validates, tags are stripped');
$ok(H::validateOnboarding(['manager_id' => ''] + $good, $mgrs, 800, '2026-10-05')['clean']['manager_id'] === 800, 'manager defaults to the requester');
$bad = fn(array $over) => H::validateOnboarding($over + $good, $mgrs, 800, '2026-10-05')['errors'];
$ok(count($bad(['name' => ''])) === 1, 'name required');
$ok(count($bad(['name' => str_repeat('x', 201)])) >= 1, 'name length capped');
$ok(count($bad(['email' => 'nope'])) === 1 && count($bad(['email' => "a@b.test\nBcc: x@y.test"])) === 1, 'email must be valid, header injection rejected');
$ok(count($bad(['start_date' => '2026-02-30'])) === 1 && count($bad(['start_date' => 'soon'])) === 1 && count($bad(['start_date' => ''])) === 1, 'start date must be a real date');
$ok(count($bad(['start_date' => '2025-01-01'])) === 1 && count($bad(['start_date' => '2030-01-01'])) === 1, 'start date window enforced');
$ok(count($bad(['manager_id' => '803'])) === 1, 'a manager from another department is refused');
$ok(H::validateOnboarding($good, [800], 800, '2026-10-05')['errors'] !== [], 'a manager outside the allowed set (a manager naming someone else) is refused');
$ok(mb_strlen(H::validateOnboarding(['notes' => str_repeat('n', 5000)] + $good, $mgrs, 800, '2026-10-05')['clean']['notes']) === 2000, 'notes capped at 2000');

// ---------------------------------------------------------------- create + idempotence (template)
$q("DELETE FROM workflow_runs"); $q("DELETE FROM workflow_run_tasks");
$clean = $v['clean'];
$r1 = $h->createOnboarding($clean, 800, 900, $svc);
$cid = $r1['contact_id'];
$ok($r1['status'] === 'created' && $cid > 0 && $r1['run_id'] > 0 && $r1['needs_ticket'] === false, 'created: contact + run, no ticket needed');
$row = mysqli_fetch_assoc($q("SELECT * FROM contacts WHERE contact_id = $cid"));
$ok($row['contact_client_id'] == 800 && $row['contact_employment_status'] === 'pre-hire' && $row['contact_start_date'] === '2026-11-02' && (int) $row['contact_manager_id'] === 801 && $row['contact_name'] === 'New Hire' && $row['contact_user_id'] == 0, 'contact is a pre-hire in the requester\'s department with the start date and manager, and has no login');
$ok((int) $one("SELECT COUNT(*) FROM workflow_runs WHERE contact_id = $cid") === 1, 'one run started');
$appr = mysqli_fetch_assoc($q("SELECT * FROM workflow_run_tasks WHERE run_id = {$r1['run_id']} AND task_type = 'approval'"));
$ok($appr && $appr['approver_type'] === 'manager' && $appr['approval_status'] === 'pending', 'the template\'s manager approval task is on the run, pending');
$r2 = $h->createOnboarding($clean, 800, 900, $svc);
$ok($r2['status'] === 'existing' && $r2['contact_id'] === $cid && $r2['run_id'] === $r1['run_id'] && $r2['needs_ticket'] === false, 'a repeat is a no-op that points at the same contact and run');
$ok((int) $one("SELECT COUNT(*) FROM contacts WHERE contact_client_id = 800 AND LOWER(contact_email) = 'new.hire@home.test'") === 1 && (int) $one("SELECT COUNT(*) FROM workflow_runs WHERE contact_id = $cid") === 1, 'still exactly one contact and one run');
$r2b = $h->createOnboarding(['email' => 'NEW.HIRE@home.test'] + $clean, 800, 900, $svc);
$ok($r2b['status'] === 'existing' && $r2b['contact_id'] === $cid, 'email match is case-insensitive');
$r3 = $h->createOnboarding(['email' => 'alice@a.test'] + $clean, 800, 900, $svc);
$ok($r3['status'] === 'exists_active' && $r3['run_id'] === 0, 'an email that belongs to an active person is refused');
$r4 = $h->createOnboarding(['email' => 'carl@b.test'] + $clean, 800, 900, $svc);
$ok($r4['status'] === 'created', 'the same email in ANOTHER department is a different person (department scoped)');

// ---------------------------------------------------------------- no template -> ticket fallback flag
$r5 = $h->createOnboarding(['email' => 'two@home.test', 'name' => 'Two'] + $clean, 800, 0, $svc);
$ok($r5['status'] === 'created' && $r5['needs_ticket'] === true && $r5['run_id'] === 0, 'no template: contact created, caller told to open a ticket');
$r5b = $h->createOnboarding(['email' => 'two@home.test', 'name' => 'Two'] + $clean, 800, 0, $svc);
$ok($r5b['status'] === 'existing' && $r5b['needs_ticket'] === false, 'repeat with no template does not ask for a second ticket');
$q("UPDATE workflow_templates SET is_active = 0 WHERE workflow_template_id = 900");
$ok($h->templateUsable(900) === false && $h->templateUsable(0) === false && $h->onboardingTemplates() === [], 'an inactive template is not usable or listed');
$q("INSERT INTO workflow_templates SET workflow_template_id = 901, name = 'Off', type = 'offboarding', is_active = 1");
$ok($h->templateUsable(901) === false, 'an offboarding template cannot be chosen for onboarding');

// ---------------------------------------------------------------- migration idempotence
$cols = fn() => (int) $one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'settings' AND COLUMN_NAME IN ('config_portal_home_sections','config_portal_onboarding_requests','config_portal_onboarding_template_id')");
$ok($cols() === 3, 'the three portal settings columns exist');
$q("UPDATE settings SET config_current_database_version = '2.6.136'");
$lines = file(__DIR__ . '/../admin/database_updates.php');
$src = implode('', $lines);
$start = strpos($src, "if (\$rivetit_db_version() == '2.6.136') {");
$end = strpos($src, "\n    }\n", $start);   // the block closes at the first 4-space brace after its gate
$block = substr($src, $start, $end - $start + 7);
$rivetit_db_version = fn() => $one("SELECT config_current_database_version FROM settings WHERE company_id = 1");
eval('(function () use ($mysqli, $rivetit_db_version) { ' . $block . ' "" ; })();');
$ok($rivetit_db_version() === '2.6.137' && $cols() === 3, 'running the 2.6.137 block again changes nothing but the version');
$def = mysqli_fetch_assoc($q("SELECT config_portal_home_sections AS s, config_portal_onboarding_requests AS o, config_portal_onboarding_template_id AS t FROM settings WHERE company_id = 1"));
$ok($def['o'] == 0 && $def['t'] == 0 && $def['s'] === 'requests,approvals,devices,onboarding,training,catalog', 'defaults: onboarding requests off, no template, all sections on');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
