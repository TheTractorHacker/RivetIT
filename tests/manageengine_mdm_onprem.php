<?php
/*
 * ManageEngine MDM, release 26.10.46: the ON-PREMISES edition (API key), MSP per-customer scoping, response checks, the server-address policy, and
 * everything the sync now does with assets (name match, Phone / Tablet creation, no-overwrite, contact assignment, the client mapping queue, stale
 * retirement) plus the sync log / sync-watch registration and the API key expiry reminder. Against the LOCAL mock only.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_onprem.php
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\ManageEngineMdmClient;
use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;
use ITFlow\Integrations\ManageEngine\MdmException;
use ITFlow\Integrations\ManageEngine\MdmStale;
use ITFlow\Integrations\ManageEngine\MdmSyncService;
use ITFlow\Recovery\SyncWatch;

$KEY = 'onprem-good-key';
$mkOn = function (array $opts = [], string $key = 'onprem-good-key') use ($base, &$sleeps): ManageEngineMdmClient {
    return ManageEngineMdmClient::onPrem($base, $key, $opts + ['allow_http_loopback' => true, 'max_retry_after' => 5, 'sleep' => function (float $s) use (&$sleeps) { $sleeps[] = $s; }]);
};
$expectC = function (callable $fn, string $code) {
    try { $fn(); } catch (MdmException $e) { return $e->errorCode === $code ? $e : null; }
    return null;
};

// =====================================================================================================================================
// A. on-premises client
$reset(); mdm_seed_mock($mock, 3); $mock(['onprem' => true]);
$c = $mkOn();
$r = $c->testConnection();
$ok($r->success && ($r->details['total'] ?? null) === 3, 'on-prem: Test connection works with the API key and reports the device count (3)');
$lg = $log();
$ok($lg !== [] && array_unique(array_column($lg, 'auth')) === [$KEY] && !in_array('/oauth/v2/token', array_column($lg, 'path'), true), 'on-prem: the key is sent as the Authorization header value (nothing else added) and no OAuth token request is ever made');
$ok(count($c->listAllDevices()) === 3 && $c->stats()['token_fetches'] === 0, 'on-prem: the device list is read; no token fetched');
$reset(); mdm_seed_mock($mock, 3); $mock(['onprem' => true]);
$c = $mkOn([], 'wrong-key-value-12345');
$e = $expectC(fn () => $c->listAllDevices(), MdmException::AUTH_FAILED);
$ok($e !== null && stripos($e->getMessage(), '6 months') !== false && strpos($e->getMessage(), 'wrong-key-value-12345') === false, 'on-prem: a rejected key is AUTH_FAILED with the expiry hint, and the key is not echoed');
$ok(count(array_filter($log(), fn ($x) => str_starts_with($x['path'], '/api/'))) === 1, 'on-prem: a 401 is final (one request, no refresh loop)');
$res = $mkOn([], 'nope')->testConnection();
$ok(!$res->success && ($res->details['error_code'] ?? '') === MdmException::AUTH_FAILED && strpos((string) $res->error, 'nope') === false, 'on-prem: Test connection with a bad key fails cleanly');

// pagination (server caps pages at 50), 429, malformed, oversize
$reset(); mdm_seed_mock($mock, 120); $mock(['onprem' => true, 'max_limit' => 50]);
$ok(count($mkOn()->listAllDevices()) === 120, 'on-prem: 120 devices read through a server that caps pages at 50');
$reset(); mdm_seed_mock($mock, 2); $mock(['onprem' => true, 'fail_once' => [429, 429], 'retry_after' => 2]); $sleeps = [];
$ok(count($mkOn()->listAllDevices()) === 2 && $sleeps === [2.0, 2.0], 'on-prem: 429 is retried after Retry-After and then succeeds');
$reset(); mdm_seed_mock($mock, 2); $mock(['onprem' => true, 'fail_status' => 429]);
$ok($expectC(fn () => $mkOn(['max_retries' => 2])->listAllDevices(), MdmException::THROTTLED) !== null, 'on-prem: a server that keeps throttling ends as THROTTLED after the retry budget');
$reset(); $mock(['onprem' => true, 'malformed' => true]);
$ok($expectC(fn () => $mkOn()->listAllDevices(), MdmException::BAD_RESPONSE) !== null, 'on-prem: a truncated / malformed JSON body is BAD_RESPONSE');
$reset(); $mock(['onprem' => true, 'html' => true]);
$ok($expectC(fn () => $mkOn()->listAllDevices(), MdmException::BAD_RESPONSE) !== null, 'on-prem: an HTML body is BAD_RESPONSE');
$reset(); $mock(['onprem' => true, 'oversize' => 3000000]);
$e = $expectC(fn () => $mkOn(['max_bytes' => 1048576])->listAllDevices(), MdmException::BAD_RESPONSE);
$ok($e !== null && stripos($e->getMessage(), 'larger') !== false, 'on-prem: a response over the size cap is refused (BAD_RESPONSE), not buffered');
$reset(); $mock(['onprem' => true, 'oversize' => 3000000]);
$ok($expectC(fn () => $mkOn()->listAllDevices(), MdmException::BAD_RESPONSE) === null, 'on-prem: the default cap (8 MB) lets a 3 MB body through to the JSON check (it parses; no devices)');
// transport rules
$thrown = function (string $url, bool $allow): bool { try { ManageEngineMdmClient::assertTransport($url, $allow); return false; } catch (MdmException) { return true; } };
$ok($thrown('http://mdm.example.com', false) && $thrown('http://mdm.example.com', true) && $thrown('http://10.0.0.5', true) && $thrown('http://127.0.0.1', false) && !$thrown('http://127.0.0.1:8080', true) && !$thrown('https://mdm.example.com', false) && $thrown('ftp://x', true), 'transport: https always; plain http only to loopback and only behind the explicit test flag');
$cl = ManageEngineMdmClient::onPrem('http://127.0.0.1:1', 'k', ['allow_http_loopback' => false]);
$ok(!defined('RIVETIT_MDM_ALLOW_HTTP_LOOPBACK') || RIVETIT_MDM_ALLOW_HTTP_LOOPBACK === true, 'transport: the test constant is only ever set by this rig');
// MSP scoping
$reset(); $a = [mdm_list_row(1), mdm_list_row(2)]; $b = [mdm_list_row(3)];
$mock(['onprem' => true, 'customers' => [['customer_id' => '9007199254740995', 'customer_name' => 'Acme Corp'], ['customer_id' => '42', 'customer_name' => 'Beta LLC']], 'customer_devices' => ['9007199254740995' => $a, '42' => $b]]);
$c = $mkOn();
$cust = $c->listCustomers();
$ok(count($cust) === 2 && $cust[0] === ['customer_id' => '9007199254740995', 'customer_name' => 'Acme Corp'], 'MSP: listCustomers reads GET customers (customer_id kept as a string)');
$c->setCustomer('9007199254740995');
$ok(count($c->listAllDevices()) === 2, 'MSP: with X-CUSTOMER set, the devices of that customer are returned');
$c->setCustomer('42');
$ok(count($c->listAllDevices()) === 1, 'MSP: another customer id scopes to another device list');
$lg = array_values(array_filter($log(), fn ($x) => str_starts_with($x['path'], '/api/v1/mdm/devices')));
$ok(($lg[0]['x_customer'] ?? null) === '9007199254740995' && end($lg)['x_customer'] === '42', 'MSP: the X-CUSTOMER header is what carries the customer');
$ok(array_filter($log(), fn ($x) => $x['path'] === '/api/v1/mdm/customers' && $x['x_customer'] === null) !== [], 'MSP: the customer list itself is requested without a customer header');
$thrownC = false; try { $c->setCustomer("1; DROP"); } catch (MdmException) { $thrownC = true; }
$ok($thrownC, 'MSP: a customer id that is not digits is refused (header injection)');
$src = php_strip_whitespace("$root/src/Integrations/ManageEngine/ManageEngineMdmClient.php");
$ok(!preg_match('/error_log|logApp|var_dump|print_r|echo /', $src), 'the client never logs or prints (so a token cannot reach a log)');

// =====================================================================================================================================
// B. server-address policy
$netsBefore = (string) $one('SELECT config_webhook_allowed_networks FROM settings WHERE company_id=1');
$q("UPDATE settings SET config_webhook_allowed_networks='' WHERE company_id=1");
$v = fn (string $u) => MdmClientFactory::vetServerUrl($db, $u);
$ok(!$v('http://mdm.example.com')['ok'] && !$v('https://169.254.169.254')['ok'] && !$v('https://127.0.0.1:8383')['ok'] && !$v('https://localhost')['ok'], 'policy: http, cloud metadata and loopback are refused');
$ok(!$v('https://10.1.2.3:8383')['ok'] && !$v('https://192.168.1.10')['ok'], 'policy: a private-network server is refused until the administrator allows that network');
$ok(!$v('https://user:pw@8.8.8.8')['ok'] && !$v('https://8.8.8.8/api/v1')['ok'] && !$v('https://8.8.8.8?x=1')['ok'] && !$v('')['ok'] && !$v('not a url')['ok'], 'policy: credentials, a path, a query and junk are refused');
$p = $v('https://8.8.8.8:8383');
$ok($p['ok'] && $p['url'] === 'https://8.8.8.8:8383' && $p['target']['ips'] === ['8.8.8.8'], 'policy: a public https address passes and the vetted address is kept for pinning');
$q("UPDATE settings SET config_webhook_allowed_networks='10.0.0.0/8' WHERE company_id=1");
$p = $v('https://10.1.2.3:8383');
$ok($p['ok'] && $p['target']['ips'] === ['10.1.2.3'] && !$v('https://192.168.1.10')['ok'] && !$v('https://127.0.0.1')['ok'], 'policy: the same allowed-networks list webhooks use lets 10.0.0.0/8 through; other private ranges and loopback stay refused');
$q("UPDATE settings SET config_webhook_allowed_networks='127.0.0.0/8' WHERE company_id=1");
$ok(!$v('https://127.0.0.1')['ok'], 'policy: loopback can never be allowed, even when listed');
$q("UPDATE settings SET config_webhook_allowed_networks='" . $esc($netsBefore) . "' WHERE company_id=1");

// =====================================================================================================================================
// C. mapping: name match, types, creation, no-overwrite, contacts, mapping queue
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'mdm_sync_log', 'asset_sync_state', 'asset_retire_queue', 'asset_rmm_links', 'asset_assignments', 'contacts', 'assets', 'integration_client_map', 'audit_events'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM clients WHERE client_id IN (801, 802, 803)");
foreach ([801 => 'Acme Corp', 802 => 'Beta LLC', 803 => 'Gamma Inc'] as $id => $nm) { $q("INSERT INTO clients SET client_id=$id, client_name='$nm', client_type=''"); }
$q('DELETE FROM manageengine_mdm_integrations');
$q("INSERT INTO manageengine_mdm_integrations SET enabled=1, edition='onprem', server_url='$base', api_key_enc='" . $esc(encryptSetting($KEY)) . "', sync_interval_minutes=15, auto_create_assets=1, default_client_id=801");
$iid = (int) $db->insert_id;
$M = fn (array $cfg = []) => new MdmDeviceMapper($db, $iid, 0, $cfg + ['auto_create' => true, 'default_client_id' => 801, 'msp_mode' => false]);
$dev = fn (int $n, array $over = []) => MdmDeviceMapper::normalize(mdm_list_row($n, $over['list'] ?? []), $over['detail'] ?? mdm_detail_row(), $over['summary'] ?? mdm_summary_row($n));
$asset = fn (string $sql) => $db->query("SELECT * FROM assets WHERE $sql ORDER BY asset_id LIMIT 1")->fetch_assoc();

// types
$types = [
    'iphone' => [['model' => 'iPhone 14', 'product_name' => 'iPhone14,7', 'platform_type' => 'iOS'], 'Phone'],
    'ipad' => [['model' => 'iPad (9th generation)', 'product_name' => 'iPad', 'platform_type' => 'iOS'], 'Tablet'],
    'android phone' => [['model' => 'SM-S911B', 'product_name' => 'Samsung Galaxy S23', 'platform_type' => 'Android'], 'Phone'],
    'android tablet' => [['model' => 'SM-T505', 'product_name' => 'Samsung Galaxy Tab A7', 'platform_type' => 'Android'], 'Tablet'],
    'pixel tablet' => [['model' => 'Pixel Tablet', 'product_name' => 'Google Pixel Tablet', 'platform_type' => 'Android'], 'Tablet'],
    'windows' => [['model' => 'Latitude 7420', 'product_name' => 'Dell Latitude', 'platform_type' => 'Windows'], 'Laptop'],
    'unknown' => [['model' => 'Widget', 'product_name' => 'Widget', 'platform_type' => 'Zephyr'], 'Other'],
];
foreach ($types as $label => [$list, $expectType]) { $ok(MdmDeviceMapper::assetType(MdmDeviceMapper::normalize(mdm_list_row(1, $list))) === $expectType, "type: $label -> $expectType"); }
$f = MdmDeviceMapper::assetFacts(MdmDeviceMapper::normalize(mdm_list_row(1, ['model' => 'SM-S911B', 'product_name' => 'Samsung Galaxy S23', 'platform_type' => 'Android', 'os_version' => '14'])));
$ok($f['make'] === 'Samsung' && $f['model'] === 'SM-S911B' && $f['os'] === 'Android 14', 'facts: make from the product name, model, OS label');

// creation (auto_create on, default department)
$st = $M()->syncDevices([$dev(1), $dev(2, ['list' => ['model' => 'iPhone 14', 'product_name' => 'iPhone14,7']])]);
$ok($st['created'] === 2 && $st['unmatched'] === 0 && (int) $one('SELECT COUNT(*) FROM assets') === 2, 'create: two unknown devices create two assets in the default department');
$a1 = $asset("asset_serial='DMPX0000001'"); $a2 = $asset("asset_serial='DMPX0000002'");
$ok($a1['asset_type'] === 'Tablet' && $a2['asset_type'] === 'Phone' && (int) $a1['asset_client_id'] === 801 && $a1['asset_make'] === 'Apple' && $a1['asset_os'] === 'iOS 17.5.1', 'create: Tablet / Phone by model, department, make Apple, OS label');
$ok((int) $one("SELECT COUNT(*) FROM asset_mdm_links WHERE asset_id={$a1['asset_id']} AND match_method='created'") === 1 && (int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='asset.created_from_mdm'") === 2, 'create: linked as "created" and audited');
$lk = $db->query("SELECT * FROM asset_mdm_links WHERE asset_id={$a1['asset_id']}")->fetch_assoc();
$raw = json_decode((string) $lk['raw_json'], true);
$ok(is_array($raw) && ($raw['device_id'] ?? '') === '9007199254740001' && strpos((string) $lk['raw_json'], 'APN-SECRET-VALUE') === false && strpos((string) $lk['raw_json'], 'apn_password') === false, 'raw JSON subset is stored without any secret field');
// no overwrite (human edit stays; blank filled; MDM change flows through unedited fields)
$q("UPDATE assets SET asset_make='HumanMake' WHERE asset_id={$a1['asset_id']}");
$d1 = $dev(1, ['list' => ['os_version' => '18.0', 'model' => 'iPad (10th generation)']]);
$M()->syncDevices([$d1]);
$a1b = $asset("asset_id={$a1['asset_id']}");
$ok($a1b['asset_make'] === 'HumanMake', 'no-overwrite: a make a person edited is left alone');
$ok($a1b['asset_os'] === 'iOS 18.0' && $a1b['asset_model'] === 'iPad (10th generation)', 'no-overwrite: fields still holding the value this sync wrote follow the MDM');
$q("UPDATE assets SET asset_os='Custom OS note' WHERE asset_id={$a1['asset_id']}");
$M()->syncDevices([$dev(1, ['list' => ['os_version' => '18.1', 'model' => 'iPad (10th generation)']])]);
$ok($asset("asset_id={$a1['asset_id']}")['asset_os'] === 'Custom OS note', 'no-overwrite: a later human OS edit is respected too');
// existing asset: serial match fills blanks only
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Front desk iPad', asset_serial='DMPX0000003', asset_make='', asset_model='', asset_os='', asset_status='Active', asset_client_id=802");
$pre = (int) $db->insert_id;
$st = $M()->syncDevices([$dev(3)]);
$ra = $asset("asset_id=$pre");
$ok($st['matched'] === 1 && $st['created'] === 0 && $ra['asset_make'] === 'Apple' && $ra['asset_name'] === 'Front desk iPad' && (int) $ra['asset_client_id'] === 802, 'match by serial: blanks are filled, the name and department are untouched');
// IMEI / UDID
$q("INSERT INTO assets SET asset_type='Phone', asset_name='Sales phone', asset_serial='356938035643809', asset_make='Samsung', asset_status='Active', asset_client_id=801");
$pi = (int) $db->insert_id;
$st = $M()->syncDevices([$dev(4, ['list' => ['serial_number' => '', 'imei' => '356938035643809']])]);
$ok($st['matched'] === 1 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740004'") === $pi && $one("SELECT match_method FROM asset_mdm_links WHERE asset_id=$pi") === 'imei', 'match by IMEI when the device has no serial');
// name match within the department
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Lobby Tablet', asset_serial='', asset_status='Active', asset_client_id=801"); $byname = (int) $db->insert_id;
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Lobby Tablet', asset_serial='', asset_status='Active', asset_client_id=802"); $other = (int) $db->insert_id;
$st = $M()->syncDevices([$dev(5, ['list' => ['device_name' => 'lobby tablet', 'serial_number' => '', 'udid' => '']])]);
$ok($st['matched'] === 1 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740005'") === $byname && $one("SELECT match_method FROM asset_mdm_links WHERE asset_id=$byname") === 'name', 'match by name: the unique asset with that name in the device\'s department (case-insensitive), not the same name in another department');
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Twin', asset_serial='', asset_status='Active', asset_client_id=801"); $q("INSERT INTO assets SET asset_type='Tablet', asset_name='Twin', asset_serial='', asset_status='Active', asset_client_id=801");
$before = (int) $one('SELECT COUNT(*) FROM assets');
$st = $M()->syncDevices([$dev(6, ['list' => ['device_name' => 'Twin', 'serial_number' => '', 'udid' => '']])]);
$ok($st['unmatched'] === 1 && $st['created'] === 0 && (int) $one('SELECT COUNT(*) FROM assets') === $before && $one("SELECT reason FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740006'") === 'ambiguous', 'name match: two assets with the same name is ambiguous, nothing is created or linked');
// archived assets never match
$q("INSERT INTO assets SET asset_type='Tablet', asset_name='Old', asset_serial='DMPX0000007', asset_status='Retired', asset_client_id=801, asset_archived_at=NOW()");
$st = $M()->syncDevices([$dev(7)]);
$ok($st['created'] === 1 && $st['matched'] === 0, 'an archived asset with the same serial is not matched (a new asset is created)');

// contacts (Intune user-assignment logic)
$q("INSERT INTO contacts SET contact_id=9001, contact_name='Una Unique', contact_email='una@example.test', contact_client_id=801");
$q("INSERT INTO contacts SET contact_id=9002, contact_name='Dup One', contact_email='dup@example.test', contact_client_id=801");
$q("INSERT INTO contacts SET contact_id=9003, contact_name='Dup Two', contact_email='dup@example.test', contact_client_id=801");
$q("INSERT INTO contacts SET contact_id=9004, contact_name='Wrong Dept', contact_email='other@example.test', contact_client_id=802");
$M()->syncDevices([$dev(11, ['list' => ['user' => ['user_name' => 'Una', 'user_email' => 'UNA@example.test']]]), $dev(12, ['list' => ['user' => ['user_name' => 'Dup', 'user_email' => 'dup@example.test']]]), $dev(13, ['list' => ['user' => ['user_name' => 'Elsewhere', 'user_email' => 'other@example.test']]])]);
$ok((int) $asset("asset_serial='DMPX0000011'")['asset_contact_id'] === 9001, 'contact: exactly one contact with the MDM user\'s e-mail (case-insensitive) in the department is assigned');
$ok((int) $asset("asset_serial='DMPX0000012'")['asset_contact_id'] === 0, 'contact: two contacts with the e-mail is ambiguous, none assigned');
$ok((int) $asset("asset_serial='DMPX0000013'")['asset_contact_id'] === 0, 'contact: a contact of another department is not used');
$q("UPDATE assets SET asset_contact_id=9002 WHERE asset_serial='DMPX0000011'");
$M()->syncDevices([$dev(11, ['list' => ['user' => ['user_name' => 'Una', 'user_email' => 'una@example.test']]])]);
$ok((int) $asset("asset_serial='DMPX0000011'")['asset_contact_id'] === 9002, 'contact: one a person chose is never replaced');

// client mapping queue: MSP
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'assets', 'integration_client_map'] as $t) { $q("DELETE FROM $t"); }
$msp = fn (array $cfg = []) => $M($cfg + ['msp_mode' => true, 'default_client_id' => 0]);
$mk = fn (int $n, string $cust) => ['customer_id' => '77', 'customer_name' => $cust] + $dev($n);
$st = $msp()->syncDevices([$mk(21, 'Acme Corp'), $mk(22, 'Nowhere Ltd')]);
$ok($st['created'] === 1 && $st['unmatched'] === 1, 'mapping: a customer named like a department resolves by name; an unknown customer waits');
$ok($one("SELECT reason FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740022'") === 'needs_mapping' && (int) $asset("asset_serial='DMPX0000021'")['asset_client_id'] === 801, 'mapping: the waiting device says needs_mapping; the other asset is in Acme');
$mrow = $db->query('SELECT * FROM integration_client_map')->fetch_assoc();
$ok($mrow && (int) $mrow['integration_id'] === -$iid && $mrow['external_name'] === 'Nowhere Ltd' && $mrow['map_status'] === 'pending', 'mapping: one pending queue entry, keyed by the NEGATIVE MDM id (it cannot collide with an RMM integration id)');
$msp()->syncDevices([$mk(22, 'Nowhere Ltd')]);
$ok((int) $one('SELECT COUNT(*) FROM integration_client_map') === 1 && (int) $one('SELECT seen_count FROM integration_client_map') >= 2, 'mapping: the same customer is not queued twice');
$qm = new ITFlow\Integrations\ClientMap($db);
$pend = $qm->rows('pending');
$ok(count($pend) === 1 && $pend[0]['integration_name'] === 'ManageEngine MDM', 'mapping: the shared queue page labels the entry "ManageEngine MDM"');
$qm->assign((int) $mrow['map_id'], 802);
$st = $msp()->syncDevices([$mk(22, 'Nowhere Ltd')]);
$ok($st['created'] === 1 && (int) $asset("asset_serial='DMPX0000022'")['asset_client_id'] === 802 && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 0, 'mapping: after the administrator maps it, the next sync creates the asset in that department and clears the review row');
$q("INSERT INTO integration_client_map SET integration_id=" . (-$iid) . ", external_name='Skip Me', map_status='ignored'");
$before = (int) $one('SELECT COUNT(*) FROM assets');
$st = $msp()->syncDevices([$mk(23, 'Skip Me')]);
$ok($st['skipped'] === 1 && (int) $one('SELECT COUNT(*) FROM assets') === $before && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 0, 'mapping: a customer marked ignored is skipped on purpose');
$st = $msp(['default_client_id' => 803])->syncDevices([$mk(24, 'Brand New Customer')]);
$ok($st['created'] === 1 && (int) $asset("asset_serial='DMPX0000024'")['asset_client_id'] === 803, 'mapping: the default department catches a customer with no mapping');
// non-MSP without a default: one account-wide entry
$q('DELETE FROM integration_client_map'); $q('DELETE FROM mdm_unmatched_devices');
$st = $M(['default_client_id' => 0])->syncDevices([$dev(31), $dev(32)]);
$ok($st['unmatched'] === 2 && (int) $one('SELECT COUNT(*) FROM integration_client_map') === 1 && $one('SELECT external_name FROM integration_client_map') === MdmDeviceMapper::ACCOUNT_LABEL, 'mapping: without MSP and without a default department there is ONE queue entry for the whole account');
// auto_create off: review list, no queue noise
$q('DELETE FROM integration_client_map'); $q('DELETE FROM mdm_unmatched_devices'); $before = (int) $one('SELECT COUNT(*) FROM assets');
$st = $M(['auto_create' => false, 'default_client_id' => 0])->syncDevices([$dev(41)]);
$ok($st['unmatched'] === 1 && $st['created'] === 0 && (int) $one('SELECT COUNT(*) FROM assets') === $before && $one('SELECT reason FROM mdm_unmatched_devices') === 'no_match' && (int) $one('SELECT COUNT(*) FROM integration_client_map') === 0, 'create off: the device waits for review (no_match) and nothing is queued or created');

// =====================================================================================================================================
// D. stale retirement (off by default, review queue shared with the RMM rule)
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'assets', 'asset_retire_queue', 'asset_rmm_links'] as $t) { $q("DELETE FROM $t"); }
$M()->syncDevices([$dev(51), $dev(52), $dev(53), $dev(54), $dev(55)]);
$ids = array_column($rows("SELECT asset_id, asset_serial FROM assets"), 'asset_id', 'asset_serial');
// 51 vanished 60 days ago; 52 removed 60 days ago; 53 enrolled but quiet 60 days (never retired); 54 gone but has an RMM link; 55 healthy
$q("UPDATE asset_mdm_links SET last_sync = NOW() - INTERVAL 60 DAY WHERE asset_id = {$ids['DMPX0000051']}");
$q("UPDATE asset_mdm_links SET enrollment_status='removed', last_contact_at = NOW() - INTERVAL 60 DAY WHERE asset_id = {$ids['DMPX0000052']}");
$q("UPDATE asset_mdm_links SET last_contact_at = NOW() - INTERVAL 60 DAY WHERE asset_id = {$ids['DMPX0000053']}");
$q("UPDATE asset_mdm_links SET last_sync = NOW() - INTERVAL 60 DAY WHERE asset_id = {$ids['DMPX0000054']}");
$q("INSERT INTO asset_rmm_links SET asset_id = {$ids['DMPX0000054']}, integration_id = 1");
$stale = new MdmStale($db);
$cand = array_column($stale->candidates($iid, 30), 'asset_id');
sort($cand);
$ok($cand === [(int) $ids['DMPX0000051'], (int) $ids['DMPX0000052']], 'stale: only the vanished and the removed device qualify (a quiet enrolled device, an RMM-managed asset and a healthy one do not)');
$ok((int) $one('SELECT COUNT(*) FROM asset_retire_queue') === 0, 'stale: candidates() is a pure read');
$row = MdmClientFactory::row($db);
$ok((int) $row['stale_retire_enabled'] === 0 && (int) $row['stale_retire_days'] === 30, 'stale: off by default (30 days when switched on)');
$res = MdmSyncService::run($db, $row, 0, 30);   // retirement is off: a real run against the mock must not retire anything
$ok((int) $one('SELECT COUNT(*) FROM asset_retire_queue') === 0, 'stale: with the switch off a sync never queues or archives anything');
$out = $stale->run($iid, 30);
$ok($out['retired'] === 2 && (int) $one('SELECT COUNT(*) FROM asset_retire_queue WHERE queue_status=\'pending\'') === 2 && (int) $one("SELECT COUNT(*) FROM assets WHERE asset_status='Retired' AND asset_archived_at IS NOT NULL") === 2, 'stale: run() archives them as Retired and puts them in the review queue');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='asset.auto_retire'") === 2, 'stale: retirement is audited');
$qid = (int) $one("SELECT queue_id FROM asset_retire_queue WHERE asset_id = {$ids['DMPX0000052']}");
$sa = new ITFlow\Assets\StaleAssets($db);
$ok($sa->restore($qid, 1) && $asset("asset_id = {$ids['DMPX0000052']}")['asset_archived_at'] === null, 'stale: Restore from the shared queue unarchives the asset');
$q("UPDATE asset_mdm_links SET last_sync = NOW(), enrollment_status='enrolled' WHERE asset_id = {$ids['DMPX0000051']}");
$out = $stale->run($iid, 30);
$ok($out['restored'] === 1 && $asset("asset_id = {$ids['DMPX0000051']}")['asset_archived_at'] === null, 'stale: a device that comes back enrolled restores its asset by itself');

// =====================================================================================================================================
// E. the sync service end to end: on-prem, MSP, bad address, sync log, watcher, key expiry
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'assets', 'asset_retire_queue', 'mdm_sync_log', 'integration_client_map', 'asset_sync_state'] as $t) { $q("DELETE FROM $t"); }
$q('DELETE FROM audit_events');
$reset(); mdm_seed_mock($mock, 4); $mock(['onprem' => true]);
$q("UPDATE manageengine_mdm_integrations SET server_url='$base', edition='onprem', msp_mode=0, default_client_id=801, auto_create_assets=1, enrich_details=1, stale_retire_enabled=0 WHERE id=$iid");
$row = MdmClientFactory::row($db);
$res = MdmSyncService::run($db, $row, 0, 60);
$ok($res['ok'] && $res['stats']['created'] === 4 && (int) $one('SELECT COUNT(*) FROM assets') === 4, 'service on-prem: 4 devices -> 4 assets created ' . ($res['message'] ?? ''));
$lg = $rows('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')[0];
$ok($lg['status'] === 'success' && (int) $lg['devices_total'] === 4 && (int) $lg['devices_created'] === 4 && $lg['finished_at'] !== null, 'service: the sync log row has the counts and a finish time');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type='integration.manageengine_mdm.sync'") === 1, 'service: the run is audited');
$ok(strpos(json_encode($rows('SELECT * FROM mdm_sync_log')), $KEY) === false && strpos(json_encode($rows('SELECT * FROM asset_mdm_links')), $KEY) === false, 'the API key appears in no log or link row');
$res = MdmSyncService::run($db, $row, 0, 60);
$ok($res['ok'] && $res['stats']['created'] === 0 && $res['stats']['updated'] === 4, 'service: a second run updates, creates nothing');

// MSP through the service
foreach (['asset_mdm_links', 'assets', 'mdm_unmatched_devices'] as $t) { $q("DELETE FROM $t"); }
$reset(); $mock(['onprem' => true, 'customers' => [['customer_id' => '11', 'customer_name' => 'Acme Corp'], ['customer_id' => '12', 'customer_name' => 'Beta LLC']], 'customer_devices' => ['11' => [mdm_list_row(61), mdm_list_row(62)], '12' => [mdm_list_row(63)]]]);
$q("UPDATE manageengine_mdm_integrations SET msp_mode=1, default_client_id=0, enrich_details=0 WHERE id=$iid");
$res = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60);
$ok($res['ok'] && $res['stats']['total'] === 3 && $res['stats']['created'] === 3, 'service MSP: customers are listed and each customer\'s devices fetched with its own header');
$byCust = $rows('SELECT a.asset_serial, a.asset_client_id, l.customer_id, l.customer_name FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id ORDER BY a.asset_serial');
$ok($byCust[0]['customer_name'] === 'Acme Corp' && (int) $byCust[0]['asset_client_id'] === 801 && $byCust[2]['customer_name'] === 'Beta LLC' && (int) $byCust[2]['asset_client_id'] === 802, 'service MSP: customer name stored on the link and mapped to the same-named department');

// a refused server address: the failure is logged (the watcher must see it) and nothing is requested
$q("UPDATE manageengine_mdm_integrations SET server_url='https://127.0.0.1:9' WHERE id=$iid"); $reset();
$res = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 30);
$lg = $rows('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')[0];
$ok(!$res['ok'] && $lg['status'] === 'failed' && $lg['errors'] !== '' && $log() === [], 'service: a refused server address fails the run, writes a failed log row and makes no request');
$q("UPDATE manageengine_mdm_integrations SET server_url='$base' WHERE id=$iid");

// sync watcher registration
$q('DELETE FROM mdm_sync_log');
$ins = fn (string $status, int $minAgo, string $err = '') => $q("INSERT INTO mdm_sync_log SET integration_id=$iid, started_at=NOW() - INTERVAL $minAgo MINUTE, finished_at=NOW() - INTERVAL $minAgo MINUTE, status='$status', errors='" . $esc($err) . "', triggered_by=0");
$inspect = function () use ($db, $iid) { foreach (SyncWatch::inspect($db, true) as $r) { if ($r['source'] === 'mdm' && $r['id'] === $iid) { return $r; } } return null; };
$ins('success', 5);
$w = $inspect();
$ok($w !== null && $w['label'] === 'ManageEngine MDM' && $w['state'] === 'ok' && $w['interval_min'] === 15 && $w['interval_source'] === 'setting', 'watch: the MDM sync is registered with its own expected interval (the connection\'s 15 minutes)');
$q('DELETE FROM mdm_sync_log'); $ins('success', 200);
$ok($inspect()['state'] === 'stale', 'watch: no run for more than 3x the interval is stale');
$q('DELETE FROM mdm_sync_log'); $ins('failed', 5, 'Zoho sign-in failed'); $ins('failed', 20, 'Zoho sign-in failed'); $ins('failed', 35, 'Zoho sign-in failed');
$ok($inspect()['state'] === 'failing', 'watch: three failed runs with real errors are failing');
$q("UPDATE manageengine_mdm_integrations SET sync_interval_minutes=60 WHERE id=$iid"); $q('DELETE FROM mdm_sync_log'); $ins('success', 100);
$ok($inspect()['state'] === 'ok' && $inspect()['interval_min'] === 60, 'watch: the expected interval follows the connection\'s sync interval (60 min: a run 100 min ago is fine, it was stale at 15 min)');
$q("UPDATE manageengine_mdm_integrations SET enabled=0 WHERE id=$iid");
$ok($inspect() === null, 'watch: a disabled connection is not watched');
$q("UPDATE manageengine_mdm_integrations SET enabled=1 WHERE id=$iid");
$ok(in_array('mdm', ITFlow\Recovery\WatchOverrides::SOURCES, true), 'watch: Mute / interval override / Forget history accept the mdm source');

// API key expiry reminder
$today = date('Y-m-d');
$kd = fn (string $d) => MdmSyncService::keyDaysLeft(['api_key_expires_on' => $d]);
$ok($kd(date('Y-m-d', strtotime('+10 days'))) === 10 && $kd(date('Y-m-d', strtotime('-2 days'))) === -2 && $kd('') === null && $kd('0000-00-00') === null, 'expiry: days left are computed from the date the administrator entered');
$q("DELETE FROM notifications");
if ((int) $one("SELECT COUNT(*) FROM users WHERE user_type=1 AND user_status=1 AND user_archived_at IS NULL") === 0) { $q("INSERT INTO users SET user_id=1, user_name='notify admin', user_email='notify@example.test', user_password='x', user_type=1, user_status=1, user_role_id=0"); }
$q("UPDATE manageengine_mdm_integrations SET api_key_expires_on='" . date('Y-m-d', strtotime('+90 days')) . "', api_key_warned_on=NULL WHERE id=$iid");
$ok(MdmSyncService::checkKeyExpiry($db, MdmClientFactory::row($db)) === 'ok' && (int) $one('SELECT COUNT(*) FROM notifications') === 0, 'expiry: 90 days out, no reminder');
$q("UPDATE manageengine_mdm_integrations SET api_key_expires_on='" . date('Y-m-d', strtotime('+20 days')) . "' WHERE id=$iid");
$nk = fn () => (int) $one("SELECT COUNT(*) FROM notifications WHERE notification_type LIKE 'ManageEngine MDM API key%'");
$ok(MdmSyncService::checkKeyExpiry($db, MdmClientFactory::row($db)) === 'warned' && $nk() > 0, 'expiry: within 30 days a notification is raised (one per active administrator)');
$first = $nk();
$ok(MdmSyncService::checkKeyExpiry($db, MdmClientFactory::row($db)) === 'warned' && $nk() === $first, 'expiry: at most one reminder per day');
$q("UPDATE manageengine_mdm_integrations SET api_key_expires_on='" . date('Y-m-d', strtotime('-1 day')) . "', api_key_warned_on=NULL WHERE id=$iid");
$ok(MdmSyncService::checkKeyExpiry($db, MdmClientFactory::row($db)) === 'expired', 'expiry: an expired key is reported as expired');
$q("UPDATE manageengine_mdm_integrations SET edition='cloud' WHERE id=$iid");
$ok(MdmSyncService::checkKeyExpiry($db, MdmClientFactory::row($db)) === 'none', 'expiry: only the on-premises edition has a key to expire');

// factory
$ok(MdmClientFactory::hasCredentials(['edition' => 'onprem', 'server_url' => 'https://x', 'api_key_enc' => 'y']) && !MdmClientFactory::hasCredentials(['edition' => 'onprem', 'server_url' => '', 'api_key_enc' => 'y']) && !MdmClientFactory::hasCredentials(['edition' => 'cloud', 'client_id' => 'a', 'client_secret_enc' => 'b']) && MdmClientFactory::edition([]) === 'cloud', 'factory: credentials are judged per edition; the default edition is cloud');
