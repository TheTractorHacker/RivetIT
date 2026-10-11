<?php
/*
 * Fixtures for tests/browser/rmm_rc13_smoke.mjs (RivetCore rc.12 optional MeshCentral agent, rc.13 RMM starter pack), run after tests/browser/rmm_seed.php on the same
 * throwaway install. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies). Each mode runs in a FRESH process and prints one JSON line.
 *
 *   php tests/browser/rmm_rc13_extra.php setup            MeshCentral on (https://mesh.example.test, a login key, no group id), a technician login, and five Windows devices with
 *                                                         Mesh agent states: MESHOK (mapped), MESHDUP (conflict with MESHOK), MESHMAN (kept by hand), MESHERR (install error),
 *                                                         MESHNONE (never reported). Prints the asset ids.
 *   php tests/browser/rmm_rc13_extra.php mesh <url> <pin|-> [overwrite 0|1]   set the Mesh address (and a pin) the way the settings card would
 *   php tests/browser/rmm_rc13_extra.php nokey | key      take the instance signing key away / put it back (key is kept in a file next to the config)
 *   php tests/browser/rmm_rc13_extra.php edit <item-id>   an administrator edits an installed starter script (saves a new version through the technician API)
 *   php tests/browser/rmm_rc13_extra.php scripts          the number of library scripts and the starter ones (JSON)
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$mode = $argv[1] ?? '';
$keyFile = sys_get_temp_dir() . '/rmm_rc13_signing_key.json';
if ($mode === 'setup') {
    $pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
    $q("UPDATE users SET user_email='tech@scratch.test', user_name='Scratch Tech', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=10");
    $q("INSERT IGNORE INTO user_settings (user_id) VALUES (10)");
    $q("INSERT IGNORE INTO user_role_permissions SET user_role_id=2, module_id=6, user_role_permission_level=2");
    $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting(bin2hex(random_bytes(16))), 'mesh_account_template' => 'rivetit-support']);
    $rmm->syncState();
    $tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'rc13 smoke')->data['token'];
    $assets = [];
    $dev = [];
    $node = fn (string $t) => 'node//' . str_repeat($t, 8);
    $caps = ['job:collect', 'job:shell', 'mesh:nodeid'];
    foreach (['MESHOK', 'MESHDUP', 'MESHMAN', 'MESHERR', 'MESHNONE'] as $n) {
        $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=1, asset_status='Active'");
        $assets[$n] = (int) $db->insert_id;
        [$c, , $j] = ea_enroll($tok, ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => 'windows']));
        if ($c !== 201) { fwrite(STDERR, "enroll $n failed: $c\n"); exit(1); }
        $dev[$n] = ['id' => (int) $j['device_id'], 'token' => (string) $j['device_token']];
        ea_checkin($dev[$n]['token'], ['capabilities' => $caps]);
    }
    ea_checkin($dev['MESHOK']['token'], ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
    ea_checkin($dev['MESHDUP']['token'], ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Ab3')]]);
    $rmm->technician()->setMeshNode($admin, $dev['MESHMAN']['id'], $node('Ef6'));
    ea_checkin($dev['MESHMAN']['token'], ['mesh_agent' => ['installed' => true, 'version' => '1.1.4', 'node_id' => $node('Gh7')]]);
    ea_checkin($dev['MESHERR']['token'], ['mesh_agent' => ['installed' => false, 'error' => 'the Mesh server answered HTTP 404']]);
    echo json_encode(['assets' => $assets, 'password' => $pw]), "\n";
} elseif ($mode === 'mesh') {
    $url = (string) ($argv[2] ?? 'https://mesh.example.test');
    $pin = (string) ($argv[3] ?? '-');
    $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => $url]);
    $rmm->meshAgentSettings()->update(['mesh_server_cert_pin' => $pin === '-' ? '' : $pin, 'mesh_auto_map_overwrite' => ($argv[4] ?? '0') === '1'], 1);
    echo json_encode(['mesh' => $url]), "\n";
} elseif ($mode === 'nokey') {
    $row = $db->query('SELECT signing_private_key_enc, signing_public_key FROM endpoint_agent_settings WHERE id=1')->fetch_assoc();
    if ((string) $row['signing_public_key'] !== '') {
        file_put_contents($keyFile, json_encode($row));
    }
    $q("UPDATE endpoint_agent_settings SET signing_private_key_enc=NULL, signing_public_key='' WHERE id=1");
    echo json_encode(['key' => 'removed']), "\n";
} elseif ($mode === 'key') {
    $row = is_file($keyFile) ? json_decode((string) file_get_contents($keyFile), true) : null;
    if (!is_array($row)) { fwrite(STDERR, "no saved key\n"); exit(1); }
    $q("UPDATE endpoint_agent_settings SET signing_private_key_enc='" . $db->real_escape_string((string) $row['signing_private_key_enc']) . "', signing_public_key='" . $db->real_escape_string((string) $row['signing_public_key']) . "' WHERE id=1");
    echo json_encode(['key' => 'restored']), "\n";
} elseif ($mode === 'edit') {
    $item = preg_replace('/[^a-z0-9-]/', '', (string) ($argv[2] ?? ''));
    $id = (int) $one("SELECT s.script_id FROM rmm_scripts_v2 s JOIN rmm_script_versions v ON v.script_id = s.script_id WHERE v.note LIKE 'starter|rivet-core-starter|" . $item . "|%' LIMIT 1");
    if ($id <= 0) { fwrite(STDERR, "not installed: $item\n"); exit(1); }
    $cur = (string) $one("SELECT v.body FROM rmm_script_versions v JOIN rmm_scripts_v2 s ON s.script_id = v.script_id AND s.current_version = v.version WHERE s.script_id = $id");
    $r = rivetRmmAutoApi($db, 1, 'admin', 'PUT', ['scripts', $id], [], ['body' => $cur . "\n# edited by the administrator\n", 'note' => 'my change']);
    echo json_encode(['ok' => $r['ok'], 'script_id' => $id, 'error' => $r['error']]), "\n";
} elseif ($mode === 'scripts') {
    echo json_encode(['all' => (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2'), 'starter' => (int) $one("SELECT COUNT(DISTINCT script_id) FROM rmm_script_versions WHERE note LIKE 'starter|%'"),
        'retired' => (int) $one('SELECT COUNT(*) FROM rmm_scripts_v2 WHERE retired_at IS NOT NULL'), 'policies' => (int) $one('SELECT COUNT(*) FROM rmm_policies')]), "\n";
} else {
    fwrite(STDERR, "usage: setup | mesh <url> <pin|-> [0|1] | nokey | key | edit <item> | scripts\n");
    exit(2);
}
