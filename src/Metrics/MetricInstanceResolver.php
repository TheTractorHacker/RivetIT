<?php

namespace ITFlow\Metrics;

/**
 * Maps (asset_id, metric_dim, instance_key) onto a device_metric_instances.instance_id,
 * creating the row the first time a dimension member is ever seen and keeping
 * last_seen_at current afterwards.
 *
 * Two things this class exists to guarantee:
 *
 *  1. Host-level samples cost ZERO database round trips. instance_id 0 is the
 *     reserved host-level sentinel and deliberately has NO row in
 *     device_metric_instances, so resolve() short-circuits before touching the DB.
 *
 *  2. A collection run that produces 40 samples for one device does not issue 40
 *     lookups. primeAssets() pulls every known instance for the whole run in one
 *     query, and per-run caching serves everything after that. Only a genuinely
 *     new dimension member (a disk that was just plugged in, a NIC that just
 *     appeared) causes a write.
 *
 * TIME CONVENTION: first_seen_at / last_seen_at are stored in UTC, matching
 * device_metric_samples.sampled_at. This is a deliberate divergence from the rest
 * of the application, which stores local time. It is done so that "last seen"
 * lines up exactly with the sample timestamps it is derived from - mixing the two
 * clocks in one subsystem would make every comparison silently wrong by the
 * server's UTC offset. Anything that RENDERS these columns must convert.
 *
 * This class never creates, updates or deletes an asset or an asset_rmm_links row.
 * Those are owned exclusively by includes/class_rmm_asset_mapper.php.
 */
class MetricInstanceResolver
{
    /** Reserved host-level sentinel. No row exists in device_metric_instances for it. */
    public const HOST_INSTANCE_ID = 0;

    /** Returned when a genuinely dimensioned sample could not be resolved (DB error). */
    public const RESOLVE_FAILED = -1;

    /** device_metric_instances.instance_key is VARCHAR(96). */
    public const MAX_KEY_LENGTH = 96;

    /** device_metric_instances.instance_label is VARCHAR(128). */
    public const MAX_LABEL_LENGTH = 128;

    /** device_metric_instances.metric_dim is VARCHAR(16). */
    public const MAX_DIM_LENGTH = 16;

    private const EPOCH = '1970-01-01 00:00:00';
    private const CHUNK = 500;

    private \mysqli $mysqli;

    /** @var array<string,int> "assetId|dim|key" => instance_id */
    private array $cache = [];

    /** @var array<int,bool> asset ids whose full instance set is already in $cache */
    private array $primed = [];

    /** @var array<string,array<int,bool>> 'Y-m-d H:i:s' (UTC) => [instance_id => true] */
    private array $pendingSeen = [];

    private int $queries = 0;
    private int $created = 0;
    private int $hits = 0;
    private int $misses = 0;
    private ?string $lastError = null;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Load every existing instance row for the given assets into the cache in one
     * query per 500 assets. Call this once at the top of a run with the distinct
     * asset ids in the batch; every subsequent resolve() for a known instance is
     * then served from memory.
     *
     * @param array<int|string> $assetIds
     * @return int number of instance rows loaded into the cache
     */
    public function primeAssets(array $assetIds): int
    {
        $wanted = [];
        foreach ($assetIds as $assetId) {
            $assetId = (int) $assetId;
            if ($assetId > 0 && !isset($this->primed[$assetId])) {
                $wanted[$assetId] = $assetId;
            }
        }
        if (!$wanted) {
            return 0;
        }

        $loaded = 0;
        foreach (array_chunk(array_values($wanted), self::CHUNK) as $chunk) {
            $sql = "SELECT `instance_id`, `asset_id`, `metric_dim`, `instance_key`
                    FROM `device_metric_instances`
                    WHERE `asset_id` IN (" . implode(',', $chunk) . ")";
            $this->queries++;
            $res = $this->mysqli->query($sql);
            if ($res === false) {
                $this->lastError = $this->mysqli->error;
                continue; // leave these assets unprimed so resolve() falls back to per-instance upserts
            }
            while ($row = $res->fetch_assoc()) {
                $key = $this->cacheKey(
                    (int) $row['asset_id'],
                    (string) ($row['metric_dim'] ?? ''),
                    (string) ($row['instance_key'] ?? '')
                );
                $this->cache[$key] = (int) $row['instance_id'];
                $loaded++;
            }
            $res->free();
            foreach ($chunk as $assetId) {
                $this->primed[$assetId] = true;
            }
        }

        return $loaded;
    }

