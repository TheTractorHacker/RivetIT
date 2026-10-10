<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Recovery (platform gap items 9, 11): backup status + alerts, stale/sync watchers, the restore drill, its Compliance check.
 * Part 1 is pure (no database). Part 2 needs a THROWAWAY MariaDB with the full migrated schema (it never reads config.php) AND a
 * second account that may only touch drill_% databases:
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *   RIVETIT_TEST_DRILL_USER=... RIVETIT_TEST_DRILL_PASS=... [RIVETIT_TEST_DB_SOCKET=/path/s.sock] php tests/recovery_drill.php
 *
 * Without RIVETIT_TEST_DB only Part 1 runs. The drill account is created like this (and is exactly what docs/RECOVERY_RUNBOOK.md says):
 *   CREATE USER 'drill'@'localhost' IDENTIFIED BY '...'; GRANT ALL ON `drill\_%`.* TO 'drill'@'localhost';
 */
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Recovery\BackupStatus;
use ITFlow\Recovery\DrillVerifier as V;
use ITFlow\Recovery\RecoveryAlerts;
use ITFlow\Recovery\RecoverySettings;
use ITFlow\Recovery\RestoreDrill;
use ITFlow\Recovery\SyncWatch;
use ITFlow\Recovery\TableSnapshot;

$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };
$st = fn (array $checks, string $id) => (array_values(array_filter($checks, fn ($c) => $c['id'] === $id))[0]['status'] ?? 'missing');

// ---------------------------------------------------------------- Part 1: pure rules
$vt = "RivetIT Backup Metadata\nGenerated: 2026-10-09 02:30:00\nType: auto\nGit Commit: abc\nRivetIT Version: 26.10.29\nDB Version: 2.6.153\n"
    . "SHA256 db.sql: " . str_repeat('a', 64) . "\nSHA256 uploads.zip: " . str_repeat('b', 64) . "\nTraining ledger head: #12 " . str_repeat('c', 64) . "\nManifest: x\n";
$p = V::parseVersionTxt($vt);
$ok($p['db_version'] === '2.6.153' && $p['version'] === '26.10.29' && $p['sha_db'] === str_repeat('a', 64) && $p['sha_uploads'] === str_repeat('b', 64)
    && $p['ledger_seq'] === 12 && $p['ledger_hash'] === str_repeat('c', 64) && $p['generated'] === '2026-10-09 02:30:00', 'version.txt parsed (db version, hashes, ledger anchor)');
$ok(V::parseVersionTxt('DB Version: N/A')['db_version'] === null, 'N/A db version is treated as unknown');
$c = V::checkChecksums($p, str_repeat('a', 64), str_repeat('b', 64));
$ok($st($c, 'sha_db') === 'pass' && $st($c, 'sha_uploads') === 'pass', 'matching checksums pass');
$c = V::checkChecksums($p, str_repeat('d', 64), str_repeat('b', 64));
$ok($st($c, 'sha_db') === 'fail' && $st($c, 'sha_uploads') === 'pass', 'a db.sql hash mismatch fails');
$ok($st(V::checkChecksums($p, null, null), 'sha_db') === 'fail', 'an unreadable db.sql fails');
$ok($st(V::checkChecksums(V::parseVersionTxt(''), 'x', 'y'), 'sha_db') === 'skip', 'no recorded hash is skipped, not failed');
$ok(V::checkSchemaVersion('2.6.153', '2.6.153', '2.6.160')['status'] === 'pass', 'schema version equal passes (older than live is fine)');
$ok(V::checkSchemaVersion('2.6.153', '2.6.150', null)['status'] === 'fail', 'schema version differing from version.txt fails');
$ok(V::checkSchemaVersion('2.6.153', null, null)['status'] === 'fail', 'no settings row fails');
$ok(V::checkSchemaVersion(null, '2.6.153', '2.6.153')['status'] === 'pass' && V::checkSchemaVersion(null, '2.6.150', '2.6.153')['status'] === 'pass'
    && V::checkSchemaVersion(null, '2.6.160', '2.6.153')['status'] === 'warn' && V::checkSchemaVersion(null, '2.6.153', null)['status'] === 'skip', 'an archive without version.txt is judged against the live schema version');
