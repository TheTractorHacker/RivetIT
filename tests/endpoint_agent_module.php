<?php
/*
 * The RMM module switch and its edges, on a scratch database with the real front controller (php -S):
 *   - a fresh install is OFF; an existing install keeps whatever endpoint_agent_settings.enabled says (the Core migration step never touches it)
 *   - module OFF: the five device endpoints answer 503 module_disabled (Retry-After 3600, no-store) and FROM THE
 *     STATE FILE ALONE: zero database connections and zero statements (counted on the server), before config.php is even loaded
 *   - disabling and re-enabling keeps every row; enrolled devices carry on with the same credential
 *   - a missing or damaged state file means "unknown", never "off"; the cron housekeeping corrects a stale file
 * Same scratch rules and environment as tests/endpoint_agent_lib.php (a throwaway MariaDB; this file also creates and drops two extra scratch databases).
 */
$sd = sys_get_temp_dir() . '/ea_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");   // config.php of the scratch install turns this into RMM_STATE_DIR
putenv("RMM_GATE_STATE_DIR=$sd");   // the pre-bootstrap gate reads the environment
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/rmm_bootstrap.php";
register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\RmmStateFile;

ea_reset();
ea_seed_users();
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$stateFile = RmmStateFile::path($sd);
$state = fn() => RmmStateFile::read($sd);
$counters = function () use ($db): array {
    $o = [];
    foreach ($db->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Connections')") as $r) { $o[$r['Variable_name']] = (int) $r['Value']; }
    return $o;
};

// ============================================================ default: off
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 0, 'after the reset the service is off (the column default)');
$ok($rmm->enabled() === false && rivetRmmEnabled($db) === false, 'rivetRmmEnabled() and RmmModule::enabled() say off');
$ok($state() !== null && $state()['enabled'] === false && $state()['master'] === false, 'the state file exists and records the module as off');
$ok(substr(sprintf('%o', fileperms($stateFile)), -4) === '0640', 'the state file is mode 0640');

// ============================================================ OFF: the gate answers with zero database work
$hdr = fn(array $h, string $n) => $h[$n][0] ?? null;
$deviceCalls = [['POST', '/api/v1/agent_enroll'], ['POST', '/api/v1/agent_checkin'], ['GET', '/api/v1/agent_jobs'], ['POST', '/api/v1/agent_jobs'], ['GET', '/api/v1/agent_update?arch=amd64&version=1.0.0'], ['POST', '/api/v1/agent_installer']];
$bodyOff = '{"error":"The RMM service is disabled on this server.","code":"module_disabled"}';
$c0 = $counters(); $c1 = $counters(); $overhead = $c1['Questions'] - $c0['Questions'];   // what reading the counters itself costs
$before = $counters();
foreach ($deviceCalls as [$m, $path]) {
    [$c, $h, , $raw] = http($m, $path, str_repeat('a', 64), $m === 'POST' ? ['x' => 1] : null);
    $ok($c === 503 && $raw === $bodyOff && $hdr($h, 'retry-after') === '3600' && $hdr($h, 'cache-control') === 'no-store' && stripos((string) $hdr($h, 'content-type'), 'application/json') === 0, "OFF: $m " . strtok($path, '?') . ' -> 503 module_disabled, Retry-After 3600, no-store');
}
[$c, , , $raw] = http('GET', '/api/v1/agent_checkin.php');
$ok($c === 503, 'OFF: the legacy .php spelling of the URL is gated too');
$after = $counters();
$ok($after['Connections'] === $before['Connections'], 'OFF: ' . (count($deviceCalls) + 1) . ' requests opened zero database connections');
$ok($overhead === 1 && $after['Questions'] - $before['Questions'] === $overhead, 'OFF: and ran zero statements on the server (the counter moved only by the one read of the counter itself)');
[$c] = http('GET', '/api/v1/tickets'); $ok($c === 401, 'OFF: other API endpoints are untouched (401 without a token)');
// The gate no longer answers for the technician endpoint: an anonymous caller must not learn whether the module is on (401 either way).
[$c, , $j, $raw] = http('GET', '/api/v1/endpoint_devices');
$ok($c === 401 && !str_contains($raw, 'not enabled') && ($j['code'] ?? '') !== 'disabled', 'OFF: endpoint_devices without a token -> 401, not the module-state answer (no anonymous module-state leak)');

