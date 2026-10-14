<?php
/*
 * Fragment of tests/rmm_ui.php (RMM Phase 6 UI, RivetCore 1.0.0-rc.11): the Remote tab of the asset page (includes/rmm_rem_ui.php, agent/post/rmm_remote_tools.php,
 * js/rmm_remote.js): services, processes, files and the Mesh buttons. Shares that file's variables.
 *
 * Everything is driven through the product: devices enroll and check in through the real device endpoints and announce the remote capabilities, the technician acts
 * through the real handler over HTTP (forged sessions, CSRF), and the DEVICE is simulated through the real device endpoints too: GET /api/v1/agent_jobs receives the
 * signed job, POST reports its result, and file bytes move through /api/v1/agent_transfer with the one-time X-Transfer-Token. The sub-switches are OFF at the start
 * (the contract of the p68 fragments); this one turns on what it needs through RemoteActions::updateSettings() and puts everything back at the end.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Remote\RemoteSettings;

$remAct = $rmm->remoteActions();
$remCall = function (string $who, string $action, array $f = []) use ($wb, $SS): array {
    [$c, $body] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS[$who], $f + ['action' => $action, 'csrf_token' => 'csrftok1']);

    return [$c, json_decode($body, true) ?: [], $body];
};
$remUpload = function (string $base, string $sid, array $f, ?string $bytes, string $name = 'upload.bin') use ($root): array {
    $post = $f + ['action' => 'xfer_upload', 'csrf_token' => 'csrftok1'];
    $tmp = null;
    if ($bytes !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'remup');
        file_put_contents($tmp, $bytes);
        $post['file'] = new CURLFile($tmp, 'application/octet-stream', $name);
    }
    $ch = curl_init($base . '/agent/post/rmm_remote_tools.php');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_TIMEOUT => 90]);
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($tmp !== null) {
        @unlink($tmp);
    }

    return [$code, json_decode($raw, true) ?: [], $raw];
};
$remRows = fn (string $sql) => $rows($sql);
$remAudit = fn (string $action, string $like = '%') => (int) $one("SELECT COUNT(*) FROM logs WHERE log_action = '" . $esc($action) . "' AND log_description LIKE '" . $esc($like) . "'");

// ---- the simulated device: receive jobs and report results through the real endpoints
$remJobs = function (string $tok): array {
    [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok);

    return $c === 200 ? ($j['jobs'] ?? []) : [];
};
$remJobOf = function (string $tok, string $type) use ($remJobs): ?array {
    foreach ($remJobs($tok) as $j) {
        if ($j['type'] === $type) {
            return $j;
        }
    }

    return null;
};
$remReport = fn (string $tok, array $job, string $state, int $exit, string $out) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $out]);

// ---- devices of this fragment: a Linux device that announces all three capabilities, a Windows one that announces none, and one in Dept B
$remDev = [];
$remTok = [];
$remAsset = [];
$remCaps = ['job:collect', 'job:shell', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'job:file_get', 'job:file_put', 'remote:services', 'remote:processes', 'remote:files'];
foreach (['REM1' => [1, 'A', 'linux', $remCaps], 'REM2' => [1, 'A', 'windows', ['job:collect']], 'REMB' => [2, 'B', 'linux', $remCaps]] as $n => [$client, $tk, $os, $caps]) {
    $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
    $remAsset[$n] = (int) $db->insert_id;
    [$c, , $j] = ea_enroll($S['enroll'][$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $remDev[$n] = (int) ($j['device_id'] ?? 0);
    $remTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $remDev[$n] > 0, "$n enrolled through the real endpoint");
    [$c] = ea_checkin($remTok[$n], ['capabilities' => $caps]);
    $ok($c === 200, "$n checked in and announced " . implode(',', array_filter($caps, fn ($x) => str_starts_with($x, 'remote:'))));
}
$A += $remAsset;
$page = fn (string $who, string $n) => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $remAsset[$n], $SS[$who]);
$tabDrawn = fn (string $body) => str_contains($body, 'id="rmm-tab-remote"') && str_contains($body, 'id="rmm-remote-tools"');
$coreRefusal = fn (string $who, string $method, array $args) => $rmm->remoteActions()->$method(new RmmPrincipal($USER[$who], $who), ...$args);

// the asset page itself needs the Assets module for these two roles (the panel inside it is gated separately); removed again at the end
foreach ([3, 5] as $role) { $q("INSERT INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2"); }
rivetRmmForgetAccess();

// ============================================================ switches and abilities: tab not drawn, handler refuses
$ok(!$rmm->featureOn('remote_tools') && !$rmm->featureOn('filetransfer'), 'the remote_tools and filetransfer sub-switches are off at the start of the fragment');
[$c, $body] = $page('admin', 'REM1');
$ok($c === 200 && str_contains($body, 'rmm-panel') && !$tabDrawn($body) && !str_contains($body, 'rmm-tab-remote'), 'switch off: the asset page has the panel but no Remote tab');
[$c, $j] = $remCall('admin', 'svc_refresh', ['device_id' => $remDev['REM1']]);
$ok($c === 409 && ($j['code'] ?? '') === 'feature_disabled' && str_contains((string) ($j['error'] ?? ''), 'switched off'), 'switch off: the handler refuses with Core\'s "switched off" text (' . $c . ')');
foreach (['svc_snapshot', 'proc_snapshot', 'job_result', 'proc_refresh'] as $a) {
    [$c, $j] = $remCall('admin', $a, ['device_id' => $remDev['REM1'], 'job_id' => ea_uuid()]);
    $ok($c === 409 && ($j['code'] ?? '') === 'feature_disabled', "switch off: $a is refused too ($c)");
}
$remAct->updateSettings($admin, ['features' => ['remote_tools' => true]]);
$fresh();
$ok($rmm->featureOn('remote_tools') && !$rmm->featureOn('filetransfer'), 'remote_tools turned on through RemoteActions::updateSettings (filetransfer stays off)');

// module off: no tab, the handler says so without touching Core
$rmm->admin()->disable($admin);
$fresh();
[$c, $body] = $page('admin', 'REM1');
$ok(!$tabDrawn($body) && !str_contains($body, 'rmm_remote.js'), 'module off: no Remote tab and the script is not linked');
[$c, $j] = $remCall('admin', 'svc_refresh', ['device_id' => $remDev['REM1']]);
$ok($c === 404 && ($j['code'] ?? '') === 'module_disabled', 'module off: the handler answers 404 module_disabled (' . $c . ')');
$rmm->admin()->enable($admin);
$fresh();

// admin sees the tab; viewer, rebootonly and remoteonly do not; the tech does only while the level 3 setting is on
[$c, $body] = $page('admin', 'REM1');
$ok($c === 200 && $tabDrawn($body) && str_contains($body, 'rmm_remote.js') && str_contains($body, 'itflow_rmm_remote.css'), 'administrator: the Remote tab is drawn and its script is linked');
foreach (['viewer', 'rebootonly', 'remoteonly'] as $who) {
    [$c, $body] = $page($who, 'REM1');
    $ok($c === 200 && str_contains($body, 'rmm-panel') && !$tabDrawn($body) && !str_contains($body, 'rmm_remote.js') && !str_contains($body, 'rmm-remote-tools'), "$who: no Remote tab and no script (the control is not drawn)");
    [$c, $j] = $remCall($who, 'svc_refresh', ['device_id' => $remDev['REM1']]);
    $refusal = $coreRefusal($who, 'listServices', [$remDev['REM1']]);
    $ok($c === 403 && ($j['error'] ?? '') === $refusal->message && $refusal->message !== '', "$who: the handler refuses with Core's text: \"" . ($j['error'] ?? '?') . '"');
    foreach (['svc_snapshot', 'proc_snapshot', 'xfer_list', 'mesh'] as $a) {
        [$c] = $remCall($who, $a, ['device_id' => $remDev['REM1'], 'view' => 'terminal']);
        $ok($c >= 400, "$who: $a refused ($c)");
    }
}
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $page('tech', 'REM1');
$ok(!$tabDrawn($body), 'technician (level 3 scripts), lvl3 setting OFF: no Remote tab');
[$c, $j] = $remCall('tech', 'svc_refresh', ['device_id' => $remDev['REM1']]);
$ok($c === 403, 'technician, lvl3 setting OFF: the handler refuses (' . $c . ')');
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $page('tech', 'REM1');
$ok($tabDrawn($body), 'technician (level 3 scripts), lvl3 setting ON: the Remote tab is drawn');
$ok(!str_contains($body, 'Send a file to the device') && !str_contains($body, 'rmmRemUpload'), 'technician: no upload control even with the tab (administrators only)');

// device of another client: user 16 sees Dept B only; the answer for REM1 equals the answer for a device that does not exist
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
[$c1, $j1, $raw1] = $remCall('deptb', 'svc_refresh', ['device_id' => $remDev['REM1']]);
[$c2, $j2, $raw2] = $remCall('deptb', 'svc_refresh', ['device_id' => 99999]);
$ok($c1 === 404 && $c2 === 404 && $raw1 === $raw2, 'another client\'s device: the same 404 body as a device that does not exist (' . $raw1 . ')');
foreach (['svc_snapshot', 'proc_kill', 'xfer_list', 'xfer_download', 'mesh', 'job_result'] as $a) {
    [$c, , $raw] = $remCall('deptb', $a, ['device_id' => $remDev['REM1'], 'pid' => '4242', 'name' => 'x', 'confirm' => '1', 'path' => '/srv/data/a', 'view' => 'files', 'job_id' => ea_uuid()]);
    $ok($c === 404 && $raw === $raw1, "another client's device: $a answers the same 404");
}
[$c, $body] = $page('deptb', 'REM1');
$ok(!$tabDrawn($body), 'another client\'s device: no Remote tab for user 16');
[$c, $j] = $remCall('deptb', 'svc_refresh', ['device_id' => $remDev['REMB']]);
$ok($c === 200 && !empty($j['job_id']), 'user 16 can use the tools on a device of their own client (' . $c . ')');

// CSRF, method, unknown action
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS['admin'], ['action' => 'svc_refresh', 'device_id' => $remDev['REM1']]);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf', 'no CSRF token: 403 csrf, nothing queued');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote_tools.php', $SS['admin'], ['action' => 'svc_refresh', 'device_id' => $remDev['REM1'], 'csrf_token' => 'wrong']);
$ok($c === 403 && (json_decode($b, true)['code'] ?? '') === 'csrf', 'wrong CSRF token: 403 csrf (JSON, not a redirect)');
$ok($one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE device_id={$remDev['REM1']}") === '0', 'no job was queued by the refused requests');
[$c, $j] = $remCall('admin', 'no_such_action', ['device_id' => $remDev['REM1']]);
$ok($c === 422 && ($j['error'] ?? '') === 'Unknown action.', 'unknown action: 422');
[$c, $b] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=svc_refresh&device_id=' . $remDev['REM1'], $SS['admin']);
$ok($c === 405, 'a GET other than the download is refused (405)');
[$c] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . ea_uuid(), 'nosuchsession');
$ok($c === 302 || $c === 401 || $c === 403, 'the download needs a signed-in session (' . $c . ')');

// ============================================================ services
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'id="rem-services"') && str_contains($body, 'Refresh list') && str_contains($body, 'No list yet') && str_contains($body, 'Services: announced') && str_contains($body, 'Files: announced')
    && str_contains($body, 'Job results are kept for'), 'the tab opens with the context line, the capabilities announced and the empty service list');
[$c, $j] = $remCall('admin', 'svc_refresh', ['device_id' => $remDev['REM1']]);
$ok($c === 200 && !empty($j['job_id']), 'Refresh list (services): queued (' . $c . ')');
$jobRow = $remRows("SELECT * FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) ($j['job_id'] ?? '')) . "'")[0] ?? [];
$ok(($jobRow['type'] ?? '') === 'svc_list' && (int) ($jobRow['destructive'] ?? 1) === 0 && (int) $jobRow['created_by'] === 1, 'the job row: svc_list, not destructive, created by the administrator');
$ok($remAudit('Remote Tool Queued', '%on device ' . $remDev['REM1'] . ': list services') === 1, 'queuing the list is in the audit log');
[$c, $j] = $remCall('admin', 'svc_snapshot', ['device_id' => $remDev['REM1']]);
$ok($c === 200 && $j['state'] === 'queued' && $j['final'] === false && str_contains($j['html'], 'Queued, waiting for the device'), 'before the device answers the list says it is waiting');
$job = $remJobOf($remTok['REM1'], 'svc_list');
$ok($job !== null && ($job['signature'] ?? '') !== '', 'the device receives the signed svc_list job through /api/v1/agent_jobs');
$svcOut = json_encode(['v' => 1, 'platform' => 'linux', 'truncated' => false, 'count' => 5, 'services' => [
    ['name' => 'ssh.service', 'display' => 'OpenBSD Secure Shell server', 'state' => 'running', 'startup' => 'auto'],
    ['name' => 'cron.service', 'display' => 'Regular background program processing daemon', 'state' => 'stopped', 'startup' => 'disabled'],
    ['name' => 'rivetit-agent.service', 'display' => 'RivetIT endpoint agent', 'state' => 'running', 'startup' => 'auto'],
    ['name' => 'evil<b>x</b>', 'display' => '<img src=x onerror=alert(1)>', 'state' => 'running', 'startup' => 'auto'],
    ['name' => 'nginx.service', 'display' => '"quoted" & \'single\'', 'state' => 'failed', 'startup' => 'manual']]]);
[$c] = $remReport($remTok['REM1'], $job, 'succeeded', 0, $svcOut);
$ok($c === 200, 'the device reports the service list');
[$c, $j] = $remCall('admin', 'svc_snapshot', ['device_id' => $remDev['REM1']]);
$h = (string) ($j['html'] ?? '');
$ok($c === 200 && $j['state'] === 'succeeded' && $j['final'] === true && str_contains($h, 'ssh.service') && str_contains($h, 'OpenBSD Secure Shell server') && str_contains($h, 'rem-svc-table') && str_contains($j['summary'], '5 services'), 'the service list is rendered (' . $j['summary'] . ')');
$ok(!str_contains($h, '<img src=x') && !str_contains($h, '<b>x</b>') && str_contains($h, '&lt;img src=x onerror=alert(1)&gt;') && str_contains($h, 'evil&lt;b&gt;x&lt;/b&gt;'), 'hostile service names and display names are escaped');
$ok(str_contains($h, 'cannot be controlled from here') && substr_count($h, 'data-rem-svc="restart"') >= 3, 'a name outside the safe list gets no buttons, the others do');
$ok(str_contains($h, 'data-rem-svc="start" data-name="cron.service"') && !str_contains($h, 'data-rem-svc="start" data-name="ssh.service"') && str_contains($h, 'data-rem-svc="enable" data-name="cron.service"') && !str_contains($h, 'data-rem-svc="manual"'),
    'row buttons follow the state (start only a stopped service; enable a disabled one; no "manual" on Linux)');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'rem-svc-table') && str_contains($body, 'OpenBSD Secure Shell server') && !str_contains($body, '<img src=x'), 'the first paint of the tab already holds the latest list, escaped');
// restart: refused without confirm, queued with it
$before = (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'");
[$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => 'ssh.service', 'verb' => 'restart']);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'") === $before, 'restart without confirm: refused with Core\'s text, nothing queued');
foreach (['stop', 'disable'] as $v) {
    [$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => 'ssh.service', 'verb' => $v]);
    $ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required', "$v without confirm: refused");
}
[$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => 'ssh', 'verb' => 'restart', 'confirm' => '1']);
$ok($c === 200 && !empty($j['job_id']), 'restart with confirm (name "ssh" is normalised to ssh.service): queued');
$ctl = $remRows("SELECT * FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'")[0] ?? [];
$ok(($ctl['type'] ?? '') === 'svc_control' && (int) ($ctl['destructive'] ?? 0) === 1 && json_decode((string) $ctl['params_json'], true) === ['name' => 'ssh.service', 'action' => 'restart'], 'the control job: svc_control, destructive, params exactly name and action');
$ok($remAudit('Remote Tool Queued', '%restart ssh.service%') === 1, 'the restart is in the audit log with who and what');
[$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $j['job_id']]);
$ok($c === 200 && $j2['state'] === 'queued' && $j2['final'] === false && str_contains($j2['html'], 'carries it out at its next check-in'), 'job_result while queued: waiting text');
$job = $remJobOf($remTok['REM1'], 'svc_control');
$ok($job !== null && ($job['params']['name'] ?? '') === 'ssh.service' && ($job['params']['action'] ?? '') === 'restart', 'the device receives the signed svc_control job with its parameters');
$remReport($remTok['REM1'], $job, 'succeeded', 0, json_encode(['v' => 1, 'name' => 'ssh.service', 'action' => 'restart', 'before' => 'running', 'after' => 'running', 'startup' => 'auto', 'ok' => true, 'detail' => 'restarted']));
[$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $j['job_id']]);
$ok($j2['state'] === 'succeeded' && $j2['final'] === true && str_contains($j2['html'], 'ssh.service') && str_contains($j2['html'], 'Done') && str_contains($j2['html'], 'running to running'), 'job_result after the device reported: done, with before and after');
// the agent's own service: refused by Core, its text shown
$before = (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'");
foreach (['rivetit-agent', 'rivetit-agent.service'] as $nm) {
    [$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => $nm, 'verb' => 'stop', 'confirm' => '1']);
    $core = $remAct->controlService($admin, $remDev['REM1'], $nm, 'stop', true);
    $ok($c === 422 && ($j['code'] ?? '') === 'protected' && ($j['error'] ?? '') === $core->message && str_contains($j['error'], 'agent\'s own service'), "stop of the agent's own service ($nm): Core's refusal text, nothing queued");
}
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='svc_control'") === $before, 'nothing was queued for the agent\'s own service');
// the agent refuses it on the device (other critical service): the failure is shown, not hidden
[$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => 'systemd-journald', 'verb' => 'stop', 'confirm' => '1']);
$job = $remJobOf($remTok['REM1'], 'svc_control');
$remReport($remTok['REM1'], $job, 'failed', 1, json_encode(['v' => 1, 'name' => 'systemd-journald.service', 'action' => 'stop', 'before' => 'running', 'after' => 'running', 'ok' => false, 'detail' => 'refused_protected']));
[$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $j['job_id']]);
$ok($j2['state'] === 'failed' && str_contains($j2['html'], 'protected system service') && str_contains($j2['html'], 'Failed'), 'a service the agent refuses (refused_protected): the failure and the reason are shown');
// validation
foreach ([['name' => 'a b', 'verb' => 'stop', 'confirm' => '1'], ['name' => '../etc', 'verb' => 'start'], ['name' => 'ssh.service', 'verb' => 'explode', 'confirm' => '1'], ['name' => 'ssh.service', 'verb' => 'manual']] as $bad) {
    [$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1']] + $bad);
    $ok($c === 422 && ($j['error'] ?? '') !== '', 'invalid service request (' . $bad['name'] . ' ' . $bad['verb'] . '): 422 with Core\'s text');
}
// start/enable need no confirmation
[$c, $j] = $remCall('admin', 'svc_control', ['device_id' => $remDev['REM1'], 'name' => 'cron.service', 'verb' => 'start']);
$ok($c === 200 && (int) $one("SELECT destructive FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'") === 0, 'start needs no confirmation and is not destructive');
$q("UPDATE endpoint_agent_jobs SET state='cancelled', finished_at=UTC_TIMESTAMP() WHERE device_id={$remDev['REM1']} AND state='queued'");
// a device that did not announce the capability: Core answers unsupported, the tab explains
[$c, $j] = $remCall('admin', 'svc_refresh', ['device_id' => $remDev['REM2']]);
$ok($c === 409 && ($j['code'] ?? '') === 'unsupported', 'a device that did not announce remote:services: 409 unsupported from Core (' . ($j['error'] ?? '') . ')');
[$c, $body] = $page('admin', 'REM2');
$ok($tabDrawn($body) && str_contains($body, 'did not announce the service manager') && str_contains($body, 'did not announce the process manager') && !str_contains($body, 'id="rem-svc-refresh"') && !str_contains($body, 'id="rem-proc-refresh"'), 'REM2: the tab explains the missing capabilities and offers no Refresh');
// retired or revoked device: no tab, the handler refuses
$q("UPDATE endpoint_agent_devices SET retired_at=UTC_TIMESTAMP() WHERE device_id={$remDev['REM2']}");
[$c, $body] = $page('admin', 'REM2');
[$c2, $j] = $remCall('admin', 'svc_refresh', ['device_id' => $remDev['REM2']]);
$ok(!$tabDrawn($body) && $c2 === 409, 'retired device: no Remote tab and the handler answers 409 (' . $c2 . ')');
$q("UPDATE endpoint_agent_devices SET retired_at=NULL WHERE device_id={$remDev['REM2']}");

// ============================================================ processes
[$c, $j] = $remCall('admin', 'proc_refresh', ['device_id' => $remDev['REM1'], 'limit' => '50', 'sort' => 'mem']);
$ok($c === 200 && !empty($j['job_id']), 'Refresh list (processes) with limit 50, sort mem: queued');
$pj = $remRows("SELECT type, params_json FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'")[0] ?? [];
$ok(($pj['type'] ?? '') === 'proc_list' && json_decode((string) $pj['params_json'], true) === ['limit' => 50, 'sort' => 'mem'], 'the proc_list job carries limit 50 and sort mem');
[$c, $j] = $remCall('admin', 'proc_refresh', ['device_id' => $remDev['REM1'], 'limit' => '9999', 'sort' => 'name']);
$ok($c >= 400 && ($j['error'] ?? '') !== '', 'an invalid limit or sort is refused by Core (' . $c . ': ' . ($j['error'] ?? '') . ')');
$job = $remJobOf($remTok['REM1'], 'proc_list');
$procOut = json_encode(['v' => 1, 'sampled_ms' => 1000, 'count' => 4, 'truncated' => true, 'processes' => [
    ['pid' => 1, 'ppid' => 0, 'name' => 'systemd', 'user' => 'root', 'cpu_pct' => 0.1, 'mem_bytes' => 12000000, 'threads' => 1, 'started' => '2026-10-01T10:00:00Z'],
    ['pid' => 4242, 'ppid' => 1, 'name' => 'sleep', 'user' => 'alex', 'cpu_pct' => 71.5, 'mem_bytes' => 2097152, 'threads' => 1, 'started' => '2026-10-10T10:00:00Z'],
    ['pid' => 4300, 'ppid' => 1, 'name' => 'rivetit-agent', 'user' => 'root', 'cpu_pct' => 1.5, 'mem_bytes' => 40000000, 'threads' => 8, 'started' => '2026-10-01T10:00:00Z'],
    ['pid' => 4400, 'ppid' => 1, 'name' => '<script>alert(2)</script>', 'user' => '"><svg onload=alert(3)>', 'cpu_pct' => 'x', 'mem_bytes' => null, 'threads' => 2, 'started' => 'now']]]);
$remReport($remTok['REM1'], $job, 'succeeded', 0, $procOut);
[$c, $j] = $remCall('admin', 'proc_snapshot', ['device_id' => $remDev['REM1']]);
$h = (string) ($j['html'] ?? '');
$ok($j['state'] === 'succeeded' && str_contains($h, 'rem-proc-table') && str_contains($h, 'sleep') && str_contains($h, '71.5 %') && str_contains($h, '2 MB') && str_contains($h, 'data-rem-sort="cpu"') && str_contains($h, 'shortened the list'), 'the process list is rendered with CPU, memory, sortable headings and the "shortened" note');
$ok(!str_contains($h, '<script>alert(2)') && !str_contains($h, '<svg onload') && str_contains($h, '&lt;script&gt;alert(2)&lt;/script&gt;') && str_contains($h, 'no data'), 'hostile process and user names are escaped; a missing figure reads "no data"');
$ok(str_contains($h, 'Cannot be ended from here') && str_contains($h, 'data-rem-kill="1" data-pid="4242" data-name="sleep"'), 'a name outside the safe list has no End button; the others carry pid AND name');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'id="rem-proc-table"') && str_contains($body, 'aria-sort="none"'), 'the first paint of the tab holds the process list with sortable headings');
// kill
$before = (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='proc_kill'");
[$c, $j] = $remCall('admin', 'proc_kill', ['device_id' => $remDev['REM1'], 'pid' => '4242', 'name' => 'sleep']);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='proc_kill'") === $before, 'end process without confirm: refused, nothing queued');
foreach ([['pid' => '1', 'name' => 'systemd'], ['pid' => 'abc', 'name' => 'x'], ['pid' => '4242', 'name' => 'sleep;rm'], ['pid' => '4242', 'name' => ''], ['pid' => '99999999', 'name' => 'x']] as $bad) {
    [$c, $j] = $remCall('admin', 'proc_kill', ['device_id' => $remDev['REM1'], 'confirm' => '1'] + $bad);
    $core = $remAct->killProcess($admin, $remDev['REM1'], $bad['pid'], $bad['name'], false, true);
    $ok($c === 422 && ($j['error'] ?? '') === $core->message, 'end process ' . json_encode($bad) . ': Core\'s refusal "' . ($j['error'] ?? '?') . '"');
}
[$c, $j] = $remCall('admin', 'proc_kill', ['device_id' => $remDev['REM1'], 'pid' => '4242', 'name' => 'sleep', 'confirm' => '1']);
$ok($c === 200 && !empty($j['job_id']), 'end process with pid, name and confirm: queued');
$kj = $remRows("SELECT * FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'")[0] ?? [];
$ok(($kj['type'] ?? '') === 'proc_kill' && (int) ($kj['destructive'] ?? 0) === 1 && json_decode((string) $kj['params_json'], true) === ['pid' => 4242, 'name' => 'sleep', 'force' => false], 'the kill job: proc_kill, destructive, pid AND name, force false');
$ok($remAudit('Remote Tool Queued', '%end process 4242 (sleep)%') === 1, 'the kill is in the audit log');
$job = $remJobOf($remTok['REM1'], 'proc_kill');
$remReport($remTok['REM1'], $job, 'succeeded', 0, json_encode(['v' => 1, 'pid' => 4242, 'name' => 'sleep', 'signal' => 'TERM', 'ok' => true, 'detail' => 'terminated']));
[$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $j['job_id']]);
$ok($j2['state'] === 'succeeded' && str_contains($j2['html'], '4242') && str_contains($j2['html'], 'sleep') && str_contains($j2['html'], 'Done'), 'the result of the kill is shown');
[$c, $j] = $remCall('admin', 'proc_kill', ['device_id' => $remDev['REM1'], 'pid' => '4242', 'name' => 'sleep', 'confirm' => '1', 'force' => '1']);
$ok((string) $one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'") === json_encode(['pid' => 4242, 'name' => 'sleep', 'force' => true]) || json_decode((string) $one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'"), true)['force'] === true, 'force is sent only when the dialog\'s force box is ticked');
$remReport($remTok['REM1'], $remJobOf($remTok['REM1'], 'proc_kill'), 'failed', 1, json_encode(['v' => 1, 'pid' => 4242, 'name' => 'sleep', 'ok' => false, 'detail' => 'name_mismatch']));
[$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $j['job_id']]);
$ok($j2['state'] === 'failed' && str_contains($j2['html'], 'different name now'), 'name_mismatch from the agent: shown in plain words');
// the agent's own process and a protected one: the agent refuses, the refusal is shown
foreach ([['4300', 'rivetit-agent', 'refused_self', 'the agent itself'], ['1', 'systemd', 'refused_protected', 'protected system']] as [$pid, $nm, $detail, $needle]) {
    $q("UPDATE endpoint_agent_jobs SET state='cancelled', finished_at=UTC_TIMESTAMP() WHERE device_id={$remDev['REM1']} AND state IN ('queued','running')");
    $core = $remAct->killProcess($admin, $remDev['REM1'], $pid, $nm, false, true);
    if ($core->ok) {
        $jid = (string) $core->data['job_id'];
        $kjob = $remJobOf($remTok['REM1'], 'proc_kill');
        $remReport($remTok['REM1'], $kjob, 'failed', 1, json_encode(['v' => 1, 'pid' => (int) $pid, 'name' => $nm, 'ok' => false, 'detail' => $detail]));
        [$c, $j2] = $remCall('admin', 'job_result', ['device_id' => $remDev['REM1'], 'job_id' => $jid]);
        $ok($j2['state'] === 'failed' && str_contains($j2['html'], $needle), "pid $pid ($nm): the agent's refusal ($detail) is shown, not hidden");
    } else {
        $ok(str_contains($core->message, 'process id'), "pid $pid ($nm): refused by Core before queuing: " . $core->message);
    }
}

// ============================================================ files: switch off
[$c, $body] = $page('admin', 'REM1');
$riskH = nullable_htmlentities(RemoteSettings::FILE_TRANSFER_RISK);
$ok(str_contains($body, 'id="rem-files"') && str_contains($body, $riskH) && str_contains($body, 'File transfer is switched off') && !str_contains($body, 'id="rem-dl-form"') && !str_contains($body, 'rmmRemUpload'), 'filetransfer off: the Files card explains it with the verbatim risk text and offers nothing');
$ok(str_contains($body, '/admin/settings_endpoint_agent.php'), 'filetransfer off: the administrator gets a link to Administration');
[$c, $body] = $page('tech', 'REM1');
$ok(str_contains($body, $riskH) && !str_contains($body, 'href="/admin/settings_endpoint_agent.php"') === true || str_contains($body, 'Only an administrator can switch it on'), 'filetransfer off: a technician is told only an administrator can switch it on');
foreach (['xfer_download' => ['path' => '/srv/data/x.txt'], 'xfer_list' => [], 'xfer_delete' => ['transfer_id' => ea_uuid()], 'xfer_upload' => ['path' => '/srv/data/x', 'confirm' => '1']] as $a => $f) {
    [$c, $j] = $remCall('admin', $a, ['device_id' => $remDev['REM1']] + $f);
    $ok($c === 409 && ($j['code'] ?? '') === 'feature_disabled' && str_contains((string) $j['error'], 'File transfer is switched off'), "filetransfer off: $a is refused with the switched-off text ($c)");
}
// turning it on needs the acknowledgement (Core)
$r = $remAct->updateSettings($admin, ['features' => ['filetransfer' => true]]);
$ok(!$r->ok && $r->code === 'confirmation_required' && str_contains($r->message, 'File transfer lets technicians'), 'filetransfer cannot be switched on without the acknowledgement; the risk text is Core\'s');
$r = $remAct->updateSettings($admin, ['features' => ['filetransfer' => true], 'accept_filetransfer_risk' => true, 'transfer_allow' => ['linux' => ['/srv/data'], 'windows' => ['C:\\Data\\Share']]]);
$fresh();
$ok($r->ok && $rmm->featureOn('filetransfer') && $rmm->remoteSettings()->allowRootsFor('linux') === ['/srv/data'], 'filetransfer on with the acknowledgement and one allowed folder (/srv/data)');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'id="rem-dl-form"') && str_contains($body, 'data-rem-root="/srv/data/"') && str_contains($body, 'Allowed folders on this Linux device') && !str_contains($body, 'C:\\Data'), 'filetransfer on: the allowed folders of the device\'s platform are clickable starting points; the Windows folder is not shown');
$ok(str_contains($body, 'id="rmmRemUpload"') && str_contains($body, 'Send a file to the device') && str_contains($body, 'can be a program that runs later') && str_contains($body, 'Largest file:'), 'filetransfer on: the administrator gets the upload dialog with the program warning and the size cap');
$ok(str_contains($body, 'No transfers yet'), 'filetransfer on: an empty transfer list says so');

// ============================================================ files: download
$data = random_bytes(70000);
$sha = hash('sha256', $data);
[$c, $j] = $remCall('admin', 'xfer_download', ['device_id' => $remDev['REM1'], 'path' => '/srv/data/report.txt']);
$ok($c === 200 && !empty($j['transfer_id']) && !empty($j['job_id']), 'download request for an allowed path: queued with a transfer id (' . $c . ')');
$tid = (string) ($j['transfer_id'] ?? '');
$ok($remAudit('File Download Requested', '%report.txt%') === 1, 'the request is in the audit log');
[$c, $j] = $remCall('admin', 'xfer_list', ['device_id' => $remDev['REM1']]);
$ok($c === 200 && $j['pending'] === true && $j['items'][0]['state'] === 'queued' && str_contains($j['html'], 'report.txt') && str_contains($j['html'], 'Waiting for the device'), 'the ledger shows the transfer as waiting for the device');
$job = $remJobOf($remTok['REM1'], 'file_get');
$tt = (string) ($job['params']['token'] ?? '');
$ok($job !== null && ($job['params']['transfer_id'] ?? '') === $tid && strlen($tt) === 64 && ($job['params']['path'] ?? '') === '/srv/data/report.txt', 'the device receives the signed file_get job with the transfer id and the one-time token');
[$c] = http('POST', "/api/v1/agent_transfer?transfer_id=$tid&offset=0", $remTok['REM1'], substr($data, 0, 65536), ['X-Transfer-Token: ' . $tt]);
$ok($c === 200, 'the device sends the first chunk through /api/v1/agent_transfer');
[$c, $j] = $remCall('admin', 'xfer_list', ['device_id' => $remDev['REM1']]);
$ok($j['items'][0]['state'] === 'receiving' && $j['pending'] === true && str_contains($j['html'], 'Transferring'), 'mid-transfer the ledger says "Transferring"');
[$c, , $r] = http('POST', "/api/v1/agent_transfer?transfer_id=$tid&offset=65536", $remTok['REM1'], substr($data, 65536), ['X-Transfer-Token: ' . $tt, 'X-Transfer-Last: 1', 'X-Transfer-Sha256: ' . $sha]);
$ok($c === 200, 'the device sends the last chunk with the whole-file SHA-256 (' . $c . ')');
$remReport($remTok['REM1'], $job, 'succeeded', 0, json_encode(['v' => 1, 'transfer_id' => $tid, 'bytes' => strlen($data), 'sha256' => $sha]));
[$c, $j] = $remCall('admin', 'xfer_list', ['device_id' => $remDev['REM1']]);
$ok($j['items'][0]['state'] === 'complete' && $j['items'][0]['sha256'] === $sha && $j['items'][0]['size_bytes'] === strlen($data) && $j['pending'] === false, 'complete: the ledger holds the SHA-256 the server verified and the size');
$ok(str_contains($j['html'], $sha) && str_contains($j['html'], 'report.txt') && str_contains($j['html'], 'action=download&amp;device_id=' . $remDev['REM1'] . '&amp;transfer_id=' . $tid) && str_contains($j['html'], '68.4 KB'), 'the ledger shows the hash, the size and the Download link');
[$c, $body, $hd] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $tid, $SS['admin']);
$ok($c === 200 && $body === $data && hash('sha256', $body) === $sha, 'the download endpoint returns exactly the bytes the device sent');
$ok(preg_match('/^content-disposition:\s*attachment;\s*filename="report\.txt"/mi', $hd) === 1 && preg_match('/^x-content-type-options:\s*nosniff/mi', $hd) === 1 && preg_match('/^content-type:\s*application\/octet-stream/mi', $hd) === 1
    && preg_match('/^content-length:\s*70000/mi', $hd) === 1 && preg_match('/^x-transfer-sha256:\s*' . $sha . '/mi', $hd) === 1, 'download headers: attachment with the sanitised name, nosniff, octet-stream, length and hash');
$ok($remAudit('File Downloaded', '%' . $tid . '%') === 1, 'the fetch is audited');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, $sha) && str_contains($body, 'report.txt') && !str_contains($body, $tt) , 'the tab shows the verified hash and no transfer token anywhere');
[$c, $body] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $tid, $SS['viewer']);
$ok($c === 403, 'a viewer cannot fetch the file (' . $c . ')');
[$c, $body] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $tid, $SS['deptb']);
$ok($c === 404 && $body === $raw1, 'another client\'s user gets the same 404 for the download');
[$c, $body] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . ea_uuid(), $SS['admin']);
$ok($c === 404, 'an unknown transfer id: 404');
$tech = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $tid, $SS['tech']);
$ok($tech[0] === 200 && $tech[1] === $data, 'a technician with the ability can fetch it too');
// refusals: outside the allowed folders, never-transfer, traversal, relative: Core's text
$before = (int) $one("SELECT COUNT(*) FROM endpoint_agent_transfers");
foreach (['/etc/hosts', '/etc/shadow', '/srv/data/../../etc/passwd', 'relative/file.txt', '/srv/data2/x.txt'] as $bad) {
    [$c, $j] = $remCall('admin', 'xfer_download', ['device_id' => $remDev['REM1'], 'path' => $bad]);
    $core = $remAct->requestDownload($admin, $remDev['REM1'], $bad);
    $ok($c >= 400 && !$core->ok && ($j['error'] ?? '') === $core->message && $core->message !== '', "download of $bad: refused with Core's text \"" . ($j['error'] ?? '?') . '"');
}
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_transfers") === $before, 'no transfer row was created by the refused paths');
// delete the stored copy
[$c, $j] = $remCall('admin', 'xfer_delete', ['device_id' => $remDev['REM1'], 'transfer_id' => $tid]);
$ok($c === 200, 'delete the stored copy (' . $c . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $tid, $SS['admin']);
$ok($c === 409, 'after the delete the file is gone: 409 not available');
$ok($remAudit('File Transfer Deleted', '%' . $tid . '%') === 1, 'the delete is audited');
[$c, $j] = $remCall('admin', 'xfer_list', ['device_id' => $remDev['REM1']]);
$ok(str_contains($j['html'], 'Deleted') && !str_contains($j['html'], 'action=download&amp;device_id'), 'the ledger shows the transfer as deleted, with no Download link');

// ============================================================ files: upload (administrators only)
$up = random_bytes(2000);
$upSha = hash('sha256', $up);
[$c, $j] = $remUpload($wb, $SS['tech'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1'], $up);
$core = $remAct->createUpload(new RmmPrincipal($USER['tech'], 'tech'), $remDev['REM1'], '/srv/data/up.bin', 2000, $upSha, false, true);
$ok($c === 403 && ($j['error'] ?? '') === $core->message && !$core->ok, 'upload by a non-administrator: refused with Core\'s text (' . $c . ': ' . ($j['error'] ?? '') . ')');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_transfers WHERE direction='upload'") === 0, 'upload by a non-administrator: no transfer row');
$ok($remAudit('Remote Tool Denied', '%file upload%') >= 1, 'the refused upload is audited as denied');
[$c, $j] = $remUpload($wb, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin'], $up);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_transfers WHERE direction='upload'") === 0, 'upload without the confirmation: refused, nothing stored');
[$c, $j] = $remUpload($wb, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1'], null);
$ok($c === 422, 'upload without a file: 422 (' . ($j['error'] ?? '') . ')');
[$c, $j] = $remUpload($wb, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/etc/cron.d/evil', 'confirm' => '1'], $up);
$ok($c >= 400 && ($j['error'] ?? '') !== '', 'upload to a path outside the allowed folders: refused with Core\'s text (' . ($j['error'] ?? '') . ')');
// the size cap
$remAct->updateSettings($admin, ['limits' => ['transfer_max_bytes' => 1024]]);
$fresh();
[$c, $j] = $remUpload($wb, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1'], $up);
$ok($c >= 400 && ($j['error'] ?? '') !== '' && (int) $one("SELECT COUNT(*) FROM endpoint_agent_transfers WHERE direction='upload'") === 0, 'upload larger than transfer_max_bytes: refused with Core\'s text, nothing stored (' . $c . ': ' . ($j['error'] ?? '') . ')');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'Largest file: 1 KB') && str_contains($body, 'data-upload-max="1024"'), 'the dialog shows the administrator\'s cap (1 KB) and the script receives it');
$remAct->updateSettings($admin, ['limits' => ['transfer_max_bytes' => 52428800]]);
$fresh();
// a good upload
[$c, $j] = $remUpload($wb, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1', 'overwrite' => '1'], $up, 'my upload (1).bin');
$ok($c === 200 && ($j['sha256'] ?? '') === $upSha && ($j['size_bytes'] ?? 0) === 2000 && !empty($j['transfer_id']) && !empty($j['job_id']), 'upload by the administrator with the confirmation: accepted, the resulting SHA-256 is returned (' . $c . ')');
$utid = (string) ($j['transfer_id'] ?? '');
$ur = $remRows("SELECT * FROM endpoint_agent_transfers WHERE transfer_id='" . $esc($utid) . "'")[0] ?? [];
$ok(($ur['direction'] ?? '') === 'upload' && ($ur['sha256'] ?? '') === $upSha && (int) ($ur['size_bytes'] ?? 0) === 2000 && ($ur['remote_path'] ?? '') === '/srv/data/up.bin', 'the ledger row: upload, size and SHA-256 of the bytes, the target path');
$ok($remAudit('File Upload Declared', '%' . $upSha . '%') === 1 && $remAudit('File Upload Queued', '%' . $utid . '%') === 1, 'declaring and queuing the upload are audited');
$job = $remJobOf($remTok['REM1'], 'file_put');
$ut = (string) ($job['params']['token'] ?? '');
$ok($job !== null && (int) ($job['params']['size'] ?? 0) === 2000 && ($job['params']['sha256'] ?? '') === $upSha && ($job['params']['overwrite'] ?? false) === true && strlen($ut) === 64, 'the device receives the signed file_put job: size, sha256, overwrite and the one-time token');
[$c, $h2, , $raw] = http('GET', "/api/v1/agent_transfer?transfer_id=$utid&offset=0&length=2000", $remTok['REM1'], null, ['X-Transfer-Token: ' . $ut]);
$ok($c === 200 && $raw === $up && ($h2['x-transfer-sha256'][0] ?? '') === $upSha, 'the device GETs the content through /api/v1/agent_transfer: exactly the bytes sent, with the matching SHA-256');
$remReport($remTok['REM1'], $job, 'succeeded', 0, json_encode(['v' => 1, 'transfer_id' => $utid, 'path' => '/srv/data/up.bin', 'bytes' => 2000, 'sha256' => $upSha]));
[$c, $j] = $remCall('admin', 'xfer_list', ['device_id' => $remDev['REM1']]);
$row = array_values(array_filter($j['items'], fn ($t) => $t['transfer_id'] === $utid))[0] ?? [];
$ok(($row['state'] ?? '') === 'complete' && str_contains($j['html'], 'To the device') && str_contains($j['html'], $upSha), 'the ledger shows the upload as complete with its hash');
[$c, $body] = $page('admin', 'REM1');
$ok(!str_contains($body, $ut) && !str_contains($body, $tt), 'no transfer token appears in the page body');
[$c, $b] = web($wb, 'GET', '/agent/post/rmm_remote_tools.php?action=download&device_id=' . $remDev['REM1'] . '&transfer_id=' . $utid, $SS['admin']);
$ok($c === 409, 'an upload cannot be fetched back through the download endpoint (409)');

// the PHP limits are read and refused politely (a second server with tiny limits)
$web2 = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir", 'upload_max_filesize=1K', 'post_max_size=200K']);
$wb2 = "http://127.0.0.1:{$web2['port']}";
[$c, $j] = $remUpload($wb2, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1'], random_bytes(4000));
$ok($c === 413 && str_contains((string) ($j['error'] ?? ''), 'upload_max_filesize'), 'a file over upload_max_filesize: 413 with a plain explanation (' . ($j['error'] ?? '') . ')');
[$c, $j] = $remUpload($wb2, $SS['admin'], ['device_id' => $remDev['REM1'], 'path' => '/srv/data/up.bin', 'confirm' => '1'], random_bytes(300000));
$ok($c === 413 && str_contains((string) ($j['error'] ?? ''), 'post_max_size'), 'a request over post_max_size: 413 with a plain explanation, not "invalid CSRF token" (' . ($j['error'] ?? '') . ')');
[$c, $body] = web($wb2, 'GET', '/agent/asset_details.php?asset_id=' . $remAsset['REM1'], $SS['admin']);
$ok(str_contains($body, 'Largest file: 1 KB') && str_contains($body, 'upload_max_filesize') && str_contains($body, 'data-upload-max="1024"'), 'the dialog names the PHP limit that applies (upload_max_filesize 1K) and gives the script the effective cap');

// ============================================================ Mesh buttons
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'id="rem-mesh"') && str_contains($body, 'not mapped to a MeshCentral node') && !str_contains($body, 'data-rem-mesh='), 'Mesh configured but the device is not mapped: an explanation, no buttons');
$rmm->technician()->setMeshNode($admin, $remDev['REM1'], 'node//' . str_repeat('A1', 12));
$fresh();
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'data-rem-mesh="desktop"') && str_contains($body, 'data-rem-mesh="terminal"') && str_contains($body, 'data-rem-mesh="files"'), 'Mesh mapped: Desktop, Terminal and Files buttons for the administrator');
[$c, $body] = $page('tech', 'REM1');
$ok(str_contains($body, 'data-rem-mesh="terminal"') && str_contains($body, 'data-rem-mesh="files"'), 'the technician with remote.launch and remote.manage gets the same buttons');
$meshHealth = sys_get_temp_dir() . '/rem_mesh_' . bin2hex(random_bytes(3)) . '.php';
file_put_contents($meshHealth, '<?php http_response_code(200); echo "ok";');
$mesh = ea_start_php($meshHealth, ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1']);
register_shutdown_function(fn () => @unlink($meshHealth));
$loginKey = \RivetCore\Rmm\Mesh\MeshCookie::newLoginKey();
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'http://127.0.0.1:' . $mesh['port'], 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting($loginKey), 'mesh_account_template' => 'rivetit-support']);
$fresh();
foreach (['desktop' => 11, 'terminal' => 12, 'files' => 13] as $view => $mode) {
    [$c, $j] = $remCall('admin', 'mesh', ['device_id' => $remDev['REM1'], 'view' => $view]);
    $ok($c === 200 && !empty($j['url']) && str_contains($j['url'], 'viewmode=' . $mode) && !empty($j['session_id']), "Mesh $view: the handler returns the launch URL with viewmode=$mode (" . $c . ' ' . ($j['error'] ?? '') . ')');
}
$ok($remAudit('Remote Session', '%terminal session%') === 1, 'the terminal launch is audited');
[$c, $j] = $remCall('admin', 'mesh', ['device_id' => $remDev['REM1'], 'view' => 'shell']);
$ok($c === 422, 'an unknown Mesh view is refused (422)');
[$c, $j] = $remCall('remoteonly', 'mesh', ['device_id' => $remDev['REM1'], 'view' => 'terminal']);
$core = $rmm->technician()->launchRemote(new RmmPrincipal($USER['remoteonly'], 'remoteonly'), $remDev['REM1'], false, '127.0.0.1', 'test', 'terminal');
$ok($c === 403 && ($j['error'] ?? '') === $core->message, 'a remote-only role may not open the terminal (needs remote.manage too): Core\'s refusal text');
[$c, $j] = $remCall('viewer', 'mesh', ['device_id' => $remDev['REM1'], 'view' => 'desktop']);
$ok($c === 403, 'a viewer may not open a Mesh session (403)');
// an offline device: the launch asks first
$q("UPDATE endpoint_agent_devices SET last_checkin_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE device_id={$remDev['REM1']}");
[$c, $j] = $remCall('admin', 'mesh', ['device_id' => $remDev['REM1'], 'view' => 'terminal']);
$ok($c === 409 && ($j['code'] ?? '') === 'device_offline', 'Mesh to an offline device: 409 device_offline (the page offers "Launch anyway")');
[$c, $j] = $remCall('admin', 'mesh', ['device_id' => $remDev['REM1'], 'view' => 'terminal', 'force' => '1']);
$ok($c === 200 && !empty($j['url']), 'Mesh forced while offline: launched');
[$c, $body] = $page('admin', 'REM1');
$ok(str_contains($body, 'The device is offline') , 'the offline device is flagged on the tab');
// ---- RivetCore 1.0.0-rc.17: the launch URL carries MeshCentral's hide parameter (default 31), set from the Mesh card; the tab is opened inside the click
$hideQ = function (string $view, bool $force = true) use ($remCall, $remDev): array {
    [$c, $j] = $remCall('admin', 'mesh', ['device_id' => $remDev['REM1'], 'view' => $view] + ($force ? ['force' => '1'] : []));
    parse_str((string) parse_url((string) ($j['url'] ?? ''), PHP_URL_QUERY), $qs);

    return [$c, $qs, (string) ($j['url'] ?? ''), $j];
};
foreach (['desktop' => 11, 'terminal' => 12, 'files' => 13] as $view => $mode) {
    [$c, $qs, $url] = $hideQ($view);
    $ok($c === 200 && ($qs['hide'] ?? null) === '31' && ($qs['viewmode'] ?? null) === (string) $mode && isset($qs['login'], $qs['gotonode']) && str_ends_with($url, '&viewmode=' . $mode . '&hide=31'), "Mesh $view: the default launch URL ends with &viewmode=$mode&hide=31");
}
[, , , $j31] = $hideQ('desktop');
$ok(isset($j31['success'], $j31['url'], $j31['session_id']) || isset($j31['url'], $j31['session_id']), 'the launch answer keeps its members (url, session_id)');
[, $mf] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $SS['admin']);
$mcard = substr($mf, (int) strpos($mf, 'id="mesh"'), 60000);
$ok(str_contains($mcard, 'name="mesh_hide"') && str_contains($mcard, 'name="mesh_hide_tools"') && str_contains($mcard, 'name="mesh_hide_present"') && str_contains($mcard, 'Hide MeshCentral interface elements')
    && str_contains($mcard, '31 = show only the remote screen') && str_contains($mcard, 'click <em>Connect</em>') && preg_match('/name="mesh_hide"[^>]*value="31"/', $mcard) === 1, 'the Mesh card has the hide field (31) with its explanation and the Connect note');
$hideForm = fn (): array => ['save_mesh_settings' => '1', 'mesh_url' => 'http://127.0.0.1:' . $mesh['port'], 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support', 'mesh_policy' => 'unattended', 'mesh_token_ttl_s' => '300',
    'mesh_enabled' => '1', 'mesh_hide_present' => '1', 'mesh_hide' => '31', 'mesh_hide_tools' => ''];
$hideSave = function (array $over) use ($hideForm, $REF1, $wb, $SS, $fresh, $rmm): void { $__r = web($wb, 'POST', '/admin/post.php', $SS['admin'], array_merge($hideForm(), $over, ['csrf_token' => 'csrftok1']), $REF1); if (getenv('HIDE_DEBUG')) { echo json_encode(array_map(fn ($x) => is_string($x) ? substr($x, 0, 400) : $x, $__r)) . "\n"; } $fresh(); $rmm->meshAgentSettings()->refresh(); };
$hideSave(['mesh_hide' => '16', 'mesh_hide_tools' => '0']);
$ok($rmm->meshAgentSettings()->get()['hide'] === 16 && $rmm->meshAgentSettings()->get()['hide_tools'] === 0, 'the form saves the hide values (16, tools 0)');
[$c, $qs] = $hideQ('desktop');
$ok(($qs['hide'] ?? null) === '16', 'desktop launch: hide=16 after the change');
[$c, $qs, $url] = $hideQ('terminal');
$ok($c === 200 && !isset($qs['hide']) && str_ends_with($url, '&viewmode=12'), 'terminal launch with the tools value 0: no hide parameter at all');
$hideSave(['mesh_hide' => '0', 'mesh_hide_tools' => '']);
[, $qs, $url] = $hideQ('desktop');
$ok(!isset($qs['hide']) && str_ends_with($url, '&viewmode=11'), 'hide 0: the launch URL is the old one (no hide parameter)');
$hideSave(['mesh_hide' => '300']);
$ok($rmm->meshAgentSettings()->get()['hide'] === 0, 'an out-of-range value is refused and nothing is changed');
$hideSave(['mesh_hide' => 'abc', 'mesh_hide_tools' => '9']);
$ok($rmm->meshAgentSettings()->get()['hide'] === 0 && $rmm->meshAgentSettings()->get()['hide_tools'] === null, 'a non-number is refused and nothing is saved with it');
$hideSave(['mesh_hide' => '31', 'mesh_hide_tools' => '']);
[, $qs] = $hideQ('files');
$ok(($qs['hide'] ?? null) === '31', 'restored: the default again (files follow the desktop value when the tools value is empty)');
[$c] = web($wb, 'POST', '/admin/post.php', $SS['tech'], array_merge($hideForm(), ['mesh_hide' => '0', 'csrf_token' => 'csrftok1']), $REF1);
$fresh(); $rmm->meshAgentSettings()->refresh();
$ok($rmm->meshAgentSettings()->get()['hide'] === 31, 'a technician cannot change the hide value');
// the click opens the tab first: every page that launches a session links the helper, and no script opens the tab after the asynchronous answer
[, $ab] = $page('admin', 'REM1');
$ok(str_contains($ab, '/js/rmm_launch.js?v='), 'the asset page links js/rmm_launch.js');
$ok(str_contains($ab, '/js/rmm_launch.js') && strpos($ab, '/js/rmm_launch.js') < strpos($ab, '/js/rmm_remote.js'), 'js/rmm_launch.js loads before js/rmm_remote.js');
foreach (['agent/rmm_agent_device.php', 'agent/rmm_asset.php'] as $__f) {
    // (the legacy device page redirects to the asset page for a device that has an asset, so its flag is checked in the source)
    $ok(str_contains((string) file_get_contents("$root/$__f"), '$rmm_launch_scripts = true;'), "$__f asks the footer to link js/rmm_launch.js");
}
foreach (['agent/rmm_agent_device.php', 'agent/rmm_asset.php', 'js/rmm_panel.js', 'js/rmm_remote.js'] as $__f) {
    $src = (string) file_get_contents("$root/$__f");
    $ok(!preg_match('/window\.open\(\s*(d2?\.url|d\.url)/', $src) && str_contains($src, 'RivetRmmLaunch') && preg_match('/\.begin\(\)[\s\S]*?(post|fetch)\(/', $src) === 1, "$__f: no window.open after the asynchronous answer; the tab is opened first (RivetRmmLaunch.begin())");
}
$launchJs = (string) file_get_contents("$root/js/rmm_launch.js");
$ok(str_contains($launchJs, "window.open('about:blank', '_blank')") && str_contains($launchJs, 'w.opener = null') && str_contains($launchJs, 'location.replace') && str_contains($launchJs, "rel = 'noopener noreferrer'") && str_contains($launchJs, 'Open remote session'),
    'js/rmm_launch.js: a blank new tab, opener cleared, navigated on the answer, a fallback link');
// no secret in any page of this device
[$c, $body] = $page('admin', 'REM1');
$ok(!str_contains($body, $loginKey) && !str_contains($body, 'login=') && !str_contains($body, 'mesh_login_key') && !str_contains($body, 'viewmode='), 'no Mesh login key, token or launch URL is rendered into the page');
[$c, $body] = $page('admin', 'REM2');
$ok(!str_contains($body, $loginKey), 'no Mesh login key on the other device either');

// ============================================================ accessibility and CSP of the markup
[$c, $body] = $page('admin', 'REM1');
$tabHtml = substr($body, (int) strpos($body, 'id="rmm-pane-remote"'));
$tabHtml = substr($tabHtml, 0, ($e = strpos($tabHtml, 'id="rmm-pane-logs"')) !== false ? $e : (strpos($tabHtml, 'id="rmmRebootModal"') ?: strlen($tabHtml)));
$ok(strlen($tabHtml) > 5000 && str_contains($tabHtml, 'rmmRemConfirm'), 'the Remote pane was cut out of the page for the markup checks (' . strlen($tabHtml) . ' bytes)');
$ok(!preg_match('/\son[a-z]+\s*=\s*["\']/i', $tabHtml) && !str_contains($tabHtml, 'javascript:'), 'the Remote tab has no inline handler and no javascript: URL');
$ok(str_contains($tabHtml, 'aria-live="polite"') && str_contains($tabHtml, 'role="region" aria-label="Services of the device"') && str_contains($tabHtml, '<caption class="visually-hidden">') && str_contains($tabHtml, 'scope="col"'), 'live regions, a labelled scroll region, a caption and column scopes are present');
$ids = [];
preg_match_all('/<(?:input|select|textarea)\b[^>]*\sid="([^"]+)"[^>]*>/', $tabHtml, $m);
$unlabeled = [];
foreach ($m[1] as $id) {
    if (!preg_match('/for="' . preg_quote($id, '/') . '"/', $tabHtml) && !preg_match('/id="' . preg_quote($id, '/') . '"[^>]*aria-label=/', $tabHtml)) {
        $unlabeled[] = $id;
    }
}
$ok($unlabeled === [], 'every input, select and textarea in the tab has a label' . ($unlabeled ? ': ' . implode(',', $unlabeled) : ''));

// ============================================================ put everything back
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 0 WHERE company_id = 1");
$q("DELETE FROM user_role_permissions WHERE user_role_id IN (3, 5) AND module_id = 6");
rivetRmmForgetAccess();
$r = $remAct->updateSettings($admin, ['features' => ['remote_tools' => false, 'filetransfer' => false], 'transfer_allow' => ['linux' => [], 'windows' => []], 'limits' => ['transfer_max_bytes' => 52428800]]);
$fresh();
$ok($r->ok && !$rmm->featureOn('remote_tools') && !$rmm->featureOn('filetransfer'), 'the sub-switches are back off at the end of the fragment');
$rmm->settings()->set(['mesh_url' => 'https://mesh.example.test']);
$fresh();
$q("UPDATE endpoint_agent_jobs SET state='cancelled', finished_at=UTC_TIMESTAMP() WHERE state IN ('queued','running')");
foreach (glob($root . '/backups/endpoint-transfer/xfer_*.bin') ?: [] as $f) { @unlink($f); }
// the pre-existing Remote Connect handler (vendor RMM links) is untouched by this tab: the tab has its own handler
$ok(is_file($root . '/agent/post/rmm_remote.php') && str_contains((string) file_get_contents($root . '/agent/post/rmm_remote.php'), 'link_id') && !str_contains((string) file_get_contents($root . '/agent/post/rmm_remote.php'), 'xfer_download')
    && is_file($root . '/agent/post/rmm_remote_tools.php'), 'agent/post/rmm_remote.php is still the Remote Connect handler; the Remote tab uses agent/post/rmm_remote_tools.php');
