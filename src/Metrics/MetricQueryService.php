<?php

namespace ITFlow\Metrics;

/**
 * The read path for device metrics: range -> resolution -> gapped series.
 *
 * Naming note: this subsystem is "Metrics", never "telemetry". `config_telemetry`
 * already exists and means anonymous phone-home usage reporting, so the two must
 * never share vocabulary.
 *
 * TIMEZONE CONTRACT - READ THIS BEFORE TOUCHING ANY TIMESTAMP HERE
 * ---------------------------------------------------------------
 * `device_metric_samples.sampled_at` and `device_metric_rollups.period_start` are
 * stored in UTC. That is a deliberate divergence from the rest of RivetIT, which
 * stores local time (see MetricIngestService for why: vendor APIs speak UTC, and a
 * DST fold would collide two distinct samples onto the same composite primary key
 * and silently destroy one). Consequently:
 *   - every DateTimeImmutable that enters this class is converted to UTC;
 *   - every SQL literal this class emits is a UTC datetime string;
 *   - every timestamp this class returns is ISO-8601 with an explicit `Z`.
 * The display layer converts to the viewer's zone. Nothing in here does.
 *
 * RESOLUTION SELECTION
 * --------------------
 * Three tiers, finest first: raw samples, hour rollups, day rollups. In 'auto'
 * mode the first tier that satisfies BOTH constraints wins:
 *   1. the requested range is within that tier's sensible reach, and
 *   2. the resulting bucket count fits under the per-series point cap.
 * Constraint 2 is what keeps "90 days" honest: 90d of hour buckets is 2,160
 * points, over the 1,000 default cap, so auto drops to day buckets (90 points)
 * instead of returning a payload no chart can draw. The chosen tier is reported
 * back in the result so the UI can label the axis "hourly average" rather than
 * implying the line is raw.
 *
 * REJECT, DON'T TRUNCATE
 * ----------------------
 * If an explicit resolution (or a range no tier can cover) would exceed the cap,
 * this throws \OverflowException. Silently returning the first N points would
 * draw a chart that is wrong in a way nobody can see - the line would simply stop
 * partway across the axis and look like the device went offline.
 *
 * GAPS ARE DATA
 * -------------
 * Every series is emitted on a fixed grid of equal buckets covering the whole
 * window. A bucket with no underlying sample gets a null value, never an
 * interpolated one and never a zero. A chart must draw a BREAK across an offline
 * period; a straight line between the last reading before an outage and the first
 * after it asserts the device was healthy throughout, which is the exact opposite
 * of the truth being investigated.
 *
 * OWNERSHIP: this class is read-only. It issues SELECTs and nothing else. It
 * never touches `assets` or `asset_rmm_links`, which belong exclusively to
 * includes/class_rmm_asset_mapper.php.
 */
class MetricQueryService
{
    public const RES_AUTO = 'auto';
    public const RES_RAW  = 'raw';
    public const RES_HOUR = 'hour';
    public const RES_DAY  = 'day';

    /** Rollup bucket names as stored in device_metric_rollups.bucket. */
    public const BUCKET_HOUR = 'hour';
    public const BUCKET_DAY  = 'day';

    /** Default per-series point cap. */
    public const DEFAULT_MAX_POINTS = 1000;

    /** Ceiling on the caller-supplied point cap, so ?max_points= can't be weaponised. */
    public const HARD_MAX_POINTS = 5000;

    /** Ceiling on series (instances x metrics) in one response. 64 cores + 8 volumes fits. */
    public const MAX_SERIES = 200;

    /**
     * Nominal collection cadence, in seconds, used as the raw grid width.
     * The fleet collects every 5 minutes today; moving to 1 minute is a
     * constructor argument, not a rewrite.
     */
    public const DEFAULT_RAW_BUCKET_SECONDS = 300;

    /** Longest range still served from raw samples (~48h). */
    public const RAW_MAX_RANGE_SECONDS = 172800;

    /** Longest range still served from hour rollups (~90d). */
    public const HOUR_MAX_RANGE_SECONDS = 7776000;

    /** A series whose newest point is older than this is reported as 'stale'. */
    public const STALE_AFTER_SECONDS = 7200;

    /** Capability status values. */
    public const STATUS_AVAILABLE   = 'available';
    public const STATUS_STALE       = 'stale';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** The reserved host-level sentinel. It deliberately has no device_metric_instances row. */
    public const HOST_INSTANCE_ID = 0;

    private \mysqli $mysqli;
    private int $maxPointsPerSeries;
    private int $rawBucketSeconds;