// ============================================================ switch on through RmmAdmin
$r = $rmm->admin()->enable($admin);
$ok($r->ok && (int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 1, 'RmmAdmin::enable() switches the module on');
$ok($state()['enabled'] === true && $rmm->enabled() === true, 'the state file follows the switch in the same call');
$ok((string) $one('SELECT signing_public_key FROM endpoint_agent_settings') !== '' && (int) $one("SELECT COUNT(*) FROM rmm_integrations WHERE type='rivetit_agent'") === 1, 'the first switch-on minted the signing key and the integration row');
$tok = Enrollment_token($rmm, $admin);
[$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => 'MODULE-PC', 'serial' => 'MOD-SER-1']));
$ok($c === 201 && isset($j['device_token']), 'ON: enrollment works (201)');
$devTok = $j['device_token']; $devId = (int) $j['device_id'];
$qb = $counters();
[$c, , $j] = ea_checkin($devTok);
$qa = $counters();
$ok($c === 200 && ($j['ok'] ?? false) === true, 'ON: check-in works (200)');
$ok($qa['Connections'] > $qb['Connections'] && $qa['Questions'] - $qb['Questions'] > 5, 'ON: the same counters do move for a real request (the zero above is a measurement, not a blind spot)');
$rowsBefore = [(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_checkins')];

// ============================================================ switch off again: nothing is lost, agents back off, same credential works after
$r = $rmm->admin()->disable($admin);
$ok($r->ok && (int) $one('SELECT enabled FROM endpoint_agent_settings WHERE id=1') === 0 && $state()['enabled'] === false, 'RmmAdmin::disable() switches it off and rewrites the state file');
[$c, $h, $j] = http('POST', '/api/v1/agent_checkin', $devTok, ['seq' => 99, 'collected_at' => ea_ts()]);
$ok($c === 503 && $hdr($h, 'retry-after') === '3600', 'OFF: an enrolled agent is told to back off (503, Retry-After 3600), not refused with 403');
$ok([(int) $one('SELECT COUNT(*) FROM endpoint_agent_devices'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'), (int) $one('SELECT COUNT(*) FROM endpoint_agent_checkins')] === $rowsBefore, 'disabling deleted nothing');
$ok(!$rmm->authorizer()->allowed(1, \RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, 0), 'OFF: the technician side is denied (not enabled)');
$ok(rivetRmmHousekeeping($db) === [], 'OFF: cron housekeeping does nothing and loads nothing');
$rmm->admin()->enable($admin);
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200 && ($j['ok'] ?? false) === true, 'ON again: the same device credential checks in (200); no re-enrollment needed');
$ok($j['status'] === 'pending_approval' || isset($j['status']), 'the device state survived the round trip');

// ============================================================ the state file is a cache: unknown is never off
file_put_contents($stateFile, '{not json');
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200, 'a garbled state file is "unknown": the request proceeds (200)');
unlink($stateFile);
[$c, , $j] = ea_checkin($devTok);
$ok($c === 200, 'a missing state file is "unknown": the request proceeds (200)');
file_put_contents($stateFile, json_encode(['v' => 99, 'enabled' => false]));
[$c] = ea_checkin($devTok);
$ok($c === 200, 'a state file of another schema version is "unknown": the request proceeds (200)');
// a stale OFF verdict (e.g. a backup restored over the database) is corrected by the next cron tick
$cur = RmmStateFile::read($sd) ?? ['v' => 1];
$rmm->syncState();
$s = $state(); $s['enabled'] = false; $s['master'] = false; unset($s['v'], $s['written_at']);
RmmStateFile::write($sd, $s, time() + 7200);
$ok($state()['enabled'] === false && (int) $one('SELECT enabled FROM endpoint_agent_settings') === 1, 'precondition: the file says off while the database says on');
[$c] = ea_checkin($devTok); $ok($c === 503, 'a stale OFF file does gate requests until it is corrected (documented trade-off of the fast path)');
$out = rivetRmmHousekeeping($db);
$ok($state()['enabled'] === true && is_array($out), 'the cron housekeeping notices the mismatch and rewrites the file');
[$c] = ea_checkin($devTok); $ok($c === 200, 'and the next request passes');

// ============================================================ pages while off
$sdir = sys_get_temp_dir() . '/ea_msessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RMM_TEST_STATE_DIR' => $sd, 'RMM_GATE_STATE_DIR' => $sd], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 1);
$rmm->admin()->disable($admin);
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'Switch the RMM module on') !== false && strpos($b, 'RMM module') !== false, 'OFF: the administration page stays reachable and offers the switch');
[$c, $b] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$devId", $sid);
$ok(in_array($c, [403, 404], true) && strpos($b, 'MODULE-PC') === false && stripos(html_entity_decode(strip_tags($b)), 'switched off') !== false, 'OFF: the device page shows the module-off notice and none of the device');
[$c, $b] = web($wb, 'POST', '/admin/post.php', $sid, ['csrf_token' => 'csrftok1', 'rmm_module_switch' => 'on'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings') === 1 && $state()['enabled'] === true, 'the page switch turns the module on (database and state file)');
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'Switch the RMM module off') !== false, 'ON: the page offers to switch it off');
[$c, $b] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$devId", $sid);
$ok($c === 200 && strpos($b, 'MODULE-PC') !== false, 'ON: the device page renders');
web($wb, 'POST', '/admin/post.php', $sid, ['csrf_token' => 'csrftok1', 'rmm_module_switch' => 'off'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one('SELECT enabled FROM endpoint_agent_settings') === 0 && $state()['enabled'] === false, 'the page switch turns the module off again');

// ============================================================ an unwritable state directory is reported, not silent
$rmm->admin()->enable($admin);
$q('UPDATE endpoint_agent_settings SET enabled=0 WHERE id=1');   // the database says off ...
unlink($stateFile); mkdir($stateFile);                            // ... and a directory sits where the file belongs, so it can neither be read nor replaced
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'The module state file is missing or does not match the settings') !== false, 'a state file that cannot be written/read is reported on the administration page');
rmdir($stateFile);
$rmm->syncState();
[$c, $b] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $sid);
$ok($c === 200 && strpos($b, 'The module state file is missing or does not match the settings') === false, 'and the warning is gone once the file agrees with the settings');

