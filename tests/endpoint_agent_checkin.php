<?php
/* Check-in: auth, idempotency by seq, inventory, metrics (null stays null), buffered samples, timestamps, caps, alerts, revoke/rotate, transfer. */
require __DIR__ . '/endpoint_agent_lib.php';
use ITFlow\EndpointAgent\Devices; use ITFlow\EndpointAgent\Enrollment; use ITFlow\EndpointAgent\Config; use ITFlow\EndpointAgent\Maintenance;

ea_reset();
$tok = ea_token(1, 24, 100);
$q("INSERT INTO assets SET asset_type='Laptop', asset_name='Front desk', asset_make='Dell', asset_serial='CI-SER-1', asset_client_id=1, asset_status='Active'");
$assetId = (int) $db->insert_id;
$d = ea_dev(['serial' => 'CI-SER-1', 'hostname' => 'FRONTDESK']);
[$c, , $j] = ea_enroll($tok, $d);
$dev = (int) $j['device_id']; $T = $j['device_token'];
$ok($j['status'] === 'linked' && (int) $j['matched_asset_id'] === $assetId, 'enrolled and linked to the asset');
$intg = (int) $one("SELECT id FROM rmm_integrations WHERE type='rivetit_agent'");

// --- authentication
[$c, , $r] = http('POST', '/api/v1/agent_checkin', null, ['seq' => 1]);
$ok($c === 401 && $r['code'] === 'invalid_token', 'no credential -> 401 invalid_token');
[$c, , $r] = http('POST', '/api/v1/agent_checkin', str_repeat('a', 64), ['seq' => 1]);
$ok($c === 401 && $r['code'] === 'invalid_token', 'wrong credential -> 401 invalid_token');
[$c, , $r] = http('GET', '/api/v1/agent_checkin', $T);
$ok($c === 405, 'GET check-in -> 405');
[$c, , $r] = http('POST', '/api/v1/agent_checkin', $T, 'nope');
$ok($c === 422, 'non-JSON body -> 422');
$q("UPDATE endpoint_agent_devices SET token_expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE device_id=$dev");
[$c, , $r] = ea_checkin($T); $ok($c === 401 && $r['code'] === 'expired', 'expired credential -> 401 expired');
$q("UPDATE endpoint_agent_devices SET token_expires_at='" . gmdate('Y-m-d H:i:s', time() + 3600) . "' WHERE device_id=$dev");

// --- first check-in with inventory
$inventory = ['hostname' => 'FRONTDESK', 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => 'CI-SER-1',
    'cpu' => ['model' => 'Intel i7-1355U', 'cores' => 10], 'memory_total_bytes' => 17179869184,
    'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS']],
    'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5', 'not-an-ip']]], 'uptime_s' => 7200, 'logged_in_user' => '<script>alert(1)</script>', 'pending_reboot' => false];
[$c, , $r] = http('POST', '/api/v1/agent_checkin', $T, ['seq' => 1, 'collected_at' => ea_ts(-30), 'agent_version' => '1.0.0', 'inventory' => $inventory,
    'metrics' => ['cpu_pct' => 12.5, 'mem_pct' => 40, 'disk' => [['mount' => 'C:', 'used_pct' => 60.8]], 'net_rx_bps' => 1000.5, 'net_tx_bps' => 500], 'checks' => [], 'buffered' => []]);