    /** metric_key => metric_id, lazily loaded once per request. */
    private ?array $metricIdByKey = null;

    public function __construct(
        \mysqli $mysqli,
        int $maxPointsPerSeries = self::DEFAULT_MAX_POINTS,
        int $rawBucketSeconds = self::DEFAULT_RAW_BUCKET_SECONDS
    ) {
        $this->mysqli = $mysqli;
        $this->maxPointsPerSeries = self::clampMaxPoints($maxPointsPerSeries);
        $this->rawBucketSeconds = $rawBucketSeconds > 0 ? $rawBucketSeconds : self::DEFAULT_RAW_BUCKET_SECONDS;
    }

    public static function clampMaxPoints(int $requested): int
    {
        if ($requested < 1) {
            return self::DEFAULT_MAX_POINTS;
        }
        return min($requested, self::HARD_MAX_POINTS);
    }

    public function maxPointsPerSeries(): int
    {
        return $this->maxPointsPerSeries;
    }

    /** Bucket width in seconds for a concrete (non-auto) resolution. */
    public function bucketSecondsFor(string $resolution): int
    {
        switch ($resolution) {
            case self::RES_RAW:
                return $this->rawBucketSeconds;
            case self::RES_HOUR:
                return 3600;
            case self::RES_DAY:
                return 86400;
        }
        throw new \InvalidArgumentException("Unknown resolution '$resolution'");
    }

    public static function isConcreteResolution(string $resolution): bool
    {
        return $resolution === self::RES_RAW
            || $resolution === self::RES_HOUR
            || $resolution === self::RES_DAY;
    }

    /**
     * How many grid buckets a window occupies at a given resolution.
     * The grid start is floored to a bucket boundary, so this is the count the
     * caller will actually receive - the number the point cap is checked against.
     */
    public function bucketCount(\DateTimeImmutable $from, \DateTimeImmutable $to, string $resolution): int
    {
        $step = $this->bucketSecondsFor($resolution);
        $gridStart = intdiv($from->getTimestamp(), $step) * $step;
        $span = $to->getTimestamp() - $gridStart;
        if ($span <= 0) {
            return 0;
        }
        return (int) ceil($span / $step);
    }

    /**
     * Pick the finest tier that both covers the range and fits the point cap.
     * Returns null when nothing fits - the caller must then reject the request
     * rather than serve a truncated series.
     */
    public function chooseResolution(\DateTimeImmutable $from, \DateTimeImmutable $to): ?string
    {
        $rangeSeconds = $to->getTimestamp() - $from->getTimestamp();
        $ladder = [
            [self::RES_RAW,  self::RAW_MAX_RANGE_SECONDS],
            [self::RES_HOUR, self::HOUR_MAX_RANGE_SECONDS],
            [self::RES_DAY,  null],
        ];
        foreach ($ladder as $tier) {
            [$resolution, $maxRange] = $tier;
            if ($maxRange !== null && $rangeSeconds > $maxRange) {
                continue;
            }
            if ($this->bucketCount($from, $to, $resolution) > $this->maxPointsPerSeries) {
                continue;
            }
            return $resolution;
        }
        return null;
    }

    // ---------------------------------------------------------------- queries

    /**
     * One metric on one device, as one series per instance.
     *
     * @throws \InvalidArgumentException on an unknown metric key or an inverted range
     * @throws \OverflowException        when the request would exceed the point or series cap
     */
    public function queryMetric(
        int $assetId,
        string $metricKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $resolution = self::RES_AUTO
    ): array {
        $batch = $this->queryBatch($assetId, [$metricKey], $from, $to, $resolution);
        $metric = $batch['metrics'][0] ?? null;
        if ($metric === null) {
            // Unreachable in practice: queryBatch() validates the key and always
            // emits an entry for it. Guarded anyway so a future change can't
            // silently return a malformed shape.
            throw new \InvalidArgumentException("No result produced for metric '$metricKey'");
        }
        return [
            'asset_id'    => $assetId,
            'resolution'  => $batch['resolution'],
            'requested_resolution' => $batch['requested_resolution'],
            'bucket_seconds' => $batch['bucket_seconds'],
            'from'        => $batch['from'],
            'to'          => $batch['to'],
            'requested_from' => $batch['requested_from'],
            'requested_to'   => $batch['requested_to'],
            'point_count' => $batch['point_count'],
            'max_points'  => $batch['max_points'],
            'metric'      => $metric,
        ];
    }

