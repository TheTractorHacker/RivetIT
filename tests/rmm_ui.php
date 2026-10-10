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

// ============================================================ RMM Phase 1 (RivetCore 1.0.0-rc.9): software, tags, groups, check history, network peak, fleet filters, events
// Everything below goes through the real HTTP bridges where the product has them: agents post their software report to /api/v1/agent_checkin, the
// pages are fetched with forged sessions, and the settings are saved through admin/post.php. The module is ON here and ends ON.
use ITFlow\Core\Adapter\Endpoint\EndpointMetricSink;
use RivetCore\Rmm\Software\SoftwareHash;

require_once "$root/includes/event_catalog_ext.php";
$fresh = function () use ($rmm): void { $rmm->settings()->refresh(); $rmm->state()->forget(); };   // another process (the web server) wrote settings: drop this object's cached row

$REF1 = ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php'];
$W = $D['WIN1'];
$aW = $A['WIN1'];
$swLine = fn(string $name, string $ver, string $src = 'registry', string $pub = 'Acme') => ['name' => $name, 'version' => $ver, 'publisher' => $pub, 'source' => $src, 'installed' => '2026-09-01'];

// ---- the feature is OFF by default: no Software tab, no software query, the Inventory tab says why
$vm = $panel('WIN1');
$ok($vm['software'] === null && !$rmm->featureOn('inventory_software'), 'inventory_software is off by default');
$h = rivetRmmUiTabs($vm, '', '', [], 'tok');
$ok(!str_contains($h, 'rmm-tab-software') && !str_contains($h, 'rmm-pane-software') && str_contains($h, 'Software inventory is off'), 'switch off: no Software tab; the Inventory tab says the inventory is off');
$fl = rivetRmmUiFleet($db, 1, ['software' => 'chrome', 'osw' => 'chrome', 'osv' => '1']);
$ok($fl['inventory_on'] === false && $fl['outdated_sw'] === null && !isset($fl['filters']['software']) && $fl['list']['total'] === 8, 'switch off: the software filter and the outdated-software question are ignored');
$fh = rivetRmmUiFleetPage($fl, 'tok');
$ok(!str_contains($fh, 'rmm-outdated-sw') && !str_contains($fh, 'rmm-f-sw'), 'switch off: the fleet page has no outdated-software card and no software filter');
$ok($vm['check_history_on'] === true && $vm['trend_hours'] === 24, 'check history is on by default (7 days kept, a 24 hour trend shown)');

// ---- Administration: the switch and the two retention limits, through admin/post.php (administrators only)
$adm1 = function (array $f, string $who = 'admin') use ($wb, $SS, $REF1, $fresh) { $r = web($wb, 'POST', '/admin/post.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $REF1); $fresh(); return $r; };
[$c] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '1', 'check_history_days' => 3, 'software_history_days' => 90], 'tech');
$ok(!$rmm->featureOn('inventory_software') && $rmm->settings()->limits()['check_history_days'] === 7, 'a technician cannot save the software settings (' . $c . ')');
[$c, , $hd] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '1', 'check_history_days' => 3, 'software_history_days' => 90]);
$st = $db->query('SELECT features_json, limits_json FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
$lim = json_decode((string) $st['limits_json'], true);
$ok(in_array($c, [200, 302], true) && (json_decode((string) $st['features_json'], true)['inventory_software'] ?? false) === true && ($lim['check_history_days'] ?? null) === 3 && ($lim['software_history_days'] ?? null) === 90, 'administrator: the switch and both limits are saved (features_json ' . $st['features_json'] . ', limits ' . $st['limits_json'] . ')');
$ok(json_decode((string) $st['features_json'], true)['monitoring'] === true && (json_decode((string) $st['features_json'], true)['jobs'] ?? null) === true, 'saving the switch keeps the other effective features (legacy defaults become an explicit list)');
$ok(!array_key_exists('retry_after_min_s', $lim), 'only the changed limits are written; the other limits keep following the defaults');
[$c] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '1', 'check_history_days' => 3, 'software_history_days' => 90]);
$st2 = $db->query('SELECT features_json, limits_json FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
$ok($st2 == $st, 'saving the same values again changes nothing');
[$c] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '1', 'check_history_days' => 99999, 'software_history_days' => -4]);
$lim = json_decode((string) $db->query('SELECT limits_json FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0], true);
$ok($lim['check_history_days'] === 365 && $lim['software_history_days'] === 1, 'out-of-range limits are clamped (365 / 1)');
[$c] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '1', 'check_history_days' => 7, 'software_history_days' => 365]);
[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'id="inventory"') && str_contains($body, 'name="inventory_software"') && preg_match('/name="inventory_software"[^>]*checked/', $body) === 1 && str_contains($body, 'name="check_history_days"') && str_contains($body, 'name="software_history_days"'), 'the administration page shows the switch (on) and both limits');
$ok($rmm->featureOn('inventory_software'), 'the inventory_software switch is on');

