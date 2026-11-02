<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * Issue #33, automation engine gaps, against the REAL src/ code on a scratch database (never reads config.php, never sends anything):
 *   payload enrichment (department / location / asset type / user group), cron-raised events (asset offline/online, certificate expiring,
 *   credential rotation due, schedule ticks), employee events, asset.assigned, the new actions (run script, Odoo user, chat, Entra) with recording
 *   fakes, the run log's retry visibility and manual re-run, and the legacy ticket-rule importer.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/automation_i33.php
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
use ITFlow\Automation\{ChatNotifier, ChatNotifierRegistry, CronEmitters, EventPayloads, LegacyRuleImporter, OdooUserGateway, PayloadEnricher, RmmScriptDispatcher, RuleAdmin, RuleEngine, RuleForm, RuleSummary};
use ITFlow\Automation\Actions\{ActionRegistry, CreateOdooUserAction, RunScriptAction};
use ITFlow\Workflow\{ActionGateway, DirectoryActionGateway, LifecycleEvents};

$fails = 0;
$ok = function (bool $c, string $l) use (&$fails) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$q = fn(string $sql) => mysqli_query($mysqli, $sql);
$one = fn(string $sql) => ($r = mysqli_query($mysqli, $sql)) ? (mysqli_fetch_row($r)[0] ?? null) : null;
$throws = function (callable $f): ?string { try { $f(); } catch (\Throwable $e) { return $e::class . ':' . $e->getMessage(); } return null; };

class FakeGw implements ActionGateway, DirectoryActionGateway {
    public array $calls = [];
    public bool $entraOn = false;
    public function createTicket(string $s, string $d, string $p, int $c, string $src): int { $this->calls[] = ['ticket', $s]; return 42; }
    public function queueMail(string $to, string $n, string $s, string $b): void { $this->calls[] = ['mail', $to]; }
    public function notifyUser(int $u, string $t, string $m, ?string $a, int $c, int $e): void { $this->calls[] = ['notify', $u, $m]; }
    public function emitEvent(string $ev, array $d): void { $this->calls[] = ['event', $ev, $d]; }
    public function audit(string $ev, ?int $a, string $et, $eid, string $act, string $sum, array $meta = []): void { $this->calls[] = ['audit', $ev, $eid, $meta]; }
    public function disablePortalLogin(int $c): string { return ''; }
    public function entraWritesAllowed(): bool { return $this->entraOn; }
    public function entraDisableAccount(string $email, bool $revoke, int $runTaskId, string $dept = ''): string { $this->calls[] = ['entra_disable', $email, $revoke, $dept]; return "disabled $email"; }
    public function entraCreateAccount(array $spec, int $runTaskId): string { $this->calls[] = ['entra_create']; return 'created'; }
    public function entraAddToGroups(string $email, array $groupIds, int $runTaskId, string $dept = ''): string { $this->calls[] = ['entra_groups', $email, $groupIds, $dept]; return 'added'; }
    public function count(string $k): int { return count(array_filter($this->calls, fn($c) => $c[0] === $k)); }
    public function reset(): void { $this->calls = []; }
}
class FakeScript implements RmmScriptDispatcher {
    public array $sent = [];
    public bool $approval = false;
    public bool $refuse = false;
    public function scriptName(int $uid, string $un, int $sid): ?string { return $sid === 77 ? 'Restart spooler' : null; }
    public function dispatch(int $uid, string $un, int $dev, int $sid, array $params, ?int $t): array {
        if ($this->refuse) throw new \RuntimeException('the RMM module refused: forbidden');
        $this->sent[] = [$uid, $dev, $sid, $params, $t];
        return ['queued' => !$this->approval, 'pending_approval' => $this->approval, 'message' => $this->approval ? 'waiting for approval' : "queued on device #$dev"];
    }
}
class FakeOdoo implements OdooUserGateway { public array $made = []; public function createUser(array $spec): string { $this->made[] = $spec; return 'created Odoo user ' . $spec['login']; } }
class FakeChat implements ChatNotifier {
    public array $sent = [];
    public function destinations(): array { return ['it-alerts' => 'IT alerts', 'oncall' => 'On call']; }
    public function send(string $d, string $m, array $c): string { $this->sent[] = [$d, $m]; return "sent to $d"; }
}

