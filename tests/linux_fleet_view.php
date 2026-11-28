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

/* finding 1: above the cap, honest counts. Load ids are chosen before any fetch, not-online first. */
$bulk = [];
for ($i = 1; $i <= 60; $i++) { $bulk[] = $dev(1000 + $i, sprintf('b%02d', $i)); }
$gcap = [['group_id' => 1, 'name' => 'a-bulk'], ['group_id' => 2, 'name' => 'prod'], ['group_id' => 3, 'name' => 'staging']];
$mcap = [1 => $bulk, 2 => [$dev(2001, 'p1', 'offline'), $dev(2002, 'p2'), $dev(2003, 'p3')], 3 => [$dev(3001, 's1'), $dev(3002, 's2'), $dev(3003, 's3')], 0 => [$dev(4001, 'u1')]];
$calls = [];
$cf = function (int $id) use ($fetch, &$calls) { $calls[] = $id; return $fetch()($id); };
$r = rivetLinuxFleetBuild($gcap, $mcap, $cf, $NOW);
$cb = []; foreach ($r['clusters'] as $c) { $cb[$c['name']] = $c; }
$ok($r['capped'] === true && $r['totals']['nodes'] === 60 && $r['totals']['unloaded'] === 7, 'cap: capped, 60 nodes, 7 unloaded');
$ok($r['totals']['down'] === 1, 'cap: the offline server is read first, Down is 1');
$ok(count($calls) === 60 && in_array(2001, $calls, true), 'cap: exactly 60 fetches, offline one among them');
$ok($cb['staging']['nodes'] === [] && $cb['staging']['unloaded'] === 3, 'cap: staging shows 0 nodes, 3 unloaded');
$ok($cb['prod']['unloaded'] === 2 && $cb['prod']['total'] === 1 && $cb['prod']['down'] === 1, 'cap: prod 1 loaded (down), 2 unloaded');
$ok(isset($cb['Ungrouped']) && $cb['Ungrouped']['unloaded'] === 1 && $cb['Ungrouped']['nodes'] === [], 'cap: Ungrouped present with 1 unloaded');
$r = rivetLinuxFleetBuild($gcap, $mcap, $fetch(), $NOW, 'attention');
$cb = []; foreach ($r['clusters'] as $c) { $cb[$c['name']] = $c; }
$ok(isset($cb['staging']) && isset($cb['Ungrouped']) && isset($cb['prod']) && isset($cb['a-bulk']), 'attention + cap: unloaded clusters stay (a-bulk has 1 unloaded)');
$ok($cb['staging']['unloaded'] === 3 && $cb['Ungrouped']['unloaded'] === 1, 'attention + cap: unloaded counts kept');
$r = rivetLinuxFleetBuild([['group_id' => 1, 'name' => 'g']], [1 => [$dev(1, 'a'), $dev(2, 'b')]], $fetch(), $NOW);
$ok($r['capped'] === false && $r['totals']['unloaded'] === 0 && $r['clusters'][0]['unloaded'] === 0, 'under the cap: unloaded 0');
$many = [];
for ($i = 1; $i <= 61; $i++) { $many[] = $dev(100 + $i, sprintf('n%02d', $i)); }
$r = rivetLinuxFleetBuild([['group_id' => 1, 'name' => 'big']], [1 => $many], $fetch(), $NOW);
$ok($r['capped'] === true && $r['totals']['nodes'] === 60 && $r['clusters'][0]['total'] === 60 && $r['clusters'][0]['unloaded'] === 1, '61 devices -> capped, 60 nodes, 1 unloaded');

/* finding 6: revoked devices are never fetched and are absent */
$calls = [];
$cf = function (int $id) use ($fetch, &$calls) { $calls[] = $id; return $fetch()($id); };
$rev = ['revoked' => true] + $dev(7, 'gone', 'offline');
$r = rivetLinuxFleetBuild([['group_id' => 1, 'name' => 'g']], [1 => [$dev(1, 'a'), $rev]], $cf, $NOW);
$ok(!in_array(7, $calls, true) && $r['clusters'][0]['total'] === 1 && $r['totals']['down'] === 0 && $r['totals']['unloaded'] === 0, 'revoked device: not fetched, not in cluster, not Down');

$r = rivetLinuxFleetBuild($groups, [1 => [$dev(1, 'a'), $dev(2, 'b')], 2 => [], 3 => [$dev(4, 'd')], 0 => []], $fetch([2]), $NOW, 'attention');
$names = array_column($r['clusters'], 'name');
$ok($names === ['pg'] && count($r['clusters'][0]['nodes']) === 1 && $r['clusters'][0]['nodes'][0]['hostname'] === 'b', 'show=attention drops healthy nodes and emptied clusters');
$ok($r['totals']['nodes'] === 3, 'totals still describe the whole fleet under show=attention');

/* carry-over a: group visibility */
$gs = [['group_id' => 1, 'name' => 'full', 'device_count' => 2], ['group_id' => 2, 'name' => 'hidden', 'device_count' => 0]];
$ok(array_column(rivetLinuxFleetPickGroups($gs, null, 0), 'name') === ['full', 'hidden'], 'unrestricted caller sees a zero-device group');
$ok(array_column(rivetLinuxFleetPickGroups($gs, [5], 0), 'name') === ['full'], 'restricted caller does not see a zero-device group (no scope leak)');
$ok(array_column(rivetLinuxFleetPickGroups($gs, null, 2), 'name') === ['hidden'] && rivetLinuxFleetPickGroups($gs, [5], 2) === [], 'group filter honoured in both modes');

/* carry-over b: Windows/macOS rows do not consume cap slots */
$mix = [];
for ($i = 1; $i <= 70; $i++) { $mix[] = $dev(200 + $i, 'w' . $i, 'online', $i % 3 === 0 ? 'macOS 14' : ($i % 3 === 1 ? 'Microsoft Windows 11' : 'Darwin 23')); }
for ($i = 1; $i <= 5; $i++) { $mix[] = $dev(300 + $i, 'L' . $i); }
$r = rivetLinuxFleetBuild([['group_id' => 1, 'name' => 'mixed']], [1 => $mix], $fetch(), $NOW);
$ok($r['capped'] === false && $r['totals']['nodes'] === 5 && count($r['clusters'][0]['nodes']) === 5, '70 Windows/macOS + 5 Linux: not capped, 5 nodes');
$kept = rivetLinuxFleetNotOtherOs([$dev(1, 'a', 'online', ''), $dev(2, 'b', 'online', 'Windows 10'), $dev(3, 'c', 'online', 'Debian 12')]);
$ok(array_column($kept, 'hostname') === ['a', 'c'], 'empty os_version kept, Windows dropped');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
