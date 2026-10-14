<?php
/*
 * ManageEngine MDM sync (src/Integrations/ManageEngine): normalisation of the documented responses, the matching rules (serial / IMEI / UDID,
 * duplicates, ambiguous matches stay unmatched, nothing auto-created, asset fields never written), the review-list actions, the sync service
 * end to end against the LOCAL mock (enrichment, degraded scopes, lock, token reuse), secret handling, the disabled state, and the migration.
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=...scratch... RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/manageengine_mdm_sync.php
 */
require __DIR__ . '/manageengine_mdm_lib.php';

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;
use ITFlow\Integrations\ManageEngine\MdmDeviceStatus;
use ITFlow\Integrations\ManageEngine\MdmException;
use ITFlow\Integrations\ManageEngine\MdmSyncService;

$mkAsset = function (string $name, string $serial, string $type = 'Tablet', ?string $archived = null) use ($q, $db): int {
    $arch = $archived ? "'$archived'" : 'NULL';
    $q("INSERT INTO assets SET asset_type='$type', asset_name='" . addslashes($name) . "', asset_serial='" . addslashes($serial) . "', asset_make='Apple', asset_model='orig-model', asset_os='orig-os', asset_status='Active', asset_archived_at=$arch");
    return (int) $db->insert_id;
};
$assetsHash = fn () => md5(json_encode($GLOBALS['rows']('SELECT * FROM assets ORDER BY asset_id')));
$dev = fn (int $n, array $over = []) => MdmDeviceMapper::normalize(mdm_list_row($n, $over['list'] ?? []), $over['detail'] ?? mdm_detail_row(), $over['summary'] ?? mdm_summary_row($n));

// =====================================================================================================================================
// normalize(): the documented response shapes
$n = MdmDeviceMapper::normalize(mdm_list_row(1, ['owned_by' => 2, 'imei' => '356938035643809']), mdm_detail_row(), mdm_summary_row(1));
$ok($n['mdm_device_id'] === '9007199254740001' && $n['device_name'] === 'iPad 1' && $n['platform'] === 'iOS' && $n['os_version'] === '17.5.1', 'normalize: id, name, platform, OS version from the list row (a 16-digit id stays a string)');
$ok($n['serial_number'] === 'DMPX0000001' && $n['udid'] === '00008030-0000000000000001' && $n['imei'] === '356938035643809' && $n['model'] === 'iPad (9th generation)', 'normalize: serial, UDID, IMEI, model');
$ok($n['is_supervised'] === 1 && $n['passcode_compliant'] === 1 && $n['is_lost_mode'] === 0 && $n['is_rooted'] === 0 && $n['battery_level'] === 77, 'normalize: supervised, passcode (docs spell it "passcode_complaint"), lost mode, rooted, battery from details');
$ok($n['owned_by'] === 'personal' && $n['user_name'] === 'User 1' && $n['user_email'] === 'user1@example.test' && $n['group_names'] === 'Training iPads, Room 4', 'normalize: owner (2 = personal), user, groups from the summary');
$ok($n['storage_total'] === 64000 && $n['storage_available'] === 16000 && MdmDeviceStatus::storageFreePercent($n) === 25, 'normalize: storage numbers kept raw; free percent needs no unit');
$ok($n['registered_at'] === date('Y-m-d H:i:s', 1735689600) && $n['last_contact_at'] !== null && $n['enrollment_status'] === 'enrolled', 'normalize: epoch-millisecond times become datetimes');
$p = MdmDeviceMapper::normalize(mdm_list_row(2), mdm_detail_row(['unregistered_time' => 1735689600000, 'security' => ['passcode_complaint' => false, 'device_rooted' => true]]), mdm_summary_row(2, ['last_contact_time' => -1]));
$ok($p['enrollment_status'] === 'unenrolled' && $p['passcode_compliant'] === 0 && $p['is_rooted'] === 1 && $p['last_contact_at'] === null, 'normalize: unregistered_time > 0 is unenrolled; -1 times are NULL; passcode false / rooted true');
$p = MdmDeviceMapper::normalize(mdm_list_row(3, ['is_removed' => true]));
$ok($p['enrollment_status'] === 'removed' && $p['battery_level'] === null && $p['is_supervised'] === null && $p['group_names'] === null && $p['last_contact_at'] === null, 'normalize: is_removed; with no details/summary the extras are NULL (not 0 or empty), so they never overwrite stored values');
$p = MdmDeviceMapper::normalize(mdm_list_row(4, ['imei' => '']), mdm_detail_row(['sims' => [['imei' => '490154203237518']]]));
$ok($p['imei'] === '490154203237518', 'normalize: IMEI falls back to the first SIM in the details');
$p = MdmDeviceMapper::normalize(mdm_list_row(5, ['device_name' => '<b>Evil</b> name']));
$ok($p['device_name'] === 'Evil name', 'normalize: markup is stripped from names');
$ok(MdmDeviceStatus::classify(['enrollment_status' => 'enrolled', 'is_lost_mode' => 1])['state'] === 'lost'
    && MdmDeviceStatus::classify(['passcode_compliant' => 0, 'last_contact_at' => date('Y-m-d H:i:s')])['state'] === 'noncompliant'
    && MdmDeviceStatus::classify(['passcode_compliant' => 1, 'last_contact_at' => date('Y-m-d H:i:s', time() - 9 * 86400)], 7)['state'] === 'stale'
    && MdmDeviceStatus::classify(['passcode_compliant' => 1, 'last_contact_at' => date('Y-m-d H:i:s', time() - 86400)], 7)['state'] === 'ok'
    && MdmDeviceStatus::classify(['enrollment_status' => 'removed'])['state'] === 'removed'
    && MdmDeviceStatus::classify([])['state'] === 'unknown', 'status: lost / not compliant / not seen N days / ok / removed / unknown');

