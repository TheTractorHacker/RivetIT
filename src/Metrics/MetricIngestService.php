<?php

namespace ITFlow\Metrics;

/**
 * Turns MetricSample objects into device_metric_samples rows.
 *
 * The whole ingest path is deliberately narrow: validate, resolve, batch-write.
 *
 * VALIDATE, DON'T CLAMP. Every value goes through MetricRegistry::isValidValue()
 * before it is written, and anything out of range is rejected outright. A CPU
 * reading of 1200% is a collection bug; clamping it to 100 would bake that bug
 * into the history permanently and there would be no way to tell later that the
 * number was ever wrong. Rejections are counted and reason-tagged so the bug
 * surfaces instead of disappearing.
 *
 * IDEMPOTENCY IS THE PRIMARY KEY. device_metric_samples has no surrogate id -
 * (asset_id, metric_id, instance_id, sampled_at) IS the idempotency key, and a
 * re-pushed sample dedupes through INSERT ... ON DUPLICATE KEY UPDATE. That means
 * a collector can safely re-send an overlapping window, and a retried cron run is
 * harmless. Nothing here needs a fingerprint column.
 *
 * TIME CONVENTION: device_metric_samples.sampled_at is stored in UTC. This is a
 * deliberate divergence from the rest of the application, which stores local time.
 * Vendor APIs hand back UTC or epoch timestamps and the fleet will not stay in one
 * timezone forever, so the sample stream is normalised to UTC on the way in.
 * device_metric_collection_state timestamps use the same UTC clock for the same
 * reason. Anything that RENDERS these columns must convert to the display zone.
 *
 * OWNERSHIP: this class writes only device_metric_samples,
 * device_metric_collection_state and (indirectly, via MetricInstanceResolver)
 * device_metric_instances. It never creates, updates or deletes an asset or an
 * asset_rmm_links row - those belong exclusively to includes/class_rmm_asset_mapper.php.
 *
 * Accepted sample shapes (both are supported so the cron collectors and the
 * device-push API endpoint can share one ingest path):
 *   - an object exposing assetId / metricKey / instanceKey / value / sampledAt,
 *     via getX() accessors, bare x() accessors, or public readonly properties;
 *   - an associative array using either camelCase or snake_case keys.
 * The optional field instanceLabel / instance_label is read when present.
 */
class MetricIngestService
{
    /** Rows per multi-row INSERT. Per-row inserts are not acceptable at fleet scale. */
    public const INSERT_CHUNK = 500;

    /** Reject samples timestamped further than this into the future (clock skew guard). */
    public int $maxFutureSkewSeconds = 3600;

    /** Reject samples older than this. Generous - Tactical prunes CheckHistory at 30 days. */
    public int $maxAgeSeconds = 34560000; // 400 days

    private \mysqli $mysqli;
    private MetricInstanceResolver $resolver;

    /** @var array<string,int>|null metric_key => metric_id, loaded once per run. */
    private ?array $metricIds = null;

    /** Guard so a missing-def self-heal sync is attempted at most once per instance. */
    private bool $defsSynced = false;

    /** @var array<string,array{0:string,1:?string}> "Class::field" => [accessor kind, member] */
    private array $accessors = [];

    public function __construct(\mysqli $mysqli, ?MetricInstanceResolver $resolver = null)
    {
        $this->mysqli = $mysqli;
        $this->resolver = $resolver ?? new MetricInstanceResolver($mysqli);
    }

    public function resolver(): MetricInstanceResolver
    {
        return $this->resolver;
    }

