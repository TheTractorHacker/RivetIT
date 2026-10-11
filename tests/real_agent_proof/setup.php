<?php
/*
 * Prepares a SCRATCH RivetIT install for the real-agent proof (tests/real_agent_proof/run.sh): the module switched on with the Phase 6 and 8 features, one department,
 * the folders and log sources the proof uses, one log rule, an administrator with an API token, and one enrollment token. Prints one JSON line.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x ... php tests/real_agent_proof/setup.php <https service url>
 *
 * Refuses anything but a database whose name contains "scratch" and a config.php that points at it. Never run against a live database.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETIT_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: scratch database only\n"); exit(2); }
$root = dirname(__DIR__, 2);
$cfg = (string) @file_get_contents("$root/config.php");
if (!preg_match('/\$database\s*=\s*[\'"]' . preg_quote((string) getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfg)) { fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2); }
$url = $argv[1] ?? '';
if (!str_starts_with($url, 'https://')) { fwrite(STDERR, "usage: setup.php <https service url>\n"); exit(2); }
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
$err = $s->update(['service_url' => $url, 'unmatched_policy' => 'auto_create',
    'features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'alerting' => true]]);
if ($err !== []) { fwrite(STDERR, implode("\n", $err) . "\n"); exit(1); }
// short intervals so the proof does not wait minutes (production floors do not apply to a scratch fixture)
$s->set(['check_in_interval_s' => 5, 'collect_interval_s' => 5, 'job_ack_timeout_s' => 60]);
// RivetIT's own door to the same call the Administration page uses: RemoteActions::updateSettings() (needs rmm.admin), with the acknowledgements
$r = $rmm->remoteActions()->updateSettings($who, [
    'features' => ['remote_tools' => true, 'filetransfer' => true, 'logs' => true],
    'transfer_allow' => ['linux' => ['/srv/proof'], 'windows' => ['C:\\Proof']],
    'logs_collect' => ['linux' => [['units' => ['rivetproof-noisy.service'], 'min_level' => 'warning', 'contains' => ['failed password', 'segfault']]], 'windows' => []],
    'limits' => ['logs_interval_s' => 30, 'logs_rate_per_min' => 120],
    'accept_filetransfer_risk' => true, 'accept_logs_storage' => true,
]);
if (!$r->ok) { fwrite(STDERR, 'remote settings: ' . json_encode([$r->http, $r->code, $r->message]) . "\n"); exit(1); }
[$rid, $rerr] = $rmm->logRules()->create(['name' => 'Failed password', 'min_level' => 'warning', 'contains' => 'failed password', 'severity' => 'fail', 'resolve_after_min' => 30], 1);
if ($rid === null) { fwrite(STDERR, implode("\n", $rerr) . "\n"); exit(1); }
$tok = $rmm->technician()->createToken($who, 1, 0, 'stable', 24, 5, 'real agent proof');
if (!$tok->ok) { fwrite(STDERR, 'enrollment token: ' . json_encode([$tok->http, $tok->code, $tok->message]) . "\n"); exit(1); }
$rmm->syncState();
echo json_encode(['token' => (string) $tok->data['token'], 'admin_api_token' => $admin, 'client_id' => 1, 'rule_id' => $rid]), "\n";
