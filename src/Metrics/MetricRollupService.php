<?php

namespace ITFlow\Metrics;

/**
 * Rolls raw device_metric_samples up into device_metric_rollups.
 *
 * TWO TIERS, ONE DIRECTION OF TRAVEL:
 *   raw samples  -> bucket='hour'   (GROUP BY the hour of sampled_at)
 *   bucket='hour'-> bucket='day'    (GROUP BY the day of period_start)
 * The day tier is built from the hour tier, never from raw, so a day costs 24
 * grouped rows instead of thousands and the two tiers can never disagree.
 *
 * SUM AND COUNT, NEVER AN AVERAGE. device_metric_rollups carries
 * min/max/sum/sample_count. Storing a precomputed average would make the day
 * tier an average-of-averages the moment the hour buckets held different sample
 * counts (a device that was offline for 40 minutes of one hour), and that error
 * compounds at every coarser tier. sum+count re-aggregates exactly:
 * day.sum = SUM(hour.sum), day.count = SUM(hour.count), and the reader divides.
 *
 * IDEMPOTENT BY CONSTRUCTION. Every write is
 * INSERT ... SELECT ... GROUP BY ... ON DUPLICATE KEY UPDATE against the
 * composite primary key (bucket, asset_id, metric_id, instance_id, period_start),
 * and each bucket is RECOMPUTED from its source rather than incremented. Running
 * this twice, or ten times, over the same window produces the same rows. That is
 * what makes it safe to always re-roll the current, still-filling bucket so that
 * samples which land late are picked up on the next pass.
 *
 * CATCH-UP. rolled_through is only ever advanced after a tier has completed
 * without error, and the work is done in bounded chunks (a day of hours, a week
 * of days) in a loop, so an install that was down for a week catches up over
 * several statements instead of one statement that holds locks for minutes.
 *
 * OUT-OF-ORDER DATA. The forward-only watermark cannot see a backfill that lands
 * BEHIND it - a device that reappears carrying 30 days of Tactical CheckHistory,
 * for instance. The collector, which is holding those samples, calls rewindTo()
 * with the oldest sampled_at it just wrote; that lowers the watermark and the
 * next pass re-rolls the affected window. Re-rolling is free of side effects, so
 * an over-eager rewind costs time and nothing else.
 *
 * TIME CONVENTION: device_metric_samples.sampled_at, device_metric_rollups.
 * period_start and device_metric_rollup_state.rolled_through are ALL UTC. This
 * is a deliberate divergence from the rest of the application, which stores
 * local time. Every timestamp this class generates comes from gmdate() or from a
 * DateTimeImmutable pinned to UTC - never from the process timezone - and every
 * timestamp it renders must be converted for display.
 */
class MetricRollupService
{
    public const BUCKET_HOUR = 'hour';
    public const BUCKET_DAY  = 'day';

    /** Hours of raw data aggregated per statement while catching up. */
    public const DEFAULT_HOURS_PER_CHUNK = 24;

    /** Days of hour-rollups aggregated per statement while catching up. */
    public const DEFAULT_DAYS_PER_CHUNK = 7;

    /** Hard stop on the chunk loop so a corrupt watermark cannot spin forever. */
    public const MAX_CHUNKS_PER_TIER = 4000;

    /** Zero-ish datetimes that mean "never rolled" if they reach the state table. */
    private const NULL_DATETIMES = ['', '0000-00-00 00:00:00', '1970-01-01 00:00:00'];

    /** When true, ranges are computed and reported but nothing is written. */
    public bool $dryRun = false;

    public int $hoursPerChunk = self::DEFAULT_HOURS_PER_CHUNK;

    public int $daysPerChunk = self::DEFAULT_DAYS_PER_CHUNK;

    private \mysqli $mysqli;