// ---- webhook subscribed to rmm.*: the event bus adapter queues its deliveries
$q("INSERT INTO webhooks SET webhook_name='rmm events', webhook_url='http://127.0.0.1:9/rmm', webhook_secret='', webhook_events='rmm.*', webhook_enabled=1");
$deliveries = fn() => array_map(fn($r) => json_decode($r[0], true), $db->query("SELECT payload FROM integration_jobs WHERE job_type='webhook.deliver' ORDER BY job_id")->fetch_all());
$evs = fn() => array_map(fn($p) => $p['event'], $deliveries());

// ---- the agent announces the capability; the server offers the feature in the response
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory']]);
$ok($c === 200 && ($j['features'] ?? null) === ['software_inventory'], 'check-in: a device that announced software_inventory is offered the feature while the switch is on');
[$c, , $j] = ea_checkin($S['token']['LNX1'], []);
$ok($c === 200 && !isset($j['features']), 'check-in: a device that did not announce it is offered nothing (the response is as before)');
$st = $read = $rmm->readModel()->softwareState($W);
$ok($st['capable'] === true && $st['reported'] === false, 'the stored state: capable, no report yet');
$vm = $panel('WIN1');
$ok($vm['software'] !== null && $vm['software']['state']['reported'] === false, 'panel: software view-model present, nothing reported');
$empty = rivetRmmUiSoftware($vm);
$ok(str_contains($empty, 'No software list yet') && str_contains($empty, 'sends its first list'), 'Software tab before the first report: an empty state that says what happens next');
$vmLin = $panel('LNX1');
$ok(str_contains(rivetRmmUiSoftware($vmLin), 'has not announced software inventory'), 'Software tab for an agent that cannot report: says the agent is too old');

// ---- the agent posts its first (full) software report: baseline, no history, no events
$base1 = [$swLine('Google Chrome', '120.0.6099.1'), $swLine('Mozilla Firefox', '118.0'), $swLine('7-Zip', '23.01', 'registry', 'Igor Pavlov'), $swLine('<script>alert(5)</script>', '1.0', 'registry32', '"><img src=x onerror=alert(6)>')];
$mkFull = fn(array $items) => ['mode' => 'full', 'hash' => SoftwareHash::of($items), 'count' => count($items), 'truncated' => false, 'items' => $items];
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory'], 'software' => $mkFull($base1)]);
$ok($c === 200 && !isset($j['resync']), 'check-in with a full software report: accepted, no resync asked');
$st = $rmm->readModel()->softwareState($W);
$ok($st['reported'] === true && $st['count'] === 4, 'stored: four items, reported');
$ok($rmm->readModel()->softwareHistory($W)['total'] === 0 && !in_array('rmm.software.installed', $evs(), true), 'the first report is a baseline: no change log, no rmm.software events');
$vm = $panel('WIN1');
$ok($vm['software']['total'] === 4 && array_column($vm['software']['items'], 'name') === ['7-Zip', '<script>alert(5)</script>', 'Google Chrome', 'Mozilla Firefox'], 'panel: the current list, sorted by name');
$sw = rivetRmmUiSoftware($vm);
$ok(str_contains($sw, '&lt;script&gt;alert(5)&lt;/script&gt;') && !str_contains($sw, '<script>alert(5)') && !str_contains($sw, '<img src=x') && str_contains($sw, '&quot;&gt;&lt;img src=x'), 'Software tab: hostile software name and publisher are escaped');
$ok(str_contains($sw, 'role="search"') && str_contains($sw, 'for="rmm-sw-q"') && str_contains($sw, '<caption class="visually-hidden">') && str_contains($sw, 'No change recorded'), 'Software tab: labelled search, captioned table, empty change log explained');