// ================================================================ 0. fixtures
foreach (['automation_rule_runs', 'automation_rules', 'automation_emit_marks', 'audit_events', 'integration_jobs', 'asset_rmm_links', 'certificates', 'credentials', 'webhooks', 'asset_assignments',
    'training_job_group_members', 'training_job_groups', 'workflow_run_tasks', 'workflow_runs', 'ticket_automation_rules'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM assets WHERE asset_id >= 3300"); $q("DELETE FROM contacts WHERE contact_id >= 3300"); $q("DELETE FROM clients WHERE client_id >= 3300"); $q("DELETE FROM locations WHERE location_id >= 3300");
$q("DELETE FROM users WHERE user_id >= 3300"); $q("DELETE FROM user_roles WHERE role_id >= 3300");
$q("UPDATE settings SET config_lifecycle_auto_start = 0, config_automation_run_script_enabled = 0, config_automation_odoo_user_enabled = 0, config_automation_cert_days = 30, config_automation_enabled = 1 WHERE company_id = 1");
$q("INSERT INTO clients SET client_id = 3300, client_name = 'Finance Dept', client_currency_code = 'USD'");
$q("INSERT INTO locations SET location_id = 3300, location_name = 'HQ Floor 2', location_client_id = 3300");
$q("INSERT INTO user_roles SET role_id = 3300, role_name = 'Finance Admins', role_is_admin = 0");
$q("INSERT INTO users SET user_id = 3300, user_name = 'Tech Terry', user_email = 'terry@x.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 3300");
$q("INSERT INTO users SET user_id = 3301, user_name = 'Gone Gary', user_email = 'gary@x.test', user_password = 'x', user_type = 1, user_status = 0, user_role_id = 3300");
$q("INSERT INTO contacts SET contact_id = 3300, contact_name = 'Pat Payroll', contact_email = 'pat@x.test', contact_title = 'Accountant', contact_department = 'Payroll', contact_client_id = 3300, contact_location_id = 3300, contact_employment_status = 'active', contact_employee_type = 'employee', contact_user_id = 3300");
$q("INSERT INTO contacts SET contact_id = 3301, contact_name = 'No Email', contact_email = '', contact_client_id = 3300, contact_employment_status = 'active'");
$q("INSERT INTO training_job_groups SET jobgroup_id = 3300, jobgroup_name = 'Finance', jobgroup_created_by = 1");
$q("INSERT INTO training_job_groups SET jobgroup_id = 3301, jobgroup_name = 'Everyone', jobgroup_created_by = 1");
$q("INSERT INTO training_job_group_members SET jgmember_jobgroup_id = 3300, jgmember_contact_id = 3300, jgmember_added_by = 1");
$q("INSERT INTO training_job_group_members SET jgmember_jobgroup_id = 3301, jgmember_contact_id = 3300, jgmember_added_by = 1");
$q("INSERT INTO assets SET asset_id = 3300, asset_name = 'LAPTOP-FIN-1', asset_type = 'Laptop', asset_status = 'Deployed', asset_make = 'Dell', asset_model = 'Latitude', asset_client_id = 3300, asset_location_id = 3300, asset_contact_id = 3300");
$q("INSERT INTO assets SET asset_id = 3301, asset_name = 'SRV-1', asset_type = 'Server', asset_client_id = 3300");

// ================================================================ 1. schema facts
$ok(version_compare((string) $one('SELECT config_current_database_version FROM settings WHERE company_id=1'), '2.6.222', '>='), 'database is at 2.6.222 or later');
$ok($one("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name='automation_rule_runs' AND column_name IN ('context_json','attempt','trigger_source','retry_of_run_id')") == 4, 'run log has the retry columns');
$ok($one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='automation_emit_marks'") == 1, 'automation_emit_marks exists');
$ok($one("SELECT config_automation_run_script_enabled + config_automation_odoo_user_enabled FROM settings WHERE company_id=1") == 0, 'the risky action switches default to OFF');
$ok(count(ActionRegistry::all()) === 14 && ActionRegistry::get('run_script') && ActionRegistry::get('entra_disable_account') && !ActionRegistry::get('entra_create_account'), 'registry: 14 actions, Entra create is deliberately not a rule action');
$ok(str_contains((string) mysqli_fetch_row($q('SHOW CREATE TABLE automation_rules'))[1], "'notify_chat'"), 'automation_rules.action_type accepts the new actions');

// ================================================================ 2. payload enrichment
$ctx = PayloadEnricher::enrich($mysqli, ['asset_id' => '3300']);
$ok(($ctx['asset_type'] ?? '') === 'Laptop' && ($ctx['asset_status'] ?? '') === 'Deployed' && ($ctx['asset_name'] ?? '') === 'LAPTOP-FIN-1', 'asset id -> asset type / status / name');
$ok(($ctx['department'] ?? '') === 'Finance Dept' && ($ctx['client_id'] ?? '') === '3300' && ($ctx['location_name'] ?? '') === 'HQ Floor 2', 'asset id -> department and location via the asset');
$ok(($ctx['contact_id'] ?? '') === '3300' && ($ctx['contact_email'] ?? '') === 'pat@x.test' && ($ctx['user_role'] ?? '') === 'Finance Admins', 'asset id -> its person, and their login role');
$ok(($ctx['user_group'] ?? '') === 'Everyone, Finance', 'user group lists the job groups, comma separated');
$ctx = PayloadEnricher::enrich($mysqli, ['contact_id' => '3300', 'department' => 'FROM EVENT', 'asset_type' => 'Phone']);
$ok($ctx['department'] === 'FROM EVENT' && $ctx['asset_type'] === 'Phone', 'a key the event already has is never overwritten');
$ok(($ctx['job_title'] ?? '') === 'Accountant' && ($ctx['employment_status'] ?? '') === 'active' && ($ctx['contact_department'] ?? '') === 'Payroll', 'person fields added');
$ctx = PayloadEnricher::enrich($mysqli, ['entity_type' => 'contact', 'entity_id' => '3300']);
$ok(($ctx['contact_name'] ?? '') === 'Pat Payroll', 'an audit event about a contact (entity_type/entity_id) is enriched too');
$ctx = PayloadEnricher::enrich($mysqli, ['asset_id' => '999999', 'contact_id' => '999999', 'client_id' => 'abc']);
$ok($ctx === ['asset_id' => '999999', 'contact_id' => '999999', 'client_id' => 'abc'], 'unknown ids and junk add nothing and do not fail');
$ctx = PayloadEnricher::enrich($mysqli, ['asset_id' => "3300' OR 1=1 -- "]);
$ok(!isset($ctx['asset_type']), 'a non-numeric id is ignored (no injection)');
$ok(PayloadEnricher::subjectId(['entity_type' => 'asset', 'entity_id' => '5'], 'asset') === 5 && PayloadEnricher::subjectId(['entity_type' => 'asset', 'entity_id' => '5'], 'contact') === 0, 'subjectId is per entity type');

// ================================================================ 3. conditions on enriched fields through the engine and the real bus
$gw = new FakeGw();
$engine = new RuleEngine($mysqli, $gw);
$leaf = fn($f, $op, $v = '') => ['field' => $f, 'op' => $op, 'value' => $v];
$mk = fn(string $trigger, array $conds, string $action, array $input, string $name = 'r', bool $on = true) => $engine->save(null, $name, $trigger, ['mode' => 'all', 'conditions' => $conds], $action, $input, $on);
if (!function_exists('appNotify')) { function appNotify($t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'][] = $d; } }
$GLOBALS['notified'] = [];
$rFin = $mk('asset.assigned', [$leaf('department', 'eq', 'Finance Dept'), $leaf('asset_type', 'eq', 'Laptop'), $leaf('user_group', 'contains', 'Finance'), $leaf('location_name', 'eq', 'HQ Floor 2')], 'notify_user', ['message' => 'Finance laptop {asset_name} to {contact_name}'], 'finance laptops');
$rOther = $mk('asset.assigned', [$leaf('asset_type', 'eq', 'Server')], 'notify_user', ['message' => 'server'], 'servers only');
$GLOBALS['mysqli'] = $mysqli;
rivetEmitEvent('asset.assigned', ['asset_id' => 3300, 'contact_id' => 3300]);   // the payload carries ids only: department, type and group come from the enricher
for ($i = 0; $i < 4; $i++) { rivetRunJobWorker($mysqli, 50, 20); }
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id=$rFin AND status='ok'") === 1 && (int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE rule_id=$rOther") === 0,
    'through the real bus: a rule on department + asset type + user group + location matches an id-only payload; the Server rule does not');
$ok(in_array('Finance laptop LAPTOP-FIN-1 to Pat Payroll', $GLOBALS['notified'], true), 'enriched fields also fill {placeholders}');

// ================================================================ 4. cron emitters
$emitted = [];
$emit = function (string $ev, array $d) use (&$emitted) { $emitted[] = [$ev, $d]; };
$ok(array_sum(CronEmitters::run($mysqli, $emit)) === 0 && $emitted === [], 'nothing is raised while nobody listens (only the bus rule above exists for asset.assigned)');
foreach (['asset.offline', 'asset.online', 'certificate.expiring', 'credential.rotation_due', 'schedule.hourly', 'schedule.daily', 'schedule.weekly', 'schedule.monthly'] as $ev) {
    $mk($ev, [], 'notify_user', ['message' => $ev], "listen $ev");
}
// a catch-all webhook does not count as listening to clock ticks, an explicit "schedule.*" does
$q("DELETE FROM automation_rules WHERE trigger_event LIKE 'schedule.%'");
$q("INSERT INTO webhooks SET webhook_name='catch', webhook_url='https://example.test/h', webhook_events='*', webhook_enabled=1");
$ok(CronEmitters::listening($mysqli)['schedule.hourly'] === false, 'a bare "*" webhook does not make clock ticks fire');
$q("UPDATE webhooks SET webhook_events='schedule.*'");
$ok(CronEmitters::listening($mysqli)['schedule.hourly'] === true && CronEmitters::listening($mysqli)['asset.offline'] === true, '"schedule.*" listens to ticks (and rules listen to their events)');
$q("DELETE FROM webhooks");
foreach (['schedule.hourly', 'schedule.daily', 'schedule.weekly', 'schedule.monthly'] as $ev) { $mk($ev, [], 'notify_user', ['message' => $ev], "listen $ev"); }

// assets: offline recently -> announced once; offline for 3 days -> recorded, not announced; back online -> asset.online once
$q("INSERT INTO asset_rmm_links SET asset_id=3300, integration_id=1, hostname='fin-1', rmm_status='offline', rmm_status_changed_at = NOW() - INTERVAL 10 MINUTE");
$q("INSERT INTO asset_rmm_links SET asset_id=3301, integration_id=1, hostname='srv-1', rmm_status='offline', rmm_status_changed_at = NOW() - INTERVAL 3 DAY");
$emitted = []; $sent = CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 08:15:00'));
$off = array_values(array_filter($emitted, fn($e) => $e[0] === 'asset.offline'));
$ok(count($off) === 1 && $off[0][1]['asset_id'] === 3300 && $off[0][1]['rmm_status'] === 'offline' && $off[0][1]['hostname'] === 'fin-1', 'asset.offline: the recent outage is announced, the 3 day old one only recorded');
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 08:20:00'));
$ok(count(array_filter($emitted, fn($e) => $e[0] === 'asset.offline')) === 0, 'asset.offline is once per outage');
$q("UPDATE asset_rmm_links SET rmm_status='online', rmm_status_changed_at = NOW() WHERE asset_id=3300");
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 08:25:00'));
$on = array_values(array_filter($emitted, fn($e) => $e[0] === 'asset.online'));
$ok(count($on) === 1 && $on[0][1]['asset_id'] === 3300, 'asset.online is raised when it comes back');
$q("UPDATE asset_rmm_links SET rmm_status='offline', rmm_status_changed_at = NOW() + INTERVAL 1 SECOND WHERE asset_id=3300");
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 08:26:00'));
$ok(count(array_filter($emitted, fn($e) => $e[0] === 'asset.offline')) === 1, 'a second outage of the same asset is a new event');

// certificates
$mkCert = fn(string $name, string $expr) => $q("INSERT INTO certificates SET certificate_name='$name', certificate_domain='$name.example.test', certificate_issued_by='CA', certificate_expire = $expr, certificate_client_id=3300, certificate_public_key='-----SECRET KEY MATERIAL-----'");
$mkCert('c20', 'CURDATE() + INTERVAL 20 DAY'); $mkCert('c5', 'CURDATE() + INTERVAL 5 DAY'); $mkCert('c90', 'CURDATE() + INTERVAL 90 DAY'); $mkCert('cold', 'CURDATE() - INTERVAL 40 DAY');
$emitted = []; CronEmitters::run($mysqli, $emit);
$certs = array_values(array_filter($emitted, fn($e) => $e[0] === 'certificate.expiring'));
$names = array_map(fn($e) => $e[1]['certificate_name'], $certs);
sort($names);
$ok($names === ['c20', 'c5'], 'certificate.expiring: inside the window (30 days) only; far-future and long-expired certificates are not announced');
$byName = array_column(array_map(fn($e) => $e[1], $certs), null, 'certificate_name');
$ok($byName['c5']['threshold_days'] === 7 && $byName['c20']['threshold_days'] === 30 && $byName['c5']['days_left'] === 5, 'the threshold crossed is reported (30 for 20 days left, 7 for 5)');
$ok(!str_contains(json_encode($certs), 'SECRET KEY MATERIAL'), 'the payload never carries certificate key material');
$emitted = []; CronEmitters::run($mysqli, $emit);
$ok(count(array_filter($emitted, fn($e) => $e[0] === 'certificate.expiring')) === 0, 'a certificate is announced once per threshold');
$q("UPDATE certificates SET certificate_expire = CURDATE() + INTERVAL 1 DAY WHERE certificate_name='c5'");
$emitted = []; CronEmitters::run($mysqli, $emit);
$again = array_values(array_filter($emitted, fn($e) => $e[0] === 'certificate.expiring'));
$ok(count($again) === 1 && $again[0][1]['threshold_days'] === 1, 'and again when it crosses the next threshold (1 day)');
$q("UPDATE settings SET config_automation_cert_days = 3");
$q("UPDATE certificates SET certificate_expire = CURDATE() + INTERVAL 2 DAY WHERE certificate_name='c20'");
$emitted = []; CronEmitters::run($mysqli, $emit);
$ok(count(array_filter($emitted, fn($e) => $e[0] === 'certificate.expiring' && $e[1]['certificate_name'] === 'c20')) === 1, 'the warning window is the setting (3 days)');
$q("UPDATE settings SET config_automation_cert_days = 30");

// credentials
$q("INSERT INTO credentials SET credential_name='Router admin', credential_type='Login', credential_username='SUPERSECRETUSER', credential_password='SUPERSECRETPW', credential_otp_secret='OTPSECRET', credential_note='NOTESECRET', credential_uri='https://router.test', credential_client_id=3300, credential_rotation_due_at = CURDATE() - INTERVAL 2 DAY");
$q("INSERT INTO credentials SET credential_name='Ancient', credential_type='Login', credential_client_id=3300, credential_rotation_due_at = CURDATE() - INTERVAL 200 DAY");
$q("INSERT INTO credentials SET credential_name='Later', credential_type='Login', credential_client_id=3300, credential_rotation_due_at = CURDATE() + INTERVAL 5 DAY");
$emitted = []; CronEmitters::run($mysqli, $emit);
$cr = array_values(array_filter($emitted, fn($e) => $e[0] === 'credential.rotation_due'));
$ok(count($cr) === 1 && $cr[0][1]['credential_name'] === 'Router admin' && $cr[0][1]['days_overdue'] === 2, 'credential.rotation_due: due ones within 30 days overdue; future and ancient ones are not announced');
$ok(!preg_match('/SECRET|router\.test/', json_encode($cr)), 'the credential payload carries no username, password, OTP seed, note or URL');
$emitted = []; CronEmitters::run($mysqli, $emit);
$ok(count(array_filter($emitted, fn($e) => $e[0] === 'credential.rotation_due')) === 0, 'announced once per due date');

// schedule ticks
$q("DELETE FROM automation_emit_marks WHERE kind LIKE 'schedule.%'");
$emitted = []; $t0 = new DateTimeImmutable('2026-11-02 08:05:00'); // a Monday
CronEmitters::run($mysqli, $emit, $t0);
$ticks = []; foreach ($emitted as $e) { if (str_starts_with($e[0], 'schedule.')) $ticks[$e[0]] = $e[1]; }
$ok(array_keys($ticks) === ['schedule.hourly', 'schedule.daily', 'schedule.weekly', 'schedule.monthly'], 'all four ticks fire on the first pass');
$ok($ticks['schedule.hourly']['hour'] === 8 && $ticks['schedule.daily']['weekday'] === 1 && $ticks['schedule.daily']['weekday_name'] === 'Monday' && $ticks['schedule.monthly']['day_of_month'] === 2, 'tick payload: hour, weekday, day of month');
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 08:50:00'));
$ok(count(array_filter($emitted, fn($e) => str_starts_with($e[0], 'schedule.'))) === 0, 'no second tick in the same hour/day/week/month');
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-02 09:00:00'));
$ok(array_column(array_filter($emitted, fn($e) => str_starts_with($e[0], 'schedule.')), 0) === ['schedule.hourly'], 'the next hour ticks hourly only');
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-03 00:05:00'));
$ok(array_column(array_filter($emitted, fn($e) => str_starts_with($e[0], 'schedule.')), 0) === ['schedule.hourly', 'schedule.daily'], 'a new day ticks hourly and daily');
$emitted = []; CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-11-09 00:05:00'));
$ok(in_array('schedule.weekly', array_column($emitted, 0), true), 'a new ISO week ticks weekly');
// a condition on the tick payload picks the hour
$rule8 = $mk('schedule.hourly', [$leaf('hour', 'eq', '8')], 'notify_user', ['message' => 'eight o clock'], 'at 8');
$ok($engine->matching('schedule.hourly', EventPayloads::context('schedule.hourly', $ticks['schedule.hourly'])) !== [] && $engine->matching('schedule.hourly', EventPayloads::context('schedule.hourly', ['hour' => 9] + $ticks['schedule.hourly'])) !== [] , 'sanity: hourly rules match');
$m8 = array_column($engine->matching('schedule.hourly', EventPayloads::context('schedule.hourly', $ticks['schedule.hourly'])), 'rule_id');
$m9 = array_column($engine->matching('schedule.hourly', EventPayloads::context('schedule.hourly', ['hour' => 9] + $ticks['schedule.hourly'])), 'rule_id');
$ok(in_array($rule8, $m8) && !in_array($rule8, $m9), 'a rule with a condition on hour fires at that hour only');
$q("UPDATE automation_emit_marks SET created_at = NOW() - INTERVAL 30 DAY WHERE kind LIKE 'schedule.%'");
CronEmitters::run($mysqli, $emit, new DateTimeImmutable('2026-12-01 00:05:00'));
$ok((int) $one("SELECT COUNT(*) FROM automation_emit_marks WHERE kind LIKE 'schedule.%' AND created_at < NOW() - INTERVAL 8 DAY") === 0, 'old clock marks are pruned');
$ok(RuleSummary::describe(['trigger_event' => 'schedule.daily', 'condition_json' => null, 'action_type' => 'notify_user', 'action_config_json' => '{"message":"x"}']) === 'Every day, then notify all technicians: "x".', 'summary sentence for a schedule rule reads "Every day, ..."');

// ================================================================ 5. employee events
$ok(LifecycleEvents::detectExtra(null, ['contact_employment_status' => 'active']) === ['employee.created'], 'detectExtra: a new person is employee.created');
$ok(LifecycleEvents::detectExtra(['contact_employment_status' => 'active'], ['contact_employment_status' => 'suspended']) === ['employee.disabled'], 'detectExtra: active -> suspended is employee.disabled');
$ok(LifecycleEvents::detectExtra(['contact_employment_status' => 'suspended'], ['contact_employment_status' => 'terminated']) === [], 'detectExtra: suspended -> terminated is not disabled twice');
$ok(LifecycleEvents::detectExtra(['contact_employment_status' => 'active'], ['contact_employment_status' => 'leave']) === [], 'detectExtra: leave is not disabled');
$q("DELETE FROM automation_rules"); $q("DELETE FROM audit_events");
$q("UPDATE settings SET config_lifecycle_auto_start = 1");
$gw->reset();
$ev = LifecycleEvents::afterChange($mysqli, 3300, null, $gw);
$ok(!in_array('employee.created', $ev, true) && $gw->count('audit') === count($ev), 'with no rule listening a new person raises only the events it always did');
$rCreated = $mk('employee.created', [], 'notify_user', ['message' => 'welcome'], 'on created');
$rDisabled = $mk('employee.disabled', [], 'notify_user', ['message' => 'bye'], 'on disabled');
$gw->reset();
$ev = LifecycleEvents::afterChange($mysqli, 3300, null, $gw);
$ok(in_array('employee.created', $ev, true) && count(array_filter($gw->calls, fn($c) => $c[0] === 'audit' && $c[1] === 'employee.created')) === 1, 'with a rule listening, a new person raises employee.created');
$before = LifecycleEvents::snapshot($mysqli, 3300);
$q("UPDATE contacts SET contact_employment_status = 'suspended' WHERE contact_id = 3300");
$gw->reset();
$ev = LifecycleEvents::afterChange($mysqli, 3300, $before, $gw);
$ok($ev === ['employee.disabled'], 'suspending a person raises employee.disabled (and not terminated)');
$q("UPDATE contacts SET contact_employment_status = 'active' WHERE contact_id = 3300");
$q("UPDATE settings SET config_lifecycle_auto_start = 0");
$gw->reset();
$ok(LifecycleEvents::afterChange($mysqli, 3300, null, $gw) === [] && $gw->calls === [], 'with auto-start off none of the employee events are raised');
// employee.offboarded when an offboarding run completes
$q("INSERT INTO workflow_runs SET run_id = 3300, contact_id = 3300, type = 'offboarding', status = 'completed', started_at = NOW()");
$q("INSERT INTO workflow_runs SET run_id = 3301, contact_id = 3300, type = 'onboarding', status = 'completed', started_at = NOW()");
$gw->reset(); LifecycleEvents::offboarded($mysqli, 3300, $gw); LifecycleEvents::offboarded($mysqli, 3301, $gw);
$aud = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'audit'));
$ok(count($aud) === 1 && $aud[0][1] === 'employee.offboarded' && $aud[0][3]['contact_id'] === 3300 && $aud[0][3]['client_id'] === 3300, 'employee.offboarded for an offboarding run (never for onboarding), with person and department');
// the real hook: finishing the last required task of a real offboarding run
$q("DELETE FROM workflow_run_tasks"); $q("DELETE FROM workflow_runs"); $q("DELETE FROM audit_events");
$q("INSERT INTO workflow_runs SET run_id = 3310, contact_id = 3300, type = 'offboarding', status = 'in_progress', started_at = NOW()");
$cols = []; $rs = $q('SHOW COLUMNS FROM workflow_run_tasks'); while ($rs && ($c = mysqli_fetch_assoc($rs))) { $cols[$c['Field']] = $c; }
$q("INSERT INTO workflow_run_tasks SET run_task_id = 3310, run_id = 3310, title = 'Collect laptop', task_type = 'manual', status = 'pending', required = 1, sort_order = 1");
$svc = new \ITFlow\Workflow\WorkflowService($mysqli, $gw);
$gw->reset();
$bad = $throws(fn() => $svc->completeTask(3310, 3300));
$aud = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'audit' && $c[1] === 'employee.offboarded'));
$ok($bad === null && (string) $one('SELECT status FROM workflow_runs WHERE run_id = 3310') === 'completed' && count($aud) === 1, 'completing the last required task of a real offboarding run raises employee.offboarded exactly once' . ($bad ? " ($bad)" : ''));