$ok(V::checkTables(['a', 'b'], ['a', 'b'], true)['status'] === 'pass', 'table lists equal pass');
$ok(V::checkTables(['a'], ['a', 'b'], true)['status'] === 'fail', 'a missing table fails on the same schema');
$ok(V::checkTables(['a'], ['a', 'b'], false)['status'] === 'warn', 'a missing table only warns when the backup is from an older schema');
$ok(V::checkTables([], ['a'], false)['status'] === 'fail', 'zero restored tables fails');
$ok(V::withinTolerance(100, 104, 5.0) && V::withinTolerance(100, 95, 5.0) && !V::withinTolerance(100, 94, 5.0) && !V::withinTolerance(100, 106, 5.0), '5% tolerance edges');
$ok(V::withinTolerance(3, 5, 5.0) && !V::withinTolerance(3, 6, 5.0), 'small tables get a 2-row slack');
$ok(!V::withinTolerance(50, 0, 5.0) && V::withinTolerance(1, 0, 5.0), 'an emptied table fails, a 1-row table may be 0');
$ok(V::checkRowCounts(['clients' => 100, 'users' => 4], ['clients' => 101, 'users' => 4])['status'] === 'pass', 'row counts within tolerance pass');
$ok(V::checkRowCounts(['clients' => 100], ['clients' => 80])['status'] === 'fail', 'row counts outside tolerance fail');
$ok(V::checkRowCounts(['clients' => 100], [])['status'] === 'fail', 'a core table absent from the restore fails');
$ok(V::checkRowCounts([], ['clients' => 1])['status'] === 'skip', 'no snapshot skips the row-count check');
$h = ['seq' => 12, 'hash' => str_repeat('c', 64)];
$ok(V::checkLedger(['seq' => 12, 'hash' => str_repeat('c', 64)], $h, ['ok' => true])['status'] === 'pass', 'ledger head equal to the anchor passes');
$ok(V::checkLedger(['seq' => 13, 'hash' => str_repeat('c', 64)], $h, ['ok' => true])['status'] === 'fail', 'ledger head behind the anchor fails');
$ok(V::checkLedger(['seq' => 12, 'hash' => str_repeat('e', 64)], $h, ['ok' => true])['status'] === 'fail', 'ledger head with a different hash fails');
$ok(V::checkLedger(null, $h, ['ok' => false, 'breaks' => [['kind' => 'chain', 'seq' => 5, 'detail' => 'x']]])['status'] === 'fail', 'a chain break fails');
$ok(V::checkLedger(null, $h, ['ok' => false, 'breaks' => [], 'checked' => 9])['status'] === 'warn', 'an unfinished walk warns');
$ok(V::checkLedger(null, null, null)['status'] === 'skip', 'no ledger skips');
$ok(V::checkSecret(true, 'smtp-pass', 'config_smtp_password')['status'] === 'pass' && V::checkSecret(true, '', 'c')['status'] === 'fail' && V::checkSecret(false, null)['status'] === 'skip', 'secret decrypt pass / fail / skip');
$ok(V::checkUploads(10, 5, 0, true)['status'] === 'pass' && V::checkUploads(10, 5, 1, true)['status'] === 'fail' && V::checkUploads(null, 0, 0, true)['status'] === 'fail'
    && V::checkUploads(0, 0, 0, true)['status'] === 'warn' && V::checkUploads(0, 0, 0, false)['status'] === 'pass', 'uploads check rules');
$mk = fn (string ...$s) => array_map(fn ($x) => ['status' => $x, 'label' => $x, 'detail' => ''], $s);
$ok(V::overall($mk('pass', 'skip')) === 'pass' && V::overall($mk('pass', 'warn')) === 'warn' && V::overall($mk('pass', 'warn', 'fail')) === 'fail' && V::overall([]) === 'fail' && V::overall($mk('skip')) === 'fail', 'overall: fail > warn > pass; nothing proven is a fail');
$a = V::restoreTestedState(3.0, 'pass', 42.5, 35, true);
$ok($a['state'] === 'pass' && str_contains($a['detail'], '42.5 s'), 'compliance: a recent pass is compliant and shows the restore time');
$ok(V::restoreTestedState(36.0, 'pass', 1.0, 35, true)['state'] === 'fail', 'compliance: a pass 36 days ago is not compliant');
$ok(V::restoreTestedState(null, 'fail', null, 35, true)['state'] === 'fail' && V::restoreTestedState(null, null, null, 35, false)['state'] === 'fail', 'compliance: failures and "never" are not compliant');

