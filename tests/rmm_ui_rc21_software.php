<?php
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 5, software deployment, RivetCore 1.0.0-rc.21): the `software_deploy` sub-switch and its Administration card (with the separate, acknowledged and
 * audited live-install switch), the three abilities in the role matrix, the deployment block of the Software tab, the Software catalog, Software assignments and Software report pages, the
 * CSV, the approvals page, and the form handler (agent/post/rmm_software_deploy.php). Shares that file's variables.
 *
 * Everything is driven through the product: devices enroll and check in through the real device endpoints, a detection is requested through the real handler (CSRF, forged sessions,
 * `php -S`), and the DEVICE is simulated through the real endpoints too: GET /api/v1/agent_jobs receives the signed software job, POST /api/v1/agent_jobs reports its result, which Core
 * applies. The `software_deploy` switch is OFF at the start (the default) and is put back OFF at the end; so is `software_live_install`.
 */

use RivetCore\Rmm\Authz\RmmAbility;

$swGet = fn (string $who, string $path) => web($wb, 'GET', $path, $SS[$who]);
$swPost = fn (string $who, array $f, array $headers = []) => web($wb, 'POST', '/agent/post/rmm_software_deploy.php', $SS[$who], $f + ['csrf_token' => 'csrftok1'], $headers);
$swJson = function (string $who, array $f) use ($swPost): array {
    [$c, $b] = $swPost($who, $f, ['Accept: application/json']);

    return [$c, json_decode($b, true) ?: [], $b];
};
$swLoc = fn (string $hdr) => preg_match('/^location:\s*(\S+)/mi', $hdr, $mm) === 1 ? $mm[1] : '';
$swAudit = fn (string $action) => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "'");
$swNav = fn (?int $uid = null) => array_values(array_filter(array_column(rivetRmmAutoNav($uid), 'href'), fn ($h) => str_contains($h, 'rmm_software')));
$swQ = function () use ($db): int { return (int) $db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1]; };
$swSettings = fn (array $f, string $who = 'admin') => $adm1($f + ['save_software_settings' => 1], $who);
$swFeatures = fn () => json_decode((string) $db->query('SELECT features_json FROM endpoint_agent_settings WHERE id=1')->fetch_row()[0], true) ?: [];
$swNoInline = fn (string $html) => preg_match('/\son[a-z]+\s*=\s*["\']|javascript:/i', $html) !== 1;
$swCount = fn (string $table, string $where = '1') => (int) $one("SELECT COUNT(*) FROM $table WHERE $where");

// ---- the starting state: module on, software_deploy OFF (the default)
$ok($rmm->enabled() && !$rmm->featureOn('software_deploy') && !rivetRmmFeatureOn('software_deploy'), 'software: the module is on and the software_deploy switch is off at the start');
$ok($swNav() === [] && $swNav($USER['admin']) === [], 'menu: no software entries while the switch is off');
$swOver = (function () use ($swQ) { $x = $swQ(); return $swQ() - $x; })();
$a = $swQ(); $swNav(); $b = $swQ();
$ok($b - $a === $swOver, 'menu: the check costs no statement while the switch is off (' . ($b - $a) . ' vs ' . $swOver . ')');
$swNavBase = count(rivetRmmAutoNav());
foreach (['/agent/rmm_software.php', '/agent/rmm_software_assignments.php', '/agent/rmm_software_report.php'] as $path) {
    [$c, $body] = $swGet('admin', $path);
    $ok($c === 403 && str_contains($body, 'switched off') && str_contains($body, 'Software deployment'), "$path: 403 and the switched-off notice while the software_deploy switch is off ($c)");
}
[$c] = $swGet('admin', '/agent/rmm_software_report_csv.php');
$ok($c === 404, 'the CSV answers 404 while the switch is off (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'detect', 'device_id' => 1]);
$ok($c === 404 && ($j['success'] ?? null) === false && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'handler (JSON): the switched-off refusal is a 404 with a message');
$vm = $panel('WIN1');
$ok(($vm['features']['software_deploy'] ?? null) === false && !str_contains(rivetRmmUiTabs($vm, '', '', [], 'tok'), 'id="rmm-deployment"'), 'the asset page has no deployment block while the switch is off');
$settingsPage = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin'])[1];
$ok(str_contains($settingsPage, 'id="software-deployment"') && str_contains($settingsPage, 'name="feature_software_deploy"') && str_contains($settingsPage, 'once a day (a short read-only check)') && !preg_match('/name="feature_software_deploy"[^>]*checked/', $settingsPage), 'Administration: the Software deployment card, switched off, with Core\'s cost text');
$ok(str_contains($settingsPage, 'name="software_live_install"') && str_contains($settingsPage, 'name="accept_software_live"') && str_contains($settingsPage, 'have never been run on a real machine') && $swNoInline($settingsPage), 'Administration: live installs are a separate switch with an acknowledgement and the Windows warning; no inline handlers');

// ---- the role matrix for the three abilities
rivetRmmForgetAccess();
$auth = $rmm->authorizer();
$matrix = [   // user => [view, manage, deploy]
    'admin' => [true, true, true], 'tech' => [true, true, true], 'rebootonly' => [true, true, false], 'viewer' => [true, false, false], 'remoteonly' => [true, false, false],
    'moduleonly' => [true, false, false], 'normo' => [false, false, false], 'deptb' => [true, true, true],
];
foreach ($matrix as $who => $exp) {
    $got = [$auth->allowed($USER[$who], RmmAbility::SOFTWARE_VIEW, 0), $auth->allowed($USER[$who], RmmAbility::SOFTWARE_MANAGE, 0), $auth->allowed($USER[$who], RmmAbility::SOFTWARE_DEPLOY, 0)];
    $ok($got === $exp, "$who: software [view, manage, deploy] = " . json_encode($got));
}
$ok(!$auth->allowed(999999, RmmAbility::SOFTWARE_VIEW, 0) && !$auth->allowed(0, RmmAbility::SOFTWARE_DEPLOY, 0), 'software abilities: an unknown user and user 0 are denied');
$sp = rivetRmmSwPerm($db, $USER['rebootonly']);
$ok($sp['view'] && $sp['manage'] && !$sp['deploy'] && !$sp['admin'], 'the page permission helper agrees: a level 2 script technician manages the catalog and dry-runs but cannot deploy');
$sp = rivetRmmSwPerm($db, $USER['moduleonly']);
$ok($sp['view'] && !$sp['manage'] && !$sp['deploy'], 'a module-only login never gets manage or deploy');

// ---- Administration: the card is admin-only; the switch, the limits, the live-install acknowledgement and the audit
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_detect_interval_s' => 7200], 'tech');
$ok(!$rmm->featureOn('software_deploy') && $rmm->settings()->limits()['software_detect_interval_s'] === 86400, 'a technician cannot save the software settings (' . $c . ')');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_detect_interval_s' => 100]);
$ok(!$rmm->featureOn('software_deploy') && $rmm->settings()->limits()['software_detect_interval_s'] === 86400, 'an out-of-range limit is refused by Core and nothing is saved (' . $c . ')');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_detect_interval_s' => 7200, 'software_max_installs' => 30, 'software_max_installs_per_client' => 6, 'software_deploy_history_days' => 400, 'software_batch' => 120]);
$lim = $rmm->settings()->limits();
$ok(in_array($c, [200, 302], true) && $rmm->featureOn('software_deploy') && rivetRmmFeatureOn('software_deploy') && $lim['software_detect_interval_s'] === 7200 && $lim['software_max_installs'] === 30 && $lim['software_max_installs_per_client'] === 6
    && $lim['software_deploy_history_days'] === 400 && $lim['software_batch'] === 120 && $lim['software_live_install'] === 0, 'administrator: the switch and the five limits are saved; live installs stay off');
$ok(($swFeatures()['monitoring'] ?? null) === true && ($swFeatures()['software_deploy'] ?? null) === true, 'saving the switch keeps the other effective features');
$liveAudit0 = $swAudit('Settings Changed');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_live_install' => '1']);
$ok($rmm->settings()->limits()['software_live_install'] === 0 && $swAudit('Settings Changed') === $liveAudit0, 'live installs cannot be switched on without the acknowledgement (nothing saved, nothing audited)');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_live_install' => '1', 'accept_software_live' => '1']);
$ok($rmm->settings()->limits()['software_live_install'] === 1 && $swAudit('Settings Changed') > $liveAudit0 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Settings Changed' AND log_description LIKE '%software deployment live installs ON%'") === 1, 'with the acknowledgement live installs switch on and the change is audited by name');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_detect_interval_s' => 7200]);   // live box not sent = off
$ok($rmm->settings()->limits()['software_live_install'] === 0 && $rmm->featureOn('software_deploy'), 'leaving the live-install box unticked switches live installs off again');
[$c] = $swSettings(['software_live_install' => '1', 'accept_software_live' => '1']);   // the main switch not ticked
$ok($rmm->settings()->limits()['software_live_install'] === 0, 'live installs cannot be switched on while software deployment itself is switched off in the same form');
[$c] = $swSettings(['feature_software_deploy' => '1']);
$ok($rmm->featureOn('software_deploy'), 'the switch is on for the rest of this fragment');
$ok($swNav() === ['/agent/rmm_software.php', '/agent/rmm_software_assignments.php', '/agent/rmm_software_report.php'] && count(rivetRmmAutoNav()) === $swNavBase + 3, 'menu: the three software entries appear with the switch on (' . count(rivetRmmAutoNav()) . ' entries)');
$ok($swNav($USER['viewer']) === $swNav() && $swNav($USER['normo']) === [], 'menu: a viewer sees the entries (read only), a user without the RMM role none');
$settingsPage = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin'])[1];
$ok(preg_match('/name="feature_software_deploy"[^>]*checked/', $settingsPage) === 1 && str_contains($settingsPage, 'value="7200"') && str_contains($settingsPage, 'Open the software catalog'), 'Administration: the card now shows the switch on, the saved values and a link');

