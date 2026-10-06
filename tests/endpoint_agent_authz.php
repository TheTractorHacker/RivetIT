<?php
/*
 * Authorization matrix (REST API and the real web pages/handlers with forged sessions) and MeshCentral remote launch against a
 * local mock server (mapped / unmapped / offline / outage / bad config), token format, audit, XSS escaping, page rendering.
 */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Mesh; use ITFlow\EndpointAgent\Config; use ITFlow\EndpointAgent\Devices; use ITFlow\EndpointAgent\Enrollment; use ITFlow\EndpointAgent\Jobs;

ea_reset();
$U = ea_seed_users();
$KEY = Mesh::newLoginKey();
$mock = ea_start_php("$root/tests/mock/mock_meshcentral.php");
$meshUrl = "http://127.0.0.1:{$mock['port']}";
$tok = ea_token(1, 24, 50); $tok2 = Enrollment::createToken(2, 0, 'stable', 24, 50, 't2', 1)['token'];
Config::set(['mesh_enabled' => 1, 'mesh_url' => $meshUrl, 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting($KEY), 'mesh_account_template' => 'rivetit-support']);
$mkdev = function (string $tk, string $name, int $client, bool $withNode = true) use ($q) {
    $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$name', asset_make='Dell', asset_serial='SER-$name', asset_client_id=$client, asset_status='Active'");
    [$c, , $j] = ea_enroll($tk, ea_dev(['serial' => "SER-$name", 'hostname' => $name]));
    $id = (int) $j['device_id'];
    if ($withNode) { Devices::setMeshNode($id, 'node//' . str_repeat('A', 24) . $id, 1); }
    ea_checkin($j['device_token']);
    return [$id, $j['device_token']];
};
[$D1, $T1] = $mkdev($tok, 'DEPT1-PC', 1); [$D2] = $mkdev($tok2, 'DEPT2-PC', 2); [$D3] = $mkdev($tok, 'UNMAPPED-PC', 1, false);

// ---------------------------------------------------------------- API matrix (direct API calls)
$api = fn(string $who, string $m, string $p, $b = null) => http($m, $p, $U[$who], $b);
$expect = [
  // user        view  ps   saved reboot remote   (status codes for device 1, department 1)
  'admin'      => [200, 201, 201, 201, 200],
  'tech'       => [200, 201, 201, 201, 200],
  'rebootonly' => [200, 403, 201, 201, 403],
  'viewer'     => [200, 403, 403, 403, 403],
  'remoteonly' => [200, 403, 403, 403, 200],
  'moduleonly' => [403, 403, 403, 403, 403],
  'normo'      => [403, 403, 403, 403, 403],
  'deptb'      => [404, 404, 404, 404, 404],
];
$q("INSERT INTO rmm_scripts SET name='Saved', script_type='powershell', script_body='Get-Date', enabled=1"); $sid = (int) $db->insert_id;
foreach ($expect as $who => [$view, $ps, $saved, $reboot, $remote]) {
    [$c] = $api($who, 'GET', "/api/v1/endpoint_devices/$D1");                                                         $ok($c === $view, "$who: view device -> $view (got $c)");
    [$c] = $api($who, 'POST', "/api/v1/endpoint_devices/$D1/jobs", ['type' => 'powershell', 'script' => 'Get-Date']);   $ok($c === $ps, "$who: free-form PowerShell -> $ps (got $c)");
    [$c] = $api($who, 'POST', "/api/v1/endpoint_devices/$D1/jobs", ['type' => 'powershell', 'script_id' => $sid]);      $ok($c === $saved, "$who: saved script -> $saved (got $c)");
    [$c] = $api($who, 'POST', "/api/v1/endpoint_devices/$D1/jobs", ['type' => 'reboot', 'confirm' => true]);            $ok($c === $reboot, "$who: reboot -> $reboot (got $c)");
    [$c] = $api($who, 'POST', "/api/v1/endpoint_devices/$D1/remote", []);                                                $ok($c === $remote, "$who: remote launch -> $remote (got $c)");
}
[$c, , $r] = $api('deptb', 'GET', '/api/v1/endpoint_devices');
$ok($c === 200 && count($r['data']) === 1 && $r['data'][0]['device_id'] === $D2, 'department-restricted user lists only their department\'s devices');
[$c] = $api('deptb', 'GET', "/api/v1/endpoint_devices/$D2"); $ok($c === 200, 'department-restricted user can view their own department\'s device');
[$c] = $api('deptb', 'POST', "/api/v1/endpoint_devices/$D2/remote", []); $ok($c === 200, '...and launch a session there');
[$c, , $r] = $api('deptb', 'POST', "/api/v1/endpoint_devices/$D2/jobs", ['type' => 'powershell', 'script' => 'x']); $ok($c === 201, '...and run a job there');
[$c, , $r] = $api('admin', 'GET', '/api/v1/endpoint_devices'); $ok($c === 200 && count($r['data']) === 3, 'administrator lists every department');
[$c] = http('GET', "/api/v1/endpoint_devices/$D1"); $ok($c === 401, 'no token -> 401');
[$c] = http('GET', "/api/v1/endpoint_devices/$D1", $T1); $ok($c === 401, 'a DEVICE credential is not a user credential');
[$c] = http('GET', "/api/v1/endpoint_devices/$D1", null, null, ['X-Api-Key: nope']); $ok($c === 401, 'bad legacy key -> 401');
$q("UPDATE users SET user_status=0 WHERE user_id=10");
[$c] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/jobs", ['type' => 'collect']); $ok($c === 401, 'a disabled user\'s token stops working');
$q("UPDATE users SET user_status=1 WHERE user_id=10");
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Job Denied'") >= 4 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Remote Denied'") >= 4, 'denied job and remote attempts are audited');
Config::set(['enabled' => 0]);
[$c, , $r] = $api('tech', 'GET', "/api/v1/endpoint_devices/$D1"); $ok($c === 404 && $r['code'] === 'disabled', 'with the service switched off the technician API answers disabled');
[$c] = ea_checkin($T1); $ok($c === 403, 'with the service switched off devices get 403 forbidden');
Config::set(['enabled' => 1]);