$now = 1_000_000;
$run = fn (string $s, int $ago, ?int $dur = 60) => ['status' => $s, 'started' => $now - $ago, 'finished' => $dur === null ? null : $now - $ago + $dur];
$ok(SyncWatch::assess([$run('success', 300)], null, $now, 15, 3)['state'] === 'ok', 'sync: a recent success is ok');
$ok(SyncWatch::assess([$run('success', 45 * 60 + 1)], null, $now, 15, 3)['state'] === 'stale', 'sync: no run in 3x the interval is stale');
$ok(SyncWatch::assess([$run('success', 45 * 60 - 5)], null, $now, 15, 3)['state'] === 'ok', 'sync: just inside 3x is ok');
$ok(SyncWatch::assess([$run('failed', 60), $run('failed', 600), $run('failed', 1200)], null, $now, 15, 3)['state'] === 'failing', 'sync: three failures in a row is failing');
$ok(SyncWatch::assess([$run('failed', 60), $run('success', 600), $run('failed', 1200)], null, $now, 15, 3)['state'] === 'ok', 'sync: a success in between resets the count');
$ok(SyncWatch::assess([$run('failed', 60), $run('failed', 600)], null, $now, 15, 3)['state'] === 'ok', 'sync: fewer errored runs than the threshold is ok');
$ok(SyncWatch::assess([$run('running', 7200, null), $run('running', 8000, null), $run('failed', 9000)], null, $now, 15, 3)['state'] === 'failing', 'sync: runs stuck "running" for over an hour count as errors');
$ok(SyncWatch::assess([$run('running', 60, null)], null, $now, 15, 3)['state'] === 'ok', 'sync: a run in progress is fine');
$ok(SyncWatch::assess([], $now - 3 * 3600, $now, 15, 3)['state'] === 'stale' && SyncWatch::assess([], $now - 600, $now, 15, 3)['state'] === 'ok' && SyncWatch::assess([], null, $now, 15, 3)['state'] === 'ok', 'sync: never-run is stale only once old enough');
$ok(SyncWatch::assess([$run('success', 99999)], null, $now, 0, 3)['state'] === 'ok', 'sync: interval 0 turns the staleness watch off');
$ok(SyncWatch::inferInterval([1000, 700, 400, 100]) === 5 && SyncWatch::inferInterval([7200, 3600, 0]) === 60 && SyncWatch::inferInterval([100, 0]) === null, 'sync: interval inferred from the median gap, floored at 5 min');
$ok(BackupStatus::staleState(26 * 3600, 26) === 'ok' && BackupStatus::staleState(26 * 3600 + 1, 26) === 'stale' && BackupStatus::staleState(null, 26) === 'none', 'backup stale boundary is 26 h');
$ok(RecoveryAlerts::dueAgain(null, 100) && !RecoveryAlerts::dueAgain(100, 100 + 6 * 3600 - 1) && RecoveryAlerts::dueAgain(100, 100 + 6 * 3600), 'alert de-dupe window is 6 h');
$ok(RestoreDrill::setupSteps('u', 'p') !== '' && str_contains(RestoreDrill::setupSteps('u', 'p'), 'GRANT ALL PRIVILEGES ON `drill\\_%`.* TO \'u\'@\'localhost\''), 'setup steps carry the drill_% GRANT');
$ok(preg_match(RestoreDrill::SCRATCH_RE, 'drill_20261009') === 1 && preg_match(RestoreDrill::SCRATCH_RE, 'itflow_scratch') === 0 && preg_match(RestoreDrill::SCRATCH_RE, 'drill_1; DROP') === 0 && preg_match(RestoreDrill::SCRATCH_RE, 'drill_20261009_probe') === 1, 'scratch database names are pattern-locked');
$ok(in_array('backup.failed', array_column(rivetEventCatalogExtrasForTest(), 'id'), true), 'backup.failed is registered in the event catalog extras');
function rivetEventCatalogExtrasForTest(): array { require_once __DIR__ . '/../includes/event_catalog_ext.php'; return rivetEventCatalogExtras(); }
$ev = array_column(rivetEventCatalogExtrasForTest(), 'id');
$ok(!array_diff(['backup.failed', 'backup.stale', 'restore_drill.failed', 'integration.sync_failed', 'integration.sync_stale'], $ev), 'all five recovery events are registered');
$ok(isset(\ITFlow\Cron\JobCatalog::all()['restore_drill.php']) && is_file(__DIR__ . '/../cron/restore_drill.php'), 'cron job catalog lists restore_drill.php');

if (getenv('RIVETIT_TEST_DB') !== '1') { echo $fails === 0 ? "ALL $n PASSED (pure part only; set RIVETIT_TEST_DB for the database part)\n" : "$fails FAILED\n"; exit($fails === 0 ? 0 : 1); }