// ---- devices of this fragment: Linux and Windows in Dept A, an old agent, Linux in Dept B; they announce the software capabilities
$swDev = $swTok = $swAsset = [];
$swCaps = ['job:collect', 'job:software_detect', 'job:software_install', 'software:detect', 'software:install'];
foreach (['SWLNX' => [1, 'A', 'linux', $swCaps], 'SWWIN' => [1, 'A', 'windows', $swCaps], 'SWOLD' => [1, 'A', 'windows', ['job:collect']], 'SWB' => [2, 'B', 'linux', $swCaps]] as $n => [$client, $tk, $os, $caps]) {
    $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $swAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $swDev[$n] = (int) ($j['device_id'] ?? 0);
    $swTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $swDev[$n] > 0, "$n enrolled through the real endpoint");
    [$c] = ea_checkin($swTok[$n], ['capabilities' => $caps]);
    $ok($c === 200, "$n checked in and announced " . implode(',', array_filter($caps, fn ($x) => str_starts_with($x, 'software:'))));
}
$A += $swAsset;
$swHtml = fn (string $who, string $n, string $qs = '') => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $A[$n] . $qs, $SS[$who]);
$swOffered = function (string $tok): array { [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok); return $c === 200 ? ($j['jobs'] ?? []) : []; };
$swJobOf = function (string $tok, string $type) use ($swOffered): ?array { foreach ($swOffered($tok) as $j) { if ($j['type'] === $type) { return $j; } } return null; };
$swReport = fn (string $tok, array $job, string $state, string $out, int $exit = 0) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $out,
    'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'finished_at' => gmdate('Y-m-d\TH:i:s\Z')]);
$swParams = fn (?array $job): array => $job === null ? [] : (json_decode(json_encode($job), true)['params'] ?? []);
$hostile = '<script>alert(1)</script> "dq" \'sq\' & </td></tr><img src=x onerror=alert(2)>';
$noRaw = fn (string $body) => !preg_match('/<img src=x|<script>alert\(1\)<\/script>|<b onmouse/', $body);
$sha = str_repeat('ab', 32);

// ---- before any package: the block says so; the old agent is explained
[$c, $body] = $swHtml('admin', 'SWLNX');
$ok($c === 200 && str_contains($body, 'id="rmm-deployment"') && str_contains($body, 'No package is assigned to this device') && str_contains($body, 'id="rmm-tab-software"'), 'SWLNX: the Software tab carries the deployment block, empty before any assignment');
[$c, $body] = $swHtml('admin', 'SWOLD');
$ok(str_contains($body, 'has not announced that it can install or remove software') && str_contains($body, 'software:install'), 'SWOLD (an older agent): the block explains why nothing happens');
[$c, $nb] = $swHtml('deptb', 'SWLNX');
$ok(!str_contains($nb, 'id="rmm-deployment"') && !str_contains($nb, 'No package is assigned'), 'SWLNX (Dept B user): nothing of a Dept A device');

// ---- the allowed installer hosts: administrators only, exact names, no IP / URL / wildcard
foreach (['tech', 'viewer', 'deptb'] as $who) {
    [$c, $j] = $swJson($who, ['action' => 'add_host', 'host' => 'downloads.example.com']);
    $ok($c === 403 && $swCount('rmm_software_hosts') === 0, "$who cannot allow an installer host ($c)");
}
foreach (['10.0.0.1', 'https://downloads.example.com', 'downloads.example.com/x', '*.example.com', 'localhost', "dl.exa\nmple.com", 'a b.example.com', 'ex ample.com:8443', ''] as $bad) {
    [$c, $j] = $swJson('admin', ['action' => 'add_host', 'host' => $bad]);
    $ok(in_array($c, [422], true) && $swCount('rmm_software_hosts') === 0, 'host ' . json_encode($bad) . ' is refused (' . $c . ')');
}
[$c, $j] = $swJson('admin', ['action' => 'add_host', 'host' => 'Downloads.Example.COM', 'note' => 'vendor <b>site</b>']);
$ok($c === 200 && $one('SELECT host FROM rmm_software_hosts') === 'downloads.example.com' && $swAudit('Software Host Allowed') === 1, 'the administrator allows a host (stored in lower case, audited)');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php');
$ok($c === 200 && str_contains($body, 'downloads.example.com') && str_contains($body, '&lt;b&gt;site&lt;/b&gt;') && !str_contains($body, 'vendor <b>site</b>') && str_contains($body, 'id="hosts"') && $swNoInline($body), 'catalog page: the host list, the note escaped');
[, $vb] = $swGet('viewer', '/agent/rmm_software.php');
$ok(str_contains($vb, 'downloads.example.com') && !str_contains($vb, 'value="add_host"') && !str_contains($vb, 'name="host"') && str_contains($vb, 'Only an administrator'), 'catalog page (viewer): the host list is read only');

