<?php
/*
 * The RMM asset panel and the agent fleet page: view-models, permission matrix, module-off path, states, escaping, query budget, and the real
 * pages over HTTP (forged sessions, `php -S`). Scratch database only (same guard and environment as tests/endpoint_agent_lib.php).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... RIVETIT_REDIS_PORT=<throwaway redis> php tests/rmm_ui.php
 *
 * The scratch config.php needs the same two lines as tests/endpoint_agent_module.php (RMM_TEST_STATE_DIR -> RMM_STATE_DIR), and EA_ALLOW_NON_WINDOWS defined
 * (the fleet includes a Linux device; the golden suite needs it undefined, so the scratch config may define it only when EA_TEST_NON_WINDOWS=1 and this suite is run with that set).
 */
$sd = sys_get_temp_dir() . '/rmm_ui_state_' . bin2hex(random_bytes(4));
putenv("RMM_TEST_STATE_DIR=$sd");
putenv("RMM_GATE_STATE_DIR=$sd");
require __DIR__ . '/endpoint_agent_lib.php';
require __DIR__ . '/support/rmm_ui_seed.php';
require_once "$root/includes/rmm_ui_render.php";
register_shutdown_function(function () use ($sd) { foreach (glob("$sd/*") ?: [] as $f) { @unlink($f); } @rmdir($sd); });
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\RmmStateFile;

$S = rmm_ui_seed();
$D = $S['dev'];
$A = $S['asset'];
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$USER = ['admin' => 1, 'tech' => 10, 'rebootonly' => 11, 'viewer' => 12, 'remoteonly' => 13, 'moduleonly' => 14, 'normo' => 15, 'deptb' => 16];
$panel = fn(string $dev, string $who = 'admin') => rivetRmmUiPanel($db, $A[$dev], $USER[$who]);
$questions = function () use ($db): int { return (int) $db->query("SHOW GLOBAL STATUS LIKE 'Questions'")->fetch_row()[1]; };
$overheadA = $questions(); $overheadB = $questions(); $overhead = $overheadB - $overheadA;

// ============================================================ the pure helpers
$ok(rivetRmmUiBand(null, [80, 95]) === 'off' && rivetRmmUiBand(0.0, [80, 95]) === 'ok' && rivetRmmUiBand(80.0, [80, 95]) === 'warn' && rivetRmmUiBand(95.0, [80, 95]) === 'crit', 'bands: missing is "off" (never "ok"), 0 is ok, edges are inclusive');
$ok(rivetRmmUiBands()['disk'] === [80, 90] && rivetRmmUiBands()['cpu'] === [80, 95], 'the shared display bands are 80/95 for CPU and memory, 80/90 for disks');
$ok(rivetRmmUiDuration(45) === '45 s' && rivetRmmUiDuration(300) === '5 min' && rivetRmmUiDuration(3 * 3600 + 600) === '3 h 10 m' && rivetRmmUiDuration(2 * 86400 + 4 * 3600) === '2 d 4 h' && rivetRmmUiDuration(null) === 'unknown', 'durations read naturally; null is "unknown"');
$ok(rivetRmmUiBytes(null) === 'no data' && rivetRmmUiBytes(17179869184.0) === '16 GB' && rivetRmmUiBytes(512.0) === '512 B', 'bytes: null is "no data"');
$ok(rivetRmmUiMbit(null) === 'no data' && rivetRmmUiMbit(6200000) === '6.2 Mbit/s' && rivetRmmUiMbit(0) === '0 Mbit/s', 'Mbit/s: null is "no data", an honest zero stays zero');
$svg = rivetRmmUiGaugeSvg('CPU', null, 80, 95, 'off');
$ok(str_contains($svg, 'aria-label="CPU no data"') && str_contains($svg, 'no data</text>') && !str_contains($svg, '>0</text>'), 'a gauge with no reading says "no data" and never draws 0');
$svg = rivetRmmUiGaugeSvg('CPU', 14.2, 80, 95, 'ok');
$ok(str_contains($svg, 'role="img"') && str_contains($svg, 'aria-label="CPU 14 percent, OK"'), 'a gauge has role=img and an aria-label with name, value and band');
$ok(rivetRmmUiPlatform(['os' => 'linux']) === 'linux' && rivetRmmUiPlatform(['os' => 'windows']) === 'windows' && rivetRmmUiPlatform(['os' => '', 'os_version' => 'Ubuntu 24.04']) === 'linux' && rivetRmmUiPlatform(['os' => 'plan9']) === 'other', 'platform comes from the device, never hard-coded to Windows');
$ok(rivetRmmUiSeverity('critical') === 'crit' && rivetRmmUiSeverity('error') === 'crit' && rivetRmmUiSeverity('warning') === 'warn' && rivetRmmUiSeverity(null) === 'warn', 'alert severities map to two kinds');

