<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Sync watcher (DB 2.6.165): the dormant rule, per-integration overrides (mute, expected interval, forget history), the 24 h repeat
 * window, the once-only recovered notice, the "real error" failure rule (the rivetit_agent sentinel, empty text), the panel and its
 * handler. Part 1 is pure. Part 2 needs the THROWAWAY scratch MariaDB with the full migrated schema (never reads config.php):
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... [RIVETIT_TEST_DB_SOCKET=...] php tests/recovery_syncwatch.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use ITFlow\Recovery\RecoveryAlerts;
use ITFlow\Recovery\RecoverySettings;
use ITFlow\Recovery\SyncWatch;
use ITFlow\Recovery\WatchOverrides;

$fails = 0; $n = 0;
$ok = function (bool $c, string $l) use (&$fails, &$n) { $n++; echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// ---------------------------------------------------------------- Part 1: pure rules
$sentinel = 'RivetIT endpoint agent devices are managed from the device page, not through an RMM client.';
$ok(!SyncWatch::realError(null) && !SyncWatch::realError('') && !SyncWatch::realError("  \n") && !SyncWatch::realError('[]') && !SyncWatch::realError('null'), 'real error: blank / [] / null are not errors');
$ok(!SyncWatch::realError($sentinel) && !SyncWatch::realError('ERROR: ' . strtoupper($sentinel)) && SyncWatch::realError('HTTP 500 from the API'), 'real error: the no-vendor-API sentinel is not an error, an HTTP failure is');
$now = 10_000_000;
$run = fn (string $s, int $ago, ?string $err = null) => ['status' => $s, 'started' => $now - $ago, 'finished' => $now - $ago + 30, 'error' => $err];
$day = 86400;

// the live UniFi case: the in-app sync ran every 17 min until 94 days ago, an external script replaced it
$unifi = [];
for ($i = 0; $i < 10; $i++) { $unifi[] = $run('success', 94 * $day + $i * 17 * 60); }
$inferred = SyncWatch::inferInterval(array_column($unifi, 'started'));
$ok($inferred === 17, 'unifi: the interval inferred from the old runs is 17 min (the old behaviour)');
$a = SyncWatch::assess($unifi, $now - 400 * $day, $now, $inferred, 3, 14);
$ok($a['state'] === 'dormant' && $a['was_healthy'] && $a['since'] === $now - 94 * $day && str_contains($a['detail'], 'dormant since ' . date('Y-m-d', $now - 94 * $day)), 'unifi: 94-day-old runs with a 17 min interval are dormant, not stale ("dormant since <date>")');
$ok(SyncWatch::assess($unifi, null, $now, $inferred, 3, 0)['state'] === 'stale', 'dormant rule off (0 days): the old behaviour (stale)');
$ok(SyncWatch::assess([$run('success', 13 * $day)], null, $now, 15, 3, 14)['state'] === 'stale' && SyncWatch::assess([$run('success', 15 * $day)], null, $now, 15, 3, 14)['state'] === 'dormant', 'dormant boundary: 13 days is stale, 15 days is dormant (default 14)');
$ok(SyncWatch::assess([$run('success', 20 * $day)], null, $now, 30 * 1440, 3, 14)['state'] === 'ok', 'a monthly job (expected every 30 days) is not dormant or stale after 20 days');
$ok(SyncWatch::assess([$run('success', 100 * $day)], null, $now, 30 * 1440, 3, 14)['state'] === 'stale' && SyncWatch::assess([$run('success', 200 * $day)], null, $now, 30 * 1440, 3, 14)['state'] === 'dormant', 'a monthly job turns stale at 3x and dormant at 6x its interval');
$ok(SyncWatch::assess([$run('failed', 30 * $day, 'boom')], null, $now, 15, 3, 14)['was_healthy'] === false, 'dormant after a failed last run is "not healthy before"');
$ok(SyncWatch::assess([], $now - 60 * $day, $now, 15, 3, 14)['state'] === 'dormant' && SyncWatch::assess([], $now - 60 * $day, $now, 15, 3, 14)['was_healthy'] === false, 'never ran in 60 days: dormant, never "healthy before"');
$ok(SyncWatch::assess([], $now - 3 * $day, $now, 15, 3, 14)['state'] === 'stale', 'never ran for 3 days (under the dormant limit): still stale');
$ok(SyncWatch::assess([], null, $now, 15, 3, 14, $now - 5 * $day)['state'] === 'dormant', 'history cleared and no run since: dormant');

// the live Sophos case: one integration, mixed success / failed rows, some carrying the sentinel
$f = fn (int $ago) => $run('failed', $ago, $sentinel);
$ok(SyncWatch::assess([$f(60), $f(600), $f(1200)], null, $now, 15, 3, 14)['state'] === 'ok', 'sophos: three failed rows carrying only the sentinel are not failing');
$ok(SyncWatch::assess([$f(60), $run('failed', 600, 'timeout'), $run('failed', 1200, 'timeout')], null, $now, 15, 3, 14)['state'] === 'ok', 'sophos: a sentinel row inside the last three breaks the chain');
$ok(SyncWatch::assess([$run('failed', 60, 'timeout'), $f(600), $run('success', 1200), $run('failed', 1800, 'timeout')], null, $now, 15, 3, 14)['state'] === 'ok', 'sophos: mixed success / failed / sentinel rows are ok');
$ok(SyncWatch::assess([$run('failed', 60), $run('failed', 600, ''), $run('failed', 1200, '[]')], null, $now, 15, 3, 14)['state'] === 'ok', 'a failed status with no error text is not counted');
$ok(SyncWatch::assess([$run('failed', 60, 'timeout'), $run('failed', 600, 'timeout'), $run('failed', 1200, 'bad key')], null, $now, 15, 3, 14)['state'] === 'failing', 'three real errors are failing');

if (getenv('RIVETIT_TEST_DB') !== '1') { echo $fails === 0 ? "ALL $n PASSED (pure part only; set RIVETIT_TEST_DB for the database part)\n" : "$fails FAILED\n"; exit($fails === 0 ? 0 : 1); }

// ---------------------------------------------------------------- Part 2: scratch database
if (!preg_match('/scratch|test/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain scratch/test\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
$mysqli = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'), 3306, getenv('RIVETIT_TEST_DB_SOCKET') ?: null);
if ($mysqli->connect_errno) { fwrite(STDERR, "cannot connect: {$mysqli->connect_error}\n"); exit(2); }
$mysqli->set_charset('utf8mb4');
$q = fn (string $sql) => $mysqli->query($sql);
$one = fn (string $sql) => ($r = $mysqli->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;

// Stubs for what functions.php provides.
$GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = []; $GLOBALS['actions'] = []; $GLOBALS['audits'] = []; $GLOBALS['flash'] = [];
function appNotify($t, $d, $a = null, $c = 0, $e = 0, $p = true) { $GLOBALS['notified'][] = $d; }
function logApp($c, $t, $d) {}
function addToMailQueue($data) { foreach ($data as $e) { $GLOBALS['mailed'][] = $e; } }
function rivetEmitEvent(string $e, array $d): void { $GLOBALS['events'][] = $e; }
function rivetAudit(string $event, ?int $actor, ?string $entityType, $entityId, string $action, ?string $summary = null, array $metadata = []): void { $GLOBALS['audits'][] = [$event, $actor, $entityType, $entityId, $action, $summary]; }
function validateCSRFToken($t) {}
function flash_alert($m, $t = 'success') { $GLOBALS['flash'][] = [$t, $m]; }
class TestRedirect extends Exception {}
function redirect(...$a) { throw new TestRedirect(); }
function logAction($type, $action, $desc, $c = 0, $e = 0) { $GLOBALS['actions'][] = [$type, $action, $desc]; }
function nullable_htmlentities($v) { return htmlentities((string) $v, ENT_QUOTES, 'UTF-8'); }
$reset = function () use ($q) {
    foreach (['recovery_alerts', 'recovery_settings', 'recovery_watch_overrides', 'rmm_sync_log', 'rmm_integrations', 'unifi_sync_log', 'unifi_integrations', 'intune_sync_log'] as $t) { $q("DELETE FROM `$t`"); }
    $GLOBALS['notified'] = []; $GLOBALS['mailed'] = []; $GLOBALS['events'] = []; $GLOBALS['actions'] = []; $GLOBALS['audits'] = []; $GLOBALS['flash'] = [];
};
$reset();
$q("INSERT INTO user_roles (role_name, role_is_admin) VALUES ('Sync watch admin', 1)");
$q("INSERT INTO users (user_name, user_email, user_password, user_role_id, user_type, user_status) VALUES ('Adm', 'admin@example.test', 'x', " . (int) $mysqli->insert_id . ", 1, 1)");
$q("UPDATE settings SET config_mail_from_email = 'noreply@example.test', config_mail_from_name = 'RivetIT' WHERE company_id = 1");
$sentinel = 'RivetIT endpoint agent devices are managed from the device page, not through an RMM client.';
$rmmRun = fn (int $id, string $when, string $status, ?string $err = null, int $by = 0) => $q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by, errors) VALUES ($id, NOW() - INTERVAL $when, NOW() - INTERVAL $when, '$status', $by, " . ($err === null ? 'NULL' : "'" . $GLOBALS['mysqli_esc']($err) . "'") . ")");
$GLOBALS['mysqli_esc'] = fn (string $s) => $mysqli->real_escape_string($s);
$problem = fn (array $p, string $src, int $id) => array_values(array_filter($p, fn ($x) => $x['source'] === $src && $x['id'] === $id))[0] ?? null;
$inspect = function (string $src, int $id) use ($mysqli) { foreach (SyncWatch::inspect($mysqli, true) as $r) { if ($r['source'] === $src && $r['id'] === $id) { return $r; } } return null; };

// -- schema, defaults
$ok($one("SHOW TABLES LIKE 'recovery_watch_overrides'") === 'recovery_watch_overrides' && WatchOverrides::ready($mysqli), 'table recovery_watch_overrides exists');
$ok($one("SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'recovery_watch_overrides'") === 'utf8mb4_general_ci', '... utf8mb4_general_ci');
$ok(RecoverySettings::get($mysqli, 'alert_repeat_hours') === '24' && RecoverySettings::get($mysqli, 'sync_dormant_days') === '14' && RecoveryAlerts::repeatHours($mysqli) === 24, 'defaults: repeat 24 h (was 6), dormant after 14 days');

// -- LIVE CASE 1: UniFi, old in-app runs 94 days ago every 17 min, interval "auto" (inferred 17 min)
$q("INSERT INTO unifi_integrations (id, name, host, api_key_enc, enabled, created_at) VALUES (1, 'Main UniFi', 'unifi.example', 'x', 1, NOW() - INTERVAL 400 DAY)");
for ($i = 9; $i >= 0; $i--) { $q("INSERT INTO unifi_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (1, NOW() - INTERVAL " . (94 * 1440 + $i * 17) . " MINUTE, NOW() - INTERVAL " . (94 * 1440 + $i * 17) . " MINUTE, 'success', 0)"); }
$ok(RecoverySettings::get($mysqli, 'sync_interval_unifi') === 'auto', 'unifi interval setting is auto');
$p = SyncWatch::run($mysqli, true);
$w = $inspect('unifi', 1);
$ok($problem($p, 'unifi', 1) === null && $GLOBALS['mailed'] === [] && $GLOBALS['notified'] === [] && $GLOBALS['events'] === [], 'LIVE unifi: 94-day-old runs, 17 min inferred interval: no email, no notification, no event');
$ok($w && $w['state'] === 'dormant' && $w['interval_min'] === 17 && $w['interval_source'] === 'inferred' && str_contains($w['detail'], 'dormant since'), '... the panel state is dormant ("dormant since <date>") with the inferred interval');
$ok(SyncWatch::run($mysqli, true) === [] && $GLOBALS['mailed'] === [], '... and nothing on the next tick either');

// -- LIVE CASE 2: Sophos, mixed success / failed rows including the sentinel text
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (11, 'Sophos Firewalls', 'sophos', 'https://x', 'x', 1, NOW() - INTERVAL 90 DAY)");
$rmmRun(11, '50 MINUTE', 'failed', $sentinel); $rmmRun(11, '35 MINUTE', 'failed', $sentinel); $rmmRun(11, '20 MINUTE', 'success'); $rmmRun(11, '5 MINUTE', 'failed', $sentinel);
$p = SyncWatch::run($mysqli, true);
$w = $inspect('rmm', 11);
$ok($problem($p, 'rmm', 11) === null && $w['state'] === 'ok' && $GLOBALS['mailed'] === [], 'LIVE sophos: mixed success and sentinel-failed rows are not failing, no email');
$rmmRun(11, '4 MINUTE', 'failed', $sentinel); $rmmRun(11, '3 MINUTE', 'failed', $sentinel); $rmmRun(11, '2 MINUTE', 'failed', $sentinel);
SyncWatch::run($mysqli, true);
$ok($inspect('rmm', 11)['state'] === 'ok' && $GLOBALS['mailed'] === [], '... even six sentinel failures in a row are not "the last 3 runs all ended in an error"');
$rmmRun(11, '90 SECOND', 'failed', null);
$ok($inspect('rmm', 11)['state'] === 'ok', '... a failed row with no error text is not counted either');
// another integration's runs never count against this one
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (12, 'Tactical', 'tactical_rmm', 'https://y', 'x', 1, NOW() - INTERVAL 90 DAY)");
$rmmRun(12, '5 MINUTE', 'success');
$rmmRun(11, '80 SECOND', 'failed', 'wrong key'); $rmmRun(11, '70 SECOND', 'failed', 'wrong key'); $rmmRun(11, '60 SECOND', 'failed', 'wrong key');
$p = SyncWatch::run($mysqli, true);
$ok($problem($p, 'rmm', 12) === null && $inspect('rmm', 12)['state'] === 'ok' && $problem($p, 'rmm', 11)['state'] === 'failing', 'only runs of the integration itself count: Sophos failing does not make the Tactical integration fail');
// the endpoint agent type is never watched
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (13, 'RivetIT Agent', 'rivetit_agent', 'https://z', 'x', 1, NOW() - INTERVAL 90 DAY)");
$rmmRun(13, '5 MINUTE', 'failed', $sentinel); $rmmRun(13, '4 MINUTE', 'failed', 'real looking error'); $rmmRun(13, '3 MINUTE', 'failed', 'real looking error'); $rmmRun(13, '2 MINUTE', 'failed', 'real looking error');
$p = SyncWatch::run($mysqli, true);
$ok($inspect('rmm', 13) === null && $problem($p, 'rmm', 13) === null, 'rivetit_agent is ignored completely (not inspected, never alerted), even with real-looking errors');

// -- LIVE CASE 3: a genuinely failing integration: one email, then none for 24 h
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (21, 'Tactical', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY)");
$rmmRun(21, '30 MINUTE', 'failed', 'HTTP 401'); $rmmRun(21, '20 MINUTE', 'failed', 'HTTP 401'); $rmmRun(21, '10 MINUTE', 'failed', 'HTTP 401');
$p = SyncWatch::run($mysqli, true);
$ok($problem($p, 'rmm', 21)['state'] === 'failing' && $problem($p, 'rmm', 21)['alerted'] && count($GLOBALS['mailed']) === 1 && count($GLOBALS['notified']) === 1 && $GLOBALS['events'] === ['integration.sync_failed'], 'REAL failure (3 real errors): the first occurrence is immediate: one email, one notification, one event');
$ok($GLOBALS['mailed'][0]['recipient'] === 'admin@example.test' && str_contains($GLOBALS['mailed'][0]['subject'], 'Tactical') && str_contains($GLOBALS['mailed'][0]['body'], 'alert is not repeated for 24 hours'), '... to the administrator, naming the integration and the 24 h window');
foreach ([1, 2, 3] as $tick) { SyncWatch::run($mysqli, true); }
$ok(count($GLOBALS['mailed']) === 1 && count($GLOBALS['notified']) === 1, '... no further email on the following cron ticks');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 23 HOUR WHERE alert_key = 'sync.rmm.21.failing'");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1, '... still none 23 h later');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 25 HOUR WHERE alert_key = 'sync.rmm.21.failing'");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 2, '... and one more after 24 h');
// repeat window is a setting
RecoverySettings::set($mysqli, 'alert_repeat_hours', '48');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 25 HOUR WHERE alert_key = 'sync.rmm.21.failing'");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 2, 'alert_repeat_hours = 48: 25 h later is still suppressed');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 49 HOUR WHERE alert_key = 'sync.rmm.21.failing'");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 3, '... and sent after 48 h');
RecoverySettings::set($mysqli, 'alert_repeat_hours', '24');

