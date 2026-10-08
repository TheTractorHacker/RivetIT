<?php
/*
 * Shared harness for tests/endpoint_agent_*.php: REAL HTTP against a `php -S` instance (the same front controller nginx uses), on a
 * SCRATCH database holding the fully migrated schema. Refuses anything else.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/endpoint_agent_enroll.php
 *
 * config.php in the repo root must point at the SAME scratch database (scripts/setup_cli.php --config-only) and define
 * EA_ALLOW_INSECURE_HTTP = true (plain http on loopback is refused by the API otherwise). Never run against a live database.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = @file_get_contents("$root/config.php");
if (!$cfgText || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText) || strpos($cfgText, 'EA_ALLOW_INSECURE_HTTP') === false) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database and define EA_ALLOW_INSECURE_HTTP\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', getenv('RIVETIT_TEST_DB_USER'), getenv('RIVETIT_TEST_DB_PASS'), getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
date_default_timezone_set('UTC');
$db->query("SET SESSION sql_mode=''");
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "$root/config.php";
require_once "$root/functions.php";
$GLOBALS['mysqli'] = $db;
$mysqli = $db;
$session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test';
require_once "$root/vendor/autoload.php";
require_once __DIR__ . '/support/endpoint_compat.php';   // the old ITFlow\EndpointAgent\* names as thin forwards to RivetCore\Rmm (the PHP moved into rivet-core)
$q = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$rows = function (string $sql) use ($db): array { $o = []; $r = $db->query($sql); while ($r && ($x = $r->fetch_assoc())) { $o[] = $x; } return $o; };
$esc = fn(string $s) => $db->real_escape_string($s);
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; });

/** Wipe everything the agent touches and seed the baseline. */
function ea_reset(): void {
    global $q, $db;
    foreach (['endpoint_agent_checkins', 'endpoint_agent_checks', 'endpoint_agent_jobs', 'endpoint_agent_mesh_nodes', 'endpoint_agent_releases', 'endpoint_agent_enroll_attempts',
              'endpoint_agent_enrollment_tokens', 'endpoint_agent_devices', 'device_metric_samples', 'device_metric_instances', 'device_metric_collection_state', 'device_metric_rollups',
              'asset_rmm_links', 'rmm_alerts', 'rmm_remote_sessions', 'ticket_replies', 'tickets', 'asset_interfaces', 'assets', 'api_tokens', 'logs', 'audit_events', 'user_client_permissions',
              'user_role_permissions', 'modules', 'users', 'user_roles', 'rmm_scripts', 'clients'] as $t) { $q("DELETE FROM $t"); }
    $q("DELETE FROM rmm_integrations");
    $q("DELETE FROM endpoint_agent_settings"); $q("INSERT INTO endpoint_agent_settings (id) VALUES (1)");
    $q("UPDATE settings SET config_ticket_prefix='T', config_ticket_next_number=1000, config_module_enable_rmm=1 WHERE company_id=1");
    $q("INSERT INTO clients SET client_id=1, client_name='Dept A'"); $q("INSERT INTO clients SET client_id=2, client_name='Dept B'");
}

/** Roles/users/API tokens for the authorization matrix. Returns name => token. */
function ea_seed_users(): array {
    global $q;
    foreach (['module_rmm' => 1, 'module_rmm_scripts' => 2, 'module_rmm_remote_connect' => 3, 'module_client' => 4, 'module_support' => 5, 'module_assets' => 6, 'module_rmm_alerts' => 7] as $m => $id) { $q("INSERT INTO modules SET module_id=$id, module_name='$m'"); }
    // roles: 1 admin; 2 full tech (client+rmm 3 scripts 3 remote 1); 3 reboot-only (rmm 1, scripts 2); 4 viewer (rmm 1); 5 remote-only (rmm 1, remote 1); 6 module-only (rmm 3, scripts 3, remote 1, no client/support/assets); 7 no-rmm tech
    foreach ([[1, 'Admin', 1], [2, 'Tech', 0], [3, 'RebootOnly', 0], [4, 'Viewer', 0], [5, 'RemoteOnly', 0], [6, 'ModuleOnly', 0], [7, 'NoRmm', 0]] as [$id, $n, $adm]) { $q("INSERT INTO user_roles SET role_id=$id, role_name='$n', role_is_admin=$adm, role_type=1"); }
    $perms = [[2, 4, 3], [2, 1, 3], [2, 2, 3], [2, 3, 1], [3, 4, 3], [3, 1, 1], [3, 2, 2], [4, 4, 3], [4, 1, 1], [5, 4, 3], [5, 1, 1], [5, 3, 1], [6, 1, 3], [6, 2, 3], [6, 3, 1], [7, 4, 3]];
    foreach ($perms as [$r, $m, $l]) { $q("INSERT INTO user_role_permissions SET user_role_id=$r, module_id=$m, user_role_permission_level=$l"); }
    $users = [1 => ['admin', 1], 10 => ['tech', 2], 11 => ['rebootonly', 3], 12 => ['viewer', 4], 13 => ['remoteonly', 5], 14 => ['moduleonly', 6], 15 => ['normo', 7], 16 => ['deptb', 2]];
    $tokens = [];
    foreach ($users as $uid => [$name, $role]) {
        $q("INSERT INTO users SET user_id=$uid, user_name='$name', user_email='$name@example.test', user_password='x', user_type=1, user_status=1, user_role_id=$role");
        $tokens[$name] = bin2hex(random_bytes(20));
        $q("INSERT INTO api_tokens SET token_user_id=$uid, token_hash='" . hash('sha256', $tokens[$name]) . "', token_created_at=NOW(), token_last_used_at=NOW()");
    }
    $q("INSERT INTO user_client_permissions SET user_id=16, client_id=2");   // user 16 may only see Dept B
    return $tokens;
}