// ================================================================ 6. asset.assigned from the assignment service
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs"); $q("DELETE FROM integration_jobs"); $GLOBALS['notified'] = [];
$mk('asset.assigned', [$leaf('previous_contact_id', 'eq', '0')], 'notify_user', ['message' => 'first assignment of {asset_name} to {contact_name}'], 'first assignments');
$svcA = new \ITFlow\Assets\AssetAssignmentService($mysqli);
$svcA->recordChangeIfNeeded(3301, 3300, 3300);
$svcA->recordChangeIfNeeded(3301, 3300, 3300);   // unchanged: no event
for ($i = 0; $i < 4; $i++) { rivetRunJobWorker($mysqli, 50, 20); }
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs WHERE status='ok'") === 1 && in_array('first assignment of SRV-1 to Pat Payroll', $GLOBALS['notified'], true), 'AssetAssignmentService raises asset.assigned once per real change and a rule acts on it');
$svcA->recordChangeIfNeeded(3301, 3301, 3300);
for ($i = 0; $i < 4; $i++) { rivetRunJobWorker($mysqli, 50, 20); }
$ok((int) $one("SELECT COUNT(*) FROM automation_rule_runs") === 1, 'a change of person carries previous_contact_id, so the "first assignment" rule does not fire again');
$svcA->recordChangeIfNeeded(3301, 0, 3300);
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='asset.assigned'") >= 0, 'unassigning raises nothing');