// -- recovered notice, once
$mailedBefore = count($GLOBALS['mailed']);
$rmmRun(21, '1 MINUTE', 'success');
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === $mailedBefore + 1 && str_contains($GLOBALS['mailed'][$mailedBefore]['subject'], 'recovered') && $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'sync.rmm.21.%'") === '0', 'recovered: one "has recovered" email and the alert state is cleared');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === $mailedBefore + 1, 'recovered: sent only once');
// a healthy integration that never alerted produces no recovered notice
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (22, 'Quiet', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY)");
$rmmRun(22, '1 MINUTE', 'success');
SyncWatch::run($mysqli, true);
$ok($GLOBALS['mailed'] === [] && $GLOBALS['notified'] === [], 'a healthy integration that never alerted sends nothing (no recovered notice)');
// fails again after recovery: first occurrence is immediate again
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (23, 'Flaky', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY)");
$rmmRun(23, '30 MINUTE', 'failed', 'e'); $rmmRun(23, '20 MINUTE', 'failed', 'e'); $rmmRun(23, '10 MINUTE', 'failed', 'e');
SyncWatch::run($mysqli, true);
$rmmRun(23, '5 MINUTE', 'success'); SyncWatch::run($mysqli, true);   // recovered notice
$m = count($GLOBALS['mailed']);
$rmmRun(23, '4 MINUTE', 'failed', 'e'); $rmmRun(23, '3 MINUTE', 'failed', 'e'); $rmmRun(23, '2 MINUTE', 'failed', 'e');
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === $m + 1 && str_contains(end($GLOBALS['mailed'])['subject'], 'failing'), 'a new failure after a recovery alerts immediately again');