$ok($c === 200 && $r['ok'] === true, 'check-in -> 200 ok');
foreach (['next_check_in_s', 'jobs_pending', 'config', 'update', 'server_time'] as $k) { $ok(array_key_exists($k, $r), "response has $k"); }
$ok($r['update'] === null && $r['jobs_pending'] === 0 && is_array($r['config']['checks']) && isset($r['config']['collect_interval_s']), 'no update, no jobs, config returned');
$row = $rows("SELECT * FROM endpoint_agent_devices WHERE device_id=$dev")[0];
$ok($row['last_checkin_at'] !== null && $row['logged_in_user'] !== null && $row['uptime_s'] == 7200 && (int) $row['pending_reboot'] === 0, 'inventory stored on the device');
$ok(strpos((string) $row['inventory_json'], 'not-an-ip') === false, 'invalid IP strings in inventory are dropped');
$link = $rows("SELECT * FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg")[0] ?? null;
$ok($link && $link['rmm_status'] === 'online' && (int) $link['rmm_cpu_percent'] === 13 && (int) $link['rmm_ram_percent'] === 40 && (int) $link['rmm_disk_percent'] === 61 && $link['cpu'] === 'Intel i7-1355U' && (float) $link['ram_gb'] === 16.0, 'existing RMM link shows status, health and inventory');
$ok($link['last_seen'] !== null && $link['logged_in_user'] === '<script>alert(1)</script>', 'last_seen set; device-supplied strings stored raw (escaped at render)');
$defs = [];
foreach ($rows("SELECT metric_key, metric_id FROM device_metric_defs") as $m) { $defs[$m['metric_key']] = (int) $m['metric_id']; }
$nSamples = fn(string $key) => (int) $one("SELECT COUNT(*) FROM device_metric_samples WHERE asset_id=$assetId AND metric_id=" . ($defs[$key] ?? 0));
$ok($nSamples('cpu.utilization') === 1 && $nSamples('memory.utilization') === 1 && $nSamples('disk.utilization') === 1 && $nSamples('network.rx_bytes_per_s') === 1 && $nSamples('system.uptime_seconds') === 1,
    'metrics land in the EXISTING device_metric_samples (cpu, memory, disk, network, uptime)');
$ok(abs((float) $one("SELECT metric_value FROM device_metric_samples WHERE asset_id=$assetId AND metric_id=" . $defs['cpu.utilization']) - 12.5) < 0.001, 'metric value stored');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_collection_state WHERE asset_id=$assetId AND integration_id=$intg") === 1, 'collection state recorded for the agent integration');

