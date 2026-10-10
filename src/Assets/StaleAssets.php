<?php

declare(strict_types=1);

namespace ITFlow\Assets;

use ITFlow\Audit\AuditService;
use ITFlow\Platform\PlatformSettings;

/**
 * Auto-retire of stale RMM-linked assets. OFF by default (platform setting stale_asset_retire_enabled). When on, an asset whose RMM
 * links have all been silent for N days (stale_asset_retire_days) is retired - archived with status "Retired" - and put in a review
 * queue (asset_retire_queue) so a person can restore it or confirm it. A device that reports in again is restored by the next run.
 * Nothing here deletes anything.
 */
final class StaleAssets
{
    public const MAX_PER_RUN = 200;

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * Assets that are not archived, are linked to an RMM, and were last seen more than $days days ago on EVERY link. Pure read.
     *
     * @return list<array{asset_id:int,client_id:int,asset_name:string,status:?string,last_seen:string}>
     */
    public function candidates(int $days, int $limit = self::MAX_PER_RUN): array
    {
        $days = max(1, $days);
        $out = [];
        $res = $this->db->query("SELECT a.asset_id, a.asset_client_id, a.asset_name, a.asset_status, MAX(COALESCE(l.last_seen, l.last_sync)) AS seen
            FROM assets a JOIN asset_rmm_links l ON l.asset_id = a.asset_id
            WHERE a.asset_archived_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM asset_retire_queue q WHERE q.asset_id = a.asset_id AND q.queue_status = 'pending')
            GROUP BY a.asset_id, a.asset_client_id, a.asset_name, a.asset_status
            HAVING seen IS NOT NULL AND seen < NOW() - INTERVAL $days DAY
            ORDER BY seen ASC LIMIT " . max(1, $limit));
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = ['asset_id' => (int) $r['asset_id'], 'client_id' => (int) $r['asset_client_id'], 'asset_name' => (string) $r['asset_name'], 'status' => $r['asset_status'] === null ? null : (string) $r['asset_status'], 'last_seen' => (string) $r['seen']];
        }

        return $out;
    }

    /** @return array{enabled:bool,retired:int,restored:int} */
    public function run(): array
    {
        $out = ['enabled' => PlatformSettings::bool($this->db, 'stale_asset_retire_enabled'), 'retired' => 0, 'restored' => 0];
        if (!$out['enabled']) {
            return $out;
        }
        $days = PlatformSettings::int($this->db, 'stale_asset_retire_days', 7, 3650);
        $out['restored'] = $this->restoreReturned($days);
        $audit = new AuditService($this->db);
        foreach ($this->candidates($days) as $c) {
            $prev = $c['status'] === null ? '' : $this->db->real_escape_string($c['status']);
            $seen = $this->db->real_escape_string($c['last_seen']);
            $this->db->query("UPDATE assets SET asset_archived_at = NOW(), asset_status = 'Retired', asset_updated_at = asset_updated_at WHERE asset_id = {$c['asset_id']} AND asset_archived_at IS NULL");
            if ($this->db->affected_rows < 1) {
                continue;
            }
            $this->db->query("INSERT INTO asset_retire_queue (asset_id, client_id, prev_status, last_seen, stale_days) VALUES ({$c['asset_id']}, {$c['client_id']}, '$prev', '$seen', $days)");
            $out['retired']++;
            try {
                $audit->log('asset.auto_retire', null, 'asset', $c['asset_id'], 'retire', "Retired stale asset {$c['asset_name']} (not seen since {$c['last_seen']})", ['client_id' => $c['client_id'], 'stale_days' => $days]);
            } catch (\Throwable $e) {
                // auditing never stops the run
            }
        }
        if ($out['retired'] > 0 && function_exists('appNotify')) {
            try {
                appNotify('Stale assets retired', $out['retired'] . ' asset(s) with an RMM agent silent for ' . $days . ' days were retired. Review them in Administration > Integrations > RMM.', '/admin/settings_integrations.php?tab=rmm#stale-assets');
            } catch (\Throwable $e) {
                // the queue itself is the record
            }
        }

        return $out;
    }

    /** A retired asset whose agent reported in again within the window comes back by itself. */
    private function restoreReturned(int $days): int
    {
        $n = 0;
        $res = $this->db->query("SELECT q.queue_id, q.asset_id FROM asset_retire_queue q WHERE q.queue_status = 'pending'
            AND (SELECT MAX(COALESCE(l.last_seen, l.last_sync)) FROM asset_rmm_links l WHERE l.asset_id = q.asset_id) >= NOW() - INTERVAL $days DAY LIMIT " . self::MAX_PER_RUN);
        while ($res && ($r = $res->fetch_assoc())) {
            $this->restoreAsset((int) $r['queue_id'], (int) $r['asset_id'], 0);
            $n++;
        }

        return $n;
    }

    /** @return list<array<string,mixed>> */
    public function pending(): array
    {
        $out = [];
        $res = $this->db->query("SELECT q.*, a.asset_name, c.client_name FROM asset_retire_queue q JOIN assets a ON a.asset_id = q.asset_id LEFT JOIN clients c ON c.client_id = q.client_id
            WHERE q.queue_status = 'pending' ORDER BY q.created_at DESC LIMIT 500");
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }

        return $out;
    }

    /** Put the asset back (unarchived, previous status) and close the queue entry. */
    public function restore(int $queueId, int $byUserId): bool
    {
        $res = $this->db->query("SELECT asset_id FROM asset_retire_queue WHERE queue_id = $queueId AND queue_status = 'pending'");
        $row = $res ? $res->fetch_row() : null;
        if (!$row) {
            return false;
        }
        $this->restoreAsset($queueId, (int) $row[0], $byUserId);

        return true;
    }

    /** The review says "yes, retire it": the entry is closed, the asset stays archived. */
    public function confirm(int $queueId, int $byUserId): bool
    {
        $this->db->query("UPDATE asset_retire_queue SET queue_status = 'confirmed', decided_at = NOW(), decided_by = $byUserId WHERE queue_id = $queueId AND queue_status = 'pending'");

        return $this->db->affected_rows > 0;
    }

    private function restoreAsset(int $queueId, int $assetId, int $byUserId): void
    {
        $res = $this->db->query("SELECT prev_status FROM asset_retire_queue WHERE queue_id = $queueId");
        $row = $res ? $res->fetch_row() : null;
        $prev = $row && $row[0] !== null && $row[0] !== '' ? "'" . $this->db->real_escape_string((string) $row[0]) . "'" : "'Active'";
        $this->db->query("UPDATE assets SET asset_archived_at = NULL, asset_status = $prev, asset_updated_at = asset_updated_at WHERE asset_id = $assetId");
        $this->db->query("UPDATE asset_retire_queue SET queue_status = 'restored', decided_at = NOW(), decided_by = $byUserId WHERE queue_id = $queueId");
    }
}
