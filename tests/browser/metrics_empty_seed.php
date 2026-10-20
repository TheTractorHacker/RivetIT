<?php
/*
 * Seeds a THROWAWAY install for tests/browser/metrics_empty_smoke.mjs (RivetIT 26.10.63): one Tactical-linked asset whose device-metric history is
 * deliberately uneven - dense CPU / memory % / network, 2-4 samples for disk space, uptime and pending reboot, memory totals only from 10 days ago, and no
 * GPU / battery / IOPS / per-core at all.
 *
 *   php tests/browser/metrics_empty_seed.php <out.json>
 *
 * Reads the worktree's config.php; refuses unless the database name contains "scratch". Gives user 1 the login admin@scratch.test.
 */
$root = dirname(__DIR__, 2);
require $root . '/config.php';
require $root . '/vendor/autoload.php';
if (stripos((string) $database, 'scratch') === false) { fwrite(STDERR, "refusing: database name must contain 'scratch'\n"); exit(2); }
$db = new mysqli($dbhost, $dbusername, $dbpassword, $database);
$db->set_charset('utf8mb4');
$db->query("SET SESSION sql_mode=''");
$q = function (string $sql) use ($db) { if (!$db->query($sql)) { fwrite(STDERR, $db->error . "\n" . $sql . "\n"); exit(1); } return $db->insert_id; };

$pw = (string) (getenv('RMM_SMOKE_PASSWORD') ?: 'Scratch-Admin-1234');
$q("UPDATE users SET user_email='admin@scratch.test', user_name='Scratch Admin', user_password='" . $db->real_escape_string(password_hash($pw, PASSWORD_DEFAULT)) . "' WHERE user_id=1");
$q("INSERT IGNORE INTO user_settings (user_id) VALUES (1)");
$q("UPDATE settings SET config_module_enable_rmm=1, config_enable_device_metrics=1 WHERE company_id=1");

$client = $q("INSERT INTO clients (client_name, client_created_at) VALUES ('Metrics Co', NOW())");
$asset  = $q("INSERT INTO assets (asset_type, asset_name, asset_make, asset_client_id, asset_created_at) VALUES ('Desktop', 'SPARSE-PC', 'Dell', $client, NOW())");
$int    = $q("INSERT INTO rmm_integrations (name, type, api_url, api_key_enc, enabled) VALUES ('Scratch RMM', 'tactical_rmm', 'http://127.0.0.1:9', 'x', 1)");
$q("INSERT INTO asset_rmm_links (asset_id, integration_id, tactical_agent_id, hostname, rmm_status, last_seen) VALUES ($asset, $int, 'scratch-agent-1', 'SPARSE-PC', 'online', NOW())");

\ITFlow\Metrics\MetricRegistry::syncToDatabase($db);
$ids = [];
$r = $db->query("SELECT metric_id, metric_key, metric_dim FROM device_metric_defs");
while ($row = $r->fetch_assoc()) { $ids[$row['metric_key']] = [(int) $row['metric_id'], $row['metric_dim']]; }
$instance = function (string $key, string $label) use ($q, $ids, $asset): int {
    return $q("INSERT INTO device_metric_instances (asset_id, metric_dim, instance_key, instance_label, first_seen_at, last_seen_at) VALUES ($asset, '" . $ids[$key][1] . "', '$label', '$label', NOW(), NOW())");
};
$now = time();
$put = function (string $key, int $instId, int $agoSeconds, float $v) use ($q, $ids, $asset): void {
    $q("INSERT INTO device_metric_samples (asset_id, metric_id, instance_id, sampled_at, metric_value) VALUES ($asset, " . $ids[$key][0] . ", $instId, '" . gmdate('Y-m-d H:i:s', time() - $agoSeconds) . "', $v)");
};
for ($i = 0; $i < 72; $i++) {            // dense: every 5 min for 6 h
    $ago = $i * 300 + 60;
    $put('cpu.utilization', 0, $ago, 20 + 30 * abs(sin($i / 6)));
    $put('memory.utilization', 0, $ago, 55 + 5 * sin($i / 9));
    $put('network.rx_bytes_per_s', 0, $ago, 200000 + 150000 * abs(sin($i / 4)));
    $put('network.tx_bytes_per_s', 0, $ago, 50000 + 30000 * abs(cos($i / 5)));
}
$c = $instance('disk.free_bytes', 'C:');
foreach ([[10800, 120e9], [7200, 118e9], [3600, 117e9], [600, 116e9]] as [$ago, $v]) {
    $put('disk.free_bytes', $c, $ago, $v);
    $put('disk.total_bytes', $c, $ago, 500e9);
}
$put('system.uptime_seconds', 0, 7200, 86400);
$put('system.uptime_seconds', 0, 900, 93600);
$put('system.pending_reboot', 0, 7200, 0);
$put('system.pending_reboot', 0, 900, 1);
$put('memory.total_bytes', 0, 10 * 86400, 16e9);
$put('memory.total_bytes', 0, 10 * 86400 + 300, 16e9);

file_put_contents($argv[1] ?? 'php://stdout', json_encode(['asset_id' => $asset, 'client_id' => $client]) . "\n");
