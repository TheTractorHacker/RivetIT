<?php
/*
 * Extra fixtures for tests/browser/rmm_p68_remote_smoke.mjs, run after tests/browser/rmm_seed.php on the same throwaway install: the remote_tools and filetransfer
 * switches ON through RemoteActions::updateSettings() (with the acknowledgement, an allowed folder per platform and a small transfer size cap), a Linux server in
 * Dept A that announces the remote capabilities (the browser answers its jobs through the real device endpoints), its MeshCentral node, and a login for the
 * technician (user 10, level 3 scripts, allowed by the lvl3 setting) so the smoke can show what a non-administrator sees.
 * Prints one JSON line with the ids and the device credential. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['remote_tools' => true, 'filetransfer' => true], 'accept_filetransfer_risk' => true,
    'transfer_allow' => ['linux' => ['/srv/data', '/var/backups/site'], 'windows' => ['C:\\Data\\Share']], 'limits' => ['transfer_max_bytes' => 20000]]);
if (!$r->ok) { fwrite(STDERR, 'settings: ' . $r->message . "\n"); exit(1); }
$q("UPDATE settings SET config_rmm_remote_manage_lvl3 = 1 WHERE company_id = 1");
$pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$q("UPDATE users SET user_email='tech@scratch.test', user_name='Scratch Tech', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=10");
$q("INSERT IGNORE INTO user_settings (user_id) VALUES (10)");
foreach ([2] as $role) { $q("INSERT IGNORE INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2"); }

$tok = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 5, 'remote smoke')->data['token'];
$q("INSERT INTO assets SET asset_type='Server', asset_name='SRV-REMOTE', asset_make='Dell', asset_serial='SER-SRVREMOTE', asset_client_id=1, asset_status='Active'");
$asset = (int) $db->insert_id;
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'SER-SRVREMOTE', 'hostname' => 'SRV-REMOTE', 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '1.2.0']));
if ($c !== 201) { fwrite(STDERR, "enroll failed: $c\n"); exit(1); }
$devId = (int) $j['device_id'];
$devTok = (string) $j['device_token'];
$caps = ['job:collect', 'job:shell', 'job:svc_list', 'job:svc_control', 'job:proc_list', 'job:proc_kill', 'job:file_get', 'job:file_put', 'remote:services', 'remote:processes', 'remote:files'];
ea_checkin($devTok, ['agent_version' => '1.2.0', 'capabilities' => $caps, 'inventory' => ['hostname' => 'SRV-REMOTE', 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'cpu' => ['model' => 'AMD EPYC', 'cores' => 8], 'memory_total_bytes' => 17179869184,
    'disks' => [['mount' => '/', 'total_bytes' => 100000000000, 'free_bytes' => 60000000000, 'fs' => 'ext4']], 'uptime_s' => 86400], 'metrics' => ['cpu_pct' => 12.0, 'mem_pct' => 33.0, 'disk' => [['mount' => '/', 'used_pct' => 40.0]], 'net_rx_bps' => 1000.0, 'net_tx_bps' => 1000.0]]);
$rmm->technician()->setMeshNode($admin, $devId, 'node//' . str_repeat('Ab3', 8));
echo json_encode(['dev' => $devId, 'asset' => $asset, 'token' => $devTok, 'password' => $pw]) . "\n";
