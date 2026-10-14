<?php
/*
 * Seeds a THROWAWAY install for tests/browser/mdm_smoke.mjs: an administrator (admin@scratch.test / MDM_SMOKE_PASSWORD, default Scratch-Admin-1234),
 * two departments and two assets, and an on-premises ManageEngine MDM connection pointing at the LOCAL mock (tests/mock/manageengine_mdm_mock.php)
 * which it also fills with devices (one not compliant, one not heard from in 40 days, one with a hostile name).
 *
 *   MDM_MOCK_URL=http://127.0.0.1:8671 RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=rivetit_scratch_x RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/browser/mdm_seed.php
 *
 * config.php must point at the scratch database and define RIVETIT_MDM_ALLOW_HTTP_LOOPBACK (the mock is plain http on loopback) and $config_https_only = FALSE.
 * It WIPES users, assets, clients and the MDM tables of that database (name must contain "scratch").
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (getenv('RIVETIT_TEST_DB') !== '1' || !preg_match('/scratch/i', (string) getenv('RIVETIT_TEST_DB_NAME'))) { fwrite(STDERR, "Refusing: scratch database only\n"); exit(2); }
$mock = rtrim((string) getenv('MDM_MOCK_URL'), '/');
if (!preg_match('#^http://127\.0\.0\.1:\d+$#', $mock)) { fwrite(STDERR, "Set MDM_MOCK_URL=http://127.0.0.1:PORT\n"); exit(2); }
$root = dirname(__DIR__, 2);
require_once "$root/config.php";
require_once "$root/functions.php";
require_once "$root/vendor/autoload.php";
$db = $mysqli;
if (!preg_match('/scratch/i', (string) $db->query('SELECT DATABASE()')->fetch_row()[0])) { fwrite(STDERR, "Refusing: not a scratch database\n"); exit(2); }
$q = fn (string $s) => $db->query($s);
$esc = fn (string $s) => $db->real_escape_string($s);
$db->query("SET SESSION sql_mode=''");
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'mdm_sync_log', 'manageengine_mdm_integrations', 'integration_client_map', 'asset_sync_state', 'assets', 'contacts', 'audit_events'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM clients");
$q("INSERT INTO clients SET client_id=1, client_name='Dept A', client_type=''");
$q("INSERT INTO clients SET client_id=2, client_name='Dept B', client_type=''");
$pw = (string) (getenv('MDM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$q("UPDATE users SET user_email='admin@scratch.test', user_name='Scratch Admin', user_password='" . $esc(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=1");
$q("INSERT IGNORE INTO user_settings (user_id) VALUES (1)");
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Existing iPad', asset_serial='DMPX0000001', asset_make='', asset_status='Active', asset_client_id=1");
$q("INSERT INTO contacts SET contact_name='User One', contact_email='user1@example.test', contact_client_id=1");
$q("INSERT INTO manageengine_mdm_integrations SET enabled=1, edition='onprem', server_url='" . $esc($mock) . "', api_key_enc='" . $esc(encryptSetting('onprem-good-key')) . "', api_key_expires_on='" . date('Y-m-d', strtotime('+12 days')) . "',
    sync_interval_minutes=15, enrich_details=1, auto_create_assets=1, default_client_id=2");

$post = function (array $s) use ($mock) { $c = stream_context_create(['http' => ['method' => 'POST', 'content' => json_encode($s), 'header' => 'Content-Type: application/json']]); file_get_contents("$mock/__state", false, $c); };
$post(['__reset' => 1]);
$row = function (int $n, array $o = []) { return $o + ['device_id' => (string) (9007199254740000 + $n), 'device_name' => "iPad $n", 'platform_type' => 'iOS', 'platform_type_id' => 1, 'os_version' => '17.5.1', 'model' => 'iPad (9th generation)',
    'product_name' => 'iPad', 'serial_number' => sprintf('DMPX%07d', $n), 'udid' => sprintf('00008030-%016d', $n), 'imei' => '', 'is_lost_mode_enabled' => false, 'owned_by' => 1, 'is_removed' => false,
    'user' => ['user_name' => "User $n", 'user_id' => 100 + $n, 'user_email' => "user$n@example.test"], 'summary' => ['group_count' => 1]]; };
$devices = [$row(1), $row(2, ['device_name' => '<img src=x onerror=alert(1)> Evil', 'model' => 'iPhone 14', 'product_name' => 'iPhone14,7']), $row(3), $row(4, ['platform_type' => 'Android', 'model' => 'SM-T505', 'product_name' => 'Samsung Galaxy Tab A7'])];
$details = $summaries = [];
foreach ($devices as $d) {
    $n = (int) substr($d['device_id'], -2);
    $details[$d['device_id']] = ['model' => $d['model'], 'is_supervised' => true, 'battery_level' => 77, 'device_capacity' => 64000, 'available_device_capacity' => 16000, 'registered_time' => 1735689600000, 'unregistered_time' => -1,
        'security' => ['passcode_complaint' => $n !== 3, 'device_rooted' => false]];
    $summaries[$d['device_id']] = ['device_id' => $d['device_id'], 'last_contact_time' => (time() - ($n === 3 ? 40 * 86400 : 3600)) * 1000, 'group' => [['group_id' => 1, 'group_name' => 'Training iPads']]];
}
$post(['onprem' => true, 'devices' => $devices, 'details' => $details, 'summaries' => $summaries]);
echo json_encode(['ok' => true, 'devices' => count($devices)]) . "\n";