// ============================================================ the panel view-model: an online device
$vm = $panel('WIN1');
$ok($vm !== null && $vm['device_id'] === $D['WIN1'] && $vm['asset_id'] === $A['WIN1'] && $vm['platform'] === 'windows' && $vm['state'] === 'online' && !$vm['offline'], 'WIN1: online Windows device found through its asset');
$g = array_column($vm['gauges'], null, 'id');
$ok(isset($g['cpu'], $g['mem'], $g['disk:C:'], $g['disk:D:']) && abs($g['cpu']['value'] - 14.2) < 0.01 && abs($g['mem']['value'] - 56.1) < 0.01, 'gauges carry the agent\'s last reading (CPU, memory, one per volume)');
$ok($g['disk:C:']['band'] === 'crit' && $g['disk:D:']['band'] === 'ok' && $g['cpu']['band'] === 'ok', 'bands: C: at 91% is critical, D: at 54% ok');
$ok($g['disk:C:']['caption'] === '42.8 GB free of 476.8 GB' && str_contains($g['mem']['caption'], '16 GB installed') && str_contains($g['cpu']['caption'], 'Intel i7-1355U'), 'gauge captions come from the inventory (sizes, memory, processor)');
$ok(!isset($g['battery']), 'no battery gauge: the agent does not report one, so the card is absent rather than empty');
$ok($vm['net']['rx_bps'] === 6200000.0 && $vm['net']['tx_bps'] === 2200000.0 && $vm['uptime_s'] === 183420 && $vm['pending_reboot'] === false, 'network, uptime and reboot flag are the stored readings');
$ok($vm['strip']['kind'] === 'crit' && $vm['strip']['label'] === 'Online with critical alerts', 'strip: online with an open critical alert is reported as such');
$ok(count($vm['alerts']['open']) === 1 && $vm['alerts']['open'][0]['kind'] === 'crit' && str_contains($vm['alerts']['open'][0]['message'], 'disk_c') && $vm['alerts']['recent'] === [], 'open alerts come from rmm_alerts for this asset');
$ok($vm['check_counts'] === ['fail' => 1, 'warn' => 0, 'ok' => 2, 'unknown' => 0], 'check summary: 1 failing, 2 passing');
$types = array_column($vm['checks'], 'type', 'key');
$ok($types['disk_c'] === 'disk' && $types['svc_eventlog'] === 'service' && $types['pending_reboot'] === 'pending reboot', 'check types come from the configured check list');
$ok($vm['mesh']['configured'] === true && $vm['mesh']['mapped'] === false, 'Mesh is configured; the device is not mapped');
$ok(count($vm['jobs']) === 3 && !array_key_exists('output', $vm['jobs'][0]), 'jobs are listed WITHOUT output (output loads on demand)');
$ok(array_column($vm['saved_scripts'], 'name') === ['Disk cleanup'], 'saved scripts are offered for a Windows device');
$ok($vm['banners'] === [], 'an online, approved device has no banner');

// ============================================================ missing is not zero
$bare = $panel('BARE');
$bg = array_column($bare['gauges'], null, 'id');
$ok($bare['state'] === 'online' && $bg['cpu']['value'] === null && $bg['mem']['value'] === null && $bg['cpu']['band'] === 'off', 'BARE: a device that reports no readings has null (not 0) gauges, band "off"');
$ok($bare['net']['rx_bps'] === null && $bare['uptime_s'] === null && $bare['pending_reboot'] === null && count($bare['gauges']) === 2, 'BARE: network, uptime and reboot are null; no disk gauges invented');
$html = rivetRmmUiOverview($bare, '', '');
$ok(substr_count($html, 'no data') >= 2 && !preg_match('/aria-label="CPU 0 percent/', $html), 'BARE: the overview prints "no data" for the gauges, never 0');
$ok(str_contains(rivetRmmUiInventory($bare), 'No inventory yet'), 'BARE: the Inventory tab is an empty state, not empty tables');

