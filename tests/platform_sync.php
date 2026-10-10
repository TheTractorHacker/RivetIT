<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }   // never run tests over HTTP (pentest F-02)
/*
 * Inventory sync into the asset's own fields (gap analysis items 15 and 18), on a SCRATCH database with the real code:
 *   - AssetInventorySync: write when blank or still the last synced value, never over a human edit (the decision table, the state, the fingerprint skip);
 *   - the built-in agent (real enrollment and check-ins over HTTP), the vendor RMM mapper and the Intune mapper;
 *   - the client mapping table and its needs-mapping queue (ClientMap + RmmAssetMapper::resolveClientId);
 *   - auto-retire of stale RMM-linked assets (off by default, review queue, restore);
 *   - Intune primary user -> the asset's contact (exactly one match in the department).
 *   RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=x_scratch RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... php tests/platform_sync.php
 */
require __DIR__ . '/endpoint_agent_lib.php';
require_once "$root/includes/class_rmm_asset_mapper.php";
use ITFlow\Assets\{AssetInventorySync, StaleAssets};
use ITFlow\Integrations\ClientMap;
use ITFlow\Integrations\Microsoft\IntuneAssetMapper;
use ITFlow\Platform\PlatformSettings;

ea_reset();
foreach (['asset_sync_state', 'integration_client_map', 'asset_retire_queue', 'asset_intune_links', 'contacts', 'asset_assignments', 'platform_settings', 'notifications'] as $t) { $q("DELETE FROM $t"); }
$q("DELETE FROM rmm_integrations WHERE type <> 'rivetit_agent'");
$sync = new AssetInventorySync($db);
$asset = fn (int $id) => $rows("SELECT * FROM assets WHERE asset_id = $id")[0];
$mk = function (string $name, int $client = 1, array $over = []) use ($q, $db): int {
    $set = array_merge(['asset_type' => 'Laptop', 'asset_name' => $name, 'asset_make' => '', 'asset_client_id' => $client, 'asset_status' => 'Active'], $over);
    $sql = implode(', ', array_map(fn ($k, $v) => "$k = '" . $db->real_escape_string((string) $v) . "'", array_keys($set), $set));
    $q("INSERT INTO assets SET $sql");
    return (int) $db->insert_id;
};