// ---------------------------------------------------------------- Part 2: scratch database
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
$sock = getenv('RIVETIT_TEST_DB_SOCKET') ?: null;
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'), 3306, $sock);
if ($mysqli->connect_errno) { fwrite(STDERR, "cannot connect: {$mysqli->connect_error}\n"); exit(2); }
$mysqli->set_charset('utf8mb4');
$q = fn (string $sql) => $mysqli->query($sql);
$one = fn (string $sql) => ($r = $mysqli->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

// Stubs for what functions.php provides (the real file needs a full app bootstrap).
$GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = [];
function appNotify($t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'][] = $d; }
function logApp($c, $t, $d) {}
function addToMailQueue($data) { foreach ($data as $e) { $GLOBALS['mailed'][] = $e; } }
function rivetEmitEvent(string $e, array $d): void { $GLOBALS['events'][] = $e; }
$reset = function () use ($q) {
    foreach (['backup_runs', 'recovery_alerts', 'recovery_settings', 'restore_drill_log', 'rmm_sync_log', 'rmm_integrations'] as $t) { $q("DELETE FROM `$t`"); }
    $GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = [];
};
$reset();
$tmpRoot = sys_get_temp_dir() . '/recovery-test-' . bin2hex(random_bytes(4));
mkdir($tmpRoot . '/backups', 0700, true);

// -- tables exist (migration 2.6.153)
foreach (['backup_runs', 'restore_drill_log', 'recovery_alerts', 'recovery_settings'] as $t) { $ok($one("SHOW TABLES LIKE '$t'") === $t, "table $t exists"); }

// -- settings
$ok(RecoverySettings::get($mysqli, 'backup_stale_hours') === '26' && RecoverySettings::get($mysqli, 'drill_enabled') === '0', 'defaults: 26 h, drill off');
$ok(RecoverySettings::set($mysqli, 'backup_stale_hours', '12') && RecoverySettings::int($mysqli, 'backup_stale_hours') === 12 && !RecoverySettings::set($mysqli, 'nope', 'x'), 'settings round-trip; unknown keys are refused');
RecoverySettings::set($mysqli, 'backup_stale_hours', '26');

// -- failure record + alert (notify + email + event), deduped
$q("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('Recovery test admin', 1)");
$q("INSERT INTO users (user_name, user_email, user_password, user_role_id, user_type, user_status) VALUES ('Adm', 'admin@example.test', 'x', " . (int) $mysqli->insert_id . ", 1, 1)");
$q("UPDATE settings SET config_mail_from_email = 'noreply@example.test', config_mail_from_name = 'RivetIT' WHERE company_id = 1");
$id = BackupStatus::begin($mysqli, 'app_auto');
$ok($id > 0 && $one("SELECT run_ok FROM backup_runs WHERE run_id = $id") === '0' && $one("SELECT run_finished_at FROM backup_runs WHERE run_id = $id") === null, 'begin() opens an unfinished run');
BackupStatus::finish($mysqli, $id, ['ok' => false, 'file' => 'itflow_x_auto.zip', 'error' => 'disk full']);
$ok($one("SELECT run_ok FROM backup_runs WHERE run_id = $id") === '0' && str_contains((string) $one("SELECT run_error FROM backup_runs WHERE run_id = $id"), 'disk full'), 'a failed run is recorded with its error');
$ok(count($GLOBALS['notified']) === 1 && count($GLOBALS['mailed']) === 1 && $GLOBALS['mailed'][0]['recipient'] === 'admin@example.test' && $GLOBALS['events'] === ['backup.failed'], 'failure: one notification, one email to the admin, one backup.failed event');
$id2 = BackupStatus::begin($mysqli, 'app_auto');
BackupStatus::finish($mysqli, $id2, ['ok' => false, 'error' => 'disk full again']);
$ok(count($GLOBALS['notified']) === 1 && count($GLOBALS['mailed']) === 1, 'a second failure inside 6 h is not re-alerted');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 7 HOUR WHERE alert_key = 'backup.failed'");
$id3 = BackupStatus::begin($mysqli, 'app_auto');
BackupStatus::finish($mysqli, $id3, ['ok' => false, 'error' => 'still broken']);
$ok(count($GLOBALS['notified']) === 2 && count($GLOBALS['mailed']) === 2, 'after 6 h the alert repeats');
$id4 = BackupStatus::begin($mysqli, 'app_auto');
BackupStatus::finish($mysqli, $id4, ['ok' => true, 'file' => 'itflow_y_auto.zip', 'size' => 123, 'sha256' => str_repeat('a', 64)]);
$ok($one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'backup.failed'") === '0' && $one("SELECT run_sha256 FROM backup_runs WHERE run_id = $id4") === str_repeat('a', 64), 'a good run clears the failure alert state and stores size/sha256');
RecoverySettings::set($mysqli, 'alert_email', 'ops@example.test, bad address');
$ok(RecoveryAlerts::recipients($mysqli) === ['ops@example.test'], 'an explicit alert address overrides the administrator list; invalid ones are dropped');
RecoverySettings::set($mysqli, 'alert_email', '');

// -- interrupted run (exit()/fatal inside the builder): the shutdown hook closes it
$id5 = BackupStatus::begin($mysqli, 'app_manual');
BackupStatus::closeOrphans();
$ok($one("SELECT run_finished_at IS NOT NULL AND run_ok = 0 FROM backup_runs WHERE run_id = $id5") === '1', 'an unfinished run is closed as failed at shutdown');
$ok($one("SELECT COUNT(*) FROM backup_runs WHERE run_id = $id5 AND run_error LIKE '%ended before it finished%'") === '1', '... with an explanatory error');

// -- stale watcher
$reset();
$q("UPDATE settings SET config_backup_auto_enabled = 1 WHERE company_id = 1");
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'none' && count($GLOBALS['notified']) === 1, 'stale watcher: scheduled backups on but none ever made alerts');
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'none' && count($GLOBALS['notified']) === 1, '... and is deduped');
$reset();
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', false) === 'not_expected' && $GLOBALS['notified'] === [], 'no schedule and no backups ever: nothing to alert about');
$q("INSERT INTO backup_runs (run_kind, run_started_at, run_finished_at, run_ok) VALUES ('app_auto', NOW() - INTERVAL 27 HOUR, NOW() - INTERVAL 27 HOUR, 1)");
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'stale' && count($GLOBALS['notified']) === 1 && in_array('backup.stale', $GLOBALS['events'], true), 'stale watcher: newest good run 27 h old alerts (backup.stale event)');
$reset();
$q("INSERT INTO backup_runs (run_kind, run_started_at, run_finished_at, run_ok) VALUES ('app_auto', NOW() - INTERVAL 25 HOUR, NOW() - INTERVAL 25 HOUR, 1)");
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'ok' && $GLOBALS['notified'] === [], 'stale watcher: 25 h old is fine');
RecoverySettings::set($mysqli, 'backup_stale_hours', '24');
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'stale', 'stale watcher honours the threshold setting');
RecoverySettings::set($mysqli, 'backup_stale_hours', '26');
$reset();
touch($tmpRoot . '/backups/itflow_20260101000000_auto.zip', time() - 30 * 3600);
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'stale', 'stale watcher: a 30 h old file with no run records is stale');
touch($tmpRoot . '/backups/itflow_20260101000000_auto.zip', time() - 3600);
$ok(BackupStatus::watchStale($mysqli, $tmpRoot . '/backups', true) === 'ok', '... and a fresh file counts even before any run is recorded');
unlink($tmpRoot . '/backups/itflow_20260101000000_auto.zip');