// -- stale and expected-interval override (an external daily script)
$reset();
$q("INSERT INTO unifi_integrations (id, name, host, api_key_enc, enabled, created_at) VALUES (2, 'Script UniFi', 'u.example', 'x', 1, NOW() - INTERVAL 100 DAY)");
RecoverySettings::set($mysqli, 'sync_interval_unifi', '15');
$q("INSERT INTO unifi_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (2, NOW() - INTERVAL 20 HOUR, NOW() - INTERVAL 20 HOUR, 'success', 0)");
$p = SyncWatch::run($mysqli, true);
$ok($problem($p, 'unifi', 2)['state'] === 'stale' && count($GLOBALS['mailed']) === 1, 'a daily external script against the 15 min setting: stale email (the false positive)');
$reset();
$q("INSERT INTO unifi_integrations (id, name, host, api_key_enc, enabled, created_at) VALUES (2, 'Script UniFi', 'u.example', 'x', 1, NOW() - INTERVAL 100 DAY)");
$q("INSERT INTO unifi_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (2, NOW() - INTERVAL 20 HOUR, NOW() - INTERVAL 20 HOUR, 'success', 0)");
RecoverySettings::set($mysqli, 'sync_interval_unifi', '15');
$ok(WatchOverrides::setInterval($mysqli, 'unifi', 2, 86400, 'external sync script', 7), 'override: expected interval 24 h saved');
$p = SyncWatch::run($mysqli, true);
$w = $inspect('unifi', 2);
$ok($problem($p, 'unifi', 2) === null && $w['state'] === 'ok' && $w['interval_min'] === 1440 && $w['interval_source'] === 'override' && $GLOBALS['mailed'] === [], 'override: 20 h since the last run of a daily script is healthy, shown as an override of 24 h, no email');
$q("UPDATE unifi_sync_log SET started_at = NOW() - INTERVAL 80 HOUR, finished_at = NOW() - INTERVAL 80 HOUR");
$p = SyncWatch::run($mysqli, true);
$ok($problem($p, 'unifi', 2)['state'] === 'stale' && count($GLOBALS['mailed']) === 1 && str_contains($problem($p, 'unifi', 2)['detail'], 'expected every 24'), 'override: 80 h (over 3 x 24 h) is stale, and says what was expected');
$ok(!WatchOverrides::setInterval($mysqli, 'unifi', 2, 30, 'x', 7) && !WatchOverrides::setInterval($mysqli, 'unifi', 2, 91 * 86400, 'x', 7) && !WatchOverrides::setInterval($mysqli, 'bogus', 2, 3600, 'x', 7) && !WatchOverrides::setInterval($mysqli, 'unifi', 0, 3600, 'x', 7), 'override: under 1 min, over 90 days, an unknown source and id 0 are refused');
$ok(WatchOverrides::setInterval($mysqli, 'unifi', 2, null, '', 7) && $inspect('unifi', 2)['interval_source'] === 'setting', 'override: clearing it returns to the setting');
$ok((int) $one("SELECT COUNT(*) FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 2") === 1, 'override: one row per (source, integration), updated in place');

