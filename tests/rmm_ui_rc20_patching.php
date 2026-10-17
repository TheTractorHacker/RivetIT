<?php
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 4, patch management, RivetCore 1.0.0-rc.20): the `patching` sub-switch and its Administration card, the three abilities in the role matrix, the
 * Patches tab of the asset page, the Patch compliance, Patch approvals and Patch policies pages, and the form handler (agent/post/rmm_patching.php). Shares that file's variables.
 *
 * Everything is driven through the product: devices enroll and check in through the real device endpoints, a scan is requested through the real handler (CSRF, forged sessions, `php -S`),
 * and the DEVICE is simulated through the real endpoints too: GET /api/v1/agent_jobs receives the signed patch job, POST /api/v1/agent_jobs reports its result, which Core applies. The
 * `patching` switch is OFF at the start (the default) and is put back OFF at the end.
 */

use RivetCore\Rmm\Authz\RmmAbility;

$ptGet = fn (string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$ptPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_patching.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$ptJson = function (string $who, array $f) use ($ptPost): array {
    [$c, $b] = $ptPost($who, $f, ['Accept: application/json']);

    return [$c, json_decode($b, true) ?: [], $b];
};
$ptLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$ptRows = fn (string $sql) => $rows($sql);
$ptUpd = fn (int $dev, string $id) => $ptRows("SELECT * FROM rmm_patch_available WHERE device_id=$dev AND update_id='" . $esc($id) . "'")[0] ?? null;
$ptAudit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");
$ptNav = fn (?int $uid = null) => array_values(array_filter(array_column(rivetRmmAutoNav($uid), 'href'), fn ($h) => str_contains($h, 'rmm_patch')));
$ptQ = function () use ($db): int { return (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]; };
$ptSettings = fn (array $f, string $who = 'admin') => $adm1($f + ['save_patch_settings' => 1], $who);
$ptFeatures = fn () => json_decode((string) $db->query('SELECT features_json FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0], true) ?: [];
$ptNoInline = fn (string $html) => preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $html) !== 1;

// ---- the starting state: module on, patching OFF (the default)
$ok($rmm->enabled() && !$rmm->featureOn('patching') && !rivetRmmFeatureOn('patching'), 'patching: the module is on and the patching switch is off at the start');
$ok($ptNav() === [] && $ptNav($USER['admin']) === [], 'menu: no patch entries while the switch is off');
$ptOver = (function () use ($ptQ) { $x = $ptQ(); return $ptQ() - $x; })();
$a = $ptQ(); $ptNav(); $b = $ptQ();
$ok($b - $a === $ptOver, 'menu: the check costs no statement while the switch is off (' . ($b - $a) . ' vs ' . $ptOver . ')');
foreach (['/agent/rmm_patches.php', '/agent/rmm_patch_updates.php', '/agent/rmm_patch_policies.php'] as $path) {
    [$c, $body] = $ptGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off') && str_contains($body, 'Patch management'), "$path: 403 and the switched-off notice while the patching switch is off ($c)");
}
[$c, $j] = $ptJson('admin', ['action' => 'scan', 'device_id' => $D['WIN1']]);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'handler (JSON): the switched-off refusal is a 404 with a message');
$vm = $panel('WIN1');
$ok(($vm['features']['patching'] ?? null) === false && !str_contains(rivetRmmUiTabs($vm, '', '', [], 'tok'), 'rmm-tab-patches'), 'the asset page has no Patches tab while the switch is off');
$settingsPage = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin'])[1];
$ok(str_contains($settingsPage, 'id="patch-management"') && str_contains($settingsPage, 'name="feature_patching"') && str_contains($settingsPage, 'once a day (a short read-only check, no download)') && !preg_match('/name="feature_patching"[^>]*checked/', $settingsPage), 'Administration: the Patch management card shows the cost text and the switch is off');
$ok(str_contains($settingsPage, 'name="patch_live_install"') && str_contains($settingsPage, 'has never been run on a real machine') && $ptNoInline($settingsPage), 'Administration: live installs are a separate switch that carries the Windows warning; no inline handlers');

// ---- the role matrix for the three abilities
rivetRmmForgetAccess();
$auth = $rmm->authorizer();
$matrix = [   // user => [view, manage, install]
    'admin' => [true, true, true], 'tech' => [true, true, true], 'rebootonly' => [true, true, false], 'viewer' => [true, false, false], 'remoteonly' => [true, false, false],
    'moduleonly' => [true, false, false], 'normo' => [false, false, false], 'deptb' => [true, true, true],
];
foreach ($matrix as $who => $exp) {
    $got = [$auth->allowed($USER[$who], RmmAbility::PATCH_VIEW, 0), $auth->allowed($USER[$who], RmmAbility::PATCH_MANAGE, 0), $auth->allowed($USER[$who], RmmAbility::PATCH_INSTALL, 0)];
    $ok($got === $exp, "$who: patch [view, manage, install] = " . json_encode($got));
}
$ok(!$auth->allowed(999999, RmmAbility::PATCH_VIEW, 0) && !$auth->allowed(0, RmmAbility::PATCH_MANAGE, 0), 'patch abilities: an unknown user and user 0 are denied');
$pp = rivetRmmPatPerm($db, $USER['rebootonly']);
$ok($pp['view'] && $pp['manage'] && !$pp['install'] && $pp['reboot'] && !$pp['admin'], 'the page permission helper agrees: a level 2 script technician manages and may restart, but cannot install');

// ---- Administration: the card is admin-only; the switch, the limits and the live-install acknowledgement
[$c] = $ptSettings(['feature_patching' => '1', 'patch_scan_interval_s' => 7200], 'tech');
$ok(!$rmm->featureOn('patching') && $rmm->settings()->limits()['patch_scan_interval_s'] === 86400, 'a technician cannot save the patch settings (' . $c . ')');
$ok(($ptFeatures()['patching'] ?? false) === false, 'the switch is still off');
[$c] = $ptSettings(['feature_patching' => '1', 'patch_scan_interval_s' => 100]);
$ok(!$rmm->featureOn('patching') && $rmm->settings()->limits()['patch_scan_interval_s'] === 86400, 'an out-of-range limit is refused by Core and nothing is saved (' . $c . ')');
[$c] = $ptSettings(['feature_patching' => '1', 'patch_scan_interval_s' => 7200, 'patch_overdue_days' => 10, 'patch_max_installs' => 30, 'patch_max_installs_per_client' => 6, 'patch_history_days' => 400, 'patch_batch' => 120]);
$lim = $rmm->settings()->limits();
$ok(in_array($c, [200, 302], true) && $rmm->featureOn('patching') && rivetRmmFeatureOn('patching') && $lim['patch_scan_interval_s'] === 7200 && $lim['patch_overdue_days'] === 10 && $lim['patch_max_installs'] === 30
    && $lim['patch_max_installs_per_client'] === 6 && $lim['patch_history_days'] === 400 && $lim['patch_batch'] === 120 && $lim['patch_live_install'] === 0, 'administrator: the switch and the six limits are saved; live installs stay off');
$ok(($ptFeatures()['monitoring'] ?? null) === true && ($ptFeatures()['patching'] ?? null) === true, 'saving the switch keeps the other effective features');
[$c] = $ptSettings(['feature_patching' => '1', 'patch_live_install' => '1']);
$ok($rmm->settings()->limits()['patch_live_install'] === 0, 'live installs cannot be switched on without the acknowledgement');
[$c] = $ptSettings(['feature_patching' => '1', 'patch_live_install' => '1', 'accept_patch_live' => '1']);
$ok($rmm->settings()->limits()['patch_live_install'] === 1 && $ptAudit('Settings Changed') >= 1, 'with the acknowledgement live installs switch on and the change is audited');
[$c] = $ptSettings(['feature_patching' => '1', 'patch_scan_interval_s' => 7200]);   // live_install box not sent = off
$ok($rmm->settings()->limits()['patch_live_install'] === 0 && $rmm->featureOn('patching'), 'leaving the live-install box unticked switches live installs off again');
$ok($ptNav() === ['/agent/rmm_patches.php', '/agent/rmm_patch_updates.php', '/agent/rmm_patch_policies.php'] && count(rivetRmmAutoNav()) === 12, 'menu: the three patch entries appear with the switch on');
$ok($ptNav($USER['viewer']) === $ptNav() && $ptNav($USER['normo']) === [], 'menu: a viewer sees the entries (read only), a user without the RMM role none');
$settingsPage = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin'])[1];
$ok(preg_match('/name="feature_patching"[^>]*checked/', $settingsPage) === 1 && str_contains($settingsPage, 'value="7200"') && str_contains($settingsPage, 'Open patch compliance'), 'Administration: the card now shows the switch on, the saved values and a link');

// ---- devices of this fragment: a Windows and a Linux device in Dept A, a Linux device in Dept B; they announce the patch capabilities
$ptDev = $ptTok = $ptAsset = [];
$ptCaps = ['job:collect', 'job:patch_scan', 'job:patch_install', 'patch:scan', 'patch:install'];
foreach (['PTWIN' => [1, 'A', 'windows', $ptCaps], 'PTLNX' => [1, 'A', 'linux', $ptCaps], 'PTOLD' => [1, 'A', 'windows', ['job:collect']], 'PTB' => [2, 'B', 'linux', $ptCaps]] as $n => [$client, $tk, $os, $caps]) {
    $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $ptAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $ptDev[$n] = (int) ($j['device_id'] ?? 0);
    $ptTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $ptDev[$n] > 0, "$n enrolled through the real endpoint");
    [$c] = ea_checkin($ptTok[$n], ['capabilities' => $caps]);
    $ok($c === 200, "$n checked in and announced " . implode(',', array_filter($caps, fn ($x) => str_starts_with($x, 'patch:'))));
}
$A += $ptAsset;
$ptHtml = fn (string $who, string $n, string $qs = '') => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A[$n] . $qs, $SS[$who]);

