<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Linux Fleet: pure node/health/cluster model. No DB.  php tests/linux_fleet_model.php */
require __DIR__ . '/../includes/rmm_linux_fleet.php';

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $m\n"; if (!$c) { $fail++; } };

$NOW = 1800000000;
$sum = ['device_id' => 7, 'hostname' => 'pg1', 'asset_id' => 3, 'status' => 'online', 'last_checkin_at' => '2026-11-20 10:00:00', 'os_version' => 'Ubuntu 22.04'];
$scanned = ['scan' => ['state' => 'ok', 'scanned_at' => gmdate('c', $NOW - 3 * 86400 - 100), 'manager' => 'apt', 'reboot_pending' => false],
    'counts' => ['missing_by_kind' => ['critical' => 1, 'security' => 1, 'important' => 0, 'optional' => 2, 'driver' => 0, 'other' => 0], 'missing' => 5, 'reboot_required' => 0],
    'overdue' => ['days' => 7, 'critical_or_security' => 0]];
$never = ['scan' => ['state' => 'never', 'scanned_at' => null, 'manager' => '', 'reboot_pending' => false],
    'counts' => ['missing_by_kind' => [], 'missing' => 0, 'reboot_required' => 0], 'overdue' => ['days' => 7, 'critical_or_security' => 0]];

$ok(rivetLinuxFleetIsLinux([], ['scan' => ['manager' => 'apt']]) === true, 'apt manager is Linux');
$ok(rivetLinuxFleetIsLinux(['os_version' => 'Windows 11'], ['scan' => ['manager' => 'wua']]) === false, 'Windows 11 / wua is not Linux');

$n = rivetLinuxFleetNode($sum, ['pending_reboot' => false, 'uptime_s' => 100, 'checks' => []], $scanned, $NOW);
$ok($n['online'] === true && $n['scanned'] === true && $n['scan_age_days'] === 3, 'scanned node: online, scan_age_days 3');
$ok($n['sec_count'] === 2 && $n['other_count'] === 3, 'sec_count 2, other_count 3');
$ok($n['manager'] === 'apt' && $n['hostname'] === 'pg1' && $n['asset_id'] === 3, 'manager/hostname/asset_id carried');
$h = rivetLinuxFleetHealth($n);
$ok($h['state'] === 'healthy' && $h['reasons'] === [], 'clean node is healthy');

$nn = rivetLinuxFleetNode($sum, ['checks' => []], $never, $NOW);
$ok($nn['scanned'] === false && $nn['scan_age_days'] === null, 'never-scanned: scanned=false, age null');
$h = rivetLinuxFleetHealth($nn);
$ok($h['state'] === 'attention' && in_array('Never scanned', $h['reasons'], true), 'never scanned -> attention "Never scanned"');

$off = rivetLinuxFleetNode(['status' => 'offline'] + $sum, ['checks' => []], $scanned, $NOW);
$ok(rivetLinuxFleetHealth($off)['state'] === 'down', 'offline -> down');

$p = $scanned; $p['overdue']['critical_or_security'] = 3;
$h = rivetLinuxFleetHealth(rivetLinuxFleetNode($sum, ['checks' => []], $p, $NOW));
$ok($h['state'] === 'attention' && in_array('3 security updates overdue', $h['reasons'], true), 'overdue 3 -> "3 security updates overdue"');

$h = rivetLinuxFleetHealth(rivetLinuxFleetNode($sum, ['pending_reboot' => true, 'checks' => []], $scanned, $NOW));
$ok(in_array('Restart pending', $h['reasons'], true), 'pending_reboot -> "Restart pending"');
$pr = $scanned; $pr['scan']['reboot_pending'] = true;
$ok(rivetLinuxFleetNode($sum, ['checks' => []], $pr, $NOW)['reboot_pending'] === true, 'patch scan reboot_pending counts');

$pgn = rivetLinuxFleetNode($sum, ['checks' => [['key' => 'postgres_ready', 'status' => 'crit', 'detail' => 'no response']]], $scanned, $NOW);
$ok($pgn['postgres']['state'] === 'fail', 'postgres_ready crit -> postgres fail');
$ok(in_array('PostgreSQL failing', rivetLinuxFleetHealth($pgn)['reasons'], true), 'reason "PostgreSQL failing"');

$long = "bad\x07" . str_repeat('x', 300);
$pg = rivetLinuxFleetPostgres([['key' => 'PG_conn', 'status' => 'fail', 'detail' => $long]]);
$ok($pg['state'] === 'fail' && !preg_match('/[\x00-\x1f\x7f]/', $pg['detail']) && strlen($pg['detail']) <= 120, 'postgres detail: control chars gone, <=120');
$ok(rivetLinuxFleetPostgres([])['state'] === 'none', 'no postgres checks -> none');
$ok(rivetLinuxFleetPostgres([['key' => 'pg_up', 'status' => 'ok', 'detail' => '']])['state'] === 'ok', 'ok postgres check -> ok');
$ok(rivetLinuxFleetPostgres([['key' => 'postgres', 'status' => 'unknown', 'detail' => '']])['state'] === 'none' || rivetLinuxFleetPostgres([['key' => 'postgres', 'status' => 'unknown', 'detail' => '']])['state'] === 'ok', 'never-reported postgres check does not fail');

$dn = rivetLinuxFleetNode($sum, ['checks' => [['key' => 'disk', 'status' => 'warn', 'detail' => '91%'], ['key' => 'cpu', 'status' => 'ok', 'detail' => '']]], $scanned, $NOW);
$ok($dn['failing_checks'] === ['disk'], 'failing_checks lists non-ok keys');
$ok(in_array('Check failing: disk', rivetLinuxFleetHealth($dn)['reasons'], true), 'reason "Check failing: disk"');
$un = rivetLinuxFleetNode($sum, ['checks' => [['key' => 'disk', 'status' => 'unknown', 'detail' => '']]], $scanned, $NOW);
$ok($un['failing_checks'] === [], 'unknown check does not fail node');

$mk = function (string $host, string $status, bool $reboot, string $os) use ($sum, $scanned, $NOW) {
    return rivetLinuxFleetNode(['hostname' => $host, 'status' => $status, 'os_version' => $os] + $sum, ['pending_reboot' => $reboot, 'checks' => []], $scanned, $NOW);
};
$c = rivetLinuxFleetCluster('pg', [$mk('c', 'online', true, 'Ubuntu 22.04'), $mk('b', 'online', true, 'Ubuntu 24.04'), $mk('a', 'online', false, 'Ubuntu 22.04')]);
$ok(in_array('2 nodes need a restart', $c['flags'], true), 'flag "2 nodes need a restart"');
$ok(in_array('OS versions differ: Ubuntu 22.04, Ubuntu 24.04', $c['flags'], true), 'flag OS versions differ');
$ok($c['total'] === 3 && $c['healthy'] === 1 && $c['attention'] === 2 && $c['down'] === 0 && $c['name'] === 'pg', 'cluster counts');

$c = rivetLinuxFleetCluster('x', [$mk('h', 'online', false, 'U1'), $mk('z', 'online', true, 'U1'), $mk('d', 'offline', false, 'U1'), $mk('a', 'online', true, 'U1')]);
$order = array_map(fn($x) => $x['hostname'], $c['nodes']);
$ok($order === ['d', 'a', 'z', 'h'], 'sort: down, attention (by hostname), healthy');
$ok($c['flags'] === ['2 nodes need a restart'], 'single OS version and offline node reboot not counted extra');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