// ============================================================ states
$off = $panel('OFFL');
$ok($off['state'] === 'offline' && $off['offline'] && $off['strip']['kind'] === 'crit' && $off['check_counts']['unknown'] === 1 && $off['checks'][0]['shown_status'] === 'unknown', 'OFFL: offline; its checks read "Unknown" while the device is quiet');
$ok($off['banners'] !== [] && str_contains($off['banners'][0]['text'], 'offline'), 'OFFL: an offline banner explains jobs are delivered on return');
$offHtml = rivetRmmUiStrip($off) . rivetRmmUiLiveHealth($off);
$ok(str_contains($offHtml, 'rmm-dim') && str_contains($offHtml, 'last reading') && str_contains($offHtml, 'uptime unknown'), 'OFFL: gauges are dimmed and say "last reading ... ago"; uptime is unknown');
$stale = $panel('STALE');
$ok($stale['state'] === 'stale' && $stale['strip']['label'] === 'Not seen for days' && str_contains($stale['banners'][0]['text'], 'retiring'), 'STALE: quiet for days, with a "consider retiring" banner');
$never = $panel('NEVER');
$ok($never['state'] === 'never' && $never['strip']['label'] === 'Waiting for first check-in' && $never['age_s'] === null, 'NEVER: waiting for the first check-in');
$nv = rivetRmmUiOverview($never, '', '');
$ok(str_contains($nv, 'Waiting for the first check-in') && !str_contains($nv, 'rmm-checks') && !str_contains($nv, 'ifm-cards') && !str_contains($nv, 'rmm-alerts'), 'NEVER: one empty state replaces gauges, graphs, alerts and checks');
$nvStrip = rivetRmmUiStrip($never);
$ok(substr_count($nvStrip, 'disabled') >= 3 && str_contains($nvStrip, 'Available after the first check-in'), 'NEVER: Run script, Reboot and Remote are disabled with the reason');
$lin = $panel('LNX1', 'admin');
$ok($lin['platform'] === 'linux' && $lin['saved_scripts'] === [], 'LNX1: a Linux device, no saved-script list');
$linStrip = rivetRmmUiStrip($lin);
$ok(str_contains($linStrip, 'fa-linux') && !str_contains($linStrip, 'fa-windows') && str_contains($linStrip, 'id="rmm-act-script" data-bs-toggle="modal" data-bs-target="#rmmRunModal" disabled') && str_contains($linStrip, 'Linux devices can collect inventory and reboot')
    && preg_match('/id="rmm-act-reboot"[^>]*>/', $linStrip, $m) === 1 && !str_contains($m[0], 'disabled'), 'LNX1: Linux icon (no Windows), Run script disabled with the reason, Reboot enabled');
$ok(!str_contains(rivetRmmUiDialogs($lin), 'rmmRunModal') && str_contains(rivetRmmUiDialogs($lin), 'rmmRebootModal'), 'LNX1: no script dialog is rendered, the reboot dialog is');
$pend = rivetRmmUiPanel($db, $A['WIN1'], 1);   // pending approval has no asset; simulate the asset link of a device that dropped back to approval
$q("UPDATE endpoint_agent_devices SET link_state='pending_approval', match_reason='ambiguous' WHERE device_id=" . $D['OFFL']);
$pend = $panel('OFFL');
$ok($pend['pending_approval'] && !$pend['usable'] && in_array('warn', array_column($pend['banners'], 'kind'), true), 'pending approval: banner (warn) and the device is not usable');
$ps = rivetRmmUiStrip($pend);
$ok(str_contains($ps, 'Waiting for approval') && str_contains($ps, 'The device is not approved or no longer active.') && str_contains($ps, 'ambiguous') === false && str_contains($ps, 'Several assets match'), 'pending approval: pill, disabled actions with the reason, and the match reason in words');
$q("UPDATE endpoint_agent_devices SET link_state='linked', match_reason='' WHERE device_id=" . $D['OFFL']);
$q("UPDATE endpoint_agent_devices SET revoked_at=UTC_TIMESTAMP(), revoked_reason='lost' WHERE device_id=" . $D['STALE']);
$rev = $panel('STALE');
$ok($rev['revoked'] && !$rev['usable'] && str_contains($rev['banners'][0]['text'], 'revoked'), 'revoked: banner, not usable');
$q("UPDATE endpoint_agent_devices SET revoked_at=NULL, revoked_reason=NULL, retired_at=UTC_TIMESTAMP() WHERE device_id=" . $D['STALE']);
$ret = $panel('STALE');
$ok($ret['retired'] && !$ret['usable'] && str_contains($ret['banners'][0]['text'], 'retired'), 'retired: banner, not usable');
$q("UPDATE endpoint_agent_devices SET retired_at=NULL WHERE device_id=" . $D['STALE']);