// =====================================================================================================================================
// matching rules
$reset(); mdm_clean();
$iid = mdm_save_row();
$mapper = fn () => new MdmDeviceMapper($GLOBALS['db'], $GLOBALS['iid']);

$a1 = $mkAsset('Kiosk iPad 1', 'DMPX0000001');
$a2 = $mkAsset('Kiosk iPad 2 (lower)', 'dmpx0000002');
$h0 = $assetsHash();
$st = $mapper()->syncDevices([$dev(1), $dev(2), $dev(3)]);
$ok($st['matched'] === 2 && $st['unmatched'] === 1 && $st['total'] === 3 && $st['errors'] === [], 'two devices matched by serial (case-insensitive), one unmatched: ' . json_encode($st));
$l1 = $db->query("SELECT * FROM asset_mdm_links WHERE asset_id=$a1")->fetch_assoc();
$ok($l1 && $l1['mdm_device_id'] === '9007199254740001' && $l1['match_method'] === 'serial' && $l1['battery_level'] == 77 && $l1['group_names'] === 'Training iPads, Room 4' && (int) $l1['is_supervised'] === 1, 'the link row stores id, method, battery, groups, supervised');
$u3 = $db->query("SELECT * FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740003'")->fetch_assoc();
$ok($u3 && $u3['reason'] === 'no_match' && (int) $u3['dismissed'] === 0 && $u3['serial_number'] === 'DMPX0000003', 'the unmatched device waits in the review list (no_match)');
$ok((int) $one('SELECT COUNT(*) FROM assets') === 2 && $assetsHash() === $h0, 'nothing was created and no asset field was changed (make/model/OS stay as typed)');

// re-sync: updates in place, no duplicates
$st = $mapper()->syncDevices([$dev(1, ['detail' => mdm_detail_row(['battery_level' => 40])]), $dev(2), $dev(3)]);
$ok($st['updated'] === 2 && $st['matched'] === 0 && $st['unmatched'] === 1 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 2 && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 1, 're-sync updates existing links in place and does not duplicate rows');
$ok((int) $one("SELECT battery_level FROM asset_mdm_links WHERE asset_id=$a1") === 40, 're-sync refreshes the battery');
$noDetail = MdmDeviceMapper::normalize(mdm_list_row(1, ['os_version' => '17.6']));
$mapper()->syncDevices([$noDetail]);
$ok((int) $one("SELECT battery_level FROM asset_mdm_links WHERE asset_id=$a1") === 40 && $one("SELECT os_version FROM asset_mdm_links WHERE asset_id=$a1") === '17.6' && $one("SELECT group_names FROM asset_mdm_links WHERE asset_id=$a1") === 'Training iPads, Room 4', 'a sync without enrichment updates list fields but keeps the stored battery and groups');

