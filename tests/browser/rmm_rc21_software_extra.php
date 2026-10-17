<?php
/*
 * Fixtures for tests/browser/rmm_rc21_software_smoke.mjs (RMM Phase 5, software deployment, RivetCore rc.21), run on the same throwaway install after tests/browser/rmm_seed.php.
 *
 *   php tests/browser/rmm_rc21_software_extra.php <seed.json> setup    switch the `software_deploy` switch on (the way Administration does it), allow one installer host, enroll one Linux device in
 *                                                                      Dept A and one in Dept B through the real endpoint (both announce software:detect / software:install), and give
 *                                                                      user 16 (a technician limited to Dept B) a login (deptb@scratch.test / Scratch-Deptb-1234). The catalog is left empty:
 *                                                                      the smoke creates it through the pages.
 *   php tests/browser/rmm_rc21_software_extra.php <seed.json> answer   let a device answer the next software job it is offered (a detection, a dry run or an install) the way the agent would
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$mode = $argv[2] ?? 'setup';
$stateFile = sys_get_temp_dir() . '/rmm_rc21_software_state_' . md5(__DIR__ . getenv('RIVETIT_TEST_DB_NAME')) . '.json';
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$caps = ['job:collect', 'job:software_detect', 'job:software_install', 'software:detect', 'software:install'];
$iso = fn (int $ago = 0) => gmdate('Y-m-d\TH:i:s\Z', time() - $ago);
$offered = function (string $tok): array { [$c, , $j] = http('GET', '/api/v1/agent_jobs', $tok); return $c === 200 ? ($j['jobs'] ?? []) : []; };
$report = fn (string $tok, array $job, string $out) => http('POST', '/api/v1/agent_jobs', $tok, ['job_id' => $job['job_id'], 'attempt' => $job['attempt'], 'state' => 'succeeded', 'exit_code' => 0, 'output' => $out, 'started_at' => $iso(5), 'finished_at' => $iso()]);

if ($mode === 'setup') {
    $f = $rmm->settings()->features();
    $f['software_deploy'] = true;
    $r = $rmm->admin()->saveSettings($admin, ['features_json' => $f]);
    if (!$r->ok) {
        fwrite(STDERR, 'could not switch software deployment on: ' . $r->message . "\n");
        exit(1);
    }
    $rmm->syncState();
    $h = $rmm->deployActions()->addHost($admin, ['host' => 'downloads.example.com', 'note' => 'smoke vendor']);
    if (!$h->ok) {
        fwrite(STDERR, 'could not allow the host: ' . $h->message . "\n");
        exit(1);
    }
    $tok = ['A' => (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'software smoke A')->data['token'], 'B' => (string) $rmm->technician()->createToken($admin, 2, 0, 'stable', 24, 20, 'software smoke B')->data['token']];
    $out = ['dev' => [], 'asset' => [], 'token' => []];
    foreach (['SSMOKE-LNX' => [1, 'A'], 'SSMOKE-B' => [2, 'B']] as $n => [$client, $tk]) {
        $q("INSERT INTO assets SET asset_type='Server', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
        $out['asset'][$n] = (int) $db->insert_id;
        [$c, , $j] = ea_enroll($tok[$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => 'linux', 'os_version' => 'Ubuntu 24.04']));
        if ($c !== 201) {
            fwrite(STDERR, "enroll $n failed with $c\n");
            exit(1);
        }
        $out['dev'][$n] = (int) $j['device_id'];
        $out['token'][$n] = (string) $j['device_token'];
        ea_checkin($out['token'][$n], ['capabilities' => $caps]);
    }
    // the restricted user's login (user 16: technician role, Dept B only)
    $q("UPDATE users SET user_email='deptb@scratch.test', user_name='Dept B Tech', user_password='" . $db->real_escape_string(password_hash('Scratch-Deptb-1234', PASSWORD_DEFAULT)) . "' WHERE user_id=16");
    $q('INSERT IGNORE INTO user_settings (user_id) VALUES (16)');
    foreach ([2, 4] as $role) {
        $q("INSERT IGNORE INTO user_role_permissions SET user_role_id=$role, module_id=6, user_role_permission_level=2");
    }
    file_put_contents($stateFile, json_encode($out));
    echo json_encode($out) . "\n";
    exit(0);
}

$st = json_decode((string) file_get_contents($stateFile), true);
if ($mode === 'answer') {
    // answer the next software job the device is offered, the way the agent would
    $n = $argv[3] ?? 'SSMOKE-LNX';
    foreach ($offered($st['token'][$n]) as $job) {
        $params = json_decode(json_encode($job), true)['params'] ?? [];
        if ($job['type'] === 'software_detect') {
            $rules = json_decode((string) ($params['rules'] ?? '[]'), true) ?: [];
            $installed = ($argv[4] ?? 'missing') === 'present';
            $items = array_map(fn (array $r): array => ['key' => $r['key'], 'installed' => $installed, 'version' => $installed ? '3.3.0' : '', 'satisfied' => $installed], $rules);
            $report($st['token'][$n], $job, (string) json_encode(['v' => 1, 'ok' => true, 'items' => $items]));
            echo json_encode(['answered' => $job['job_id'], 'type' => 'software_detect', 'rules' => count($rules)]) . "\n";
            exit(0);
        }
        if ($job['type'] === 'software_install') {
            $dry = ($params['mode'] ?? 'dry_run') === 'dry_run';
            $un = ($params['action'] ?? 'install') === 'uninstall';
            $result = $dry ? ($un ? 'would_uninstall' : 'would_install') : ($un ? 'uninstalled' : 'installed');
            $report($st['token'][$n], $job, (string) json_encode(['v' => 1, 'ok' => true, 'result' => $result, 'code' => '', 'detail' => ($dry ? 'dry run: ' : '') . ($params['pkg'] ?? '?') . ' ' . $result, 'version' => $un ? '' : '3.3.0', 'reboot_required' => false]));
            echo json_encode(['answered' => $job['job_id'], 'type' => 'software_install', 'mode' => $params['mode'] ?? '', 'action' => $params['action'] ?? '', 'pkg' => $params['pkg'] ?? '']) . "\n";
            exit(0);
        }
    }
    echo json_encode(['answered' => null]) . "\n";
    exit(0);
}
exit(2);