// ============================================================ permission matrix (view-model)
//            user         visible run_saved reboot run_script remote admin
$matrix = [
    'admin'      => [true,  true,  true,  true,  true,  true],
    'tech'       => [true,  true,  true,  true,  true,  false],
    'rebootonly' => [true,  true,  true,  false, false, false],
    'viewer'     => [true,  false, false, false, false, false],
    'remoteonly' => [true,  false, false, false, true,  false],
];
foreach ($matrix as $who => $exp) {
    $v = $panel('WIN1', $who);
    $got = $v === null ? [false] : [true, $v['perm']['run_saved'], $v['perm']['reboot'], $v['perm']['run_script'], $v['perm']['remote'], $v['perm']['admin']];
    $ok($got === $exp, "$who: panel [visible, run_saved, reboot, run_script, remote, admin] = " . json_encode($got));
}
foreach (['moduleonly', 'normo'] as $who) {
    $ok($panel('WIN1', $who) === null || $panel('WIN1', $who)['perm']['run_saved'] === false, "$who: no RMM role or module-only login: nothing runnable");
}
$ok($panel('WIN1', 'deptb') === null, 'a user restricted to Dept B gets nothing for a Dept A device (same as missing)');
$ok($panel('LNX1', 'deptb') !== null && $panel('LNX1', 'deptb')['perm']['run_saved'] === true, 'a user restricted to Dept B sees and may act on Dept B\'s own device');
$ok(rivetRmmUiPanel($db, 999999, 1) === null && rivetRmmUiPanel($db, 0, 1) === null && rivetRmmUiPanel($db, $A['WIN1'], 0) === null, 'no asset, asset 0 and anonymous user all give nothing');
$ok(rivetRmmUiPanel($db, $A['WIN1'], 1, $D['LNX1']) === null, 'a link row pointing at another asset\'s device is not shown');
$viewerHtml = rivetRmmUiStrip($panel('WIN1', 'viewer')) . rivetRmmUiJobs($panel('WIN1', 'viewer'), []) . rivetRmmUiDialogs($panel('WIN1', 'viewer'));
$ok(!str_contains($viewerHtml, 'rmm-output-btn') && !str_contains($viewerHtml, 'rmm-cancel-btn') && !str_contains($viewerHtml, 'rmm-act-collect') && str_contains($viewerHtml, 'Your role cannot run jobs.')
    && !str_contains($viewerHtml, 'id="rmm-run-form"'), 'viewer: no output/cancel/collect controls, no script form, disabled buttons carry the reason');
$techHtml = rivetRmmUiStrip($panel('WIN1', 'tech')) . rivetRmmUiJobs($panel('WIN1', 'tech'), []);
$ok(str_contains($techHtml, 'rmm-output-btn') && str_contains($techHtml, 'rmm-cancel-btn') && str_contains($techHtml, 'rmm-act-collect') && !str_contains($techHtml, 'Open in RMM administration'), 'technician: output, cancel and collect; no administration link');
$adminHtml = rivetRmmUiStrip($panel('WIN1', 'admin')) . rivetRmmUiRemoteCard($panel('WIN1', 'admin'));
$ok(str_contains($adminHtml, 'Open in RMM administration') && str_contains($adminHtml, 'rmm-mesh-form'), 'administrator: administration link and the Mesh mapping form');
$ok(!str_contains(rivetRmmUiRemoteCard($panel('WIN1', 'tech')), 'rmm-mesh-form'), 'technician: no Mesh mapping form');
$ok($panel('WIN1', 'tech')['mesh']['node_id'] === '' , 'the Mesh node id is only carried for administrators');
$rebootOnlyStrip = rivetRmmUiStrip($panel('WIN1', 'rebootonly'));
$ok(preg_match('/id="rmm-act-remote"[^>]*disabled/', $rebootOnlyStrip) === 1 && preg_match('/id="rmm-act-reboot"[^>]*disabled/', $rebootOnlyStrip) === 0, 'reboot-only role: Remote disabled, Reboot enabled');

// ============================================================ Mesh not configured
$rmm->settings()->set(['mesh_enabled' => 0]);
$nm = $panel('WIN1', 'tech');
$ok($nm['mesh']['configured'] === false && !str_contains(rivetRmmUiStrip($nm), 'rmm-act-remote') && rivetRmmUiRemoteCard($nm) === '', 'Mesh not configured: no remote button for a technician, no remote card');
$ok(str_contains(rivetRmmUiRemoteCard($panel('WIN1', 'admin')), 'MeshCentral is not configured'), 'Mesh not configured: the administrator is told where to set it up');
$rmm->settings()->set(['mesh_enabled' => 1]);