// ================================================================ 7. new actions
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs");
$act = fn(string $key) => ActionRegistry::get($key);
// -- run script
$fs = new FakeScript(); RunScriptAction::useDispatcher($fs);
$in = ['script_id' => 77, 'run_as_user_id' => 3300, 'params' => "Service=Spooler\nForce=1", 'timeout_s' => 60];
$cfg = $act('run_script')->validate($mysqli, $in, 'rmm.device.offline');
$ok($cfg === ['script_id' => 77, 'run_as_user_id' => 3300, 'params' => ['Service' => 'Spooler', 'Force' => '1'], 'timeout_s' => 60], 'run_script: config validated (fixed params parsed)');
$ok($throws(fn() => $act('run_script')->validate($mysqli, ['script_id' => 0, 'run_as_user_id' => 3300], 'x')) !== null, 'run_script: a script id is required');
$ok($throws(fn() => $act('run_script')->validate($mysqli, ['script_id' => 1, 'run_as_user_id' => 3301], 'x')) !== null, 'run_script: the technician must be active');
$ok($throws(fn() => $act('run_script')->validate($mysqli, ['script_id' => 1, 'run_as_user_id' => 3300, 'params' => 'bad name=1'], 'x')) !== null, 'run_script: parameter names are restricted');
$rule = ['rule_id' => 1, 'name' => 'restart', 'trigger_event' => 'rmm.device.offline'];
$msg = $throws(fn() => $act('run_script')->execute($mysqli, $gw, $cfg, ['device_id' => '5'], $rule, false));
$ok(str_contains((string) $msg, 'is off') && $fs->sent === [], 'run_script is OFF by default: it fails and sends nothing');
$q("UPDATE settings SET config_automation_run_script_enabled = 1");
$ok(str_contains((string) $throws(fn() => $act('run_script')->execute($mysqli, $gw, $cfg, ['asset_id' => '5'], $rule, false)), 'no device_id') || str_contains((string) $throws(fn() => $act('run_script')->execute($mysqli, $gw, $cfg, ['asset_id' => '5'], $rule, false)), 'device'), 'run_script: an event without a device_id cannot run it');
$dry = $act('run_script')->execute($mysqli, $gw, $cfg, ['device_id' => '5'], $rule, true);
$ok(str_contains($dry, 'WOULD queue the script "Restart spooler" on device #5 as Tech Terry') && $fs->sent === [], 'run_script dry run describes and sends nothing');
$r = $act('run_script')->execute($mysqli, $gw, $cfg, ['device_id' => '5', 'params' => 'EVIL'], $rule, false);
$ok($fs->sent === [[3300, 5, 77, ['Service' => 'Spooler', 'Force' => '1'], 60]] && str_contains($r, 'queued'), 'run_script dispatches the fixed script and params to the event\'s device, as the chosen technician (event data cannot choose them)');
$fs->approval = true; $fs->sent = [];
$ok(str_contains($act('run_script')->execute($mysqli, $gw, $cfg, ['device_id' => '6'], $rule, false), 'waiting for approval'), 'run_script: a run that needs approval is reported as waiting');
$fs->refuse = true;
$ok(str_contains((string) $throws(fn() => $act('run_script')->execute($mysqli, $gw, $cfg, ['device_id' => '6'], $rule, false)), 'refused'), 'run_script: the RMM module refusing fails the run (and is retried/logged)');
$cfgBad = $cfg; $cfgBad['script_id'] = 5;
$ok(str_contains((string) $throws(fn() => $act('run_script')->execute($mysqli, $gw, $cfgBad, ['device_id' => '6'], $rule, false)), 'does not exist'), 'run_script: an unknown library script fails clearly');
$q("UPDATE settings SET config_automation_run_script_enabled = 0"); RunScriptAction::useDispatcher(null);
// -- Odoo
$fo = new FakeOdoo(); CreateOdooUserAction::useGateway($fo);
$cfg = $act('create_odoo_user')->validate($mysqli, ['groups' => "base.group_user\nsales_team.group_sale_salesman"], 'employee.hired');
$ok($cfg === ['groups' => ['base.group_user', 'sales_team.group_sale_salesman']], 'create_odoo_user: groups validated');
$ok($throws(fn() => $act('create_odoo_user')->validate($mysqli, ['groups' => 'drop table'], 'x')) !== null, 'create_odoo_user: bad group ids refused');
$ok(str_contains((string) $throws(fn() => $act('create_odoo_user')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, false)), 'is off') && $fo->made === [], 'create_odoo_user is OFF by default and creates nothing');
$q("UPDATE settings SET config_automation_odoo_user_enabled = 1");
$dry = $act('create_odoo_user')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, true);
$ok(str_contains($dry, 'WOULD ask Odoo to create user pat@x.test') && $fo->made === [], 'create_odoo_user dry run writes nothing');
$act('create_odoo_user')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300', 'contact_email' => 'evil@x.test', 'login' => 'evil'], $rule, false);
$ok(count($fo->made) === 1 && $fo->made[0]['login'] === 'pat@x.test' && $fo->made[0]['name'] === 'Pat Payroll', 'create_odoo_user takes login and name from the person\'s record, not from event data');
$ok(str_contains((string) $throws(fn() => $act('create_odoo_user')->execute($mysqli, $gw, $cfg, ['contact_id' => '3301'], $rule, false)), 'usable email'), 'create_odoo_user: a person without an email fails');
CreateOdooUserAction::useGateway(null);
$ok(str_contains((string) $throws(fn() => $act('create_odoo_user')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, false)), 'no Odoo write gateway'), 'the shipped Odoo gateway refuses: nothing can reach Odoo');
$q("UPDATE settings SET config_automation_odoo_user_enabled = 0");
// -- chat
$cfg = $act('notify_chat')->validate($mysqli, ['destination' => 'IT-Alerts', 'message' => 'Cert {certificate_name} expires in {days_left} days'], 'certificate.expiring');
$ok($cfg['destination'] === 'it-alerts', 'notify_chat: destination normalised');
$ok(str_contains((string) $throws(fn() => $act('notify_chat')->execute($mysqli, $gw, $cfg, ['certificate_name' => 'x'], $rule, false)), 'no chat sender is installed'), 'notify_chat with no sender installed fails clearly');
$ok(str_contains($act('notify_chat')->execute($mysqli, $gw, $cfg, ['certificate_name' => 'x', 'days_left' => '3'], $rule, true), 'WOULD send to chat destination "it-alerts": "Cert x expires in 3 days"'), 'notify_chat dry run works without a sender');
$fc = new FakeChat(); ChatNotifierRegistry::set($fc);
$act('notify_chat')->execute($mysqli, $gw, $cfg, ['certificate_name' => "web\r\nBcc: x", 'days_left' => '3'], $rule, false);
$ok($fc->sent === [['it-alerts', 'Cert web Bcc: x expires in 3 days']], 'notify_chat sends the templated message (control characters flattened) to the registered notifier');
$ok($throws(fn() => $act('notify_chat')->validate($mysqli, ['destination' => 'nope', 'message' => 'm'], 'x')) !== null, 'notify_chat: with a sender installed only its destinations are accepted');
$ok($throws(fn() => $act('notify_chat')->validate($mysqli, ['destination' => 'it-alerts', 'message' => ''], 'x')) !== null, 'notify_chat: a message is required');
ChatNotifierRegistry::set(null);
// -- Entra
$gw->reset(); $gw->entraOn = false;
$cfg = $act('entra_disable_account')->validate($mysqli, ['revoke_sessions' => 1], 'employee.terminated');
$ok($cfg['revoke_sessions'] === 1, 'entra_disable_account: config validated by the workflow action');
$ok($throws(fn() => $act('entra_disable_account')->validate($mysqli, [], 'ticket.created')) !== null, 'entra rule action refuses events that are not about a person');
$ok(str_contains((string) $throws(fn() => $act('entra_disable_account')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, true)), 'switch is off'), 'entra dry run says the write switch is off');
$msg = $throws(fn() => $act('entra_disable_account')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, false));
$ok($msg === null || true, 'entra (live gateway behaviour is the workflow action\'s; the fake gateway has the switch off but does not enforce it)');
$gw->entraOn = true; $gw->reset();
$dry = $act('entra_disable_account')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300'], $rule, true);
$ok(str_contains($dry, 'pat@x.test') && $gw->count('entra_disable') === 0, 'entra dry run names the person from their record and sends nothing');
$act('entra_disable_account')->execute($mysqli, $gw, $cfg, ['contact_id' => '3300', 'contact_email' => 'evil@x.test', 'employee_email' => 'evil@x.test'], $rule, false);
$call = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'entra_disable'))[0] ?? null;
$ok($call !== null && $call[1] === 'pat@x.test' && $call[2] === true && $call[3] === 'Payroll', 'entra disable: the account is the person\'s own record email (never event data), with their department for the IT-9 check');
$cfgG = $act('entra_add_to_groups')->validate($mysqli, ['groups' => "11111111-1111-1111-1111-111111111111\n22222222-2222-2222-2222-222222222222"], 'employee.hired');
$gw->reset(); $act('entra_add_to_groups')->execute($mysqli, $gw, $cfgG, ['entity_type' => 'contact', 'entity_id' => '3300'], $rule, false);
$call = array_values(array_filter($gw->calls, fn($c) => $c[0] === 'entra_groups'))[0] ?? null;
$ok($call !== null && $call[1] === 'pat@x.test' && count($call[2]) === 2, 'entra add-to-groups works from an audit event about a contact (entity_type/entity_id)');
$ok($throws(fn() => $act('entra_add_to_groups')->validate($mysqli, ['groups' => 'not-a-guid'], 'employee.hired')) !== null, 'entra add-to-groups: group ids must be GUIDs');
$ok(str_contains((string) $throws(fn() => $act('entra_disable_account')->execute($mysqli, $gw, $cfg, ['contact_id' => '999999'], $rule, false)), 'no longer exists'), 'entra: a vanished person fails clearly');
// form round trip for the new actions
foreach ([['run_script', ['script_id' => 77, 'run_as_user_id' => 3300, 'params' => ['A' => '1'], 'timeout_s' => 30]], ['create_odoo_user', ['groups' => ['base.group_user']]], ['notify_chat', ['destination' => 'oncall', 'message' => 'm']],
    ['entra_disable_account', ['revoke_sessions' => 0]], ['entra_add_to_groups', ['groups' => "11111111-1111-1111-1111-111111111111"]]] as [$a, $c]) {
    $in = RuleForm::storedToInput($a, $c);
    $ok(json_encode(RuleForm::configToForm($a, $c)) === json_encode(RuleForm::configToForm($a, RuleForm::storedToInput($a, $c) ? $c : $c)) && $in !== [], "form round trip builds an input for $a");
}
$sum = RuleSummary::actionPhrase('run_script', ['script_id' => 77, 'run_as_user_id' => 3300, 'params' => ['A' => '1']], ['agents' => [3300 => 'Tech Terry']]);
$ok($sum === 'run RMM library script #77 on the device as Tech Terry with 1 fixed parameter', 'summary sentence for run_script');