// -- mute
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (31, 'Muted one', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY)");
$rmmRun(31, '30 MINUTE', 'failed', 'e'); $rmmRun(31, '20 MINUTE', 'failed', 'e'); $rmmRun(31, '10 MINUTE', 'failed', 'e');
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1, 'mute: setup, the failing integration alerts once');
$ok(WatchOverrides::setMuted($mysqli, 'rmm', 31, true, 7), 'mute: muted');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 30 HOUR");
$p = SyncWatch::run($mysqli, true);
$w = $inspect('rmm', 31);
$ok($p === [] && count($GLOBALS['mailed']) === 1 && $w['state'] === 'muted' && $w['underlying'] === 'failing', 'mute: no email even past the repeat window; the panel state is muted and still knows it would be failing');
$ok($one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key LIKE 'sync.rmm.31.%'") === '0', 'mute: the alert state is cleared (no recovered notice for a muted one)');
$rmmRun(31, '1 MINUTE', 'success'); SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1, 'mute: becoming healthy while muted sends no recovered notice');
WatchOverrides::setMuted($mysqli, 'rmm', 31, false, 7);
$ok($inspect('rmm', 31)['state'] === 'ok', 'mute: unmuting returns to the real state');

// -- dormant: notice only when it was healthy and only once while newly dormant
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (41, 'Recently stopped', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (41, NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 16 DAY, 'success', 0)");
$p = SyncWatch::run($mysqli, true);
$ok($problem($p, 'rmm', 41)['state'] === 'dormant' && count($GLOBALS['mailed']) === 1 && str_contains($GLOBALS['mailed'][0]['subject'], 'no longer running'), 'dormant: healthy until 16 days ago: ONE "no longer running" notice');
SyncWatch::run($mysqli, true); SyncWatch::run($mysqli, true);
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 25 HOUR");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1, 'dormant: no repeat on the next ticks or after 24 h (30 day repeat)');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 31 DAY");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 2, 'dormant: allowed again after 30 days while still newly dormant');
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (42, 'Long gone', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (42, NOW() - INTERVAL 120 DAY, NOW() - INTERVAL 120 DAY, 'success', 0)");
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (43, 'Never ran', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (44, 'Failed then dormant', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by, errors) VALUES (44, NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 16 DAY, 'failed', 0, 'e')");
$p = SyncWatch::run($mysqli, true);
$ok($p === [] && $GLOBALS['mailed'] === [], 'dormant: long dormant (120 days), never ran, or last run failed: no email');
$ok($inspect('rmm', 42)['state'] === 'dormant' && $inspect('rmm', 43)['state'] === 'dormant' && $inspect('rmm', 43)['last_ts'] === null && str_contains($inspect('rmm', 43)['detail'], 'never run'), 'dormant: shown as dormant on the panel, "has never run" for the one without runs');
// dormant after a stale alert: the stale state is cleared silently, no recovered notice
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (45, 'Went quiet', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (45, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, 'success', 0)");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1 && str_contains($GLOBALS['mailed'][0]['subject'], 'has stopped'), 'a sync silent for 3 days (under the dormant limit) is still a normal stale alert');
$q("UPDATE rmm_sync_log SET started_at = NOW() - INTERVAL 50 DAY, finished_at = NOW() - INTERVAL 50 DAY");
SyncWatch::run($mysqli, true);
$ok(count($GLOBALS['mailed']) === 1 && $one("SELECT COUNT(*) FROM recovery_alerts WHERE alert_key = 'sync.rmm.45.stale'") === '0', 'turning dormant clears the stale state without a recovered notice or another email');
// dormant threshold setting
$reset();
RecoverySettings::set($mysqli, 'sync_dormant_days', '2');
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (46, 'Tight', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$q("INSERT INTO rmm_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (46, NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY, 'success', 0)");
$ok($inspect('rmm', 46)['state'] === 'dormant', 'the dormant threshold is a setting (2 days: 3 days is dormant)');
RecoverySettings::set($mysqli, 'sync_dormant_days', '14');

// -- forget history
$reset();
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (51, 'Forgettable', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 300 DAY)");
$rmmRun(51, '30 MINUTE', 'failed', 'e'); $rmmRun(51, '20 MINUTE', 'failed', 'e'); $rmmRun(51, '10 MINUTE', 'failed', 'e');
$ok($inspect('rmm', 51)['state'] === 'failing', 'forget: failing before');
$q("UPDATE rmm_sync_log SET started_at = started_at - INTERVAL 1 HOUR WHERE integration_id = 51");
sleep(1);
$ok(WatchOverrides::forgetHistory($mysqli, 'rmm', 51, 7), 'forget: history cleared');
$w = $inspect('rmm', 51);
$ok($w['state'] === 'dormant' && $w['last_ts'] === null && str_contains($w['detail'], 'history cleared') && (int) $one("SELECT COUNT(*) FROM rmm_sync_log WHERE integration_id = 51") === 3, 'forget: shown as dormant "history cleared", nothing deleted');
$p = SyncWatch::run($mysqli, true);
$ok($p === [] && $GLOBALS['mailed'] === [], 'forget: quiet');
$rmmRun(51, '0 SECOND', 'success');
sleep(1);
$ok($inspect('rmm', 51)['state'] === 'ok', 'forget: a new run after it is judged normally');

// -- the old 6 h behaviour is gone for backup alerts too
$ok(RecoveryAlerts::claim($mysqli, 'backup.failed', 'x') === true && RecoveryAlerts::claim($mysqli, 'backup.failed', 'x') === false, 'claim: first immediate, second suppressed');
$q("UPDATE recovery_alerts SET alert_last_sent_at = NOW() - INTERVAL 7 HOUR WHERE alert_key = 'backup.failed'");
$ok(RecoveryAlerts::claim($mysqli, 'backup.failed', 'x') === false, 'claim: 7 h later still suppressed (24 h window)');
$ok(RecoveryAlerts::claim($mysqli, 'backup.failed', 'x', 6) === true, 'claim: an explicit 6 h window still works');

// -- panel rendering
$reset();
$q("INSERT INTO unifi_integrations (id, name, host, api_key_enc, enabled, created_at) VALUES (1, '<b>Old UniFi</b>', 'u', 'x', 1, NOW() - INTERVAL 400 DAY)");
for ($i = 3; $i >= 0; $i--) { $q("INSERT INTO unifi_sync_log (integration_id, started_at, finished_at, status, triggered_by) VALUES (1, NOW() - INTERVAL " . (94 * 1440 + $i * 17) . " MINUTE, NOW(), 'success', 0)"); }
$q("INSERT INTO rmm_integrations (id, name, type, api_url, api_key_enc, enabled, created_at) VALUES (61, 'Healthy RMM', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY), (62, 'Failing RMM', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY), (63, 'Stale RMM', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY), (64, 'Muted RMM', 'tactical_rmm', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY), (65, 'The Agent', 'rivetit_agent', 'https://x', 'x', 1, NOW() - INTERVAL 30 DAY)");
$rmmRun(61, '2 MINUTE', 'success');
$rmmRun(62, '30 MINUTE', 'failed', 'bad key'); $rmmRun(62, '20 MINUTE', 'failed', 'bad key'); $rmmRun(62, '10 MINUTE', 'failed', 'bad key');
$rmmRun(63, '5 HOUR', 'success');
$rmmRun(64, '2 MINUTE', 'success');
WatchOverrides::setMuted($mysqli, 'rmm', 64, true, 7);
WatchOverrides::setInterval($mysqli, 'rmm', 61, 6 * 3600, 'cron <script>x</script>', 7);
SyncWatch::run($mysqli, true);   // so 'last alert sent' has something to show
$q("UPDATE settings SET config_module_enable_rmm = 1 WHERE company_id = 1");
$_SESSION['csrf_token'] = 'tok123';
ob_start(); require __DIR__ . '/../admin/sync_watch_panel.php'; $html = ob_get_clean();
foreach (['healthy', 'failing', 'stale', 'dormant', 'muted'] as $st) { $ok(preg_match('/<span class="badge text-bg-[a-z]+">' . $st . '<\/span>/', $html) === 1, "panel: shows a $st row"); }
$ok(str_contains($html, 'id="sync-watch"') && str_contains($html, 'Integration sync watch'), 'panel: the card is there');
$ok(str_contains($html, 'dormant since') && str_contains($html, '&lt;b&gt;Old UniFi&lt;/b&gt;') && !str_contains($html, '<b>Old UniFi</b>'), 'panel: "dormant since <date>" and the integration name is escaped');
$ok(str_contains($html, 'every 6 h') && str_contains($html, 'set by an admin') && str_contains($html, 'note: cron &lt;script&gt;') && !str_contains($html, '<script>x'), 'panel: the expected interval override and its (escaped) note');
$ok(str_contains($html, 'last alert sent') && str_contains($html, 'the last 3 runs all ended in an error'), 'panel: why it alerted (failing text, when the alert went out)');
$ok(str_contains($html, 'would be') && str_contains($html, 'value="unmute"') && str_contains($html, 'value="mute"') && str_contains($html, 'value="forget"') && str_contains($html, 'value="interval"'), 'panel: Mute / Unmute / Forget history / Expected interval controls');
$ok(!str_contains($html, 'The Agent') && !str_contains($html, 'rivetit_agent'), 'panel: the endpoint agent integration is not listed');
$ok(substr_count($html, 'name="csrf_token" value="tok123"') >= 10 && !preg_match('/\son(click|submit|change)=/i', $html), 'panel: every form carries the CSRF token; no inline handlers');
$ok(preg_match_all('/<form action="post.php" method="post"/', $html) >= 8, 'panel: forms post to post.php');

// -- handler (admin/post/backup_recovery.php, picked from the Referer by admin/post.php)
define('FROM_POST_HANDLER', true);
$post = function (array $d) use ($mysqli) {
    $session_user_id = 7; $session_name = 'Test Admin'; $dbusername = 'x';
    $_POST = $d + ['csrf_token' => 'tok123']; $GLOBALS['flash'] = [];
    try { include __DIR__ . '/../admin/post/backup_recovery.php'; } catch (TestRedirect $e) { /* redirect() exits in the real app */ }
    return end($GLOBALS['flash']) ?: null;
};
$f = $post(['sync_watch_action' => 'mute', 'sw_source' => 'rmm', 'sw_id' => '62']);
$ok($f && $f[0] === 'success' && (int) $one("SELECT override_muted FROM recovery_watch_overrides WHERE override_source = 'rmm' AND override_integration_id = 62") === 1 && $inspect('rmm', 62)['state'] === 'muted', 'handler: mute');
$ok(end($GLOBALS['actions'])[0] === 'Settings' && str_contains(end($GLOBALS['actions'])[2], 'muted the sync watch for RMM Failing RMM') && str_contains(end($GLOBALS['actions'])[2], 'Test Admin'), 'handler: mute is logged with who did it');
$ok(end($GLOBALS['audits'])[0] === 'recovery.sync_watch_changed' && end($GLOBALS['audits'])[1] === 7 && end($GLOBALS['audits'])[4] === 'mute', 'handler: mute is written to the audit trail');
$post(['sync_watch_action' => 'unmute', 'sw_source' => 'rmm', 'sw_id' => '62']);
$ok($inspect('rmm', 62)['state'] === 'failing' && end($GLOBALS['actions'])[2] && str_contains(end($GLOBALS['actions'])[2], 'unmuted'), 'handler: unmute');
$f = $post(['sync_watch_action' => 'interval', 'sw_source' => 'unifi', 'sw_id' => '1', 'sw_interval' => '24', 'sw_unit' => 'h', 'sw_note' => "external script\nnightly"]);
$ok($f[0] === 'success' && (int) $one("SELECT override_expected_interval_s FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === 86400 && $one("SELECT override_note FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === 'external script nightly' && (int) $one("SELECT override_updated_by FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === 7, 'handler: expected interval 24 h with a note (control characters flattened), the editing user recorded');
$ok(str_contains(end($GLOBALS['actions'])[2], '24 h') && end($GLOBALS['audits'])[4] === 'interval', 'handler: interval change is logged and audited');
$post(['sync_watch_action' => 'interval', 'sw_source' => 'unifi', 'sw_id' => '1', 'sw_interval' => '2', 'sw_unit' => 'd']);
$ok((int) $one("SELECT override_expected_interval_s FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === 172800, 'handler: days unit');
$f = $post(['sync_watch_action' => 'interval', 'sw_source' => 'unifi', 'sw_id' => '1', 'sw_interval' => '5000', 'sw_unit' => 'd']);
$ok($f[0] === 'error' && (int) $one("SELECT override_expected_interval_s FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === 172800, 'handler: 5000 days is refused, the old value kept');
$f = $post(['sync_watch_action' => 'interval', 'sw_source' => 'unifi', 'sw_id' => '1', 'sw_interval' => '0', 'sw_unit' => 'm']);
$ok($f[0] === 'error', 'handler: 0 minutes is refused');
$f = $post(['sync_watch_action' => 'interval', 'sw_source' => 'unifi', 'sw_id' => '1', 'sw_interval' => '', 'sw_unit' => 'm']);
$ok($f[0] === 'success' && $one("SELECT override_expected_interval_s FROM recovery_watch_overrides WHERE override_source = 'unifi' AND override_integration_id = 1") === null, 'handler: a blank interval removes the override');
$post(['sync_watch_action' => 'forget', 'sw_source' => 'rmm', 'sw_id' => '62']);
$ok($one("SELECT override_forget_before FROM recovery_watch_overrides WHERE override_source = 'rmm' AND override_integration_id = 62") !== null && end($GLOBALS['audits'])[4] === 'forget', 'handler: forget history');
$n0 = (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides');
$f = $post(['sync_watch_action' => 'mute', 'sw_source' => 'rmm', 'sw_id' => '65']);
$ok($f[0] === 'error' && (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides') === $n0, 'handler: the endpoint agent integration cannot be controlled');
$f = $post(['sync_watch_action' => 'mute', 'sw_source' => 'rmm', 'sw_id' => '99999']);
$ok($f[0] === 'error' && (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides') === $n0, 'handler: an integration that does not exist is refused');
$f = $post(['sync_watch_action' => 'mute', 'sw_source' => "rmm' OR 1=1 -- ", 'sw_id' => '61']);
$ok($f[0] === 'error' && (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides') === $n0, 'handler: a hostile source is refused');
$f = $post(['sync_watch_action' => 'bogus', 'sw_source' => 'rmm', 'sw_id' => '61']);
$ok($f[0] === 'error' && (int) $one('SELECT COUNT(*) FROM recovery_watch_overrides') === $n0, 'handler: an unknown action changes nothing');
$post(['save_recovery_settings' => '1', 'alert_repeat_hours' => '12', 'sync_dormant_days' => '30', 'backup_stale_hours' => '26', 'sync_error_threshold' => '3', 'sync_interval_rmm' => '15', 'sync_interval_intune' => '15', 'sync_interval_unifi' => 'auto']);
$ok(RecoverySettings::get($mysqli, 'alert_repeat_hours') === '12' && RecoverySettings::get($mysqli, 'sync_dormant_days') === '30', 'handler: repeat hours and dormant days are saved settings');
$post(['save_recovery_settings' => '1', 'alert_repeat_hours' => '99999', 'sync_dormant_days' => '-5', 'backup_stale_hours' => '26', 'sync_error_threshold' => '3', 'sync_interval_rmm' => '15', 'sync_interval_intune' => '15', 'sync_interval_unifi' => 'auto']);
$ok(RecoverySettings::get($mysqli, 'alert_repeat_hours') === '720' && RecoverySettings::get($mysqli, 'sync_dormant_days') === '0', 'handler: both are clamped (720 h max, 0 days = dormant rule off)');

// -- before the migration: no table, nothing throws, the watcher still runs
$q("RENAME TABLE recovery_watch_overrides TO zz_recovery_watch_overrides_off");
$mysqli2 = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'), 3306, getenv('RIVETIT_TEST_DB_SOCKET') ?: null);
$ok(!WatchOverrides::ready($mysqli2) && WatchOverrides::all($mysqli2) === [] && is_array(SyncWatch::inspect($mysqli2, true)) && !WatchOverrides::setMuted($mysqli2, 'rmm', 61, true, 7), 'without the table: no overrides, inspect works, writes are refused, nothing throws');
$q("RENAME TABLE zz_recovery_watch_overrides_off TO recovery_watch_overrides");
$reset();

echo $fails === 0 ? "ALL $n PASSED\n" : "$fails of $n FAILED\n";
exit($fails === 0 ? 0 : 1);
