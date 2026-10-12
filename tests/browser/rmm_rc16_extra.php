<?php
/*
 * Fixtures for tests/browser/rmm_rc16_smoke.mjs (RivetCore rc.16: the pushed MeshCentral agent install and the MSI installer), run after tests/browser/rmm_seed.php on the same
 * throwaway install. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies). Each mode runs in a FRESH process and prints one JSON line.
 *
 *   php tests/browser/rmm_rc16_extra.php setup               Mesh on with a RANDOM placeholder device group id, the remote-tools switch on, a technician login, tag "rc16push" and four
 *                                                            Linux devices: PUSH1 (supports the push), PUSH2 (an old agent), PUSHI (already reports the Mesh agent), PUSHL (linked, no asset
 *                                                            page: the legacy device page keeps it). Prints the asset and device ids.
 *   php tests/browser/rmm_rc16_extra.php msi <file|none>     publish <file> as the current x64 MSI template, or remove every template
 *   php tests/browser/rmm_rc16_extra.php finish <device> <state> <result-json>   the device reports the open Mesh job (the signed job is taken from the database)
 *   php tests/browser/rmm_rc16_extra.php installed <device> 1|0                  the device's next check-in says the Mesh agent is installed (or gone)
 *   php tests/browser/rmm_rc16_extra.php limits <json>       saveSettings(limits_json): approval_bulk_threshold, bulk_run_max
 *   php tests/browser/rmm_rc16_extra.php jobs                the number of mesh_install / mesh_uninstall jobs per device (JSON)
 *   php tests/browser/rmm_rc16_extra.php templates           the MSI template rows with the SHA-256 of the stored files (JSON)
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$mode = $argv[1] ?? '';
$stateFile = sys_get_temp_dir() . '/rmm_rc16_state.json';
$st = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$fresh = function () use ($rmm): void { $rmm->meshAgentSettings()->refresh(); $rmm->settings()->refresh(); $rmm->state()->forget(); };

if ($mode === 'setup') {
    $pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
    $q("UPDATE users SET user_email='tech@scratch.test', user_name='Scratch Tech', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=10");
    $q("INSERT IGNORE INTO user_settings (user_id) VALUES (10)");
    $q("INSERT IGNORE INTO user_role_permissions SET user_role_id=2, module_id=6, user_role_permission_level=2");
    $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting(bin2hex(random_bytes(16))), 'mesh_account_template' => 'rivetit-support']);
    $errs = $rmm->meshAgentSettings()->update(['mesh_group_id' => bin2hex(random_bytes(48))], 1, 'https://mesh.example.test');   // a random placeholder: no real meshid anywhere in the repository
    if ($errs !== []) { fwrite(STDERR, implode(' ', $errs) . "\n"); exit(1); }
    $r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['remote_tools' => true]]);
    if (!$r->ok) { fwrite(STDERR, 'remote_tools: ' . $r->message . "\n"); exit(1); }
    $rmm->syncState();
    $tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'rc16 smoke')->data['token'];
    $caps = ['job:collect', 'job:shell', 'job:mesh_install', 'job:mesh_uninstall', 'mesh:nodeid'];
    $assets = []; $dev = []; $devTok = [];
    $rmm->inventory()->createTag($admin, ['name' => 'rc16push']);
    foreach (['PUSH1' => $caps, 'PUSH2' => ['job:collect', 'job:shell'], 'PUSHI' => $caps, 'PUSHL' => $caps] as $n => $c) {
        if ($n !== 'PUSHL') {
            $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=1, asset_status='Active'");
            $assets[$n] = (int) $db->insert_id;
        }
        [$code, , $j] = ea_enroll($tok, ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => 'linux', 'os_version' => 'Ubuntu 24.04']));
        if ($code !== 201) { fwrite(STDERR, "enroll $n failed: $code\n"); exit(1); }
        $dev[$n] = (int) $j['device_id'];
        $devTok[$n] = (string) $j['device_token'];
        ea_checkin($devTok[$n], ['capabilities' => $c]);
        if ($n !== 'PUSHL') { $rmm->inventory()->tagDevice($admin, $dev[$n], 'rc16push'); }
    }
    $q("UPDATE endpoint_agent_devices SET asset_id=NULL, link_state='linked', match_reason='' WHERE device_id=" . $dev['PUSHL']);
    ea_checkin($devTok['PUSHI'], ['capabilities' => $caps, 'mesh_agent' => ['installed' => true, 'version' => '1.2.3', 'node_id' => 'node//' . str_repeat('Ii', 20)]]);
    file_put_contents($stateFile, json_encode(['dev' => $dev, 'tok' => $devTok, 'caps' => $caps]));
    echo json_encode(['assets' => $assets, 'dev' => $dev, 'password' => $pw]), "\n";
} elseif ($mode === 'msi') {
    $f = (string) ($argv[2] ?? 'none');
    foreach ($rows('SELECT * FROM endpoint_agent_msi_templates') as $t) { @unlink($rmm->binaryStore()->pathFor($t)); }
    $q('DELETE FROM endpoint_agent_msi_templates');
    if ($f !== 'none') {
        $r = $rmm->binaryStore()->publishMsi($f, '0.6.0', 'amd64', 1, ['activate' => true]);
        if (!$r['ok']) { fwrite(STDERR, $r['error'] . "\n"); exit(1); }
    }
    echo json_encode(['msi' => $f === 'none' ? 'none' : 'published']), "\n";
} elseif ($mode === 'finish') {
    $dev = $st['dev'][(string) ($argv[2] ?? '')] ?? 0;
    $state = (string) ($argv[3] ?? 'succeeded');
    $out = (string) ($argv[4] ?? '{}');
    $job = $db->query("SELECT job_id FROM endpoint_agent_jobs WHERE device_id=$dev AND type LIKE 'mesh\\_%' AND state IN ('queued','running') ORDER BY created_at DESC LIMIT 1")->fetch_row()[0] ?? null;
    if ($job === null) { fwrite(STDERR, "no open mesh job on {$argv[2]}\n"); exit(1); }
    $q("UPDATE endpoint_agent_jobs SET state='" . $db->real_escape_string($state) . "', started_at=UTC_TIMESTAMP(), finished_at=UTC_TIMESTAMP(), exit_code=" . ($state === 'succeeded' ? 0 : 3) . ", output='" . $db->real_escape_string($out) . "' WHERE job_id='$job'");
    echo json_encode(['job' => $job, 'state' => $state]), "\n";
} elseif ($mode === 'installed') {
    $n = (string) ($argv[2] ?? '');
    $tok = $st['tok'][$n] ?? '';
    $yes = ($argv[3] ?? '1') === '1';
    [$code] = ea_checkin($tok, ['seq' => (int) (microtime(true) * 100) % 2000000000,   // every mode runs in a fresh process: the default sequence number would start at 1 again and be ignored as a replay
        'capabilities' => $st['caps'], 'mesh_agent' => $yes ? ['installed' => true, 'version' => '1.2.3', 'node_id' => 'node//' . str_repeat('Nn', 20)] : ['installed' => false]]);
    echo json_encode(['checkin' => $code]), "\n";
} elseif ($mode === 'limits') {
    $r = $rmm->admin()->saveSettings($admin, ['limits_json' => json_decode((string) ($argv[2] ?? '{}'), true) ?: []]);
    echo json_encode(['ok' => $r->ok]), "\n";
} elseif ($mode === 'jobs') {
    $o = [];
    foreach ($rows("SELECT d.hostname, j.type, j.state, COUNT(*) AS n FROM endpoint_agent_jobs j JOIN endpoint_agent_devices d ON d.device_id=j.device_id WHERE j.type LIKE 'mesh\\_%' GROUP BY d.hostname, j.type, j.state") as $r) {
        $o[$r['hostname']][$r['type'] . ':' . $r['state']] = (int) $r['n'];
    }
    echo json_encode($o ?: new stdClass()), "\n";
} elseif ($mode === 'templates') {
    $o = [];
    foreach ($rows('SELECT * FROM endpoint_agent_msi_templates ORDER BY template_id') as $t) {
        $o[] = ['version' => $t['version'], 'arch' => $t['arch'], 'sha256' => $t['sha256'], 'current' => (int) $t['is_current'], 'active' => (int) $t['active'], 'file_sha256' => is_file($rmm->binaryStore()->pathFor($t)) ? hash_file('sha256', $rmm->binaryStore()->pathFor($t)) : null];
    }
    echo json_encode($o), "\n";
} else {
    fwrite(STDERR, "usage: setup | msi <file|none> | finish <device> <state> <json> | installed <device> 1|0 | limits <json> | jobs | templates\n");
    exit(2);
}
