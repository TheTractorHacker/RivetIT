<?php
/*
 * Fixtures for the RMM asset panel / fleet page tests (tests/rmm_ui.php) and the browser smoke (tests/browser/rmm_seed.php): a small fleet built
 * through the REAL enrollment and check-in endpoints of a scratch install, so every number on the pages comes from rows the product itself wrote.
 *
 * Needs tests/endpoint_agent_lib.php loaded first (scratch database guard, `php -S` server, http()/ea_* helpers). Scratch databases only.
 */

use RivetCore\Rmm\Authz\RmmPrincipal;

/**
 * Wipe, seed users and the fleet, switch the module on. Returns ids by name:
 * ['dev' => name => device_id, 'asset' => name => asset_id, 'token' => name => device credential, 'users' => name => api token].
 *
 * Devices: WIN1 (Dept A, online, metrics, inventory, a failing check that opened an alert and a critical disk), LNX1 (Dept B, Linux, online),
 * OFFL (Dept A, quiet an hour), STALE (Dept A, quiet 10 days), NEVER (Dept A, enrolled, never checked in), PEND (Dept A, no matching asset: waiting for approval),
 * HOST (Dept A, hostile strings everywhere), BARE (Dept A, online, reports NO metrics and no inventory: every reading is missing).
 */