    /**
     * Resolve one sample's instance.
     *
     * Host-level (no dimension, or no instance key) returns HOST_INSTANCE_ID with no
     * database access at all. A dimensioned instance is looked up in the cache, then
     * upserted if unseen. Returns RESOLVE_FAILED if the database refused the write,
     * so the caller can reject the sample rather than mis-filing it under the host.
     *
     * @param string|null $seenAtUtc 'Y-m-d H:i:s' in UTC; defaults to now (UTC).
     */
    public function resolve(
        int $assetId,
        ?string $dim,
        ?string $instanceKey,
        ?string $instanceLabel = null,
        ?string $seenAtUtc = null
    ): int {
        // Host-level short circuit: no dimension or no member key means the sample
        // describes the whole device. Sentinel 0, no query, ever.
        if ($dim === null || $dim === '' || $instanceKey === null || $instanceKey === '') {
            return self::HOST_INSTANCE_ID;
        }
        if ($assetId <= 0) {
            return self::RESOLVE_FAILED;
        }

        $dim = $this->truncate($dim, self::MAX_DIM_LENGTH);
        $instanceKey = $this->truncate($instanceKey, self::MAX_KEY_LENGTH);
        $label = ($instanceLabel === null || $instanceLabel === '')
            ? null
            : $this->truncate($instanceLabel, self::MAX_LABEL_LENGTH);

        // sampled_at and last_seen_at share the UTC clock - see the class docblock.
        $seenAt = $this->normalizeSeenAt($seenAtUtc);

        $cacheKey = $this->cacheKey($assetId, $dim, $instanceKey);
        if (isset($this->cache[$cacheKey])) {
            $this->hits++;
            $instanceId = $this->cache[$cacheKey];
            $this->markSeen($instanceId, $seenAt);
            return $instanceId;
        }

        $this->misses++;
        $instanceId = $this->upsert($assetId, $dim, $instanceKey, $label, $seenAt);
        if ($instanceId === self::RESOLVE_FAILED) {
            return self::RESOLVE_FAILED;
        }

        $this->cache[$cacheKey] = $instanceId;
        // The upsert already wrote last_seen_at, so nothing to queue for flush().
        return $instanceId;
    }

    /**
     * Write the queued seen-at values for instances that were served from cache.
     * One UPDATE per distinct timestamp per 500 ids.
     *
     * GREATEST() keeps last_seen_at monotonic so backfilling older history never
     * drags it backwards, and LEAST() lets a genuine backfill pull first_seen_at
     * earlier. Both bounds are applied here as well as in upsert() so an instance
     * behaves identically whether it was created this run or served from cache.
     *
     * @return int rows actually changed
     */
    public function flush(): int
    {
        $updated = 0;
        foreach ($this->pendingSeen as $seenAt => $idMap) {
            $seenAtSql = $this->mysqli->real_escape_string((string) $seenAt);
            foreach (array_chunk(array_keys($idMap), self::CHUNK) as $chunk) {
                $ids = implode(',', array_map('intval', $chunk));
                $sql = "UPDATE `device_metric_instances`
                        SET `last_seen_at`  = GREATEST(COALESCE(`last_seen_at`,'" . self::EPOCH . "'),'{$seenAtSql}'),
                            `first_seen_at` = LEAST(COALESCE(`first_seen_at`,'{$seenAtSql}'),'{$seenAtSql}')
                        WHERE `instance_id` IN ({$ids})";
                $this->queries++;
                if ($this->mysqli->query($sql)) {
                    $affected = $this->mysqli->affected_rows;
                    if ($affected > 0) {
                        $updated += (int) $affected;
                    }
                } else {
                    $this->lastError = $this->mysqli->error;
                }
            }
        }
        $this->pendingSeen = [];
        return $updated;
    }

    /** Drop all cached state. Long-lived CLI processes should call this between runs. */
    public function reset(): void
    {
        $this->cache = [];
        $this->primed = [];
        $this->pendingSeen = [];
        $this->queries = 0;
        $this->created = 0;
        $this->hits = 0;
        $this->misses = 0;
        $this->lastError = null;
    }