// -- sync watcher against the real log tables
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (901, 'Tactical', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 5 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (901, NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 3 HOUR, 'success', 0)");
$p = SyncWatch::run($mysqli, true);
$rmm = array_values(array_filter($p, fn ($x) => $x['source'] === 'rmm'));
$ok(count($rmm) === 1 && $rmm[0]['state'] === 'stale' && $rmm[0]['alerted'], 'sync watch: RMM silent for 3 h (limit 45 min) alerts once');
$n1 = count($GLOBALS['notified']);
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['notified']) === $n1, 'sync watch: same problem a minute later is de-duplicated');
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (901, NOW() - INTERVAL 30 MINUTE, NOW() - INTERVAL 30 MINUTE, 'failed', 0), (901, NOW() - INTERVAL 20 MINUTE, NOW() - INTERVAL 20 MINUTE, 'failed', 0), (901, NOW() - INTERVAL 10 MINUTE, NOW() - INTERVAL 10 MINUTE, 'failed', 0)");
$p = SyncWatch::run($mysqli, true);
$rmm = array_values(array_filter($p, fn ($x) => $x['source'] === 'rmm'));
$ok($rmm && $rmm[0]['state'] === 'failing' && in_array('integration.sync_failed', $GLOBALS['events'], true), 'sync watch: three errored runs in a row is "failing" (integration.sync_failed)');
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (901, NOW() - INTERVAL 2 MINUTE, NOW() - INTERVAL 1 MINUTE, 'success', 0)");
$p = SyncWatch::run($mysqli, true);
$ok(array_values(array_filter($p, fn ($x) => $x['source'] === 'rmm')) === [] && $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'sync.rmm.901.%'") === '0', 'sync watch: recovery clears the alert state');
$ok(SyncWatch::run($mysqli, false) === [], 'sync watch: RMM is not watched while the RMM module is off');
$q("DELETE FROM rmm_sync_log"); $q("DELETE FROM rmm_integrations");

// -- the full path: real build_backup() -> real zip -> real drill into a scratch database
$drillUser = getenv('RIVETIT_TEST_DRILL_USER'); $drillPass = getenv('RIVETIT_TEST_DRILL_PASS');
if (!$drillUser) { echo "SKIP  drill end-to-end (no RIVETIT_TEST_DRILL_USER)\n"; echo $fails === 0 ? "ALL $n PASSED (drill part skipped)\n" : "$fails FAILED\n"; exit($fails === 0 ? 0 : 1); }

$reset();
define('FROM_POST_HANDLER', true);
$_SERVER['DOCUMENT_ROOT'] = $tmpRoot;
$GLOBALS['installation_id'] = 'test-install'; $GLOBALS['config_settings_enc_key'] = 'test-key'; $GLOBALS['config_backup_passphrase'] = '';
$appRoot = realpath(__DIR__ . '/..');
define('CURRENT_DATABASE_VERSION', (string) $one("SELECT config_current_database_version FROM settings WHERE company_id = 1"));
chdir($appRoot . '/admin');
function validateCSRFToken($t) {} function flash_alert(...$a) {} function redirect(...$a) {} function logAction(...$a) {}
// Seed recognisable data: 40 clients, an encrypted setting, ledger head at its zero state.
$q("DELETE FROM clients");
$q("SET SESSION sql_mode = ''");   // seeding only: the fixture rows do not need every NOT NULL column
for ($i = 1; $i <= 40; $i++) { $q("INSERT INTO clients (client_name, client_currency_code, client_created_at) VALUES ('Drill Client $i', 'USD', NOW())"); }
$secretPlain = 'smtp-secret-' . bin2hex(random_bytes(3));
$q("UPDATE settings SET config_smtp_password = 'ENC2:" . base64_encode(strrev($secretPlain)) . "' WHERE company_id = 1");
$decrypt = fn (string $v) => strrev((string) base64_decode(substr($v, 5)));
require_once $appRoot . '/admin/post/backup.php';
$res = build_backup($mysqli, 'auto', $tmpRoot . '/backups');
$ok(is_file($res['path']), 'build_backup() still produces the zip');
$run = $mysqli->query("SELECT * FROM backup_runs ORDER BY run_id DESC LIMIT 1")->fetch_assoc();
$ok($run['run_ok'] === '1' && $run['run_kind'] === 'app_auto' && $run['run_file'] === $res['name'] && (int) $run['run_size'] === filesize($res['path']) && $run['run_sha256'] === hash_file('sha256', $res['path']), 'build_backup() records a status row (kind, file, size, sha256, ok)');
$zip = new ZipArchive(); $zip->open($res['path']);
$snap = json_decode((string) $zip->getFromName(TableSnapshot::ENTRY), true);
$names = []; for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
$zip->close();
$ok(in_array('db.sql', $names) && in_array('uploads.zip', $names) && in_array('version.txt', $names) && in_array('backup-manifest.json', $names), 'the backup keeps its existing entries');
$ok(($snap['core_counts']['clients'] ?? null) === 40 && isset($snap['tables']['settings']) && ($snap['snapshot_version'] ?? 0) === 1, 'the backup carries a table snapshot with exact core counts');
$ok(!str_contains(json_encode($snap), 'test-key') && !str_contains(json_encode($snap), $secretPlain), 'the snapshot holds no secrets');

$dopts = ['trigger' => 'script', 'user' => $drillUser, 'pass' => $drillPass, 'socket' => $sock, 'live_user' => getenv('RIVETIT_TEST_DB_USER'), 'backup_dir' => $tmpRoot . '/backups',
    'decrypt' => $decrypt, 'work_dir' => $tmpRoot, 'uploads_sample' => 8];
$drillDbs = function () use ($drillUser, $drillPass, $sock) {
    $d = new mysqli('localhost', $drillUser, $drillPass, null, 3306, $sock); $r = $d->query("SHOW DATABASES LIKE 'drill\\_%'"); $o = []; while ($r && ($x = $r->fetch_row())) { $o[] = $x[0]; } return $o;
};
$leftoverDirs = fn () => glob($tmpRoot . '/rivetit-drill-*') ?: [];

$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'pass', 'DRILL pass on a healthy backup: ' . $r['status'] . ' - ' . $r['message']);
if ($r['status'] !== 'pass' || getenv('DRILL_VERBOSE')) { foreach ($r['checks'] as $c) { echo "      [{$c['status']}] {$c['label']}: {$c['detail']}\n"; } }
$ok($st($r['checks'], 'sha_db') === 'pass' && $st($r['checks'], 'sha_uploads') === 'pass' && $st($r['checks'], 'schema_version') === 'pass' && $st($r['checks'], 'tables') === 'pass'
    && $st($r['checks'], 'row_counts') === 'pass' && $st($r['checks'], 'ledger') === 'pass' && $st($r['checks'], 'secret') === 'pass' && $st($r['checks'], 'uploads') === 'pass', 'every check ran and passed (checksums, schema, tables, rows, ledger, secret, uploads)');
