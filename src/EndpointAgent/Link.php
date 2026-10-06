<?php

namespace ITFlow\EndpointAgent;

/**
 * The bridge from an agent device to the EXISTING RMM data model: one asset_rmm_links row per linked asset (integration_id = the
 * synthetic "RivetIT Endpoint Agent" integration). That row feeds the asset page RMM card, the RMM assets/dashboard views and the
 * automation triggers (asset_offline / asset_online) exactly like a Tactical or Level link. This class never touches the other
 * integrations' rows.
 */
final class Link
{
    public static function agentKey(int $deviceId): string
    {
        return 'rivetit:' . $deviceId;
    }

    /** Create or update the link row for a device that is linked to an asset. */
    public static function ensure(int $deviceId, int $assetId): void
    {
        $dev = Db::one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        if (!$dev) {
            return;
        }
        $intg = Config::integrationId();
        // The device moved to another asset: drop the link it had on the old one.
        Db::run('DELETE FROM asset_rmm_links WHERE integration_id = ? AND tactical_agent_id = ? AND asset_id <> ?', [$intg, self::agentKey($deviceId), $assetId]);
        $existing = Db::one('SELECT id FROM asset_rmm_links WHERE asset_id = ? AND integration_id = ?', [$assetId, $intg]);
        $vals = [self::agentKey($deviceId), $dev['hostname'], 'Windows', $dev['os_version'], (string) $dev['manufacturer'], (string) $dev['model']];
        if ($existing) {
            Db::run("UPDATE asset_rmm_links SET tactical_agent_id = ?, hostname = ?, os_name = ?, os_version = ?, manufacturer = ?, model = ?, last_sync = NOW() WHERE id = ?",
                array_merge($vals, [$existing['id']]));
        } else {
            Db::run("INSERT INTO asset_rmm_links (asset_id, integration_id, tactical_agent_id, hostname, os_name, os_version, manufacturer, model, rmm_status, last_sync)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unknown', NOW())", array_merge([$assetId, $intg], $vals));
        }
        // Fill blanks on the asset only; never overwrite what a human typed.
        Db::run("UPDATE assets SET asset_serial = IF(asset_serial IS NULL OR asset_serial = '', ?, asset_serial), asset_model = IF(asset_model IS NULL OR asset_model = '', ?, asset_model),
            asset_make = IF(asset_make = '', ?, asset_make), asset_os = IF(asset_os IS NULL OR asset_os = '', ?, asset_os) WHERE asset_id = ?",
            [$dev['serial'], $dev['model'], (string) $dev['manufacturer'], 'Windows ' . $dev['os_version'], $assetId]);
    }

    public static function drop(int $deviceId): void
    {
        Db::run('DELETE FROM asset_rmm_links WHERE integration_id = ? AND tactical_agent_id = ?', [Config::integrationId(), self::agentKey($deviceId)]);
    }

    /**
     * Push a check-in's live data into the link row.
     *
     * @param array<string,mixed> $dev the device row after this check-in's inventory was applied
     * @param array{cpu:?float,mem:?float,disk:?float} $health percentages, null stays null (the link columns are nullable)
     */
    public static function applyCheckin(array $dev, array $health): void
    {
        if (empty($dev['asset_id']) || $dev['link_state'] !== 'linked') {
            return;
        }
        $intg = Config::integrationId();
        $link = Db::one('SELECT id, rmm_status FROM asset_rmm_links WHERE asset_id = ? AND integration_id = ?', [$dev['asset_id'], $intg]);
        if (!$link) {
            self::ensure((int) $dev['device_id'], (int) $dev['asset_id']);
            $link = Db::one('SELECT id, rmm_status FROM asset_rmm_links WHERE asset_id = ? AND integration_id = ?', [$dev['asset_id'], $intg]);
            if (!$link) {
                return;
            }
        }
        $changed = $link['rmm_status'] !== 'online' ? 'rmm_status_changed_at = NOW(),' : '';
        $boot = ($dev['uptime_s'] !== null && (int) $dev['uptime_s'] >= 0) ? date('Y-m-d H:i:s', time() - (int) $dev['uptime_s']) : null;
        $cpuStr = '';
        $ramGb = '';
        $inv = $dev['inventory_json'] ? json_decode((string) $dev['inventory_json'], true) : null;
        if (is_array($inv)) {
            $cpuStr = mb_substr((string) ($inv['cpu']['model'] ?? ''), 0, 300);
            if (!empty($inv['memory_total_bytes']) && is_numeric($inv['memory_total_bytes'])) {
                $ramGb = (string) round(((float) $inv['memory_total_bytes']) / 1073741824, 1);
            }
        }
        $r = static fn(?float $v) => $v === null ? null : (int) round($v);
        Db::run("UPDATE asset_rmm_links SET $changed rmm_status = 'online', last_seen = NOW(), last_sync = NOW(), hostname = ?, os_name = 'Windows', os_version = ?,
            manufacturer = ?, model = ?, cpu = ?, ram_gb = ?, logged_in_user = ?, rmm_cpu_percent = ?, rmm_ram_percent = ?, rmm_disk_percent = ?, rmm_needs_reboot = ?,
            rmm_last_boot = ?, rmm_health_updated_at = NOW() WHERE id = ?",
            [$dev['hostname'], $dev['os_version'], (string) $dev['manufacturer'], (string) $dev['model'], $cpuStr, $ramGb, (string) $dev['logged_in_user'],
                $r($health['cpu']), $r($health['mem']), $r($health['disk']), (int) ($dev['pending_reboot'] ?? 0), $boot, $link['id']]);
    }
}