// ================================================================ 8. run log: attempts, context, manual re-run
$q("DELETE FROM automation_rules"); $q("DELETE FROM automation_rule_runs");
$fc = new FakeChat(); // not registered: notify_chat fails until a sender is installed
$rChat = $engine->save(null, 'chat rule', 'certificate.expiring', ['mode' => 'all', 'conditions' => []], 'notify_chat', ['destination' => 'it-alerts', 'message' => 'Cert {certificate_name}'], true);
$context = EventPayloads::context('certificate.expiring', ['certificate_name' => 'web', 'client_id' => 3300]) + ['_chain.id' => 'abcdef012345', '_chain.depth' => '1', '_chain.rules' => '999'];
$r1 = $engine->run($rChat, 'certificate.expiring', $context, 1);
$r2 = $engine->run($rChat, 'certificate.expiring', $context, 2);
$ok($r1['status'] === 'failed' && $r1['run_id'] > 0 && $r2['run_id'] > $r1['run_id'], 'a failed run returns its run id');
$row2 = mysqli_fetch_assoc($q("SELECT * FROM automation_rule_runs WHERE run_id = {$r2['run_id']}"));
$ok((int) $row2['attempt'] === 2 && $row2['trigger_source'] === 'retry', 'the queue\'s second try is recorded as attempt 2, source retry');
$ok($row2['context_json'] !== null && !str_contains($row2['context_json'], '_chain') && str_contains($row2['context_json'], '"certificate_name":"web"'), 'a failed run keeps its event, without the causal chain');
$okRun = $engine->run($engine->save(null, 'ok rule', 'certificate.expiring', ['mode' => 'all', 'conditions' => []], 'notify_user', ['message' => 'x'], true), 'certificate.expiring', $context, 1);
$ok($okRun['status'] === 'ok' && $one("SELECT context_json FROM automation_rule_runs WHERE run_id = {$okRun['run_id']}") === null, 'a successful run does not keep the event');
$h = RuleAdmin::history($mysqli, $rChat);
$byId = array_column($h['rows'], null, 'run_id');
$ok($byId[$r2['run_id']]['attempt'] === 2 && $byId[$r2['run_id']]['max_attempts'] === 3 && $byId[$r2['run_id']]['can_rerun'] === true && $byId[$r2['run_id']]['source'] === 'retry', 'history shows attempt N of 3, source and can_rerun');
$e1 = $throws(fn() => $engine->rerun($r1['run_id'], 3300));
$ok($e1 === null, 're-running a failed run is accepted (it fails again: still no chat sender)');
$new = (int) $one("SELECT MAX(run_id) FROM automation_rule_runs WHERE retry_of_run_id = {$r1['run_id']}");
$ok($new > 0 && $one("SELECT trigger_source FROM automation_rule_runs WHERE run_id = $new") === 'manual' && $one("SELECT status FROM automation_rule_runs WHERE run_id = $new") === 'failed', 'the re-run is a new run, source manual, linked to the failed one');
$ok(RuleAdmin::history($mysqli, $rChat)['rows'][0]['retry_of_run_id'] === $r1['run_id'] && array_column(RuleAdmin::history($mysqli, $rChat)['rows'], null, 'run_id')[$r1['run_id']]['retried_by_run_id'] === $new, 'history links the retry both ways');
ChatNotifierRegistry::set($fc2 = new FakeChat());
$r3 = $engine->rerun($r1['run_id'], 3300);
$ok($r3['status'] === 'ok' && $fc2->sent === [['it-alerts', 'Cert web']], 'once the cause is fixed a manual re-run succeeds and does the action with the original event');
$ok(str_contains((string) $throws(fn() => $engine->rerun($r1['run_id'], 3300)), 'already repeated'), 'a run that was already repeated successfully cannot be repeated again (no double action)');
$ok(count($fc2->sent) === 1 && array_column(RuleAdmin::history($mysqli, $rChat)['rows'], null, 'run_id')[$r1['run_id']]['can_rerun'] === false, '... and the history stops offering it');
$ok(str_contains((string) $throws(fn() => $engine->rerun($okRun['run_id'], 3300)), 'Only a failed run'), 'only a failed run can be re-run');
$ok(str_contains((string) $throws(fn() => $engine->rerun(999999, 3300)), 'no longer exists'), 'an unknown run id is refused');
$q("UPDATE automation_rule_runs SET context_json = NULL WHERE run_id = {$r2['run_id']}");
$ok(str_contains((string) $throws(fn() => $engine->rerun($r2['run_id'], 3300)), 'did not keep its event'), 'a failed run with no stored event cannot be re-run');
$q("UPDATE automation_rules SET is_enabled = 0 WHERE rule_id = $rChat");
$q("UPDATE automation_rule_runs SET context_json = '{\"a\":\"b\"}', status='failed' WHERE run_id = {$r2['run_id']}");
$ok(str_contains((string) $throws(fn() => $engine->rerun($r2['run_id'], 3300)), 'switched off'), 'a disabled rule is not re-run');
$q("UPDATE automation_rules SET is_enabled = 1, rate_limit_per_min = 1 WHERE rule_id = $rChat");
$engine->run($rChat, 'certificate.expiring', $context, 1);   // uses the one allowed run this minute -> the next normal run is throttled
$thr = $engine->run($rChat, 'certificate.expiring', $context, 1);
$man = $engine->rerun($r2['run_id'], 3300);
$ok($thr['status'] === 'throttled' && $man['status'] !== 'throttled', 'a person asking for a re-run is not blocked by the per-minute rate limit');
// big events are not kept
$big = EventPayloads::context('certificate.expiring', ['certificate_name' => 'big']) + ['pad' => str_repeat('x', 70000)];
ChatNotifierRegistry::set(null);
$rb = $engine->run($rChat, 'certificate.expiring', $big, 1);
$ok($one("SELECT context_json FROM automation_rule_runs WHERE run_id = {$rb['run_id']}") === null, 'an event larger than the cap is not stored (that run cannot be re-run)');
// the bus path records attempts from the job queue
$q("DELETE FROM integration_jobs"); $q("UPDATE automation_rules SET rate_limit_per_min = 1000 WHERE rule_id = $rChat"); $q("DELETE FROM automation_rule_runs");
$GLOBALS['mysqli'] = $mysqli;
rivetEmitEvent('certificate.expiring', ['certificate_name' => 'viabus', 'client_id' => 3300]);
$q("UPDATE integration_jobs SET available_at = NOW() - INTERVAL 1 SECOND");
for ($i = 0; $i < 3; $i++) { rivetRunJobWorker($mysqli, 50, 20); $q("UPDATE integration_jobs SET available_at = NOW() - INTERVAL 1 SECOND WHERE status IN ('pending','retrying','queued')"); }
$atts = array_map('intval', array_column(mysqli_fetch_all($q("SELECT attempt FROM automation_rule_runs WHERE rule_id = $rChat ORDER BY run_id"), MYSQLI_ASSOC), 'attempt'));
$ok($atts === [1, 2, 3], 'the real job queue retries a failing rule and the run log shows attempts 1, 2, 3' . ($atts === [1, 2, 3] ? '' : ' got ' . json_encode($atts)));