// --- idempotency by (device, seq)
$body = ['seq' => 50, 'collected_at' => ea_ts(-10), 'agent_version' => '1.0.0', 'inventory' => null, 'metrics' => ['cpu_pct' => 77.0, 'mem_pct' => null, 'disk' => [], 'net_rx_bps' => null, 'net_tx_bps' => null],
    'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'low']]];
[$c1] = http('POST', '/api/v1/agent_checkin', $T, $body);
[$c2] = http('POST', '/api/v1/agent_checkin', $T, $body);
[$c3] = http('POST', '/api/v1/agent_checkin', $T, $body);
$ok($c1 === 200 && $c2 === 200 && $c3 === 200, 'a retried check-in is acknowledged every time');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples WHERE asset_id=$assetId AND metric_id={$defs['cpu.utilization']}") === 2, 'a retried check-in is not double counted (metrics)');
$ok((int) $one("SELECT consecutive_failures FROM endpoint_agent_checks WHERE device_id=$dev AND check_key='disk_c'") === 1, 'a retried check-in is not double counted (check failure counter)');
$ok((int) $one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id=$dev AND seq=50") === 1, 'one idempotency row per (device, seq)');

// --- null stays null, never zero
$ok($nSamples('memory.utilization') === 1, 'a null mem_pct produced NO sample (never stored as zero)');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples WHERE asset_id=$assetId AND metric_value = 0 AND metric_id IN ({$defs['cpu.utilization']},{$defs['memory.utilization']},{$defs['disk.utilization']},{$defs['network.rx_bytes_per_s']},{$defs['network.tx_bytes_per_s']})") === 0, 'no zero-valued cpu/memory/disk/network sample exists (a missing reading is never stored as 0)');
$m = json_decode((string) $one("SELECT last_metrics_json FROM endpoint_agent_devices WHERE device_id=$dev"), true);
$ok($m['mem_pct'] === null && $m['net_rx_bps'] === null && $m['cpu_pct'] == 77.0, 'the last-metrics snapshot keeps null as null');
$ok((string) $one("SELECT rmm_ram_percent FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === '' || $one("SELECT rmm_ram_percent FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === null, 'the RMM link ram percent is NULL, not 0, when the reading is missing');

// --- buffered samples
$buf = [];
for ($i = 1; $i <= 3; $i++) { $buf[] = ['collected_at' => ea_ts(-3600 * $i), 'metrics' => ['cpu_pct' => 20 + $i, 'mem_pct' => 30 + $i, 'disk' => [['mount' => 'C:', 'used_pct' => 50]], 'net_rx_bps' => null, 'net_tx_bps' => null], 'checks' => []]; }
$buf[] = ['collected_at' => ea_ts(3600 * 5), 'metrics' => ['cpu_pct' => 99]];   // in the future: dropped, not fatal
$buf[] = 'garbage';
[$c] = ea_checkin($T, ['buffered' => $buf]);
$ok($c === 200, 'buffered backlog accepted');
$ok($nSamples('cpu.utilization') === 2 + 1 + 3, 'buffered samples stored with their own collection times (3 valid, future one dropped)');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples WHERE asset_id=$assetId AND metric_id={$defs['cpu.utilization']} AND metric_value = 99") === 0, 'a buffered sample from the future is rejected');
[$c] = ea_checkin($T, ['buffered' => array_fill(0, 101, ['collected_at' => ea_ts(-60), 'metrics' => ['cpu_pct' => 5]])]);
$ok($c === 422, 'more than 100 buffered samples -> 422');

// --- timestamps and caps
[$c, , $r] = ea_checkin($T, ['collected_at' => ea_ts(3600)]);
$ok($c === 422 && $r['code'] === 'invalid', 'a collected_at far in the future is rejected');
[$c] = ea_checkin($T, ['collected_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 400)]);
$ok($c === 422, 'a collected_at older than the retention window is rejected');
[$c] = ea_checkin($T, ['collected_at' => 'yesterday']);
$ok($c === 422, 'a non-RFC3339 collected_at is rejected');
[$c] = ea_checkin($T, ['collected_at' => ea_ts(60)]);
$ok($c === 200, 'a small clock skew is tolerated');
[$c] = http('POST', '/api/v1/agent_checkin', $T, ['seq' => -1, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0']);
$ok($c === 422, 'negative seq -> 422');
[$c] = http('POST', '/api/v1/agent_checkin', $T, ['seq' => '7', 'collected_at' => ea_ts(), 'agent_version' => '1.0.0']);
$ok($c === 422, 'string seq -> 422');
[$c] = ea_checkin($T, ['checks' => array_fill(0, 101, ['key' => 'k', 'status' => 'ok', 'detail' => ''])]);
$ok($c === 422, 'more than 100 checks -> 422');
[$c] = ea_checkin($T, ['checks' => [['key' => 'bad key!', 'status' => 'ok']]]);
$ok($c === 422, 'invalid check key -> 422');
[$c] = ea_checkin($T, ['checks' => [['key' => 'k', 'status' => 'great']]]);
$ok($c === 422, 'invalid check status -> 422');
[$c] = ea_checkin($T, ['inventory' => ['blob' => str_repeat('x', 70000)]]);
$ok($c === 422 || $c === 413, 'oversized inventory is refused');
[$c] = http('POST', '/api/v1/agent_checkin', $T, str_repeat('{"a":"' . str_repeat('x', 1000) . '"},', 1500), ['Content-Type: application/json']);
$ok($c === 413, 'a body over 1 MiB -> 413');
$cpuBefore = $nSamples('cpu.utilization');
[$c, , $r] = ea_checkin($T, ['metrics' => ['cpu_pct' => 150.0, 'mem_pct' => -4, 'disk' => [['mount' => 'C:', 'used_pct' => 101]], 'net_rx_bps' => -1, 'net_tx_bps' => 3]]);
$ok($c === 200 && $nSamples('cpu.utilization') === $cpuBefore, 'out-of-range readings are dropped, not clamped, not zeroed');
$ok((int) $one("SELECT COUNT(*) FROM device_metric_samples WHERE asset_id=$assetId AND metric_id={$defs['memory.utilization']} AND metric_value < 0") === 0, 'no negative memory sample');

// --- alert debounce: one alert, one ticket, recovery resolves
$q("DELETE FROM endpoint_agent_checks"); $q("DELETE FROM rmm_alerts"); $q("DELETE FROM tickets");
$fail = fn() => ea_checkin($T, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: is 97% full']]]);
$good = fn() => ea_checkin($T, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => '']]]);
$fail(); $fail();
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts") === 0, 'two failures (debounce is 3) raise no alert yet');
$fail();
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE integration_id=$intg AND status='new'") === 1, 'the third consecutive failure raises ONE alert');
$a = $rows("SELECT * FROM rmm_alerts")[0];
$ok((int) $a['asset_id'] === $assetId && (int) $a['client_id'] === 1 && $a['severity'] === 'error' && strpos($a['message'], 'disk_c') !== false, 'alert is on the linked asset and department');
$fail(); $fail(); $fail();
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts") === 1, 'continued failures do not create more alerts');
http('POST', '/api/v1/agent_checkin', $T, ['seq' => 900001, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0', 'checks' => [['key' => 'disk_c', 'status' => 'fail']]]);
http('POST', '/api/v1/agent_checkin', $T, ['seq' => 900001, 'collected_at' => ea_ts(), 'agent_version' => '1.0.0', 'checks' => [['key' => 'disk_c', 'status' => 'fail']]]);
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts") === 1, 'repeated delivery of the same check-in creates no duplicate alert');
// the existing alert-to-ticket path
require_once "$root/includes/rmm_functions.php";
$alert = $rows("SELECT * FROM rmm_alerts")[0];
$t1 = createTicketFromRmmAlert($db, $alert, 0, 'RMM Automation');
$alert = $rows("SELECT * FROM rmm_alerts")[0];
$t2 = createTicketFromRmmAlert($db, $alert, 0, 'RMM Automation');
$ok(!$t1['existing'] && $t2['existing'] && $t1['ticket_id'] === $t2['ticket_id'] && (int) $one("SELECT COUNT(*) FROM tickets") === 1, 'repeated alert-to-ticket delivery creates exactly one ticket (existing dedupe reused)');
$good();
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE status='resolved'") === 0, 'one OK result does not resolve yet (recovery debounce is 2)');
$good();
$ok($one("SELECT status FROM rmm_alerts") === 'resolved', 'two consecutive OK results resolve the alert');
$ok($one("SELECT ticket_closed_at FROM tickets") !== null && (int) $one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply LIKE 'Auto-closed%'") === 1, 'recovery auto-closes the untouched ticket through the existing rule');
$fail(); $fail(); $fail();
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts") === 2 && (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE status='new'") === 1, 'a new failure episode after recovery raises a NEW alert');
$q("UPDATE endpoint_agent_checks SET alert_id=alert_id"); // noop
[$c] = ea_checkin($T, ['checks' => [['key' => 'disk_c', 'status' => 'unknown', 'detail' => '']]]);
$ok((int) $one("SELECT consecutive_failures FROM endpoint_agent_checks WHERE check_key='disk_c'") === 3, 'an unknown result changes no counter');

// --- offline sweep + status
Config::set(['offline_after_s' => 900]);
$q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 2000) . "' WHERE device_id=$dev");
$res = Maintenance::run();
$ok($res['offline'] === 1 && $one("SELECT rmm_status FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === 'offline' && $one("SELECT rmm_status_changed_at FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") !== null, 'the cron sweep flips the existing RMM link to offline with a status-change timestamp');
$st = Devices::status(Devices::find($dev));
$ok($st['state'] === 'offline' && $st['offline_since'] !== null && $st['last_checkin_at'] !== null, 'status is offline with explicit timestamps');
$q("UPDATE endpoint_agent_devices SET last_checkin_at='" . gmdate('Y-m-d H:i:s', time() - 86400 * 30) . "' WHERE device_id=$dev");
$ok(Devices::status(Devices::find($dev))['state'] === 'stale', 'a device unseen for longer than the stale threshold is stale');
[$c] = ea_checkin($T);
$ok($c === 200 && Devices::status(Devices::find($dev))['state'] === 'online' && $one("SELECT rmm_status FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === 'online', 'a check-in brings it back online');

// --- rotation / revocation block check-in AND jobs immediately
[$c, , $r] = http('GET', '/api/v1/agent_jobs', $T); $ok($c === 200 && $r['jobs'] === [], 'job fetch works before revocation');
Devices::rotate($dev, 1);
[$c, , $r] = ea_checkin($T); $ok($c === 401 && $r['code'] === 'invalid_token', 'rotated credential: check-in -> 401 invalid_token');
[$c, , $r] = http('GET', '/api/v1/agent_jobs', $T); $ok($c === 401, 'rotated credential: job fetch -> 401');
[$c, , $j2] = ea_enroll(ea_token(1, 24, 10), $d);
$ok($c === 201 && (int) $j2['device_id'] === $dev, 're-enrollment after rotation keeps the device id'); $T2 = $j2['device_token'];
[$c] = ea_checkin($T2); $ok($c === 200, 'new credential works');
Devices::revoke($dev, 'test', 1);
[$c, , $r] = ea_checkin($T2); $ok($c === 401 && $r['code'] === 'revoked', 'revoked: check-in -> 401 revoked');
[$c, , $r] = http('GET', '/api/v1/agent_jobs', $T2); $ok($c === 401 && $r['code'] === 'revoked', 'revoked: job fetch -> 401 revoked');
[$c, , $r] = http('POST', '/api/v1/agent_jobs', $T2, ['job_id' => ea_uuid(), 'attempt' => 1, 'state' => 'running']); $ok($c === 401, 'revoked: job report -> 401');
[$c, , $r] = ea_enroll(ea_token(1, 24, 10), $d); $ok($c === 403, 'a revoked device cannot simply re-enroll (admin must allow it)');
Devices::allowReenroll($dev, 1);
[$c, , $j3] = ea_enroll(ea_token(1, 24, 10), $d); $ok($c === 201 && (int) $j3['device_id'] === $dev, 'after "allow re-enroll" it can enroll again, same device'); $T3 = $j3['device_token'];

// --- department transfer
$q("INSERT INTO rmm_alerts SET asset_id=$assetId, client_id=1, integration_id=$intg, tactical_alert_id='agent:x', severity='error', status='new', message='m'");
Devices::transfer($dev, 2, 0, 1);
$ok((int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id=$dev") === 2 && (int) $one("SELECT asset_client_id FROM assets WHERE asset_id=$assetId") === 2 && (int) $one("SELECT client_id FROM rmm_alerts WHERE tactical_alert_id='agent:x'") === 2, 'department transfer moves device, asset and open alerts');
[$c] = ea_checkin($T3); $ok($c === 200 && (int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === 1, 'the device keeps reporting after the transfer, link unchanged');
[$c, , $j4] = ea_enroll(ea_token(1, 24, 10), $d);
$ok($c === 201 && (int) $one("SELECT client_id FROM endpoint_agent_devices WHERE device_id=$dev") === 2, 're-enrolling with a department-1 token does not silently move a transferred device back');

// --- retire
Devices::retire($dev, 1);
$ok((int) $one("SELECT COUNT(*) FROM asset_rmm_links WHERE asset_id=$assetId AND integration_id=$intg") === 0 && (int) $one("SELECT COUNT(*) FROM assets WHERE asset_id=$assetId") === 1, 'retire stops monitoring (link removed) and keeps the asset');
$ok((int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE status='new' AND tactical_alert_id LIKE 'agent:$dev:%'") === 0, 'retire resolves the device\'s open alerts');
[$c, , $r] = ea_checkin($j4['device_token']); $ok($c === 401, 'retired device is locked out');

// --- version string / transport guards
[$c] = http('POST', '/api/v1/agent_checkin', $T3, ['seq' => 1, 'collected_at' => ea_ts(), 'agent_version' => '1.0']);
$ok($c === 401, '(retired) still locked out for malformed input');
$ok(strpos(file_get_contents($EA['log']), 'Fatal') === false, 'server log has no PHP fatals');
