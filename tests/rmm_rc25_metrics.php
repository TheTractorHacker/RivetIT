<?php
/*
 * rc.25 extended metrics through Core's sink into device_metric_*: every key the asset Metrics tab charts must be a key the sink really writes (or one the agent cannot send yet).
 * Scratch DB only (see tests/endpoint_agent_lib.php).
 */
require __DIR__ . '/endpoint_agent_lib.php';
ea_reset();
$tok = ea_token(1, 24, 10);
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='MET', asset_serial='MET-1', asset_client_id=1, asset_status='Active'"); $assetId = (int) $db->insert_id;
[$c, , $j] = ea_enroll($tok, ea_dev(['serial' => 'MET-1', 'hostname' => 'METPC']));
$T = $j['device_token']; $dev = (int) $j['device_id'];
$ok($c === 200 || $c === 201, "enrolled ($c)");
$m = ['cpu_pct' => 12.5, 'mem_pct' => 40.0, 'disk' => [['mount' => 'C:', 'used_pct' => 55.0, 'free_bytes' => 100 * 2 ** 30, 'total_bytes' => 250 * 2 ** 30]], 'net_rx_bps' => 800.0, 'net_tx_bps' => 400.0,
    'mem_used_bytes' => 4 * 2 ** 30, 'mem_available_bytes' => 4 * 2 ** 30, 'mem_total_bytes' => 8 * 2 ** 30, 'uptime_s' => 3600, 'pending_reboot' => 1, 'cpu_core_pct' => [10.0, 20.0, 30.0, 40.0],
    'disk_read_iops' => 5.0, 'disk_write_iops' => 7.0, 'disk_queue' => 0.5, 'net_errors' => 2, 'battery_pct' => 80.0];
[$c, , $r] = ea_checkin($T, ['metrics' => $m]);
$ok($c === 200, "check-in with the extended members accepted ($c)");
$keys = array_column($rows("SELECT DISTINCT d.metric_key FROM device_metric_samples s JOIN device_metric_defs d ON d.metric_id=s.metric_id WHERE s.asset_id=$assetId"), 'metric_key');
$want = ['cpu.utilization', 'cpu.core.utilization', 'memory.utilization', 'memory.used_bytes', 'memory.available_bytes', 'memory.total_bytes', 'disk.utilization', 'disk.free_bytes', 'disk.total_bytes', 'disk.read_iops', 'disk.write_iops',
    'disk.queue_length', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s', 'network.errors', 'battery.charge_percent', 'system.uptime_seconds', 'system.pending_reboot'];
$ok(array_diff($want, $keys) === [], 'the sink wrote every extended key (missing: ' . implode(',', array_diff($want, $keys)) . ')');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples s JOIN device_metric_defs d ON d.metric_id=s.metric_id WHERE s.asset_id=$assetId AND d.metric_key='cpu.core.utilization'") === 4, 'four cores gave four per-core samples');
// the Metrics tab only asks for keys the sink knows
$tab = file_get_contents("$root/agent/includes/asset/metrics_tab.php");
preg_match_all("/'keys' => \[([^\]]*)\]/", $tab, $mm);
$tabKeys = []; foreach ($mm[1] as $l) { preg_match_all("/'([a-z_.]+)'/", $l, $k); $tabKeys = array_merge($tabKeys, $k[1]); }
$tabKeys = array_unique($tabKeys);
$notSent = ['gpu.utilization', 'gpu.memory_used_bytes', 'gpu.memory_total_bytes', 'gpu.temperature', 'battery.health_percent', 'disk.latency_ms'];   // rc.25 does not collect these
$unknown = array_diff($tabKeys, $keys, $notSent);
$ok($unknown === [], 'every Metrics tab key is written by the sink or is one rc.25 does not collect (' . implode(',', $unknown) . ')');
$def = array_column($rows("SELECT metric_key FROM device_metric_defs"), 'metric_key');
$ok(array_diff($tabKeys, $def) === [], 'every Metrics tab key has a metric definition');
// the switch: metrics_extended = 0 drops the extra members
$rmm = rivetRmmModule($db);
$lim = json_decode((string) $one('SELECT limits_json FROM endpoint_agent_settings WHERE id=1'), true) ?: [];
$lim['metrics_extended'] = 0;
$ok($rmm->settings()->update(['limits_json' => json_encode($lim)]) === [], 'metrics_extended=0 is accepted');
$q("DELETE FROM device_metric_samples");
[$c] = ea_checkin($T, ['metrics' => $m]);
$k2 = array_column($rows("SELECT DISTINCT d.metric_key FROM device_metric_samples s JOIN device_metric_defs d ON d.metric_id=s.metric_id WHERE s.asset_id=$assetId"), 'metric_key');
$ok($c === 200 && !in_array('memory.used_bytes', $k2, true) && !in_array('cpu.core.utilization', $k2, true) && in_array('cpu.utilization', $k2, true), 'with the switch off only the basic metrics are stored');
$ok(strpos(file_get_contents("$root/includes/rmm_scale_cards.php"), 'Send extra health details (memory, disks, per-core, battery)') !== false && strpos(file_get_contents("$root/admin/post/settings_endpoint_agent.php"), "'metrics_extended'") !== false, 'the admin page has the plain switch and its handler');
$q("DELETE FROM endpoint_agent_devices"); $q("DELETE FROM assets"); $q("UPDATE endpoint_agent_settings SET limits_json=NULL WHERE id=1");
