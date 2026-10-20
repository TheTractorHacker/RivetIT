<?php
/*
 * Fixtures for tests/browser/rmm_rc25_smoke.mjs (RivetIT 26.10.65), run on the same throwaway install after tests/browser/rmm_seed.php.
 *
 *   php tests/browser/rmm_rc25_extra.php <seed.json> seed    a Windows PC (MWA) whose check-in was refused for its clock and that holds the 8 Linux service checks with open alerts, a Windows PC 6 minutes
 *                                                           ahead, an OS-less stored schedule, change monitoring on with two records (one hostile); prints the ids
 *   php tests/browser/rmm_rc25_extra.php <seed.json> state   MWA's Linux check rows, open alerts, resolved alerts, tickets, the stored schedule's service OS values
 *
 * Prints one JSON line. Scratch databases only (the guard of tests/endpoint_agent_lib.php applies).
 */
require dirname(__DIR__) . '/endpoint_agent_lib.php';
require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

$mode = $argv[2] ?? 'state';
$rmm = rivetRmmModule($db);
$linux = ['ssh', 'sshd', 'cron', 'crond', 'chronyd', 'systemd-timesyncd', 'rsyslog', 'fail2ban'];
$out = ['ok' => true];
$mk = function (string $host, array $cols) use ($q, $db): int {
    $sets = ''; foreach ($cols as $k => $v) { $sets .= ", $k=" . ($v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'"); }
    $q("INSERT INTO endpoint_agent_devices SET install_id='" . ea_uuid() . "', hostname='$host', os='windows', link_state='linked', client_id=1, first_seen_at=UTC_TIMESTAMP()$sets");
    return (int) $db->insert_id;
};
$asset = function (int $dev, string $name) use ($q, $db): int {
    $q("INSERT INTO assets SET asset_name='" . $db->real_escape_string($name) . "', asset_type='Laptop', asset_client_id=1"); $id = (int) $db->insert_id;
    $q("UPDATE endpoint_agent_devices SET asset_id=$id WHERE device_id=$dev"); return $id;
};
if ($mode === 'seed') {
    $integration = $rmm->settings()->integrationId();
    $now = gmdate('Y-m-d H:i:s');
    $hostile = '<img src=x onerror="window.__xss=1">';
    $mwa = $mk('MWA-SMOKE', ['clock_skew_s' => 300, 'clock_skew_at' => $now, 'last_reject_reason' => 'collected_at is in the future ' . $hostile, 'last_reject_at' => $now]);
    $aMwa = $asset($mwa, 'MWA-SMOKE');
    $ahead = $mk('AHEAD-SMOKE', ['last_checkin_at' => $now, 'clock_skew_s' => 360, 'clock_skew_at' => $now, 'agent_version' => '0.9.0']);
    $aAhead = $asset($ahead, 'AHEAD-SMOKE');
    foreach ($linux as $k) {
        $q("INSERT INTO rmm_alerts SET integration_id=$integration, asset_id=$aMwa, client_id=1, tactical_alert_id='agent:$mwa:svc_$k:1', severity='error', status='new', message='service $k not found', raw_data_json='{}'");
        $q("INSERT INTO endpoint_agent_checks SET device_id=$mwa, check_key='svc_$k', status='fail', detail='x', alert_id=" . (int) $db->insert_id . ", last_reported_at=UTC_TIMESTAMP()");
    }
    $list = [['key' => 'disk_free', 'type' => 'disk', 'params' => ['warn_free_pct' => 20, 'fail_free_pct' => 10], 'interval_s' => 900], ['key' => 'svc_eventlog', 'type' => 'service', 'params' => ['name' => 'EventLog', 'expected' => 'running'], 'interval_s' => 900]];
    foreach ($linux as $k) { $list[] = ['key' => "svc_$k", 'type' => 'service', 'params' => ['name' => $k, 'expected' => 'running'], 'interval_s' => 900]; }
    $out['schedule_errors'] = $rmm->settings()->update(['checks_json' => json_encode($list)]);
    $r = $rmm->changeActions()->updateSettings(rivetRmmPrincipal(1, 'admin'), ['features' => ['change_monitor' => true], 'accept_change_storage' => true]);
    $out['change_on'] = $r->ok;
    $q("INSERT INTO endpoint_agent_change_records SET device_id=$ahead, occurred_at=UTC_TIMESTAMP(), received_at=UTC_TIMESTAMP(), kind='registry', watch='win.startup', change_type=2, target='HKLM\\\\SOFTWARE\\\\Run', item='Updater', old_value='a.exe', new_value='" . $db->real_escape_string("b.exe $hostile") . "', flags=0, dedupe=UNHEX(MD5('s1'))");
    $q("INSERT INTO endpoint_agent_change_records SET device_id=$ahead, occurred_at=UTC_TIMESTAMP(), received_at=UTC_TIMESTAMP(), kind='file', watch='x', change_type=2, target='/etc/app.conf', item='', old_value='', new_value='[redacted]', flags=1, dedupe=UNHEX(MD5('s2'))");
    $out += ['mwa' => $mwa, 'asset_mwa' => $aMwa, 'ahead' => $ahead, 'asset_ahead' => $aAhead];
} else {
    $out['linux_rows'] = (int) $one("SELECT COUNT(*) FROM endpoint_agent_checks c JOIN endpoint_agent_devices d ON d.device_id=c.device_id WHERE d.hostname='MWA-SMOKE' AND c.check_key LIKE 'svc\\_%'");
    $out['open_alerts'] = (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE tactical_alert_id LIKE 'agent:%:svc\\_%' AND status<>'resolved' AND message LIKE 'service % not found'");
    $out['resolved_alerts'] = (int) $one("SELECT COUNT(*) FROM rmm_alerts WHERE tactical_alert_id LIKE 'agent:%:svc\\_%' AND status='resolved'");
    $out['tickets'] = (int) $one('SELECT COUNT(*) FROM tickets');
    $raw = json_decode((string) $one('SELECT checks_json FROM endpoint_agent_settings WHERE id=1'), true) ?: [];
    $out['os'] = []; foreach ($raw as $c) { if (($c['type'] ?? '') === 'service') { $out['os'][$c['key']] = $c['os'] ?? null; } }
}
echo json_encode($out), "\n";
