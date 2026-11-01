<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/*
 * rivetRmmAssetMoved(): an asset that changes department (client) outside RivetCore's own transfer takes its RMM device with it (device, jobs, and the stored
 * log / change history), SCRATCH database:   RIVETIT_TEST_DB=1 ... php tests/rmm_asset_moved.php
 */
require __DIR__ . '/support/security_lib.php';
require_once "$root/includes/event_bus.php";
require_once "$root/includes/rmm_bootstrap.php";

foreach (['endpoint_agent_log_events', 'endpoint_agent_jobs', 'endpoint_agent_devices', 'assets', 'clients'] as $t) { $q("DELETE FROM $t"); }
$q("INSERT INTO clients SET client_id=9701, client_name='Dept A', client_created_at=NOW()");
$q("INSERT INTO clients SET client_id=9702, client_name='Dept B', client_created_at=NOW()");
$q("INSERT INTO assets SET asset_id=9710, asset_type='Laptop', asset_name='PC-1', asset_client_id=9701, asset_created_at=NOW()");
$q("INSERT INTO assets SET asset_id=9711, asset_type='Laptop', asset_name='PC-2', asset_client_id=9701, asset_created_at=NOW()");
$cols = array_column($rows("SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_devices' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"), 'COLUMN_NAME');
$set = ['client_id' => 9701, 'asset_id' => 9710, 'hostname' => "'pc-1'"];
foreach ($cols as $c) { if (!isset($set[$c])) { $set[$c] = "''"; } }
$sets = implode(', ', array_map(fn ($k, $v) => "`$k` = $v", array_keys($set), $set));
$q("INSERT INTO endpoint_agent_devices SET $sets");
$dev = (int) $one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id = 9710");
$ok($dev > 0, 'a device is linked to the asset');

$ok(rivetRmmAssetMoved($db, 9711, 9702, 1) === false, 'an asset with no device: nothing to move');
$ok(rivetRmmAssetMoved($db, 9710, 9701, 1) === false, 'a device already in that department: nothing to move');
$q("UPDATE assets SET asset_client_id = 9702 WHERE asset_id = 9710");
$ok(rivetRmmAssetMoved($db, 9710, 9702, 1) === true, 'the asset moved department: the device follows');
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id = $dev") === 9702, 'the device row is in the new department');
$ok((int) $one("SELECT asset_client_id FROM assets WHERE asset_id = 9710") === 9702, 'and the asset is too');
$ok(rivetRmmAssetMoved($db, 9710, 9702, 1) === false, 'running it again changes nothing');
$ok(rivetRmmAssetMoved($db, 0, 9702, 1) === false, 'an unknown asset is ignored without an error');
