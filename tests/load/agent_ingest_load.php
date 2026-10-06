<?php
/*
 * Endpoint agent ingestion load test (scratch database only).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... \
 *     php tests/load/agent_ingest_load.php [devices=200] [checkins_per_device=10] [concurrency=16] [workers=8]
 *
 * Part 1 (cost probe): one realistic check-in is run IN PROCESS through a query-counting mysqli, giving the exact number of SQL
 * statements and rows written per check-in (steady state, and for a check-in that also carries inventory).
 * Part 2 (throughput): N enrolled devices send realistic check-ins over real HTTP (php -S with PHP_CLI_SERVER_WORKERS), M
 * in flight at a time, and the script reports check-ins per second and latency percentiles.
 * Both parts run on whatever box you run them on; on a shared scratch machine the numbers are a floor, not a capacity promise.
 */
require __DIR__ . '/../endpoint_agent_lib.php';   // refuses anything but a scratch DB
use ITFlow\EndpointAgent\Enrollment; use ITFlow\EndpointAgent\Config; use ITFlow\EndpointAgent\Checkin; use ITFlow\EndpointAgent\Devices;

$N = max(1, (int) ($argv[1] ?? 200)); $M = max(1, (int) ($argv[2] ?? 10)); $C = max(1, (int) ($argv[3] ?? 16)); $W = max(1, (int) ($argv[4] ?? 8));
ea_reset();
Config::enable();
$tok = Enrollment::createToken(1, 0, 'stable', 24, 5000, 'load', 1)['token'];
Config::set(['unmatched_policy' => 'auto_create']);

// enroll N devices (each its own asset, so metrics are written)
$devs = [];
$t0 = microtime(true);
for ($i = 0; $i < $N; $i++) {
    [$c, , $j] = ea_enroll($tok, ea_dev(['hostname' => sprintf('LOAD-%04d', $i), 'serial' => sprintf('LOADSER%05d', $i)]));
    if ($c !== 201) { echo "enroll failed ($c)\n"; exit(1); }
    $devs[] = ['id' => (int) $j['device_id'], 'token' => $j['device_token'], 'seq' => 0];
}
printf("enrolled %d devices in %.1fs (%.1f/s, includes asset creation and a signing round trip)\n", $N, microtime(true) - $t0, $N / (microtime(true) - $t0));

function payload(int $seq, bool $withInventory): array {
    $p = ['seq' => $seq, 'collected_at' => gmdate('Y-m-d\TH:i:s\Z'), 'agent_version' => '1.0.0', 'inventory' => null,
        'metrics' => ['cpu_pct' => 5 + ($seq % 60), 'mem_pct' => 40 + ($seq % 30), 'disk' => [['mount' => 'C:', 'used_pct' => 55.5], ['mount' => 'D:', 'used_pct' => 20.1]], 'net_rx_bps' => 12345.5, 'net_tx_bps' => 6789.1],
        'checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => ''], ['key' => 'pending_reboot', 'status' => 'ok', 'detail' => ''], ['key' => 'svc_eventlog', 'status' => 'ok', 'detail' => 'running']], 'buffered' => []];
    if ($withInventory) {
        $p['inventory'] = ['hostname' => 'LOAD', 'os' => 'windows', 'os_version' => 'Windows 11', 'manufacturer' => 'Dell', 'model' => 'Latitude', 'serial' => 'X', 'cpu' => ['model' => 'Intel i7', 'cores' => 8], 'memory_total_bytes' => 17179869184,
            'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS'], ['mount' => 'D:', 'total_bytes' => 1000000000000, 'free_bytes' => 800000000000, 'fs' => 'NTFS']],
            'network' => [['name' => 'Ethernet', 'mac' => 'aa:bb:cc:dd:ee:01', 'ips' => ['10.0.0.5']]], 'uptime_s' => 86400, 'logged_in_user' => 'user', 'pending_reboot' => false];
    }
    return $p;
}