    /**
     * Ingest a batch of samples.
     *
     * @param array $samples MetricSample objects (or equivalent associative arrays).
     * @param int|null $integrationId When given, device_metric_collection_state is
     *        updated for every asset in the batch as a successful collection. Omit
     *        it to write samples only and manage state yourself.
     *
     * @return array{inserted:int,rejected:int,duplicate:int,reject_reasons:array<string,int>,errors:array<int,string>}
     */
    public function ingest(array $samples, ?int $integrationId = null): array
    {
        $result = [
            'inserted'       => 0,
            'rejected'       => 0,
            'duplicate'      => 0,
            'reject_reasons' => [],
            'errors'         => [],
        ];

        if (!$samples) {
            return $result;
        }

        if (!$this->loadMetricIds()) {
            $result['rejected'] = count($samples);
            $result['reject_reasons']['metric_defs_unavailable'] = count($samples);
            $result['errors'][] = 'device_metric_defs could not be read: ' . $this->mysqli->error;
            return $result;
        }

        // ---- pass 1: normalise and validate, without touching the database ----
        $pending = [];   // parallel list of validated, unresolved samples
        $assetIds = [];  // distinct asset ids, for a single priming query
        $now = time();

        foreach ($samples as $sample) {
            $normalised = $this->normalize($sample, $now);
            if (is_string($normalised)) {
                $this->countReject($result, $normalised);
                continue;
            }
            $pending[] = $normalised;
            $assetIds[$normalised['asset_id']] = $normalised['asset_id'];
        }

        if (!$pending) {
            return $result;
        }

        // ---- pass 2: resolve instances (one priming query for the whole run) ----
        $this->resolver->primeAssets(array_values($assetIds));

        $rows = [];          // dedupe within the batch on the composite primary key
        $maxSampleAt = [];   // asset_id => newest sampled_at, for collection state

        foreach ($pending as $item) {
            $instanceId = $this->resolver->resolve(
                $item['asset_id'],
                $item['dim'],
                $item['instance_key'],
                $item['instance_label'],
                $item['sampled_at']
            );

            if ($instanceId === MetricInstanceResolver::RESOLVE_FAILED) {
                $this->countReject($result, 'instance_resolve_failed');
                $error = $this->resolver->lastError();
                if ($error !== null && !in_array($error, $result['errors'], true)) {
                    $result['errors'][] = $error;
                }
                continue;
            }

            $pk = $item['asset_id'] . '|' . $item['metric_id'] . '|' . $instanceId . '|' . $item['sampled_at'];
            if (isset($rows[$pk])) {
                // Same primary key twice inside one batch. The DB would dedupe this
                // anyway; doing it here keeps the statement smaller and the count honest.
                $result['duplicate']++;
            }
            $rows[$pk] = [
                'asset_id'    => $item['asset_id'],
                'metric_id'   => $item['metric_id'],
                'instance_id' => $instanceId,
                'sampled_at'  => $item['sampled_at'],
                'value'       => $item['value'],
            ];

            $assetId = $item['asset_id'];
            if (!isset($maxSampleAt[$assetId]) || $item['sampled_at'] > $maxSampleAt[$assetId]) {
                $maxSampleAt[$assetId] = $item['sampled_at'];
            }
        }

        // Persist last_seen_at for instances that were served from the cache.
        $this->resolver->flush();

        if (!$rows) {
            return $result;
        }

        // ---- pass 3: chunked multi-row upsert ----
        foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
            $written = $this->writeChunk($chunk);
            if ($written === null) {
                $result['rejected'] += count($chunk);
                $this->addReason($result, 'insert_failed', count($chunk));
                $error = $this->mysqli->error;
                if ($error !== '' && !in_array($error, $result['errors'], true)) {
                    $result['errors'][] = $error;
                }
                continue;
            }
            $result['inserted']  += $written['inserted'];
            $result['duplicate'] += $written['duplicate'];
        }

        // ---- pass 4: collection state ----
        if ($integrationId !== null && $integrationId > 0) {
            foreach ($maxSampleAt as $assetId => $sampledAt) {
                $this->recordSuccess((int) $assetId, $integrationId, $sampledAt);
            }
        }