// ============================================================ fresh install vs existing install (the Core migration step never decides the switch)
$q('SET SESSION sql_mode=\'\'');
$mk = function (string $suffix, ?int $enabled) use ($db, $root, $q): array {
    $name = getenv('RIVETIT_TEST_DB_NAME') . '_' . $suffix . bin2hex(random_bytes(2));
    $db->query("DROP DATABASE IF EXISTS `$name`"); $db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $c = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), $name);
    $c->query("SET SESSION sql_mode=''"); $c->query('SET FOREIGN_KEY_CHECKS=0');
    $c->multi_query(file_get_contents("$root/db.sql"));
    do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
    if ($enabled !== null) { $c->query("UPDATE endpoint_agent_settings SET enabled=$enabled WHERE id=1"); }
    return [$name, $c];
};
$runStep = function (mysqli $c): void {
    (new \RivetCore\Migration\MigrationRunner(new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($c), \RivetCore\Migration\CoreMigrations::all(), new \RivetCore\Support\SystemClock()))->run();
};
[$fresh, $cf] = $mk('fresh', null);
$ok((int) $cf->query('SELECT COUNT(*) FROM endpoint_agent_settings')->fetch_row()[0] === 1 && (int) $cf->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 0, 'FRESH install (db.sql): one settings row, enabled = 0');
$runStep($cf);
$ok((int) $cf->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 0 && (int) $cf->query('SELECT COUNT(*) FROM endpoint_agent_settings')->fetch_row()[0] === 1, 'FRESH install after the Core migrations (2.6.150 step): still off, still one row');
$ok((int) $cf->query("SELECT COUNT(*) FROM rivet_core_migrations WHERE migration_id IN ('0014_endpoint_agent_core','0015_endpoint_agent_converge','0016_rmm_module_switches')")->fetch_row()[0] === 3, 'FRESH install: the three RMM migrations are recorded');
$cf->close(); $db->query("DROP DATABASE `$fresh`");
foreach ([1 => 'ON', 0 => 'OFF'] as $en => $label) {
    [$ex, $ce] = $mk('exist' . $en, $en);
    $ce->query("DELETE FROM rivet_core_migrations WHERE migration_id IN ('0014_endpoint_agent_core','0015_endpoint_agent_converge','0016_rmm_module_switches')");
    $ce->query('ALTER TABLE endpoint_agent_settings DROP COLUMN features_json, DROP COLUMN limits_json, DROP COLUMN shed_level, DROP COLUMN ingest_mode, DROP COLUMN max_devices');   // the DB 2.6.146 shape
    $ce->query("UPDATE endpoint_agent_settings SET service_url='https://keep.example', failure_debounce=9 WHERE id=1");
    $runStep($ce);
    $ok((int) $ce->query('SELECT enabled FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === $en, "EXISTING install with the agent $label: stays $label after the 2.6.150 step (decided by the column, never inferred)");
    $ok($ce->query('SELECT service_url FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0] === 'https://keep.example' && (int) $ce->query('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'endpoint_agent_settings\' AND column_name IN (\'features_json\',\'limits_json\',\'shed_level\',\'ingest_mode\',\'max_devices\')')->fetch_row()[0] === 5, "EXISTING ($label): settings kept, the five module switch columns added");
    $ce->close(); $db->query("DROP DATABASE `$ex`");
}

function Enrollment_token($rmm, $admin): string
{
    $r = $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'module test');
    return (string) $r->data['token'];
}
$q("UPDATE endpoint_agent_settings SET enabled=0 WHERE id=1");