// ---- the catalog: packages
[$c, $j] = $swJson('viewer', ['action' => 'save_package', 'package_id' => '0', 'name' => 'Viewer package']);
$ok($c === 403 && $swCount('rmm_packages') === 0, 'a viewer cannot add a package (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'save_package', 'package_id' => '0', 'name' => 'Dept B package']);
$ok($c === 403 && $swCount('rmm_packages') === 0, 'a user restricted to one department cannot change the fleet-wide catalog (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'save_package', 'package_id' => '0', 'name' => '']);
$ok($c === 422 && $swCount('rmm_packages') === 0, 'a package without a name is refused (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => '0', 'name' => 'SWP htop', 'publisher' => 'Debian', 'description' => 'a process viewer', 'enabled' => '1', 'two_person_shown' => '1']);
$pHtop = (int) $one("SELECT package_id FROM rmm_packages WHERE name='SWP htop'");
$ok($c === 200 && $pHtop > 0 && $swAudit('Software Package Created') === 1, 'a technician creates a package (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => '0', 'name' => 'SWP htop', 'enabled' => '1']);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'exists'), 'a second package with the same name is refused');
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => '0', 'name' => 'SWP secure', 'enabled' => '1', 'two_person_shown' => '1', 'two_person' => '1']);
$pSec = (int) $one("SELECT package_id FROM rmm_packages WHERE name='SWP secure'");
$ok($c === 200 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pSec") === 1, 'a catalog manager can switch the second-person rule ON');
// lowering needs rmm.admin: the form of a non-admin does not even offer the box; a forged post is refused by Core
[$c, $body] = $swGet('tech', '/agent/rmm_software.php?package_id=' . $pSec);
$ok($c === 200 && preg_match('/id="pk_two"[^>]*checked[^>]*disabled|id="pk_two"[^>]*disabled[^>]*checked/', $body) === 1 && !str_contains($body, 'name="two_person_shown"') && str_contains($body, 'Only an administrator can switch this off'), 'package form (technician): the second-person box is shown ticked and locked, with the reason');
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => (string) $pSec, 'name' => 'SWP secure', 'enabled' => '1', 'two_person_shown' => '1']);
$ok($c === 403 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pSec") === 1 && $swAudit('Software Denied') >= 1, 'a forged post that lowers two_person as a technician is refused by Core and audited (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => (string) $pSec, 'name' => 'SWP secure', 'description' => 'edited by a technician', 'enabled' => '1']);
$ok($c === 200 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pSec") === 1 && $one("SELECT description FROM rmm_packages WHERE package_id=$pSec") === 'edited by a technician', 'a technician editing other fields leaves the second-person rule alone');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php?package_id=' . $pSec);
$ok(str_contains($body, 'name="two_person_shown"') && !preg_match('/id="pk_two"[^>]*disabled/', $body), 'package form (administrator): the box can be changed');

// ---- the catalog: versions (hostile input, immutability, retire)
$vers0 = $swCount('rmm_package_versions');
$vPost = fn (int $pid, array $f, string $who = 'admin') => $swJson($who, ['action' => 'add_version', 'package_id' => (string) $pid] + $f);
$refused = [
    'option as package name' => ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => '--allow-unauthenticated'],
    'command in the name' => ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => 'htop; rm -rf /'],
    'substitution' => ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => '$(id)'],
    'upper case' => ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => 'Htop'],
    'newline in the name' => ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => "ht\nop"],
    'newline in the version' => ['fmt' => 'apt', 'version' => "1.0\nx", 'pkg_name' => 'htop'],
    'pin with a space' => ['fmt' => 'apt', 'version' => '1.0 beta', 'pkg_name' => 'htop', 'pin_version' => '1'],
    'no package name' => ['fmt' => 'apt', 'version' => '1.0'],
    'unknown format' => ['fmt' => 'deb', 'version' => '1.0', 'pkg_name' => 'htop'],
    'http address' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'http://downloads.example.com/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'IP address' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://10.0.0.1/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'query string' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.msi?token=1', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'user information' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://u:p@downloads.example.com/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'host not on the list' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://evil.example.net/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'a lookalike host' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com.evil.net/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'a short hash' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.msi', 'sha256' => 'abc123', 'size_bytes' => '100', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'no size' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.msi', 'sha256' => $sha, 'size_bytes' => '', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'a size over 1 GiB' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.msi', 'sha256' => $sha, 'size_bytes' => '2000000000', 'product_code' => '{12345678-ABCD-1234-ABCD-1234567890AB}'],
    'an msi without its product code' => ['fmt' => 'msi', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.msi', 'sha256' => $sha, 'size_bytes' => '100', 'product_code' => ''],
    'quotes in exe arguments' => ['fmt' => 'exe', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.exe', 'sha256' => $sha, 'size_bytes' => '100', 'silent_args' => '/S "&& calc"', 'detect_kind' => 'uninstall_name', 'detect_value' => 'App'],
    'a shell metacharacter in exe arguments' => ['fmt' => 'exe', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.exe', 'sha256' => $sha, 'size_bytes' => '100', 'silent_args' => '/S | calc', 'detect_kind' => 'uninstall_name', 'detect_value' => 'App'],
    'an exe without a detection rule' => ['fmt' => 'exe', 'version' => '1.0', 'url' => 'https://downloads.example.com/a.exe', 'sha256' => $sha, 'size_bytes' => '100', 'silent_args' => '/S', 'detect_kind' => 'uninstall_name', 'detect_value' => ''],
];
foreach ($refused as $label => $f) {
    [$c, $j] = $vPost($pHtop, $f);
    $ok($c === 422 && $swCount('rmm_package_versions') === $vers0, "a version with $label is refused with words and nothing is stored (" . $c . ': ' . mb_substr((string) ($j['error'] ?? ''), 0, 70) . ')');
}
[$c, $j] = $vPost($pHtop, ['fmt' => 'apt', 'version' => '3.3.0', 'pkg_name' => 'htop'], 'viewer');
$ok($c === 403 && $swCount('rmm_package_versions') === $vers0, 'a viewer cannot add a version (' . $c . ')');
[$c, $j] = $vPost($pHtop, ['fmt' => 'apt', 'version' => '3.3.0', 'pkg_name' => 'htop'], 'deptb');
$ok($c === 403 && $swCount('rmm_package_versions') === $vers0, 'a Dept B user cannot add a version (' . $c . ')');
[$c, $j] = $vPost($pHtop, ['fmt' => 'apt', 'version' => '3.3.0', 'pkg_name' => 'htop'], 'tech');
$vHtop = (int) $one("SELECT version_id FROM rmm_package_versions WHERE package_id=$pHtop");
$ok($c === 200 && $vHtop > 0 && $one("SELECT detect_kind FROM rmm_package_versions WHERE version_id=$vHtop") === 'package' && $swAudit('Software Version Added') === 1, 'a technician adds an apt version; the detection rule is derived from the package name');
[$c, $j] = $vPost($pHtop, ['fmt' => 'apt', 'version' => '3.3.0', 'pkg_name' => 'htop-evil'], 'tech');
$ok($c === 422 && $one("SELECT pkg_name FROM rmm_package_versions WHERE version_id=$vHtop") === 'htop', 'a version is immutable: adding the same version again with other content is refused and changes nothing');
[$c, $j] = $vPost($pSec, ['fmt' => 'apt', 'version' => '1.0', 'pkg_name' => 'secure-tool'], 'tech');
$vSec = (int) $one("SELECT version_id FROM rmm_package_versions WHERE package_id=$pSec");
$ok($c === 200 && $vSec > 0, 'the two-person package gets its apt version');
// a Windows package with the real pin
[$c, $j] = $swJson('tech', ['action' => 'save_package', 'package_id' => '0', 'name' => 'SWP Agent', 'publisher' => $hostile, 'description' => $hostile, 'enabled' => '1', 'two_person_shown' => '1']);
$pWin = (int) $one("SELECT package_id FROM rmm_packages WHERE name='SWP Agent'");
[$c, $j] = $vPost($pWin, ['fmt' => 'msi', 'version' => '2.4.1', 'url' => 'https://Downloads.Example.com/app-2.4.1.msi', 'sha256' => strtoupper($sha), 'size_bytes' => '1048576', 'product_code' => '{12345678-abcd-1234-abcd-1234567890ab}', 'detect_min' => '2.4.1'], 'tech');
$vWin = (int) $one("SELECT version_id FROM rmm_package_versions WHERE package_id=$pWin");
$vrow = $rows("SELECT * FROM rmm_package_versions WHERE version_id=$vWin")[0] ?? [];
$ok($c === 200 && $vWin > 0 && $vrow['url'] === 'https://downloads.example.com/app-2.4.1.msi' && $vrow['sha256'] === $sha && $vrow['product_code'] === '{12345678-ABCD-1234-ABCD-1234567890AB}' && (int) $vrow['size_bytes'] === 1048576, 'an msi version is stored normalised: host in lower case, hash lower case, product code upper case');
// the same Linux package for yum-style names: dnf
[$c, $j] = $vPost($pHtop, ['fmt' => 'dnf', 'version' => '3.3.0', 'pkg_name' => 'htop'], 'tech');
$ok($c === 200, 'a dnf version of the same package is accepted (one package, several formats)');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php?package_id=' . $pHtop);
$ok($c === 200 && str_contains($body, 'id="versions"') && str_contains($body, 'never edited') && str_contains($body, 'SWP htop') && str_contains($body, 'data-rmm-when="#vf_fmt=msi,exe"') && !preg_match('/<form[^>]*>[^<]*(?:<[^>]+>[^<]*)*name="action" value="edit_version"/', $body), 'catalog page: the versions table, no edit control for a version, the format-dependent fields');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php?package_id=' . $pWin);
$ok(str_contains($body, 'downloads.example.com/app-2.4.1.msi') && str_contains($body, substr($sha, 0, 16)) && str_contains($body, '1 MB') && !str_contains($body, '<script>alert(1)') && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && $noRaw($body), 'catalog page: the address, a hash prefix and the size are shown; the hostile publisher and description are escaped');
[, $vb] = $swGet('viewer', '/agent/rmm_software.php?package_id=' . $pWin);
$ok(str_contains($vb, 'SWP Agent') && !str_contains($vb, 'name="action" value="add_version"') && !str_contains($vb, 'id="ver-form"') && str_contains($vb, 'Your role can read the catalog but not change it'), 'catalog page (viewer): read only');
[$c, $j] = $swJson('tech', ['action' => 'retire_version', 'package_id' => (string) $pWin, 'version_id' => (string) $vWin, 'retired' => '1']);
$ok($c === 200 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$vWin") === 1 && $swAudit('Software Version Retired') === 1, 'a technician retires a version (audited)');
[$c, $j] = $swJson('viewer', ['action' => 'retire_version', 'package_id' => (string) $pWin, 'version_id' => (string) $vWin, 'retired' => '0']);
$ok($c === 403 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$vWin") === 1, 'a viewer cannot restore it (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'retire_version', 'package_id' => (string) $pWin, 'version_id' => (string) $vWin, 'retired' => '0']);
$ok($c === 200 && (int) $one("SELECT retired FROM rmm_package_versions WHERE version_id=$vWin") === 0, 'and a technician restores it');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php');
$ok($c === 200 && str_contains($body, 'SWP htop') && str_contains($body, 'Second person') && str_contains($body, 'id="packages"') && $swNoInline($body), 'catalog page: the package list with the second-person flag');
[$c, $body] = $swGet('admin', '/agent/rmm_software.php?package_id=999999&new=%22%3E%3Cx');
$ok($c === 200 && str_contains($body, 'That package does not exist') && !str_contains($body, '"><x'), 'catalog page: an unknown package id says so and echoes nothing');

// ---- assignments: the page, abilities, department scope, the two-person rule
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php');
$ok($c === 200 && str_contains($body, 'Nothing is assigned') && str_contains($body, 'Rings spread a rollout') && str_contains($body, 'pilot') && $swNoInline($body), 'assignments page: empty, with the ring explanation and the seeded rings');
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php?package_id=' . $pHtop);
$ok(str_contains($body, 'name="asg_type"') && str_contains($body, 'name="version_id"') && str_contains($body, 'Newest version that fits') && str_contains($body, '3.3.0'), 'assignments page: after choosing a package the form offers its versions and the scope picker');
[$c, $j] = $swJson('viewer', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'global']);
$ok($c === 403 && $swCount('rmm_package_assignments') === 0, 'a viewer cannot assign (' . $c . ')');
[$c, $j] = $swJson('rebootonly', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'global']);
$ok($c === 403 && $swCount('rmm_package_assignments') === 0, 'a level 2 script technician cannot assign (' . $c . ')');
[$c, $j] = $swJson('moduleonly', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'global']);
$ok(in_array($c, [403, 404], true) && $swCount('rmm_package_assignments') === 0, 'a module-only login cannot assign (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'assign', 'package_id' => '0', 'asg_type' => 'global']);
$ok($c === 422 && $swCount('rmm_package_assignments') === 0, 'assigning without a package is refused with words (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '0']);
$ok($c === 422 && $swCount('rmm_package_assignments') === 0, 'a department assignment without a department is refused (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'global']);
$ok($c === 403 && $swCount('rmm_package_assignments') === 0, 'a Dept B user cannot assign to everyone (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '1']);
$ok($c === 404 && $swCount('rmm_package_assignments') === 0, 'a Dept B user cannot assign to Dept A: the 404 of a missing department (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'device', 'asg_id_device' => (string) $swDev['SWLNX']]);
$ok($c === 404 && $swCount('rmm_package_assignments') === 0, 'a Dept B user cannot assign to a Dept A device (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '1', 'package_action' => 'install', 'version_id' => (string) $vHtop]);
$asgA = (int) $one('SELECT assignment_id FROM rmm_package_assignments LIMIT 1');
$ok($c === 200 && $asgA > 0 && $swAudit('Software Assigned') === 1 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgA") === 1, 'the administrator assigns htop to Dept A at a pinned version; it is live and audited (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $j] = $swJson('admin', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '1', 'package_action' => 'uninstall']);
$ok($c === 409 && $swCount('rmm_package_assignments') === 1, 'a conflicting assignment of the same package to the same scope is refused (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '2']);
$asgB = (int) $one('SELECT assignment_id FROM rmm_package_assignments WHERE scope_id=2');
$ok($c === 200 && $asgB > 0, 'a Dept B user assigns to their own department (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $body] = $swGet('admin', '/agent/rmm_software_assignments.php');
$ok($c === 200 && substr_count($body, 'SWP htop') >= 2 && str_contains($body, 'Dept A') && str_contains($body, 'Dept B') && str_contains($body, 'value="unassign"') !== false && str_contains($body, 'Live'), 'assignments page: both assignments, scope names, state');
[$c, $body] = $swGet('deptb', '/agent/rmm_software_assignments.php');
$ok($c === 200 && str_contains($body, 'Dept B') && !str_contains($body, 'Dept A'), 'assignments page (Dept B user): only their own department\'s assignment');
[$c, $j] = $swJson('deptb', ['action' => 'unassign', 'assignment_id' => (string) $asgA]);
$ok($c === 404 && $swCount('rmm_package_assignments') === 2, 'a Dept B user removing a Dept A assignment gets the 404 of a missing one (' . $c . ')');
[$c, $j] = $swJson('viewer', ['action' => 'unassign', 'assignment_id' => (string) $asgB]);
$ok($c === 403 && $swCount('rmm_package_assignments') === 2, 'a viewer cannot remove an assignment (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'unassign', 'assignment_id' => (string) $asgB]);
$ok($c === 200 && $swCount('rmm_package_assignments') === 1 && $swAudit('Software Unassigned') === 1, 'a Dept B user removes their own assignment; it is audited');
[$c, $j] = $swJson('admin', ['action' => 'delete_package', 'package_id' => (string) $pHtop]);
$ok($c === 409 && $swCount('rmm_packages', "package_id=$pHtop") === 1, 'a package that is still assigned cannot be deleted (' . $c . ')');

// ---- detection: queued by the handler, offered to the device signed, applied from the device's answer
[$c, $j] = $swJson('viewer', ['action' => 'detect', 'device_id' => $swDev['SWLNX']]);
$ok($c === 403 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_detect'") === 0, 'a viewer cannot ask a device what it has (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'detect', 'device_id' => $swDev['SWLNX']]);
$ok($c === 404 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_detect'") === 0, 'a Dept B user asking about a Dept A device gets the 404 of a missing device (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'detect', 'device_id' => '99999999']);
$ok($c === 404, 'detect on a device that does not exist: 404 (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'detect', 'device_id' => $swDev['SWOLD']]);
$ok($swCount('endpoint_agent_jobs', "device_id={$swDev['SWOLD']} AND type='software_detect'") === 0, 'detect on an older agent (and a device no catalog version fits) queues nothing (' . $c . ': ' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $b] = $swPost('admin', ['action' => 'detect', 'device_id' => $swDev['SWLNX']], []);
[$c0, $b0] = web($wb, 'POST', '/agent/post/rmm_software_deploy.php', $SS['admin'], ['action' => 'detect', 'device_id' => $swDev['SWLNX']]);   // no CSRF token
$ok($swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_detect'") === 1, 'the administrator queues one detection; a post without a CSRF token queued nothing more (' . $c0 . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_software_deploy.php?action=detect&device_id=' . $swDev['SWLNX'], $SS['admin']);
$ok($c === 405, 'the handler answers a GET with 405');
[$c, $j] = $swJson('tech', ['action' => 'detect', 'device_id' => $swDev['SWLNX']]);
$ok($c === 200 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_detect'") === 1, 'a second request while one waits is not queued twice');
$job = $swJobOf($swTok['SWLNX'], 'software_detect');
$rules = json_decode((string) ($swParams($job)['rules'] ?? ''), true);
$ok($job !== null && ($job['signature'] ?? '') !== '' && is_array($rules) && ($rules[0]['kind'] ?? '') === 'package' && ($rules[0]['value'] ?? '') === 'htop', 'the device receives the signed software_detect job with the rule built from the catalog (' . json_encode($rules) . ')');
$key = (string) ($rules[0]['key'] ?? 'p0');
[$c] = $swReport($swTok['SWLNX'], $job, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'items' => [['key' => $key, 'installed' => false, 'version' => '', 'satisfied' => false], ['key' => 'p999999', 'installed' => true, 'version' => '9', 'satisfied' => true]]]));
$inst = $rows("SELECT * FROM rmm_package_installs WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop")[0] ?? null;
$ok($c === 200 && $inst !== null && (int) $inst['installed'] === 0 && $inst['detected_at'] !== null && $swCount('rmm_package_installs', "device_id={$swDev['SWLNX']} AND package_id=999999") === 0, 'the answer is applied to the package that was asked about; a package nobody asked about is ignored');
[$c, $body] = $swHtml('admin', 'SWLNX');
$ok(str_contains($body, 'SWP htop') && str_contains($body, 'Missing') && str_contains($body, 'not installed') && str_contains($body, 'value="dry_run"') && str_contains($body, 'Install...') && str_contains($body, 'Remove...') && str_contains($body, 'Detect now') && $swNoInline($body), 'SWLNX block (administrator): the expected package, state Missing, quick dry run, install and remove links, Detect now');
[, $vwBody] = $swHtml('viewer', 'SWLNX');
$ok(str_contains($vwBody, 'SWP htop') && !str_contains($vwBody, 'Detect now') && !str_contains($vwBody, 'value="dry_run"') && !str_contains($vwBody, 'sw-run-form') && str_contains($vwBody, 'Your role can read software deployment but not change it'), 'SWLNX block (viewer): read only, no buttons');
$rbBody = rivetRmmSwPanelSection(rivetRmmUiPanel($db, $swAsset['SWLNX'], $USER['rebootonly']), $db, 'csrftok1');
$ok(str_contains($rbBody, 'sw-run-form') && str_contains($rbBody, 'value="dry_run"') && !str_contains($rbBody, 'Run for real') && !str_contains($rbBody, 'Install...'), 'SWLNX block (level 2 script technician): dry run only, no install section');
[$c, $mb] = $swHtml('moduleonly', 'SWLNX');
$ok(!str_contains($mb, 'sw-run-form') && !str_contains($mb, 'Run for real') && !str_contains($mb, 'Detect now'), 'SWLNX block (module-only login): never any controls');
[$c, $pb] = $swHtml('admin', 'SWLNX', '&sw_pkg=' . $pHtop . '&sw_action=uninstall');
$ok(preg_match('/<option value="' . $pHtop . '" selected>/', $pb) === 1 && preg_match('/<option value="uninstall" selected>/', $pb) === 1 && str_contains($pb, 'Run for real'), 'the Remove... link preselects the package and the action in the run form');
[$c, $pb] = $swHtml('admin', 'SWLNX', '&sw_pkg=%22%3E%3Cx&sw_action=%22%3E%3Cb%3E');
$ok(!str_contains($pb, '"><x') && !str_contains($pb, '"><b>'), 'hostile sw_pkg / sw_action values are not echoed');

// ---- a dry run, then a real run: confirmations, abilities, everything built from the catalog
[$c, $j] = $swJson('viewer', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop]);
$ok($c === 403 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install'") === 0, 'a viewer cannot start a dry run (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop]);
$ok($c === 404 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install'") === 0, 'a Dept B user cannot dry-run on a Dept A device: the 404 of a missing device (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => '0']);
$ok($c === 422, 'a dry run without a package is refused with words (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => '999999']);
$ok($c === 422 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install'") === 0, 'a dry run of a package that does not exist is refused (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pWin]);
$ok(in_array($c, [422, 409], true) && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install'") === 0, 'a Windows package on a Linux device: nothing fits, nothing queued (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'package_action' => 'install',
    'url' => 'https://evil.example.net/x', 'sha256' => str_repeat('0', 64), 'pkg' => 'evil-package', 'args' => '--allow-unauthenticated', 'pkg_name' => 'evil', 'fmt' => 'exe']);
$dry = $swJobOf($swTok['SWLNX'], 'software_install');
$dp = $swParams($dry);
$ok($c === 200 && $dry !== null && ($dp['mode'] ?? '') === 'dry_run' && ($dp['pkg'] ?? '') === 'htop' && ($dp['fmt'] ?? '') === 'apt' && !isset($dp['url']) && !isset($dp['sha256']) && !isset($dp['args']) && !str_contains(json_encode($dp), 'evil'), 'a dry run is queued from the catalog row only: extra fields (url, hash, pkg, args, fmt) never reach the job (' . json_encode($dp) . ')');
[$c, $j] = $swJson('tech', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop]);
$ok($c === 200 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install'") === 1, 'a second install job while one waits is not queued (one at a time per device)');
[$c] = $swReport($swTok['SWLNX'], $dry, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'result' => 'would_install', 'code' => '', 'detail' => 'htop 3.3.0 would be installed', 'version' => '3.3.0', 'reboot_required' => false]));
$h = $rows("SELECT * FROM rmm_package_history WHERE device_id={$swDev['SWLNX']}")[0] ?? [];
$ok($c === 200 && ($h['mode'] ?? '') === 'dry_run' && ($h['result'] ?? '') === 'would_install' && (int) $one("SELECT installed FROM rmm_package_installs WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop") === 0, 'the dry run is recorded in the history; the installed state is untouched');
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop]);
$ok($c === 422 && str_contains((string) ($j['error'] ?? ''), 'confirmation box') && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'a real run without the confirmation box is refused and queues nothing (' . $c . ')');
[$c, $j] = $swJson('rebootonly', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'confirm' => '1']);
$ok($c === 403 && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'a level 2 script technician cannot install for real, even with the confirmation (' . $c . ')');
[$c, $j] = $swJson('viewer', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'confirm' => '1']);
$ok($c === 403 && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'a viewer cannot install for real (' . $c . ')');
[$c, $j] = $swJson('deptb', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'confirm' => '1']);
$ok($c === 404 && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'a Dept B user cannot install on a Dept A device (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWOLD'], 'package_id' => (string) $pHtop, 'confirm' => '1']);
$ok(in_array($c, [409, 422], true) && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWOLD']} AND type='software_install'") === 0, 'a real run on a device that cannot do it queues nothing (' . $c . ')');
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'package_action' => 'install', 'confirm' => '1']);
$inst = $swJobOf($swTok['SWLNX'], 'software_install');
$ip = $swParams($inst);
$ok($c === 200 && $inst !== null && ($ip['mode'] ?? '') === 'run' && ($ip['pkg'] ?? '') === 'htop' && !isset($ip['version']) && ($ip['action'] ?? '') === 'install' && $swAudit('Software Job Queued') === 1, 'a technician with the deploy grant installs for real: the job carries the catalog\'s package name, no address and no hash (' . json_encode($ip) . ')');
[$c] = $swReport($swTok['SWLNX'], $inst, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'result' => 'installed', 'code' => '', 'detail' => 'htop 3.3.0 installed', 'version' => '3.3.0', 'reboot_required' => false]));
$ir = $rows("SELECT * FROM rmm_package_installs WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop")[0] ?? [];
$ok((int) ($ir['installed'] ?? 0) === 1 && ($ir['installed_version'] ?? '') === '3.3.0' && (int) ($ir['satisfied'] ?? 0) === 1 && ($ir['last_result'] ?? '') === 'installed', 'the install result is applied: installed, satisfied, version 3.3.0');
[$c, $body] = $swHtml('admin', 'SWLNX');
$ok(str_contains($body, 'Compliant') && str_contains($body, 'Install history') && str_contains($body, 'Dry run, install') && str_contains($body, 'htop 3.3.0 installed'), 'the block shows Compliant and the install history');
// removal
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'package_action' => 'uninstall', 'confirm' => '1']);
$rm = $swJobOf($swTok['SWLNX'], 'software_install');
$ok($c === 200 && ($swParams($rm)['action'] ?? '') === 'uninstall', 'a removal is queued as a removal');
$swReport($swTok['SWLNX'], $rm, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'result' => 'uninstalled', 'code' => '', 'detail' => 'removed', 'version' => '', 'reboot_required' => false]));
$q("UPDATE rmm_package_installs SET installed=1, installed_version='3.3.0', satisfied=1 WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop");   // put the device back to "has it" for the next steps
// a failed install is shown as failed
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pHtop, 'package_action' => 'install', 'confirm' => '1']);
$bad = $swJobOf($swTok['SWLNX'], 'software_install');
$swReport($swTok['SWLNX'], $bad, 'failed', json_encode(['v' => 1, 'ok' => false, 'result' => 'locked', 'code' => 'dpkg_lock', 'detail' => $hostile, 'version' => '', 'reboot_required' => false]), 1);
[$c, $body] = $swHtml('admin', 'SWLNX');
$ok(str_contains($body, 'Package manager busy') && $noRaw($body) && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'a failed install shows its result; the hostile detail the device sent is escaped');
$q("UPDATE rmm_package_installs SET fail_count=0, last_result='installed', last_detail='', installed=1, satisfied=1 WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop");