$ok($r['restore_seconds'] > 0 && $r['total_seconds'] >= $r['restore_seconds'], 'restore seconds are measured (RTO evidence): ' . $r['restore_seconds'] . ' s');
$ok($r['cleanup_ok'] === true && $drillDbs() === [] && $leftoverDirs() === [], 'the scratch database and temp files are gone afterwards');
$row = $mysqli->query("SELECT * FROM restore_drill_log ORDER BY drill_id DESC LIMIT 1")->fetch_assoc();
$ok($row['drill_status'] === 'pass' && $row['drill_backup_file'] === $res['name'] && $row['drill_restore_seconds'] > 0 && $row['drill_cleanup_ok'] === '1' && $row['drill_scratch_db'] === 'drill_' . date('Ymd')
    && count(json_decode($row['drill_checks'], true)['checks']) >= 8, 'restore_drill_log has the result (file, seconds, scratch db name, checks)');
$ok($GLOBALS['notified'] === [], 'a passing drill raises no alert');

// ledger anchor: bump the live head AFTER the backup was taken -> still the backup's own head restored, so a pass; then tamper the db.sql head instead
// (a) tampered db.sql: change the SQL text but keep version.txt -> checksum + (maybe) rows fail
$tamper = function (callable $edit) use ($res, $tmpRoot): string {
    $copy = $tmpRoot . '/backups/itflow_29990101000000_manual.zip';
    copy($res['path'], $copy);
    $z = new ZipArchive(); $z->open($copy); $edit($z); $z->close();
    return $copy;
};
$bad = $tamper(function (ZipArchive $z) { $z->addFromString('db.sql', $z->getFromName('db.sql') . "\n-- tampered\n"); });
touch($bad, time() + 100);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'sha_db') === 'fail', 'DRILL fails when db.sql no longer matches version.txt');
$ok($drillDbs() === [] && $leftoverDirs() === [] && $r['cleanup_ok'] === true, '... and still drops the scratch database and files');
$ok(count($GLOBALS['notified']) === 1 && in_array('restore_drill.failed', $GLOBALS['events'], true) && count($GLOBALS['mailed']) >= 1, 'a failing drill notifies, emails and emits restore_drill.failed');
$n1 = count($GLOBALS['notified']);
(new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok(count($GLOBALS['notified']) === $n1, 'a second failing drill inside 6 h is not re-alerted');
unlink($bad);

$bad = $tamper(function (ZipArchive $z) { $s = json_decode($z->getFromName('table-snapshot.json'), true); $s['core_counts']['clients'] = 400; $z->addFromString('table-snapshot.json', json_encode($s)); });
touch($bad, time() + 100);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'row_counts') === 'fail' && $st($r['checks'], 'sha_db') === 'pass', 'DRILL fails when core row counts are off by more than 5%');
unlink($bad);

