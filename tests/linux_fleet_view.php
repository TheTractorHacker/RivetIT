<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Linux Fleet: pure assembly of the view model (rivetLinuxFleetBuild) over injected data. No DB, no Core.  php tests/linux_fleet_view.php */
require __DIR__ . '/../includes/rmm_linux_fleet.php';

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $m\n"; if (!$c) { $fail++; } };

$src = (string) file_get_contents(__DIR__ . '/../includes/rmm_linux_fleet.php');
$ok(function_exists('rivetLinuxFleetView') && function_exists('rivetLinuxFleetBuild'), 'rivetLinuxFleetView and rivetLinuxFleetBuild exist');
$ok(!function_exists('rivetRmmPatPage'), 'loading the file does not load rmm_pat_ui.php');
$ok(!preg_match('/^(require|include)(_once)?\b/m', $src), 'no include at file top level');
$ok(preg_match('/function rivetLinuxFleetView\b[^{]*\{[^}]*?require_once __DIR__ \. \'\/rmm_pat_ui\.php\';/s', $src) === 1, 'rivetLinuxFleetView requires rmm_pat_ui.php lazily');

$NOW = 1800000000;
$dev = fn(int $id, string $host, string $status = 'online', string $os = 'Ubuntu 22.04') => ['device_id' => $id, 'hostname' => $host, 'asset_id' => $id, 'status' => $status, 'os_version' => $os, 'last_checkin_at' => '2026-11-20 10:00:00'];
$linuxPatch = ['scan' => ['state' => 'ok', 'scanned_at' => gmdate('c', $NOW - 86400), 'manager' => 'apt', 'reboot_pending' => false], 'counts' => ['missing_by_kind' => ['security' => 1], 'missing' => 2], 'overdue' => ['critical_or_security' => 0]];
$winPatch = ['scan' => ['state' => 'ok', 'scanned_at' => gmdate('c', $NOW), 'manager' => 'wua', 'reboot_pending' => false], 'counts' => ['missing_by_kind' => [], 'missing' => 0], 'overdue' => ['critical_or_security' => 0]];
$fetch = function (array $bad = [], array $skip = []) use ($linuxPatch, $winPatch) {
    return function (int $id) use ($bad, $skip, $linuxPatch, $winPatch): ?array {
        $s = ['device_id' => $id, 'hostname' => $id === 9 ? 'win1' : 'h' . $id, 'asset_id' => $id, 'status' => 'online', 'os_version' => 'Ubuntu 22.04', 'last_checkin_at' => null];
        if (in_array($id, $skip, true)) { return null; }
        if (str_starts_with($s['hostname'], 'win')) { return ['summary' => $s, 'detail' => ['os_version' => 'Windows 11', 'checks' => []], 'patch' => $winPatch]; }
        return ['summary' => $s, 'detail' => ['checks' => in_array($id, $bad, true) ? [['key' => 'disk', 'status' => 'crit', 'detail' => '']] : []], 'patch' => $linuxPatch];
    };
};
$groups = [['group_id' => 1, 'name' => 'pg'], ['group_id' => 2, 'name' => 'empty'], ['group_id' => 3, 'name' => 'web']];

$r = rivetLinuxFleetBuild($groups, [1 => [$dev(1, 'a'), $dev(2, 'b'), $dev(9, 'win1')], 2 => [], 3 => [$dev(2, 'b'), $dev(4, 'd')], 0 => [$dev(5, 'e')]], $fetch([2]), $NOW);
$byName = [];
foreach ($r['clusters'] as $c) { $byName[$c['name']] = $c; }
$ok(isset($byName['empty']) && $byName['empty']['total'] === 0 && $byName['empty']['nodes'] === [], 'empty group shows with zero nodes');
$ok($byName['pg']['total'] === 2, 'Windows device excluded from cluster');
$ok($byName['pg']['total'] === 2 && $byName['web']['total'] === 2, 'device in two groups is in both clusters');
$ok($r['totals']['nodes'] === 4, 'device in two groups counted once in totals (4 nodes)');
$ok($r['totals']['attention'] === 1 && $r['totals']['healthy'] === 3 && $r['totals']['down'] === 0, 'totals health: 1 attention, 3 healthy');
$ok($r['totals']['security_pending'] === 4, 'security_pending sums once per device');
$ok(isset($byName['Ungrouped']) && $byName['Ungrouped']['nodes'][0]['hostname'] === 'e', 'ungrouped device lands in Ungrouped');
$ok($r['capped'] === false && $r['skipped'] === 0, 'not capped, none skipped');

$r = rivetLinuxFleetBuild($groups, [1 => [$dev(1, 'a'), $dev(2, 'b')], 2 => [], 3 => [], 0 => []], $fetch([], [2]), $NOW);
$ok($r['skipped'] === 1 && $r['totals']['nodes'] === 1, 'fetch returning null is skipped and counted');
$names = array_column($r['clusters'], 'name');
$ok(!in_array('Ungrouped', $names, true), 'empty Ungrouped is not shown');

$many = [];
for ($i = 1; $i <= 61; $i++) { $many[] = $dev(100 + $i, sprintf('n%02d', $i)); }
$r = rivetLinuxFleetBuild([['group_id' => 1, 'name' => 'big']], [1 => $many], $fetch(), $NOW);
$ok($r['capped'] === true && $r['totals']['nodes'] === 60 && $r['clusters'][0]['total'] === 60, '61 devices -> capped, 60 nodes');

$r = rivetLinuxFleetBuild($groups, [1 => [$dev(1, 'a'), $dev(2, 'b')], 2 => [], 3 => [$dev(4, 'd')], 0 => []], $fetch([2]), $NOW, 'attention');
$names = array_column($r['clusters'], 'name');
$ok($names === ['pg'] && count($r['clusters'][0]['nodes']) === 1 && $r['clusters'][0]['nodes'][0]['hostname'] === 'b', 'show=attention drops healthy nodes and emptied clusters');
$ok($r['totals']['nodes'] === 3, 'totals still describe the whole fleet under show=attention');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
