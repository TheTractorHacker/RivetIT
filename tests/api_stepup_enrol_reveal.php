<?php
declare(strict_types=1);
/*
 * IT-INJ-6 regression: the credential-reveal step-up key cannot be (re)enrolled with a bearer token alone, and API credential
 * reveals are audited and rate limited. Real HTTP against a php -S instance, on a SCRATCH database (full schema + setup_cli rows).
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=<scratch db> RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... RIVETIT_REDIS_PORT=1 \
 *     php tests/api_stepup_enrol_reveal.php
 *
 * config.php in the repo root must point at the same scratch database. Never prints a key, token or password.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') exit(2);
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfg = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfg)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = new mysqli('localhost', (string) getenv('RIVETIT_TEST_DB_USER'), (string) getenv('RIVETIT_TEST_DB_PASS'), (string) getenv('RIVETIT_TEST_DB_NAME'));
if ($db->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$q   = fn(string $sql) => $db->query($sql);
$one = fn(string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$fails = 0;
$ok = function (bool $c, string $l) use (&$fails): void { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; if (!$c) $fails++; };

// ---- seed: admin user (setup_cli made one) gets a known password and a bearer token
$uid = (int) $one("SELECT user_id FROM users ORDER BY user_id LIMIT 1");
$password = bin2hex(random_bytes(10));
$q("UPDATE users SET user_password = '" . $db->real_escape_string(password_hash($password, PASSWORD_DEFAULT)) . "', user_token = NULL WHERE user_id = $uid");
$token = bin2hex(random_bytes(20));
$q("DELETE FROM api_tokens");
$q("INSERT INTO api_tokens SET token_user_id = $uid, token_hash = '" . hash('sha256', $token) . "', token_created_at = NOW(), token_last_used_at = NOW()");
$q("DELETE FROM api_biometric_keys"); $q("DELETE FROM audit_events"); $q("DELETE FROM credentials");
$q("DELETE FROM security_settings WHERE setting_key = 'vault_reveal_limit'");
$q("INSERT INTO credentials SET credential_id = 500, credential_name = 'Test cred', credential_client_id = 0, credential_username = '', credential_password = ''");

// ---- server
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1]; fclose($sock);
$logf = sys_get_temp_dir() . "/stepup_enrol_server_$port.log";
$proc = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log=$logf", '-S', "127.0.0.1:$port", "$root/tests/mobile_api_router.php"],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logf, 'a'], 2 => ['file', $logf, 'a']], $pipes, $root);
register_shutdown_function(function () use ($proc, $logf) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } @unlink($logf); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }

function http(string $method, string $path, ?string $token, $body = null, array $headers = []): array {
    global $port;
    $h = $headers;
    if ($token) $h[] = "Authorization: Bearer $token";
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return ['code' => $code, 'json' => json_decode($raw, true)];
}

$mkKey = function (): array {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    return [$k, openssl_pkey_get_details($k)['key']];
};
[$attPriv, $attPem] = $mkKey();   // "attacker" key
[$devPriv, $devPem] = $mkKey();   // legitimate device key
$keyRows = fn() => (int) $one("SELECT COUNT(*) FROM api_biometric_keys WHERE user_id = $uid");
$storedPem = fn() => (string) $one("SELECT device_public_key_pem FROM api_biometric_keys WHERE user_id = $uid");
$audit = fn(string $type, string $action) => (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = '$type' AND action = '$action' AND actor_user_id = $uid");

// ---- enrolment
$r = http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $attPem]);
$ok($r['code'] === 401 && $keyRows() === 0, "bearer token alone cannot enrol a step-up key (HTTP {$r['code']})");
$r = http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $attPem, 'current_password' => 'wrong-' . $password]);
$ok($r['code'] === 401 && $keyRows() === 0, "wrong password refused (HTTP {$r['code']})");
$ok($audit('vault.api_stepup_key_enrol', 'denied') === 2, 'both refusals audited');
$r = http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $devPem, 'current_password' => $password]);
$ok($r['code'] === 200 && trim($storedPem()) === trim($devPem), "correct password enrols the key (HTTP {$r['code']})");
$ok($audit('vault.api_stepup_key_enrol', 'success') === 1, 'enrolment audited');
$r = http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $attPem]);
$ok($r['code'] === 401 && trim($storedPem()) === trim($devPem), 'bearer-only overwrite of an existing key refused, key unchanged');
$r = http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $attPem, 'current_password' => $password]);
$ok($r['code'] === 200 && trim($storedPem()) === trim($attPem), 'replacement with the password works and is audited');
$ok($audit('vault.api_stepup_key_enrol', 'success') === 2, 'replacement audited');
// put the device key back for the reveal tests
http('PUT', '/api/v1/me', $token, ['device_public_key_pem' => $devPem, 'current_password' => $password]);
$q("DELETE FROM audit_events WHERE event_type = 'vault.api_stepup_key_enrol' AND action = 'denied'"); // clear the lockout counter used above

// ---- reveal
$reveal = function () use ($token, $devPriv): array {
    $c = http('GET', '/api/v1/auth', null);
    $bytes = rtrim(strtr((string) ($c['json']['challenge'] ?? ''), '-_', '+/'), '=');
    $raw = base64_decode($bytes . str_repeat('=', (4 - strlen($bytes) % 4) % 4), true);
    openssl_sign((string) $raw, $sig, $devPriv, OPENSSL_ALGO_SHA256);
    return http('GET', '/api/v1/credentials/500', $token, null, ['X-Biometric-Challenge-Token: ' . ($c['json']['challengeToken'] ?? ''), 'X-Biometric-Signature: ' . base64_encode($sig)]);
};
$q("INSERT INTO security_settings SET setting_key = 'vault_reveal_limit', setting_value = '2'");
$r = $reveal();
$ok($r['code'] === 200 && ($r['json']['name'] ?? '') === 'Test cred', "reveal with a valid signed challenge works (HTTP {$r['code']})");
$ok($audit('vault.credential_revealed', 'reveal') === 1, 'API reveal writes an audit event');
$ok((int) $one("SELECT COUNT(*) FROM logs WHERE log_type = 'Credential' AND log_action = 'Reveal' AND log_user_id = $uid") === 1, 'API reveal writes a Credential log line');
$r = $reveal(); $ok($r['code'] === 200, 'second reveal allowed');
$r = $reveal();
$ok($r['code'] === 429, "third reveal over the limit of 2 is refused (HTTP {$r['code']})");
$ok(!isset($r['json']['password']) && !isset($r['json']['username']), 'no secret fields in the refused response');
$ok($audit('vault.reveal_rate_limited', 'blocked') === 1, 'rate-limit hit audited');
$ok($audit('vault.credential_revealed', 'reveal') === 2, 'refused reveal is not counted as a reveal');

echo $fails === 0 ? "\nALL PASSED\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