    /** @var string[] */
    private array $errors = [];

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * Roll the hour tier, then the day tier.
     *
     * The order matters: the day tier reads the hour tier's watermark, so
     * running hours first means a single pass catches up both tiers.
     *
     * @return array{hour:array<string,mixed>,day:array<string,mixed>,ok:bool,errors:string[],duration_ms:int}
     */
    public function run(): array
    {
        $started = microtime(true);
        $this->errors = [];

        $hour = $this->rollHourly();
        $day  = $this->rollDaily();

        return [
            'hour'        => $hour,
            'day'         => $day,
            'ok'          => ($hour['error'] === null && $day['error'] === null),
            'errors'      => $this->errors,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Aggregate raw samples into bucket='hour'.
     *
     * @return array{bucket:string,ran:bool,reason:?string,from:?string,to:?string,chunks:int,rows_written:int,rolled_through:?string,error:?string,dry_run:bool}
     */
    public function rollHourly(): array
    {
        $result = $this->emptyTierResult(self::BUCKET_HOUR);

        // The current, still-filling hour is always included and always re-rolled.
        $currentHour = $this->floorHour(gmdate('Y-m-d H:i:s'));

        $start = $this->startFor(self::BUCKET_HOUR);
        if ($start === false) {
            $result['error'] = $this->lastError();
            return $result;
        }
        if ($start === null) {
            // No watermark and no samples at all: nothing has ever been collected.
            $result['reason'] = 'no raw samples to roll';
            $this->touchRun(self::BUCKET_HOUR);
            return $result;
        }

        // Do not attempt to re-roll a window whose raw rows retention has already
        // deleted - the buckets are already written and recomputing them from a
        // half-pruned source would silently shrink them.
        $oldestRaw = $this->scalar("SELECT MIN(`sampled_at`) FROM `device_metric_samples`");
        if ($oldestRaw === false) {
            $result['error'] = $this->lastError();
            return $result;
        }
        if ($oldestRaw === null) {
            $result['reason'] = 'no raw samples to roll';
            $this->advance(self::BUCKET_HOUR, $currentHour);
            $result['rolled_through'] = $currentHour;
            return $result;
        }
        $safeStart = $this->firstRollableHour($oldestRaw);
        if ($start < $safeStart) {
            $start = $safeStart;
        }

        // Clock skew, or a hand-edited watermark, must not create a backwards range.
        if ($start > $currentHour) {
            $result['reason'] = 'watermark is ahead of the current hour; nothing to roll';
            $this->advance(self::BUCKET_HOUR, $currentHour);
            $result['rolled_through'] = $currentHour;
            return $result;
        }

        $end = $this->addHours($currentHour, 1); // exclusive: covers the partial hour

        $result['from'] = $start;
        $result['to']   = $end;
        $result['ran']  = true;

        if ($this->dryRun) {
            $result['reason'] = 'dry run: no rows written';
            return $result;
        }

        $cursor = $start;
        while ($cursor < $end && $result['chunks'] < self::MAX_CHUNKS_PER_TIER) {
            $chunkEnd = $this->addHours($cursor, $this->hoursPerChunk);
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $written = $this->rollHourChunk($cursor, $chunkEnd);
            if ($written === null) {
                $result['error'] = $this->lastError();
                return $result; // watermark deliberately NOT advanced
            }

            $result['rows_written'] += $written;
            $result['chunks']++;
            $cursor = $chunkEnd;
        }

        if ($cursor < $end) {
            $result['error'] = 'chunk limit reached before catching up; re-run to continue';
            // Partial progress is still real: everything before $cursor is rolled.
            $this->advance(self::BUCKET_HOUR, $this->floorHour($cursor));
            $result['rolled_through'] = $this->floorHour($cursor);
            $this->addError($result['error']);
            return $result;
        }

        // Park the watermark ON the current partial hour, not past it, so the next
        // run re-rolls that bucket and picks up samples that landed after this pass.
        $this->advance(self::BUCKET_HOUR, $currentHour);
        $result['rolled_through'] = $currentHour;

        return $result;
    }

    /**
     * Aggregate bucket='hour' rows into bucket='day'.
     *
     * @return array{bucket:string,ran:bool,reason:?string,from:?string,to:?string,chunks:int,rows_written:int,rolled_through:?string,error:?string,dry_run:bool}
     */
    public function rollDaily(): array
    {
        $result = $this->emptyTierResult(self::BUCKET_DAY);

        $hourState = $this->state(self::BUCKET_HOUR);
        $hourThrough = $hourState !== null ? $this->normalizeDatetime($hourState['rolled_through'] ?? null) : null;
        if ($hourThrough === null) {
            $result['reason'] = 'hour tier has not rolled yet';
            $this->touchRun(self::BUCKET_DAY);
            return $result;
        }
        $hourThrough = $this->floorHour($hourThrough);

        // Everything up to and including the day that contains the hour watermark.
        // That day is partial and is re-rolled on every pass, exactly like the
        // partial hour above.
        $currentDay = $this->floorDay($hourThrough);
        $end = $this->addDays($currentDay, 1);

        $start = $this->startFor(self::BUCKET_DAY);
        if ($start === false) {
            $result['error'] = $this->lastError();
            return $result;
        }
        if ($start === null) {
            $result['reason'] = 'no hour rollups to aggregate';
            $this->touchRun(self::BUCKET_DAY);
            return $result;
        }

        // Same protection as the hour tier: hour rollups are pruned at their own
        // retention horizon, and recomputing a day from a partially pruned set of
        // hours would understate it.
        $oldestHour = $this->scalar(
            "SELECT MIN(`period_start`) FROM `device_metric_rollups` WHERE `bucket` = 'hour'"
        );
        if ($oldestHour === false) {
            $result['error'] = $this->lastError();
            return $result;
        }
        if ($oldestHour === null) {
            $result['reason'] = 'no hour rollups to aggregate';
            $this->advance(self::BUCKET_DAY, $currentDay);
            $result['rolled_through'] = $currentDay;
            return $result;
        }
        $safeStart = $this->firstRollableDay($oldestHour);
        if ($start < $safeStart) {
            $start = $safeStart;
        }

        if ($start > $currentDay) {
            $result['reason'] = 'watermark is ahead of the hour tier; nothing to roll';
            $this->advance(self::BUCKET_DAY, $currentDay);
            $result['rolled_through'] = $currentDay;
            return $result;
        }

        $result['from'] = $start;
        $result['to']   = $end;
        $result['ran']  = true;

        if ($this->dryRun) {
            $result['reason'] = 'dry run: no rows written';
            return $result;
        }

        $cursor = $start;
        while ($cursor < $end && $result['chunks'] < self::MAX_CHUNKS_PER_TIER) {
            $chunkEnd = $this->addDays($cursor, $this->daysPerChunk);
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $written = $this->rollDayChunk($cursor, $chunkEnd);
            if ($written === null) {
                $result['error'] = $this->lastError();
                return $result;
            }

            $result['rows_written'] += $written;
            $result['chunks']++;
            $cursor = $chunkEnd;
        }

        if ($cursor < $end) {
            $result['error'] = 'chunk limit reached before catching up; re-run to continue';
            $this->advance(self::BUCKET_DAY, $this->floorDay($cursor));
            $result['rolled_through'] = $this->floorDay($cursor);
            $this->addError($result['error']);
            return $result;
        }

        $this->advance(self::BUCKET_DAY, $currentDay);
        $result['rolled_through'] = $currentDay;

        return $result;
    }

    /**
     * Lower both watermarks so a window that has already been rolled is rolled
     * again. Called by the collector after ingesting samples older than the
     * watermark (a backfill); harmless when the watermark is already older.
     *
     * @param string $sampledAtUtc 'Y-m-d H:i:s' in UTC
     */
    public function rewindTo(string $sampledAtUtc): bool
    {
        $normalized = $this->normalizeDatetime($sampledAtUtc);
        if ($normalized === null) {
            return false;
        }
        if ($this->dryRun) {
            return true;
        }

        $hour = $this->mysqli->real_escape_string($this->floorHour($normalized));
        $day  = $this->mysqli->real_escape_string($this->floorDay($normalized));

        $okHour = $this->mysqli->query(
            "UPDATE `device_metric_rollup_state`
                SET `rolled_through` = LEAST(`rolled_through`, '{$hour}')
              WHERE `bucket` = 'hour'"
        );
        $okDay = $this->mysqli->query(
            "UPDATE `device_metric_rollup_state`
                SET `rolled_through` = LEAST(`rolled_through`, '{$day}')
              WHERE `bucket` = 'day'"
        );

        if ($okHour === false || $okDay === false) {
            $this->addError('rewindTo failed: ' . $this->mysqli->error);
            return false;
        }

        return true;
    }

    /**
     * One device_metric_rollup_state row.
     *
     * @return array{bucket:string,rolled_through:string,last_run_at:?string}|null
     */
    public function state(string $bucket): ?array
    {
        if ($bucket !== self::BUCKET_HOUR && $bucket !== self::BUCKET_DAY) {
            return null;
        }

        $stmt = $this->mysqli->prepare(
            "SELECT `bucket`,`rolled_through`,`last_run_at`
               FROM `device_metric_rollup_state` WHERE `bucket` = ? LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('s', $bucket);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = ($res !== false) ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!is_array($row)) {
            return null;
        }

        return [
            'bucket'         => (string) $row['bucket'],
            'rolled_through' => (string) $row['rolled_through'],
            'last_run_at'    => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
        ];
    }

    /** Failures from the most recent run(), newest last. @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    // ------------------------------------------------------------------
    // The two aggregation statements
    // ------------------------------------------------------------------

    /**
     * Raw -> hour, for [$startUtc, $endUtc).
     *
     * @return int|null rows affected, or null on SQL failure
     */
    private function rollHourChunk(string $startUtc, string $endUtc): ?int
    {
        $start = $this->mysqli->real_escape_string($startUtc);
        $end   = $this->mysqli->real_escape_string($endUtc);

        // sampled_at is UTC, so the '%Y-%m-%d %H:00:00' truncation produces a UTC
        // period_start. No timezone conversion happens anywhere in this statement.
        $sql = "INSERT INTO `device_metric_rollups`
                    (`bucket`,`asset_id`,`metric_id`,`instance_id`,`period_start`,
                     `min_value`,`max_value`,`sum_value`,`sample_count`)
                SELECT 'hour',
                       `asset_id`,
                       `metric_id`,
                       `instance_id`,
                       DATE_FORMAT(`sampled_at`, '%Y-%m-%d %H:00:00'),
                       MIN(`metric_value`),
                       MAX(`metric_value`),
                       SUM(`metric_value`),
                       COUNT(*)
                  FROM `device_metric_samples`
                 WHERE `sampled_at` >= '{$start}' AND `sampled_at` < '{$end}'
                 GROUP BY `asset_id`, `metric_id`, `instance_id`,
                          DATE_FORMAT(`sampled_at`, '%Y-%m-%d %H:00:00')
                ON DUPLICATE KEY UPDATE
                       `min_value`    = VALUES(`min_value`),
                       `max_value`    = VALUES(`max_value`),
                       `sum_value`    = VALUES(`sum_value`),
                       `sample_count` = VALUES(`sample_count`)";

        if ($this->mysqli->query($sql) === false) {
            $this->addError("hour rollup {$startUtc}..{$endUtc} failed: " . $this->mysqli->error);
            return null;
        }

        // affected_rows counts 1 per insert and 2 per changed update; it is a
        // measure of work done, not of distinct buckets touched.
        return max(0, (int) $this->mysqli->affected_rows);
    }

    /**
     * Hour -> day, for hour buckets in [$startUtc, $endUtc).
     *
     * The source is wrapped in a derived table on purpose. This statement inserts
     * into the same table it selects from (different `bucket` values, so there is
     * no logical overlap), and materialising the aggregate first removes any
     * question of the engine reading rows it is concurrently writing.
     *
     * @return int|null rows affected, or null on SQL failure
     */
    private function rollDayChunk(string $startUtc, string $endUtc): ?int
    {
        $start = $this->mysqli->real_escape_string($startUtc);
        $end   = $this->mysqli->real_escape_string($endUtc);

        // MIN of mins, MAX of maxes, SUM of sums, SUM of counts. Re-aggregating
        // sum+count this way is exact; averaging averages would not be.
        $sql = "INSERT INTO `device_metric_rollups`
                    (`bucket`,`asset_id`,`metric_id`,`instance_id`,`period_start`,
                     `min_value`,`max_value`,`sum_value`,`sample_count`)
                SELECT 'day',
                       `src`.`asset_id`,
                       `src`.`metric_id`,
                       `src`.`instance_id`,
                       `src`.`day_start`,
                       `src`.`min_value`,
                       `src`.`max_value`,
                       `src`.`sum_value`,
                       `src`.`sample_count`
                  FROM (
                        SELECT `asset_id`,
                               `metric_id`,
                               `instance_id`,
                               DATE_FORMAT(`period_start`, '%Y-%m-%d 00:00:00') AS `day_start`,
                               MIN(`min_value`)    AS `min_value`,
                               MAX(`max_value`)    AS `max_value`,
                               SUM(`sum_value`)    AS `sum_value`,
                               SUM(`sample_count`) AS `sample_count`
                          FROM `device_metric_rollups`
                         WHERE `bucket` = 'hour'
                           AND `period_start` >= '{$start}'
                           AND `period_start` <  '{$end}'
                         GROUP BY `asset_id`, `metric_id`, `instance_id`,
                                  DATE_FORMAT(`period_start`, '%Y-%m-%d 00:00:00')
                       ) AS `src`
                ON DUPLICATE KEY UPDATE
                       `min_value`    = VALUES(`min_value`),
                       `max_value`    = VALUES(`max_value`),
                       `sum_value`    = VALUES(`sum_value`),
                       `sample_count` = VALUES(`sample_count`)";

        if ($this->mysqli->query($sql) === false) {
            $this->addError("day rollup {$startUtc}..{$endUtc} failed: " . $this->mysqli->error);
            return null;
        }

        return max(0, (int) $this->mysqli->affected_rows);
    }

    // ------------------------------------------------------------------
    // Watermark handling
    // ------------------------------------------------------------------

    /**
     * Where this tier should start rolling from, before retention clamping.
     *
     * @return string|false|null datetime, null when the tier has never run AND
     *         its source is empty, false when a query failed
     */
    private function startFor(string $bucket)
    {
        $state = $this->state($bucket);
        $watermark = $state !== null ? $this->normalizeDatetime($state['rolled_through']) : null;

        if ($watermark !== null) {
            return $bucket === self::BUCKET_HOUR
                ? $this->floorHour($watermark)
                : $this->floorDay($watermark);
        }

        // First ever run: start at the oldest thing the source still holds, so an
        // initial 30-day Tactical CheckHistory backfill is rolled in full.
        if ($bucket === self::BUCKET_HOUR) {
            $oldest = $this->scalar("SELECT MIN(`sampled_at`) FROM `device_metric_samples`");
            return $oldest === null ? null : $this->floorHour($oldest);
        }

        $oldest = $this->scalar(
            "SELECT MIN(`period_start`) FROM `device_metric_rollups` WHERE `bucket` = 'hour'"
        );
        return $oldest === null ? null : $this->floorDay($oldest);
    }

    /**
     * The oldest hour bucket it is SAFE to (re)compute from raw.
     *
     * When the oldest surviving sample sits mid-hour, that hour is only partly
     * present in raw - and there are two entirely different reasons for that:
     *
     *   a) retention deleted the earlier part of the hour. Recomputing the bucket
     *      would replace a complete aggregate with an understated one.
     *   b) collection simply STARTED mid-hour (a fresh install, a device's first
     *      sample, or a backfill that a rewindTo() just pulled the watermark back
     *      to). Nothing has ever aggregated that hour.
     *
     * The two are told apart by asking whether the bucket already exists: if it
     * does, it was written while the hour was whole and is left alone; if it does
     * not, rolling it from what is there beats leaving a permanent hole at the
     * start of every chart. Getting this wrong in the (b) direction is how a
     * rewound backfill silently never rolls.
     */
    private function firstRollableHour(string $oldestRawUtc): string
    {
        $floor = $this->floorHour($oldestRawUtc);
        if ($floor === $oldestRawUtc) {
            return $floor;
        }
        return $this->bucketExists(self::BUCKET_HOUR, $floor)
            ? $this->addHours($floor, 1)
            : $floor;
    }

    /** Day equivalent of firstRollableHour(), against hour-rollup coverage. */
    private function firstRollableDay(string $oldestHourUtc): string
    {
        $floor = $this->floorDay($oldestHourUtc);
        if ($floor === $oldestHourUtc) {
            return $floor;
        }
        return $this->bucketExists(self::BUCKET_DAY, $floor)
            ? $this->addDays($floor, 1)
            : $floor;
    }

    /**
     * Does any row exist for this bucket and period_start? Answered from
     * idx_rollup_period, so it is an index probe rather than a scan.
     *
     * A failed query answers "yes", which is the conservative direction: it means
     * "assume it was already rolled and leave it alone" rather than recomputing a
     * bucket from a source that may be half pruned.
     */
    private function bucketExists(string $bucket, string $periodStart): bool
    {
        $stmt = $this->mysqli->prepare(
            "SELECT 1 FROM `device_metric_rollups`
              WHERE `bucket` = ? AND `period_start` = ? LIMIT 1"
        );
        if ($stmt === false) {
            $this->addError('rollup coverage probe failed: ' . $this->mysqli->error);
            return true;
        }
        $stmt->bind_param('ss', $bucket, $periodStart);
        if (!$stmt->execute()) {
            $this->addError('rollup coverage probe failed: ' . $stmt->error);
            $stmt->close();
            return true;
        }
        $res = $stmt->get_result();
        $found = ($res !== false) && ($res->fetch_row() !== null);
        $stmt->close();

        return $found;
    }

    /** Move a tier's watermark and stamp last_run_at. UTC. */
    private function advance(string $bucket, string $rolledThroughUtc): bool
    {
        if ($this->dryRun) {
            return true;
        }

        $bucketEsc = $this->mysqli->real_escape_string($bucket);
        $through   = $this->mysqli->real_escape_string($rolledThroughUtc);
        $now       = gmdate('Y-m-d H:i:s'); // UTC, matching sampled_at

        $ok = $this->mysqli->query(
            "INSERT INTO `device_metric_rollup_state` (`bucket`,`rolled_through`,`last_run_at`)
             VALUES ('{$bucketEsc}','{$through}','{$now}')
             ON DUPLICATE KEY UPDATE
                `rolled_through` = VALUES(`rolled_through`),
                `last_run_at`    = VALUES(`last_run_at`)"
        );

        if ($ok === false) {
            $this->addError("could not advance {$bucket} watermark: " . $this->mysqli->error);
            return false;
        }

        return true;
    }

    /** Stamp last_run_at without touching rolled_through (nothing-to-do runs). */
    private function touchRun(string $bucket): void
    {
        if ($this->dryRun) {
            return;
        }

        $bucketEsc = $this->mysqli->real_escape_string($bucket);
        $now = gmdate('Y-m-d H:i:s');

        // Only updates an existing row: a tier that has never rolled must not get
        // a watermark it did not earn.
        $this->mysqli->query(
            "UPDATE `device_metric_rollup_state` SET `last_run_at` = '{$now}'
              WHERE `bucket` = '{$bucketEsc}'"
        );
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    /** @return array{bucket:string,ran:bool,reason:?string,from:?string,to:?string,chunks:int,rows_written:int,rolled_through:?string,error:?string,dry_run:bool} */
    private function emptyTierResult(string $bucket): array
    {
        return [
            'bucket'         => $bucket,
            'ran'            => false,
            'reason'         => null,
            'from'           => null,
            'to'             => null,
            'chunks'         => 0,
            'rows_written'   => 0,
            'rolled_through' => null,
            'error'          => null,
            'dry_run'        => $this->dryRun,
        ];
    }

    /**
     * First column of the first row as a UTC datetime string.
     *
     * Three outcomes, deliberately distinct: a datetime, null when the source is
     * genuinely empty, and false when the QUERY FAILED. Collapsing the last two
     * would let a dead connection look like an empty table and advance a
     * watermark over data that was never rolled.
     *
     * @return string|false|null
     */
    private function scalar(string $sql)
    {
        $res = $this->mysqli->query($sql);
        if ($res === false) {
            $this->addError('query failed: ' . $this->mysqli->error);
            return false;
        }
        $row = $res->fetch_row();
        $res->free();

        if (!is_array($row) || $row[0] === null) {
            return null;
        }

        return $this->normalizeDatetime((string) $row[0]);
    }

    /** 'Y-m-d H:i:s' or null when unusable. */
    private function normalizeDatetime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || in_array($value, self::NULL_DATETIMES, true)) {
            return null;
        }

        return $this->utc($value)?->format('Y-m-d H:i:s');
    }

    /**
     * Parse a naive datetime AS UTC. Every timestamp in this subsystem is UTC,
     * and the process timezone (America/Chicago on this install) must never be
     * allowed to touch it.
     */
    private function utc(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
    }

    private function floorHour(string $utc): string
    {
        return substr($utc, 0, 13) . ':00:00';
    }

    private function floorDay(string $utc): string
    {
        return substr($utc, 0, 10) . ' 00:00:00';
    }

    /**
     * Hour arithmetic in UTC. Done through DateTimeImmutable in an explicit UTC
     * zone rather than strtotime()+date(), because the process timezone has DST:
     * "+24 hours" across a US spring-forward would otherwise land on 01:00 and
     * knock every subsequent bucket boundary out of alignment.
     */
    private function addHours(string $utc, int $hours): string
    {
        $dt = $this->utc($utc);
        if ($dt === null) {
            return $utc;
        }
        return $dt->modify(($hours >= 0 ? '+' : '') . $hours . ' hours')->format('Y-m-d H:i:s');
    }

    /** Day arithmetic in UTC, for the same reason as addHours(). */
    private function addDays(string $utc, int $days): string
    {
        $dt = $this->utc($utc);
        if ($dt === null) {
            return $utc;
        }
        return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d H:i:s');
    }

    private function addError(string $message): void
    {
        $this->errors[] = $message;
        if (count($this->errors) > 50) {
            array_shift($this->errors);
        }
    }

    private function lastError(): string
    {
        return $this->errors !== [] ? $this->errors[count($this->errors) - 1] : 'unknown rollup error';
    }
}