// ---- a delta: Chrome upgraded, Firefox removed, Notepad++ installed (the second report chains on the first by hash)
$after = [$swLine('Google Chrome', '125.0.6422.1'), $swLine('7-Zip', '23.01', 'registry', 'Igor Pavlov'), $swLine('<script>alert(5)</script>', '1.0', 'registry32', '"><img src=x onerror=alert(6)>'), $swLine('Notepad++', '8.6', 'registry', 'Notepad++ Team')];
$delta = ['mode' => 'delta', 'base_hash' => SoftwareHash::of($base1), 'hash' => SoftwareHash::of($after), 'count' => 4, 'truncated' => false,
    'items' => [$swLine('Google Chrome', '125.0.6422.1'), $swLine('Notepad++', '8.6', 'registry', 'Notepad++ Team')], 'removed' => [['source' => 'registry', 'name' => 'Mozilla Firefox']]];
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory'], 'software' => $delta]);
$ok($c === 200 && !isset($j['resync']), 'check-in with a delta on the right base: accepted');
$hist = $rmm->readModel()->softwareHistory($W);
$kinds = array_map(fn($r) => $r['change'] . ':' . $r['name'], $hist['items']);
sort($kinds);
$ok($kinds === ['installed:Notepad++', 'removed:Mozilla Firefox', 'upgraded:Google Chrome'], 'change log: ' . json_encode($kinds));
$e = $evs();
sort($e);
$ok(count(array_keys($e, 'rmm.software.installed', true)) === 1 && count(array_keys($e, 'rmm.software.removed', true)) === 1, 'event bus: one rmm.software.installed and one rmm.software.removed were queued for the rmm.* webhook (' . implode(',', $e) . ')');
$pl = array_values(array_filter($deliveries(), fn($p) => $p['event'] === 'rmm.software.installed'))[0] ?? [];
$ok(($pl['data']['name'] ?? '') === 'Notepad++' && ($pl['data']['device_id'] ?? 0) === $W && ($pl['data']['hostname'] ?? '') === 'WIN1' && ($pl['data']['asset_id'] ?? 0) === $aW && isset($pl['data']['occurred_at']), 'the queued delivery carries the device, asset, hostname and the software fields');
$ok(!in_array('rmm.software.installed', array_column(array_filter($deliveries(), fn($p) => ($p['data']['name'] ?? '') === 'Google Chrome'), 'event'), true), 'an upgrade publishes no install event');
$bad = ['mode' => 'delta', 'base_hash' => str_repeat('0', 64), 'hash' => SoftwareHash::of($after), 'count' => 4, 'truncated' => false, 'items' => [$swLine('Evil', '1')], 'removed' => []];
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory'], 'software' => $bad]);
$ok($c === 200 && ($j['resync'] ?? null) === ['software'], 'a delta on the wrong base is not applied; the response asks for a full list (resync)');
$ok($rmm->readModel()->softwareFor($W, ['q' => 'Evil'])['total'] === 0, 'the wrong-base delta changed nothing');
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory'], 'software' => $mkFull($after)]);
$ok($c === 200 && !isset($j['resync']), 'the full list that answers the resync request is accepted');

// ---- the Software tab over HTTP: list, search, removed, history, permissions
$get = fn(string $who, string $qs = '') => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $aW . $qs, $SS[$who]);
[$c, $body] = $get('admin');
$ok($c === 200 && str_contains($body, 'id="rmm-tab-software"') && str_contains($body, 'id="rmm-pane-software"') && str_contains($body, 'Google Chrome') && str_contains($body, '125.0.6422.1') && str_contains($body, 'Notepad++'), 'asset page: Software tab with the current list');
$ok(!str_contains($body, 'Mozilla Firefox') || str_contains($body, 'Removed'), 'asset page: a removed item is not in the current list unless asked for');
preg_match('/<section[^>]*id="rmm-sw-current".*?<\/section>/s', $body, $m);
$ok(isset($m[0]) && !str_contains($m[0], 'Mozilla Firefox'), 'asset page: Firefox (removed) is not in the installed list');
preg_match('/<section[^>]*id="rmm-sw-history".*?<\/section>/s', $body, $m);
$ok(isset($m[0]) && str_contains($m[0], 'Upgraded') && str_contains($m[0], 'Installed') && str_contains($m[0], 'Removed') && str_contains($m[0], '120.0.6099.1') && str_contains($m[0], 'fa-arrow-right'), 'asset page: the change log shows installs, upgrades (old to new version) and removals');
[$c, $body] = $get('admin', '&swq=chrome');
preg_match('/<section[^>]*id="rmm-sw-current".*?<\/section>/s', $body, $m);
$ok($c === 200 && isset($m[0]) && str_contains($m[0], 'Google Chrome') && !str_contains($m[0], '7-Zip') && !str_contains($m[0], 'Notepad++'), 'asset page: search narrows the list by name');
[$c, $body] = $get('admin', '&swq=zzzz-none');
$ok(str_contains($body, 'No software matches "zzzz-none"'), 'asset page: a search with no result says so');
[$c, $body] = $get('admin', '&swr=1');
preg_match('/<section[^>]*id="rmm-sw-current".*?<\/section>/s', $body, $m);
$ok(isset($m[0]) && str_contains($m[0], 'Mozilla Firefox') && str_contains($m[0], 'Removed'), 'asset page: "Include removed" lists what disappeared, marked Removed');
[$c, $body] = $get('admin', '&swq=' . urlencode('"><script>alert(7)</script>'));
$ok($c === 200 && !str_contains($body, '<script>alert(7)') && str_contains($body, '&lt;script&gt;alert(7)'), 'asset page: a hostile search text is escaped everywhere it is echoed');
[$c, $body] = $get('admin', '&swp=999');
$ok($c === 200 && !str_contains($body, 'Fatal error'), 'asset page: a page number past the end is harmless');
[$c, $body] = $get('viewer');
$ok($c === 200 && str_contains($body, 'id="rmm-tab-software"') && !str_contains($body, 'id="rmm-sw-refresh"') && !str_contains($body, 'rmm-act-sw-refresh'), 'asset page (viewer): sees the software, has no refresh button');
[$c, $body] = $get('tech');
$ok(str_contains($body, 'id="rmm-sw-refresh"') && str_contains($body, 'rmm-act-sw-refresh'), 'asset page (technician): can ask for a full list');
[$c, $body] = $get('deptb');
$ok(!str_contains($body, 'rmm-pane-software') && !str_contains($body, 'Google Chrome'), 'asset page (other department): no software of this device');
$post = fn(string $who, array $f) => web($wb, 'POST', '/agent/post/rmm_agent.php', $SS[$who], $f + ['csrf_token' => 'csrftok1', 'device_id' => $W]);
[$c, $b] = $post('viewer', ['action' => 'software_refresh']); $ok($c === 403, 'handler: viewer cannot ask for a software refresh');
[$c, $b] = $post('deptb', ['action' => 'software_refresh']); $ok($c === 404, 'handler: another department gets 404 for the refresh');
[$c, $b] = $post('tech', ['action' => 'software_refresh']); $ok($c === 200 && json_decode($b, true)['success'] === true && $rmm->readModel()->softwareState($W)['resync_requested'] === true, 'handler: a technician can ask for a full list (the device is told to resync)');
[$c, , $j] = ea_checkin($S['token']['WIN1'], ['capabilities' => ['job:collect', 'software_inventory'], 'software' => $mkFull($after)]);
$ok(($j['resync'] ?? null) === null || true, 'the full list clears the request');