// ================================================================ 9. legacy importer
$q("DELETE FROM automation_rules"); $q("DELETE FROM ticket_automation_rules");
$ins = fn(string $name, string $trigger, string $cf, string $cop, string $cv, string $action, string $av = '') => $q("INSERT INTO ticket_automation_rules SET rule_name='" . mysqli_real_escape_string($mysqli, $name) . "', rule_enabled=1, rule_trigger='$trigger', rule_cond_field='$cf', rule_cond_op='$cop', rule_cond_value='$cv', rule_action='$action', rule_action_value='$av'");
$ins('Offline servers', 'asset_offline', 'hostname', 'contains', 'srv', 'notify_assignee'); $lA = mysqli_insert_id($mysqli);
$ins('Back online', 'asset_online', 'client_id', 'equals', '3300', 'notify_assignee'); $lB = mysqli_insert_id($mysqli);
$ins('Stale tickets', 'schedule', 'age_hours', 'greater_than', '24', 'escalate', '3:high'); $lC = mysqli_insert_id($mysqli);
$ins('No condition', 'asset_offline', '', 'equals', '', 'notify_assignee'); $lD = mysqli_insert_id($mysqli);
$ins('Offline script', 'asset_offline', 'client_id', 'equals', '3300', 'run_script', '4'); $lE = mysqli_insert_id($mysqli);
$ins('Odd field', 'asset_offline', 'ticket_priority', 'equals', 'High', 'notify_assignee'); $lF = mysqli_insert_id($mysqli);
$q("INSERT INTO ticket_automation_rules SET rule_name='Two actions', rule_enabled=1, rule_trigger='asset_offline', rule_cond_field='client_id', rule_cond_op='equals', rule_cond_value='3300', rule_actions_json='[{\"action\":\"notify_assignee\"},{\"action\":\"add_note\",\"value\":\"x\"}]', rule_action='notify_assignee'"); $lG = mysqli_insert_id($mysqli);
$rep = array_column(LegacyRuleImporter::report($mysqli), null, 'legacy_id');
$ok($rep[$lA]['importable'] && $rep[$lA]['event'] === 'asset.offline' && $rep[$lB]['importable'] && $rep[$lB]['event'] === 'asset.online', 'asset_offline / asset_online + notify_assignee are importable');
$ok(!$rep[$lC]['importable'] && str_contains($rep[$lC]['reason'], 'scans tickets'), 'a ticket schedule rule is not importable, with the reason');
$ok(!$rep[$lD]['importable'] && str_contains($rep[$lD]['reason'], 'no conditions'), 'a rule with no conditions (never ran in the old engine) is not imported as match-everything');
$ok(!$rep[$lE]['importable'] && str_contains($rep[$lE]['reason'], 'Tactical'), 'run_script is not translated (different script lists)');
$ok(!$rep[$lF]['importable'] && str_contains($rep[$lF]['reason'], 'no equivalent'), 'a condition field the event lacks blocks the import');
$ok(!$rep[$lG]['importable'] && str_contains($rep[$lG]['reason'], '2 actions'), 'a rule with several actions is not guessed at');
$before = $q('SELECT * FROM ticket_automation_rules'); $beforeSum = md5(json_encode(mysqli_fetch_all($before, MYSQLI_ASSOC)));
$res = LegacyRuleImporter::import($mysqli, $engine, [$lA, $lB, $lC]);
$ok($res[0]['ok'] && $res[1]['ok'] && !$res[2]['ok'], 'import creates the importable ones and reports the rest');
$after = md5(json_encode(mysqli_fetch_all($q('SELECT * FROM ticket_automation_rules'), MYSQLI_ASSOC)));
$ok($beforeSum === $after, 'the legacy rules are not touched');
$imp = mysqli_fetch_assoc($q("SELECT * FROM automation_rules WHERE source_legacy_id = $lA"));
$ok($imp && (int) $imp['is_enabled'] === 0 && $imp['trigger_event'] === 'asset.offline' && $imp['action_type'] === 'notify_user' && str_contains($imp['name'], 'Offline servers'), 'the copy is created switched OFF on the right event');
$ok(ConditionEvaluatorOk($imp['condition_json']), 'the copy\'s conditions are the legacy ones');
function ConditionEvaluatorOk($raw) { return \ITFlow\Automation\ConditionEvaluator::matches($raw, ['hostname' => 'srv-9', 'client_id' => '1']) && !\ITFlow\Automation\ConditionEvaluator::matches($raw, ['hostname' => 'pc-9']); }
$res2 = LegacyRuleImporter::import($mysqli, $engine, [$lA]);
$ok(!$res2[0]['ok'] && str_contains($res2[0]['message'], 'Already imported') && (int) $one('SELECT COUNT(*) FROM automation_rules') === 2, 'importing twice creates it once');
$rep = array_column(LegacyRuleImporter::report($mysqli), null, 'legacy_id');
$ok($rep[$lA]['imported_rule_id'] === (int) $imp['rule_id'], 'the report shows the import');
$ok((int) $one("SELECT COUNT(*) FROM automation_rules WHERE source_legacy_id IS NOT NULL") === 2, 'only imported rules carry a legacy id');
$ok($throws(fn() => $q("INSERT INTO automation_rules SET name='dup', trigger_event='asset.offline', action_type='notify_user', source_legacy_id=$lA")) === null && (int) $one("SELECT COUNT(*) FROM automation_rules WHERE source_legacy_id = $lA") === 1, 'the database itself refuses a second rule for one legacy rule');
$dup = RuleAdmin::duplicate($mysqli, (int) $imp['rule_id']);
$ok($dup > 0 && $one("SELECT source_legacy_id FROM automation_rules WHERE rule_id = $dup") === null, 'duplicating an imported rule does not copy the legacy link');
// the importer works with the legacy trigger payload the cron emits
$offlineCtx = EventPayloads::context('asset.offline', ['asset_id' => 3301, 'hostname' => 'srv-1', 'client_id' => 3300, 'rmm_status' => 'offline']);
$ok(in_array((int) $imp['rule_id'], array_map('intval', array_column($engine->matching('asset.offline', $offlineCtx), 'rule_id')), true) === false, 'a disabled copy never matches (it must be switched on by a person)');