// ---- the second-person rule on a package: assignments and real runs wait for someone else
$apr0 = $swCount('rmm_approvals', "kind='software'");
[$c, $j] = $swJson('tech', ['action' => 'assign', 'package_id' => (string) $pSec, 'asg_type' => 'client', 'asg_id_client' => '1']);
$asgSec = (int) $one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id=$pSec");
$aprSec = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='software' ORDER BY approval_id DESC LIMIT 1");
$ok($c === 200 && $asgSec > 0 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgSec") === 0 && $swCount('rmm_approvals', "kind='software'") === $apr0 + 1 && str_contains((string) ($j['message'] ?? ''), 'second person'), 'a two-person package: the assignment is saved switched off and opens a request (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $body] = $swGet('tech', '/agent/rmm_software_assignments.php');
$ok(str_contains($body, 'Waiting for a second person') && str_contains($body, 'You cannot approve your own request') && str_contains($body, 'value="cancel_approval"') && !str_contains($body, 'value="decide_approval"'), 'assignments page: the request waits; the requester cannot approve their own but can cancel it');
[$c, $j] = $swJson('tech', ['action' => 'decide_approval', 'approval_id' => (string) $aprSec]);
$ok($c === 403 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgSec") === 0, 'the requester deciding their own request is refused by Core (' . $c . ')');
foreach (['viewer', 'deptb', 'rebootonly'] as $who) {
    [$c, $j] = $swJson($who, ['action' => 'decide_approval', 'approval_id' => (string) $aprSec]);
    $ok(in_array($c, [403, 404], true) && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgSec") === 0, "$who cannot decide it ($c)");
}
[$c, $j] = $swJson('viewer', ['action' => 'cancel_approval', 'approval_id' => (string) $aprSec]);
$ok(in_array($c, [403, 404], true) && $one("SELECT state FROM rmm_approvals WHERE approval_id=$aprSec") === 'pending_approval', 'someone else cannot cancel the request (' . $c . ')');
[$c, $j] = $swJson('admin', ['action' => 'decide_approval', 'approval_id' => (string) $aprSec, 'note' => 'ok']);
$ok($c === 200 && (int) $one("SELECT enabled FROM rmm_package_assignments WHERE assignment_id=$asgSec") === 1 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$aprSec") === 'approved', 'the administrator, as the second person, approves: the assignment goes live (' . ($j['message'] ?? $j['error'] ?? '') . ')');
[$c, $body] = $swGet('admin', '/agent/rmm_approvals.php?state=approved&kind=software');
$ok($c === 200 && str_contains($body, 'Software') && str_contains($body, '<option value="software" selected>') && str_contains($body, 'SWP secure'), 'the Approvals page lists the request as Software and has a Kind filter for it');
// reject drops a pending assignment
[$c, $j] = $swJson('admin', ['action' => 'unassign', 'assignment_id' => (string) $asgSec]);
[$c, $j] = $swJson('tech', ['action' => 'assign', 'package_id' => (string) $pSec, 'asg_type' => 'client', 'asg_id_client' => '2']);
$asgSec2 = (int) $one("SELECT assignment_id FROM rmm_package_assignments WHERE package_id=$pSec");
$aprSec2 = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='software' ORDER BY approval_id DESC LIMIT 1");
[$c, $j] = $swJson('admin', ['action' => 'reject_approval', 'approval_id' => (string) $aprSec2]);
$ok($c === 200 && $swCount('rmm_package_assignments', "assignment_id=$asgSec2") === 0, 'rejecting the request drops the waiting assignment (' . $c . ')');
// a real run of a two-person package waits too
[$c, $j] = $swJson('tech', ['action' => 'run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pSec, 'confirm' => '1']);
$aprRun = (int) $one("SELECT approval_id FROM rmm_approvals WHERE kind='software' ORDER BY approval_id DESC LIMIT 1");
$ok($c === 200 && str_contains((string) ($j['message'] ?? ''), 'second person') && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install' AND state IN ('queued','running')") === 0 && $one("SELECT state FROM rmm_approvals WHERE approval_id=$aprRun") === 'pending_approval', 'a real run of a two-person package queues nothing yet and opens a request (' . ($j['message'] ?? '') . ')');
[$c, $j] = $swJson('admin', ['action' => 'dry_run', 'device_id' => $swDev['SWLNX'], 'package_id' => (string) $pSec]);
$ok($c === 200 && $swCount('endpoint_agent_jobs', "device_id={$swDev['SWLNX']} AND type='software_install' AND state IN ('queued','running')") === 1, 'a dry run of a two-person package needs no second person');
$dj = $swJobOf($swTok['SWLNX'], 'software_install');
$swReport($swTok['SWLNX'], $dj, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'result' => 'would_install', 'code' => '', 'detail' => 'dry', 'version' => '1.0', 'reboot_required' => false]));
[$c, $j] = $swJson('tech', ['action' => 'decide_approval', 'approval_id' => (string) $aprRun]);
$ok(in_array($c, [403, 404], true) && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'the requester of the run (a technician without the approval grant) cannot approve it (' . $c . ')');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $j] = $swJson('tech', ['action' => 'decide_approval', 'approval_id' => (string) $aprRun]);
$ok($c === 403 && $swJobOf($swTok['SWLNX'], 'software_install') === null, 'even with the approval grant the requester cannot approve their own run: Core refuses it (' . $c . ': ' . ($j['error'] ?? '') . ')');
$q("UPDATE settings SET config_rmm_approve_scripts_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $j] = $swJson('admin', ['action' => 'decide_approval', 'approval_id' => (string) $aprRun, 'note' => 'ok']);
$runJob = $swJobOf($swTok['SWLNX'], 'software_install');
$ok($c === 200 && $runJob !== null && ($swParams($runJob)['pkg'] ?? '') === 'secure-tool' && ($swParams($runJob)['mode'] ?? '') === 'run' && (int) $one("SELECT created_by FROM rmm_package_jobs WHERE job_id='" . $esc((string) $runJob['job_id']) . "'") === $USER['tech'], 'the second person approves: the run is queued, attributed to the person who asked (' . ($j['message'] ?? $j['error'] ?? '') . ')');
$swReport($swTok['SWLNX'], $runJob, 'succeeded', json_encode(['v' => 1, 'ok' => true, 'result' => 'installed', 'code' => '', 'detail' => 'ok', 'version' => '1.0', 'reboot_required' => false]));
// the administrator may lower the flag
[$c, $j] = $swJson('admin', ['action' => 'save_package', 'package_id' => (string) $pSec, 'name' => 'SWP secure', 'enabled' => '1', 'two_person_shown' => '1']);
$ok($c === 200 && (int) $one("SELECT two_person FROM rmm_packages WHERE package_id=$pSec") === 0, 'the administrator can lower the second-person rule (' . $c . ')');
$q("DELETE FROM rmm_package_assignments WHERE package_id=$pSec");

// ---- the scheduler: dry runs only until live installs are switched on, then a real install
$q("DELETE FROM rmm_package_jobs WHERE device_id={$swDev['SWLNX']}");
$q("DELETE FROM endpoint_agent_jobs WHERE device_id={$swDev['SWLNX']} AND type LIKE 'software_%'");
$q("UPDATE rmm_package_installs SET installed=0, installed_version='', satisfied=0, last_result='', last_job_id=NULL, fail_count=0, attempts=0 WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop");
$q("UPDATE rmm_deploy_state SET detected_at=UTC_TIMESTAMP(), detect_requested_at=UTC_TIMESTAMP() WHERE device_id={$swDev['SWLNX']}");
$hk = $rmm->housekeeping()->run();
$sched = $rows("SELECT * FROM rmm_package_jobs WHERE device_id={$swDev['SWLNX']} AND type='software_install'");
$ok(is_array($hk) && count($sched) === 1 && $sched[0]['mode'] === 'dry_run' && $sched[0]['source'] === 'scheduler', 'with live installs off the scheduler queued exactly one DRY RUN and no real install (housekeeping ' . (is_array($hk) ? 'ran' : 'did not run') . ')');
$q("UPDATE endpoint_agent_jobs SET state='cancelled' WHERE device_id={$swDev['SWLNX']} AND type='software_install'");
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_live_install' => '1', 'accept_software_live' => '1', 'software_detect_interval_s' => '7200']);
$q("DELETE FROM rmm_package_jobs WHERE device_id={$swDev['SWLNX']} AND type='software_install'");
$hk = $rmm->housekeeping()->run();
$sched = $rows("SELECT * FROM rmm_package_jobs WHERE device_id={$swDev['SWLNX']} AND type='software_install'");
$ok(count($sched) === 1 && $sched[0]['mode'] === 'run' && $sched[0]['source'] === 'scheduler', 'with live installs on the scheduler queues the real install (' . json_encode(array_column($sched, 'mode')) . ')');
[$c, $body] = $swHtml('admin', 'SWLNX');
$ok(str_contains($body, 'Pending'), 'the block shows the waiting job as Pending');
[$c] = $swSettings(['feature_software_deploy' => '1', 'software_detect_interval_s' => '7200']);
$ok($rmm->settings()->limits()['software_live_install'] === 0, 'live installs are switched off again');
$q("UPDATE endpoint_agent_jobs SET state='cancelled' WHERE device_id={$swDev['SWLNX']} AND type='software_install'");
$q("UPDATE rmm_package_installs SET installed=1, installed_version='3.3.0', satisfied=1, last_job_id=NULL, last_result='installed', detected_at=UTC_TIMESTAMP() WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop");

// ---- the report: groups, filters, escaping, the department scope, the CSV
$q("UPDATE rmm_package_installs SET installed=0, satisfied=0, last_result='failed', fail_count=1, last_job_id=NULL WHERE device_id={$swDev['SWLNX']} AND package_id=$pHtop");
[$c, $j] = $swJson('admin', ['action' => 'assign', 'package_id' => (string) $pHtop, 'asg_type' => 'client', 'asg_id_client' => '2']);
$rmm->housekeeping()->run();
foreach (['package', 'client', 'ring', 'device'] as $by) {
    [$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=' . $by);
    $ok($c === 200 && str_contains($body, 'Software report') && str_contains($body, 'Download CSV') && str_contains($body, 'Deployed against expected') && $swNoInline($body), "report page, grouped by $by (" . $c . ')');
}
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=device');
$ok(str_contains($body, 'SWLNX') && str_contains($body, '/agent/asset_details.php?asset_id=' . $A['SWLNX'] . '#rmm-software') && str_contains($body, 'Failed') && str_contains($body, 'SWB'), 'report by device: the devices, a link to their Software tab, the states');
[, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=device&state=failed');
$ok(str_contains($body, 'SWLNX') && !str_contains($body, 'SWB'), 'report by device: the state filter');
[, $csvc] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=client&client_id=1');
$ok(preg_match('/^1,/m', $csvc) === 1 && preg_match('/^2,/m', $csvc) !== 1, 'report by department: the department filter (CSV rows: ' . json_encode(explode("\r\n", trim($csvc))) . ')');
[, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=package');
$ok(str_contains($body, 'SWP htop'), 'report by package: the package row');
[$c, $body] = $swGet('deptb', '/agent/rmm_software_report.php?group_by=device');
$ok($c === 200 && str_contains($body, 'SWB') && !str_contains($body, 'SWLNX') && !str_contains($body, 'Dept A'), 'report (Dept B user): nothing of Dept A');
[$c, $body] = $swGet('deptb', '/agent/rmm_software_report.php?group_by=device&client_id=1');
$ok(!str_contains($body, 'SWLNX') && (str_contains($body, 'No such client') || str_contains($body, 'could not be read')), 'report (Dept B user asking for Dept A): refused, nothing leaks');
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=%22%3E%3Cscript%3E&client_id=abc&package_id=-1&ring=%22%3E%3Cb%3E&state=%22%3E%3Ci%3E&page=-3');
$ok($c === 200 && !str_contains($body, '"><script>') && !str_contains($body, '"><b>') && !str_contains($body, '"><i>') && !str_contains($body, '&quot;&gt;&lt;script'), 'report: hostile parameters are ignored, not echoed');
[$c, $body] = $swGet('normo', '/agent/rmm_software_report.php');
$ok($c === 403, 'report: a user without the RMM role is refused (' . $c . ')');
[$c, $body] = $swGet('viewer', '/agent/rmm_software_report.php');
$ok($c === 200 && str_contains($body, 'Software report'), 'report: a viewer can read it');
// CSV
[$c, $csv, $hd] = web($wb, 'GET', '/agent/rmm_software_report_csv.php?group_by=device', $SS['admin']);
$ok($c === 200 && stripos($hd, 'content-type: text/csv') !== false && stripos($hd, 'attachment; filename="software-deployment-device.csv"') !== false && stripos($hd, 'x-content-type-options: nosniff') !== false && str_contains($csv, "\r\n") && str_contains($csv, 'hostname') && str_contains($csv, 'SWLNX'), 'CSV: headers, CRLF and the rows');
[$c, $csv] = $swGet('deptb', '/agent/rmm_software_report_csv.php?group_by=device');
$ok($c === 200 && str_contains($csv, 'SWB') && !str_contains($csv, 'SWLNX'), 'CSV (Dept B user): only their own department');
[$c, $csv] = $swGet('deptb', '/agent/rmm_software_report_csv.php?group_by=device&client_id=1');
$ok($c === 404 && !str_contains($csv, 'SWLNX'), 'CSV (Dept B user asking for Dept A): the 404 of a missing department (' . $c . ')');
[$c, $csv] = $swGet('normo', '/agent/rmm_software_report_csv.php');
$ok($c === 403, 'CSV: a user without the RMM role is refused (' . $c . ')');
[$c, $b] = web($wb, 'POST', '/agent/rmm_software_report_csv.php', $SS['admin'], ['csrf_token' => 'csrftok1']);
$ok($c === 405, 'CSV: only GET (' . $c . ')');
$q("UPDATE rmm_packages SET name='=HYPERLINK(\"http://evil.example/\",\"x\")' WHERE package_id=$pHtop");
[$c, $csv] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=package');
$ok(str_contains($csv, "\"'=HYPERLINK(") && !preg_match('/(^|,)=HYPERLINK/m', $csv), 'CSV: a package name that starts with = is neutralised with an apostrophe (formula injection)');
$q("UPDATE rmm_packages SET name='@SUM(1)' WHERE package_id=$pHtop");
[$c, $csv] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=package');
$ok(str_contains($csv, "'@SUM(1)"), 'CSV: a leading @ is neutralised too');
$q("UPDATE rmm_packages SET name='SWP htop' WHERE package_id=$pHtop");

// ---- the REST API carries the same routes with the same rules (technician tokens; nothing to route in the edition)
$tk = $S['users'];
[$c, , $j] = http('GET', '/api/v1/endpoint_devices/software_report', $tk['deptb']);
$ok($c === 200 && isset($j['rows']) && !str_contains(json_encode($j), 'SWLNX'), 'REST: a Dept B token reads only its own report (' . $c . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/' . $swDev['SWLNX'] . '/deployments', $tk['deptb']);
$ok($c === 404, 'REST: a Dept B token asking for a Dept A device gets the 404 of a missing device (' . $c . ')');
[$c] = http('POST', '/api/v1/endpoint_devices/' . $swDev['SWLNX'] . '/deployments/run', $tk['viewer'], ['package_id' => $pHtop, 'mode' => 'run', 'confirm' => true]);
$ok($c === 403, 'REST: a viewer token cannot run (' . $c . ')');
[$c] = http('POST', '/api/v1/endpoint_devices/software_hosts', $tk['tech'], ['host' => 'rest.example.com']);
$ok($c === 403 && $swCount('rmm_software_hosts', "host='rest.example.com'") === 0, 'REST: a technician token cannot change the host list (' . $c . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/software_report');
$ok($c === 401, 'REST: no token, no answer (' . $c . ')');
[$c] = http('GET', '/api/v1/endpoint_devices/software_report', $tk['normo']);
$ok($c === 403, 'REST: a token of a user without the RMM role is refused (' . $c . ')');

// ---- hostile text from devices and people is text on every page: hostname, package name, publisher, description, host note, result detail
$hostHost = '<img src=x onerror=alert(3)> H"q\'s&';
$q("INSERT INTO assets SET asset_type='Server', asset_name='SWHOST', asset_make='Dell', asset_serial='SER-SWHOST', asset_client_id=1, asset_status='Active'");
$hostAsset = (int) $db->insert_id;
[$c, , $j] = ea_enroll($S['enroll']['A'], ea_dev(['serial' => 'SER-SWHOST', 'hostname' => $hostHost, 'os' => 'linux', 'os_version' => 'Ubuntu 24.04']));
$hostDev = (int) ($j['device_id'] ?? 0);
$hostTok = (string) ($j['device_token'] ?? '');
ea_checkin($hostTok, ['capabilities' => $swCaps]);
$q("UPDATE rmm_packages SET name='" . $esc($hostile) . "', publisher='" . $esc($hostile) . "', description='" . $esc($hostile) . "' WHERE package_id=$pHtop");
$q("INSERT INTO rmm_package_history SET device_id=$hostDev, client_id=1, package_id=$pHtop, action='install', mode='run', result='failed', code='x', detail='" . $esc($hostile) . "', version='" . $esc($hostile) . "', at=UTC_TIMESTAMP()");
$q("INSERT INTO rmm_package_installs SET device_id=$hostDev, package_id=$pHtop, client_id=1, expected_action='install', expected_version_id=$vHtop, ring='pilot', expected_since=UTC_TIMESTAMP(), installed=0, last_action='install', last_result='failed', last_code='x', last_detail='" . $esc($hostile) . "', fail_count=1, attempts=1, updated_at=UTC_TIMESTAMP()");
$q("UPDATE rmm_software_hosts SET note='" . $esc($hostile) . "'");
[$c, $body] = web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $hostAsset, $SS['admin']);
$ok($c === 200 && $noRaw($body) && str_contains($body, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($body, 'Install history'), 'hostile package name, result detail and version are escaped on the Software tab');
foreach (['/agent/rmm_software.php', '/agent/rmm_software.php?package_id=' . $pHtop, '/agent/rmm_software_assignments.php', '/agent/rmm_software_report.php?group_by=device', '/agent/rmm_software_report.php?group_by=package', '/agent/rmm_approvals.php?kind=software&state=all'] as $path) {
    [$c, $body] = $swGet('admin', $path);
    $ok($c === 200 && $noRaw($body), "hostile text is escaped on $path");
}
[$c, $body] = $swGet('admin', '/agent/rmm_software_report.php?group_by=device');
$ok(str_contains($body, '&lt;img src=x onerror=alert(3)&gt;'), 'the hostile hostname is escaped on the report page');
[$c, $csv] = $swGet('admin', '/agent/rmm_software_report_csv.php?group_by=device');
$ok($c === 200 && str_contains($csv, 'onerror=alert(3)') && !str_contains($body, '<img src=x'), 'the hostile hostname is carried verbatim as CSV text (a data file, not a page)');

// ---- put everything back: the switch OFF (the default), live installs off
$q("DELETE FROM rmm_package_installs");
$q("DELETE FROM rmm_package_history");
$q("DELETE FROM rmm_package_jobs");
$q("DELETE FROM rmm_package_assignments");
$q("DELETE FROM rmm_package_versions");
$q("DELETE FROM rmm_packages");
$q("DELETE FROM rmm_software_hosts");
$q("DELETE FROM rmm_deploy_state");
$q("DELETE FROM rmm_approvals WHERE kind='software'");
$q("DELETE FROM endpoint_agent_jobs WHERE type LIKE 'software_%'");
[$c] = $swSettings(['software_detect_interval_s' => '86400', 'software_max_installs' => '25', 'software_max_installs_per_client' => '5', 'software_deploy_history_days' => '365', 'software_batch' => '100']);
$ok(!$rmm->featureOn('software_deploy') && !rivetRmmFeatureOn('software_deploy') && $rmm->settings()->limits()['software_live_install'] === 0 && $rmm->settings()->limits()['software_max_installs'] === 25 && ($swFeatures()['monitoring'] ?? null) === true, 'software_deploy and software_live_install are back OFF, the limits are back to their defaults');