    /**
     * Several metrics on one device in a single round trip, so a device page
     * makes one HTTP call instead of one per chart. All metrics share the
     * window, the resolution and the grid, which is also what makes the charts
     * comparable when they are stacked vertically.
     *
     * @param string[] $metricKeys
     * @throws \InvalidArgumentException
     * @throws \OverflowException
     */
    public function queryBatch(
        int $assetId,
        array $metricKeys,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $resolution = self::RES_AUTO
    ): array {
        $from = self::toUtc($from);
        $to   = self::toUtc($to);
        if ($to->getTimestamp() <= $from->getTimestamp()) {
            throw new \InvalidArgumentException('The "to" timestamp must be later than "from"');
        }

        $keys = [];
        foreach ($metricKeys as $raw) {
            $key = is_string($raw) ? trim($raw) : '';
            if ($key === '') {
                continue;
            }
            if (!MetricRegistry::exists($key)) {
                throw new \InvalidArgumentException("Unknown metric key '$key'");
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw new \InvalidArgumentException('At least one metric key is required');
        }

        $requestedResolution = $resolution === '' ? self::RES_AUTO : $resolution;
        if ($requestedResolution === self::RES_AUTO) {
            $chosen = $this->chooseResolution($from, $to);
            if ($chosen === null) {
                $dayPoints = $this->bucketCount($from, $to, self::RES_DAY);
                throw new \OverflowException(
                    'Requested range is too long: even daily buckets would need ' . $dayPoints
                    . ' points per series, over the cap of ' . $this->maxPointsPerSeries
                    . '. Narrow the range.'
                );
            }
            $resolution = $chosen;
        } else {
            if (!self::isConcreteResolution($requestedResolution)) {
                throw new \InvalidArgumentException(
                    "Unknown resolution '$requestedResolution'. Use auto, raw, hour or day."
                );
            }
            $resolution = $requestedResolution;
            $points = $this->bucketCount($from, $to, $resolution);
            if ($points > $this->maxPointsPerSeries) {
                throw new \OverflowException(
                    'Resolution "' . $resolution . '" over this range needs ' . $points
                    . ' points per series, over the cap of ' . $this->maxPointsPerSeries
                    . '. Narrow the range or request a coarser resolution.'
                );
            }
        }

        $step = $this->bucketSecondsFor($resolution);
        // Floor the window to a bucket boundary so bucket edges are stable
        // between requests (and therefore cacheable and comparable), then walk
        // whole buckets to the far edge. The effective window is reported back.
        $gridStartTs = intdiv($from->getTimestamp(), $step) * $step;
        $bucketCount = (int) ceil(($to->getTimestamp() - $gridStartTs) / $step);
        if ($bucketCount < 1) {
            $bucketCount = 1;
        }
        $gridEndTs = $gridStartTs + ($bucketCount * $step);

        $gridStart = (new \DateTimeImmutable('@' . $gridStartTs))->setTimezone(new \DateTimeZone('UTC'));
        $gridEnd   = (new \DateTimeImmutable('@' . $gridEndTs))->setTimezone(new \DateTimeZone('UTC'));

        $idByKey = $this->metricIdMap();
        $idsWanted = [];
        $keyById = [];
        foreach ($keys as $key) {
            if (isset($idByKey[$key])) {
                $idsWanted[] = $idByKey[$key];
                $keyById[$idByKey[$key]] = $key;
            }
        }

        // metric_key => instance_id => [bucketIndex => aggregate]
        $grouped = [];
        if ($idsWanted !== []) {
            $rows = $resolution === self::RES_RAW
                ? $this->fetchRawBuckets($assetId, $idsWanted, $gridStart, $gridEnd, $step)
                : $this->fetchRollupBuckets($assetId, $idsWanted, $gridStart, $gridEnd, $step, $resolution);

            $seriesSeen = 0;
            foreach ($rows as $row) {
                $mid = (int) $row['metric_id'];
                if (!isset($keyById[$mid])) {
                    continue;
                }
                $key = $keyById[$mid];
                $instanceId = (int) $row['instance_id'];
                $bidx = (int) $row['bidx'];
                if ($bidx < 0 || $bidx >= $bucketCount) {
                    continue;
                }
                if (!isset($grouped[$key][$instanceId])) {
                    $seriesSeen++;
                    if ($seriesSeen > self::MAX_SERIES) {
                        throw new \OverflowException(
                            'This request covers more than ' . self::MAX_SERIES
                            . ' series. Request fewer metrics at a time.'
                        );
                    }
                    $grouped[$key][$instanceId] = [];
                }
                $count = (int) $row['n'];
                $avg = $count > 0 ? ((float) $row['sum_value']) / $count : null;
                $grouped[$key][$instanceId][$bidx] = [
                    'avg'   => $avg,
                    'min'   => $row['min_value'] === null ? null : (float) $row['min_value'],
                    'max'   => $row['max_value'] === null ? null : (float) $row['max_value'],
                    'count' => $count,
                ];
            }
        }

        $instanceMeta = $this->instanceMetaFor($assetId, $grouped);

        $metrics = [];
        foreach ($keys as $key) {
            $spec = MetricRegistry::spec($key);
            if ($spec === null) {
                continue; // guarded above; defensive only
            }
            $byInstance = $grouped[$key] ?? [];
            $instanceIds = array_keys($byInstance);
            usort($instanceIds, static function ($a, $b) use ($instanceMeta) {
                if ($a === self::HOST_INSTANCE_ID || $b === self::HOST_INSTANCE_ID) {
                    return $a === $b ? 0 : ($a === self::HOST_INSTANCE_ID ? -1 : 1);
                }
                $ka = $instanceMeta[$a]['instance_key'] ?? (string) $a;
                $kb = $instanceMeta[$b]['instance_key'] ?? (string) $b;
                return strnatcasecmp($ka, $kb) ?: ($a <=> $b);
            });

            $series = [];
            foreach ($instanceIds as $instanceId) {
                $series[] = [
                    'instance_id'    => $instanceId,
                    'instance_key'   => $instanceMeta[$instanceId]['instance_key'] ?? null,
                    'instance_label' => $instanceMeta[$instanceId]['instance_label'] ?? null,
                    'host_level'     => $instanceId === self::HOST_INSTANCE_ID,
                    'points'         => $this->buildPoints(
                        $byInstance[$instanceId],
                        $gridStartTs,
                        $step,
                        $bucketCount,
                        (int) $spec['precision']
                    ),
                ];
            }

            $metrics[] = [
                'key'        => $spec['key'],
                'display'    => $spec['display'],
                'unit'       => $spec['unit'],
                'kind'       => $spec['kind'],
                'dim'        => $spec['dim'],
                'precision'  => $spec['precision'],
                'tier'       => $spec['tier'],
                'value_min'  => $spec['min'],
                'value_max'  => $spec['max'],
                'has_data'   => $series !== [],
                'series'     => $series,
            ];
        }

        return [
            'asset_id'             => $assetId,
            'resolution'           => $resolution,
            'requested_resolution' => $requestedResolution,
            'bucket_seconds'       => $step,
            'from'                 => self::iso($gridStart),
            'to'                   => self::iso($gridEnd),
            'requested_from'       => self::iso($from),
            'requested_to'         => self::iso($to),
            'point_count'          => $bucketCount,
            'max_points'           => $this->maxPointsPerSeries,
            'metrics'              => $metrics,
        ];
    }

    /**
     * Fill the fixed grid, emitting null for every bucket with no sample.
     * `v` is the bucket average; `min`/`max` describe the spread inside the
     * bucket so a rolled-up line can still show a spike it averaged away.
     * `n` is the number of underlying samples: 0 means the gap is real.
     */
    private function buildPoints(array $buckets, int $gridStartTs, int $step, int $bucketCount, int $precision): array
    {
        $points = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $ts = $gridStartTs + ($i * $step);
            $t = gmdate('Y-m-d\TH:i:s\Z', $ts);
            if (!isset($buckets[$i]) || $buckets[$i]['count'] < 1 || $buckets[$i]['avg'] === null) {
                // A real gap. Never interpolate and never substitute zero: a chart
                // must draw a break across an offline window, not a flat healthy line.
                $points[] = ['t' => $t, 'v' => null, 'min' => null, 'max' => null, 'n' => 0];
                continue;
            }
            $b = $buckets[$i];
            $points[] = [
                't'   => $t,
                'v'   => round($b['avg'], $precision),
                'min' => $b['min'] === null ? null : round($b['min'], $precision),
                'max' => $b['max'] === null ? null : round($b['max'], $precision),
                'n'   => $b['count'],
            ];
        }
        return $points;
    }