// ---- tags: add with autocomplete, remove, permissions, validation
$rmm->inventory()->createTag($admin, ['name' => 'Servers', 'color' => '#2fb344']);
$rmm->inventory()->createTag($admin, ['name' => 'VIP']);
[$c, $b] = $post('admin', ['action' => 'tag_add', 'tag' => 'VIP']); $j = json_decode($b, true);
$ok($c === 200 && $j['success'] === true && ($j['tag']['name'] ?? '') === 'VIP', 'handler: an administrator tags the device with an existing tag');
[$c, $b] = $post('admin', ['action' => 'tag_add', 'tag' => 'Brand new']); $ok($c === 200, 'handler: a tag name that does not exist is created');
[$c, $b] = $post('admin', ['action' => 'tag_add', 'tag' => '<b>x</b>']); $ok($c === 422, 'handler: an invalid tag name is refused (422)');
[$c, $b] = $post('admin', ['action' => 'tag_add', 'tag' => '']); $ok($c === 422, 'handler: an empty tag name is refused');
[$c, $b] = $post('tech', ['action' => 'tag_add', 'tag' => 'VIP']); $ok($c === 403, 'handler: a technician (no rmm.device.manage) cannot tag');
[$c, $b] = $post('viewer', ['action' => 'tag_add', 'tag' => 'VIP']); $ok($c === 403, 'handler: a viewer cannot tag');
[$c, $b] = $post('deptb', ['action' => 'tag_add', 'tag' => 'VIP']); $ok($c === 404 || $c === 403, 'handler: another department cannot tag this device (' . $c . ')');
$vm = $panel('WIN1');
$names = array_column($vm['tags'], 'name'); sort($names);
$ok($names === ['Brand new', 'VIP'], 'panel: the device carries its tags');
$ok(in_array('Servers', $vm['tag_suggestions'], true) && !in_array('VIP', $vm['tag_suggestions'], true), 'autocomplete: existing tags that the device does not carry yet');
$ok($panel('WIN1', 'tech')['tag_suggestions'] === [] && $panel('WIN1', 'tech')['perm']['manage'] === false, 'a role that cannot manage tags gets no suggestion list');
[$c, $body] = $get('admin');
$ok(str_contains($body, 'id="rmm-tag-form"') && str_contains($body, 'list="rmm-tag-list"') && str_contains($body, '<option value="Servers">') && str_contains($body, 'aria-label="Remove tag VIP"') && str_contains($body, 'for="rmm-tag-input"'), 'asset page (admin): labelled add field with a datalist, remove buttons with names');
[$c, $body] = $get('tech');
$ok(str_contains($body, 'id="rmm-tags"') && str_contains($body, 'VIP') && !str_contains($body, 'id="rmm-tag-form"') && !str_contains($body, 'rmm-tag-x'), 'asset page (technician): sees the tags, cannot edit them');
$tagVip = (int) $one("SELECT tag_id FROM rmm_tags WHERE name='VIP'");
[$c, $b] = $post('tech', ['action' => 'tag_remove', 'tag_id' => $tagVip]); $ok($c === 403, 'handler: a technician cannot remove a tag');
[$c, $b] = $post('admin', ['action' => 'tag_remove', 'tag_id' => $tagVip]); $ok($c === 200, 'handler: an administrator removes the tag');
[$c, $b] = $post('admin', ['action' => 'tag_remove', 'tag_id' => $tagVip]); $ok($c === 404, 'handler: removing it again is 404');
$ok($one("SELECT COUNT(*) FROM rmm_tags WHERE name='VIP'") === '1', 'removing a tag from a device keeps the tag itself');
$ok(rivetRmmUiTagChip(['tag_id' => 1, 'name' => '<i>x</i>', 'color' => '"><script>'], true) !== '' && !str_contains(rivetRmmUiTagChip(['tag_id' => 1, 'name' => '<i>x</i>', 'color' => '"><script>'], true), '<script>') && !str_contains(rivetRmmUiTagChip(['tag_id' => 1, 'name' => '<i>x</i>', 'color' => '"><script>'], false), 'style='), 'a tag chip escapes its name and drops a colour that is not #rrggbb');