// ---------------------------------------------------------------- MeshCentral launch
$q("DELETE FROM rmm_remote_sessions"); $q("DELETE FROM logs");
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 200 && isset($r['url'], $r['session_id']), 'mapped online device: launch returns a URL and a safe session id');
$u = parse_url($r['url']); parse_str($u['query'], $qs);
$ok(strpos($r['url'], $meshUrl . '/?login=') === 0 && ($qs['gotonode'] ?? '') === 'node//' . str_repeat('A', 24) . $D1, 'URL targets the MeshCentral login-token entry and the LINKED device\'s node');
$cookie = Mesh::decodeCookie($qs['login'], $KEY);
$ok($cookie && $cookie['u'] === 'user//rivetit-support' && $cookie['a'] === 3 && abs($cookie['time'] - (time() - 120)) < 30, 'login token decrypts with the key to the limited service account (no admin credential) with a fresh timestamp');
$ok(strpos($qs['login'], '+') === false && strpos($qs['login'], '/') === false, 'token uses MeshCentral\'s @ and $ substitutions');
$r2 = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", [])[2];
$ok(parse_url($r2['url'], PHP_URL_QUERY) !== parse_url($r['url'], PHP_URL_QUERY), 'every launch mints a different token');
$sessions = $rows("SELECT * FROM rmm_remote_sessions");
$ok(count($sessions) === 2 && $sessions[0]['connection_type'] === 'meshcentral' && strpos($sessions[0]['connection_url'], 'login') === false && strpos($sessions[0]['connection_url'], 'meshcentral:session:' . $r['session_id']) === 0, 'the session is recorded with a safe id and NO token');
$logs = (string) $one("SELECT GROUP_CONCAT(log_description) FROM logs");
$ok(strpos($logs, $qs['login']) === false && strpos($logs, $KEY) === false && strpos($logs, $r['session_id']) !== false, 'neither the token nor the key is logged; the safe session id is');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type='Endpoint Agent' AND log_action='Remote Session' AND log_user_id=10") === 2, 'audit names the actor');
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D3/remote", []);
$ok($c === 404 && $r['code'] === 'unmapped' && strpos($r['message'] ?? $r['error'], 'not mapped') !== false && !isset($r['url']), 'unmapped device: clear safe message, no URL');
$q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 5000) . "' WHERE device_id=$D1");
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 409 && $r['code'] === 'device_offline' && !isset($r['url']), 'offline device: refused with a clear message');
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", ['force' => true]);
$ok($c === 200 && isset($r['url']), 'offline device can be launched on explicit request (MeshCentral may still reach it)');
ea_checkin($T1);
$mockDown = ea_start_php("$root/tests/mock/mock_meshcentral.php", ['MOCK_MESH_MODE' => 'error']);
Config::set(['mesh_url' => "http://127.0.0.1:{$mockDown['port']}"]);
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 503 && $r['code'] === 'mesh_unavailable' && !isset($r['url']), 'MeshCentral answering 500 -> 503 with a clear message, no PHP error');
$mockSlow = ea_start_php("$root/tests/mock/mock_meshcentral.php", ['MOCK_MESH_MODE' => 'slow']);
Config::set(['mesh_url' => "http://127.0.0.1:{$mockSlow['port']}"]);
$t0 = microtime(true);
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 503 && $r['code'] === 'mesh_unavailable' && microtime(true) - $t0 < 9, 'MeshCentral timing out -> 503 within the timeout');
Config::set(['mesh_url' => 'http://127.0.0.1:1']);
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 503 && $r['code'] === 'mesh_unavailable', 'MeshCentral refusing connections -> 503');
Config::set(['mesh_url' => 'http://169.254.169.254']);
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []);
$ok($c === 503 && strpos($r['error'], 'network policy') !== false, 'an address outside the network policy (cloud metadata) is refused (SSRF)');
$ok(Mesh::normalizeUrl('ftp://x') === null && Mesh::normalizeUrl('http://user:pw@host') === null && Mesh::normalizeUrl('https://host/?a=b') === null && Mesh::normalizeUrl('https://mesh.example.com/') === 'https://mesh.example.com', 'MeshCentral URL validation');
Config::set(['mesh_url' => $meshUrl, 'mesh_enabled' => 0]);
[$c, , $r] = $api('tech', 'POST', "/api/v1/endpoint_devices/$D1/remote", []); $ok($c === 409 && $r['code'] === 'not_configured', 'MeshCentral disabled -> not_configured');
Config::set(['mesh_enabled' => 1]);
Devices::retire($D3, 1); Devices::revoke($D2, 'x', 1);
[$c, , $r] = $api('admin', 'POST', "/api/v1/endpoint_devices/$D2/remote", []); $ok($c === 409 && $r['code'] === 'device_retired', 'a revoked device cannot be launched');
$ok(Devices::setMeshNode($D1, 'node//short', 1) === false && Devices::setMeshNode($D1, 'node//' . str_repeat('B', 24), 1, 'manual') === true, 'node id format is validated');
$ok(Devices::setMeshNode($D1, null, 1) && (int) $one("SELECT COUNT(*) FROM endpoint_agent_mesh_nodes WHERE device_id=$D1") === 0, 'the node association can be cleared');
$ok($one("SELECT asset_name FROM assets WHERE asset_id=(SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$D1)") === 'DEPT1-PC', 'the asset display name is independent of the mesh mapping');
// agent-reported node id never overrides a manual one
Devices::setMeshNode($D1, 'node//' . str_repeat('M', 24), 1, 'manual');
ea_checkin($T1, ['inventory' => ['hostname' => 'DEPT1-PC', 'mesh_node_id' => 'node//' . str_repeat('Z', 24)]]);
$ok($one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$D1") === 'node//' . str_repeat('M', 24), 'a node id set by an administrator is not overwritten by what the device reports');
Devices::setMeshNode($D1, null, 1);
ea_checkin($T1, ['inventory' => ['hostname' => 'DEPT1-PC', 'mesh_node_id' => 'node//' . str_repeat('Z', 24)]]);
$ok($one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$D1") === 'node//' . str_repeat('Z', 24) && $one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id=$D1") === 'agent', 'an unmapped device\'s reported mesh_node_id is adopted (source agent)');
ea_checkin($T1, ['inventory' => ['hostname' => 'DEPT1-PC', 'mesh_node_id' => 'garbage; DROP TABLE x']]);
$ok($one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id=$D1") === 'node//' . str_repeat('Z', 24), 'a malformed reported node id is ignored');

// ---------------------------------------------------------------- web: real pages and handlers with forged sessions
$sdir = sys_get_temp_dir() . '/ea_sessions_' . bin2hex(random_bytes(4)); mkdir($sdir);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$S = []; foreach ([1 => 'admin', 10 => 'tech', 11 => 'rebootonly', 12 => 'viewer', 13 => 'remoteonly', 14 => 'moduleonly', 15 => 'normo', 16 => 'deptb'] as $uid => $n) { $S[$n] = ea_forge_session($sdir, $uid); }
Config::set(['mesh_url' => $meshUrl]);
$q("UPDATE endpoint_agent_devices SET hostname='<img src=x onerror=alert(1)>', last_checkin_at='" . gmdate('Y-m-d H:i:s') . "', logged_in_user='\"><script>alert(2)</script>' WHERE device_id=$D1");
$q("UPDATE endpoint_agent_jobs SET state='succeeded', output='<script>alert(3)</script>', finished_at=NOW() WHERE device_id=$D1 AND state='queued' LIMIT 1");
Devices::setMeshNode($D1, 'node//' . str_repeat('A', 24) . $D1, 1);
foreach (['admin' => 200, 'tech' => 200, 'rebootonly' => 200, 'viewer' => 200, 'remoteonly' => 200, 'moduleonly' => 200, 'normo' => 403, 'deptb' => 403] as $who => $exp) {
    [$c, $body] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$D1", $S[$who]);
    $ok($c === $exp, "web device page as $who -> $exp (got $c)");
    if ($c === 200) {
        $ok(strpos($body, '<img src=x') === false && strpos($body, '&lt;img src=x') !== false && strpos($body, '<script>alert(') === false, "device page escapes hostile device-supplied strings ($who)");
        $ok((strpos($body, 'ea-remote') !== false) === in_array($who, ['admin', 'tech', 'remoteonly'], true), "remote button shown only to those who may use it ($who)");
        $ok((strpos($body, 'id="ea-job-form"') !== false) === in_array($who, ['admin', 'tech', 'rebootonly'], true), "job form shown only to those who may run jobs ($who)");
    }
}
[$c, $body] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=999999", $S['admin']); $ok($c === 403 || $c === 404, 'unknown device id -> denied page');
[$c, $body] = web($wb, 'GET', "/agent/rmm_agent_device.php?device_id=$D2", $S['deptb']); $ok($c === 200, 'department user sees their own department\'s device page');
$post = fn(string $who, array $f, bool $csrf = true) => web($wb, 'POST', '/agent/post/rmm_agent.php', $S[$who], $f + ($csrf ? ['csrf_token' => 'csrftok1'] : []));
[$c, $b] = $post('tech', ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'powershell', 'script' => 'Get-Date'], false); $ok($c === 403, 'web job submit without CSRF token -> 403');
[$c, $b] = $post('tech', ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'powershell', 'script' => 'Get-Date']); $j = json_decode($b, true); $ok($c === 200 && $j['success'] === true, 'web job submit as tech works');
foreach (['viewer', 'remoteonly', 'moduleonly', 'normo'] as $who) { [$c, $b] = $post($who, ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'powershell', 'script' => 'x']); $ok($c === 403 || $c === 404, "web job submit as $who denied by the HANDLER (got $c) even though the page hid the button"); }
[$c, $b] = $post('rebootonly', ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'powershell', 'script' => 'x']); $ok($c === 403, 'reboot-only role cannot submit free-form PowerShell through the web handler');
[$c, $b] = $post('rebootonly', ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'reboot', 'confirm' => '1']); $ok($c === 200, 'reboot-only role can reboot through the web handler');
[$c, $b] = $post('deptb', ['action' => 'submit_job', 'device_id' => $D1, 'type' => 'collect']); $ok($c === 404, 'department-restricted user cannot reach another department\'s device through the handler');
[$c, $b] = $post('remoteonly', ['action' => 'remote', 'device_id' => $D1]); $j = json_decode($b, true); $ok($c === 200 && strpos($j['url'] ?? '', '?login=') !== false, 'web remote launch as remote-only role works');
foreach (['viewer', 'rebootonly', 'moduleonly', 'normo'] as $who) { [$c] = $post($who, ['action' => 'remote', 'device_id' => $D1]); $ok($c === 403 || $c === 404, "web remote launch as $who denied (got $c)"); }
[$c] = $post('deptb', ['action' => 'remote', 'device_id' => $D1]); $ok($c === 404, 'web remote launch across departments -> 404');
[$c, $b] = $post('tech', ['action' => 'set_mesh_node', 'device_id' => $D1, 'mesh_node_id' => 'node//' . str_repeat('Q', 24)]); $ok($c === 403, 'only administrators may map a MeshCentral node');
[$c, $b] = $post('admin', ['action' => 'set_mesh_node', 'device_id' => $D1, 'mesh_node_id' => 'node//' . str_repeat('Q', 24)]); $ok($c === 200, 'an administrator can map a MeshCentral node');
// the existing "Connect" handler routes agent devices through the same checks
$link = (int) $one("SELECT id FROM asset_rmm_links WHERE tactical_agent_id='rivetit:$D1'");
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote.php', $S['remoteonly'], ['csrf_token' => 'csrftok1', 'link_id' => $link, 'type' => 'tactical']); $j = json_decode($b, true);
$ok($j['success'] === true && strpos($j['url'], '?login=') !== false, 'the existing Connect button works for an agent device through MeshCentral');
[$c, $b] = web($wb, 'POST', '/agent/post/rmm_remote.php', $S['viewer'], ['csrf_token' => 'csrftok1', 'link_id' => $link, 'type' => 'tactical']); $ok($c === 403 || (json_decode($b, true)['success'] ?? true) === false, 'the existing Connect handler still denies a viewer');