    /**
     * Raw samples aggregated onto the grid.
     *
     * Bucketing is done with TIMESTAMPDIFF against the grid start rather than
     * UNIX_TIMESTAMP()/FROM_UNIXTIME(), which would drag the MySQL session time
     * zone into a column that is UTC by contract. TIMESTAMPDIFF is pure datetime
     * arithmetic and cannot be perturbed by @@session.time_zone.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchRawBuckets(
        int $assetId,
        array $metricIds,
        \DateTimeImmutable $gridStart,
        \DateTimeImmutable $gridEnd,
        int $step
    ): array {
        $ids = implode(',', array_map('intval', $metricIds));
        $startSql = $this->esc(self::sqlUtc($gridStart));
        $endSql   = $this->esc(self::sqlUtc($gridEnd));
        $assetId  = (int) $assetId;
        $step     = (int) $step;

        // SUM + COUNT rather than AVG so this row shape is identical to the
        // rollup query's and buildPoints() needs only one code path.
        $sql = "SELECT s.metric_id AS metric_id,
                       s.instance_id AS instance_id,
                       FLOOR(TIMESTAMPDIFF(SECOND, '$startSql', s.sampled_at) / $step) AS bidx,
                       SUM(s.metric_value) AS sum_value,
                       COUNT(*) AS n,
                       MIN(s.metric_value) AS min_value,
                       MAX(s.metric_value) AS max_value
                FROM device_metric_samples s FORCE INDEX (PRIMARY)
                WHERE s.asset_id = $assetId
                  AND s.metric_id IN ($ids)
                  AND s.sampled_at >= '$startSql'
                  AND s.sampled_at <  '$endSql'
                GROUP BY s.metric_id, s.instance_id, bidx
                ORDER BY s.metric_id, s.instance_id, bidx";

        return $this->fetchAll($sql);
    }

    /**
     * Hour or day rollups aggregated onto the grid.
     *
     * Averages are recomputed as SUM(sum_value)/SUM(sample_count), never as an
     * average of averages - that is exactly why the rollup table stores sum and
     * count instead of a precomputed mean.
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchRollupBuckets(
        int $assetId,
        array $metricIds,
        \DateTimeImmutable $gridStart,
        \DateTimeImmutable $gridEnd,
        int $step,
        string $resolution
    ): array {
        $bucket = $resolution === self::RES_DAY ? self::BUCKET_DAY : self::BUCKET_HOUR;
        $ids = implode(',', array_map('intval', $metricIds));
        $startSql = $this->esc(self::sqlUtc($gridStart));
        $endSql   = $this->esc(self::sqlUtc($gridEnd));
        $assetId  = (int) $assetId;
        $step     = (int) $step;

        $sql = "SELECT r.metric_id AS metric_id,
                       r.instance_id AS instance_id,
                       FLOOR(TIMESTAMPDIFF(SECOND, '$startSql', r.period_start) / $step) AS bidx,
                       SUM(r.sum_value) AS sum_value,
                       SUM(r.sample_count) AS n,
                       MIN(r.min_value) AS min_value,
                       MAX(r.max_value) AS max_value
                FROM device_metric_rollups r FORCE INDEX (PRIMARY)
                WHERE r.bucket = '$bucket'
                  AND r.asset_id = $assetId
                  AND r.metric_id IN ($ids)
                  AND r.period_start >= '$startSql'
                  AND r.period_start <  '$endSql'
                GROUP BY r.metric_id, r.instance_id, bidx
                ORDER BY r.metric_id, r.instance_id, bidx";

        return $this->fetchAll($sql);
    }

    /**
     * Labels for every non-host instance appearing in a grouped result set.
     *
     * @param array<string,array<int,array>> $grouped
     * @return array<int,array{instance_key:string,instance_label:?string,metric_dim:string}>
     */
    private function instanceMetaFor(int $assetId, array $grouped): array
    {
        $ids = [];
        foreach ($grouped as $byInstance) {
            foreach (array_keys($byInstance) as $instanceId) {
                $instanceId = (int) $instanceId;
                if ($instanceId !== self::HOST_INSTANCE_ID) {
                    $ids[$instanceId] = true;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_map('intval', array_keys($ids)));
        $assetId = (int) $assetId;
        $rows = $this->fetchAll(
            "SELECT instance_id, metric_dim, instance_key, instance_label
             FROM device_metric_instances
             WHERE asset_id = $assetId AND instance_id IN ($idList)"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['instance_id']] = [
                'instance_key'   => (string) $row['instance_key'],
                'instance_label' => $row['instance_label'] !== null && $row['instance_label'] !== ''
                    ? (string) $row['instance_label']
                    : (string) $row['instance_key'],
                'metric_dim'     => (string) $row['metric_dim'],
            ];
        }
        return $out;
    }

    // ----------------------------------------------------------- capabilities

    /**
     * What this device actually reports, so the UI can hide cards it cannot draw.
     *
     * Every registry key is returned with a status, because "we have never seen
     * this metric from this device" and "this device stopped reporting an hour
     * ago" require different UI and must not be collapsed into an empty chart:
     *   available   - has data, newest point inside STALE_AFTER_SECONDS
     *   stale       - has data, but nothing recent (device offline, collector broken)
     *   unavailable - no sample and no rollup has ever existed for this key
     * Combined with the metric's tier, 'unavailable' is readable: an OPTIONAL-tier
     * key with no data means unsupported hardware (no NVIDIA GPU), a COLLECTOR-tier
     * key with no data means the collector script is not deployed, and a
     * VENDOR-tier key with no data on a device that reports nothing at all means
     * collection has simply not run yet.
     *
     * The MIN/MAX-per-group queries below read only the clustered primary keys -
     * (asset_id, metric_id, instance_id, sampled_at) and (bucket, asset_id,
     * metric_id, instance_id, period_start) - so every column they touch is a key
     * part and the plan is an index-only ref scan of one device's slice, verified
     * with EXPLAIN (type=ref, key=PRIMARY, Using index). FORCE INDEX (PRIMARY) is
     * there because on the rollups table the optimiser otherwise prefers the much
     * weaker idx_rollup_period (bucket only), which scans every device's rollups
     * rather than this one's. Sample COUNTs are deliberately absent: COUNT(*) would
     * turn these into a full scan of the device's entire history for no UI benefit.
     */
    public function capabilities(int $assetId, ?\DateTimeImmutable $now = null): array
    {
        $assetId = (int) $assetId;
        $now = $now === null ? new \DateTimeImmutable('now', new \DateTimeZone('UTC')) : self::toUtc($now);

        $keyById = array_flip($this->metricIdMap());

        // instance_id => label metadata for this asset (host sentinel has no row).
        $instanceMeta = [];
        foreach ($this->fetchAll(
            "SELECT instance_id, metric_dim, instance_key, instance_label
             FROM device_metric_instances WHERE asset_id = $assetId"
        ) as $row) {
            $instanceMeta[(int) $row['instance_id']] = [
                'instance_key'   => (string) $row['instance_key'],
                'instance_label' => $row['instance_label'] !== null && $row['instance_label'] !== ''
                    ? (string) $row['instance_label']
                    : (string) $row['instance_key'],
                'metric_dim'     => (string) $row['metric_dim'],
            ];
        }

        // metric_key => ['first'=>ts,'last'=>ts,'instances'=>[id=>['first'=>,'last'=>]]]
        $seen = [];
        $collect = function (array $rows, string $firstCol, string $lastCol) use (&$seen, $keyById): void {
            foreach ($rows as $row) {
                $mid = (int) $row['metric_id'];
                if (!isset($keyById[$mid])) {
                    continue;
                }
                $key = $keyById[$mid];
                $instanceId = (int) $row['instance_id'];
                $first = self::tsFromDbUtc($row[$firstCol]);
                $last  = self::tsFromDbUtc($row[$lastCol]);
                if ($first === null || $last === null) {
                    continue;
                }
                if (!isset($seen[$key])) {
                    $seen[$key] = ['first' => $first, 'last' => $last, 'instances' => []];
                } else {
                    $seen[$key]['first'] = min($seen[$key]['first'], $first);
                    $seen[$key]['last']  = max($seen[$key]['last'], $last);
                }
                if (!isset($seen[$key]['instances'][$instanceId])) {
                    $seen[$key]['instances'][$instanceId] = ['first' => $first, 'last' => $last];
                } else {
                    $seen[$key]['instances'][$instanceId]['first'] = min($seen[$key]['instances'][$instanceId]['first'], $first);
                    $seen[$key]['instances'][$instanceId]['last']  = max($seen[$key]['instances'][$instanceId]['last'], $last);
                }
            }
        };

        $collect($this->fetchAll(
            "SELECT metric_id, instance_id, MIN(sampled_at) AS first_at, MAX(sampled_at) AS last_at
             FROM device_metric_samples FORCE INDEX (PRIMARY)
             WHERE asset_id = $assetId
             GROUP BY metric_id, instance_id"
        ), 'first_at', 'last_at');

        // Rollups are consulted too: raw samples are pruned, so a metric whose raw
        // window has aged out is still a supported metric with usable history.
        $collect($this->fetchAll(
            "SELECT metric_id, instance_id, MIN(period_start) AS first_at, MAX(period_start) AS last_at
             FROM device_metric_rollups FORCE INDEX (PRIMARY)
             WHERE bucket = '" . self::BUCKET_DAY . "' AND asset_id = $assetId
             GROUP BY metric_id, instance_id"
        ), 'first_at', 'last_at');

        $nowTs = $now->getTimestamp();
        $metrics = [];
        $availableCount = 0;
        $deviceLast = null;
        $deviceFirst = null;

        foreach (MetricRegistry::keys() as $key) {
            $spec = MetricRegistry::spec($key);
            if ($spec === null) {
                continue;
            }
            $entry = $seen[$key] ?? null;
            if ($entry === null) {
                $status = self::STATUS_UNAVAILABLE;
                $instances = [];
                $firstAt = null;
                $lastAt = null;
            } else {
                $firstAt = $entry['first'];
                $lastAt  = $entry['last'];
                $status = ($nowTs - $lastAt) <= self::STALE_AFTER_SECONDS
                    ? self::STATUS_AVAILABLE
                    : self::STATUS_STALE;
                if ($status === self::STATUS_AVAILABLE) {
                    $availableCount++;
                }
                $deviceLast = $deviceLast === null ? $lastAt : max($deviceLast, $lastAt);
                $deviceFirst = $deviceFirst === null ? $firstAt : min($deviceFirst, $firstAt);

                $instances = [];
                foreach ($entry['instances'] as $instanceId => $window) {
                    $instances[] = [
                        'instance_id'    => (int) $instanceId,
                        'instance_key'   => $instanceMeta[$instanceId]['instance_key'] ?? null,
                        'instance_label' => $instanceMeta[$instanceId]['instance_label'] ?? null,
                        'host_level'     => ((int) $instanceId) === self::HOST_INSTANCE_ID,
                        'first_sample_at' => gmdate('Y-m-d\TH:i:s\Z', $window['first']),
                        'last_sample_at'  => gmdate('Y-m-d\TH:i:s\Z', $window['last']),
                    ];
                }
                usort($instances, static function ($a, $b) {
                    if ($a['host_level'] !== $b['host_level']) {
                        return $a['host_level'] ? -1 : 1;
                    }
                    return strnatcasecmp((string) $a['instance_key'], (string) $b['instance_key'])
                        ?: ($a['instance_id'] <=> $b['instance_id']);
                });
            }

            $metrics[] = [
                'key'             => $spec['key'],
                'display'         => $spec['display'],
                'unit'            => $spec['unit'],
                'kind'            => $spec['kind'],
                'dim'             => $spec['dim'],
                'precision'       => $spec['precision'],
                'tier'            => $spec['tier'],
                'value_min'       => $spec['min'],
                'value_max'       => $spec['max'],
                'status'          => $status,
                'has_data'        => $entry !== null,
                'first_sample_at' => $firstAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $firstAt),
                'last_sample_at'  => $lastAt === null ? null : gmdate('Y-m-d\TH:i:s\Z', $lastAt),
                'instances'       => $instances,
            ];
        }

        return [
            'asset_id'         => $assetId,
            'has_any_data'     => $deviceLast !== null,
            'first_sample_at'  => $deviceFirst === null ? null : gmdate('Y-m-d\TH:i:s\Z', $deviceFirst),
            'last_sample_at'   => $deviceLast === null ? null : gmdate('Y-m-d\TH:i:s\Z', $deviceLast),
            'stale_after_seconds' => self::STALE_AFTER_SECONDS,
            'available_count'  => $availableCount,
            'metric_count'     => count($metrics),
            'collection_state' => $this->collectionState($assetId),
            'metrics'          => $metrics,
        ];
    }

