<?php

namespace ITFlow\Integrations\ManageEngine;

use ITFlow\Assets\StaleAssets;

/**
 * Stale-device retirement for MDM-linked assets. OFF by default (manageengine_mdm_integrations.stale_retire_enabled), consistent with the RMM
 * version in ITFlow\Assets\StaleAssets and sharing its review queue (asset_retire_queue: Restore / Confirm under Integrations > RMM > Stale assets).
 *
 * An asset is a candidate when it is not archived, has an MDM link in this connection and NO RMM link (an asset an RMM still manages is the RMM
 * rule's business), and EVERY one of its MDM links is gone: the device left the MDM list ("last_sync" older than N days, refreshed only by a
 * successful complete run) or the MDM says it is removed / unenrolled and it was last heard from more than N days ago. A device that is merely
 * quiet but still enrolled is NEVER retired. Retiring archives the asset (status Retired) and queues it; nothing is deleted. A device that
 * comes back enrolled restores its asset by itself. Called only after a successful run that listed at least one device.
 */
final class MdmStale
{
    public const MAX_PER_RUN = 200;

    public function __construct(private \mysqli $db)
    {
    }

    /** SQL for "when was this link last alive". */
    private const SEEN = "CASE WHEN l.enrollment_status IN ('removed','unenrolled') THEN COALESCE(l.last_contact_at, l.registered_at, l.last_sync) ELSE l.last_sync END";

    /** @return list<array{asset_id:int,client_id:int,asset_name:string,status:?string,last_seen:string}> */
    public function candidates(int $integrationId, int $days, int $limit = self::MAX_PER_RUN): array
    {
        $days = max(1, $days);
        $out = [];
        $res = $this->db->query('SELECT a.asset_id, a.asset_client_id, a.asset_name, a.asset_status, MAX(' . self::SEEN . ") AS seen
            FROM assets a JOIN asset_mdm_links l ON l.asset_id = a.asset_id AND l.integration_id = " . (int) $integrationId . "
            WHERE a.asset_archived_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM asset_rmm_links r WHERE r.asset_id = a.asset_id)
              AND NOT EXISTS (SELECT 1 FROM asset_retire_queue q WHERE q.asset_id = a.asset_id AND q.queue_status = 'pending')
            GROUP BY a.asset_id, a.asset_client_id, a.asset_name, a.asset_status
            HAVING seen IS NOT NULL AND seen < NOW() - INTERVAL $days DAY
            ORDER BY seen ASC LIMIT " . max(1, $limit));
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = ['asset_id' => (int) $r['asset_id'], 'client_id' => (int) $r['asset_client_id'], 'asset_name' => (string) $r['asset_name'], 'status' => $r['asset_status'] === null ? null : (string) $r['asset_status'], 'last_seen' => (string) $r['seen']];
        }

        return $out;
    }

    /** @return array{retired:int,restored:int} */
    public function run(int $integrationId, int $days): array
    {
        $out = ['retired' => 0, 'restored' => 0];
        $out['restored'] = $this->restoreReturned($integrationId, $days);
        $audit = new \ITFlow\Audit\AuditService($this->db);
        foreach ($this->candidates($integrationId, $days) as $c) {
            $prev = $c['status'] === null ? '' : $this->db->real_escape_string($c['status']);
            $seen = $this->db->real_escape_string($c['last_seen']);
            $this->db->query("UPDATE assets SET asset_archived_at = NOW(), asset_status = 'Retired', asset_updated_at = asset_updated_at WHERE asset_id = {$c['asset_id']} AND asset_archived_at IS NULL");
            if ($this->db->affected_rows < 1) {
                continue;
            }
            $this->db->query("INSERT INTO asset_retire_queue (asset_id, client_id, prev_status, last_seen, stale_days) VALUES ({$c['asset_id']}, {$c['client_id']}, '$prev', '$seen', $days)");
            $out['retired']++;
            try {
                $audit->log('asset.auto_retire', null, 'asset', $c['asset_id'], 'retire', "Retired asset {$c['asset_name']}: its MDM device has not been seen since {$c['last_seen']}", ['client_id' => $c['client_id'], 'stale_days' => $days, 'source' => 'manageengine_mdm']);
            } catch (\Throwable) {
            }
        }
        if ($out['retired'] > 0 && function_exists('appNotify')) {
            try {
                appNotify('Stale MDM devices retired', $out['retired'] . ' asset(s) whose MDM device was gone for ' . $days . ' days were retired. Review them in Administration > Integrations > RMM > Stale assets.', '/admin/settings_integrations.php?tab=rmm#stale-assets');
            } catch (\Throwable) {
            }
        }

        return $out;
    }

    /** A retired asset whose MDM device is enrolled and seen again comes back by itself. Never touches an asset an RMM manages. */
    private function restoreReturned(int $integrationId, int $days): int
    {
        $n = 0;
        $stale = new StaleAssets($this->db);
        $res = $this->db->query("SELECT q.queue_id FROM asset_retire_queue q
            WHERE q.queue_status = 'pending'
              AND NOT EXISTS (SELECT 1 FROM asset_rmm_links r WHERE r.asset_id = q.asset_id)
              AND EXISTS (SELECT 1 FROM asset_mdm_links l WHERE l.asset_id = q.asset_id AND l.integration_id = " . (int) $integrationId . "
                          AND l.enrollment_status = 'enrolled' AND l.last_sync >= NOW() - INTERVAL $days DAY) LIMIT " . self::MAX_PER_RUN);
        while ($res && ($r = $res->fetch_assoc())) {
            if ($stale->restore((int) $r['queue_id'], 0)) {
                $n++;
            }
        }

        return $n;
    }
}
