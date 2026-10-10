<?php

declare(strict_types=1);

namespace ITFlow\Integrations;

/**
 * Per-integration client mapping: which RivetIT department an RMM's own client / group name belongs to.
 *
 * resolve() is what a sync calls for every device. Order: a saved mapping for this integration, then the exact (case-insensitive)
 * name match the syncs always used, then the "needs mapping" queue. A name that matches nothing is no longer skipped silently: it is
 * queued once (with how many devices carry it and one example), and an administrator maps it to a department or marks it ignored
 * (Administration > Integrations > RMM). The next sync uses the answer.
 */
final class ClientMap
{
    /** @var array<string,array{client_id:int,source:string}> */
    private array $cache = [];

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * @return array{client_id:int,source:'mapped'|'name'|'ignored'|'pending'}
     */
    public function resolve(int $integrationId, string $externalName, string $sampleHost = ''): array
    {
        $externalName = trim($externalName);
        if ($externalName === '' || $integrationId < 1) {
            return ['client_id' => 0, 'source' => 'pending'];
        }
        $key = $integrationId . '|' . mb_strtolower($externalName);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $n = $this->db->real_escape_string($externalName);
        $res = $this->db->query("SELECT map_id, client_id, map_status FROM integration_client_map WHERE integration_id = $integrationId AND external_name = '$n' LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if ($row) {
            $status = (string) $row['map_status'];
            if ($status === 'mapped') {
                $cid = (int) $row['client_id'];
                if ($cid > 0 && $this->clientExists($cid)) {
                    $this->touch((int) $row['map_id'], $sampleHost);

                    return $this->cache[$key] = ['client_id' => $cid, 'source' => 'mapped'];
                }
                // The department it pointed at is gone or archived: ask again instead of dropping devices unseen.
                $this->db->query('UPDATE integration_client_map SET map_status = \'pending\', client_id = NULL WHERE map_id = ' . (int) $row['map_id']);
                $status = 'pending';
            }
            $this->touch((int) $row['map_id'], $sampleHost);

            return ['client_id' => 0, 'source' => $status === 'ignored' ? 'ignored' : 'pending'];   // not cached: every device counts toward "seen"
        }
        // No saved mapping: the exact-name match every sync has always used.
        $res = $this->db->query("SELECT client_id FROM clients WHERE LOWER(client_name) = LOWER('$n') AND client_archived_at IS NULL LIMIT 1");
        $c = $res ? $res->fetch_row() : null;
        if ($c) {
            return $this->cache[$key] = ['client_id' => (int) $c[0], 'source' => 'name'];
        }
        $h = $this->db->real_escape_string(mb_substr($sampleHost, 0, 200));
        $this->db->query("INSERT INTO integration_client_map (integration_id, external_name, map_status, sample_host) VALUES ($integrationId, '$n', 'pending', " . ($h === '' ? 'NULL' : "'$h'") . ')
            ON DUPLICATE KEY UPDATE seen_count = seen_count + 1, last_seen_at = NOW()');

        return ['client_id' => 0, 'source' => 'pending'];
    }

    private function touch(int $mapId, string $sampleHost): void
    {
        $h = $this->db->real_escape_string(mb_substr($sampleHost, 0, 200));
        $this->db->query("UPDATE integration_client_map SET seen_count = seen_count + 1, last_seen_at = NOW()" . ($h !== '' ? ", sample_host = COALESCE(sample_host, '$h')" : '') . " WHERE map_id = $mapId");
    }

    private function clientExists(int $clientId): bool
    {
        $r = $this->db->query("SELECT 1 FROM clients WHERE client_id = $clientId AND client_archived_at IS NULL");

        return (bool) ($r && $r->fetch_row());
    }

    // ------------------------------------------------------------------------------------------------------- the queue

    /** @return list<array<string,mixed>> */
    public function rows(string $status): array
    {
        if (!in_array($status, ['pending', 'mapped', 'ignored'], true)) {
            return [];
        }
        $out = [];
        $res = $this->db->query("SELECT m.*, i.name AS integration_name, c.client_name FROM integration_client_map m LEFT JOIN rmm_integrations i ON i.id = m.integration_id
            LEFT JOIN clients c ON c.client_id = m.client_id WHERE m.map_status = '$status' ORDER BY m.last_seen_at DESC, m.map_id DESC LIMIT 500");
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }

        return $out;
    }

    public function pendingCount(): int
    {
        $r = @$this->db->query("SELECT COUNT(*) FROM integration_client_map WHERE map_status = 'pending'");
        $x = $r ? $r->fetch_row() : null;

        return $x ? (int) $x[0] : 0;
    }

    /** @return array{ok:bool,message:string} */
    public function assign(int $mapId, int $clientId): array
    {
        if (!$this->clientExists($clientId)) {
            return ['ok' => false, 'message' => 'Pick a department that exists and is not archived.'];
        }
        $this->db->query("UPDATE integration_client_map SET client_id = $clientId, map_status = 'mapped', last_seen_at = last_seen_at WHERE map_id = $mapId");

        return $this->db->affected_rows > 0 ? ['ok' => true, 'message' => 'Mapped. The next sync uses it.'] : ['ok' => false, 'message' => 'That entry no longer exists.'];
    }

    /** @return array{ok:bool,message:string} */
    public function ignore(int $mapId): array
    {
        $this->db->query("UPDATE integration_client_map SET client_id = NULL, map_status = 'ignored', last_seen_at = last_seen_at WHERE map_id = $mapId");

        return $this->db->affected_rows > 0 ? ['ok' => true, 'message' => 'Ignored. Devices under that name are skipped on purpose.'] : ['ok' => false, 'message' => 'That entry no longer exists.'];
    }

    /** Put a mapped or ignored entry back in the queue. */
    public function reopen(int $mapId): void
    {
        $this->db->query("UPDATE integration_client_map SET client_id = NULL, map_status = 'pending', last_seen_at = last_seen_at WHERE map_id = $mapId");
    }
}