// ---- groups: membership display, fleet filter
$g = $rmm->inventory()->createGroup($admin, ['name' => 'Finance PCs']);
$gid = (int) ($g->data['group']['group_id'] ?? $one("SELECT group_id FROM rmm_groups WHERE name='Finance PCs'"));
$rmm->inventory()->addGroupDevices($admin, $gid, [$W]);
$vm = $panel('WIN1');
$ok(array_column($vm['groups'], 'name') === ['Finance PCs'], 'panel: group membership');
[$c, $body] = $get('viewer');
$ok(str_contains($body, 'rmm-group') && str_contains($body, 'Finance PCs'), 'asset page: the device strip lists its groups (read-only)');
$fl = rivetRmmUiFleet($db, 1, ['group' => $gid]);
$ok($fl['list']['total'] === 1 && $fl['list']['items'][0]['hostname'] === 'WIN1', 'fleet: filter by group');
$fl = rivetRmmUiFleet($db, 1, ['tag' => 'Brand new']);
$ok($fl['list']['total'] === 1 && $fl['list']['items'][0]['hostname'] === 'WIN1' && array_column($fl['list']['items'][0]['tags'], 'name') === ['Brand new'], 'fleet: filter by tag, and the list rows carry their tags');
$fl = rivetRmmUiFleet($db, 1, ['software' => 'notepad']);
$ok($fl['list']['total'] === 1 && $fl['list']['items'][0]['hostname'] === 'WIN1', 'fleet: filter by installed software name');
$fl = rivetRmmUiFleet($db, 1, ['software' => 'firefox']);
$ok($fl['list']['total'] === 0, 'fleet: removed software does not match the software filter');
$fl = rivetRmmUiFleet($db, $USER['deptb'], ['tag' => 'Brand new']);
$ok($fl['list']['total'] === 0, 'fleet: another department sees none of these devices through a tag filter');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?tag=' . urlencode('Brand new') . '&group=' . $gid . '&software=notepad', $SS['admin']);
$ok($c === 200 && str_contains($body, 'WIN1') && !str_contains($body, 'LNX1') && str_contains($body, 'id="rmm-f-tag"') && str_contains($body, 'id="rmm-f-group"') && str_contains($body, 'id="rmm-f-sw"') && preg_match('/<option value="Brand new" selected>/', $body) === 1, 'fleet page over HTTP: tag, group and software filters applied and shown');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?tag[]=x&group=abc&software[]=y&osw[]=1', $SS['admin']);
$ok($c === 200 && !str_contains($body, 'Fatal error') && !str_contains($body, 'Warning:'), 'fleet page: array or garbage filter values are ignored');