// ============================================================ escaping
$h = $panel('HOST');
$page = rivetRmmUiStrip($h) . rivetRmmUiTabs($h, '', '', [], 'tok');
$ok(!str_contains($page, '<script>alert') && !str_contains($page, '<img src=x') && !str_contains($page, '<b>CPU</b>') && !str_contains($page, '<i>eth</i>'), 'HOST: hostile hostname, model, CPU, adapter and check text never reach the page as markup');
$ok(str_contains($page, '&lt;script&gt;alert(9)&lt;/script&gt;') && str_contains($page, '&lt;script&gt;alert(2)&lt;/script&gt;') && str_contains($page, '&lt;script&gt;alert(3)&lt;/script&gt;'), 'HOST: the hostile text is shown, escaped');
$ok(!str_contains($page, "'\"") || str_contains($page, '&quot;&#039;'), 'HOST: quotes are escaped');
$jobsHtml = rivetRmmUiJobs($panel('WIN1'), [10 => 'tech<b>']);
$ok(!str_contains($jobsHtml, 'tech<b>') && str_contains($jobsHtml, 'tech&lt;b&gt;'), 'a user name in the job table is escaped');
$ok(!str_contains($jobsHtml, 'boom <script>') && !str_contains($jobsHtml, 'boom'), 'job output is never inlined into the page');

// ============================================================ query budget
$a = $questions(); $v = rivetRmmUiPanel($db, $A['WIN1'], 1, $D['WIN1']); $b = $questions();
$added = $b - $a - $overhead;
$ok($added <= 40, "view-model for an admin costs $added statements (informational ceiling 40)");
$a = $questions(); $v = rivetRmmUiPanel($db, $A['WIN1'], 12, $D['WIN1']); $b = $questions();
echo "INFO  panel statements: admin=$added, viewer=" . ($b - $a - $overhead) . "\n";

// ============================================================ module OFF: nothing renders, zero queries
$r = $rmm->admin()->disable($admin);
$ok($r->ok && RmmStateFile::read($sd)['enabled'] === false, 'module switched off (state file says so)');
$a = $questions();
$p1 = rivetRmmUiPanel($db, $A['WIN1'], 1);
$p2 = rivetRmmUiPanel($db, $A['WIN1'], 1, $D['WIN1']);
$p3 = rivetRmmUiFleet($db, 1, []);
$b = $questions();
$ok($p1 === null && $p2 === null && $p3 === null, 'OFF: panel and fleet view-models are null');
$ok($b - $a === $overhead, 'OFF: zero database statements for the panel and the fleet (' . ($b - $a - $overhead) . ' above the counter\'s own cost)');
$rmm->admin()->enable($admin);