// ---------------------------------------------------------------- the decision table (pure)
$ok(AssetInventorySync::decide('', null, 'Latitude') === 'write', 'blank field -> write');
$ok(AssetInventorySync::decide('Latitude', null, 'Latitude') === 'record', 'already the reported value -> only remember it');
$ok(AssetInventorySync::decide('Latitude', 'Latitude', 'Precision') === 'write', 'still the last synced value -> write the new report');
$ok(AssetInventorySync::decide('My laptop', 'Latitude', 'Precision') === 'keep', 'a human edit (differs from the last synced value) -> keep');
$ok(AssetInventorySync::decide('My laptop', null, 'Precision') === 'keep', 'a value of unknown origin is treated as human -> keep');
$ok(AssetInventorySync::decide('Latitude', 'Latitude', '') === 'keep', 'the source reporting nothing never blanks a field');
$ok(AssetInventorySync::decide('  Latitude ', 'Latitude', 'Precision') === 'write', 'whitespace around the stored value does not count as an edit');
$ok(AssetInventorySync::ramLabel('16') === '16 GB' && AssetInventorySync::ramLabel('15.8') === '15.8 GB' && AssetInventorySync::ramLabel('16.0') === '16 GB' && AssetInventorySync::ramLabel('16 GB') === '16 GB' && AssetInventorySync::ramLabel('') === '', 'ram label');
$ok(AssetInventorySync::osLabel('Windows', 'Windows 11 23H2') === 'Windows 11 23H2' && AssetInventorySync::osLabel('Windows', '10.0.22631') === 'Windows 10.0.22631' && AssetInventorySync::osLabel('', '14.2') === '14.2', 'os label does not repeat the name');
$nic = AssetInventorySync::primaryNic([['name' => 'vEthernet (WSL)', 'mac' => 'AA-00', 'ips' => ['172.20.0.1']], ['name' => 'Bluetooth', 'ips' => ['169.254.1.1']], ['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['fe80::1', '10.0.0.5']]]);
$ok($nic === ['nic_ip' => '10.0.0.5', 'nic_mac' => 'aa:bb:cc:dd:ee:01'], 'the primary adapter skips virtual, link-local and IPv6-only adapters');

// ---------------------------------------------------------------- apply(): fields, state, no-overwrite
$a = $mk('ws-1');
$r = $sync->apply($a, ['make' => 'Dell', 'model' => 'Latitude 7440', 'os' => 'Windows 11 23H2', 'cpu' => 'Intel i7-1355U', 'ram' => '16 GB', 'nic_ip' => '10.0.0.5', 'nic_mac' => 'AA-BB-CC-DD-EE-01'], 'test');
$row = $asset($a);
$ok($row['asset_make'] === 'Dell' && $row['asset_model'] === 'Latitude 7440' && $row['asset_os'] === 'Windows 11 23H2' && $row['asset_cpu'] === 'Intel i7-1355U' && $row['asset_ram'] === '16 GB', 'a new asset gets model, CPU, RAM and OS written');
$iface = $rows("SELECT * FROM asset_interfaces WHERE interface_asset_id = $a")[0] ?? null;
$ok($iface && $iface['interface_primary'] == 1 && $iface['interface_ip'] === '10.0.0.5' && $iface['interface_mac'] === 'aa:bb:cc:dd:ee:01', 'the primary adapter is created with IP and MAC');
$st = $sync->loadState($a);
$ok($st['model'] === 'Latitude 7440' && $st['cpu'] === 'Intel i7-1355U' && $st['nic_ip'] === '10.0.0.5' && !empty($st['_fp']), 'the state remembers what was written');
$r = $sync->apply($a, ['make' => 'Dell', 'model' => 'Latitude 7440', 'os' => 'Windows 11 23H2', 'cpu' => 'Intel i7-1355U', 'ram' => '16 GB', 'nic_ip' => '10.0.0.5', 'nic_mac' => 'AA-BB-CC-DD-EE-01'], 'test');
$ok($r['skipped'] === true, 'an identical report is skipped (one read)');
$q("UPDATE assets SET asset_updated_at = NULL WHERE asset_id = $a");
$sync->apply($a, ['make' => 'Dell', 'model' => 'Latitude 7440', 'os' => 'Windows 11 23H2', 'cpu' => 'Intel i7-1365U', 'ram' => '32 GB', 'nic_ip' => '10.0.0.9'], 'test');
$row = $asset($a);
$ok($row['asset_cpu'] === 'Intel i7-1365U' && $row['asset_ram'] === '32 GB', 'a hardware change flows through while the field still holds the last synced value');
$ok($rows("SELECT interface_ip FROM asset_interfaces WHERE interface_asset_id = $a AND interface_primary = 1")[0]['interface_ip'] === '10.0.0.9', '... and so does the primary adapter\'s IP');
// a human edits two fields
$q("UPDATE assets SET asset_model = 'Blake''s laptop', asset_ram = '64 GB (upgraded)' WHERE asset_id = $a");
$q("UPDATE asset_interfaces SET interface_ip = '192.168.1.77' WHERE interface_asset_id = $a AND interface_primary = 1");
$r = $sync->apply($a, ['make' => 'Dell', 'model' => 'Precision 5680', 'os' => 'Windows 11 24H2', 'cpu' => 'Intel i9', 'ram' => '32 GB', 'nic_ip' => '10.0.0.10'], 'test');
$row = $asset($a);
$ok($row['asset_model'] === "Blake's laptop" && $row['asset_ram'] === '64 GB (upgraded)', 'human-edited model and RAM are never overwritten');
$ok($rows("SELECT interface_ip FROM asset_interfaces WHERE interface_asset_id = $a AND interface_primary = 1")[0]['interface_ip'] === '192.168.1.77', 'a human-edited IP is never overwritten');
$ok($row['asset_os'] === 'Windows 11 24H2' && $row['asset_cpu'] === 'Intel i9', 'untouched fields keep following the source');
$ok(in_array('model', $r['kept'], true) && in_array('ram', $r['kept'], true) && in_array('nic_ip', $r['kept'], true), 'the result lists what was kept');
$sync->apply($a, ['model' => 'Precision 5680', 'ram' => '32 GB', 'cpu' => 'Intel i9 (2)'], 'test');
$ok($asset($a)['asset_model'] === "Blake's laptop" && $asset($a)['asset_cpu'] === 'Intel i9 (2)', 'still kept on the next report');
$sync->apply($a, ['model' => ''], 'test');
$ok($asset($a)['asset_model'] === "Blake's laptop", 'an empty report changes nothing');
// the human value later equals the source value: it is remembered, then follows again
$q("UPDATE assets SET asset_model = 'Latitude Z' WHERE asset_id = $a");
$sync->apply($a, ['model' => 'Latitude Z'], 'test');
$sync->apply($a, ['model' => 'Latitude Y'], 'test');
$ok($asset($a)['asset_model'] === 'Latitude Y', 'a value that matched the source is treated as synced again');
$ok($sync->apply(99999999, ['model' => 'x'], 'test')['skipped'] === true, 'an unknown asset is skipped');
// HTML in a reported value
$sync->apply($a, ['os' => '<b>Evil</b> OS<script>x</script>'], 'test');
$ok(!str_contains($asset($a)['asset_os'], '<'), 'markup in a reported value is stripped');
// promotion of an existing adapter with the same MAC
$b = $mk('ws-2');
$q("INSERT INTO asset_interfaces SET interface_asset_id = $b, interface_name = 'Intel NIC', interface_mac = 'aa:bb:cc:00:00:02', interface_primary = 0");
$sync->apply($b, ['nic_ip' => '10.1.1.2', 'nic_mac' => 'AA-BB-CC-00-00-02'], 'test');
$ifs = $rows("SELECT * FROM asset_interfaces WHERE interface_asset_id = $b");
$ok(count($ifs) === 1 && $ifs[0]['interface_primary'] == 1 && $ifs[0]['interface_ip'] === '10.1.1.2', 'an existing adapter with the same MAC becomes the primary (no duplicate)');
// a human-created primary adapter keeps its values
$c = $mk('ws-3');
$q("INSERT INTO asset_interfaces SET interface_asset_id = $c, interface_name = '01', interface_ip = '10.9.9.9', interface_mac = '00:11:22:33:44:55', interface_primary = 1");
$sync->apply($c, ['nic_ip' => '10.2.2.2', 'nic_mac' => '66:77:88:99:aa:bb'], 'test');
$ok($rows("SELECT interface_ip, interface_mac FROM asset_interfaces WHERE interface_asset_id = $c")[0] === ['interface_ip' => '10.9.9.9', 'interface_mac' => '00:11:22:33:44:55'], 'a typed primary adapter is left alone');

// ---------------------------------------------------------------- the built-in agent over HTTP
$tok = ea_token(1, 24, 100);
$d = $mk('Front desk', 1, ['asset_make' => 'Dell', 'asset_serial' => 'PS-SER-1']);
$dev1 = ea_dev(['serial' => 'PS-SER-1', 'hostname' => 'FRONTDESK', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440']);
[$c, , $j] = ea_enroll($tok, $dev1);
$T = $j['device_token'];
$ok(($j['status'] ?? '') === 'linked', 'agent: enrolled and linked to the existing asset');
$inv = fn (array $over = []) => array_merge(['hostname' => 'FRONTDESK', 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => 'PS-SER-1',
    'cpu' => ['model' => 'Intel i7-1355U', 'cores' => 10], 'memory_total_bytes' => 17179869184, 'disks' => [], 'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5']]], 'uptime_s' => 100, 'logged_in_user' => 'u', 'pending_reboot' => false], $over);
$seq = 1;
$checkin = function (array $inventory) use (&$seq, $T) { [$c, , $r] = http('POST', '/api/v1/agent_checkin', $T, ['seq' => $seq++, 'collected_at' => ea_ts(-5), 'agent_version' => '1.0.0', 'inventory' => $inventory, 'metrics' => ['cpu_pct' => 5, 'mem_pct' => 5, 'disk' => [], 'net_rx_bps' => null, 'net_tx_bps' => null], 'checks' => [], 'buffered' => []]); return $c; };
$ok($checkin($inv()) === 200, 'agent: check-in with inventory');
$row = $asset($d);
$ok($row['asset_model'] === 'Latitude 7440' && $row['asset_cpu'] === 'Intel i7-1355U' && $row['asset_ram'] === '16 GB' && $row['asset_os'] === 'Windows 11 23H2', 'agent: the asset itself gets model, CPU, RAM and OS (OS without a repeated "Windows")');
$pn = $rows("SELECT * FROM asset_interfaces WHERE interface_asset_id = $d AND interface_primary = 1")[0] ?? null;
$ok($pn && $pn['interface_ip'] === '10.0.0.5' && $pn['interface_mac'] === 'aa:bb:cc:dd:ee:01', 'agent: the primary adapter has the IP and MAC');
$q("UPDATE assets SET asset_cpu = 'custom cpu note' WHERE asset_id = $d");
$ok($checkin($inv(['cpu' => ['model' => 'Intel i9', 'cores' => 16], 'memory_total_bytes' => 34359738368, 'os_version' => 'Windows 11 24H2'])) === 200, 'agent: next check-in with new hardware');
$row = $asset($d);
$ok($row['asset_cpu'] === 'custom cpu note', 'agent: a human-edited CPU field survives the next check-in');
$ok($row['asset_ram'] === '32 GB' && $row['asset_os'] === 'Windows 11 24H2', 'agent: RAM and OS follow the new report');
$ok($checkin($inv(['cpu' => ['model' => 'Intel i9', 'cores' => 16], 'memory_total_bytes' => 34359738368, 'os_version' => 'Windows 11 24H2'])) === 200 && $asset($d)['asset_cpu'] === 'custom cpu note', 'agent: an unchanged report changes nothing');
// a freshly created asset (no match) records its starting values, so the agent may refresh them
$tok2 = ea_token(1, 24, 100);
$dev2 = ea_dev(['serial' => 'PS-NEW-1', 'hostname' => 'NEWBOX', 'manufacturer' => 'HP', 'model' => 'ProBook']);
[$c, , $j2] = ea_enroll($tok2, $dev2);
$newAsset = (int) ($j2['matched_asset_id'] ?? 0) ?: (int) $one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id = " . (int) $j2['device_id']);
if ($newAsset === 0) { $newAsset = (int) $one("SELECT asset_id FROM assets WHERE asset_name = 'NEWBOX'"); }
$ok($newAsset > 0 || ($j2['status'] ?? '') === 'pending_approval', 'agent: an unmatched device is enrolled (' . ($j2['status'] ?? '?') . ')');

// ---------------------------------------------------------------- vendor RMM mapper
$q("INSERT INTO rmm_integrations SET name = 'Tactical', type = 'tactical_rmm', api_url = 'https://rmm.example.test', api_key_enc = 'x', enabled = 1");
$intg = (int) $db->insert_id;
$mapper = new RmmAssetMapper($db, $intg, 0, null);
$agent = fn (array $o = []) => array_merge(['agent_id' => 'ag-1', 'hostname' => 'RMM-PC-1', 'serial_number' => 'RMM-SER-1', 'operating_system' => 'Windows 10 Pro', 'os_version' => '19045', 'manufacturer' => 'Lenovo', 'model' => 'ThinkPad T14',
    'cpu' => 'AMD Ryzen 5', 'ram' => '16', 'status' => 'online', 'client_name' => 'Dept A', 'wmi_detail' => ['network_config' => [[['Description' => 'Intel Ethernet', 'MACAddress' => 'AA-BB-CC-00-11-22', 'IPAddress' => ['192.168.5.20', 'fe80::1']]]]]], $o);
$st = $mapper->syncAgents([$agent()]);
$ok($st['created'] === 1 && $st['skipped'] === 0, 'rmm: a device of a known department creates an asset');
$ra = (int) $one("SELECT asset_id FROM assets WHERE asset_serial = 'RMM-SER-1'");
$row = $asset($ra);
$ok($row['asset_model'] === 'ThinkPad T14' && $row['asset_cpu'] === 'AMD Ryzen 5' && $row['asset_ram'] === '16 GB' && $row['asset_make'] === 'Lenovo' && (int) $row['asset_client_id'] === 1, 'rmm: model, CPU, RAM and make written on create');
$pn = $rows("SELECT * FROM asset_interfaces WHERE interface_asset_id = $ra AND interface_primary = 1")[0] ?? null;
$ok($pn && $pn['interface_ip'] === '192.168.5.20' && $pn['interface_mac'] === 'aa:bb:cc:00:11:22', 'rmm: the primary adapter (IPv4 of the first real adapter) is set; no second copy of that adapter');
$ok((int) $one("SELECT COUNT(*) FROM asset_interfaces WHERE interface_asset_id = $ra") === 1, 'rmm: the adapter list holds one row for it (the older per-adapter sync promoted, not duplicated)');
$q("UPDATE assets SET asset_model = 'Renamed by Blake' WHERE asset_id = $ra");
$st = $mapper->syncAgents([$agent(['cpu' => 'AMD Ryzen 7', 'ram' => '32', 'model' => 'ThinkPad T14 Gen 2'])]);
$row = $asset($ra);
$ok($st['updated'] === 1 && $row['asset_cpu'] === 'AMD Ryzen 7' && $row['asset_ram'] === '32 GB', 'rmm: a later sync refreshes CPU and RAM');
$ok($row['asset_model'] === 'Renamed by Blake', 'rmm: a human-edited model is not overwritten');

// ---------------------------------------------------------------- client mapping queue
$rows0 = $rows("SELECT * FROM integration_client_map");
$ok($rows0 === [], 'map: an exact department name needs no mapping row');
$st = $mapper->syncAgents([$agent(['agent_id' => 'ag-2', 'hostname' => 'ACME-PC-1', 'serial_number' => 'ACME-1', 'client_name' => 'Acme Holdings']), $agent(['agent_id' => 'ag-3', 'hostname' => 'ACME-PC-2', 'serial_number' => 'ACME-2', 'client_name' => 'acme holdings'])]);
$ok($st['created'] === 0 && $st['skipped'] === 2, 'map: devices of an unknown client name are skipped...');
$qrow = $rows("SELECT * FROM integration_client_map")[0] ?? null;
$ok($qrow && $qrow['map_status'] === 'pending' && $qrow['external_name'] === 'Acme Holdings' && (int) $qrow['seen_count'] >= 2 && $qrow['sample_host'] === 'ACME-PC-1' && (int) $qrow['integration_id'] === $intg, '... and queued once (case-insensitive), with a count and an example device');
$ok((int) $one("SELECT COUNT(*) FROM integration_client_map") === 1, 'map: one queue entry however many devices share the name');
$cm = new ClientMap($db);
$ok($cm->pendingCount() === 1 && count($cm->rows('pending')) === 1, 'map: the pending count and list');
$ok($cm->assign((int) $qrow['map_id'], 99999)['ok'] === false, 'map: a department that does not exist is refused');
$q("INSERT INTO clients SET client_id = 3, client_name = 'Acme Corporation'");
$ok($cm->assign((int) $qrow['map_id'], 3)['ok'] === true, 'map: an administrator maps the name to a department');
$mapper2 = new RmmAssetMapper($db, $intg, 0, null);
$st = $mapper2->syncAgents([$agent(['agent_id' => 'ag-2', 'hostname' => 'ACME-PC-1', 'serial_number' => 'ACME-1', 'client_name' => 'Acme Holdings'])]);
$ok($st['created'] === 1 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_serial = 'ACME-1'") === 3, 'map: the next sync uses the saved mapping before any name match');
// the saved mapping wins over an exact name
$q("INSERT INTO integration_client_map SET integration_id = $intg, external_name = 'Dept A', client_id = 2, map_status = 'mapped'");
$st = (new RmmAssetMapper($db, $intg, 0, null))->syncAgents([$agent(['agent_id' => 'ag-4', 'hostname' => 'DEPTA-PC', 'serial_number' => 'MAPWIN-1', 'client_name' => 'Dept A'])]);
$ok((int) $one("SELECT asset_client_id FROM assets WHERE asset_serial = 'MAPWIN-1'") === 2, 'map: a saved mapping is used before the exact-name fallback');
// another integration is not affected by this one's mapping
$q("INSERT INTO rmm_integrations SET name = 'Level', type = 'level', api_url = 'https://level.example.test', api_key_enc = 'x', enabled = 1");
$intg2 = (int) $db->insert_id;
$st = (new RmmAssetMapper($db, $intg2, 0, null))->syncAgents([$agent(['agent_id' => 'lv-1', 'hostname' => 'LV-PC', 'serial_number' => 'LV-1', 'client_name' => 'Acme Holdings'])]);
$ok($st['skipped'] === 1 && (int) $one("SELECT COUNT(*) FROM integration_client_map WHERE integration_id = $intg2 AND map_status = 'pending'") === 1, 'map: a mapping belongs to one integration; the other one queues its own');
$lv = (int) $one("SELECT map_id FROM integration_client_map WHERE integration_id = $intg2");
$ok($cm->ignore($lv)['ok'] === true, 'map: a name can be ignored');
$st = (new RmmAssetMapper($db, $intg2, 0, null))->syncAgents([$agent(['agent_id' => 'lv-1', 'hostname' => 'LV-PC', 'serial_number' => 'LV-1', 'client_name' => 'Acme Holdings'])]);
$ok($st['skipped'] === 1 && $cm->pendingCount() === 0 && (int) $one("SELECT COUNT(*) FROM assets WHERE asset_serial = 'LV-1'") === 0, 'map: an ignored name is skipped on purpose and is not queued again');
$cm->reopen($lv);
$ok($cm->pendingCount() === 1, 'map: "ask again" puts it back');
$q("UPDATE clients SET client_archived_at = NOW() WHERE client_id = 3");
(new RmmAssetMapper($db, $intg, 0, null))->syncAgents([$agent(['agent_id' => 'ag-5', 'hostname' => 'ACME-PC-3', 'serial_number' => 'ACME-3', 'client_name' => 'Acme Holdings'])]);
$ok($one("SELECT map_status FROM integration_client_map WHERE integration_id = $intg AND external_name = 'Acme Holdings'") === 'pending', 'map: a mapping to an archived department goes back to the queue instead of dropping devices silently');
$q("UPDATE clients SET client_archived_at = NULL WHERE client_id = 3");
// devices with no client name use the integration default as before
$q("UPDATE rmm_integrations SET default_client_id = 1 WHERE id = $intg");
$st = (new RmmAssetMapper($db, $intg, 0, null))->syncAgents([$agent(['agent_id' => 'ag-6', 'hostname' => 'NOCLIENT', 'serial_number' => 'NC-1', 'client_name' => ''])]);
$ok($st['created'] === 1 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_serial = 'NC-1'") === 1, 'map: no client name -> the integration default department, as before');

// ---------------------------------------------------------------- stale asset auto-retire
$q("DELETE FROM asset_rmm_links"); $q("DELETE FROM asset_retire_queue");
$s1 = $mk('stale-1'); $s2 = $mk('fresh-1'); $s3 = $mk('never-linked');
$q("INSERT INTO asset_rmm_links SET asset_id = $s1, integration_id = $intg, tactical_agent_id = 's1', last_seen = NOW() - INTERVAL 120 DAY, rmm_status = 'offline'");
$q("INSERT INTO asset_rmm_links SET asset_id = $s2, integration_id = $intg, tactical_agent_id = 's2', last_seen = NOW() - INTERVAL 5 DAY, rmm_status = 'offline'");
$stale = new StaleAssets($db);
$ok(PlatformSettings::get($db, 'stale_asset_retire_enabled') === '0', 'retire: off by default');
$ok($stale->run() === ['enabled' => false, 'retired' => 0, 'restored' => 0] && $asset($s1)['asset_archived_at'] === null, 'retire: nothing happens while it is off');
$ok(array_column($stale->candidates(90), 'asset_id') === [$s1], 'retire: the candidates are the linked assets silent for N days (not fresh ones, not unlinked ones)');
PlatformSettings::set($db, 'stale_asset_retire_enabled', '1'); PlatformSettings::set($db, 'stale_asset_retire_days', '90');
$res = $stale->run();
$ok($res['retired'] === 1 && $asset($s1)['asset_archived_at'] !== null && $asset($s1)['asset_status'] === 'Retired' && $asset($s2)['asset_archived_at'] === null && $asset($s3)['asset_archived_at'] === null, 'retire: the stale asset is retired (archived, status Retired); others untouched');
$qr = $rows("SELECT * FROM asset_retire_queue")[0] ?? null;
$ok($qr && (int) $qr['asset_id'] === $s1 && $qr['queue_status'] === 'pending' && $qr['prev_status'] === 'Active' && (int) $qr['stale_days'] === 90, 'retire: it is in the review queue with its previous status');
$ok($stale->run()['retired'] === 0 && (int) $one("SELECT COUNT(*) FROM asset_retire_queue") === 1, 'retire: a second run does not queue it again');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'asset.auto_retire'") === 1, 'retire: an audit event is written');
$ok($stale->restore((int) $qr['queue_id'], 1) && $asset($s1)['asset_archived_at'] === null && $asset($s1)['asset_status'] === 'Active', 'retire: Restore brings it back with its old status');
$ok($stale->restore((int) $qr['queue_id'], 1) === false, 'retire: a handled entry cannot be handled twice');
$q("UPDATE asset_rmm_links SET last_seen = NOW() - INTERVAL 200 DAY WHERE asset_id = $s1");
$stale->run();
$q2 = (int) $one("SELECT queue_id FROM asset_retire_queue WHERE queue_status = 'pending'");
$ok($stale->confirm($q2, 1) && $asset($s1)['asset_archived_at'] !== null && $one("SELECT queue_status FROM asset_retire_queue WHERE queue_id = $q2") === 'confirmed', 'retire: Confirm closes the entry and the asset stays retired');
$q("UPDATE assets SET asset_archived_at = NULL, asset_status = 'Active' WHERE asset_id = $s1"); $q("DELETE FROM asset_retire_queue");
$stale->run();
$q("UPDATE asset_rmm_links SET last_seen = NOW() WHERE asset_id = $s1");
$res = $stale->run();
$ok($res['restored'] === 1 && $asset($s1)['asset_archived_at'] === null, 'retire: a device that reports in again is restored by the next run');
PlatformSettings::set($db, 'stale_asset_retire_days', '90');

// ---------------------------------------------------------------- Intune primary user -> contact
$q("INSERT INTO contacts SET contact_id = 501, contact_name = 'Alex Doe', contact_email = 'alex.doe@acme.test', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 502, contact_name = 'Sam Roe', contact_email = 'sam.roe@acme.test', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 503, contact_name = 'Pat Dup 1', contact_email = 'pat@acme.test', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 504, contact_name = 'Pat Dup 2', contact_email = 'PAT@acme.test', contact_client_id = 1");
$q("INSERT INTO contacts SET contact_id = 505, contact_name = 'Other dept Alex', contact_email = 'alex.doe@acme.test', contact_client_id = 2");
$q("INSERT INTO contacts SET contact_id = 506, contact_name = 'Gone Gus', contact_email = 'gus@acme.test', contact_client_id = 1, contact_archived_at = NOW()");
$i1 = $mk('intune-1'); $i2 = $mk('intune-2'); $i3 = $mk('intune-3'); $i4 = $mk('intune-4'); $i5 = $mk('intune-5'); $i6 = $mk('intune-nodept', 0);
$ok($sync->applyPrimaryUser($i1, 'Alex.Doe@Acme.test') === 'assigned' && (int) $asset($i1)['asset_contact_id'] === 501, 'intune: exactly one contact in the department matches the UPN (case-insensitive) -> assigned');
$ok((int) $one("SELECT COUNT(*) FROM asset_assignments WHERE asset_id = $i1 AND contact_id = 501 AND returned_at IS NULL") === 1, 'intune: the assignment history records it');
$ok($sync->applyPrimaryUser($i1, 'alex.doe@acme.test') === 'unchanged', 'intune: the same user again changes nothing');
$ok($sync->applyPrimaryUser($i1, 'sam.roe@acme.test') === 'assigned' && (int) $asset($i1)['asset_contact_id'] === 502, 'intune: while the contact is still the one set here, a new primary user moves it');
$q("UPDATE assets SET asset_contact_id = 503 WHERE asset_id = $i1");
$ok($sync->applyPrimaryUser($i1, 'alex.doe@acme.test') === 'kept_human' && (int) $asset($i1)['asset_contact_id'] === 503, 'intune: a contact a person chose is never replaced');
$ok($sync->applyPrimaryUser($i2, 'pat@acme.test') === 'ambiguous' && (int) $asset($i2)['asset_contact_id'] === 0, 'intune: two contacts with that address -> nothing assigned');
$ok($sync->applyPrimaryUser($i3, 'nobody@acme.test') === 'no_match' && $sync->applyPrimaryUser($i3, 'gus@acme.test') === 'no_match', 'intune: no match, or only an archived contact -> nothing assigned');
$ok($sync->applyPrimaryUser($i4, 'alex.doe@acme.test') === 'assigned' && (int) $asset($i4)['asset_contact_id'] === 501, 'intune: the match is looked up in the asset\'s own department (not the other Alex)');
$ok($sync->applyPrimaryUser($i6, 'alex.doe@acme.test') === 'no_department' && (int) $asset($i6)['asset_contact_id'] === 0, 'intune: an asset with no department is not matched');
$ok($sync->applyPrimaryUser($i5, '') === 'no_upn' && $sync->applyPrimaryUser($i5, 'not-an-address') === 'no_upn', 'intune: an empty or malformed UPN is ignored');
$q("INSERT INTO microsoft_integrations SET microsoft_integration_id = 1, microsoft_integration_name = 'Entra', microsoft_integration_enabled = 1") ;
$intune = new IntuneAssetMapper($db, 1, 0);
$dev = ['id' => 'intune-dev-1', 'deviceName' => 'INTUNE-PC-1', 'serialNumber' => 'IN-SER-1', 'operatingSystem' => 'Windows', 'osVersion' => '10.0.22631.4037', 'manufacturer' => 'Microsoft', 'model' => 'Surface Laptop 5', 'userPrincipalName' => 'sam.roe@acme.test', 'complianceState' => 'compliant'];
$q("INSERT INTO assets SET asset_type = 'Laptop', asset_name = 'INTUNE-PC-1', asset_serial = 'IN-SER-1', asset_make = '', asset_client_id = 1, asset_status = 'Active'");
$ia = (int) $db->insert_id;
$st = $intune->syncDevices([$dev]);
$row = $asset($ia);
$ok($st['matched'] === 1 && $row['asset_model'] === 'Surface Laptop 5' && $row['asset_make'] === 'Microsoft' && $row['asset_os'] === 'Windows 10.0.22631.4037' && (int) $row['asset_contact_id'] === 502, 'intune: a synced device gets make, model, OS and its primary user as contact');
$dev['userPrincipalName'] = 'alex.doe@acme.test';
$st = $intune->syncDevices([$dev]);
$ok($st['updated'] === 1 && (int) $asset($ia)['asset_contact_id'] === 501, 'intune: the next sync follows a new primary user');
$q("UPDATE assets SET asset_contact_id = 504 WHERE asset_id = $ia");
$dev['userPrincipalName'] = 'sam.roe@acme.test';
$intune->syncDevices([$dev]);
$ok((int) $asset($ia)['asset_contact_id'] === 504, 'intune: a contact changed by hand stays');

// ---------------------------------------------------------------- admin page and handler (real HTTP, forged admin session)
$sdir = sys_get_temp_dir() . '/platform_sync_sess_' . bin2hex(random_bytes(3));
mkdir($sdir, 0700);
register_shutdown_function(function () use ($sdir) { foreach (glob("$sdir/*") ?: [] as $f) { @unlink($f); } @rmdir($sdir); });
$q("DELETE FROM user_roles WHERE role_id = 1"); $q("DELETE FROM users WHERE user_id = 1");
$q("INSERT INTO user_roles SET role_id = 1, role_name = 'Admin', role_is_admin = 1, role_type = 1");
$q("INSERT INTO users SET user_id = 1, user_name = 'admin', user_email = 'admin@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 1");
$q("DELETE FROM user_settings"); $q("INSERT INTO user_settings SET user_id = 1");
$web = ea_start_php($root . '/tests/mobile_api_router.php', ['RIVETIT_WEBHOOK_ALLOW_PRIVATE' => '1'], ["session.save_path=$sdir"]);
$wb = "http://127.0.0.1:{$web['port']}";
$sid = ea_forge_session($sdir, 1);
$wr = fn (string $m, string $p, array $post = [], array $h = []) => web($wb, $m, $p, $sid, $post, array_merge(['User-Agent: platform-sync-test'], $h));
$q("DELETE FROM integration_client_map"); $q("DELETE FROM asset_retire_queue");
$q("INSERT INTO integration_client_map SET integration_id = $intg, external_name = 'Waiting Co', map_status = 'pending', sample_host = 'WC-PC'");
$mapId = (int) $db->insert_id;
$q("UPDATE assets SET asset_archived_at = NOW(), asset_status = 'Retired' WHERE asset_id = $s2");
$q("INSERT INTO asset_retire_queue SET asset_id = $s2, client_id = 1, prev_status = 'Active', last_seen = NOW() - INTERVAL 100 DAY, stale_days = 90");
$qid = (int) $db->insert_id;
[$c, $body] = $wr('GET', '/admin/settings_integrations.php?tab=rmm');
$ok($c === 200 && str_contains($body, 'id="client-mapping"') && str_contains($body, 'Waiting Co') && str_contains($body, 'WC-PC') && str_contains($body, 'name="map_integration_client"'), 'page: the needs-mapping queue lists the waiting name with a department picker');
$ok(str_contains($body, 'id="stale-assets"') && str_contains($body, 'name="save_stale_asset_settings"') && str_contains($body, 'fresh-1') && str_contains($body, 'name="restore_retired_asset"'), 'page: the stale-asset settings and review queue');
$ok(!preg_match('/PHP (Warning|Fatal)/', (string) @file_get_contents($web['log'])), 'page: no PHP warnings');
$ref = ['Referer: ' . $wb . '/admin/settings_integrations.php'];
$wr('POST', '/admin/post.php', ['csrf_token' => 'wrong', 'map_integration_client' => 1, 'map_id' => $mapId, 'client_id' => 1], $ref);
$ok($one("SELECT map_status FROM integration_client_map WHERE map_id = $mapId") === 'pending', 'handler: a wrong CSRF token changes nothing');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'map_integration_client' => 1, 'map_id' => $mapId, 'client_id' => 1], $ref);
$ok($one("SELECT map_status FROM integration_client_map WHERE map_id = $mapId") === 'mapped' && (int) $one("SELECT client_id FROM integration_client_map WHERE map_id = $mapId") === 1, 'handler: Map saves the department');
$ok((int) $one("SELECT COUNT(*) FROM audit_events WHERE event_type = 'integration.edit'") >= 1, 'handler: the mapping is in the audit trail');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'ignore_integration_client' => 1, 'map_id' => $mapId], $ref);
$ok($one("SELECT map_status FROM integration_client_map WHERE map_id = $mapId") === 'ignored', 'handler: Ignore');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'reopen_integration_client' => 1, 'map_id' => $mapId], $ref);
$ok($one("SELECT map_status FROM integration_client_map WHERE map_id = $mapId") === 'pending', 'handler: Ask again');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'save_stale_asset_settings' => 1, 'stale_asset_retire_enabled' => 1, 'stale_asset_retire_days' => 5], $ref);
$ok(PlatformSettings::get($db, 'stale_asset_retire_enabled') === '1' && PlatformSettings::get($db, 'stale_asset_retire_days') === '7', 'handler: stale settings saved (days clamped to at least 7)');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'save_stale_asset_settings' => 1, 'stale_asset_retire_days' => 90], $ref);
$ok(PlatformSettings::get($db, 'stale_asset_retire_enabled') === '0' && PlatformSettings::get($db, 'stale_asset_retire_days') === '90', 'handler: the switch off is saved');
$wr('POST', '/admin/post.php', ['csrf_token' => 'csrftok1', 'restore_retired_asset' => 1, 'queue_id' => $qid], $ref);
$ok($asset($s2)['asset_archived_at'] === null && $one("SELECT queue_status FROM asset_retire_queue WHERE queue_id = $qid") === 'restored', 'handler: Restore un-archives the asset');
// non-admins cannot reach the handler
$q("INSERT INTO user_roles SET role_id = 30, role_name = 'Tech', role_is_admin = 0, role_type = 1");
$q("INSERT INTO users SET user_id = 30, user_name = 'tech', user_email = 'tech@example.test', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 30");
$q("INSERT INTO user_settings SET user_id = 30");
$sid30 = ea_forge_session($sdir, 30);
$q("INSERT INTO integration_client_map SET integration_id = $intg, external_name = 'Tech Try', map_status = 'pending'");
$tm = (int) $db->insert_id;
web($wb, 'POST', '/admin/post.php', $sid30, ['csrf_token' => 'csrftok1', 'map_integration_client' => 1, 'map_id' => $tm, 'client_id' => 1], ['User-Agent: t', ...$ref]);
$ok($one("SELECT map_status FROM integration_client_map WHERE map_id = $tm") === 'pending', 'handler: a non-administrator cannot map clients');
