<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;

/**
 * RivetIT's RMM tables for the endpoint agent module: the synthetic `rmm_integrations` row, one `asset_rmm_links` row per linked asset (this
 * feeds the asset page RMM card, the RMM dashboard and the asset_offline / asset_online automations), `rmm_alerts`, the saved
 * `rmm_scripts` library and the `rmm_remote_sessions` log. The statements are the ones the old ITFlow\EndpointAgent classes ran.
 *
 * It must run on the same connection as the DatabaseInterface the module is given, so these writes join the module's transactions; the
 * ticket auto-close of a resolved alert (RmmAssetMapper) uses that connection too.
 */
final class EndpointBridge implements RmmBridgeInterface
{
    public function __construct(private DatabaseInterface $database, private \mysqli $mysqli)
    {
    }

    public function ensureIntegration(string $type, string $name): int
    {
        $row = $this->database->fetchOne('SELECT id FROM rmm_integrations WHERE type = ? ORDER BY id LIMIT 1', [$type]);
        if ($row !== null) {
            return (int) $row['id'];
        }

        return (int) $this->database->execute("INSERT INTO rmm_integrations (name, type, api_url, web_url, api_key_enc, enabled) VALUES (?, ?, '', '', '', 1)", [$name, $type])->insertId;
    }

    public function integrationExists(int $integrationId, string $type): bool
    {
        return $this->database->fetchOne('SELECT id FROM rmm_integrations WHERE id = ? AND type = ?', [$integrationId, $type]) !== null;
    }

    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void
    {
        // The device moved to another asset: drop the link it had on the old one.
        $this->database->execute('DELETE FROM asset_rmm_links WHERE integration_id = ? AND tactical_agent_id = ? AND asset_id <> ?', [$integrationId, $agentKey, $assetId]);
        $existing = $this->database->fetchOne('SELECT id FROM asset_rmm_links WHERE asset_id = ? AND integration_id = ?', [$assetId, $integrationId]);
        $vals = [$agentKey, $facts['hostname'], $facts['os_name'], $facts['os_version'], (string) $facts['manufacturer'], (string) $facts['model']];
        if ($existing !== null) {
            $this->database->execute(
                'UPDATE asset_rmm_links SET tactical_agent_id = ?, hostname = ?, os_name = ?, os_version = ?, manufacturer = ?, model = ?, last_sync = NOW() WHERE id = ?',
                array_merge($vals, [(int) $existing['id']])
            );
        } else {
            $this->database->execute(
                "INSERT INTO asset_rmm_links (asset_id, integration_id, tactical_agent_id, hostname, os_name, os_version, manufacturer, model, rmm_status, last_sync)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unknown', NOW())",
                array_merge([$assetId, $integrationId], $vals)
            );
        }
    }

    public function removeLink(int $integrationId, string $agentKey): void
    {
        $this->database->execute('DELETE FROM asset_rmm_links WHERE integration_id = ? AND tactical_agent_id = ?', [$integrationId, $agentKey]);
    }

    public function applyHealth(int $integrationId, int $assetId, array $health): bool
    {
        $link = $this->database->fetchOne('SELECT id, rmm_status FROM asset_rmm_links WHERE asset_id = ? AND integration_id = ?', [$assetId, $integrationId]);
        if ($link === null) {
            return false;
        }
        $changed = $link['rmm_status'] !== 'online' ? 'rmm_status_changed_at = NOW(),' : '';
        $this->database->execute(
            "UPDATE asset_rmm_links SET $changed rmm_status = 'online', last_seen = NOW(), last_sync = NOW(), hostname = ?, os_name = 'Windows', os_version = ?,
             manufacturer = ?, model = ?, cpu = ?, ram_gb = ?, logged_in_user = ?, rmm_cpu_percent = ?, rmm_ram_percent = ?, rmm_disk_percent = ?, rmm_needs_reboot = ?,
             rmm_last_boot = ?, rmm_health_updated_at = NOW() WHERE id = ?",
            [$health['hostname'], $health['os_version'], (string) $health['manufacturer'], (string) $health['model'], $health['cpu'], $health['ram_gb'], (string) $health['logged_in_user'],
                $health['cpu_pct'], $health['ram_pct'], $health['disk_pct'], (int) $health['needs_reboot'], $health['last_boot'], (int) $link['id']]
        );

        return true;
    }

    public function markOffline(int $integrationId, array $agentKeys): int
    {
        if ($agentKeys === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($agentKeys), '?'));

        return $this->database->execute(
            "UPDATE asset_rmm_links SET rmm_status = 'offline', rmm_status_changed_at = NOW() WHERE integration_id = ? AND rmm_status = 'online' AND tactical_agent_id IN ($in)",
            array_merge([$integrationId], array_values($agentKeys))
        )->affectedRows;
    }

    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int
    {
        return (int) $this->database->execute(
            "INSERT INTO rmm_alerts (asset_id, client_id, integration_id, tactical_alert_id, severity, status, message, raw_data_json, created_at)
             VALUES (?, ?, ?, ?, ?, 'new', ?, ?, NOW()) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            [$assetId, $clientId > 0 ? $clientId : null, $integrationId, $alertKey, $severity, $message, json_encode($raw)]
        )->insertId;
    }

    public function resolveAlert(int $integrationId, int $alertId): void
    {
        $alert = $this->database->fetchOne('SELECT id, status, ticket_id FROM rmm_alerts WHERE id = ?', [$alertId]);
        if ($alert === null || $alert['status'] === 'resolved') {
            return;
        }
        $this->database->execute("UPDATE rmm_alerts SET status = 'resolved', resolved_at = NOW() WHERE id = ?", [$alertId]);
        if (!empty($alert['ticket_id'])) {
            require_once dirname(__DIR__, 4) . '/includes/class_rmm_asset_mapper.php';
            (new \RmmAssetMapper($this->mysqli, $integrationId, 0, null))->autoCloseAlertTicket((int) $alert['ticket_id'], $alertId);
        }
    }

    public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void
    {
        $this->database->execute('UPDATE rmm_alerts SET client_id = ? WHERE asset_id = ? AND integration_id = ?', [$clientId, $assetId, $integrationId]);
    }

    public function savedPowerShellScript(int $scriptId): ?string
    {
        $row = $this->database->fetchOne('SELECT script_body, script_type FROM rmm_scripts WHERE id = ? AND enabled = 1', [$scriptId]);
        if ($row === null || strtolower((string) $row['script_type']) !== 'powershell' || trim((string) $row['script_body']) === '') {
            return null;
        }

        return (string) $row['script_body'];
    }

    public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void
    {
        $this->database->execute(
            'INSERT INTO rmm_remote_sessions (asset_id, client_id, user_id, connection_type, connection_url, source_ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
            [$assetId, $clientId, $userId, $connectionType, $reference, substr((string) $ipAddress, 0, 100), substr((string) $userAgent, 0, 300)]
        );
    }
}