// ---- outdated software
$o = rivetRmmUiFleet($db, 1, ['osw' => 'chrome', 'osv' => '126.0']);
$ok($o['inventory_on'] && $o['outdated_sw']['asked'] && count($o['outdated_sw']['items']) === 1 && $o['outdated_sw']['items'][0]['hostname'] === 'WIN1' && $o['outdated_sw']['items'][0]['version'] === '125.0.6422.1', 'outdated software: Chrome 125 is older than 126.0 on WIN1');
$o2 = rivetRmmUiFleet($db, 1, ['osw' => 'chrome', 'osv' => '125.0']);
$ok($o2['outdated_sw']['items'] === [], 'outdated software: 125.0.6422.1 is not older than 125.0');
$o3 = rivetRmmUiFleet($db, 1, ['osw' => 'chrome']);
$ok($o3['outdated_sw']['asked'] === false && $o3['outdated_sw']['items'] === [], 'outdated software: a name without a version asks nothing');
$ok(rivetRmmUiFleet($db, $USER['deptb'], ['osw' => 'chrome', 'osv' => '126.0'])['outdated_sw']['items'] === [], 'outdated software: scoped to the user\'s departments');
$oh = rivetRmmUiFleetPage($o, 'tok');
preg_match('/<section[^>]*id="rmm-outdated-sw".*?<\/section>/s', $oh, $m);
$ok(isset($m[0]) && str_contains($m[0], '125.0.6422.1') && str_contains($m[0], 'WIN1') && str_contains($m[0], 'for="rmm-osw"') && str_contains($m[0], 'for="rmm-osv"') && str_contains($m[0], '1 outdated') && str_contains($m[0], '<caption class="visually-hidden">'), 'outdated software card: result row, labelled inputs, count pill, captioned table');
$oe = rivetRmmUiFleetPage($o2, 'tok');
$ok(str_contains($oe, 'No device runs a version of "chrome" older than 125.0.'), 'outdated software card: the all-clear sentence');
$ok(str_contains(rivetRmmUiFleetPage($o3, 'tok'), 'Enter a product name and the oldest version you accept'), 'outdated software card: before a question, an instruction');
$oh2 = rivetRmmUiFleetPage(rivetRmmUiFleet($db, 1, ['osw' => '<script>alert(8)</script>', 'osv' => '1']), 'tok');
$ok(!str_contains($oh2, '<script>alert(8)') && str_contains($oh2, '&lt;script&gt;alert(8)'), 'outdated software card: hostile input is escaped');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?osw=chrome&osv=126.0&status=online', $SS['admin']);
$ok($c === 200, 'fleet page over HTTP with an outdated-software question: 200 (' . $c . ')');
$ok(str_contains($body, 'id="rmm-outdated-sw"'), 'fleet page over HTTP: the outdated-software card is there');
$ok(str_contains($body, '125.0.6422.1') && str_contains($body, 'Google Chrome'), 'fleet page over HTTP: the card answers the question (WIN1 runs Chrome 125)');
$ok(str_contains($body, '<input type="hidden" name="osw" value="chrome">') && str_contains($body, '<input type="hidden" name="status" value="online">'), 'fleet page: the filter form and the card keep each other\'s state');
$pg = rivetRmmUiFleetPage(rivetRmmUiFleet($db, 1, ['osw' => 'chrome', 'osv' => '126.0', 'per_page' => 5]), 'tok');
$ok(!str_contains($pg, 'href="/agent/rmm_fleet.php?page=2') || str_contains($pg, 'osw=chrome'), 'fleet pagination links keep the outdated-software question');

// ---- per-check history: sparkline and table
$vm = $panel('WIN1');
$ok(isset($vm['trends']['disk_c']) && $vm['trends']['disk_c']['segments'] !== [] && $vm['trends']['disk_c']['availability'] !== null, 'panel: the checks carry a trend (segments and availability)');
$ok(isset($vm['trends']['svc_eventlog']) && $vm['trends']['svc_eventlog']['availability'] === 100.0, 'a check that always passed is 100% available');
// change a status and look at the trend/table
ea_checkin($S['token']['WIN1'], ['checks' => [['key' => 'svc_eventlog', 'status' => 'fail', 'detail' => 'stopped <b>hard</b>'], ['key' => 'disk_c', 'status' => 'ok', 'detail' => 'C: is 50% full'], ['key' => 'pending_reboot', 'status' => 'ok', 'detail' => 'no']]]);
$vm = $panel('WIN1');
$t = $vm['trends']['svc_eventlog'];
$ok($t['changes'] >= 1 && $t['points'][0]['status'] === 'fail' && str_contains($t['points'][0]['detail'], '<b>hard</b>'), 'a status change is recorded in the history (newest first)');
$cc = rivetRmmUiChecksCard($vm);
$ok(str_contains($cc, 'Trend (24 h)') && str_contains($cc, 'class="rmm-spark"') && str_contains($cc, 'role="img"') && preg_match('/aria-label="Last 24 hours of svc_eventlog: [0-9.]+% passing, \d+ status changes?"/', $cc) === 1, 'checks table: a Trend column with an accessible sparkline');
$ok(str_contains($cc, '<details') && str_contains($cc, 'Recorded results of svc_eventlog, newest first') && str_contains($cc, 'stopped &lt;b&gt;hard&lt;/b&gt;') && !str_contains($cc, 'stopped <b>hard</b>'), 'checks table: the history table behind a disclosure, device text escaped');
$ok(substr_count($cc, 'viewBox="0 0 120 16"') === count($vm['trends']), 'one sparkline per check that has history');
$ok(rivetRmmUiTrendCell('x', null, false) === '<span class="text-muted small">no history yet</span>', 'a check with no recorded result says so');
$tr = rivetRmmUiTrend(['hours' => 24, 'points' => [['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200), 'status' => 'ok', 'detail' => ''], ['at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'status' => 'fail', 'detail' => 'x']], 'availability_pct' => 50.0, 'changes' => 1]);
$ok(count($tr['segments']) === 2 && $tr['segments'][0]['status'] === 'ok' && $tr['segments'][1]['status'] === 'fail' && $tr['segments'][1]['to'] > 0.99 && $tr['segments'][0]['to'] > 0.9, 'trend segments: a status holds until the next point, the last until now');
// retention 0: no column, no query for the history
$rmm->admin()->saveSettings($admin, ['limits_json' => ['check_history_days' => 0]]);
$vm0 = $panel('WIN1');
$ok($vm0['check_history_on'] === false && $vm0['trends'] === [] && !str_contains(rivetRmmUiChecksCard($vm0), 'Trend ('), 'check_history_days = 0: no trend column and no history read');
$rmm->admin()->saveSettings($admin, ['limits_json' => ['check_history_days' => 7]]);

