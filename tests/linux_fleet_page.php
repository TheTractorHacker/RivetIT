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
$ok(!str_contains($h, 'Showing the first 60 servers.'), 'old capped wording gone');
$ok(!str_contains(rivetLinuxFleetPage(['capped' => false] + $vm), 'not loaded'), 'no capped notice when not capped');
$ok(substr_count($h, 'rmm_asset.php?asset_id=') === 2 && str_contains($h, 'rmm_asset.php?asset_id=12') && !str_contains($h, 'rmm_asset.php?asset_id=0'), 'asset link only when asset_id > 0');
$ok(str_contains($h, '3 security / 5 other') && str_contains($h, 'Never scanned') && str_contains($h, '2d ago') && str_contains(rivetLinuxFleetPage(['clusters' => [['nodes' => [$node(['scan_age_days' => 0])]] + $vm['clusters'][0]]] + $vm), 'Today'), 'updates and last-scan cells');
$ok(str_contains($h, 'Failing: ') && str_contains(rivetLinuxFleetPage(['clusters' => [['nodes' => [$node(['postgres' => ['state' => 'fail', 'detail' => '']])]] + $vm['clusters'][0]]] + $vm), '>Failing<') && str_contains($h, 'Restart pending; PostgreSQL failing'), 'postgres failing and Why cells');
$ok(str_contains($h, 'alert-warning') && str_contains($h, '2 nodes need a restart'), 'flags rendered as alert-warning');
$ok(str_contains($h, '/agent/rmm_patches.php') && str_contains($h, 'Patch compliance'), 'link to patch compliance');
$ok(str_contains($h, 'action="/agent/rmm_linux_fleet.php"') && str_contains($h, 'name="show"') && str_contains($h, 'name="group"'), 'filter form');
foreach (['Servers', 'Healthy', 'Need attention', 'Down', 'Security updates pending'] as $k) { $ok(str_contains($h, '>' . $k . '<'), "KPI $k"); }
$ok(str_contains($h, 'rmmx-pill-warn'), 'warn pill for attention cluster');
$ok(str_contains(rivetLinuxFleetPage(['error' => 'The Linux fleet could not be read.'] + $vm), 'alert-danger'), 'error renders alert');
$one = ['clusters' => [['name' => 'a', 'total' => 1, 'healthy' => 0, 'attention' => 0, 'down' => 1, 'flags' => [], 'nodes' => [$node(['online' => false, 'health' => ['state' => 'down', 'reasons' => ['Offline']]])]]]] + $vm;
$ok(str_contains(rivetLinuxFleetPage($one), 'rmmx-pill-crit'), 'crit pill when any down');

/* finding 3: table classes that give the node table a min-width on phones */
$ok(str_contains($h, 'table table-sm mb-0 rmmx-table') && str_contains($h, '<td class="rmmx-msg">') && str_contains($h, 'table-responsive'), 'node table uses rmmx-table and rmmx-msg, keeps table-responsive');
$ok(!str_contains($h, 'rmmx-scroll'), 'no nested rmmx-scroll');

/* finding 1: capped view */
$cap = ['capped' => true, 'totals' => ['nodes' => 60, 'healthy' => 60, 'attention' => 0, 'down' => 0, 'security_pending' => 0, 'unloaded' => 7],
    'clusters' => [['name' => 'staging', 'total' => 0, 'healthy' => 0, 'attention' => 0, 'down' => 0, 'unloaded' => 3, 'flags' => [], 'nodes' => []],
        ['name' => 'prod', 'total' => 2, 'healthy' => 2, 'attention' => 0, 'down' => 0, 'unloaded' => 2, 'flags' => [], 'nodes' => [$node()]]]] + $vm;
$hc = rivetLinuxFleetPage($cap);
$ok(str_contains($hc, 'alert-warning') && str_contains($hc, 'Read the first 60 servers (servers that are not online first). 7 more devices were not loaded and are not in the counts above.') && str_contains($hc, 'Choose a group to see them.'), 'capped warning with count and group hint');
$ok(!str_contains(rivetLinuxFleetPage(['filters' => ['group' => 5, 'show' => 'all']] + $cap), 'Choose a group to see them.'), 'no group hint when a group is chosen');
$ok(str_contains($hc, '3 devices not loaded') && str_contains($hc, 'Over the 60-server limit for one view.') && !str_contains($hc, 'No Linux servers in this group') && !str_contains($hc, '0 of 0 healthy'), 'unloaded cluster: honest empty text and badge');
$ok(str_contains($hc, '>Not loaded<') || str_contains($hc, 'Not loaded'), 'zero-total cluster badge says Not loaded');
$ok(str_contains($hc, '2 not loaded') && str_contains($hc, '2 of 2 healthy'), 'partly loaded cluster keeps badge and adds N not loaded');
$ok(str_contains($hc, 'first 60 read') && !str_contains($hc, '--tblr-success'), 'capped: Servers hint, Healthy KPI never success');
$ok(str_contains(rivetLinuxFleetPage(['capped' => false, 'totals' => ['nodes' => 3, 'healthy' => 3, 'attention' => 0, 'down' => 0, 'security_pending' => 0, 'unloaded' => 0]] + $vm), '--tblr-success'), 'not capped: Healthy KPI may be success');
$ok(str_contains(rivetLinuxFleetPage(['clusters' => [['unloaded' => 0, 'total' => 0] + $vm['clusters'][1]]] + $vm), 'No Linux servers'), 'zero total, none unloaded: No Linux servers');

/* findings 2 and 6 */
$ok(str_contains(rivetLinuxFleetPage(['list_incomplete' => true] + $vm), 'More than 5,000 devices: those past the first 5,000 by hostname were not checked.') && !str_contains($h, 'More than 5,000'), 'list_incomplete notice');
$ok(str_contains(rivetLinuxFleetPage(['skipped' => 2] + $vm), '2 servers could not be read and are not counted.') && !str_contains($h, 'could not be read'), 'skipped line');

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