// an asset created later with that serial: the next sync links it and clears the review row
$a3 = $mkAsset('New iPad 3', 'DMPX0000003');
$st = $mapper()->syncDevices([$dev(3)]);
$ok($st['matched'] === 1 && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 0 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740003'") === $a3, 'adding the asset later: next sync links it and removes the review row');

// ambiguous: two assets with the same serial
mdm_clean();
$x1 = $mkAsset('Dup A', 'DMPX0000010'); $x2 = $mkAsset('Dup B', 'DMPX0000010');
$st = $mapper()->syncDevices([$dev(10)]);
$u = $db->query("SELECT * FROM mdm_unmatched_devices")->fetch_assoc();
$ok($st['unmatched'] === 1 && $st['matched'] === 0 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 0 && $u['reason'] === 'ambiguous' && str_contains((string) $u['candidate_asset_ids'], (string) $x1) && str_contains((string) $u['candidate_asset_ids'], (string) $x2), 'two assets with the same serial: ambiguous, stays unmatched, both candidates recorded');

// archived assets never match
mdm_clean();
$mkAsset('Old iPad', 'DMPX0000011', 'Tablet', date('Y-m-d H:i:s'));
$st = $mapper()->syncDevices([$dev(11)]);
$ok($st['unmatched'] === 1 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 0, 'an archived asset with that serial is not matched');

// IMEI / UDID fallbacks
mdm_clean();
$ai = $mkAsset('Phone by IMEI', '356938035643809', 'Mobile Phone');
$au = $mkAsset('Tablet by UDID', '00008030-0000000000000013');
$noSerialA = $dev(12, ['list' => ['serial_number' => '', 'imei' => '356938035643809']]);
$noSerialB = $dev(13, ['list' => ['serial_number' => '']]);
$st = $mapper()->syncDevices([$noSerialA, $noSerialB]);
$ok($st['matched'] === 2 && $one("SELECT match_method FROM asset_mdm_links WHERE asset_id=$ai") === 'imei' && $one("SELECT match_method FROM asset_mdm_links WHERE asset_id=$au") === 'udid', 'no serial reported: matched by IMEI, or by UDID, against the asset serial');
// serial wins over a conflicting IMEI
mdm_clean();
$as = $mkAsset('By serial', 'DMPX0000014'); $mkAsset('By imei', '111111111111119');
$mapper()->syncDevices([$dev(14, ['list' => ['imei' => '111111111111119']])]);
$ok((int) $one('SELECT asset_id FROM asset_mdm_links') === $as && $one('SELECT match_method FROM asset_mdm_links') === 'serial', 'serial number has priority over IMEI');

// placeholder serials are not match keys
mdm_clean();
$mkAsset('Unknown serial asset', 'unknown'); $mkAsset('Zero serial asset', '0'); $mkAsset('Short', 'ab');
$st = $mapper()->syncDevices([$dev(15, ['list' => ['serial_number' => 'unknown', 'imei' => '', 'udid' => '']]), $dev(16, ['list' => ['serial_number' => '0', 'imei' => '', 'udid' => '']]), $dev(17, ['list' => ['serial_number' => 'ab', 'imei' => '', 'udid' => '']])]);
$ok($st['unmatched'] === 3 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 0, 'placeholder or too-short serials ("unknown", "0", "ab") are never used to match');

// two live devices with the same serial: both stay unmatched (never "first one wins")
mdm_clean();
$mkAsset('Contested', 'DMPX0000020');
$st = $mapper()->syncDevices([$dev(20), $dev(21, ['list' => ['serial_number' => 'DMPX0000020']])]);
$ok($st['unmatched'] === 2 && $st['matched'] === 0 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 0 && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE reason='ambiguous'") === 2, 'one serial on two live devices: both ambiguous, nothing linked');
// ... but a removed duplicate (old record of a re-enrolled device) does not block the live one
mdm_clean();
$ar = $mkAsset('Re-enrolled', 'DMPX0000022');
$st = $mapper()->syncDevices([$dev(22, ['list' => ['is_removed' => true]]), $dev(23, ['list' => ['serial_number' => 'DMPX0000022']])]);
$ok($st['matched'] === 1 && $st['skipped'] === 1 && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740023'") === $ar && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 0, 'a removed record is skipped (no review row) and does not block the live device with the same serial');

// asset already linked to another device
mdm_clean();
$ac = $mkAsset('Claimed', 'DMPX0000030');
$mapper()->syncDevices([$dev(30)]);
$st = $mapper()->syncDevices([$dev(30), $dev(31, ['list' => ['serial_number' => 'DMPX0000030']])]);
$ok($st['unmatched'] === 1 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 1 && $one("SELECT mdm_device_id FROM asset_mdm_links WHERE asset_id=$ac") === '9007199254740030', 'an asset already linked to a LIVE device is not stolen by another device (ambiguous)');
$st = $mapper()->syncDevices([$dev(32, ['list' => ['serial_number' => 'DMPX0000030']])]);   // device 30 is gone from the list: re-enrolled as 32
$ok($st['matched'] === 1 && $one("SELECT mdm_device_id FROM asset_mdm_links WHERE asset_id=$ac") === '9007199254740032' && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 1, 'a re-enrolled device (new id, old one gone) takes over the existing link in place');

// a device that turns into "removed" keeps its link, marked removed
mdm_clean();
$ad = $mkAsset('Retired iPad', 'DMPX0000040');
$mapper()->syncDevices([$dev(40)]);
$mapper()->syncDevices([$dev(40, ['list' => ['is_removed' => true]])]);
$ok($one("SELECT enrollment_status FROM asset_mdm_links WHERE asset_id=$ad") === 'removed', 'a device removed from management keeps its link, marked removed');

// prune: unmatched devices that left ManageEngine disappear after a full run
mdm_clean();
$mapper()->syncDevices([$dev(50), $dev(51)]);
$ok((int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 2, 'two unmatched devices in the review list');
$mapper()->pruneUnmatched(['9007199254740050']);
$ok((int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 1 && $one('SELECT mdm_device_id FROM mdm_unmatched_devices') === '9007199254740050', 'pruneUnmatched removes review rows for devices that are no longer listed');

// SQL-hostile values are stored, not executed
mdm_clean();
$st = $mapper()->syncDevices([$dev(60, ['list' => ['device_name' => "x'); DROP TABLE assets;-- ", 'serial_number' => "SN'\"\\ 12345"]])]);
$ok($st['unmatched'] === 1 && $st['errors'] === [] && (int) $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='assets'") === 1 && str_contains((string) $one('SELECT device_name FROM mdm_unmatched_devices'), 'DROP TABLE'), 'quotes / SQL in device fields are escaped and stored as text');

// =====================================================================================================================================
// review-list actions
mdm_clean();
$mapper()->syncDevices([$dev(70), $dev(71, ['list' => ['device_name' => 'Galaxy Tab', 'platform_type' => 'Android', 'model' => 'SM-T505', 'product_name' => 'Galaxy Tab A7']])]);
$uid70 = (int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740070'");
$uid71 = (int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740071'");
$target = $mkAsset('Hand-picked asset', 'DIFFERENT-SERIAL');
$r = $mapper()->linkUnmatched($uid70, $target);
$ok($r['ok'] && (int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740070'") === $target && $one("SELECT match_method FROM asset_mdm_links WHERE asset_id=$target") === 'manual' && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$uid70") === 0, 'Link to existing asset: link row (method manual) created, review row removed');
$ok($one("SELECT asset_serial FROM assets WHERE asset_id=$target") === 'DIFFERENT-SERIAL', 'linking does not change the asset');
$again = $mapper()->linkUnmatched($uid71, $target);
$ok(!$again['ok'] && (int) $one("SELECT COUNT(*) FROM mdm_unmatched_devices WHERE id=$uid71") === 1, 'an asset that already has an MDM link cannot take a second device');
$arch = $mkAsset('Archived one', 'ARCH-1', 'Tablet', date('Y-m-d H:i:s'));
$r = $mapper()->linkUnmatched($uid71, $arch);
$ok(!$r['ok'] && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 1, 'an archived or missing asset cannot be linked');
$r = $mapper()->linkUnmatched(99999, $target);
$ok(!$r['ok'], 'a stale review id is refused cleanly');
$before = (int) $one('SELECT COUNT(*) FROM assets');
$r = $mapper()->createAssetFor($uid71, 1);
$na = $r['asset_id'] ? $db->query("SELECT * FROM assets WHERE asset_id={$r['asset_id']}")->fetch_assoc() : null;
$ok($r['ok'] && $na && (int) $one('SELECT COUNT(*) FROM assets') === $before + 1 && $na['asset_type'] === 'Tablet' && $na['asset_name'] === 'Galaxy Tab' && $na['asset_serial'] === 'DMPX0000071' && (int) $na['asset_client_id'] === 1 && $na['asset_os'] === 'Android 17.5.1', 'Create asset: one tablet asset with name, serial, model, OS in the chosen department');
$ok((int) $one("SELECT asset_id FROM asset_mdm_links WHERE mdm_device_id='9007199254740071'") === (int) $r['asset_id'] && $one("SELECT match_method FROM asset_mdm_links WHERE mdm_device_id='9007199254740071'") === 'created' && (int) $one('SELECT COUNT(*) FROM mdm_unmatched_devices') === 0, 'the created asset is linked and the review row is gone');
$iphone = $dev(72, ['list' => ['model' => 'iPhone 14', 'product_name' => 'iPhone']]);
$mapper()->syncDevices([$iphone]);
$r = $mapper()->createAssetFor((int) $one("SELECT id FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740072'"), 0);
$ok($one("SELECT asset_type FROM assets WHERE asset_id={$r['asset_id']}") === 'Mobile Phone' && $one("SELECT asset_make FROM assets WHERE asset_id={$r['asset_id']}") === 'Apple', 'Create asset: an iPhone becomes a Mobile Phone, make Apple');

// =====================================================================================================================================
// the sync service against the mock
mdm_clean(); $reset();
$iid = mdm_save_row();
mdm_seed_mock($mock, 6, [4 => ['detail' => ['security' => ['passcode_complaint' => false]]], 5 => ['summary' => ['last_contact_time' => (time() - 30 * 86400) * 1000]]]);
foreach ([1, 2, 3, 4] as $i) { $mkAsset("Fleet iPad $i", sprintf('DMPX%07d', $i)); }
$h0 = $assetsHash();
$opts = ['mdm_base' => $base, 'accounts_base' => $base, 'sleep' => fn ($s) => null];
$row = MdmClientFactory::row($db);
$run = MdmSyncService::run($db, $row, 7, 60, $opts);
$ok($run['ok'] && $run['stats']['total'] === 6 && $run['stats']['matched'] === 4 && $run['stats']['unmatched'] === 2 && $run['stats']['errors'] === [], 'sync service: 6 devices, 4 linked, 2 to review: ' . $run['message']);
$ok($assetsHash() === $h0 && (int) $one('SELECT COUNT(*) FROM assets') === 4, 'sync service: the asset table is untouched');
$logRow = $db->query("SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1")->fetch_assoc();
$ok($logRow['status'] === 'success' && (int) $logRow['devices_total'] === 6 && (int) $logRow['devices_matched'] === 4 && (int) $logRow['devices_unmatched'] === 2 && (int) $logRow['triggered_by'] === 7 && $logRow['finished_at'] !== null, 'sync log row: counts, status, who started it');
$ok($one('SELECT last_sync_at FROM manageengine_mdm_integrations WHERE id=' . $iid) !== null, 'the connection records last_sync_at');
$l4 = $db->query("SELECT * FROM asset_mdm_links WHERE mdm_device_id='9007199254740004'")->fetch_assoc();
$ok((int) $l4['passcode_compliant'] === 0 && MdmDeviceStatus::classify($l4)['state'] === 'noncompliant' && $l4['last_contact_at'] !== null && (int) $l4['battery_level'] === 77, 'enrichment: detail (passcode, battery) and summary (last contact, groups) reached the link');
$u5 = $db->query("SELECT * FROM mdm_unmatched_devices WHERE mdm_device_id='9007199254740005'")->fetch_assoc();
$ok(MdmDeviceStatus::classify($u5, 7)['state'] === 'stale', 'a device last seen 30 days ago classifies as not seen in 7+ days');
$entries = $log();
$tokenCalls = count(array_filter($entries, fn ($e) => $e['path'] === '/oauth/v2/token'));
$ok($tokenCalls === 1, 'one token refresh for the whole run');
$n1 = count($entries);
$run2 = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$ok($run2['ok'] && count(array_filter($log(), fn ($e) => $e['path'] === '/oauth/v2/token')) === 1, 'the next run (cron) reuses the stored access token: still ONE token request in total');
$ok($run2['stats']['matched'] === 0 && $run2['stats']['updated'] === 4 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 4, 'second run: 4 updated, nothing new');

// details/summary calls per device; enrich_details off = list only
$detailCalls = count(array_filter($log(), fn ($e) => preg_match('#/api/v1/mdm/devices/\d+$#', $e['path'])));
$summaryCalls = count(array_filter($log(), fn ($e) => preg_match('#/summary$#', $e['path'])));
$ok($detailCalls === 12 && $summaryCalls === 12, "two runs x 6 devices: $detailCalls detail and $summaryCalls summary calls");
$reset(); mdm_seed_mock($mock, 6); mdm_clean(); $mkAsset('Fleet iPad 1', 'DMPX0000001');
mdm_save_row(['enrich_details' => 0]);
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$listOnly = array_filter($log(), fn ($e) => str_starts_with($e['path'], '/api/v1/mdm/devices/'));
$ok($run['ok'] && count($listOnly) === 0 && $one('SELECT battery_level FROM asset_mdm_links LIMIT 1') === null && $one('SELECT device_name FROM asset_mdm_links LIMIT 1') === 'iPad 1', 'enrich_details = 0: only the list endpoint is called; list fields (name, lost mode, user) are still synced');

// missing device-management scope: run succeeds, warns once, keeps going
$reset(); mdm_seed_mock($mock, 4); mdm_clean(); $mkAsset('Fleet iPad 1', 'DMPX0000001');
mdm_save_row();
$mock(['summary_forbidden' => true]);
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$summaryTried = count(array_filter($log(), fn ($e) => preg_match('#/summary$#', $e['path'])));
$ok($run['ok'] && $summaryTried === 1 && count($run['stats']['warnings']) === 1 && (int) $one('SELECT battery_level FROM asset_mdm_links LIMIT 1') === 77 && $one('SELECT last_contact_at FROM asset_mdm_links LIMIT 1') === null, 'a missing MDMDeviceMgmt scope: the run still succeeds, summary calls stop after the first 403, the warning is recorded');
$ok(str_contains((string) $one('SELECT errors FROM mdm_sync_log ORDER BY id DESC LIMIT 1'), 'warning:') && $one('SELECT status FROM mdm_sync_log ORDER BY id DESC LIMIT 1') === 'success', 'the warning is in the sync log text but the status stays success');

// failures are classified in the log
$reset(); mdm_seed_mock($mock, 2); mdm_clean();
mdm_save_row(['refresh' => 'revoked-refresh']);
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$lr = $db->query('SELECT * FROM mdm_sync_log ORDER BY id DESC LIMIT 1')->fetch_assoc();
$ok(!$run['ok'] && $run['error_code'] === 'auth_failed' && $lr['status'] === 'failed' && $lr['error_code'] === 'auth_failed' && strpos((string) $lr['errors'], 'revoked-refresh') === false && strpos((string) $lr['errors'], 'good-secret') === false, 'bad credentials: failed, error_code auth_failed, no secret in the log text');
mdm_save_row(); $mock(['fail_status' => 429]);
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts + ['max_retries' => 1]);
$ok(!$run['ok'] && $run['error_code'] === 'throttled' && $one('SELECT error_code FROM mdm_sync_log ORDER BY id DESC LIMIT 1') === 'throttled', 'a throttled API: failed with error_code throttled (device data from earlier runs is kept)');
$ok((int) $one('SELECT COUNT(*) FROM assets') >= 0 && $run['stats'] === null, 'a failed run reports no stats');

// lock: no overlapping runs
$reset(); mdm_seed_mock($mock, 2); mdm_clean(); mdm_save_row();
$q("INSERT INTO mdm_sync_log SET integration_id=" . (int) $one('SELECT id FROM manageengine_mdm_integrations LIMIT 1') . ", status='running', started_at=NOW()");
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$ok(!$run['ok'] && $run['locked'] === true && count($log()) === 0, 'a run already in progress blocks a second one and makes no request');
$q("UPDATE mdm_sync_log SET started_at = DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$ok($run['ok'] && !$run['locked'], 'a stale "running" row (crashed run) stops blocking after five minutes');

// not configured
$q('DELETE FROM manageengine_mdm_integrations');
$ok(MdmClientFactory::row($db) === null && !MdmClientFactory::isEnabled($db), 'no connection saved: no row, not enabled');
$iid = mdm_save_row(['secret' => '']);
$q("UPDATE manageengine_mdm_integrations SET client_secret_enc=''");
$run = MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$ok(!$run['ok'] && $run['stats'] === null && stripos($run['message'], 'client secret') !== false, 'a missing secret is refused before any request');

// disabled state and the cron schedule
$reset(); mdm_clean(); mdm_save_row(['enabled' => 0]);
$ok(!MdmSyncService::isDue($db, MdmClientFactory::row($db)) && !MdmClientFactory::isEnabled($db), 'disabled (the default): cron never runs it and the UI treats it as off');
mdm_save_row(['enabled' => 1, 'sync_interval_minutes' => 60]);
$ok(MdmSyncService::isDue($db, MdmClientFactory::row($db)) && MdmClientFactory::isEnabled($db), 'enabled and never run: due');
$iid = (int) $one('SELECT id FROM manageengine_mdm_integrations');
$q("INSERT INTO mdm_sync_log SET integration_id=$iid, status='success', triggered_by=0, started_at=DATE_SUB(NOW(), INTERVAL 20 MINUTE)");
$ok(!MdmSyncService::isDue($db, MdmClientFactory::row($db)), 'a scheduled run 20 minutes ago with a 60 minute interval: not due');
$q("UPDATE mdm_sync_log SET started_at=DATE_SUB(NOW(), INTERVAL 61 MINUTE)");
$ok(MdmSyncService::isDue($db, MdmClientFactory::row($db)), '61 minutes ago: due');
$q("UPDATE manageengine_mdm_integrations SET sync_interval_minutes=1");
$q("UPDATE mdm_sync_log SET started_at=DATE_SUB(NOW(), INTERVAL 3 MINUTE)");
$ok(!MdmSyncService::isDue($db, MdmClientFactory::row($db)), 'the interval floor is 5 minutes whatever is stored');
$q("UPDATE manageengine_mdm_integrations SET enabled=1, refresh_token_enc=''");
$ok(!MdmSyncService::isDue($db, MdmClientFactory::row($db)) && !MdmClientFactory::isEnabled($db), 'enabled without a refresh token: never due');

// =====================================================================================================================================
// secrets
$reset(); mdm_clean(); mdm_seed_mock($mock, 4, [1 => ['list' => ['serial_number' => 'DMPX0000001']]]); $mkAsset('Fleet iPad 1', 'DMPX0000001');
$iid = mdm_save_row();
MdmSyncService::run($db, MdmClientFactory::row($db), 0, 60, $opts);
$cols = $db->query('SELECT client_secret_enc, refresh_token_enc, token_cache_enc FROM manageengine_mdm_integrations')->fetch_assoc();
$ok($cols['client_secret_enc'] !== 'good-secret' && strpos($cols['client_secret_enc'], 'good-secret') === false && decryptSetting($cols['client_secret_enc']) === 'good-secret', 'the client secret is stored encrypted and decrypts back');
$ok($cols['refresh_token_enc'] !== 'good-refresh' && strpos($cols['refresh_token_enc'], 'good-refresh') === false && decryptSetting($cols['refresh_token_enc']) === 'good-refresh', 'the refresh token is stored encrypted and decrypts back');
$ok(!empty($cols['token_cache_enc']) && strpos($cols['token_cache_enc'], 'at-') === false, 'the cached access token is stored encrypted');
$dump = '';
foreach (['asset_mdm_links', 'mdm_unmatched_devices', 'mdm_sync_log', 'logs', 'audit_events', 'assets'] as $t) { $dump .= json_encode($rows("SELECT * FROM `$t`")); }
foreach (['good-secret', 'good-refresh', 'APN-SECRET-VALUE'] as $secret) {
    $ok(strpos($dump, $secret) === false, "'$secret' appears in no link, review, sync-log, audit or asset row (the device apn_password is never stored)");
}
$dump2 = json_encode($rows("SELECT * FROM asset_mdm_links")) . json_encode($rows("SELECT * FROM mdm_sync_log"));
$ok(!preg_match('/"at-\d+"|Zoho-oauthtoken/', $dump2), 'no access token or Authorization header in the stored rows');
$ok(strpos(file_get_contents("$root/src/Crypto/ColumnRegistry.php"), "['manageengine_mdm_integrations', 'id', ['client_secret_enc', 'refresh_token_enc', 'token_cache_enc']]") !== false, 'the secret columns are registered for key rotation (ColumnRegistry)');

// =====================================================================================================================================
// migration 2.6.168 and db.sql
require_once "$root/includes/database_version.php";
$ok(version_compare(LATEST_DATABASE_VERSION, '2.6.168', '>='), 'LATEST_DATABASE_VERSION is at least 2.6.168 (' . LATEST_DATABASE_VERSION . ')');
$tables = ['manageengine_mdm_integrations', 'asset_mdm_links', 'mdm_unmatched_devices', 'mdm_sync_log'];
$sql = file_get_contents("$root/db.sql");
$upd = file_get_contents("$root/admin/database_updates.php");
foreach ($tables as $t) {
    $coll = (string) $one("SELECT table_collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$t'");
    $ok($coll === 'utf8mb4_general_ci', "table $t exists and uses utf8mb4_general_ci: $coll");
    $ok(preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\) ENGINE/s', $sql, $m) === 1, "db.sql defines $t");
    preg_match_all('/^  `([a-z_0-9]+)` /m', $m[1] ?? '', $cols2);
    $live = array_column($rows("SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='$t' ORDER BY ordinal_position"), 'column_name');
    $ok($cols2[1] === $live, "db.sql and the installed schema agree on the columns of $t");
    // the migration block holds the same DDL as db.sql, statement for statement
    preg_match('/CREATE TABLE IF NOT EXISTS `' . $t . '` \((.*?)\n\s*\) ENGINE/s', $upd, $mm);
    preg_match_all('/^\s*`([a-z_0-9]+)` /m', $mm[1] ?? '', $cols3);
    $ok($cols3[1] === $cols2[1], "the migration and db.sql define the same columns for $t");
}
$ok(strpos($sql, 'uca1400') === false, 'db.sql carries no uca1400 collation');
$blockStart = strpos($upd, "if (\$rivetit_db_version() == '2.6.167') {");
$blockEnd = strpos($upd, "if (\$rivetit_db_version() == '2.6.168') {");
$block = substr($upd, $blockStart, $blockEnd !== false ? $blockEnd - $blockStart : null);
$ok($blockStart !== false && strpos($block, "'2.6.168'") !== false, 'the migration is gated 2.6.167 -> 2.6.168');
$ok(substr_count($block, 'CREATE TABLE IF NOT EXISTS') === 4 && stripos($block, 'ALTER TABLE') === false && stripos($block, 'DROP ') === false, 'the migration only adds four tables (IF NOT EXISTS): no ALTER, no DROP, the nearly full settings table is not touched');
$ok(stripos($block, 'INSERT INTO') === false, 'the migration inserts no row: the integration is off until an administrator saves a connection');
$ok((int) $one('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=\'manageengine_mdm_integrations\' AND column_name=\'enabled\' AND column_default=\'0\'') === 1, 'enabled defaults to 0 (off by default)');
// replay over live data: the update runner is idempotent and keeps rows
$q('DELETE FROM manageengine_mdm_integrations'); mdm_clean();
$iid = mdm_save_row(['client_id' => '1000.KEEPME']);
$a = $mkAsset('Replay asset', 'DMPX0000099'); $mapper = new MdmDeviceMapper($db, $iid); $mapper->syncDevices([$dev(99)]);
$prev = $one("SELECT config_current_database_version FROM settings WHERE company_id=1");
for ($run = 1; $run <= 2; $run++) {
    $q("UPDATE settings SET config_current_database_version='2.6.167' WHERE company_id=1");
    $out = shell_exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' scripts/update_cli.php --update_db 2>&1');
    $ok((string) $one("SELECT config_current_database_version FROM settings WHERE company_id=1") === LATEST_DATABASE_VERSION, "update run $run lands on the latest version");
    $ok($one('SELECT client_id FROM manageengine_mdm_integrations') === '1000.KEEPME' && (int) $one('SELECT COUNT(*) FROM manageengine_mdm_integrations') === 1 && (int) $one('SELECT COUNT(*) FROM asset_mdm_links') === 1, "run $run keeps the saved connection and the links");
}
