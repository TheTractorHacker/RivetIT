<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP
/*
 * Shared harness for tests/manageengine_mdm_*.php: a SCRATCH database holding the fully migrated schema plus a LOCAL mock of the
 * ManageEngine MDM Cloud API and Zoho token endpoint (tests/mock/manageengine_mdm_mock.php). It never contacts ManageEngine or Zoho.
 *
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_client.php
 *
 * config.php in the repo root must point at the same scratch database. Refuses anything else.
 */
if (getenv('RIVETIT_TEST_DB') !== '1') { fwrite(STDERR, "set RIVETIT_TEST_DB=1\n"); exit(2); }
if (!preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: DB name must contain 'scratch'\n"); exit(2); }
$root = dirname(__DIR__);
$cfgText = @file_get_contents("$root/config.php");
if (!$cfgText || !preg_match('/\$database\s*=\s*[\'"]' . preg_quote(getenv('RIVETIT_TEST_DB_NAME'), '/') . '[\'"]/', $cfgText)) {
    fwrite(STDERR, "Refusing: config.php must point at the same scratch database\n"); exit(2);
}
mysqli_report(MYSQLI_REPORT_OFF);
date_default_timezone_set('UTC');
$_SERVER['DOCUMENT_ROOT'] = $root; $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; $_SERVER['HTTP_USER_AGENT'] = 'mdm-test';
if (!defined('RIVETIT_MDM_ALLOW_HTTP_LOOPBACK')) { define('RIVETIT_MDM_ALLOW_HTTP_LOOPBACK', true); }   // test rigs only: plain http to the loopback mock
require_once "$root/config.php";
require_once "$root/functions.php";
require_once "$root/vendor/autoload.php";
$db = $mysqli;
if (!preg_match('/scratch/i', (string) ($db->query('SELECT DATABASE()')->fetch_row()[0] ?? ''))) { fwrite(STDERR, "Refusing: connected database is not a scratch one\n"); exit(2); }
$db->query("SET SESSION sql_mode=''");
$GLOBALS['mysqli'] = $db;
$session_user_id = 0; $session_ip = '127.0.0.1'; $session_user_agent = 'test';

use ITFlow\Integrations\ManageEngine\ManageEngineMdmClient as MdmClient;
use ITFlow\Integrations\ManageEngine\MdmException;

$q = fn (string $sql) => $db->query($sql);
$one = fn (string $sql) => ($r = $db->query($sql)) ? ($r->fetch_row()[0] ?? null) : null;
$rows = function (string $sql) use ($db): array { $o = []; $r = $db->query($sql); while ($r && ($x = $r->fetch_assoc())) { $o[] = $x; } return $o; };
$esc = fn (string $s) => $db->real_escape_string($s);
$fails = 0; $passes = 0;
$ok = function (bool $c, string $l) use (&$fails, &$passes) { echo ($c ? 'PASS' : 'FAIL') . "  $l\n"; $c ? $passes++ : $fails++; };
register_shutdown_function(function () use (&$fails, &$passes) { echo "\n$passes passed, $fails failed\n"; if ($fails) { exit(1); } });

// ---- the mock, on a free loopback port
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es); $port = (int) explode(':', stream_socket_get_name($sock, false))[1]; fclose($sock);
$state = sys_get_temp_dir() . '/mdm_mock_' . getmypid() . '.json';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$root/tests/mock/manageengine_mdm_mock.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['MOCK_STATE' => $state] + ['PATH' => getenv('PATH')]);
register_shutdown_function(function () use ($proc, $state) { if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); } foreach ([$state, "$state.log", "$state.lock"] as $f) { @unlink($f); } });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) break; usleep(100000); }
$base = "http://127.0.0.1:$port";
$mock = function (array $s) use ($base) { $c = stream_context_create(['http' => ['method' => 'POST', 'content' => json_encode($s), 'header' => 'Content-Type: application/json']]); return json_decode(file_get_contents("$base/__state", false, $c), true); };
$log = fn () => json_decode(file_get_contents("$base/__log"), true);
$reset = fn () => $mock(['__reset' => 1]);