// ============================================================ the fleet view-model
$f = rivetRmmUiFleet($db, 1, []);
$c = $f['counts'];
$ok($c['online'] === 4 && $c['offline'] === 1 && $c['stale'] === 1 && $c['never'] === 2 && $c['pending_approval'] === 1 && $c['total'] === 8, 'fleet counts: ' . json_encode($c));
$ok($f['list']['total'] === 8 && count($f['list']['items']) === 8, 'device list: all eight');
$ok(array_column($f['offline'], 'hostname') === ['OFFL'] && array_column($f['stale'], 'hostname') === ['STALE'], 'offline and stale lists');
$ok(count($f['approvals']) === 1 && $f['approvals'][0]['hostname'] === 'PEND' && str_contains($f['approvals'][0]['match_reason_text'], 'No asset has this serial'), 'devices needing approval, with the reason in words');
$ok($f['open_alerts'] === 1, 'open agent alerts: 1');
$ok($f['rings'] === ['pilot' => 1, 'stable' => 7], 'rings counted: ' . json_encode($f['rings']));
$ok($f['capacity'] !== null && $f['capacity']['devices']['active'] === 8 && $f['perm']['admin'], 'administrator: the capacity report is included');
foreach (['tech', 'viewer', 'rebootonly'] as $who) {
    $fv = rivetRmmUiFleet($db, $USER[$who], []);
    $ok($fv !== null && $fv['capacity'] === null && !$fv['perm']['admin'], "$who: fleet page without the capacity panel");
}
$ok(rivetRmmUiFleet($db, $USER['normo'], []) === null, 'normo (no RMM grant): no fleet');
$fb = rivetRmmUiFleet($db, $USER['deptb'], []);
$ok($fb['counts']['total'] === 1 && count($fb['list']['items']) === 1 && $fb['list']['items'][0]['hostname'] === 'LNX1' && $fb['offline'] === [] && $fb['approvals'] === [] && $fb['open_alerts'] === 0, 'Dept B user: only Dept B\'s device, alert and approvals');
$fq = rivetRmmUiFleet($db, 1, ['status' => 'online', 'per_page' => 5, 'page' => 2]);
$ok($fq['list']['total'] === 4 && $fq['page'] === 2 && count($fq['list']['items']) === 0 && $fq['pages'] === 1, 'filters + pagination: status online, 5 per page, page 2 is empty');
$fq = rivetRmmUiFleet($db, 1, ['q' => 'WIN', 'per_page' => 5]);
$ok($fq['list']['total'] === 1 && $fq['list']['items'][0]['hostname'] === 'WIN1', 'filter by hostname text');
$fq = rivetRmmUiFleet($db, 1, ['ring' => 'pilot']);
$ok($fq['list']['total'] === 1 && $fq['list']['items'][0]['hostname'] === 'LNX1', 'filter by ring');
$fq = rivetRmmUiFleet($db, 1, ['per_page' => 5, 'page' => 2]);
$ok($fq['list']['total'] === 8 && $fq['pages'] === 2 && count($fq['list']['items']) === 3, 'pagination: 8 devices, 5 per page: page 2 has 3');
// failures
$ok(count($f['failures']) === 1 && $f['failures'][0]['hostname'] === 'WIN1' && $f['failures'][0]['state'] === 'failed' && !array_key_exists('output', $f['failures'][0]) && !array_key_exists('script', $f['failures'][0]), 'recent job failures: metadata only (no output, no script)');
$ok(rivetRmmUiFleet($db, $USER['deptb'], [])['failures'] === [], 'job failures are scoped to the user\'s departments');
// outdated: host a current binary at 1.2.0 and check version compare
$ok($f['outdated'] === [] && $f['outdated_total'] === 0 && $f['current_versions'] === ['amd64' => null, 'arm64' => null], 'no hosted binary: nothing counts as outdated');
$q("INSERT INTO endpoint_agent_binaries (version, arch, sha256, size_bytes, storage_name, active, is_current, uploaded_by) VALUES ('1.1.0', 'amd64', '" . str_repeat('a', 64) . "', 10, 'bin_x.bin', 1, 1, 1)");
$f2 = rivetRmmUiFleet($db, 1, []);
$names = array_column($f2['outdated'], 'hostname');
sort($names);
$ok($f2['current_versions']['amd64'] === '1.1.0' && $f2['outdated_total'] === count($names) && in_array('WIN1', $names, true) && !in_array('LNX1', $names, true), 'outdated agents: those below the hosted 1.1.0 (WIN1 at 1.0.0 yes, LNX1 at 1.1.0 no): ' . json_encode($names));
$q("DELETE FROM endpoint_agent_binaries");

// ============================================================ rendered fleet page
$html = rivetRmmUiFleetPage($f, 'tok');
foreach (['Online', 'Offline', 'Stale', 'Never seen', 'Need approval', 'Open alerts'] as $lbl) { $ok(str_contains($html, $lbl), "fleet page: KPI \"$lbl\""); }
$ok(str_contains($html, 'role="img" aria-label="Fleet status: 4 online, 1 offline, 1 stale, 2 never seen"'), 'fleet page: the donut has a text alternative with the counts');
$ok(str_contains($html, 'id="rmm-capacity"') && str_contains($html, 'Administrators only'), 'fleet page: capacity panel for the administrator');
$ok(!str_contains(rivetRmmUiFleetPage(rivetRmmUiFleet($db, 10, []), 't'), 'rmm-capacity'), 'fleet page: no capacity panel for a technician');
$ok(str_contains($html, 'Dept A') && str_contains($html, '/agent/asset_details.php?asset_id=' . $A['WIN1']), 'fleet page: department names and links to the asset page');
$ok(!str_contains($html, '<script>alert(9)') && str_contains($html, 'HOST&lt;script&gt;'), 'fleet page: hostile hostname escaped');
$ok(substr_count($html, '<h') >= 1 && str_contains($html, '<caption class="visually-hidden">') && str_contains($html, 'scope="col"') && str_contains($html, 'aria-label="Filter devices"'), 'fleet page: accessible table (caption, column scopes) and labelled filter form');

// ============================================================ real pages over HTTP (forged sessions)
$sdir = sys_get_temp_dir() . '/rmm_ui_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$SS = [];
foreach ($USER as $n => $uid) { $SS[$n] = ea_forge_session($sdir, $uid); }
$q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");
// The asset page itself needs the Assets module; the RMM panel inside it is gated separately. Give the technician and viewer roles read access to assets.
foreach ([2, 4] as $role) { $q("INSERT INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2"); }
rivetRmmForgetAccess();   // RmmAuthorizer memoizes per object; this process changed role grants after the first checks