    /** @return array{queries:int,created:int,cache_hits:int,cache_misses:int,cached:int} */
    public function stats(): array
    {
        return [
            'queries'      => $this->queries,
            'created'      => $this->created,
            'cache_hits'   => $this->hits,
            'cache_misses' => $this->misses,
            'cached'       => count($this->cache),
        ];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Create-or-touch a single instance row and return its id.
     *
     * LAST_INSERT_ID(instance_id) in the duplicate branch makes insert_id report the
     * EXISTING id on a collision, so this is one round trip in both directions. A
     * SELECT fallback covers the case where the duplicate branch changed nothing and
     * the driver reports no insert id.
     */
    private function upsert(int $assetId, string $dim, string $instanceKey, ?string $label, string $seenAt): int
    {
        $dimSql   = $this->mysqli->real_escape_string($dim);
        $keySql   = $this->mysqli->real_escape_string($instanceKey);
        $seenSql  = $this->mysqli->real_escape_string($seenAt);
        $labelSql = $label === null ? 'NULL' : "'" . $this->mysqli->real_escape_string($label) . "'";

        $sql = "INSERT INTO `device_metric_instances`
                    (`asset_id`,`metric_dim`,`instance_key`,`instance_label`,`first_seen_at`,`last_seen_at`)
                VALUES ({$assetId},'{$dimSql}','{$keySql}',{$labelSql},'{$seenSql}','{$seenSql}')
                ON DUPLICATE KEY UPDATE
                    `instance_id`    = LAST_INSERT_ID(`instance_id`),
                    `first_seen_at`  = LEAST(COALESCE(`first_seen_at`, VALUES(`first_seen_at`)), VALUES(`first_seen_at`)),
                    `last_seen_at`   = GREATEST(COALESCE(`last_seen_at`,'" . self::EPOCH . "'), VALUES(`last_seen_at`)),
                    `instance_label` = COALESCE(VALUES(`instance_label`), `instance_label`)";

        $this->queries++;
        if (!$this->mysqli->query($sql)) {
            $this->lastError = $this->mysqli->error;
            return self::RESOLVE_FAILED;
        }

        // affected_rows == 1 means a brand new dimension member appeared.
        if ($this->mysqli->affected_rows === 1) {
            $this->created++;
        }

        $instanceId = (int) $this->mysqli->insert_id;
        if ($instanceId > 0) {
            return $instanceId;
        }

        return $this->selectExisting($assetId, $dim, $instanceKey);
    }

    private function selectExisting(int $assetId, string $dim, string $instanceKey): int
    {
        $sql = "SELECT `instance_id` FROM `device_metric_instances`
                WHERE `asset_id` = ? AND `metric_dim` = ? AND `instance_key` = ?
                LIMIT 1";
        $this->queries++;
        $stmt = $this->mysqli->prepare($sql);
        if ($stmt === false) {
            $this->lastError = $this->mysqli->error;
            return self::RESOLVE_FAILED;
        }
        $stmt->bind_param('iss', $assetId, $dim, $instanceKey);
        if (!$stmt->execute()) {
            $this->lastError = $stmt->error;
            $stmt->close();
            return self::RESOLVE_FAILED;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$row) {
            $this->lastError = 'instance row not found after upsert';
            return self::RESOLVE_FAILED;
        }
        return (int) $row['instance_id'];
    }

    private function markSeen(int $instanceId, string $seenAt): void
    {
        if ($instanceId <= 0) {
            return;
        }
        if (!isset($this->pendingSeen[$seenAt])) {
            $this->pendingSeen[$seenAt] = [];
        }
        $this->pendingSeen[$seenAt][$instanceId] = true;
    }

    private function cacheKey(int $assetId, string $dim, string $instanceKey): string
    {
        return $assetId . '|' . $dim . '|' . $instanceKey;
    }

    /** Column widths are in characters, so truncate in characters, not bytes. */
    private function truncate(string $value, int $length): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }
        return mb_substr($value, 0, $length);
    }

    /** Accepts a caller-supplied UTC timestamp, falling back to now in UTC. */
    private function normalizeSeenAt(?string $seenAtUtc): string
    {
        if ($seenAtUtc === null || $seenAtUtc === '') {
            return gmdate('Y-m-d H:i:s');
        }
        return $seenAtUtc;
    }
}