    /**
     * Read-only view of device_metric_collection_state for one asset, so the UI can
     * say "the collector last failed with X" instead of showing an empty chart and
     * letting the technician guess whether the device or the integration is broken.
     * Timestamps in this table share the samples table's UTC clock.
     *
     * @return array<int,array<string,mixed>>
     */
    public function collectionState(int $assetId): array
    {
        $assetId = (int) $assetId;
        $rows = $this->fetchAll(
            "SELECT cs.integration_id, cs.last_collected_at, cs.last_sample_at, cs.last_error,
                    cs.consecutive_failures, i.name AS integration_name, i.type AS integration_type
             FROM device_metric_collection_state cs
             LEFT JOIN rmm_integrations i ON i.id = cs.integration_id
             WHERE cs.asset_id = $assetId
             ORDER BY cs.integration_id"
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'integration_id'       => (int) $row['integration_id'],
                'integration_name'     => $row['integration_name'],
                'integration_type'     => $row['integration_type'],
                'last_collected_at'    => self::isoFromDbUtc($row['last_collected_at']),
                'last_sample_at'       => self::isoFromDbUtc($row['last_sample_at']),
                'last_error'           => $row['last_error'],
                'consecutive_failures' => (int) $row['consecutive_failures'],
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- catalog

    /**
     * The registry itself, in API shape. Served from the PHP registry rather than
     * device_metric_defs so the catalogue is correct even before the defs table has
     * been synced on a fresh install; metric_id is annotated when it is known,
     * because that is the one fact the DB owns and the registry does not.
     */
    public function catalog(): array
    {
        $idByKey = $this->metricIdMap();
        $out = [];
        foreach (MetricRegistry::keys() as $key) {
            $spec = MetricRegistry::spec($key);
            if ($spec === null) {
                continue;
            }
            $out[] = [
                'key'       => $spec['key'],
                'display'   => $spec['display'],
                'unit'      => $spec['unit'],
                'kind'      => $spec['kind'],
                'dim'       => $spec['dim'],
                'precision' => $spec['precision'],
                'tier'      => $spec['tier'],
                'value_min' => $spec['min'],
                'value_max' => $spec['max'],
                'metric_id' => isset($idByKey[$key]) ? (int) $idByKey[$key] : null,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------- time input

    /**
     * Parse a user-supplied timestamp into UTC.
     *
     * Accepted:
     *   "now"                     - the reference instant
     *   "-24h", "now-7d", "+1h"   - offset from it (s, m, h, d, w)
     *   "1757030400"              - epoch seconds (9-11 digits)
     *   "2026-09-05T12:00:00Z"    - any ISO-8601 form; an explicit offset is honoured
     *   "2026-09-05 12:00:00"     - a bare datetime, interpreted as UTC
     *
     * A bare datetime means UTC because the whole metric store is UTC. Guessing the
     * app's local zone here would silently shift every chart by the UTC offset.
     */
    public static function parseTime(string $value, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $now = $now === null ? new \DateTimeImmutable('now', $utc) : self::toUtc($now);
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (strcasecmp($value, 'now') === 0) {
            return $now;
        }
        if (preg_match('/^(?:now)?\s*([+-])\s*(\d+)\s*([smhdw])$/i', $value, $m)) {
            $units = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
            $delta = (int) $m[2] * $units[strtolower($m[3])];
            $ts = $m[1] === '-' ? $now->getTimestamp() - $delta : $now->getTimestamp() + $delta;
            return (new \DateTimeImmutable('@' . $ts))->setTimezone($utc);
        }
        if (preg_match('/^\d{9,11}$/', $value)) {
            return (new \DateTimeImmutable('@' . (int) $value))->setTimezone($utc);
        }
        try {
            $parsed = new \DateTimeImmutable($value, $utc);
        } catch (\Exception $e) {
            return null;
        }
        return $parsed->setTimezone($utc);
    }

    // --------------------------------------------------------------- internals

    /** metric_key => metric_id, loaded once. Keys absent from the registry are ignored. */
    private function metricIdMap(): array
    {
        if ($this->metricIdByKey !== null) {
            return $this->metricIdByKey;
        }
        $map = [];
        foreach ($this->fetchAll("SELECT metric_id, metric_key FROM device_metric_defs") as $row) {
            $key = (string) $row['metric_key'];
            if (MetricRegistry::exists($key)) {
                $map[$key] = (int) $row['metric_id'];
            }
        }
        $this->metricIdByKey = $map;
        return $map;
    }

    /** @return array<int,array<string,mixed>> */
    private function fetchAll(string $sql): array
    {
        $res = $this->mysqli->query($sql);
        if ($res === false) {
            throw new \RuntimeException('Metric query failed: ' . $this->mysqli->error);
        }
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    }

    private function esc(string $value): string
    {
        return $this->mysqli->real_escape_string($value);
    }

    private static function toUtc(\DateTimeImmutable $dt): \DateTimeImmutable
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    /** UTC literal for SQL. sampled_at/period_start are UTC by contract. */
    private static function sqlUtc(\DateTimeImmutable $dt): string
    {
        return self::toUtc($dt)->format('Y-m-d H:i:s');
    }

    private static function iso(\DateTimeImmutable $dt): string
    {
        return self::toUtc($dt)->format('Y-m-d\TH:i:s\Z');
    }

    /** DB datetime (UTC) => epoch seconds, or null. */
    private static function tsFromDbUtc(?string $value): ?int
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        return $dt->getTimestamp();
    }

    /** DB datetime (UTC) => ISO-8601 Z string, or null. */
    private static function isoFromDbUtc(?string $value): ?string
    {
        $ts = self::tsFromDbUtc($value);
        return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