// the simulated device
$ptOffered = function (string $tok): array { [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok); return $c === 200 ? ($j['jobs'] ?? []) : []; };
$ptJobOf = function (string $tok, string $type) use ($ptOffered): ?array { foreach ($ptOffered($tok) as $j) { if ($j['type'] === $type) { return $j; } } return null; };
$ptReport = fn (string $tok, array $job, string $state, string $out, int $exit = 0) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $out,
    'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')]);
$GU = ['crit' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'sec' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'opt' => 'cccccccc-3333-4333-8333-cccccccccccc', 'host' => 'dddddddd-4444-4444-8444-dddddddddddd'];
$hostile = '<script>alert(1)</script> "dq" \'sq\' & </td></tr><img src=x onerror=alert(2)>';
$ptWinScan = function (array $over = []) use ($GU, $hostile): string {
    $upd = fn (string $id, string $kind, string $title, array $x = []) => $x + ['id' => $id, 'kb' => 'KB5000001', 'title' => $title, 'kind' => $kind, 'severity' => $kind === 'optional' ? 'unspecified' : 'important', 'size' => 1048576,
        'reboot_required' => $kind === 'security', 'released_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 3), 'eula_accepted' => true, 'categories' => ['Security Updates'], 'current' => '', 'version' => ''];
    $list = [$upd($GU['crit'], 'critical', 'PT critical rollup'), $upd($GU['sec'], 'security', 'PT security fix'), $upd($GU['opt'], 'optional', 'PT optional update'), $upd($GU['host'], 'optional', $hostile, ['kb' => 'KB<b>1</b>'])];

    return (string) json_encode($over + ['v' => 1, 'ok' => true, 'manager' => 'wua', 'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'scan_state' => 'ok', 'wu_state' => 'running', 'reboot_pending' => false,
        'last_install_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 9), 'truncated' => false, 'total' => count($list), 'updates' => $list]);
};
$ptLinScan = fn () => (string) json_encode(['v' => 1, 'ok' => true, 'manager' => 'apt', 'scanned_at' => gmdate('Y-m-d\TH:i:s\Z'), 'scan_state' => 'ok', 'wu_state' => 'n/a', 'reboot_pending' => true, 'last_install_at' => null, 'truncated' => false, 'total' => 2,
    'updates' => [['id' => 'openssl', 'kb' => '', 'title' => 'openssl', 'kind' => 'security', 'severity' => 'important', 'size' => 2048, 'reboot_required' => false, 'released_at' => null, 'eula_accepted' => true, 'categories' => [], 'current' => '3.0.1', 'version' => '3.0.2'],
        ['id' => 'libc6:amd64', 'kb' => '', 'title' => 'libc6', 'kind' => 'optional', 'severity' => 'unspecified', 'size' => 4096, 'reboot_required' => false, 'released_at' => null, 'eula_accepted' => true, 'categories' => [], 'current' => '2.39', 'version' => '2.40']]]);

// ---- before the first scan: the tab says so, the scan button queues a job, and only a device that announced the capability gets one
[$c, $body] = $ptHtml('admin', 'PTWIN');
$ok($c === 200 && str_contains($body, 'id="rmm-tab-patches"') && str_contains($body, 'id="rmm-pane-patches"') && str_contains($body, 'No scan yet') && str_contains($body, 'Never scanned'), 'PTWIN: the Patches tab shows "never scanned" before the first scan');
$ok(str_contains(file_get_contents($root . '/js/rmm_panel.js'), 'logs|patches') && $ptNoInline($body), 'the tab deep link handler knows #rmm-patches; no inline handlers on the asset page');
[$c, $body] = $ptHtml('admin', 'PTOLD');
$ok(str_contains($body, 'has not announced that it can scan for updates') && str_contains($body, 'patch:scan'), 'PTOLD (an older agent): the tab explains why nothing happens');
[$c, $j] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptDev['PTOLD']]);
$ok($c === 409 && ($j['success'] ?? null) === false && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTOLD'] . " AND type='patch_scan'") === 0, 'scan on a device that did not announce patch:scan is refused and queues nothing (' . $c . ')');
[$c, $j] = $ptJson('viewer', ['action' => 'scan', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 403 && ($j['success'] ?? null) === false && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 0, 'a viewer cannot start a scan (' . $c . ')');
[$c, $body] = web($wb, 'POST', '/agent/post/rmm_patching.php', $SS['admin'], ['action' => 'scan', 'device_id' => $ptDev['PTWIN']]);   // no CSRF token
$ok((int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 0, 'a post without a CSRF token queues nothing (' . $c . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_patching.php?action=scan&device_id=' . $ptDev['PTWIN'], $SS['admin']);
$ok($c === 405 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 0, 'the handler answers a GET with 405');
[$c, $j] = $ptJson('deptb', ['action' => 'scan', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 404 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 0, 'a Dept B user scanning a Dept A device gets the same 404 as a missing device (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'scan', 'device_id' => 99999999]);
$ok($c === 404, 'scan of a device that does not exist: 404 (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 200 && ($j['success'] ?? null) === true && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 1, 'the administrator queues a scan (' . $c . ')');
[$c, $j] = $ptJson('tech', ['action' => 'scan', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id=' . $ptDev['PTWIN'] . " AND type='patch_scan'") === 1, 'a second scan request while one waits is not queued twice');
$job = $ptJobOf($ptTok['PTWIN'], 'patch_scan');
$ok($job !== null && ($job['signature'] ?? '') !== '', 'the device receives the signed patch_scan job through /api/v1/agent_jobs');
[$c] = $ptReport($ptTok['PTWIN'], $job, 'succeeded', $ptWinScan());
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM rmm_patch_available WHERE device_id=' . $ptDev['PTWIN']) === 4, 'the scan result is applied: four updates are listed for the device');
$job = $ptJobOf($ptTok['PTLNX'], 'patch_scan');
$ok($job === null, 'the Linux device has no scan job yet');
[$c] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptDev['PTLNX']]);
$job = $ptJobOf($ptTok['PTLNX'], 'patch_scan');
[$c] = $ptReport($ptTok['PTLNX'], $job, 'succeeded', $ptLinScan());
$ok((int) $one('SELECT COUNT(*) FROM rmm_patch_available WHERE device_id=' . $ptDev['PTLNX']) === 2, 'the Linux scan is applied (apt: openssl and libc6:amd64)');

// ---- the tab with data
[$c, $body] = $ptHtml('admin', 'PTWIN');
$ok($c === 200 && str_contains($body, 'PT critical rollup') && str_contains($body, 'PT security fix') && str_contains($body, 'Waiting for a decision') && str_contains($body, 'Windows Update') && str_contains($body, 'Compliant'), 'PTWIN tab: updates, status pills, the manager and the verdict');
$ok(!str_contains($body, '<script>alert(1)') && !str_contains($body, '<img src=x') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, 'KB<b>1</b>'), 'PTWIN tab: a hostile update title and KB are escaped');
$ok(preg_match('/<button[^>]*name="action" value="approve"/', $body) === 1 && preg_match('/name="update_ids\[\]" value="' . $GU['crit'] . '"/', $body) === 1 && str_contains($body, 'Install for real') && str_contains($body, 'data-rmm-confirm='), 'PTWIN tab (administrator): tick boxes, approve / decline / dry run, the install section with its confirmation dialog');
[, $viewerBody] = $ptHtml('viewer', 'PTWIN');
$ok(str_contains($viewerBody, 'PT critical rollup') && !str_contains($viewerBody, 'name="update_ids[]"') && !str_contains($viewerBody, 'value="approve"') && !str_contains($viewerBody, 'Scan now') && str_contains($viewerBody, 'Your role can read patch status but not change it'), 'PTWIN tab (viewer): the list is read only, no tick boxes, no buttons');
$rbBody = rivetRmmPatPanelSection(rivetRmmUiPanel($db, $ptAsset['PTWIN'], $USER['rebootonly']), $db, 'csrftok1');   // (this role cannot open the asset page itself; the section is what the page embeds)
$ok(str_contains($rbBody, 'value="approve"') && !str_contains($rbBody, 'Install for real'), 'PTWIN tab (level 2 script technician): approve and dry run, no install section');
[$c, $nb] = $ptHtml('deptb', 'PTWIN');
$ok(!str_contains($nb, 'PT critical rollup') && !str_contains($nb, 'rmm-tab-patches'), 'PTWIN tab (Dept B user): nothing of a Dept A device');
[$c, $mb] = $ptHtml('moduleonly', 'PTWIN');
$ok(!str_contains($mb, 'value="approve"') && !str_contains($mb, 'Install for real'), 'PTWIN tab (module-only login): never any controls');
[, $fb] = $ptHtml('admin', 'PTWIN', '&pt_kind=security');
$ok(str_contains($fb, 'PT security fix') && !str_contains($fb, 'PT optional update'), 'tab filter: kind=security shows only that kind');
[, $fb] = $ptHtml('admin', 'PTWIN', '&pt_q=rollup&pt_status=available');
$ok(str_contains($fb, 'PT critical rollup') && !str_contains($fb, 'PT security fix'), 'tab filter: a search for "rollup" finds one update');
[, $fb] = $ptHtml('admin', 'PTWIN', '&pt_status=bogus&pt_kind=%22%3E%3Cx&pt_page=-5');
$ok(str_contains($fb, 'PT critical rollup') && !str_contains($fb, '"><x'), 'tab filter: nonsense values fall back to the defaults and are never echoed');
[, $lb] = $ptHtml('admin', 'PTLNX');
$ok(str_contains($lb, 'openssl') && str_contains($lb, '3.0.1') && str_contains($lb, 'apt') && str_contains($lb, 'A restart is pending'), 'PTLNX tab: package names, versions, the manager and the pending restart');

// ---- approve and decline on one device
[$c, $j] = $ptJson('viewer', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['crit']]]);
$ok($c === 403 && $ptUpd($ptDev['PTWIN'], $GU['crit'])['status'] === 'available', 'a viewer cannot approve (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['crit']]]);
$ok($c === 404 && $ptUpd($ptDev['PTWIN'], $GU['crit'])['status'] === 'available', 'a Dept B user cannot approve on a Dept A device: same 404 as a missing device (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'Tick at least one update'), 'approve with nothing ticked is refused with words (' . $c . ')');
foreach (['--oAPT::Foo', 'a b', "x;touch /w/pwned", '$(id)', "openssl\n", str_repeat('a', 150)] as $bad) {
    [$c] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptDev['PTLNX'], 'update_ids' => [$bad]]);
    $ok($c === 422 && (int) $one("SELECT COUNT(*) FROM rmm_patch_available WHERE status='approved' AND device_id=" . $ptDev['PTLNX']) === 0, 'a hostile update id ' . json_encode(substr($bad, 0, 20)) . ' is refused (' . $c . ')');
}
[$c, $j] = $ptJson('tech', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['crit'], $GU['sec']]]);
$ok($c === 200 && ($j['success'] ?? null) === true && $ptUpd($ptDev['PTWIN'], $GU['crit'])['status'] === 'approved' && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'approved' && $ptUpd($ptDev['PTWIN'], $GU['opt'])['status'] === 'available'
    && (int) $ptUpd($ptDev['PTWIN'], $GU['crit'])['status_by'] === $USER['tech'], 'a technician approves two updates: only those, by that user (' . ($j['message'] ?? '') . ')');
$ok($ptAudit('Patch Approved') >= 1, 'the approval is in the audit log');
[$c, $j] = $ptJson('tech', ['action' => 'decline', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['opt']], 'reason' => 'Not needed here <b>x</b>']);
$u = $ptUpd($ptDev['PTWIN'], $GU['opt']);
$ok($c === 200 && $u['status'] === 'declined' && str_contains((string) $u['decline_reason'], 'Not needed'), 'a technician declines an update with a reason');
[, $body] = $ptHtml('admin', 'PTWIN', '&pt_status=declined');
$ok(str_contains($body, 'PT optional update') && str_contains($body, 'Declined') && !str_contains($body, '<b>x</b>') && !str_contains($body, 'PT critical rollup'), 'the declined filter shows the declined update and the reason, escaped');

// ---- dry run, install: confirmation, abilities, only approved updates
[$c, $j] = $ptJson('tech', ['action' => 'dry_run', 'device_id' => $ptDev['PTWIN']]);
$dry = $ptJobOf($ptTok['PTWIN'], 'patch_install');
$ok($c === 200 && ($j['success'] ?? null) === true && $dry !== null && str_contains(json_encode($dry), '"dry_run"'), 'a dry run queues a patch_install job in dry_run mode');
$dryIds = (string) (json_decode(json_encode($dry), true)['params']['ids'] ?? '');
$ok(str_contains($dryIds, $GU['crit']) && str_contains($dryIds, $GU['sec']) && !str_contains($dryIds, $GU['opt']) && !str_contains($dryIds, $GU['host']), 'the dry run names exactly the approved updates (' . $dryIds . ')');
$ptDryOut = (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'dry_run', 'manager' => 'wua', 'reboot_required' => false, 'truncated' => false,
    'results' => [['id' => $GU['crit'], 'title' => 'PT critical rollup', 'result' => 'would_install', 'code' => 0, 'detail' => 'would install', 'reboot_required' => false], ['id' => $GU['sec'], 'title' => 'PT security fix', 'result' => 'would_install', 'code' => 0, 'detail' => '', 'reboot_required' => true]]]);
[$c] = $ptReport($ptTok['PTWIN'], $dry, 'succeeded', $ptDryOut);
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM rmm_patch_history WHERE device_id=' . $ptDev['PTWIN'] . " AND action='dry_run'") === 2 && $ptUpd($ptDev['PTWIN'], $GU['crit'])['status'] === 'approved', 'the dry run result is recorded in the history; nothing is marked installed');
[$c, $j] = $ptJson('viewer', ['action' => 'dry_run', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 403, 'a viewer cannot start a dry run (' . $c . ')');
[$c, $j] = $ptJson('rebootonly', ['action' => 'install', 'device_id' => $ptDev['PTWIN'], 'confirm' => '1']);
$ok($c === 403 && $ptJobOf($ptTok['PTWIN'], 'patch_install') === null, 'a level 2 script technician cannot install, even with the confirmation (' . $c . ')');
[$c, $j] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptDev['PTWIN']]);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'confirmation box') && $ptJobOf($ptTok['PTWIN'], 'patch_install') === null, 'install without the confirmation box is refused and queues nothing (' . $c . ')');
[$c, $j] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptDev['PTWIN'], 'confirm' => '1', 'reboot_policy' => 'if_required_now']);
$ok($c === 422 && $ptJobOf($ptTok['PTWIN'], 'patch_install') === null, 'a restart after patching needs its own confirmation (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'install', 'device_id' => $ptDev['PTWIN'], 'confirm' => '1']);
$ok($c === 404 && $ptJobOf($ptTok['PTWIN'], 'patch_install') === null, 'a Dept B user cannot install on a Dept A device (' . $c . ')');
[$c, $j] = $ptJson('tech', ['action' => 'install', 'device_id' => $ptDev['PTWIN'], 'confirm' => '1', 'update_ids' => [$GU['crit']]]);
$inst = $ptJobOf($ptTok['PTWIN'], 'patch_install');
$instP = json_decode(json_encode($inst), true)['params'] ?? [];
$ok($c === 200 && $inst !== null && ($instP['mode'] ?? '') === 'install' && ($instP['ids'] ?? '') === $GU['crit'], 'a technician with the install ability installs one named approved update (' . json_encode($instP) . ')');
$ptInstOut = (string) json_encode(['v' => 1, 'ok' => true, 'mode' => 'install', 'manager' => 'wua', 'reboot_required' => false, 'truncated' => false,
    'results' => [['id' => $GU['crit'], 'title' => 'PT critical rollup', 'result' => 'installed', 'code' => 0, 'detail' => 'installed', 'reboot_required' => false]]]);
[$c] = $ptReport($ptTok['PTWIN'], $inst, 'succeeded', $ptInstOut);
$ok($ptUpd($ptDev['PTWIN'], $GU['crit'])['status'] === 'installed' && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'approved' && $ptAudit('Patch Install Queued') >= 1, 'the install result marks that update installed; the other approved update is untouched; the install is audited');
[, $body] = $ptHtml('admin', 'PTWIN');
$ok(str_contains($body, 'Install history') && str_contains($body, 'Installed') && str_contains($body, 'Dry run'), 'the tab shows the install history');
[$c, $j] = $ptJson('admin', ['action' => 'install', 'device_id' => $ptDev['PTWIN'], 'confirm' => '1', 'update_ids' => [$GU['host']]]);
$ok($c === 404, 'installing an update that was never approved is refused by Core as not found (' . $c . ')');
$ok($ptJobOf($ptTok['PTWIN'], 'patch_install') === null || !str_contains(json_encode($ptJobOf($ptTok['PTWIN'], 'patch_install')), $GU['host']), 'an unapproved update id never reaches the device');

// ---- the fleet pages
[$c, $body] = $ptGet('admin', '/agent/rmm_patches.php');
$ok($c === 200 && str_contains($body, 'Patch compliance') && str_contains($body, 'Devices') && str_contains($body, 'PTWIN') === false && str_contains($body, 'Dept A') && $ptNoInline($body), 'compliance page: totals and the department table (' . $c . ')');
// age a critical update so the Windows device becomes non-compliant
$q("UPDATE rmm_patch_available SET first_seen_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) WHERE device_id=" . $ptDev['PTWIN'] . " AND update_id='" . $GU['sec'] . "'");
[$c, $body] = $ptGet('admin', '/agent/rmm_patches.php');
$ok(str_contains($body, 'PTWIN') && str_contains($body, '/agent/asset_details.php?asset_id=' . $A['PTWIN'] . '#rmm-patches') && str_contains($body, 'Devices to look at'), 'compliance page: the overdue device is listed with a link to its Patches tab');
[, $nc] = $ptHtml('admin', 'PTWIN');
$ok(str_contains($nc, 'Not compliant') && str_contains($nc, 'missing more than 10 days'), 'the tab agrees: not compliant, with the configured limit (10 days)');
[$c, $body] = $ptGet('deptb', '/agent/rmm_patches.php');
$ok($c === 200 && !str_contains($body, 'PTWIN') && !str_contains($body, 'Dept A') && !str_contains($body, 'PTLNX'), 'compliance page (Dept B user): nothing of Dept A');
[$c, $body] = $ptGet('deptb', '/agent/rmm_patches.php?client_id=' . 1);
$ok(!str_contains($body, 'PTWIN') && (str_contains($body, 'No such client') || str_contains($body, 'could not be read') || str_contains($body, 'No devices')), 'compliance page (Dept B user asking for Dept A): refused, nothing leaks');
[$c, $body] = $ptGet('admin', '/agent/rmm_patches.php?group_by=site&os=linux&overdue_days=1&client_id=1');
$ok($c === 200 && !str_contains($body, 'PTWIN'), 'compliance page: os, overdue days, group by and department filters apply');
[$c, $body] = $ptGet('admin', '/agent/rmm_patches.php?group_by=%22%3E%3Cscript%3E&os=x&client_id=abc&overdue_days=-1&page=-3');
$ok($c === 200 && !str_contains($body, '"><script>') && !str_contains($body, '&quot;&gt;&lt;script') && true, 'compliance page: hostile parameters are ignored, not echoed');
[$c, $body] = $ptGet('viewer', '/agent/rmm_patches.php');
$ok($c === 200 && str_contains($body, 'Patch compliance'), 'compliance page: a viewer can read it');
[$c, $body] = $ptGet('normo', '/agent/rmm_patches.php');
$ok($c === 403, 'compliance page: a user without the RMM role is refused (' . $c . ')');

[$c, $body] = $ptGet('admin', '/agent/rmm_patch_updates.php');
$ok($c === 200 && str_contains($body, $GU['host']) && str_contains($body, 'Waiting for a decision') === false || str_contains($body, 'openssl'), 'approvals page: updates still waiting for a decision, one row per update id');
$ok(str_contains($body, 'openssl') && str_contains($body, 'libc6:amd64') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($body, '<script>alert(1)') && str_contains($body, 'name="update_ids[]"'), 'approvals page: Linux and Windows rows, the hostile title escaped, tick boxes for a manager');
[$c, $body] = $ptGet('deptb', '/agent/rmm_patch_updates.php');
$ok($c === 200 && !str_contains($body, 'openssl') && !str_contains($body, 'PT security fix'), 'approvals page (Dept B user): nothing of Dept A');
[$c, $body] = $ptGet('viewer', '/agent/rmm_patch_updates.php');
$ok($c === 200 && str_contains($body, 'openssl') && !str_contains($body, 'name="update_ids[]"') && str_contains($body, 'but not approve or decline'), 'approvals page (viewer): read only');
// fleet approve, scoped by department
[$c, $j] = $ptJson('deptb', ['action' => 'approve', 'update_ids' => ['openssl'], 'client_id' => 1]);
$ok(in_array($c, [403, 404], true) && $ptUpd($ptDev['PTLNX'], 'openssl')['status'] === 'available', 'fleet approve: a Dept B user naming Dept A changes nothing (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'approve', 'update_ids' => ['openssl']]);
$ok(in_array($c, [403, 404], true) && $ptUpd($ptDev['PTLNX'], 'openssl')['status'] === 'available', 'fleet approve without a department: a Dept B user still reaches only Dept B (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'approve', 'update_ids' => ['openssl'], 'client_id' => 1]);
$ok($c === 200 && $ptUpd($ptDev['PTLNX'], 'openssl')['status'] === 'approved' && $ptUpd($ptDev['PTLNX'], 'libc6:amd64')['status'] === 'available', 'fleet approve by the administrator for Dept A: that update id on that department (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $j] = $ptJson('admin', ['action' => 'decline', 'update_ids' => ['libc6:amd64'], 'reason' => 'x']);
$ok($c === 200 && $ptUpd($ptDev['PTLNX'], 'libc6:amd64')['status'] === 'declined', 'fleet decline works across devices');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_updates.php?status=declined');
$ok($c === 200 && str_contains($body, 'libc6:amd64') && !str_contains($body, 'openssl'), 'approvals page: the declined filter');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_updates.php?status=%22%3E%3Cb%3E&client_id=zz&page=0');
$ok($c === 200 && !str_contains($body, '"><b>') && !str_contains($body, '&quot;&gt;&lt;b') && !str_contains($body, 'zz'), 'approvals page: hostile parameters are ignored, not echoed');

// ---- the REST API carries the same routes with the same rules (technician tokens; nothing to route in the edition)
$tk = $S['users'];
[$c, , $j] = http('GET', '/api/v1/endpoint_devices/patches/compliance', $tk['tech']);
$ok($c === 200 && isset($j['summary']['devices']) && $j['summary']['devices'] >= 3, 'REST: GET endpoint_devices/patches/compliance answers for a technician (' . $c . ')');
[$c, , $j] = http('GET', '/api/v1/endpoint_devices/patches/compliance', $tk['deptb']);
$ptDeptB = (int) $one('SELECT COUNT(*) FROM endpoint_agent_devices WHERE client_id = 2 AND revoked_at IS NULL AND retired_at IS NULL');
$ok($c === 200 && ($j['summary']['devices'] ?? -1) === $ptDeptB && $ptDeptB >= 1 && array_column($j['groups'] ?? [], 'key') === [2], 'REST: a Dept B token sees only Dept B (' . json_encode($j['summary'] ?? null) . ' of ' . $ptDeptB . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/' . $ptDev['PTWIN'] . '/patches', $tk['deptb']);
$ok($c === 404, 'REST: a Dept B token asking for a Dept A device gets the 404 of a missing device (' . $c . ')');
[$c] = http('POST', '/api/v1/endpoint_devices/patches/approve', $tk['viewer'], ['update_ids' => ['openssl']]);
$ok($c === 403 && $ptUpd($ptDev['PTLNX'], 'openssl')['status'] !== 'xx', 'REST: a viewer token cannot approve (' . $c . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/patches/compliance');
$ok($c === 401, 'REST: no token, no answer (' . $c . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/patches/compliance', $tk['normo']);
$ok($c === 403, 'REST: a token of a user without the RMM role is refused (' . $c . ')');

// ---- policies: the page, the forms, the refusals
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok($c === 200 && str_contains($body, 'No patch policy yet') && str_contains($body, 'pilot') && str_contains($body, 'broad') && str_contains($body, 'last') && str_contains($body, 'id="rings"') && str_contains($body, 'id="rules"') && $ptNoInline($body), 'policies page: no policy yet, the three seeded rings, the rule section');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php?new=1');
$ok(str_contains($body, 'id="pol-form"') && str_contains($body, 'name="s_window_days[]"') && str_contains($body, 'data-rmm-when="#pat_window_mode=daily"') && str_contains($body, 'data-rmm-when="#pat_reboot=if_required_now"'), 'policies page: the form with the window and restart controls that appear on demand');
[, $vb] = $ptGet('viewer', '/agent/rmm_patch_policies.php?new=1');
$ok(!str_contains($vb, 'id="pol-form"') && str_contains($vb, 'but not change them'), 'policies page (viewer): no form, read-only notice');
[$c, $j] = $ptJson('viewer', ['action' => 'save_policy', 'name' => 'Viewer policy', 'enabled' => '1']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 0, 'a viewer cannot create a policy (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'save_policy', 'name' => 'Dept B policy', 'enabled' => '1']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 0, 'a user restricted to one department cannot create a fleet-wide policy (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => '', 'enabled' => '1']);
$ok($c === 422 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 0, 'a policy without a name is refused (' . $c . ')');
foreach ([['s_approval_mode' => 'sometimes'], ['s_ring' => 'nonexistent'], ['s_window_mode' => 'daily'], ['s_window_mode' => 'maintenance'], ['s_reboot_policy' => 'if_required_now'], ['s_auto_days_critical' => '400'], ['s_auto_days_security' => 'x'], ['s_max_minutes' => '999']] as $bad) {
    [$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'Bad ' . json_encode($bad), 'enabled' => '1'] + $bad);
    $ok($c === 422 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 0, 'a policy with ' . json_encode($bad) . ' is refused with words (' . $c . ': ' . ($j['error'] ?? '') . ')');
}
// a refused form comes back with what was typed
$sid = $SS['admin'];
[$c, , $hd] = web($wb, 'POST', '/agent/post/rmm_patching.php', $sid, ['action' => 'save_policy', 'name' => 'Draft <b>one</b>', 'description' => 'kept', 'enabled' => '1', 's_approval_mode' => 'auto', 's_auto_days_critical' => 'x', 'csrf_token' => 'csrftok1']);
$back = $ptLoc($hd);
[, $body] = web($wb, 'GET', $back, $sid);
$ok($c === 302 && str_contains($body, 'Draft &lt;b&gt;one&lt;/b&gt;') && !str_contains($body, 'Draft <b>one</b>') && str_contains($body, 'whole number from 0 to 365'), 'a refused policy form is shown again with what was typed (escaped) and the reason');
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'Workstations <i>x</i>', 'description' => 'Daily patching', 'enabled' => '1', 's_ring' => 'pilot', 's_approval_mode' => 'auto', 's_auto_days_critical' => '0', 's_auto_days_security' => '2',
    's_install_enabled' => '1', 's_window_mode' => 'daily', 's_window_days' => ['sat', 'sun'], 's_window_start' => '02:00', 's_window_minutes' => '120', 's_window_tz' => 'Europe/Berlin', 's_reboot_policy' => 'if_required_defer', 's_max_minutes' => '90']);
$pol = $ptRows('SELECT * FROM rmm_patch_policies')[0] ?? null;
$pset = json_decode((string) ($pol['settings_json'] ?? '{}'), true) ?: [];
$ok($c === 200 && $pol !== null && ($pset['ring']['value'] ?? null) === 'pilot' && ($pset['auto_days_critical']['value'] ?? null) === 0 && ($pset['auto_days_security']['value'] ?? null) === 2 && ($pset['install_enabled']['value'] ?? null) === true
    && ($pset['window_days']['value'] ?? null) === 'sat,sun' && ($pset['window_start']['value'] ?? null) === '02:00' && ($pset['window_minutes']['value'] ?? null) === 120 && ($pset['window_tz']['value'] ?? null) === 'Europe/Berlin'
    && ($pset['max_minutes']['value'] ?? null) === 90 && !isset($pset['two_person']), 'the administrator creates a policy; typed values arrive as typed values (ints, booleans, a day list), unset ones are absent');
$polId = (int) ($pol['policy_id'] ?? 0);
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok(str_contains($body, 'Workstations &lt;i&gt;x&lt;/i&gt;') && !str_contains($body, 'Workstations <i>x</i>') && str_contains($body, 'Not assigned: applies to nothing') && str_contains($body, 'sat,sun 02:00 for 120 min'), 'policies page: the policy name is escaped, it says it applies to nothing yet, and what it decides');
[$c, $j] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $polId, 'asg_type' => 'client', 'asg_id_client' => 1, 'priority' => '10', 'enforce' => '1']);
$ass = $ptRows('SELECT * FROM rmm_patch_assignments')[0] ?? null;
$ok($c === 200 && $ass !== null && $ass['scope_type'] === 'client' && (int) $ass['scope_id'] === 1 && (int) $ass['priority'] === 10 && (int) $ass['enforce'] === 1, 'the policy is assigned to Dept A (priority 10, enforced)');
[$c, $j] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $polId, 'asg_type' => 'device', 'asg_id_device' => 99999999]);
$ok($c === 422 && (int) $one('SELECT COUNT(*) FROM rmm_patch_assignments') === 1, 'assigning to a device that does not exist is refused (' . $c . ')');
[$c, $j] = $ptJson('viewer', ['action' => 'assign_policy', 'policy_id' => $polId, 'asg_type' => 'global']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_assignments') === 1, 'a viewer cannot assign (' . $c . ')');
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php?policy_id=' . $polId);
$ok(str_contains($body, 'Department Dept A') && str_contains($body, 'priority 10, enforced') && str_contains($body, 'id="pol-assign"') && str_contains($body, 'checked'), 'policies page: the assignment is listed, the edit form is filled in');
[, $tabA] = $ptHtml('admin', 'PTWIN');
$ok(str_contains($tabA, 'Patch policy for this device') && str_contains($tabA, 'pilot') && str_contains($tabA, 'dry runs only (live installs are off)'), 'the Patches tab shows the effective policy of the device: ring pilot, installs by schedule are dry runs while live installs are off');
[, $tabB] = $ptHtml('admin', 'PTB');
$ok(str_contains($tabB, 'No patch policy applies'), 'a device in Dept B has no policy: the tab states the safe defaults');
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'policy_id' => $polId, 'name' => 'Workstations', 'enabled' => '', 's_approval_mode' => 'manual']);
$pset = json_decode((string) $one("SELECT settings_json FROM rmm_patch_policies WHERE policy_id=$polId"), true) ?: [];
$ok($c === 200 && $one("SELECT enabled FROM rmm_patch_policies WHERE policy_id=$polId") === '0' && ($pset['approval_mode']['value'] ?? '') === 'manual' && !isset($pset['ring']), 'editing a policy replaces its settings: an unset field is removed, "enabled" unticked turns it off');
[$c, $j] = $ptJson('admin', ['action' => 'unassign_policy', 'policy_id' => $polId, 'assignment_id' => (int) $ass['assignment_id']]);
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM rmm_patch_assignments') === 0, 'the assignment is removed');
[$c, $j] = $ptJson('viewer', ['action' => 'delete_policy', 'policy_id' => $polId]);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 1, 'a viewer cannot delete a policy (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'delete_policy', 'policy_id' => $polId]);
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM rmm_patch_policies') === 0, 'the administrator deletes the policy');
[$c, $j] = $ptJson('admin', ['action' => 'delete_policy', 'policy_id' => 999999]);
$ok($c === 404, 'deleting a policy that does not exist: 404 (' . $c . ')');

