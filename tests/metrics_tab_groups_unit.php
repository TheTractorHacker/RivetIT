<?php
/* Metrics tab group selection (RivetIT 26.10.63): which charts a device gets, what is summarised as "not reported", the last-sample hint. No DB, no server.
 *   php tests/metrics_tab_groups_unit.php */
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
require dirname(__DIR__) . '/agent/includes/asset/metrics_tab.php';   // no $mysqli: defines the helpers, then returns
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };

$spec = fn($unit, $last = null) => ['unit' => $unit, 'precision' => 1, 'last_sample_at' => $last];
$status = ['cpu.utilization' => 'available', 'memory.utilization' => 'available', 'network.rx_bytes_per_s' => 'available', 'network.tx_bytes_per_s' => 'available',
           'disk.free_bytes' => 'available', 'disk.total_bytes' => 'stale', 'system.uptime_seconds' => 'available', 'system.pending_reboot' => 'available',
           'memory.total_bytes' => 'stale', 'gpu.utilization' => 'unavailable'];
$specs = ['cpu.utilization' => $spec('percent', '2026-10-20T10:00:00Z'), 'disk.free_bytes' => $spec('bytes', '2026-10-20T09:00:00Z'), 'disk.total_bytes' => $spec('bytes', '2026-10-20T11:00:00Z'),
          'memory.total_bytes' => $spec('bytes', '2026-10-10T12:00:00Z')];
$r = metrics_tab_select_groups(metrics_tab_groups(), $status, $specs);
$ids = array_column($r['groups'], 'id');

$ok($ids === ['cpu', 'mem', 'mem-bytes', 'disk-bytes', 'net', 'uptime', 'reboot'], 'only groups with a reported key are kept, in catalogue order: ' . implode(',', $ids));
$ok($r['request_keys'] === ['cpu.utilization', 'memory.utilization', 'memory.total_bytes', 'disk.free_bytes', 'disk.total_bytes', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s', 'system.uptime_seconds', 'system.pending_reboot'], 'request keys: only live keys, no duplicates');
$by = array_column($r['groups'], null, 'id');
$ok($by['mem-bytes']['keys'] === ['memory.total_bytes'] && $by['mem-bytes']['headline'] === null, 'a group keeps only its live keys and drops a headline that is not live');
$ok($by['disk-bytes']['headline'] === 'disk.free_bytes' && $by['disk-bytes']['last_sample_at'] === '2026-10-20T11:00:00Z', 'last_sample_at is the newest across the live keys');
$ok($by['mem-bytes']['last_sample_at'] === '2026-10-10T12:00:00Z', 'a stale-only group still carries its last sample');
$ok($by['cpu']['unit'] === 'percent' && $by['reboot']['last_sample_at'] === null, 'unit from the first live key; no spec -> null last sample');
foreach (['gpu', 'gpu-mem', 'gpu-temp', 'battery', 'disk-iops', 'disk-queue', 'disk-lat', 'cpu-cores', 'net-err', 'disk'] as $g) {
    $ok(!in_array($g, $ids, true), "never-reported group $g is not rendered");
}
$ok($r['missing'] === ['Per-core utilization', 'Disk used', 'Disk IOPS', 'Disk queue length', 'Disk latency', 'Network errors', 'GPU', 'Battery'], 'missing list: ' . implode(' | ', $r['missing']));

$none = metrics_tab_select_groups(metrics_tab_groups(), [], []);
$ok($none['groups'] === [] && $none['request_keys'] === [] && $none['missing'] === ['CPU', 'Memory', 'Storage', 'Network', 'GPU', 'Battery', 'System'], 'no data: no groups, whole families named once: ' . implode(',', $none['missing']));
$ok(metrics_tab_select_groups([], $status, $specs) === ['groups' => [], 'request_keys' => [], 'missing' => []], 'empty catalogue');

echo "\n$passes passed, $fails failed\n";
exit($fails ? 1 : 0);
