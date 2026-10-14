<?php
/*
 * Fixtures for tests/browser/rmm_rc17_remote_smoke.mjs, run after tests/browser/rmm_seed.php and rmm_p68_remote_extra.php on the same throwaway install:
 * points the MeshCentral settings at the smoke's mock Mesh server (a loopback http address; EA_ALLOW_INSECURE_HTTP allows it on a scratch install) with a fresh login key, and
 * sets the Mesh view values through Core's own settings API.
 *
 *   php tests/browser/rmm_rc17_extra.php <mesh port> [<mesh_hide> [<mesh_hide_tools or ->]]
 *
 * Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

// RivetCore 1.0.0-rc.18: `php tests/browser/rmm_rc17_extra.php hexnode <device id>` stores a fresh random MeshCentral node for the device the way an agent used to report it
// (node// plus 96 hex characters, written straight to the table) and prints {"url": <the 64-character URL form MeshCentral wants in gotonode>}.
if (($argv[1] ?? '') === 'hexnode') {
    $devId = (int) ($argv[2] ?? 0);
    do {
        $raw = random_bytes(48);
        $url = strtr(rtrim(base64_encode($raw), '='), '+/', '@$');
    } while (!str_contains($url, '@') || !str_contains($url, '$'));
    $db->query("UPDATE endpoint_agent_mesh_nodes SET mesh_node_id = 'node//" . bin2hex($raw) . "' WHERE device_id = $devId");
    if ($db->affected_rows !== 1) { fwrite(STDERR, "no mapped node for device $devId\n"); exit(1); }
    echo json_encode(['url' => $url]) . "\n";
    exit(0);
}
$port = (int) ($argv[1] ?? 0);
if ($port < 1024) { fwrite(STDERR, "usage: rmm_rc17_extra.php <mesh port> [hide [tools|-]]\n"); exit(2); }
$rmm = rivetRmmModule($db);
$rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'http://127.0.0.1:' . $port, 'mesh_domain' => '', 'mesh_account_template' => 'rivetit-support',
    'mesh_login_key_enc' => encryptSetting(\RivetCore\Rmm\Mesh\MeshCookie::newLoginKey())]);
if (isset($argv[2])) {
    $in = ['mesh_hide' => (int) $argv[2]];
    if (isset($argv[3])) { $in['mesh_hide_tools'] = $argv[3] === '-' ? null : (int) $argv[3]; }
    $err = $rmm->meshAgentSettings()->update($in, 1);
    if ($err !== []) { fwrite(STDERR, implode(' ', $err) . "\n"); exit(1); }
}
$rmm->meshAgentSettings()->refresh();
$rmm->settings()->refresh();
$rmm->state()->forget();
echo json_encode(['hide' => $rmm->meshAgentSettings()->get()['hide'], 'hide_tools' => $rmm->meshAgentSettings()->get()['hide_tools']]) . "\n";