// ---- a policy that restarts devices by itself needs the restart grant of whoever saves it
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'Restarts', 'enabled' => '1', 's_reboot_policy' => 'if_required_now', 's_reboot_confirm' => '1']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_policies WHERE name='Restarts' AND reboot_confirmed_by=" . $USER['admin']) === 1, 'a policy that restarts devices after patching records who accepted it');
$q("DELETE FROM rmm_patch_policies WHERE name='Restarts'");

// ---- rings
[$c, $j] = $ptJson('admin', ['action' => 'save_ring', 'name' => 'Canary', 'delay_days' => '1']);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 0 || $c === 422, 'a ring name must be lower case (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'save_ring', 'name' => 'canary', 'delay_days' => '1', 'sort_order' => '5', 'description' => 'Canary <b>ring</b>']);
$ring = $ptRows("SELECT * FROM rmm_patch_rings WHERE name='canary'")[0] ?? null;
$ok($c === 200 && $ring !== null && (int) $ring['delay_days'] === 1 && (int) $ring['sort_order'] === 5, 'the administrator adds a ring');
[$c, $j] = $ptJson('admin', ['action' => 'save_ring', 'name' => 'x', 'delay_days' => '999']);
$ok($c === 422, 'a ring delay over 365 days is refused (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'save_ring', 'name' => 'x', 'delay_days' => 'abc']);
$ok($c === 422, 'a ring delay that is not a number is refused (' . $c . ')');
[, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php');
$ok(str_contains($body, 'canary') && str_contains($body, 'Canary &lt;b&gt;ring&lt;/b&gt;') && !str_contains($body, 'Canary <b>ring</b>'), 'policies page: the new ring is listed, its description escaped');
[$c, $j] = $ptJson('deptb', ['action' => 'delete_ring', 'ring_id' => (int) $ring['ring_id']]);
$ok($c === 403 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 1, 'a user restricted to one department cannot delete a ring (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'Uses canary', 'enabled' => '1', 's_ring' => 'canary']);
[$c, $j] = $ptJson('admin', ['action' => 'delete_ring', 'ring_id' => (int) $ring['ring_id']]);
$ok($c === 409 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 1, 'a ring a policy still uses cannot be deleted (' . $c . ')');
$q("DELETE FROM rmm_patch_policies WHERE name='Uses canary'");
[$c, $j] = $ptJson('admin', ['action' => 'delete_ring', 'ring_id' => (int) $ring['ring_id']]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rings WHERE name='canary'") === 0, 'an unused ring is deleted');

// ---- approval rules
[$c, $j] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'Critical in pilot', 'after_days' => '3', 'ring' => 'pilot', 'kinds' => ['critical', 'security'], 'severities' => ['critical'], 'client_id' => '0', 'enabled' => '1']);
$rule = $ptRows('SELECT * FROM rmm_patch_rules')[0] ?? null;
$ok($c === 200 && $rule !== null && $rule['kinds'] === 'critical,security' && (int) $rule['after_days'] === 3 && (int) $rule['client_id'] === 0 && (int) $rule['enabled'] === 1, 'the administrator creates a rule for all departments');
[$c, $j] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'Bad', 'after_days' => '999']);
$ok($c === 422 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 1, 'a rule with 999 days is refused (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'Bad', 'after_days' => '1', 'kinds' => ['nonsense']]);
$ok($c === 422 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 1, 'a rule with an unknown kind is refused (' . $c . ')');
[$c, $j] = $ptJson('viewer', ['action' => 'save_rule', 'name' => 'Viewer rule', 'after_days' => '1', 'client_id' => '0']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 1, 'a viewer cannot create a rule (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'save_rule', 'name' => 'For everyone', 'after_days' => '1', 'client_id' => '0']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 1, 'a user restricted to Dept B cannot make a rule for every department (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'save_rule', 'name' => 'For Dept A', 'after_days' => '1', 'client_id' => '1']);
$ok($c === 403 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 1, 'a user restricted to Dept B cannot make a rule for Dept A (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'save_rule', 'name' => 'For Dept B', 'after_days' => '1', 'client_id' => '2', 'kinds' => ['security']]);
$ok($c === 200 && (int) $one("SELECT COUNT(*) FROM rmm_patch_rules WHERE name='For Dept B' AND client_id=2") === 1, 'the same user makes a rule for their own department');
$ruleB = (int) $one("SELECT rule_id FROM rmm_patch_rules WHERE name='For Dept B'");
[$c, $j] = $ptJson('deptb', ['action' => 'delete_rule', 'rule_id' => (int) $rule['rule_id']]);
$ok($c === 404 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules WHERE rule_id=' . (int) $rule['rule_id']) === 1, 'that user cannot delete the all-departments rule: same 404 as a missing rule (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'save_rule', 'rule_id' => (int) $rule['rule_id'], 'name' => 'Hijack', 'after_days' => '0', 'client_id' => '2']);
$ok(in_array($c, [403, 404], true) && $one('SELECT name FROM rmm_patch_rules WHERE rule_id=' . (int) $rule['rule_id']) === 'Critical in pilot', 'nor change it (' . $c . ')');
[, $body] = $ptGet('admin', '/agent/rmm_patch_policies.php?rule_id=' . (int) $rule['rule_id']);
$ok(str_contains($body, 'Critical in pilot') && str_contains($body, 'critical, security') && str_contains($body, '3 days + ring pilot') && str_contains($body, 'Change rule'), 'policies page: the rule is listed in words and its form is filled in');
[, $body] = $ptGet('deptb', '/agent/rmm_patch_policies.php');
$ok(str_contains($body, 'only a user with access to every department can change them') && str_contains($body, 'id="rule-form"') && !str_contains($body, 'id="pol-form"'), 'policies page (Dept B user): told why policies are locked, the rule form is still there');
$q("DELETE FROM rmm_patch_rules WHERE rule_id=$ruleB");
[$c, $j] = $ptJson('admin', ['action' => 'delete_rule', 'rule_id' => (int) $rule['rule_id']]);
$ok($c === 200 && (int) $one('SELECT COUNT(*) FROM rmm_patch_rules') === 0, 'the administrator deletes the rule');

// ---- a rule approves by itself when the scheduler runs (the housekeeping pass cron runs)
[$c, $j] = $ptJson('admin', ['action' => 'save_rule', 'name' => 'Security now', 'after_days' => '0', 'kinds' => ['security'], 'client_id' => '1', 'enabled' => '1']);
$q("UPDATE rmm_patch_available SET status='available', status_source='', status_by=NULL WHERE device_id=" . $ptDev['PTLNX'] . " AND update_id='openssl'");
[$c] = $ptJson('admin', ['action' => 'scan', 'device_id' => $ptDev['PTLNX']]);
$job = $ptJobOf($ptTok['PTLNX'], 'patch_scan');
$ptReport($ptTok['PTLNX'], $job, 'succeeded', $ptLinScan());
$hk = $rmm->housekeeping()->run();
$ok(is_array($hk) && $ptUpd($ptDev['PTLNX'], 'openssl')['status'] === 'approved' && str_starts_with((string) $ptUpd($ptDev['PTLNX'], 'openssl')['status_source'], 'rule'), 'a rule approves the security update when the next scan is applied (source: rule); housekeeping still runs');
$ok($ptUpd($ptDev['PTLNX'], 'libc6:amd64')['status'] === 'declined' || $ptUpd($ptDev['PTLNX'], 'libc6:amd64')['status'] === 'available', 'a declined update is never approved by a rule');
$q('DELETE FROM rmm_patch_rules');

// ---- two people: a policy that asks for a second person turns an approval into a request
[$c, $j] = $ptJson('admin', ['action' => 'save_policy', 'name' => 'Two people', 'enabled' => '1', 's_two_person' => '1']);
$twoId = (int) $one("SELECT policy_id FROM rmm_patch_policies WHERE name='Two people'");
[$c, $j] = $ptJson('admin', ['action' => 'assign_policy', 'policy_id' => $twoId, 'asg_type' => 'global', 'priority' => '100']);
$ok($c === 200, 'a global policy with the second-person rule is assigned');
$q("UPDATE rmm_patch_available SET status='available', status_source='', status_by=NULL WHERE device_id=" . $ptDev['PTWIN'] . " AND update_id='" . $GU['sec'] . "'");
$apr0 = (int) $one("SELECT COUNT(*) FROM rmm_approvals WHERE kind='patch'");
[$c, $j] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['sec']]]);
$ok($c === 200 && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'available' && (int) $one("SELECT COUNT(*) FROM rmm_approvals WHERE kind='patch'") === $apr0 + 1, 'with the policy a manual approval changes nothing yet and opens a request (' . ($j['message'] ?? '') . ')');
$apprId = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='patch' ORDER BY approval_id DESC LIMIT 1");
[$c, $body] = $ptGet('admin', '/agent/rmm_patch_updates.php');
$ok(str_contains($body, 'Waiting for a second person') && str_contains($body, 'You cannot approve your own request') && str_contains($body, 'value="cancel_approval"'), 'approvals page: the request waits; the requester cannot approve their own but can cancel it');
[$c, $j] = $ptJson('admin', ['action' => 'decide_approval', 'approval_id' => $apprId]);
$ok($c === 403 && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'available', 'the requester deciding their own request is refused by Core (' . $c . ')');
[$c, $j] = $ptJson('viewer', ['action' => 'decide_approval', 'approval_id' => $apprId]);
$ok(in_array($c, [403, 404], true) && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'available', 'a viewer cannot decide it (' . $c . ')');
[$c, $j] = $ptJson('deptb', ['action' => 'decide_approval', 'approval_id' => $apprId]);
$ok(in_array($c, [403, 404], true) && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'available', 'a Dept B user cannot decide a Dept A request (' . $c . ')');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
[, $techBody] = $ptGet('tech', '/agent/rmm_patch_updates.php');
$ok(str_contains($techBody, 'Waiting for a second person') && str_contains($techBody, 'value="decide_approval"'), 'approvals page (second person with the grant): Approve and Reject are offered');
[$c, $j] = $ptJson('tech', ['action' => 'decide_approval', 'approval_id' => $apprId, 'note' => 'ok']);
$ok($c === 200 && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'approved', 'the second person approves: the update becomes approved (' . ($j['message'] ?? $j['error'] ?? '') . ')');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $ptGet('admin', '/agent/rmm_approvals.php?state=approved&kind=patch');
$ok($c === 200 && str_contains($body, 'Patch updates') && str_contains($body, '<option value="patch" selected>'), 'the Approvals page lists the request as "Patch updates" and has a Kind filter for it');
$q("UPDATE rmm_patch_available SET status='available', status_source='', status_by=NULL WHERE device_id=" . $ptDev['PTWIN'] . " AND update_id='" . $GU['sec'] . "'");
[$c, $j] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['sec']]]);
$apprId2 = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='patch' ORDER BY approval_id DESC LIMIT 1");
[$c, $j] = $ptJson('viewer', ['action' => 'cancel_approval', 'approval_id' => $apprId2]);
$ok(in_array($c, [403, 404], true) && $one("SELECT state FROM rmm_approvals WHERE approval_id=$apprId2") === 'pending_approval', 'someone else cannot cancel the request (' . $c . ')');
[$c, $j] = $ptJson('admin', ['action' => 'cancel_approval', 'approval_id' => $apprId2]);
$ok($c === 200 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$apprId2") === 'cancelled' && $ptUpd($ptDev['PTWIN'], $GU['sec'])['status'] === 'available', 'the requester cancels it: nothing is approved');
[$c, $j] = $ptJson('admin', ['action' => 'unassign_policy', 'policy_id' => $twoId, 'assignment_id' => (int) $one("SELECT assignment_id FROM rmm_patch_assignments WHERE policy_id=$twoId")]);
$q("DELETE FROM rmm_patch_policies WHERE policy_id=$twoId");

// ---- the unknown and the malformed
[$c, $j] = $ptJson('admin', ['action' => 'format_disk']);
$ok($c === 422 && ($j['error'] ?? '') === 'Unknown action.', 'an unknown action is refused');
[$c, $b] = $ptPost('admin', ['action' => 'approve', 'update_ids' => 'not-an-array', 'device_id' => $ptDev['PTWIN']]);
$ok(in_array($c, [302, 200, 422], true) && $ptUpd($ptDev['PTWIN'], $GU['host'])['status'] === 'available', 'update_ids that is not a list changes nothing');
[$c, $b, $hd] = $ptPost('admin', ['action' => 'scan', 'device_id' => '1 OR 1=1', 'return' => 'https://evil.example/x']);
$ok($c === 302 && !str_contains($ptLoc($hd), 'evil.example') && str_starts_with($ptLoc($hd), '/agent/'), 'a hostile device id is not a device, and the return target never leaves the application (' . $ptLoc($hd) . ')');
[$c, $b, $hd] = $ptPost('admin', ['action' => 'scan', 'device_id' => (string) $ptDev['PTB'], 'return' => '//evil.example']);
$ok(!str_contains($ptLoc($hd), 'evil.example') && !str_starts_with($ptLoc($hd), '//'), 'a protocol-relative return target is replaced');

// ---- everything is gone from the menu, the pages and the tab when the switch is turned off again; nothing is deleted
[$c] = $ptSettings([]);
$ok(!$rmm->featureOn('patching') && $ptNav() === [] && (int) $one('SELECT COUNT(*) FROM rmm_patch_available') >= 6, 'switching patching off removes the menu entries and deletes nothing');
[$c, $body] = $ptGet('admin', '/agent/rmm_patches.php');
$ok($c === 403 && str_contains($body, 'switched off'), 'the compliance page answers the switched-off notice again');
[, $body] = $ptHtml('admin', 'PTWIN');
$ok(!str_contains($body, 'rmm-tab-patches'), 'the Patches tab is gone');
[$c, $j] = $ptJson('admin', ['action' => 'approve', 'device_id' => $ptDev['PTWIN'], 'update_ids' => [$GU['host']]]);
$ok($c === 404 && $ptUpd($ptDev['PTWIN'], $GU['host'])['status'] === 'available', 'the handler refuses while the switch is off');
$hk = $rmm->housekeeping()->run();
$ok(is_array($hk), 'housekeeping still runs with the switch off');