[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['admin']);
$ok($c === 200, 'asset page (admin): 200');
foreach (['id="rmm-strip"', 'id="rmm-panel"', 'role="tablist"', 'aria-label="CPU 14 percent, OK"', 'aria-label="Disk C: 91 percent, Critical"', 'id="rmm-checks"', 'id="rmm-alerts"', 'rmmRebootModal', 'itflow_rmm.css', '/js/rmm_panel.js', 'data-asset-metrics'] as $needle) {
    $ok(str_contains($body, $needle), "asset page (admin) contains $needle");
}
$ok(!str_contains($body, 'boom') && !str_contains($body, 'collected: 2 disks'), 'asset page: no job output inline');
$ok(!str_contains($body, 'rmmDetailTabs') && !str_contains($body, 'Agent device'), 'asset page: the old vendor card is not drawn for an agent link');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['viewer']);
$ok($c === 200 && str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm-output-btn') && preg_match('/id="rmm-act-reboot"[^>]*disabled/', $body) === 1 && str_contains($body, 'aria-label="CPU 14 percent, OK"'), 'asset page (viewer): gauges visible, Run/Reboot disabled, no output controls');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['deptb']);
$ok(!str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm_panel.js'), 'asset page (Dept B user, Dept A asset): no RMM markup');
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['NEVER'], $SS['admin']);
$ok($c === 200 && str_contains($body, 'Waiting for the first check-in') && str_contains($body, 'Waiting for first check-in'), 'asset page: never-checked-in device renders its empty state');
foreach (['OFFL', 'STALE', 'BARE', 'HOST'] as $n) {
    [$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A[$n], $SS['admin']);
    $ok($c === 200 && str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'Warning:') && !str_contains($body, 'Fatal error') && !str_contains($body, 'Deprecated:'), "asset page $n: renders without PHP errors");
}
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['HOST'], $SS['admin']);
$ok(!str_contains($body, '<script>alert(') && !str_contains($body, '<img src=x onerror'), 'asset page HOST: no injected script or image tag');
[$c, , $hd] = web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $D['WIN1'], $SS['admin']);
$ok($c === 302 && preg_match('#location: /agent/asset_details\.php\?asset_id=' . $A['WIN1'] . '\#rmm-overview#i', $hd) === 1, 'the old device page redirects to the asset page');
[$c, $body] = web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $D['PEND'], $SS['admin']);
$ok($c === 200 && str_contains($body, 'PEND'), 'a device with no asset keeps the legacy page');

// fleet page
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'Agent fleet') && str_contains($body, 'id="rmm-capacity"') && str_contains($body, 'id="rmm-approvals"') && str_contains($body, 'WIN1') && str_contains($body, 'Agent Fleet'), 'fleet page (admin): content and nav entry');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?status=offline', $SS['viewer']);
$ok($c === 200 && str_contains($body, 'OFFL') && !str_contains($body, 'id="rmm-capacity"') && !str_contains($body, 'aria-current="true"><i') , 'fleet page (viewer): filtered list, no capacity');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?status=bogus&ring=nope&page=-4&q=' . urlencode('<script>'), $SS['admin']);
$ok($c === 200 && !str_contains($body, '<script>alert') && !str_contains($body, 'value="<script>'), 'fleet page: bad filters are ignored, text filter is escaped');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['deptb']);
$ok($c === 200 && str_contains($body, 'LNX1') && !str_contains($body, 'WIN1'), 'fleet page (Dept B): only its own device');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['normo']);
$ok($c === 403, 'fleet page (no RMM role): denied');

// job output endpoint
$jid = $S['job_failed'];
[$c, $body] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=$jid", $SS['tech']);
$j = json_decode($body, true);
$ok($c === 200 && $j['success'] && $j['state'] === 'failed' && $j['exit_code'] === 1 && $j['output'] === 'boom <script>alert(1)</script>' && $j['truncated'] === false, 'job output (technician): JSON with the stored output (the browser shows it as text)');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=$jid", $SS['viewer']);
$ok($c === 403, 'job output (view-only role): 403, output follows the run permission');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=$jid", $SS['deptb']);
$ok($c === 404, 'job output (other department): 404, same as missing');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id=99999&job_id=$jid", $SS['admin']);
$ok($c === 404, 'job output (no such device): 404');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=not-a-uuid", $SS['admin']);
$ok($c === 404, 'job output (malformed job id): 404');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['LNX1']}&job_id=$jid", $SS['admin']);
$ok($c === 404, 'job output (job of another device): 404');
[$c] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=$jid", 'nosession');
$ok($c === 302 || $c === 401 || $c === 403, 'job output (not signed in): refused');