$bad = $tamper(function (ZipArchive $z) { $z->addFromString('version.txt', preg_replace('/DB Version: \S+/', 'DB Version: 2.5.1', $z->getFromName('version.txt'))); });
touch($bad, time() + 100);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'schema_version') === 'fail', 'DRILL fails when the schema version differs from version.txt');
unlink($bad);

$bad = $tamper(function (ZipArchive $z) { $z->addFromString('version.txt', preg_replace('/Training ledger head: #\d+ \S+/', 'Training ledger head: #77 ' . str_repeat('9', 64), $z->getFromName('version.txt'))); });
touch($bad, time() + 100);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'ledger') === 'fail', 'DRILL fails when the restored ledger head is not the one recorded outside the database');
unlink($bad);

$bad = $tamper(function (ZipArchive $z) { $z->addFromString('uploads.zip', 'not a zip at all'); });
touch($bad, time() + 100);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'sha_uploads') === 'fail', 'DRILL fails on a corrupt uploads.zip');
unlink($bad);

$r = (new RestoreDrill($mysqli, $appRoot, ['decrypt' => fn ($v) => ''] + $dopts))->run();
$ok($r['status'] === 'fail' && $st($r['checks'], 'secret') === 'fail', 'DRILL fails when the stored secret cannot be decrypted with the key');

file_put_contents($tmpRoot . '/backups/itflow_29990102000000_manual.zip', 'garbage'); touch($tmpRoot . '/backups/itflow_29990102000000_manual.zip', time() + 200);
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($r['status'] === 'error' && str_contains($r['message'], 'zip') && $drillDbs() === [], 'an unreadable newest backup is an error and creates no scratch database');
unlink($tmpRoot . '/backups/itflow_29990102000000_manual.zip');

