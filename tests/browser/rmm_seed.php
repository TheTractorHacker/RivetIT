<?php
/*
 * Seeds a THROWAWAY install for tests/browser/rmm_smoke.mjs: the small fleet of tests/support/rmm_ui_seed.php (real enrollment and check-in through the
 * install's own endpoints), then gives user 1 the login admin@scratch.test / the password in RMM_SMOKE_PASSWORD (default Scratch-Admin-1234) and writes
 * the ids to the JSON file named by the first argument.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/rmm_seed.php /tmp/rmm-seed.json
 *
 * The scratch config.php must define EA_ALLOW_NON_WINDOWS (the seed enrolls a Linux device).
 *
 * It WIPES the users, assets, clients and agent tables of that database (the scratch guard of tests/endpoint_agent_lib.php applies: the database name must
 * contain "scratch" and config.php must point at it). Run it just before the smoke; the devices are "online" for 15 minutes after their check-in.
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require dirname(__DIR__) . '/support/rmm_ui_seed.php';

$out = $argv[1] ?? (sys_get_temp_dir() . '/rmm-seed.json');
$S = rmm_ui_seed();
rmm_ui_seed_phase1($S);   // RivetCore 1.0.0-rc.9: software, tags, group, check history, network peak
$pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$q("UPDATE users SET user_email='admin@scratch.test', user_name='Scratch Admin', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=1");
$q("INSERT IGNORE INTO user_settings (user_id) VALUES (1)");   // the preferences page (theme switch) reads this row
// the asset page needs the Assets module for non-admin roles; the smoke signs in as the administrator, so nothing else is needed
$q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");
// "Add device" / "Download installer": the dialog needs the service URL (this install, loopback http is allowed by EA_ALLOW_INSECURE_HTTP) and a current
// agent binary per architecture. Placeholder executables with valid PE headers; RMM_SMOKE_BASE_URL is the URL the smoke browses (http://127.0.0.1:<port>).
$smokeBase = rtrim((string) getenv('RMM_SMOKE_BASE_URL'), '/');
if ($smokeBase !== '') {
    \ITFlow\EndpointAgent\Config::enable();
    \ITFlow\EndpointAgent\Config::set(['service_url' => $smokeBase]);
    $fakePe = function (int $machine, int $size): string {
        $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
        $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
        return $b . substr(str_repeat(hash('sha256', 'smoke' . $machine, true), intdiv($size, 32) + 1), 0, $size - strlen($b));
    };
    $q("DELETE FROM endpoint_agent_binaries");
    foreach (['amd64' => 0x8664, 'arm64' => 0xAA64] as $arch => $machine) {
        $f = tempnam(sys_get_temp_dir(), 'smokeexe'); file_put_contents($f, $fakePe($machine, 120000));
        $r = rivetRmmModule($db)->binaryStore()->publish($f, '1.0.0', $arch, 1, ['activate' => true]);
        @unlink($f);
        if (empty($r['ok'])) { fwrite(STDERR, "could not publish the $arch placeholder binary: " . ($r['error'] ?? '?') . "\n"); exit(1); }
    }
}
file_put_contents($out, json_encode(['dev' => $S['dev'], 'asset' => $S['asset'], 'job_collect' => $S['job_collect'], 'job_failed' => $S['job_failed'], 'job_queued' => $S['job_queued']], JSON_PRETTY_PRINT));
echo "seeded " . count($S['dev']) . " devices; ids in $out\n";
