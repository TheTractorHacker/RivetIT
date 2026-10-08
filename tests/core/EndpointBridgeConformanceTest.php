<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Testing\RmmBridgeConformanceTestCase;

/** EndpointBridge over rmm_integrations, asset_rmm_links, rmm_alerts, rmm_scripts and rmm_remote_sessions (same connection as the module). */
final class EndpointBridgeConformanceTest extends RmmBridgeConformanceTestCase
{
    protected function bridge(): RmmBridgeInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointBridge(EndpointKit::db(), EndpointKit::mysqli());
    }

    protected function createAsset(): int
    {
        return (int) EndpointKit::db()->execute("INSERT INTO assets SET asset_type = 'Laptop', asset_name = ?, asset_status = 'Active', asset_client_id = 0, asset_created_at = NOW()", ['conf-' . bin2hex(random_bytes(3))])->insertId;
    }

    protected function readLink(int $integrationId, string $agentKey): ?array
    {
        $r = EndpointKit::db()->fetchOne('SELECT asset_id, rmm_status, rmm_status_changed_at FROM asset_rmm_links WHERE integration_id = ? AND tactical_agent_id = ?', [$integrationId, $agentKey]);

        return $r === null ? null : ['asset_id' => (int) $r['asset_id'], 'status' => (string) $r['rmm_status'], 'status_changed_at' => $r['rmm_status_changed_at'] === null ? null : (string) $r['rmm_status_changed_at']];
    }

    protected function backdateStatusChange(int $integrationId, string $agentKey): void
    {
        EndpointKit::db()->execute("UPDATE asset_rmm_links SET rmm_status_changed_at = '2000-01-01 00:00:00' WHERE integration_id = ? AND tactical_agent_id = ?", [$integrationId, $agentKey]);
    }

    protected function readAlert(int $alertId): ?array
    {
        $r = EndpointKit::db()->fetchOne('SELECT status, client_id, asset_id, severity FROM rmm_alerts WHERE id = ?', [$alertId]);

        return $r === null ? null : ['status' => (string) $r['status'], 'client_id' => (int) $r['client_id'], 'asset_id' => $r['asset_id'] === null ? null : (int) $r['asset_id'], 'severity' => (string) $r['severity']];
    }

    protected function countAlerts(int $integrationId, string $alertKey): int
    {
        return (int) (EndpointKit::db()->fetchOne('SELECT COUNT(*) AS c FROM rmm_alerts WHERE integration_id = ? AND tactical_alert_id = ?', [$integrationId, $alertKey])['c'] ?? 0);
    }

    protected function createScript(string $body, bool $powershell, bool $enabled): int
    {
        return (int) EndpointKit::db()->execute('INSERT INTO rmm_scripts SET name = ?, script_type = ?, script_body = ?, enabled = ?', ['conf-' . bin2hex(random_bytes(3)), $powershell ? 'powershell' : 'shell', $body, $enabled ? 1 : 0])->insertId;
    }

    protected function readRemoteSessions(int $assetId): array
    {
        return array_map(static fn (array $r): array => [
            'client_id' => (int) $r['client_id'], 'user_id' => (int) $r['user_id'], 'connection_type' => (string) $r['connection_type'], 'reference' => (string) $r['connection_url'],
            'ip_address' => $r['source_ip'] === null ? null : (string) $r['source_ip'], 'user_agent' => $r['user_agent'] === null ? null : (string) $r['user_agent'],
        ], EndpointKit::db()->fetchAll('SELECT * FROM rmm_remote_sessions WHERE asset_id = ? ORDER BY id', [$assetId]));
    }
}