// POST: the page's buttons are the existing handler; prove the matrix at the handler for the new dialog payloads
$post = fn(string $who, array $f) => web($wb, 'POST', '/agent/post/rmm_agent.php', $SS[$who], $f + ['csrf_token' => 'csrftok1', 'device_id' => $D['WIN1']]);
[$c, $b] = $post('viewer', ['action' => 'submit_job', 'type' => 'reboot', 'destructive' => '1', 'confirm' => '1']); $ok($c === 403, 'handler: viewer cannot reboot (the disabled button is cosmetic)');
[$c, $b] = $post('tech', ['action' => 'submit_job', 'type' => 'reboot', 'destructive' => '1']); $ok($c === 422 && json_decode($b, true)['code'] === 'confirmation_required', 'handler: reboot without confirmation is refused');
[$c, $b] = $post('tech', ['action' => 'submit_job', 'type' => 'reboot', 'destructive' => '1', 'confirm' => '1']); $ok($c === 200 && json_decode($b, true)['success'] === true, 'handler: confirmed reboot is queued');
[$c, $b] = $post('tech', ['action' => 'submit_job', 'type' => 'collect']); $ok($c === 200, 'handler: collect inventory queued');
[$c, $b] = $post('rebootonly', ['action' => 'submit_job', 'type' => 'powershell', 'script_id' => (int) $one('SELECT id FROM rmm_scripts LIMIT 1')]); $ok($c === 200, 'handler: a saved script is queued for a role with the saved grant');
[$c, $b] = $post('rebootonly', ['action' => 'submit_job', 'type' => 'powershell', 'script' => 'Get-Date']); $ok($c === 403, 'handler: free-form text is refused without the script grant');

// module OFF over HTTP
$rmm->admin()->disable($admin);
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['admin']);
$ok($c === 200 && !str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm-panel') && !str_contains($body, 'rmm_panel.js') && !str_contains($body, 'rmmRebootModal') && !str_contains($body, 'data-asset-metrics'), 'OFF: the asset page is a plain asset (no strip, no panel, no script link, no metrics pane)');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php', $SS['admin']);
$ok($c === 403 && str_contains($body, 'RMM module off') && !str_contains($body, 'WIN1'), 'OFF: the fleet page is the module-off notice and shows no device');
[$c, $body] = web($wb, 'GET', "/agent/rmm_job_output.php?device_id={$D['WIN1']}&job_id=$jid", $SS['admin']);
$ok($c === 404, 'OFF: the job output endpoint answers 404');
[$c, $body] = web($wb, 'GET', '/agent/dashboard.php', $SS['admin']);
$ok(!str_contains($body, 'Agent Fleet') && !str_contains($body, 'rmm_fleet.php'), 'OFF: the navigation has no Agent Fleet entry');
$rmm->admin()->enable($admin);
[$c, $body] = web($wb, 'GET', '/agent/dashboard.php', $SS['admin']);
$ok(str_contains($body, 'href="/agent/rmm_fleet.php"'), 'ON: the navigation has the Agent Fleet entry');
[$c, $body] = web($wb, 'GET', '/agent/dashboard.php', $SS['normo']);
$ok(!str_contains($body, 'rmm_fleet.php'), 'a user with no RMM grant never sees the entry');

// vendor-linked assets are unchanged
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='VENDOR1', asset_client_id=1, asset_status='Active'"); $va = (int) $db->insert_id;
$q("INSERT INTO rmm_integrations SET name='Tactical', type='tactical_rmm', enabled=1"); $ti = (int) $db->insert_id;
$q("INSERT INTO asset_rmm_links SET asset_id=$va, integration_id=$ti, tactical_agent_id='abc', hostname='VENDOR1', rmm_status='online'");
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $va, $SS['admin']);
$ok($c === 200 && str_contains($body, 'rmmDetailTabs') && !str_contains($body, 'id="rmm-strip"') && !str_contains($body, 'rmm_panel.js'), 'a Tactical-linked asset keeps the vendor card and gets no agent panel');
// both links: agent panel + vendor card, the Performance partial rendered once
$q("INSERT INTO asset_rmm_links SET asset_id={$A['WIN1']}, integration_id=$ti, tactical_agent_id='both-1', hostname='WIN1', rmm_status='online'");
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A['WIN1'], $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="rmm-strip"') && str_contains($body, 'rmmDetailTabs') && substr_count($body, 'data-asset-metrics') === 1, 'both links: agent panel and vendor card; the metrics partial is rendered once');
$q("DELETE FROM asset_rmm_links WHERE tactical_agent_id='both-1'");