        return $result;
    }

    /**
     * Build and run one multi-row INSERT ... ON DUPLICATE KEY UPDATE.
     *
     * Counting the insert/duplicate split needs BOTH signals, verified empirically
     * against MariaDB 10.11:
     *
     *   fresh insert      affected_rows=2  "Records: 2  Duplicates: 0"
     *   identical re-push affected_rows=0  "Records: 2  Duplicates: 0"
     *   changed re-push   affected_rows=4  "Records: 2  Duplicates: 2"
     *
     * The Duplicates counter only counts duplicates the ODKU actually CHANGED, so
     * on its own it cannot tell a fresh insert from an idempotent no-op re-push -
     * both report 0. affected_rows counts 1 per insert, 2 per changed duplicate and
     * 0 per unchanged duplicate. Together they solve exactly:
     *
     *   changed   = Duplicates
     *   inserted  = affected_rows - 2 * changed
     *   duplicate = total - inserted        (changed + unchanged)
     *
     * Getting this wrong would report every idempotent re-collection as a fresh
     * insert and hide the fact that dedupe is working at all.
     *
     * @param array<int,array{asset_id:int,metric_id:int,instance_id:int,sampled_at:string,value:float}> $chunk
     * @return array{inserted:int,duplicate:int}|null null when the query failed
     */
    private function writeChunk(array $chunk): ?array
    {
        $values = [];
        foreach ($chunk as $row) {
            $values[] = sprintf(
                "(%d,%d,%d,'%s',%s)",
                $row['asset_id'],
                $row['metric_id'],
                $row['instance_id'],
                $this->mysqli->real_escape_string($row['sampled_at']),
                $this->sqlFloat($row['value'])
            );
        }

        // sampled_at is UTC by convention - see the class docblock.
        $sql = "INSERT INTO `device_metric_samples`
                    (`asset_id`,`metric_id`,`instance_id`,`sampled_at`,`metric_value`)
                VALUES " . implode(',', $values) . "
                ON DUPLICATE KEY UPDATE `metric_value` = VALUES(`metric_value`)";

        if (!$this->mysqli->query($sql)) {
            return null;
        }

        $total    = count($chunk);
        $affected = (int) $this->mysqli->affected_rows;
        $info     = (string) $this->mysqli->info;

        if (preg_match('/Duplicates:\s*(\d+)/', $info, $m)) {
            $changed  = (int) $m[1];
            $inserted = $affected - (2 * $changed);
        } else {
            // No parsable info string: affected_rows == total means all fresh.
            $inserted = $affected >= $total ? $total : $affected;
        }

        if ($inserted < 0) {
            $inserted = 0;
        }
        if ($inserted > $total) {
            $inserted = $total;
        }

        return ['inserted' => $inserted, 'duplicate' => $total - $inserted];
    }

    /**
     * Validate one incoming sample and flatten it.
     *
     * @return array{asset_id:int,metric_id:int,dim:?string,instance_key:?string,instance_label:?string,value:float,sampled_at:string}|string
     *         the flattened row, or a reject-reason string
     */
    private function normalize($sample, int $now)
    {
        if (!is_object($sample) && !is_array($sample)) {
            return 'malformed_sample';
        }

        $assetId = (int) $this->field($sample, 'assetId', 'asset_id');
        if ($assetId <= 0) {
            return 'invalid_asset';
        }

        $metricKey = $this->field($sample, 'metricKey', 'metric_key');
        if (!is_string($metricKey) || $metricKey === '') {
            return 'missing_metric_key';
        }

        $spec = MetricRegistry::spec($metricKey);
        if ($spec === null) {
            return 'unknown_metric';
        }

        $value = $this->field($sample, 'value', 'value');
        if (!MetricRegistry::isValidValue($metricKey, $value)) {
            // Out of the metric's declared range, non-numeric, NaN or infinite.
            // Rejected on purpose - see the class docblock.
            return 'invalid_value';
        }

        if (!isset($this->metricIds[$metricKey])) {
            return 'metric_not_in_defs';
        }

        $instanceKey = $this->field($sample, 'instanceKey', 'instance_key');
        if ($instanceKey !== null && !is_string($instanceKey)) {
            $instanceKey = is_scalar($instanceKey) ? (string) $instanceKey : null;
        }
        if ($instanceKey === '') {
            $instanceKey = null;
        }

        // The registry decides whether a metric is dimensioned. A mismatch means the
        // provider mapped the metric wrong, and letting it through would either
        // collapse every volume onto the host sentinel or file a host reading under a
        // dimension - both silently corrupt the series. Reject instead.
        if ($spec['dim'] === null && $instanceKey !== null) {
            return 'unexpected_instance_key';
        }
        if ($spec['dim'] !== null && $instanceKey === null) {
            return 'missing_instance_key';
        }

        $instanceLabel = $this->field($sample, 'instanceLabel', 'instance_label');
        if ($instanceLabel !== null && !is_string($instanceLabel)) {
            $instanceLabel = is_scalar($instanceLabel) ? (string) $instanceLabel : null;
        }

        $sampledAt = $this->normalizeTimestamp($this->field($sample, 'sampledAt', 'sampled_at'));
        if ($sampledAt === null) {
            return 'invalid_timestamp';
        }

        $epoch = (int) strtotime($sampledAt . ' UTC');
        if ($epoch > $now + $this->maxFutureSkewSeconds) {
            return 'timestamp_in_future';
        }
        if ($epoch < $now - $this->maxAgeSeconds) {
            return 'timestamp_too_old';
        }

        return [
            'asset_id'       => $assetId,
            'metric_id'      => (int) $this->metricIds[$metricKey],
            'dim'            => $spec['dim'],
            'instance_key'   => $instanceKey,
            'instance_label' => $instanceLabel,
            'value'          => (float) $value,
            'sampled_at'     => $sampledAt,
        ];
    }

    /**
     * Normalise any reasonable timestamp representation to 'Y-m-d H:i:s' in UTC.
     *
     * A bare date string carrying no zone or offset is read AS UTC, because every
     * producer feeding this path (vendor APIs, the collector script, the push
     * endpoint) emits UTC. Strings with an explicit offset or Z are converted.
     */
    private function normalizeTimestamp($raw): ?string
    {
        $utc = new \DateTimeZone('UTC');

        try {
            if ($raw === null || $raw === '') {
                // No timestamp supplied means "now" - normal for a live push.
                return gmdate('Y-m-d H:i:s');
            }
            if ($raw instanceof \DateTimeInterface) {
                $dt = \DateTimeImmutable::createFromInterface($raw)->setTimezone($utc);
            } elseif (is_int($raw) || is_float($raw)) {
                $dt = (new \DateTimeImmutable('@' . (int) $raw))->setTimezone($utc);
            } elseif (is_string($raw) && ctype_digit($raw) && strlen($raw) >= 9 && strlen($raw) <= 11) {
                // Unambiguous epoch seconds. Shorter/longer digit runs fall through to
                // date parsing so "20240101" is not misread as an epoch.
                $dt = (new \DateTimeImmutable('@' . (int) $raw))->setTimezone($utc);
            } elseif (is_string($raw)) {
                $dt = (new \DateTimeImmutable($raw, $utc))->setTimezone($utc);
            } else {
                return null;
            }
        } catch (\Exception $e) {
            return null;
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /** metric_key => metric_id for the whole run, in one query. */
    private function loadMetricIds(): bool
    {
        if ($this->metricIds !== null) {
            return true;
        }

        $map = $this->queryMetricIds();
        if ($map === null) {
            return false;
        }

        // Self-heal: if the defs table has not been seeded (or the registry gained a
        // metric since the last sync) push the registry down and re-read, once.
        if (!$this->defsSynced && count($map) < count(MetricRegistry::keys())) {
            $this->defsSynced = true;
            MetricRegistry::syncToDatabase($this->mysqli);
            $reread = $this->queryMetricIds();
            if ($reread !== null) {
                $map = $reread;
            }
        }

        $this->metricIds = $map;
        return true;
    }

    /** @return array<string,int>|null */
    private function queryMetricIds(): ?array
    {
        $res = $this->mysqli->query("SELECT `metric_id`, `metric_key` FROM `device_metric_defs`");
        if ($res === false) {
            return null;
        }
        $map = [];
        while ($row = $res->fetch_assoc()) {
            $map[(string) $row['metric_key']] = (int) $row['metric_id'];
        }
        $res->free();
        return $map;
    }

    /** Force the metric_key => metric_id map to be re-read on the next ingest(). */
    public function refreshMetricIds(): void
    {
        $this->metricIds = null;
    }

    /**
     * Record a successful collection for one (asset, integration) pair.
     *
     * Clears last_error and resets consecutive_failures. last_sample_at only ever
     * moves forward, so a backfill of older history does not make the newest sample
     * look older than it is. All three timestamps use the UTC clock, matching
     * device_metric_samples.sampled_at.
     *
     * @param string|null $lastSampleAtUtc 'Y-m-d H:i:s' UTC; null leaves it unchanged.
     * @param string|null $vendorCursorJson null leaves the stored cursor unchanged.
     */
    public function recordSuccess(
        int $assetId,
        int $integrationId,
        ?string $lastSampleAtUtc = null,
        ?string $vendorCursorJson = null
    ): bool {
        if ($assetId <= 0 || $integrationId <= 0) {
            return false;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $sampleSql = $lastSampleAtUtc === null
            ? 'NULL'
            : "'" . $this->mysqli->real_escape_string($lastSampleAtUtc) . "'";
        $cursorSql = $vendorCursorJson === null
            ? 'NULL'
            : "'" . $this->mysqli->real_escape_string($vendorCursorJson) . "'";

        $updates = [
            "`last_collected_at` = VALUES(`last_collected_at`)",
            "`last_error` = NULL",
            "`consecutive_failures` = 0",
        ];
        if ($lastSampleAtUtc !== null) {
            $updates[] = "`last_sample_at` = GREATEST(COALESCE(`last_sample_at`,'1970-01-01 00:00:00'), VALUES(`last_sample_at`))";
        }
        if ($vendorCursorJson !== null) {
            $updates[] = "`vendor_cursor_json` = VALUES(`vendor_cursor_json`)";
        }

        $sql = "INSERT INTO `device_metric_collection_state`
                    (`asset_id`,`integration_id`,`last_collected_at`,`last_sample_at`,`last_error`,`consecutive_failures`,`vendor_cursor_json`)
                VALUES ({$assetId},{$integrationId},'{$nowUtc}',{$sampleSql},NULL,0,{$cursorSql})
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

        return (bool) $this->mysqli->query($sql);
    }

    /**
     * Record a failed collection attempt.
     *
     * last_collected_at is deliberately NOT advanced on failure: it means "the last
     * time we actually got data", so a device that has been erroring for six hours
     * does not look freshly collected. consecutive_failures and last_error carry the
     * failure story. last_error is VARCHAR(255), so the message is truncated.
     */
    public function recordFailure(int $assetId, int $integrationId, string $error): bool
    {
        if ($assetId <= 0 || $integrationId <= 0) {
            return false;
        }

        $message = trim($error);
        if ($message === '') {
            $message = 'unknown error';
        }
        if (mb_strlen($message) > 255) {
            $message = mb_substr($message, 0, 255);
        }
        $messageSql = $this->mysqli->real_escape_string($message);

        $sql = "INSERT INTO `device_metric_collection_state`
                    (`asset_id`,`integration_id`,`last_collected_at`,`last_sample_at`,`last_error`,`consecutive_failures`)
                VALUES ({$assetId},{$integrationId},NULL,NULL,'{$messageSql}',1)
                ON DUPLICATE KEY UPDATE
                    `last_error` = VALUES(`last_error`),
                    `consecutive_failures` = `consecutive_failures` + 1";

        return (bool) $this->mysqli->query($sql);
    }

    /**
     * Read one collection-state row - providers need this to resume from
     * vendor_cursor_json and to back off after repeated failures.
     *
     * @return array<string,mixed>|null
     */
    public function collectionState(int $assetId, int $integrationId): ?array
    {
        if ($assetId <= 0 || $integrationId <= 0) {
            return null;
        }
        $stmt = $this->mysqli->prepare(
            "SELECT `asset_id`,`integration_id`,`last_collected_at`,`last_sample_at`,`last_error`,
                    `consecutive_failures`,`vendor_cursor_json`
             FROM `device_metric_collection_state`
             WHERE `asset_id` = ? AND `integration_id` = ? LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('ii', $assetId, $integrationId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Render a validated float as a SQL literal. PHP's default double-to-string is
     * round-trip exact and locale-independent on PHP 8, and MySQL accepts the
     * exponent form it produces for very large or very small magnitudes.
     */
    private function sqlFloat(float $value): string
    {
        if (!is_finite($value)) {
            return '0'; // unreachable: isValidValue() already rejected NaN/Inf
        }
        return (string) $value;
    }

    private function countReject(array &$result, string $reason): void
    {
        $result['rejected']++;
        $this->addReason($result, $reason, 1);
    }

    private function addReason(array &$result, string $reason, int $count): void
    {
        if (!isset($result['reject_reasons'][$reason])) {
            $result['reject_reasons'][$reason] = 0;
        }
        $result['reject_reasons'][$reason] += $count;
    }

    /**
     * Read a field from a sample without hard-referencing an accessor that may not
     * exist. MetricSample is built by a different module; this supports getX(),
     * bare x(), a public (readonly) property, and both key styles on a plain array,
     * so an ingest call can never fatal on an accessor-name mismatch. The resolved
     * strategy is cached per class so the reflection cost is paid once, not per row.
     */
    private function field($sample, string $camel, string $snake)
    {
        if (is_array($sample)) {
            if (array_key_exists($camel, $sample)) {
                return $sample[$camel];
            }
            if (array_key_exists($snake, $sample)) {
                return $sample[$snake];
            }
            return null;
        }

        $cacheKey = get_class($sample) . '::' . $camel;
        if (!isset($this->accessors[$cacheKey])) {
            $this->accessors[$cacheKey] = $this->resolveAccessor($sample, $camel, $snake);
        }
        [$kind, $member] = $this->accessors[$cacheKey];

        if ($kind === 'method') {
            return $sample->$member();
        }
        if ($kind === 'prop') {
            return $sample->$member;
        }
        return null;
    }

    /** @return array{0:string,1:?string} */
    private function resolveAccessor($sample, string $camel, string $snake): array
    {
        $getter = 'get' . ucfirst($camel);
        foreach ([$getter, $camel, 'get' . ucfirst($snake)] as $method) {
            if (method_exists($sample, $method) && is_callable([$sample, $method])) {
                return ['method', $method];
            }
        }

        // get_object_vars() from outside the class returns only publicly readable
        // properties, so this cannot select a private one and fatal on access.
        $vars = get_object_vars($sample);
        foreach ([$camel, $snake] as $prop) {
            if (array_key_exists($prop, $vars)) {
                return ['prop', $prop];
            }
        }

        return ['none', null];
    }
}
