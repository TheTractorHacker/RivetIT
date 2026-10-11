<?php
/*
 * Prepares a SCRATCH RivetIT install for tests/real_agent_proof/run_rc13.sh: the module on, one department, an administrator with an API token, MeshCentral on (the mock
 * Mesh server's address, a device group id and the pin of its certificate). Prints one JSON line. Scratch databases only (same guards as setup.php).
 *   php tests/real_agent_proof/setup_rc13.php <https service url> <https mesh url> <group hex> <pin hex>
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETIT_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: scratch database only\n"); exit(2); }
$root = dirname(__DIR__, 2);
$cfg = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfg)) { fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2); }
[$url, $meshUrl, $group, $pin] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''];
if (!str_starts_with($url, 'https://') || !str_starts_with($meshUrl, 'https://')) { fwrite(STDERR, "usage: setup_rc13.php <https service url> <https mesh url> <group hex> <pin hex>\n"); exit(2); }
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once "$root/config.php";
require_once "$root/functions.php";
require_once "$root/vendor/autoload.php";
$GLOBALS['mysqli'] = $mysqli;
$session_user_id = 1; $session_ip = '127.0.0.1'; $session_user_agent = 'proof';
require_once "$root/includes/rmm_bootstrap.php";
$q = fn (string $sql) => $mysqli->query($sql);
$q("SET SESSION sql_mode=''");
foreach (['modules', 'user_role_permissions', 'api_tokens', 'users', 'user_roles', 'user_client_permissions', 'clients'] as $t) { $q("DELETE FROM $t"); }
$q("UPDATE settings SET config_module_enable_rmm=1 WHERE company_id=1");
$q("INSERT INTO user_roles SET role_id=1, role_name='Admin', role_is_admin=1, role_type=1");
$q("INSERT INTO users SET user_id=1, user_name='proof-admin', user_email='proof-admin@example.test', user_password='x', user_type=1, user_status=1, user_role_id=1");
$admin = bin2hex(random_bytes(20));
$q("INSERT INTO api_tokens SET token_user_id=1, token_hash='" . hash('sha256', $admin) . "', token_created_at=NOW(), token_last_used_at=NOW()");
$q("INSERT INTO clients SET client_id=1, client_name='Proof Dept'");
$rmm = rivetRmmModule($mysqli);
$who = new RivetCore\Rmm\Authz\RmmPrincipal(1, 'proof-admin');
$rmm->admin()->enable($who);
$s = $rmm->settings();
$err = $s->update(['service_url' => $url, 'unmatched_policy' => 'auto_create', 'features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true, 'policies' => true, 'scripts' => true]]);
if ($err !== []) { fwrite(STDERR, implode("\n", $err) . "\n"); exit(1); }
$s->set(['check_in_interval_s' => 5, 'collect_interval_s' => 5, 'job_ack_timeout_s' => 60, 'mesh_enabled' => 1, 'mesh_url' => $meshUrl, 'mesh_login_key_enc' => encryptSetting(bin2hex(random_bytes(32))), 'mesh_account_template' => 'proof']);
$e = $rmm->meshAgentSettings()->update(['mesh_group_id' => $group, 'mesh_server_cert_pin' => $pin], 1);
if ($e !== []) { fwrite(STDERR, implode("\n", $e) . "\n"); exit(1); }
// "Add device" needs a published agent binary per architecture before it will issue anything; placeholder executables with valid PE headers (the Linux command downloads nothing)
$fakePe = function (int $machine, int $size): string {
    $b = str_pad('MZ' . str_repeat("\0", 0x3A) . pack('V', 128), 128, "\0");
    $b .= "PE\0\0" . pack('v', $machine) . pack('v', 3) . str_repeat("\0", 12) . pack('v', 0xE0) . pack('v', 0x0022);
    return $b . substr(str_repeat(hash('sha256', 'proof' . $machine, true), intdiv($size, 32) + 1), 0, $size - strlen($b));
};
$q("DELETE FROM endpoint_agent_binaries");
foreach (['amd64' => 0x8664, 'arm64' => 0xAA64] as $arch => $machine) {
    $f = tempnam(sys_get_temp_dir(), 'proofexe'); file_put_contents($f, $fakePe($machine, 120000));
    $r = $rmm->binaryStore()->publish($f, '1.0.0', $arch, 1, ['activate' => true]);
    @unlink($f);
    if (empty($r['ok'])) { fwrite(STDERR, "could not publish the $arch placeholder binary: " . ($r['error'] ?? '?') . "\n"); exit(1); }
}
$rmm->syncState();
echo json_encode(['admin_api_token' => $admin, 'client_id' => 1]), "\n";