$r = (new RestoreDrill($mysqli, $appRoot, ['user' => '', 'pass' => ''] + $dopts))->run();
$ok($r['status'] === 'not_configured' && str_contains($r['message'], 'No drill database account'), 'no drill account: "not configured", not a silent failure');
$r = (new RestoreDrill($mysqli, $appRoot, ['user' => getenv('RIVETIT_TEST_DB_USER'), 'pass' => getenv('RIVETIT_TEST_DB_PASS')] + $dopts))->run();
$ok($r['status'] === 'not_configured' && str_contains($r['message'], 'must not be the application'), 'the application\'s own DB account is refused as the drill account');
$r = (new RestoreDrill($mysqli, $appRoot, ['pass' => 'wrong-password'] + $dopts))->run();
$ok($r['status'] === 'not_configured' && $one("SELECT drill_status FROM restore_drill_log ORDER BY drill_id DESC LIMIT 1") === 'not_configured', 'a wrong drill password is "not configured" and logged');
RecoverySettings::set($mysqli, 'drill_enabled', '1');
$GLOBALS['notified'] = [];
(new RestoreDrill($mysqli, $appRoot, ['pass' => 'wrong-password'] + $dopts))->run();
$ok(count($GLOBALS['notified']) === 1, 'enabled but broken drill alerts; disabled-and-unset does not');
RecoverySettings::set($mysqli, 'drill_enabled', '0');

// leftover scratch from an interrupted run is swept; the lock stops two drills overlapping
$d = new mysqli('localhost', $drillUser, $drillPass, null, 3306, $sock); $d->query('CREATE DATABASE drill_20200101');
$other = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'), 3306, $sock);
$other->query("SELECT GET_LOCK('rivetit_restore_drill', 0)");   // another process (e.g. the root script) is mid-drill
$r = (new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok(!empty($r['busy']) && $drillDbs() === ['drill_20200101'], 'a second drill while another process holds the drill lock does nothing');
$other->query("DO RELEASE_LOCK('rivetit_restore_drill')"); $other->close();
(new RestoreDrill($mysqli, $appRoot, $dopts))->run();
$ok($drillDbs() === [], 'a leftover drill_* database from an interrupted run is swept at the next drill');

// the drill never touched live data
$ok($one("SELECT COUNT(*) FROM clients") === '40' && $one("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'drill\\_%'") === '0', 'live data untouched, no drill_* database remains');

// deploy/backup.sh style directory source (decrypted tar unpacked by deploy/restore_drill.sh)
$dir = $tmpRoot . '/unpacked'; mkdir($dir . '/uploads/sub', 0700, true);
$z = new ZipArchive(); $z->open($res['path']); file_put_contents($dir . '/backup-test-20261009T000000Z.sql', $z->getFromName('db.sql')); file_put_contents($dir . '/table-snapshot.json', $z->getFromName('table-snapshot.json')); $z->close();
file_put_contents($dir . '/uploads/sub/a.txt', 'hello'); file_put_contents($dir . '/backup-manifest.json', '{}');
$r = (new RestoreDrill($mysqli, $appRoot, ['extracted_dir' => $dir, 'source_name' => 'backup-test-20261009T000000Z.tar.gz.enc'] + $dopts))->run();
$ok($r['status'] === 'pass' && $r['backup_kind'] === 'enc' && $r['backup_file'] === 'backup-test-20261009T000000Z.tar.gz.enc' && $st($r['checks'], 'row_counts') === 'pass' && $drillDbs() === [], 'DRILL on an unpacked deploy/backup.sh archive passes and is logged as kind enc');

// -- Compliance check "restore tested in last 35 days"
$cat = new \ITFlow\Compliance\ComplianceCatalog($mysqli, $appRoot, []);
$find = function () use ($cat) { foreach ($cat->checks() as $ch) { if ($ch->id() === 'restore_tested') { return $ch->run(); } } return null; };
$res1 = $find();
$ok($res1 !== null, 'Compliance catalog has the restore_tested check');
if ($res1 !== null) {
    $ok($res1->status === 'pass' || strtolower((string) ($res1->status->value ?? $res1->status)) === 'pass', 'compliance: recent passing drill -> pass');
    $q("UPDATE restore_drill_log SET drill_finished_at = NOW() - INTERVAL 40 DAY");
    $res2 = $find();
    $ok(strtolower((string) ($res2->status->value ?? $res2->status)) === 'fail', 'compliance: last pass 40 days ago -> fail');
    $q("DELETE FROM restore_drill_log");
    $res3 = $find();
    $ok(strtolower((string) ($res3->status->value ?? $res3->status)) === 'fail', 'compliance: never tested -> fail');
}

// cleanup
foreach (glob($tmpRoot . '/{,*/,*/*/}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) @unlink($f); }
exec('rm -rf ' . escapeshellarg($tmpRoot));
$reset();
$q("UPDATE settings SET config_smtp_password = NULL WHERE company_id = 1"); $q("DELETE FROM clients"); $q("DELETE FROM users WHERE user_email = 'admin@example.test'"); $q("DELETE FROM user_roles WHERE role_name = 'Recovery test admin'");
echo $fails === 0 ? "ALL $n PASSED\n" : "$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
