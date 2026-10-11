<?php
/*
 * Fixtures for tests/browser/rmm_p68_logs_smoke.mjs, run on the same throwaway install after tests/browser/rmm_seed.php.
 *
 *   php tests/browser/rmm_p68_logs_extra.php <seed.json> setup   switch the `logs` switch on (with the storage acknowledgement and one source per platform), enroll one device in
 *                                                                Dept A and one in Dept B through the real endpoint, send their log lines through /api/v1/agent_logs, and give
 *                                                                user 16 (a technician limited to Dept B) a login (deptb@scratch.test / Scratch-Deptb-1234)
 *   php tests/browser/rmm_p68_logs_extra.php <seed.json> alert   send the lines that match the rule the smoke creates ("smoke disk failing"), through the same endpoint
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmPrincipal;

$mode = $argv[2] ?? 'setup';
$stateFile = sys_get_temp_dir() . '/rmm_p68_logs_state_' . md5(__DIR__ . getenv('RIVETIT_TEST_DB_NAME')) . '.json';
$rmm = rivetRmmModule($db);
$admin = new RmmPrincipal(1, 'admin');
$ev = fn (int $ago, string $level, string $src, ?int $code, string $msg) => ['ts' => gmdate('Y-m-d\TH:i:s\Z', time() - $ago), 'level' => $level, 'source' => $src, 'event_id' => $code, 'message' => $msg];
$send = fn (string $token, array $events) => http('POST', '/api/v1/agent_logs', $token, ['events' => $events, 'dropped' => 0]);

if ($mode === 'setup') {
    $srcs = ['linux' => [['kind' => 'journal', 'units' => ['ssh.service'], 'identifiers' => [], 'min_level' => 'warning', 'contains' => []]],
        'windows' => [['kind' => 'eventlog', 'channel' => 'Security', 'levels' => ['critical', 'error', 'warning'], 'event_ids' => [], 'contains' => []]]];
    $r = $rmm->remoteActions()->updateSettings($admin, ['features' => ['logs' => true], 'accept_logs_storage' => true, 'logs_collect' => $srcs, 'limits' => ['logs_retention_days' => 30]]);
    if (!$r->ok) {
        fwrite(STDERR, 'could not switch logs on: ' . $r->message . "\n");
        exit(1);
    }
    $tok = ['A' => (string) $rmm->technician()->createToken($admin, 1, 0, 'stable', 24, 20, 'logs smoke A')->data['token'], 'B' => (string) $rmm->technician()->createToken($admin, 2, 0, 'stable', 24, 20, 'logs smoke B')->data['token']];
    $out = ['dev' => [], 'asset' => [], 'token' => []];
    foreach (['LGSMOKE-A' => [1, 'A', 'windows'], 'LGSMOKE-B' => [2, 'B', 'linux']] as $n => [$client, $tk, $os]) {
        $q("INSERT INTO assets SET asset_type='Laptop', asset_name='$n', asset_make='Dell', asset_serial='SER-$n', asset_client_id=$client, asset_status='Active'");
        $out['asset'][$n] = (int) $db->insert_id;
        [$c, , $j] = ea_enroll($tok[$tk], ea_dev(['serial' => "SER-$n", 'hostname' => $n, 'os' => $os, 'os_version' => $os === 'linux' ? 'Ubuntu 24.04' : 'Windows 11 23H2']));
        if ($c !== 201) {
            fwrite(STDERR, "enroll $n failed with $c\n");
            exit(1);
        }
        $out['dev'][$n] = (int) $j['device_id'];
        $out['token'][$n] = (string) $j['device_token'];
        ea_checkin($out['token'][$n], ['capabilities' => ['job:collect', 'logs:collect']]);
    }
    $lines = [];
    for ($i = 1; $i <= 120; $i++) {
        $lines[] = $ev(300 + $i * 7, $i % 9 === 0 ? 'warning' : 'info', $i % 2 ? 'Service Control Manager' : 'Application', $i % 2 ? 7036 : 1000, sprintf('BULK-%04d routine entry from the smoke fixture', $i));
    }
    $lines[] = $ev(20, 'error', 'Evil<b>src', 4625, '<script>alert(1)</script> "quoted" \'single\' & an account failed to log on DEPTA-SECRET-LINE');
    $lines[] = $ev(25, 'warning', 'Long.Source', null, str_repeat('The quick brown fox jumps over the lazy dog. ', 14) . 'LONGTAIL-MARKER');
    $lines[] = $ev(30, 'critical', 'Kernel', 41, 'The system rebooted without cleanly shutting down first');
    [$c, , $j] = $send($out['token']['LGSMOKE-A'], $lines);
    $out['sent_a'] = $j['accepted'] ?? $c;
    [$c, , $j] = $send($out['token']['LGSMOKE-B'], [$ev(10, 'error', 'sshd', null, 'DEPTB-ONLY-LINE Failed password for invalid user'), $ev(15, 'warning', 'sshd', null, 'DEPTB-ONLY-LINE connection reset by peer')]);
    $out['sent_b'] = $j['accepted'] ?? $c;
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
if ($mode === 'alert') {
    [$c, , $j] = $send($st['token']['LGSMOKE-A'], [$ev(2, 'error', 'smartd', null, 'Device /dev/sda: smoke disk failing, SMART health check failed')]);
    echo json_encode(['accepted' => $j['accepted'] ?? $c]) . "\n";
    exit(0);
}
fwrite(STDERR, "unknown mode\n");
exit(2);
