<?php
/*
 * Golden replay: the HTTP transcripts RivetCore recorded from the ORIGINAL RivetIT endpoint agent code (rivet-core tests/Fixtures/rmm/golden, baseline
 * c26957c0b, 10 files covering the device API and the technician calls) are replayed against THIS install's bridges (api/v1/agent_*.php,
 * endpoint_devices.php) and must come out identical: status codes, headers, JSON (key order included), signatures (verified), stored side effects.
 *
 *   RIVET_CORE_DIR=/path/to/rivet-core RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *     RIVETIT_REDIS_PORT=6371 php tests/endpoint_agent_golden.php
 *
 * RIVET_CORE_DIR is a checkout of rivet-core (the fixtures and the driver are not shipped in the Composer package). Same scratch rules as
 * tests/endpoint_agent_lib.php: a scratch database with 'scratch' in its name, config.php pointing at it, EA_ALLOW_INSECURE_HTTP defined. The
 * second server (the 426 transcripts) gets EA_TEST_NO_INSECURE=1, which a scratch config.php may honour:
 *     if (getenv('EA_TEST_NO_INSECURE') !== '1') { define('EA_ALLOW_INSECURE_HTTP', true); }
 * A throwaway Redis on RIVETIT_REDIS_PORT is needed for the per-device 429 transcripts (the rate limiter fails open without it).
 */
if (getenv('RIVETIT_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "set RIVETIT_TEST_DB=1 and a scratch RIVETIT_TEST_DB_NAME\n"); exit(2); }
$core = rtrim((string) getenv('RIVET_CORE_DIR'), '/');
if ($core === '' || !is_file("$core/scripts/rmm-golden/golden.php") || !is_dir("$core/tests/Fixtures/rmm/golden")) { fwrite(STDERR, "set RIVET_CORE_DIR to a rivet-core checkout\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText) || strpos($cfgText, 'EA_ALLOW_INSECURE_HTTP') === false) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database and define EA_ALLOW_INSECURE_HTTP\n"); exit(2);
}
// Four-digit ports on purpose: the stamped installer carries the server URL, so the recorded installer length depends on the port having four digits.
$free = static function (array $taken): int {
    for ($i = 0; $i < 200; $i++) {
        $p = random_int(8200, 9899);
        $s = in_array($p, $taken, true) ? false : @stream_socket_server("tcp://127.0.0.1:$p");
        if ($s) { fclose($s); return $p; }
    }
    fwrite(STDERR, "no free port\n"); exit(2);
};
$procs = [];
$start = static function (int $port, array $env) use ($root, &$procs): void {
    $log = sys_get_temp_dir() . "/ea_golden_$port.log";
    $p = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$log", '-S', "127.0.0.1:$port", "$root/tests/mobile_api_router.php"],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root, array_merge(getenv(), $env));
    $procs[] = $p;
    for ($i = 0; $i < 80; $i++) { if (@fsockopen('127.0.0.1', $port)) { return; } usleep(100000); }
    fwrite(STDERR, "server on $port did not start\n"); exit(2);
};
$main = $free([]);
$tls = $free([$main]);
$start($main, ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1', 'RIVET_CORE_DIR' => $core]);
$start($tls, ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1', 'EA_TEST_NO_INSECURE' => '1', 'RIVET_CORE_DIR' => $core]);
$cmd = [PHP_BINARY, "$core/scripts/rmm-golden/golden.php", $argv[1] ?? 'replay', "--base=http://127.0.0.1:$main", "--tls-base=http://127.0.0.1:$tls", "--install=$root",
    "--adapter=$root/tests/rmm_golden/adapter.php", "--dir=$core/tests/Fixtures/rmm/golden"];
$p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes, $root, array_merge(getenv(), ['RIVET_CORE_DIR' => $core]));
$code = proc_close($p);
foreach ($procs as $proc) { proc_terminate($proc); proc_close($proc); }
exit($code);