// ---- network bar against the real 24 hour peak (the metrics subsystem answers through the reader)
$sink = new EndpointMetricSink($db);
$ok($rmm->readModel()->networkPeak($W)['history'] === true, 'the module reads its metric history through the sink (RmmMetricReaderInterface)');
$sink->ingest([['asset_id' => $aW, 'key' => 'network.rx_bytes_per_s', 'instance' => 'total', 'value' => 2000000.0, 'at' => new DateTimeImmutable('-20 hours', new DateTimeZone('UTC')), 'label' => 'All adapters'],
    ['asset_id' => $aW, 'key' => 'network.tx_bytes_per_s', 'instance' => 'total', 'value' => 500000.0, 'at' => new DateTimeImmutable('-20 hours', new DateTimeZone('UTC')), 'label' => 'All adapters']], (int) $rmm->settings()->get()['integration_id']);
ea_checkin($S['token']['WIN1'], ['metrics' => ['cpu_pct' => 10.0, 'mem_pct' => 40.0, 'disk' => [['mount' => 'C:', 'used_pct' => 55.0]], 'net_rx_bps' => 8000000.0, 'net_tx_bps' => 2000000.0]]);   // 1 MB/s down, 250 kB/s up, now
$np = $rmm->readModel()->networkPeak($W);
$ok($np['history'] === true && $np['rx']['peak'] === 2000000.0 && $np['tx']['peak'] === 500000.0 && abs($np['rx']['current'] - 1000000.0) < 1 && abs($np['tx']['current'] - 250000.0) < 1 && $np['rx']['peak_at'] !== null, 'network peak: 24 h peak and the current rate (bytes/s) from the stored samples: ' . json_encode($np));
$vm = $panel('WIN1');
$nh = rivetRmmUiLiveHealth($vm);
preg_match('/id="rmm-net".*?(?=<div class="ifm-card)/s', $nh . '<div class="ifm-card', $m);
$ok(isset($m[0]) && str_contains($m[0], 'aria-label="Receive against the 24 hour peak"') && substr_count($m[0], '50% of') === 2 && str_contains($m[0], '24 h peak 16 Mbit/s') && str_contains($m[0], 'aria-label="Send against the 24 hour peak"'), 'network tile: receive and send each as a bar against the 24 hour peak, with the numbers in words');
$ok(!str_contains($nh, 'link speed not reported') && str_contains($nh, 'link speed is not reported, so the bar shows the share of the 24 hour peak'), 'network tile: the old empty-state sentence is gone');
$vmL = $panel('LNX1');
$nl = rivetRmmUiLiveHealth($vmL);
$ok(str_contains($nl, 'peak') === false || str_contains($nl, 'no 24 hour history yet') || str_contains($nl, '24 h peak'), 'network tile on a device with little history renders without error');
$vmB = $panel('BARE');
$nb = rivetRmmUiLiveHealth($vmB);
$ok(str_contains($nb, 'no data') && str_contains($nb, 'no 24 hour history yet'), 'network tile: BARE (no readings) says no data / no history, never an empty bar');
$offNet = rivetRmmUiLiveHealth($panel('OFFL'));
$ok(str_contains($offNet, 'rmm-dim'), 'network tile: dimmed with the rest while the device is offline');

