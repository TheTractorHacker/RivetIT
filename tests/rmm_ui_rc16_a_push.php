<?php
/*
 * Fragment of tests/rmm_ui.php (RivetCore 1.0.0-rc.16 UI, shipped in RivetIT 26.10.42): pushing the MeshCentral agent to enrolled devices. Shares that file's variables.
 *
 *   1. The device page (Remote access card), the Remote tab and the legacy device page: Install / Remove MeshCentral agent, shown from Core's detail()['mesh_install'], the
 *      remote-tools switch and rmm.remote.manage; the confirm dialog; agent/post/rmm_mesh_push.php (install, idempotent states, force, uninstall with its explicit confirm,
 *      the job result in the job history); a real device simulated through the real device endpoints (it fetches the signed job, reports, checks in).
 *   2. Agent Fleet: the bulk dialog, Core's first unconfirmed answer (device_count), the counts, the refusals (approval_required, too_many), client scoping.
 *   3. The module off and the switch off: nothing drawn, nothing linked, the handler refuses, zero RMM statements for the builders.
 *
 * The module is ON here and ends ON; the Mesh settings and the remote-tools switch are put back.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

$pA = $rmm->remoteActions();
$pPush = function (string $who, string $action, array $f = []) use ($wb, $SS): array {
    [$c, $body] = web($wb, 'POST', '/agent/post/rmm_mesh_push.php', $SS[$who], $f + ['action' => $action, 'csrf_token' => 'csrftok1']);

    return [$c, json_decode($body, true) ?: [], $body];
};
$pFresh = function () use ($rmm): void { $rmm->meshAgentSettings()->refresh(); $rmm->settings()->refresh(); $rmm->state()->forget(); };
$pJobs = function (string $tok): array { [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok); return $c === 200 ? ($j['jobs'] ?? []) : []; };
$pReport = fn (string $tok, array $job, string $state, int $exit, string $out) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => $state, 'exit_code' => $exit, 'output' => $out]);
$pCount = fn (string $type, ?int $dev = null) => (int) $one("SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type='" . $esc($type) . "'" . ($dev === null ? '' : " AND device_id=$dev"));

$pGroup = bin2hex(random_bytes(48));   // a random placeholder: no real meshid in this repository
$pRawU = random_bytes(48);
$pGroupUrl = strtr(base64_encode($pRawU), ['+' => '@', '/' => '$']);
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test']);
$pFresh();

// ---- devices of this fragment (all in a tag of their own so the bulk calls never reach the rest of the fleet)
$pDev = []; $pTok = []; $pAsset = [];
$pCapsAll = ['job:collect', 'job:shell', 'job:svc_list', 'job:mesh_install', 'job:mesh_uninstall', 'mesh:nodeid', 'remote:services'];
$pTokA = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'rc16 push A')->data['token'];
$pTokB = (string) $rmm->technician()->createToken($admin, 2, 0, 'stable', 24, 50, 'rc16 push B')->data['token'];
foreach (['PUSH1' => [1, $pTokA, 'linux', $pCapsAll, true], 'PUSH2' => [1, $pTokA, 'linux', ['job:collect', 'job:shell'], true], 'PUSHW' => [1, $pTokA, 'windows', array_merge(array_values(array_diff($pCapsAll, ['job:shell'])), ['job:powershell']), true],
    'PUSHB' => [2, $pTokB, 'linux', $pCapsAll, true], 'PUSHL' => [1, $pTokA, 'linux', $pCapsAll, false]] as $n => [$client, $tk, $os, $caps, $withAsset]) {
    if ($withAsset) {
        $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
        $pAsset[$n] = (int) $db->insert_id;
    }
    [$c, , $j] = ea_enroll($tk, ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
    $pDev[$n] = (int) ($j['device_id'] ?? 0);
    $pTok[$n] = (string) ($j['device_token'] ?? '');
    $ok($c === 201 && $pDev[$n] > 0, "$n enrolled through the real endpoint");
    [$c] = ea_checkin($pTok[$n], ['capabilities' => $caps]);
    $ok($c === 200, "$n announced " . count($caps) . ' capabilities');
}
$A += $pAsset;
$q("UPDATE endpoint_agent_devices SET asset_id=NULL, link_state='linked', match_reason='' WHERE device_id=" . $pDev['PUSHL']);   // a linked device with no asset page: the legacy device page keeps it
$rmm->inventory()->createTag($admin, ['name' => 'rc16push']);
foreach (['PUSH1', 'PUSH2', 'PUSHW'] as $n) { $rmm->inventory()->tagDevice($admin, $pDev[$n], 'rc16push'); }
$pTagId = (int) $one("SELECT tag_id FROM rmm_tags WHERE name='rc16push'");
$ok($pTagId > 0, 'the rc16push tag exists (id ' . $pTagId . ')');

$pPage = fn (string $who, string $n) => web($wb, 'GET', '/agent/asset_details.php?asset_id=' . $pAsset[$n], $SS[$who]);
$pLegacy = fn (string $who) => web($wb, 'GET', '/agent/rmm_agent_device.php?device_id=' . $pDev['PUSHL'], $SS[$who]);
$pFleet = fn (string $who, string $qs = '') => web($wb, 'GET', '/agent/rmm_fleet.php' . $qs, $SS[$who]);
$pInstallBtn = fn (string $body) => substr_count($body, 'data-mesh-push-action="install"');
$pRemoveBtn = fn (string $body) => substr_count($body, 'data-mesh-push-action="uninstall"');
foreach ([3, 5] as $role) { $q("INSERT INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2"); }   // the asset page needs the Assets module for these roles; removed below
rivetRmmForgetAccess();
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();

// ============================================================ nothing is offered until Core says the install is available
$pAct = $rmm->remoteActions();
$ok(!$rmm->featureOn('remote_tools'), 'the remote_tools sub-switch is off at the start of the fragment');
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($c === 200 && str_contains($body, 'rmm-panel') && $pInstallBtn($body) === 0 && !str_contains($body, 'rmm_mesh_push.js') && !str_contains($body, 'rmmMeshPushConfirm'), 'switch off: the page has no install button, no dialog and does not link the script');
$pAct->updateSettings($admin, ['features' => ['remote_tools' => true]]);
$pFresh();
$ok($rmm->featureOn('remote_tools'), 'remote_tools turned on through RemoteActions::updateSettings');
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($c === 200 && $pInstallBtn($body) === 0 && $pRemoveBtn($body) === 0 && !str_contains($body, 'rmm_mesh_push.js'), 'Mesh has no device group id yet: Core offers no install, so there is no button and no script (nothing installed to remove either)');
$dv = $rmm->readModel()->deviceView($pDev['PUSH1'], false, 5);
$ok(!array_key_exists('mesh_install', $dv), 'Core: no mesh_install member while the install is unavailable');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 409 && ($j['code'] ?? '') === 'not_configured' && str_contains((string) ($j['error'] ?? ''), 'device group id') && $pCount('mesh_install') === 0, 'the handler refuses with Core\'s words and queues nothing (' . ($j['error'] ?? '?') . ')');

// ---- configure the Mesh agent install (random placeholder id)
$errs = $rmm->meshAgentSettings()->update(['mesh_group_id' => $pGroupUrl], 1, 'https://mesh.example.test');
$pFresh();
$ok($errs === [] && $rmm->meshAgents()->installRefusal() === null, 'the Mesh device group id is saved: the install is available');
$dv = $rmm->readModel()->deviceView($pDev['PUSH1'], false, 5);
$ok(($dv['mesh_install'] ?? null) === ['available' => true, 'agent_supports' => true, 'installed' => false], 'Core: detail()[mesh_install] = available, supported, not installed: ' . json_encode($dv['mesh_install'] ?? null));
$dv2 = $rmm->readModel()->deviceView($pDev['PUSH2'], false, 5);
$ok(($dv2['mesh_install'] ?? null) === ['available' => true, 'agent_supports' => false, 'installed' => false], 'Core: an agent that did not announce job:mesh_install is available=true, agent_supports=false');

// ============================================================ 1. the device page
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($c === 200 && $pInstallBtn($body) === 2, 'PUSH1: the Install button is drawn twice (the Remote access card and the Remote tab) (' . $pInstallBtn($body) . ')');
$ok(str_contains($body, 'rmm_mesh_push.js') && substr_count($body, 'id="rmmMeshPushConfirm"') === 1 && str_contains($body, 'data-post-url="/agent/post/rmm_mesh_push.php"') && str_contains($body, 'Install MeshCentral agent'), 'PUSH1: one confirm dialog, the script linked');
$ok($pRemoveBtn($body) === 0 && !preg_match('/data-mesh-push-action="install"[^>]*disabled/', $body), 'PUSH1: nothing to remove yet, and the install button is enabled');
$ok(!str_contains($body, $pGroupUrl) && !str_contains($body, $pGroup), 'PUSH1: the device group id is not on the page');
[$c, $body] = $pPage('admin', 'PUSH2');
$ok(preg_match('/data-mesh-push-action="install"[^>]*disabled aria-disabled="true"/', $body) === 1 && str_contains($body, 'too old to install the MeshCentral agent by push') && str_contains($body, 'Update the agent first'), 'PUSH2: an agent that did not announce it gets a disabled button and the reason in words');
// the viewer, reboot-only and remote-only roles hold no rmm.remote.manage: nothing is drawn, nothing linked
foreach (['viewer', 'rebootonly', 'remoteonly'] as $who) {
    [$c, $body] = $pPage($who, 'PUSH1');
    $ok($c === 200 && str_contains($body, 'rmm-panel') && $pInstallBtn($body) === 0 && !str_contains($body, 'rmm_mesh_push.js') && !str_contains($body, 'rmmMeshPushConfirm'), "$who: no install button, no dialog, the script is not linked");
    [$c, $j] = $pPush($who, 'install', ['device_id' => $pDev['PUSH1']]);
    $refusal = $pAct->installMeshAgent(new RmmPrincipal($USER[$who], $who), $pDev['PUSH1']);
    $ok($c === 403 && ($j['error'] ?? '') === $refusal->message && $refusal->message !== '', "$who: the handler refuses with Core's text");
    foreach (['uninstall', 'job_result'] as $a) {
        [$c] = $pPush($who, $a, ['device_id' => $pDev['PUSH1'], 'confirm' => '1', 'job_id' => ea_uuid()]);
        $ok($c >= 400, "$who: $a is refused ($c)");
    }
}
[$c, $j] = $pPush('viewer', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($c === 403 && $pCount('mesh_install') === 0, 'viewer: the bulk install is refused');
// the technician holds it through the level 3 setting only
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 0 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $pPage('tech', 'PUSH1');
$ok($pInstallBtn($body) === 0, 'technician with the level 3 setting OFF: no button');
[$c, $j] = $pPush('tech', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 403, 'technician with the level 3 setting OFF: the handler refuses (' . $c . ')');
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
rivetRmmForgetAccess();
[$c, $body] = $pPage('tech', 'PUSH1');
$ok($pInstallBtn($body) === 2, 'technician with the level 3 setting ON: the button is drawn');

// ---- a CSRF-less POST and a GET are refused
[$c, $raw] = web($wb, 'POST', '/agent/post/rmm_mesh_push.php', $SS['admin'], ['action' => 'install', 'device_id' => $pDev['PUSH1'], 'csrf_token' => 'wrong']);
$ok($c === 403 && str_contains($raw, 'csrf'), 'a wrong CSRF token is refused (' . $c . ')');
[$c] = web($wb, 'GET', '/agent/post/rmm_mesh_push.php?action=install&device_id=' . $pDev['PUSH1'], $SS['admin']);
$ok($c === 405 && $pCount('mesh_install') === 0, 'a GET is refused (405) and queues nothing');

// ---- the install: queued, idempotent, signed, the group id out of the row
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 200 && ($j['success'] ?? false) === true && ($j['code'] ?? '') === 'queued' && preg_match('/^[0-9a-f-]{36}$/', (string) ($j['job_id'] ?? '')) === 1, 'install: queued with a job id (' . json_encode($j) . ')');
$job1 = (string) $j['job_id'];
$row = $rows("SELECT * FROM endpoint_agent_jobs WHERE job_id='$job1'")[0];
$ok($row['type'] === 'mesh_install' && (int) $row['device_id'] === $pDev['PUSH1'] && (int) $row['destructive'] === 0 && (int) $row['created_by'] === 1 && $row['state'] === 'queued', 'install: one mesh_install job, queued, by the signed-in user');
$ok(!str_contains((string) $row['params_json'], $pGroupUrl) && !str_contains((string) $row['params_json'], $pGroup) && json_decode((string) $row['params_json'], true)['force'] === false, 'install: the device group id is not in the job row and force is false');
$ok(str_contains(json_encode($rows("SELECT log_action, log_description FROM logs ORDER BY log_id DESC LIMIT 6")), 'install the MeshCentral agent'), 'install: audited by Core');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 200 && ($j['code'] ?? '') === 'already_queued' && ($j['job_id'] ?? '') === $job1 && $pCount('mesh_install', $pDev['PUSH1']) === 1, 'a second click while it is queued: already_queued with the same job, nothing new');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH2']]);
$ok($c === 409 && ($j['code'] ?? '') === 'unsupported' && str_contains((string) ($j['error'] ?? ''), 'Update the agent') && $pCount('mesh_install', $pDev['PUSH2']) === 0, 'an agent that did not announce it: 409 unsupported with Core\'s "Update the agent" text');
[$c, $j] = $pPush('admin', 'install', ['device_id' => 99999]);
[$c2, $j2, $raw2] = $pPush('deptb', 'install', ['device_id' => $pDev['PUSH1']]);
[, , $raw1] = $pPush('deptb', 'install', ['device_id' => 99999]);
$ok($c === 404 && $c2 === 404 && $raw1 === $raw2 && $pCount('mesh_install', $pDev['PUSH1']) === 1, 'another client\'s device: the same 404 body as a device that does not exist, and nothing queued');
foreach (['uninstall', 'job_result'] as $a) {
    [$cc, , $rawc] = $pPush('deptb', $a, ['device_id' => $pDev['PUSH1'], 'confirm' => '1', 'job_id' => $job1]);
    $ok($cc === 404 && $rawc === $raw1, "another client's device: $a is the same 404");
}
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1'], 'url' => 'https://evil.example', 'meshid' => 'x']);
$ok(($j['code'] ?? '') === 'already_queued' && $pCount('mesh_install', $pDev['PUSH1']) === 1, 'a request carrying an address or an id is not read for them: only force is taken (the job is the one already queued)');
// the page now shows the queued job in the job history, with a way to read the result
[$c, $body] = $pPage('admin', 'PUSH1');
$ok(str_contains($body, 'Install MeshCentral agent</td>') === false && str_contains($body, 'Install MeshCentral agent<') && str_contains($body, 'data-mesh-push-result="' . $job1 . '"') && str_contains($body, 'id="rmm-mres-' . $job1 . '"') && !str_contains($body, 'data-job="' . $job1 . '" aria-expanded'), 'job history: the queued install is labelled and has a "View result" button (not the script "View output")');

// ---- the device receives the signed job and reports; the result is read through the handler
$jobs = $pJobs($pTok['PUSH1']);
$mj = array_values(array_filter($jobs, fn ($x) => $x['type'] === 'mesh_install'))[0] ?? null;
$ok($mj !== null && ($mj['params']['meshid'] ?? '') === $pGroupUrl && ($mj['params']['url'] ?? '') === 'https://mesh.example.test' && !empty($mj['signature']), 'the device is offered the signed job with the REAL group id and the stored address');
[$c, $j] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => $job1]);
$ok($c === 200 && in_array($j['state'] ?? '', ['running', 'queued'], true), 'job_result while the device has it: not final (' . ($j['state'] ?? '?') . ')');
$ok(($j['final'] ?? null) === false && str_contains((string) ($j['html'] ?? ''), 'Install MeshCentral agent'), 'job_result: not final, a fragment naming the action');
$out = json_encode(['v' => 1, 'ok' => false, 'detail' => 'install_failed', 'installed' => false, 'error' => 'pin mismatch <script>alert(16)</script>', 'exit_code' => 3, 'log' => "line1\n<img src=x onerror=alert(17)>"]);
$pReport($pTok['PUSH1'], $mj, 'failed', 3, $out);
[$c, $j] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => $job1]);
$ok($c === 200 && $j['final'] === true && $j['state'] === 'failed' && str_contains($j['html'], 'Failed') && str_contains($j['html'], 'pin mismatch &lt;script&gt;') && !str_contains($j['html'], '<script>alert(16)') && !str_contains($j['html'], '<img src=x') && str_contains($j['html'], 'Installer exit code 3'), 'a failed install: shown as failed, the device\'s text escaped (' . substr(strip_tags($j['html'] ?? ''), 0, 120) . ')');
$ok(!str_contains((string) $j['html'], $pGroupUrl), 'the result never prints the device group id');
[$c, $j] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => ea_uuid()]);
$ok($c === 404, 'job_result for a job that does not exist: 404');
// a job of another type is not readable through this endpoint
[$c, $j] = $pPush('admin', 'svc_refresh', ['device_id' => $pDev['PUSH1']]);
$ok($c === 422 && ($j['code'] ?? '') === 'invalid', 'an unknown action is refused');
// the failed install is not "installed": the button is still the install one; a second attempt queues a new job
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 200 && ($j['code'] ?? '') === 'queued' && $j['job_id'] !== $job1, 'after a failed install a new one can be queued');
$job2 = (string) $j['job_id'];
$mj2 = array_values(array_filter($pJobs($pTok['PUSH1']), fn ($x) => $x['type'] === 'mesh_install'))[0] ?? null;
$pReport($pTok['PUSH1'], $mj2, 'succeeded', 0, json_encode(['v' => 1, 'ok' => true, 'detail' => 'installed', 'installed' => true, 'node_id' => 'node//' . str_repeat('Qq', 20)]));
ea_checkin($pTok['PUSH1'], ['capabilities' => $pCapsAll, 'mesh_agent' => ['installed' => true, 'version' => '1.2.3', 'node_id' => 'node//' . str_repeat('Qq', 20)]]);
[$c, $j] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => $job2]);
$ok($j['state'] === 'succeeded' && $j['final'] === true && str_contains($j['html'], 'Done') && str_contains($j['html'], 'The Mesh agent was installed.') && str_contains($j['html'], 'node//' . str_repeat('Qq', 20)), 'a successful install: "Done", "The Mesh agent was installed." and the node id');
$ok((string) $one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=" . $pDev['PUSH1']) === 'node//' . str_repeat('Qq', 20), 'the node id is mapped by Core from the check-in');

// ---- the page now: installed, with the Remove button; install again says already_installed unless forced
$dv = $rmm->readModel()->deviceView($pDev['PUSH1'], false, 5);
$ok(($dv['mesh_install']['installed'] ?? false) === true && ($dv['mesh_agent']['installed'] ?? false) === true, 'Core: installed=true');
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($pInstallBtn($body) === 2 && $pRemoveBtn($body) === 2 && substr_count($body, 'data-installed="1"') === 2 && str_contains($body, 'Remove MeshCentral agent') && str_contains($body, 'already reports the Mesh agent as installed'), 'PUSH1 installed: Install (offered with force) and Remove are drawn on both cards, marked installed');
$ok(str_contains($body, 'id="rmmMeshPushForce"') && str_contains($body, 'id="rmmMeshPushCheck"') && str_contains($body, 'I understand and want to do this.') && str_contains($body, 'Run the installer again even though the device reports'), 'the dialog has the force box and the explicit confirm box');
$before = $pCount('mesh_install', $pDev['PUSH1']);
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
$ok($c === 200 && ($j['code'] ?? '') === 'already_installed' && ($j['node_id'] ?? '') === 'node//' . str_repeat('Qq', 20) && $pCount('mesh_install', $pDev['PUSH1']) === $before, 'install on an installed device: already_installed with the node id, nothing queued');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1'], 'force' => '1']);
$ok($c === 200 && ($j['code'] ?? '') === 'queued' && json_decode((string) $one("SELECT params_json FROM endpoint_agent_jobs WHERE job_id='" . $esc((string) $j['job_id']) . "'"), true)['force'] === true, 'force=1 queues it again with force true');
$jobF = (string) $j['job_id'];
$mjF = array_values(array_filter($pJobs($pTok['PUSH1']), fn ($x) => $x['type'] === 'mesh_install'))[0] ?? null;
$pReport($pTok['PUSH1'], $mjF, 'succeeded', 0, json_encode(['v' => 1, 'ok' => true, 'detail' => 'installed']));

// ---- the removal: explicit confirm, destructive, offered once, the RivetIT agent untouched
[$c, $j] = $pPush('admin', 'uninstall', ['device_id' => $pDev['PUSH1']]);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && $pCount('mesh_uninstall') === 0, 'removal without the confirm box: 422 confirmation_required, nothing queued');
[$c, $j] = $pPush('admin', 'uninstall', ['device_id' => $pDev['PUSH1'], 'confirm' => '1']);
$ok($c === 200 && ($j['code'] ?? '') === 'queued' && $pCount('mesh_uninstall', $pDev['PUSH1']) === 1, 'removal with the confirm box: queued');
$jobU = (string) $j['job_id'];
$ok((int) $one("SELECT destructive FROM endpoint_agent_jobs WHERE job_id='$jobU'") === 1, 'the removal is a destructive job');
[$c, $j] = $pPush('admin', 'uninstall', ['device_id' => $pDev['PUSH2'], 'confirm' => '1']);
$ok($c === 409 && ($j['code'] ?? '') === 'unsupported', 'removal on an agent that did not announce it: 409 unsupported (' . ($j['code'] ?? '?') . ')');
$uj = array_values(array_filter($pJobs($pTok['PUSH1']), fn ($x) => $x['type'] === 'mesh_uninstall'))[0] ?? null;
$ok($uj !== null && count(array_filter($pJobs($pTok['PUSH1']), fn ($x) => $x['type'] === 'mesh_uninstall')) === 0, 'the device is offered the removal once and never again');
$pReport($pTok['PUSH1'], $uj, 'succeeded', 0, json_encode(['v' => 1, 'ok' => true, 'detail' => 'uninstalled', 'installed' => false]));
ea_checkin($pTok['PUSH1'], ['capabilities' => $pCapsAll, 'mesh_agent' => ['installed' => false]]);
[$c, $j] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => $jobU]);
$ok($j['state'] === 'succeeded' && str_contains($j['html'], 'Remove MeshCentral agent') && str_contains($j['html'], 'The Mesh agent was removed.'), 'the removal result is shown in words');
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($pRemoveBtn($body) === 0 && $pInstallBtn($body) === 2, 'after the removal the device reports it gone: no Remove button, Install again');
$ok(str_contains($body, 'Remove MeshCentral agent</') === false || str_contains($body, 'data-mesh-push-result="' . $jobU . '"'), 'job history: the removal row has its result button');

// ---- the legacy device page
[$c, $body] = $pLegacy('admin');
$ok($c === 200 && str_contains($body, 'MeshCentral agent') && $pInstallBtn($body) === 1 && str_contains($body, 'id="rmmMeshPushConfirm"') && str_contains($body, 'rmm_mesh_push.js'), 'legacy device page: the card with the Install button, the dialog and the script (' . $c . ')');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSHL']]);
$ok($c === 200 && ($j['code'] ?? '') === 'queued', 'legacy device: the same handler queues the install');
[$c, $body] = $pLegacy('admin');
$ok(str_contains($body, 'data-mesh-push-result="') && str_contains($body, 'id="rmm-mres-'), 'legacy device page: the mesh job in the job table has its result button');
[$c, $body] = $pLegacy('viewer');
$ok($c === 200 && $pInstallBtn($body) === 0 && !str_contains($body, 'rmm_mesh_push.js') && !str_contains($body, 'data-mesh-push-result'), 'legacy device page, viewer: nothing drawn, nothing linked');

// ---- the remote-tools switch off again: nothing drawn, the handler refuses
$pAct->updateSettings($admin, ['features' => ['remote_tools' => false]]);
$pFresh();
[$c, $body] = $pPage('admin', 'PUSH1');
$ok($pInstallBtn($body) === 0 && !str_contains($body, 'rmm_mesh_push.js') && !str_contains($body, 'rmmMeshPushConfirm'), 'switch off again: no button, no dialog, no script');
$qn = $pCount('mesh_install');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH2']]);
[$c3, $j3] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
[$c4, $j4] = $pPush('admin', 'job_result', ['device_id' => $pDev['PUSH1'], 'job_id' => $job2]);
$ok($c === 409 && $c3 === 409 && $c4 === 409 && ($j['code'] ?? '') === 'feature_disabled' && ($j3['code'] ?? '') === 'feature_disabled' && ($j4['code'] ?? '') === 'feature_disabled' && $pCount('mesh_install') === $qn, 'switch off: install, bulk and job_result are refused with feature_disabled and nothing is queued');
$pAct->updateSettings($admin, ['features' => ['remote_tools' => true]]);
$pFresh();

// ============================================================ 2. Agent Fleet: the bulk install
[$c, $body] = $pFleet('admin');
$ok($c === 200 && str_contains($body, 'data-mesh-bulk-open') && str_contains($body, 'id="rmmMeshBulk"') && str_contains($body, 'rmm_mesh_push.js') && str_contains($body, 'Install MeshCentral agent'), 'fleet: the toolbar button, the bulk dialog and the script for an administrator');
$ok(str_contains($body, '<option value="all:0" selected>Every device I can see</option>') && str_contains($body, 'value="client:1"') && str_contains($body, 'value="tag:' . $pTagId . '"') && str_contains($body, 'rc16push'), 'fleet: the target list has everything, departments and tags');
$ok(str_contains($body, 'id="rmm-mb-check"') && str_contains($body, 'id="rmm-mb-force"') && str_contains($body, 'Status, ring and search filters cannot narrow this'), 'fleet: the force box, the confirm box and the note about filters');
[$c, $body] = $pFleet('admin', '?tag=rc16push');
$ok(str_contains($body, '<option value="tag:' . $pTagId . '" selected>'), 'fleet: a tag filter preselects that target');
[$c, $body] = $pFleet('admin', '?status=online&q=zzz');
$ok(str_contains($body, 'data-mesh-bulk-narrowed') && str_contains($body, '<option value="all:0" selected>'), 'fleet: a status or search filter shows the "does not apply here" note and preselects everything');
foreach (['viewer', 'rebootonly', 'remoteonly'] as $who) {
    [$c, $body] = $pFleet($who);
    $ok($c === 200 && !str_contains($body, 'rmmMeshBulk') && !str_contains($body, 'rmm_mesh_push.js'), "$who: the fleet page has no bulk button, no dialog, no script");
}
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'galaxy', 'target_id' => 1]);
$ok($c === 422, 'bulk with an unknown target type: 422');
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => 999999]);
$ok($c === 422 && $pCount('mesh_install') === $qn, 'bulk with a tag that does not exist: 422, nothing queued');
// the first, unconfirmed call returns Core's count and queues nothing
$qn = $pCount('mesh_install');
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId]);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && ($j['device_count'] ?? 0) === 3 && str_contains((string) ($j['error'] ?? ''), '3 device(s)') && $pCount('mesh_install') === $qn, 'bulk, first call: 422 confirmation_required with device_count 3 (PUSH1, PUSH2, PUSHW), nothing queued: ' . json_encode($j));
// the confirmed call: PUSH1 is installed? (removed above) so it is queued; PUSH2 is too old; PUSHW is queued
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($c === 200 && $j['device_count'] === 3 && $j['created'] === 2 && $j['skipped_unsupported'] === 1 && $j['skipped_installed'] === 0 && $j['skipped_queued'] === 0 && $j['skipped_denied'] === 0 && $j['skipped_error'] === 0, 'bulk, confirmed: 2 queued, 1 skipped (agent too old): ' . json_encode($j));
$ok($pCount('mesh_install', $pDev['PUSH1']) === 4 && $pCount('mesh_install', $pDev['PUSHW']) === 1 && $pCount('mesh_install', $pDev['PUSH2']) === 0 && $pCount('mesh_install', $pDev['PUSHB']) === 0, 'bulk: jobs on PUSH1 and PUSHW only, none on PUSH2 (too old) or PUSHB (not in the tag)');
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($j['created'] === 0 && $j['skipped_queued'] === 2 && $j['skipped_unsupported'] === 1, 'bulk again: nothing new (2 already queued, 1 too old) -- safe to repeat');
// the approval rule: refused, never queued around
$rmm->admin()->saveSettings($admin, ['limits_json' => ['approval_bulk_threshold' => 2]]);
$pFresh();
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($c === 409 && ($j['code'] ?? '') === 'approval_required' && str_contains((string) ($j['error'] ?? ''), 'approval threshold of 2') && str_contains((string) ($j['error'] ?? ''), 'install in batches'), 'above approval_bulk_threshold the bulk is REFUSED with Core\'s text (' . ($j['error'] ?? '?') . ')');
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId]);
$ok($c === 409 && ($j['code'] ?? '') === 'approval_required', 'the unconfirmed first call is refused the same way (no count offered for a request that cannot be queued)');
$rmm->admin()->saveSettings($admin, ['limits_json' => ['approval_bulk_threshold' => 0, 'bulk_run_max' => 2]]);
$pFresh();
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($c === 422 && ($j['code'] ?? '') === 'too_many' && str_contains((string) ($j['error'] ?? ''), 'limited to 2'), 'more devices than bulk_run_max: 422 too_many (' . ($j['error'] ?? '?') . ')');
$rmm->admin()->saveSettings($admin, ['limits_json' => ['bulk_run_max' => 1000]]);
$pFresh();
// client scoping: the Dept B user reaches Dept B devices only, whatever the target
[$c, $j] = $pPush('deptb', 'bulk', ['target_type' => 'all']);
$ok($c === 422 && ($j['code'] ?? '') === 'confirmation_required' && ($j['device_count'] ?? 0) === (int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE client_id=2 AND retired_at IS NULL AND link_state='linked' AND revoked_at IS NULL AND os IN ('windows','linux')"), 'Dept B user, target everything: Core counts Dept B devices only (' . ($j['device_count'] ?? '?') . ')');
$bBefore = $pCount('mesh_install', $pDev['PUSH1']);
[$c, $j] = $pPush('deptb', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($pCount('mesh_install', $pDev['PUSH1']) === $bBefore && $pCount('mesh_install', $pDev['PUSHW']) === 1 && ($c === 422 || ($j['created'] ?? 0) === 0), 'Dept B user, a tag of Dept A devices: nothing is reached (' . $c . ')');
[$c, $j] = $pPush('deptb', 'bulk', ['target_type' => 'client', 'target_id' => 1, 'confirm' => '1']);
$ok($pCount('mesh_install', $pDev['PUSH1']) === $bBefore && ($c === 422 || ($j['created'] ?? 0) === 0), 'Dept B user, Dept A as the target: nothing is reached (' . $c . ')');
[$c, $body] = $pFleet('deptb');
$ok($c === 200 && !str_contains($body, 'value="client:1"') && !str_contains($body, 'Department: Dept A'), 'Dept B user: the target list does not name Dept A');
// force through the bulk call
$mjW = array_values(array_filter($pJobs($pTok['PUSHW']), fn ($x) => $x['type'] === 'mesh_install'))[0] ?? null;
$pReport($pTok['PUSHW'], $mjW, 'succeeded', 0, json_encode(['v' => 1, 'ok' => true, 'detail' => 'installed']));
ea_checkin($pTok['PUSHW'], ['capabilities' => array_merge($pCapsAll, ['job:powershell']), 'mesh_agent' => ['installed' => true, 'version' => '1.2.3', 'node_id' => 'node//' . str_repeat('Ww', 20)]]);
[$c, $j] = $pPush('admin', 'bulk', ['target_type' => 'tag', 'target_id' => $pTagId, 'confirm' => '1']);
$ok($j['skipped_installed'] === 1 && $j['skipped_queued'] === 1 && $j['created'] === 0, 'bulk: a device that reports the Mesh agent is skipped as installed: ' . json_encode($j));
$q("UPDATE endpoint_agent_jobs SET state='cancelled', reason='cancelled_by_user' WHERE type='mesh_install' AND state IN ('queued','running')");

// ============================================================ 3. the module off: nothing drawn, nothing asked, nothing linked
$rmm->admin()->disable($admin);
$pFresh();
$a = $questions();
$o1 = rivetRmmUiPanel($db, $pAsset['PUSH1'], 1, $pDev['PUSH1']);
$o2 = rivetRmmUiFleet($db, 1, ['tag' => 'rc16push']);
$b = $questions();
$ok($o1 === null && $o2 === null && $b - $a === $overhead, 'module OFF: the panel and the fleet are null having asked the database nothing (the bulk context included)');
[$c, $j] = $pPush('admin', 'install', ['device_id' => $pDev['PUSH1']]);
[$c2, $j2] = $pPush('admin', 'bulk', ['target_type' => 'all']);
$ok($c === 404 && $c2 === 404 && ($j['code'] ?? '') === 'module_disabled' && ($j2['code'] ?? '') === 'module_disabled', 'module OFF: the handler answers 404 module_disabled for one device and for bulk');
[$c, $body] = $pPage('admin', 'PUSH1');
[$c2, $fbody] = $pFleet('admin');
$ok(!str_contains($body, 'rmm_mesh_push.js') && !str_contains($body, 'data-mesh-push') && !str_contains($fbody, 'rmm_mesh_push.js') && !str_contains($fbody, 'rmmMeshBulk') && $c2 === 403, 'module OFF: no button, no dialog, the script is not linked (the fleet answers the module-off notice)');
$rmm->admin()->enable($admin);
$pFresh();

// ---- put back
$rmm->meshAgentSettings()->update(['mesh_group_id' => ''], 1, 'https://mesh.example.test');
$rmm->settings()->set(['mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '']);
$pAct->updateSettings($admin, ['features' => ['remote_tools' => false]]);
$pFresh();
$q("DELETE FROM user_role_permissions WHERE module_id=6 AND user_role_id IN (3,5)");
rivetRmmForgetAccess();
$ok($rmm->enabled() && !$rmm->featureOn('remote_tools') && $rmm->meshAgents()->installRefusal() !== null, 'the module ends ON; the remote-tools switch and the Mesh group id are put back');