// ---------------------------------------------------------------- Part 1: cost probe
class CountingMysqli extends mysqli {
    public array $stmts = [];
    public function prepare(string $query): mysqli_stmt|false { $this->stmts[] = $query; return parent::prepare($query); }
    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool { $this->stmts[] = $query; return parent::query($query, $result_mode); }
}
$cm = new CountingMysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
$GLOBALS['mysqli'] = $cm; $mysqli = $cm;
$probe = function (bool $inv) use ($cm, $devs) {
    static $seq = 5000000;
    $dev = Devices::find($devs[0]['id']);
    $before = [];
    foreach (['device_metric_samples', 'endpoint_agent_checkins', 'endpoint_agent_checks', 'device_metric_instances'] as $t) { $before[$t] = (int) $cm->query("SELECT COUNT(*) FROM $t")->fetch_row()[0]; }
    $cm->stmts = [];
    Checkin::handle($dev, payload(++$seq, $inv));
    $s = $cm->stmts;
    $kinds = ['SELECT' => 0, 'INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0, 'other' => 0];
    foreach ($s as $q) { $k = strtoupper(strtok(ltrim($q), " \n")); $kinds[isset($kinds[$k]) ? $k : 'other']++; }
    $rows = [];
    foreach ($before as $t => $b) { $rows[$t] = (int) $cm->query("SELECT COUNT(*) FROM $t")->fetch_row()[0] - $b; }
    return [count($s), $kinds, $rows];
};
$probe(false); // warm: first call creates check rows and metric instances
[$n1, $k1, $r1] = $probe(false);
[$n2, $k2, $r2] = $probe(true);
echo "\nPER CHECK-IN DATABASE COST (counted in process)\n";
printf("  steady state check-in : %d statements %s, new rows %s\n", $n1, json_encode($k1), json_encode($r1));
printf("  with full inventory   : %d statements %s, new rows %s\n", $n2, json_encode($k2), json_encode($r2));
$GLOBALS['mysqli'] = $db; $mysqli = $db;

// ---------------------------------------------------------------- Part 2: throughput over HTTP
$srv = ea_start_php("$root/tests/mobile_api_router.php", ['PHP_CLI_SERVER_WORKERS' => (string) $W]);
$hb = "http://127.0.0.1:{$srv['port']}/api/v1/agent_checkin";
$total = $N * $M; $sent = 0; $done = 0; $errors = 0; $lat = [];
$mh = curl_multi_init(); $active = [];
$start = function () use (&$devs, &$sent, $M, $hb, $mh, &$active, $N) {
    $di = $sent % $N; $round = intdiv($sent, $N);
    $d = &$devs[$di]; $d['seq']++;
    $ch = curl_init($hb);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $d['token']],
        CURLOPT_POSTFIELDS => json_encode(payload($d['seq'], $d['seq'] === 1))]);
    curl_multi_add_handle($mh, $ch);
    $active[(int) $ch] = [$ch, microtime(true)];
    $sent++;
};
$t0 = microtime(true);
for ($i = 0; $i < min($C, $total); $i++) { $start(); }
while ($done < $total) {
    curl_multi_exec($mh, $running);
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch = $info['handle']; [, $ts] = $active[(int) $ch];
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code !== 200) { $errors++; }
        $lat[] = microtime(true) - $ts; $done++;
        curl_multi_remove_handle($mh, $ch); curl_close($ch); unset($active[(int) $ch]);
        if ($sent < $total) { $start(); }
    }
    if ($running) { curl_multi_select($mh, 0.2); }
}
$el = microtime(true) - $t0;
sort($lat);
$pct = fn(float $p) => $lat[(int) min(count($lat) - 1, floor($p * count($lat)))] * 1000;
echo "\nTHROUGHPUT OVER HTTP (php -S, $W workers, $C in flight, $N devices x $M check-ins)\n";
printf("  %d check-ins in %.1fs = %.1f check-ins/s, errors %d\n", $total, $el, $total / $el, $errors);
printf("  latency ms: p50 %.0f  p95 %.0f  p99 %.0f  max %.0f\n", $pct(0.5), $pct(0.95), $pct(0.99), end($lat) * 1000);
printf("  metric samples stored: %d, idempotency rows: %d, load average %s\n", (int) $one("SELECT COUNT(*) FROM device_metric_samples"), (int) $one("SELECT COUNT(*) FROM endpoint_agent_checkins"), implode(' ', array_map(fn($x) => round($x, 1), sys_getloadavg())));
$fails = $errors > 0 ? 1 : 0;
