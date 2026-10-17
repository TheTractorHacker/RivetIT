<?php
/*
 * Fixtures for tests/browser/rmm_rc20_patch_smoke.mjs (RMM Phase 4, patch management, RivetCore rc.20), run on the same throwaway install after tests/browser/rmm_seed.php.
 *
 *   php tests/browser/rmm_rc20_patch_extra.php <seed.json> setup   switch the `patching` switch on (the way Administration does it), enroll one Windows device in Dept A and one Linux
 *                                                                  device in Dept B through the real endpoint, let each answer a patch scan (through GET/POST /api/v1/agent_jobs),
 *                                                                  and give user 16 (a technician limited to Dept B) a login (deptb@scratch.test / Scratch-Deptb-1234)
 *   php tests/browser/rmm_rc20_patch_extra.php <seed.json> answer  let a device answer the next job it is offered (a dry run or an install) with a matching result
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$mode = $argv[2] ?? 'setup';
$stateFile = sys_get_temp_dir() . '/rmm_rc20_patch_state_' . md5(__DIR__ . getenv('RIVETIT_TEST_DB_NAME')) . '.json';
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$caps = ['job:collect', 'job:patch_scan', 'job:patch_install', 'patch:scan', 'patch:install'];
$iso = fn (int $ago = 0) => gmdate('Y-m-d\TH:i:s\Z', time() - $ago);
$offered = function (string $tok): array { [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok); return $c === 200 ? ($j['jobs'] ?? []) : []; };
$report = fn (string $tok, array $job, string $out) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => 'succeeded', 'exit_code' => 0, 'output' => $out, 'started_at' => $iso(5), 'finished_at' => $iso()]);
$GU = ['crit' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'sec' => 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb', 'opt' => 'cccccccc-3333-4333-8333-cccccccccccc', 'host' => 'dddddddd-4444-4444-8444-dddddddddddd', 'drv' => 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'];

if ($mode === 'setup') {
    $f = $rmm->settings()->features();
    $f['patching'] = true;
    $r = $rmm->admin()->saveSettings($admin, ['features_json' => $f]);
    if (!$r->ok) {
        fwrite(STDERR, 'could not switch patching on: ' . $r->message . "\n");
        exit(1);
    }
    $rmm->syncState();
    $tok = ['A' => (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'patch smoke A')->data['token'], 'B' => (string) $rmm->technician()->createToken($admin, 2, 0, 'stable', 24, 20, 'patch smoke B')->data['token']];
    $out = ['dev' => [], 'asset' => [], 'token' => [], 'guid' => $GU];
    foreach (['PSMOKE-WIN' => [1, 'A', 'windows'], 'PSMOKE-LNX' => [2, 'B', 'linux']] as $n => [$client, $tk, $os]) {
        $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
        $out['asset'][$n] = (int) $db->insert_id;
        [$c, , $j] = ea_enroll($tok[$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
        if ($c !== 201) {
            fwrite(STDERR, "enroll $n failed with $c\n");
            exit(1);
        }
        $out['dev'][$n] = (int) $j['device_id'];
        $out['token'][$n] = (string) $j['device_token'];
        ea_checkin($out['token'][$n], ['capabilities' => $caps]);
    }
    $upd = fn (string $id, string $kind, string $title, array $x = []) => $x + ['id' => $id, 'kb' => 'KB5000001', 'title' => $title, 'kind' => $kind, 'severity' => $kind === 'optional' || $kind === 'driver' ? 'unspecified' : 'important', 'size' => 52428800,
        'reboot_required' => $kind === 'security', 'released_at' => $iso(86400 * 3), 'eula_accepted' => true, 'categories' => ['Updates'], 'current' => '', 'version' => ''];
    $win = [$upd($GU['crit'], 'critical', '2026-10 Cumulative Update for Windows 11 (KB5000001)'), $upd($GU['sec'], 'security', 'Security Intelligence Update for Microsoft Defender'), $upd($GU['opt'], 'optional', '.NET 9 optional update'),
        $upd($GU['drv'], 'driver', 'Intel - Net - 22.250.1.2', ['kb' => '']),
        $upd($GU['host'], 'optional', '<script>alert(1)</script> "quoted" \'single\' & </td></tr><img src=x onerror=alert(2)>', ['kb' => 'KB<b>9</b>'])];
    $doc = fn (array $list, string $mgr, array $over = []) => (string) json_encode($over + ['v' => 1, 'ok' => true, 'manager' => $mgr, 'scanned_at' => $iso(), 'scan_state' => 'ok', 'wu_state' => $mgr === 'wua' ? 'running' : 'n/a', 'reboot_pending' => $mgr === 'apt',
        'last_install_at' => $iso(86400 * 9), 'truncated' => false, 'total' => count($list), 'updates' => $list]);
    $lin = [$upd('openssl', 'security', 'openssl', ['kb' => '', 'current' => '3.0.1', 'version' => '3.0.2', 'size' => 2048]), $upd('libc6:amd64', 'optional', 'libc6', ['kb' => '', 'current' => '2.39', 'version' => '2.40', 'size' => 4096])];
    foreach (['PSMOKE-WIN' => $doc($win, 'wua'), 'PSMOKE-LNX' => $doc($lin, 'apt')] as $n => $payload) {
        $r = $rmm->patchActions()->scan($admin, $out['dev'][$n]);
        $job = null;
        foreach ($offered($out['token'][$n]) as $j) {
            if ($j['type'] === 'patch_scan') {
                $job = $j;
            }
        }
        if (!$r->ok || $job === null) {
            fwrite(STDERR, "scan of $n was not queued or offered: " . $r->message . "\n");
            exit(1);
        }
        $report($out['token'][$n], $job, $payload);
    }
    // the restricted user's login (user 16: technician role, Dept B only)
    $q("UPDATE users SET user_email='deptb@scratch.test', user_name='Dept B Tech', user_password='" . $db->real_escape_string(password_hash('Scratch-Deptb-1234', PASSWORD_DEFAULT)) . "' WHERE user_id=16");
    $q('INSERT IGNORE INTO user_settings (user_id) VALUES (16)');
    foreach ([2, 4] as $role) {
        $q("INSERT IGNORE INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2");
    }
    $out['updates'] = (int) $one('SELECT COUNT(*) FROM rmm_patch_available');
    file_put_contents($stateFile, json_encode($out));
    echo json_encode($out) . "\n";
    exit(0);
}

$st = json_decode((string) file_get_contents($stateFile), true);
if ($mode === 'answer') {
    // answer the next patch_install job the Windows device is offered, the way the agent would: every named id installed (or would be, in a dry run)
    $n = $argv[3] ?? 'PSMOKE-WIN';
    foreach ($offered($st['token'][$n]) as $job) {
        if ($job['type'] !== 'patch_install') {
            continue;
        }
        $params = json_decode(json_encode($job), true)['params'] ?? [];
        $ids = array_filter(explode(',', (string) ($params['ids'] ?? '')));
        $dry = ($params['mode'] ?? 'dry_run') === 'dry_run';
        $results = array_map(fn (string $id): array => ['id' => $id, 'title' => $id, 'result' => $dry ? 'would_install' : 'installed', 'code' => 0, 'detail' => $dry ? 'would install' : 'installed', 'reboot_required' => false], array_values($ids));
        $report($st['token'][$n], $job, (string) json_encode(['v' => 1, 'ok' => true, 'mode' => $params['mode'] ?? 'dry_run', 'manager' => 'wua', 'reboot_required' => false, 'truncated' => false, 'results' => $results]));
        echo json_encode(['answered' => $job['job_id'], 'mode' => $params['mode'] ?? '', 'ids' => count($results)]) . "\n";
        exit(0);
    }
    echo json_encode(['answered' => null]) . "\n";
    exit(0);
}
exit(2);