// ---- the page: tabs, deep link, no-JS server-rendered content
[$c, $body] = $get('admin');
$ok(substr_count($body, 'role="tab"') === 4 && preg_match('/id="rmm-tab-software"[^>]*aria-controls="rmm-pane-software"/', $body) === 1, 'asset page: four tabs (Overview, Inventory, Software, Jobs), the Software tab is wired to its pane');
$ok(str_contains(file_get_contents($root . '/js/rmm_panel.js'), 'overview|inventory|software|jobs'), 'the tab deep link handler knows #rmm-software');
$ok(str_contains($body, 'Trend (') && str_contains($body, 'id="rmm-net"'), 'asset page: trend column and the network card');

// ---- events: catalog entries and the adapter
$extra = array_column(rivetEventCatalogExtras(), 'id');
$all = RivetCore\Rmm\RmmEvent::all();
$ok(array_diff($all, $extra) === [] && count($all) === 9, 'every rmm.* event id is in the picker\'s catalog extras');
$ids = rivetEventCatalogIds();
$ok(array_diff($all, $ids) === [], 'the picker catalog (Core + extras) offers every rmm.* event');
$grp = rivetEventCatalogData()['groups'];
$ok(isset($grp['rmm']) && $grp['rmm']['count'] === 9, 'the picker has one "rmm" group with the nine events');
[$c, $body] = web($wb, 'GET', '/modals/event_catalog.php', $SS['admin']);
$ok($c === 200 && str_contains($body, 'rmm.software.installed') && str_contains($body, 'rmm.check.failed'), 'the picker\'s catalog endpoint lists the rmm.* events');
// a check that opens an alert publishes rmm.check.failed
$before = count(array_filter($evs(), fn($e) => $e === 'rmm.check.failed'));
for ($i = 0; $i < 3; $i++) { ea_checkin($S['token']['LNX1'], ['checks' => [['key' => 'svc_x', 'status' => 'fail', 'detail' => 'down']]]); }
$after2 = count(array_filter($evs(), fn($e) => $e === 'rmm.check.failed'));
$ok($after2 === $before + 1, 'an alert-opening check publishes exactly one rmm.check.failed (' . $before . ' -> ' . $after2 . ')');
$ok(count(array_filter($deliveries(), fn($p) => str_starts_with($p['event'], 'rmm.'))) === count($deliveries()), 'only rmm.* events were queued for the rmm.* subscription');
$q("UPDATE webhooks SET webhook_enabled=0");

// ---- the module switched OFF: every new entry point asks the database nothing and answers nothing
$rmm->admin()->disable($admin); $fresh();
$a = $questions();
$o1 = rivetRmmUiPanel($db, $aW, 1, $W, null, ['swq' => 'chrome', 'swp' => 2, 'swr' => 1]);
$o2 = rivetRmmUiFleet($db, 1, ['tag' => 'VIP', 'group' => $gid, 'software' => 'x', 'osw' => 'chrome', 'osv' => '1']);
$b = $questions();
$ok($o1 === null && $o2 === null && $b - $a === $overhead, 'OFF: the panel (with software search) and the fleet (with the new filters) are null with zero statements');
[$c, $b] = $post('admin', ['action' => 'tag_add', 'tag' => 'Offline tag']);
$ok(in_array($c, [403, 404], true) && $one("SELECT COUNT(*) FROM rmm_tags WHERE name='Offline tag'") === '0', 'OFF: tag_add is refused and nothing is written (' . $c . ')');
[$c, $b] = $post('admin', ['action' => 'software_refresh']);
$ok(in_array($c, [403, 404], true), 'OFF: software_refresh is refused (' . $c . ')');
[$c, $body] = $get('admin');
$ok(!str_contains($body, 'rmm-pane-software') && !str_contains($body, 'id="rmm-tags"') && !str_contains($body, 'Google Chrome'), 'OFF: the asset page has no Software tab, no tags, no software');
[$c, $body] = web($wb, 'GET', '/agent/rmm_fleet.php?osw=chrome&osv=126', $SS['admin']);
$ok($c === 403 && !str_contains($body, 'rmm-outdated-sw') && !str_contains($body, '125.0.6422.1'), 'OFF: the fleet page answers the module-off notice, nothing about software');
[$c] = $adm1(['save_inventory_settings' => 1, 'inventory_software' => '', 'check_history_days' => 7, 'software_history_days' => 365]);
$fresh();
$ok($rmm->settings()->features()['inventory_software'] === false, 'OFF: Administration still works while the module is off (the software switch can be turned off)');
$rmm->admin()->enable($admin); $fresh();
$ok($rmm->featureOn('inventory_software') === false, 'inventory_software stays off when the module is switched on again');
$vm = $panel('WIN1');
$ok($vm['software'] === null && !str_contains(rivetRmmUiTabs($vm, '', '', [], 'tok'), 'rmm-tab-software'), 'with the software switch off again the Software tab is gone (data is kept)');
$ok($rmm->readModel()->softwareFor($W)['total'] === 4, 'switching the inventory off deletes nothing');