/** Start `php -S` on a free loopback port. @return array{port:int,log:string} */
function ea_start_php(string $router, array $env = [], array $ini = []): array {
    global $root;
    $sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
    $logf = sys_get_temp_dir() . "/ea_server_$port.log";
    $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logf"];
    foreach ($ini as $i) { $cmd[] = '-d'; $cmd[] = $i; }
    array_push($cmd, '-S', "127.0.0.1:$port", $router);
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logf, 'a'], 2 => ['file', $logf, 'a']], $pipes, $root, array_merge(getenv(), $env));
    register_shutdown_function(function () use ($proc) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } });
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
    return ['port' => $port, 'log' => $logf];
}

function ea_start_server(array $env = [], array $ini = []): array {
    global $root;
    return ea_start_php("$root/tests/mobile_api_router.php", array_merge(['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], $env), $ini);
}

/** Forge a logged-in web session file for user $uid (scratch DB only) and return the session id. */
function ea_forge_session(string $dir, int $uid): string {
    $sid = 'eatest' . $uid . bin2hex(random_bytes(4));
    file_put_contents("$dir/sess_$sid", 'logged|b:1;user_id|i:' . $uid . ';csrf_token|s:8:"csrftok1";');
    return $sid;
}

/** Web request with a forged session cookie. @return array{0:int,1:string,2:string} status, body, headers */
function web(string $baseUrl, string $method, string $path, string $sid, array $post = [], array $headers = []): array {
    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = curl_exec($ch); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, substr((string) $raw, $hs), substr((string) $raw, 0, $hs)];
}

$EA = ea_start_server();
$base = "http://127.0.0.1:{$EA['port']}";

/** @return array{0:int,1:array,2:mixed,3:string} status, lowercased headers, decoded JSON (or null), raw body */
function http(string $method, string $path, ?string $token = null, $body = null, array $headers = []): array {
    global $base;
    $ch = curl_init($base . $path);
    $h = $headers;
    if ($token) $h[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) {
        if (is_array($body)) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        else { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hs); $content = substr((string) $raw, $hs);
    $hdrs = [];
    foreach (explode("\r\n", $head) as $line) { if (strpos($line, ':') !== false) { [$k, $v] = explode(':', $line, 2); $hdrs[strtolower(trim($k))][] = trim($v); } }
    return [$code, $hdrs, json_decode($content, true), $content];
}

function ea_uuid(): string { return \ITFlow\EndpointAgent\Jobs::uuid(); }

/** A valid device block for enrollment. */
function ea_dev(array $over = []): array {
    return array_merge(['install_id' => ea_uuid(), 'machine_guid' => strtolower(bin2hex(random_bytes(8))), 'hostname' => 'WS-' . strtoupper(bin2hex(random_bytes(2))), 'os' => 'windows',
        'os_version' => 'Windows 11 23H2', 'arch' => 'amd64', 'serial' => 'SN' . strtoupper(bin2hex(random_bytes(4))), 'manufacturer' => 'Dell', 'model' => 'Latitude 7440',
        'mac_addresses' => [], 'agent_version' => '1.0.0'], $over);
}

/** Enable the feature the way the admin page does and make a token. Returns the plaintext token. */
function ea_token(int $client = 1, int $ttlH = 24, int $maxUses = 5, string $ring = 'stable'): string {
    \ITFlow\EndpointAgent\Config::enable();
    $r = \ITFlow\EndpointAgent\Enrollment::createToken($client, 0, $ring, $ttlH, $maxUses, 'test', 1);
    return $r['token'];
}

function ea_enroll(string $token, array $dev): array { return http('POST', '/api/v1/agent_enroll', null, ['enrollment_token' => $token, 'device' => $dev]); }

function ea_ts(int $offset = 0): string { return gmdate('Y-m-d\TH:i:s\Z', time() + $offset); }

function ea_checkin(string $devToken, array $over = []): array {
    static $seq = 0; $seq++;
    $body = array_merge(['seq' => $seq, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0', 'inventory' => null, 'metrics' => ['cpu_pct' => 10.5, 'mem_pct' => 40.0, 'disk' => [['mount' => 'C:', 'used_pct' => 55.0]], 'net_rx_bps' => 100.0, 'net_tx_bps' => 50.0],
        'checks' => [], 'buffered' => []], $over);
    return http('POST', '/api/v1/agent_checkin', $devToken, $body);
}
