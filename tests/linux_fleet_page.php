<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/* Linux Fleet: renderer, page wiring and navigation.  php tests/linux_fleet_page.php */
function nullable_htmlentities($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../includes/rmm_linux_fleet.php';

$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) { echo ($c ? 'PASS' : 'FAIL') . ": $m\n"; if (!$c) { $fail++; } };
$root = __DIR__ . '/..';

$ok(function_exists('rivetLinuxFleetPage'), 'rivetLinuxFleetPage exists');
if (!function_exists('rivetLinuxFleetPage')) { echo "\n$fail FAILED\n"; exit(1); }

$node = fn(array $o = []) => $o + ['device_id' => 1, 'hostname' => 'pg1', 'asset_id' => 11, 'online' => true, 'status' => 'online', 'os_version' => 'Ubuntu 22.04', 'uptime_s' => 3600, 'scanned' => true,
    'scan_age_days' => 2, 'sec_count' => 3, 'other_count' => 5, 'overdue' => 0, 'reboot_pending' => false, 'failing_checks' => [], 'postgres' => ['state' => 'none', 'detail' => ''], 'manager' => 'apt',
    'health' => ['state' => 'healthy', 'reasons' => []]];
$vm = ['error' => '', 'skipped' => 0, 'capped' => true, 'filters' => ['group' => 0, 'show' => 'all'],
    'totals' => ['nodes' => 3, 'healthy' => 2, 'attention' => 1, 'down' => 0, 'security_pending' => 6],
    'clusters' => [
        ['name' => 'Postgres', 'total' => 3, 'healthy' => 2, 'attention' => 1, 'down' => 0, 'flags' => ['2 nodes need a restart'], 'nodes' => [
            $node(['hostname' => '<script>alert(1)</script>', 'asset_id' => 0, 'os_version' => '<b>x</b>', 'health' => ['state' => 'attention', 'reasons' => ['Restart pending', 'PostgreSQL failing']],
                'postgres' => ['state' => 'fail', 'detail' => '<i>no quorum</i>'], 'failing_checks' => ['disk'], 'reboot_pending' => true]),
            $node(['device_id' => 2, 'hostname' => 'pg2', 'asset_id' => 12, 'postgres' => ['state' => 'ok', 'detail' => '']]),
            $node(['device_id' => 3, 'hostname' => 'pg3', 'asset_id' => 13, 'scanned' => false, 'scan_age_days' => null]),
        ]],
        ['name' => 'Empty', 'total' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'flags' => [], 'nodes' => []],
    ]];
$h = rivetLinuxFleetPage($vm);
$ok(str_contains($h, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($h, '<script'), 'hostname escaped, no raw <script');
$ok(!str_contains($h, '<b>x</b>') && str_contains($h, '&lt;b&gt;x&lt;/b&gt;'), 'os_version escaped');
$ok(!str_contains($h, '<i>no quorum</i>') && str_contains($h, '&lt;i&gt;no quorum'), 'postgres detail escaped');
$ok(str_contains($h, '2 of 3 healthy'), 'badge text "2 of 3 healthy"');
$ok(str_contains($h, 'No Linux servers in this group'), 'empty cluster text');
$ok(str_contains($h, 'Showing the first 60 servers.'), 'capped notice');
$ok(!str_contains(rivetLinuxFleetPage(['capped' => false] + $vm), 'Showing the first 60'), 'no capped notice when not capped');
$ok(substr_count($h, 'rmm_asset.php?asset_id=') === 2 && str_contains($h, 'rmm_asset.php?asset_id=12') && !str_contains($h, 'rmm_asset.php?asset_id=0'), 'asset link only when asset_id > 0');
$ok(str_contains($h, '3 security / 5 other') && str_contains($h, 'Never scanned') && str_contains($h, '2d ago'), 'updates and last-scan cells');
$ok(str_contains($h, 'Failing: ') && str_contains($h, 'Restart pending; PostgreSQL failing'), 'postgres failing and Why cells');
$ok(str_contains($h, 'alert-warning') && str_contains($h, '2 nodes need a restart'), 'flags rendered as alert-warning');
$ok(str_contains($h, '/agent/rmm_patches.php') && str_contains($h, 'Patch compliance'), 'link to patch compliance');
$ok(str_contains($h, 'action="/agent/rmm_linux_fleet.php"') && str_contains($h, 'name="show"') && str_contains($h, 'name="group"'), 'filter form');
foreach (['Servers', 'Healthy', 'Need attention', 'Down', 'Security updates pending'] as $k) { $ok(str_contains($h, '>' . $k . '<'), "KPI $k"); }
$ok(str_contains($h, 'rmmx-pill-warn'), 'warn pill for attention cluster');
$ok(str_contains(rivetLinuxFleetPage(['error' => 'The Linux fleet could not be read.'] + $vm), 'alert-danger'), 'error renders alert');
$one = ['clusters' => [['name' => 'a', 'total' => 1, 'healthy' => 0, 'attention' => 0, 'down' => 1, 'flags' => [], 'nodes' => [$node(['online' => false, 'health' => ['state' => 'down', 'reasons' => ['Offline']]])]]]] + $vm;
$ok(str_contains(rivetLinuxFleetPage($one), 'rmmx-pill-crit'), 'crit pill when any down');

$page = (string) file_get_contents($root . '/agent/rmm_linux_fleet.php');
$ok($page !== '' && strpos($page, "enforceUserPermission('module_rmm')") !== false && strpos($page, 'rivetRmmPatPage(') > strpos($page, "enforceUserPermission('module_rmm')"), 'page enforces module_rmm then rivetRmmPatPage');
$ok(!str_contains($page, '<script') && !str_contains($page, 'onclick') && !str_contains($h, 'onclick'), 'no <script, no onclick');
$ok(str_contains($page, 'rivetLinuxFleetPage(rivetLinuxFleetView('), 'page echoes renderer over view');
$boot = (string) file_get_contents($root . '/includes/rmm_bootstrap.php');
$ok(str_contains($boot, "['patching', '/agent/rmm_linux_fleet.php', 'Linux fleet', 'fa-server', ['rmm_linux_fleet.php'], 'patching']"), 'nav entry');
$ok(str_contains($boot, "'/agent/rmm_linux_fleet.php' => \\RivetCore\\Rmm\\Authz\\RmmAbility::PATCH_VIEW"), 'nav ability mapping');
$ok(str_contains((string) file_get_contents($root . '/agent/includes/side_nav.php'), "'rmm_linux_fleet.php'"), 'side_nav page');

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit($fail ? 1 : 0);