// admin page + settings handler
[$c, $body] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S['admin']);
$ok($c === 200 && strpos($body, 'Endpoint agent') !== false && strpos($body, 'Enrollment tokens') !== false && strpos($body, '&lt;img src=x') !== false && strpos($body, '<img src=x') === false, 'admin page renders and escapes device-supplied hostnames');
$ok(strpos($body, $KEY) === false && strpos($body, 'ENC2:') === false, 'the MeshCentral login key and encrypted values never reach the page');
foreach (['tech', 'viewer', 'moduleonly'] as $who) { [$c] = web($wb, 'GET', '/admin/settings_endpoint_agent.php', $S[$who]); $ok($c === 403, "admin page denied to $who"); }
[$c] = web($wb, 'POST', '/admin/post.php', $S['tech'], ['csrf_token' => 'csrftok1', 'device_action' => 'revoke', 'device_id' => $D1], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_id=$D1 AND revoked_at IS NOT NULL") === 0, 'a non-admin cannot revoke a device through the admin post handler');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['device_action' => 'revoke', 'device_id' => $D1], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE device_id=$D1 AND revoked_at IS NOT NULL") === 0, 'admin post without a CSRF token changes nothing');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['csrf_token' => 'csrftok1', 'create_enroll_token' => 1, 'client_id' => 1, 'ttl_hours' => 2, 'max_uses' => 3, 'label' => 'ui test'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens WHERE label='ui test' AND max_uses=3") === 1 && (int) $one("SELECT COUNT(*) FROM logs WHERE log_action='Enrollment Token Created'") === 1, 'admin creates an enrollment token through the UI handler (audited)');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['csrf_token' => 'csrftok1', 'save_mesh_settings' => 1, 'mesh_url' => 'ftp://evil', 'mesh_enabled' => 1], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok($one("SELECT mesh_url FROM endpoint_agent_settings") === $meshUrl, 'an invalid MeshCentral address is rejected by the settings handler');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['csrf_token' => 'csrftok1', 'save_mesh_settings' => 1, 'mesh_url' => $meshUrl, 'mesh_enabled' => 1, 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support', 'mesh_login_key' => ''], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok(decryptSetting((string) $one("SELECT mesh_login_key_enc FROM endpoint_agent_settings")) === $KEY, 'leaving the login key field empty keeps the stored key');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['csrf_token' => 'csrftok1', 'save_agent_settings' => 1, 'enabled' => 1, 'checks_json' => '[{"key":"x","type":"nope","interval_s":60}]'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((string) $one("SELECT checks_json FROM endpoint_agent_settings") === '' || $one("SELECT checks_json FROM endpoint_agent_settings") === null, 'invalid check definitions are rejected');
[$c] = web($wb, 'POST', '/admin/post.php', $S['admin'], ['csrf_token' => 'csrftok1', 'save_agent_settings' => 1, 'enabled' => 1, 'failure_debounce' => 5, 'unmatched_policy' => 'approval', 'checks_json' => '[{"key":"svc_spooler","type":"service","params":{"name":"Spooler"},"interval_s":120},{"key":"scr","type":"script","params":{"script":"(Get-Date).Year","timeout_s":10},"interval_s":600}]'], ['Referer: ' . $wb . '/admin/settings_endpoint_agent.php']);
$ok((int) $one("SELECT failure_debounce FROM endpoint_agent_settings") === 5 && count(Config::checks()) === 2, 'valid settings and check schedule save');
$sc = Config::signedChecks(); $ok(count($sc) === 2 && Mesh::validNodeId('node//' . str_repeat('A', 20)) && $sc[1]['type'] === 'script' && isset($sc[1]['signature']), 'script checks are delivered signed');
[, , $pubkey] = [0, 0, Config::get(true)['signing_public_key']];
$scObj = $sc[1]; $sig = $scObj['signature']; unset($scObj['signature']);
$ok(\ITFlow\EndpointAgent\Signer::verify(\ITFlow\EndpointAgent\Signer::canonical(json_decode(json_encode($scObj))), $sig, $pubkey), 'a delivered check definition verifies against the instance public key');

// existing RMM pages still render, with a Tactical-style link next to the agent link
$q("INSERT INTO rmm_integrations SET name='Tactical test', type='tactical_rmm', api_url='https://rmm.invalid', api_key_enc='', enabled=1"); $ti = (int) $db->insert_id;
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='TRMM-PC', asset_make='HP', asset_serial='TRMM-1', asset_client_id=1, asset_status='Active'"); $ta = (int) $db->insert_id;
$q("INSERT INTO asset_rmm_links SET asset_id=$ta, integration_id=$ti, tactical_agent_id='trmm-agent-1', hostname='TRMM-PC', rmm_status='online', last_seen=NOW(), os_name='Windows', last_sync=NOW()");
$agentAsset = (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$D1");
$q("UPDATE settings SET config_module_enable_rmm=1");
foreach (['/agent/rmm_dashboard.php', '/agent/rmm_assets.php', '/agent/rmm_checks.php', '/agent/rmm_alerts.php', '/agent/rmm_scripts.php', "/agent/rmm_assets.php?integration_id=" . (int) $one("SELECT id FROM rmm_integrations WHERE type='rivetit_agent'"), '/admin/settings_integrations.php', '/admin/settings.php'] as $path) {
    [$c, $body] = web($wb, 'GET', $path, $S['admin']);
    $ok($c === 200 && stripos($body, 'Fatal error') === false && stripos($body, 'Uncaught') === false, "existing page still renders: $path");
}
[$c, $body] = web($wb, 'GET', '/agent/rmm_assets.php', $S['admin']); $ok(strpos($body, 'TRMM-PC') !== false && strpos($body, '&lt;img src=x') !== false, 'RMM assets lists both the Tactical link and the agent device (escaped)');
[$c, $body] = web($wb, 'GET', "/agent/asset_details.php?asset_id=$ta", $S['admin']); $ok($c === 200 && strpos($body, 'TRMM-PC') !== false && strpos($body, 'Agent device') === false, 'a Tactical-linked asset page is unchanged');
[$c, $body] = web($wb, 'GET', "/agent/asset_details.php?asset_id=$agentAsset", $S['admin']); $ok($c === 200 && strpos($body, 'rmm_agent_device.php?device_id=' . $D1) !== false && strpos($body, '<img src=x onerror') === false, 'an agent-linked asset page shows the RMM card with a link to the device page (escaped)');
[$c, $body] = web($wb, 'GET', '/admin/settings_integrations.php', $S['admin']); $ok(strpos($body, 'RivetIT Endpoint Agent') === false, 'the synthetic integration is hidden from the RMM integrations list');
$ok(strpos(file_get_contents($web['log']), 'Fatal') === false && strpos(file_get_contents($EA['log']), 'Fatal') === false, 'no PHP fatals in the server logs');