function rmm_ui_seed(bool $mesh = true): array
{
    global $q, $one, $db;
    require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';
    ea_reset();
    $users = ea_seed_users();
    $rmm = rivetRmmModule($db);
    $admin = new RmmPrincipal(1, 'admin');
    $rmm->admin()->enable($admin);
    $rmm->settings()->set(['check_in_interval_s' => 300, 'offline_after_s' => 900, 'stale_after_s' => 604800]);
    if ($mesh) {
        $rmm->settings()->set(['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.test', 'mesh_domain' => '', 'mesh_login_key_enc' => encryptSetting(bin2hex(random_bytes(16))), 'mesh_account_template' => 'rivetit-support']);
    }
    $tokA = (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 50, 'ui seed A')->data['token'];
    $tokB = (string) $rmm->technician()->createToken($admin, 2, 0, 'pilot', 24, 50, 'ui seed B')->data['token'];
    $q("INSERT INTO rmm_scripts SET name='Disk cleanup', script_type='powershell', script_body='Get-Date', enabled=1");

    $out = ['dev' => [], 'asset' => [], 'token' => [], 'users' => $users, 'enroll' => ['A' => $tokA, 'B' => $tokB]];
    $mk = function (string $name, int $client, string $enrollToken, array $dev = [], bool $asset = true) use ($q, $db, &$out): int {
        $serial = 'SER-' . $name;
        if ($asset) {
            $q("INSERT INTO assets SET asset_type='Laptop', asset_name='" . $db->real_escape_string($name) . "', asset_make='Dell', asset_serial='$serial', asset_client_id=$client, asset_status='Active'");
            $out['asset'][$name] = (int) $db->insert_id;
        }
        [$c, , $j] = ea_enroll($enrollToken, ea_dev($dev + ['serial' => $serial, 'hostname' => $name]));
        if ($c !== 201) {
            throw new RuntimeException("enroll $name failed with $c");
        }
        $out['dev'][$name] = (int) $j['device_id'];
        $out['token'][$name] = (string) $j['device_token'];

        return (int) $j['device_id'];
    };
    $inv = static fn (array $over = []): array => $over + ['hostname' => 'x', 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => 'S',
        'cpu' => ['model' => 'Intel i7-1355U', 'cores' => 10], 'memory_total_bytes' => 17179869184,
        'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 46000000000, 'fs' => 'NTFS'], ['mount' => 'D:', 'total_bytes' => 1000000000000, 'free_bytes' => 457000000000, 'fs' => 'NTFS']],
        'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5']]], 'uptime_s' => 183420, 'logged_in_user' => 'ACME\\alex', 'pending_reboot' => false];

    // WIN1: healthy except one critical disk and a failing check (three bad results in a row open an alert)
    $mk('WIN1', 1, $tokA, ['agent_version' => '1.0.0']);
    $checks = fn(string $st) => [['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running'], ['key' => 'disk_c', 'status' => $st, 'detail' => 'C: is 91% full'], ['key' => 'pending_reboot', 'status' => 'ok', 'detail' => 'no']];
    for ($i = 0; $i < 3; $i++) {
        ea_checkin($out['token']['WIN1'], ['agent_version' => '1.0.0', 'inventory' => $i === 0 ? $inv(['hostname' => 'WIN1']) : null,
            'metrics' => ['cpu_pct' => 14.2, 'mem_pct' => 56.1, 'disk' => [['mount' => 'C:', 'used_pct' => 91.0], ['mount' => 'D:', 'used_pct' => 54.3]], 'net_rx_bps' => 6200000.0, 'net_tx_bps' => 2200000.0],
            'checks' => $checks('fail')]);
    }
    // a finished job with output, a failed job and a queued one
    $jobs = $rmm->technician();
    $r = $jobs->submitJob($admin, $out['dev']['WIN1'], ['type' => 'collect']);
    $out['job_collect'] = (string) $r->data['job_id'];
    $q("UPDATE endpoint_agent_jobs SET state='succeeded', started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 MINUTE), finished_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 19 MINUTE), exit_code=0, output='collected: 2 disks, 1 adapter', created_by=1 WHERE job_id='" . $out['job_collect'] . "'");
    $r = $jobs->submitJob($admin, $out['dev']['WIN1'], ['type' => 'powershell', 'script' => 'Get-Date', 'timeout_s' => 60]);
    $out['job_failed'] = (string) $r->data['job_id'];
    $q("UPDATE endpoint_agent_jobs SET state='failed', reason='nonzero_exit', started_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE), finished_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 9 MINUTE), exit_code=1, output='boom <script>alert(1)</script>', created_by=10 WHERE job_id='" . $out['job_failed'] . "'");
    $r = $jobs->submitJob($admin, $out['dev']['WIN1'], ['type' => 'collect']);
    $out['job_queued'] = (string) $r->data['job_id'];
    $q("UPDATE endpoint_agent_devices SET agent_version='1.0.0' WHERE device_id=" . $out['dev']['WIN1']);

    // LNX1 in Dept B (pilot ring)
    $mk('LNX1', 2, $tokB, ['os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'arch' => 'amd64', 'agent_version' => '1.1.0']);
    ea_checkin($out['token']['LNX1'], ['agent_version' => '1.1.0', 'inventory' => $inv(['hostname' => 'LNX1', 'os' => 'linux', 'os_version' => 'Ubuntu 24.04', 'disks' => [['mount' => '/', 'total_bytes' => 100000000000, 'free_bytes' => 40000000000, 'fs' => 'ext4']]]),
        'metrics' => ['cpu_pct' => 41.0, 'mem_pct' => 78.0, 'disk' => [['mount' => '/', 'used_pct' => 60.0]], 'net_rx_bps' => 1000.0, 'net_tx_bps' => 2000.0], 'checks' => []]);

    // OFFL, STALE, NEVER, PEND, HOST, BARE
    $mk('OFFL', 1, $tokA);
    ea_checkin($out['token']['OFFL'], ['inventory' => $inv(['hostname' => 'OFFL']), 'metrics' => ['cpu_pct' => 22.0, 'mem_pct' => 30.0, 'disk' => [['mount' => 'C:', 'used_pct' => 40.0]], 'net_rx_bps' => 10.0, 'net_tx_bps' => 10.0], 'checks' => [['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running']]]);
    $q("UPDATE endpoint_agent_devices SET last_checkin_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE device_id=" . $out['dev']['OFFL']);
    $mk('STALE', 1, $tokA);
    ea_checkin($out['token']['STALE'], ['inventory' => $inv(['hostname' => 'STALE'])]);
    $q("UPDATE endpoint_agent_devices SET last_checkin_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY) WHERE device_id=" . $out['dev']['STALE']);
    $mk('NEVER', 1, $tokA);
    $mk('PEND', 1, $tokA, ['serial' => 'NO-SUCH-SERIAL', 'hostname' => 'PEND'], false);
    $mk('HOST', 1, $tokA, ['hostname' => 'HOST<script>alert(9)</script>"\'', 'model' => '<img src=x onerror=alert(1)>']);
    ea_checkin($out['token']['HOST'], ['inventory' => $inv(['hostname' => 'HOST<script>alert(9)</script>"\'', 'cpu' => ['model' => '<b>CPU</b>"\'', 'cores' => 2], 'logged_in_user' => '<script>alert(2)</script>',
        'network' => [['name' => '<i>eth</i>', 'mac' => '<x>', 'ips' => ['<script>']]]]), 'metrics' => ['cpu_pct' => 5.0, 'mem_pct' => 5.0, 'disk' => [], 'net_rx_bps' => 1.0, 'net_tx_bps' => 1.0],
        'checks' => [['key' => 'svc_evil', 'status' => 'warn', 'detail' => '<script>alert(3)</script> ' . str_repeat('A', 400)]]]);
    $mk('BARE', 1, $tokA);
    ea_checkin($out['token']['BARE'], ['inventory' => null, 'metrics' => ['disk' => []], 'checks' => []]);

    foreach ($out['dev'] as $n => $id) {
        $out['link'][$n] = (int) $one("SELECT id FROM asset_rmm_links WHERE tactical_agent_id='rivetit:$id'");
    }

    return $out;
}