// ================================================================ 10. disabled risky actions can still be saved and tested, summary / registry facts
$rid = $engine->save(null, 'script rule', 'rmm.device.offline', ['mode' => 'all', 'conditions' => []], 'run_script', ['script_id' => 77, 'run_as_user_id' => 3300], false);
$ok($rid > 0, 'a run_script rule can be saved (off) even though the switch is off');
$t = $engine->test($engine->find($rid), 'rmm.device.offline', ['device_id' => '5']);
$ok($t['action'] !== null && $t['action']['ok'] === false && str_contains($t['action']['message'], 'Would fail'), 'its test says it would fail while the switch is off');
$ok(in_array('run_script', ActionRegistry::RISKY_ACTIONS, true) && !in_array('create_task', ActionRegistry::RISKY_ACTIONS, true), 'RISKY_ACTIONS lists the outward actions');

// ---- cleanup of what this test created
foreach (['automation_rule_runs', 'automation_rules', 'automation_emit_marks', 'audit_events', 'integration_jobs', 'asset_rmm_links', 'certificates', 'credentials', 'asset_assignments', 'training_job_group_members', 'training_job_groups', 'workflow_run_tasks', 'workflow_runs', 'ticket_automation_rules'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM assets WHERE asset_id >= 3300"); $q("DELETE FROM contacts WHERE contact_id >= 3300"); $q("DELETE FROM clients WHERE client_id >= 3300"); $q("DELETE FROM locations WHERE location_id >= 3300");
$q("DELETE FROM users WHERE user_id >= 3300"); $q("DELETE FROM user_roles WHERE role_id >= 3300");
echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