$sleeps = [];
/** A client wired to the mock (mdm and accounts hosts both point at it); the sleeps are recorded, never slept. */
$mk = function (array $opts = [], ?object $store = null, string $secret = 'good-secret', string $refresh = 'good-refresh') use ($base, &$sleeps): MdmClient {
    return new MdmClient('us', '1000.TESTCLIENT', $secret, $refresh, $store, $opts + ['mdm_base' => $base, 'accounts_base' => $base, 'max_retry_after' => 5,
        'sleep' => function (float $s) use (&$sleeps) { $sleeps[] = $s; }]);
};
$expect = function (callable $fn, string $code) {
    try { $fn(); } catch (MdmException $e) { return $e->errorCode === $code ? $e : null; }
    return null;
};

/** A row of GET /api/v1/mdm/devices as documented. */
function mdm_list_row(int $n, array $over = []): array {
    return $over + [
        'device_id' => (string) (9007199254740000 + $n), 'device_name' => "iPad $n", 'platform_type' => 'iOS', 'platform_type_id' => 1, 'os_version' => '17.5.1',
        'model' => 'iPad (9th generation)', 'product_name' => 'iPad', 'serial_number' => sprintf('DMPX%07d', $n), 'udid' => sprintf('00008030-%016d', $n), 'imei' => '',
        'is_lost_mode_enabled' => false, 'owned_by' => 1, 'is_removed' => false,
        'user' => ['user_name' => "User $n", 'user_id' => 100 + $n, 'user_email' => "user$n@example.test"],
        'summary' => ['profile_count' => 5, 'app_count' => 10, 'doc_count' => 1, 'group_count' => 2],
    ];
}
function mdm_detail_row(array $over = []): array {
    return $over + ['model' => 'iPad (9th generation)', 'is_supervised' => true, 'is_lost_mode_enabled' => false, 'battery_level' => 77, 'device_capacity' => 64000, 'available_device_capacity' => 16000,
        'registered_time' => 1735689600000, 'unregistered_time' => -1, 'security' => ['passcode_complaint' => true, 'device_rooted' => false, 'passcode_present' => true],
        'apn_username' => 'apnuser', 'apn_password' => 'APN-SECRET-VALUE', 'os' => ['platform_type' => 'iOS', 'os_version' => '17.5.1']];
}
function mdm_summary_row(int $n, array $over = []): array {
    return $over + ['device_id' => (string) (9007199254740000 + $n), 'last_contact_time' => (time() - 3600) * 1000, 'platform_type' => 'ios', 'group' => [['group_id' => 1, 'group_name' => 'Training iPads'], ['group_id' => 2, 'group_name' => 'Room 4']]];
}
/** Put $count devices (with details and summaries) into the mock. */
function mdm_seed_mock(callable $mock, int $count, array $overridesByN = []): void {
    $devices = []; $details = []; $summaries = [];
    for ($n = 1; $n <= $count; $n++) {
        $o = $overridesByN[$n] ?? [];
        $row = mdm_list_row($n, $o['list'] ?? []);
        $devices[] = $row;
        $details[$row['device_id']] = mdm_detail_row($o['detail'] ?? []);
        $summaries[$row['device_id']] = mdm_summary_row($n, $o['summary'] ?? []);
    }
    $mock(['devices' => $devices, 'details' => $details, 'summaries' => $summaries]);
}

/** The single connection row, saved the way the settings page saves it (secrets encrypted). Returns its id. */
function mdm_save_row(array $over = []): int {
    global $db, $q, $esc;
    $q('DELETE FROM manageengine_mdm_integrations');
    $o = $over + ['enabled' => 1, 'data_center' => 'us', 'client_id' => '1000.TESTCLIENT', 'secret' => 'good-secret', 'refresh' => 'good-refresh', 'sync_interval_minutes' => 60, 'enrich_details' => 1, 'stale_days' => 7];
    $q("INSERT INTO manageengine_mdm_integrations SET enabled={$o['enabled']}, data_center='{$o['data_center']}', client_id='{$o['client_id']}', client_secret_enc='" . $esc(encryptSetting($o['secret'])) . "', refresh_token_enc='" . $esc(encryptSetting($o['refresh'])) . "', sync_interval_minutes={$o['sync_interval_minutes']}, enrich_details={$o['enrich_details']}, stale_days={$o['stale_days']}");
    return (int) $db->insert_id;
}
function mdm_clean(): void {
    global $q;
    foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'mdm_sync_log', 'assets'] as $t) { $q("DELETE FROM $t"); }
}
