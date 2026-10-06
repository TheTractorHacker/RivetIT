<?php

namespace ITFlow\EndpointAgent;

/**
 * Check results -> the EXISTING rmm_alerts table, with debounce, dedupe and auto-resolve.
 *
 *  - fail/warn count as "bad", ok as "good", unknown changes nothing.
 *  - An alert opens after `failure_debounce` consecutive bad results and closes after `recovery_debounce` consecutive ok results.
 *  - The alert's tactical_alert_id is "agent:<device>:<check>:<episode>"; (integration_id, tactical_alert_id) is UNIQUE, so a
 *    re-delivered check-in can never create a second row for the same episode. Tickets then follow the existing path
 *    (cron auto-ticket / automation rules -> createTicketFromRmmAlert, which links one ticket per alert).
 *  - Recovery resolves the alert and runs the existing conservative auto-close of the linked ticket.
 */
final class Checks
{
    /**
     * @param list<array{key:string,status:string,detail:string,at:string}> $results oldest first
     */
    public static function apply(array $dev, array $results): void
    {
        if (!$results) {
            return;
        }
        $cfg = Config::get();
        $failN = max(1, (int) $cfg['failure_debounce']);
        $okN = max(1, (int) $cfg['recovery_debounce']);
        $now = Db::utcNow();
        foreach ($results as $r) {
            $row = Db::one('SELECT * FROM endpoint_agent_checks WHERE device_id = ? AND check_key = ?', [$dev['device_id'], $r['key']]);
            if (!$row) {
                Db::run('INSERT IGNORE INTO endpoint_agent_checks (device_id, check_key, status, detail, last_reported_at, last_changed_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$dev['device_id'], $r['key'], 'unknown', '', $now, $now]);
                $row = Db::one('SELECT * FROM endpoint_agent_checks WHERE device_id = ? AND check_key = ?', [$dev['device_id'], $r['key']]);
            }
            $fails = (int) $row['consecutive_failures'];
            $oks = (int) $row['consecutive_ok'];
            $alertId = $row['alert_id'] === null ? null : (int) $row['alert_id'];
            $episode = (int) $row['episode'];
            $bad = in_array($r['status'], ['fail', 'warn'], true);
            if ($bad) {
                $fails++;
                $oks = 0;
                if ($alertId === null && $fails >= $failN) {
                    $episode++;
                    $alertId = self::openAlert($dev, $r, $episode);
                }
            } elseif ($r['status'] === 'ok') {
                $oks++;
                $fails = 0;
                if ($alertId !== null && $oks >= $okN) {
                    self::resolveAlert($dev, $alertId);
                    $alertId = null;
                }
            }
            Db::run('UPDATE endpoint_agent_checks SET status = ?, detail = ?, consecutive_failures = ?, consecutive_ok = ?, episode = ?, alert_id = ?, last_reported_at = ?,
                last_changed_at = IF(status <> ?, ?, last_changed_at) WHERE device_id = ? AND check_key = ?',
                [$r['status'], $r['detail'], $fails, $oks, $episode, $alertId, $now, $r['status'], $now, $dev['device_id'], $r['key']]);
        }
    }

    private static function openAlert(array $dev, array $r, int $episode): int
    {
        $msg = mb_substr("Endpoint agent check '" . $r['key'] . "' " . ($r['status'] === 'warn' ? 'warning' : 'failed') . ' on ' . $dev['hostname']
            . ($r['detail'] !== '' ? ': ' . $r['detail'] : ''), 0, 1000);
        $client = (int) $dev['client_id'];
        $id = Db::insert("INSERT INTO rmm_alerts (asset_id, client_id, integration_id, tactical_alert_id, severity, status, message, raw_data_json, created_at)
            VALUES (?, ?, ?, ?, ?, 'new', ?, ?, NOW()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            [$dev['asset_id'] ?: null, $client ?: null, Config::integrationId(), 'agent:' . $dev['device_id'] . ':' . $r['key'] . ':' . $episode,
                $r['status'] === 'warn' ? 'warning' : 'error', $msg,
                json_encode(['source' => 'rivetit_agent', 'device_id' => (int) $dev['device_id'], 'check' => $r['key'], 'status' => $r['status'], 'episode' => $episode])]);
        return $id;
    }

    private static function resolveAlert(array $dev, int $alertId): void
    {
        global $mysqli;
        $alert = Db::one('SELECT id, status, ticket_id FROM rmm_alerts WHERE id = ?', [$alertId]);
        if (!$alert || $alert['status'] === 'resolved') {
            return;
        }
        Db::run("UPDATE rmm_alerts SET status = 'resolved', resolved_at = NOW() WHERE id = ?", [$alertId]);
        if (!empty($alert['ticket_id'])) {
            require_once dirname(__DIR__, 2) . '/includes/class_rmm_asset_mapper.php';
            (new \RmmAssetMapper($mysqli, Config::integrationId(), 0, null))->autoCloseAlertTicket((int) $alert['ticket_id'], $alertId);
        }
    }

    /** Device retired or removed: resolve everything still open so no orphan alert stays "new". */
    public static function resolveAllOpen(int $deviceId, string $why): void
    {
        $dev = Devices::find($deviceId);
        if (!$dev) {
            return;
        }
        foreach (Db::all('SELECT check_key, alert_id FROM endpoint_agent_checks WHERE device_id = ? AND alert_id IS NOT NULL', [$deviceId]) as $c) {
            self::resolveAlert($dev, (int) $c['alert_id']);
        }
        Db::run('UPDATE endpoint_agent_checks SET alert_id = NULL WHERE device_id = ?', [$deviceId]);
    }
}
