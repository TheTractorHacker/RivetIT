<?php

namespace ITFlow\Metrics;

/**
 * Deletes device metric history that has aged out of its tier.
 *
 * THE POLICY, top to bottom:
 *   raw samples          kept config_metrics_raw_retention_days  (default 14)
 *   bucket='hour' rows   kept config_metrics_hour_retention_days (default 90)
 *   bucket='day' rows    kept FOREVER - they are three doubles and an int per
 *                        asset/metric/instance/day, roughly 1000 series x 365
 *                        rows a year on this fleet. Year-over-year comparison is
 *                        the whole point of keeping them; nothing here deletes them.
 *
 * CHUNKED DELETES, ALWAYS. device_metric_samples has no partitioning, so a single
 * `DELETE ... WHERE sampled_at < cutoff` covering two weeks of a 26-device fleet
 * would take one enormous set of row locks, hold them for the length of the
 * statement, and block every concurrent ingest behind it. Instead each tier
 * deletes in bounded LIMIT batches in a loop, pausing briefly between batches so
 * the ingest path can interleave. The loop stops on a short batch, a chunk cap,
 * or a wall-clock budget - whichever comes first - and reports whether it
 * finished, so an operator can see that a first prune of a large backlog needs
 * another pass rather than assuming it silently did nothing.
 *
 * NEVER PRUNE AHEAD OF THE ROLLUPS. The cutoff for raw samples is clamped to the
 * hour tier's rolled_through, and the cutoff for hour rollups to the day tier's.
 * If rollups have been failing for a week, retention stops at the point the
 * rollups reached instead of deleting samples that were never aggregated. Data
 * loss from a broken cron is not an acceptable outcome of a cleanup job.
 *
 * TIME CONVENTION: sampled_at, period_start and rolled_through are UTC (a
 * deliberate divergence from the rest of the app, which stores local time), so
 * every cutoff this class computes comes from gmdate() and every comparison it
 * makes is UTC-to-UTC. Using date() here would shift the horizon by the local
 * offset and prune up to six hours of data early.
 *
 * This class writes to exactly two tables: device_metric_samples and
 * device_metric_rollups. It never touches assets or asset_rmm_links.
 */
class MetricRetentionService
{
    public const DEFAULT_RAW_RETENTION_DAYS  = 14;
    public const DEFAULT_HOUR_RETENTION_DAYS = 90;

    /** Rows per DELETE statement. Small enough to keep lock windows short. */
    public const DEFAULT_CHUNK_SIZE = 5000;

    /** Hard stop on the delete loop per tier. 20k chunks x 5k rows = 100M rows. */
    public const MAX_CHUNKS_PER_TIER = 20000;

    /** Wall-clock budget for the whole prune(), in seconds. */
    public const DEFAULT_TIME_BUDGET_SECONDS = 300;

    /** Refuse to act on an obviously wrong setting - 1 day of raw is the floor. */
    public const MIN_RAW_RETENTION_DAYS  = 1;
    public const MIN_HOUR_RETENTION_DAYS = 2;

    /** When true, cutoffs and counts are computed but nothing is deleted. */
    public bool $dryRun = false;

    public int $chunkSize = self::DEFAULT_CHUNK_SIZE;

    public int $maxChunksPerTier = self::MAX_CHUNKS_PER_TIER;

    public int $timeBudgetSeconds = self::DEFAULT_TIME_BUDGET_SECONDS;

    /** Pause between delete batches, microseconds. Lets ingest interleave. */
    public int $chunkPauseMicroseconds = 10000;

    /** Clamp cutoffs to the rollup watermarks. Turn off only for a forced purge. */
    public bool $respectRollupWatermarks = true;

    private \mysqli $mysqli;

    private int $rawRetentionDays;

    private int $hourRetentionDays;

    /** @var string[] */
    private array $errors = [];

    public function __construct(
        \mysqli $mysqli,
        int $rawRetentionDays = self::DEFAULT_RAW_RETENTION_DAYS,
        int $hourRetentionDays = self::DEFAULT_HOUR_RETENTION_DAYS
    ) {
        $this->mysqli = $mysqli;
        $this->rawRetentionDays  = $rawRetentionDays;
        $this->hourRetentionDays = $hourRetentionDays;
    }

    /**
     * Build from the settings row, falling back to the documented defaults when a
     * column is missing (an install that has not run the migration yet) or holds
     * a value too small to be meant seriously.
     */
    public static function fromSettings(\mysqli $mysqli): self
    {
        $raw  = self::DEFAULT_RAW_RETENTION_DAYS;
        $hour = self::DEFAULT_HOUR_RETENTION_DAYS;

        $res = $mysqli->query(
            "SELECT `config_metrics_raw_retention_days`, `config_metrics_hour_retention_days`
               FROM `settings` WHERE `company_id` = 1 LIMIT 1"
        );
        if ($res !== false) {
            $row = $res->fetch_assoc();
            $res->free();
            if (is_array($row)) {
                if (isset($row['config_metrics_raw_retention_days'])
                    && (int) $row['config_metrics_raw_retention_days'] >= self::MIN_RAW_RETENTION_DAYS) {
                    $raw = (int) $row['config_metrics_raw_retention_days'];
                }
                if (isset($row['config_metrics_hour_retention_days'])
                    && (int) $row['config_metrics_hour_retention_days'] >= self::MIN_HOUR_RETENTION_DAYS) {
                    $hour = (int) $row['config_metrics_hour_retention_days'];
                }
            }
        }

        return new self($mysqli, $raw, $hour);
    }

    public function rawRetentionDays(): int
    {
        return $this->rawRetentionDays;
    }

    public function hourRetentionDays(): int
    {
        return $this->hourRetentionDays;
    }

    /**
     * Prune every tier.
     *
     * @return array{
     *   raw:array<string,mixed>,
     *   hour_rollups:array<string,mixed>,
     *   day_rollups:array<string,mixed>,
     *   deleted_total:int,
     *   ok:bool,
     *   errors:string[],
     *   duration_ms:int,
     *   dry_run:bool
     * }
     */
    public function prune(): array
    {
        $started = microtime(true);
        $this->errors = [];
        $deadline = $started + max(1, $this->timeBudgetSeconds);

        $raw  = $this->pruneRawSamples($deadline);
        $hour = $this->pruneHourRollups($deadline);

        // Stated explicitly rather than omitted, so a reader of the summary can
        // see that day rollups were considered and deliberately kept.
        $day = [
            'tier'    => 'day_rollups',
            'ran'     => false,
            'reason'  => 'day rollups are retained indefinitely by policy',
            'cutoff'  => null,
            'deleted' => 0,
            'chunks'  => 0,
            'complete' => true,
            'error'   => null,
        ];

        return [
            'raw'           => $raw,
            'hour_rollups'  => $hour,
            'day_rollups'   => $day,
            'deleted_total' => $raw['deleted'] + $hour['deleted'],
            'ok'            => ($raw['error'] === null && $hour['error'] === null),
            'errors'        => $this->errors,
            'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
            'dry_run'       => $this->dryRun,
        ];
    }

    /**
     * Delete raw samples older than the raw horizon.
     *
     * @return array{tier:string,ran:bool,reason:?string,cutoff:?string,deleted:int,chunks:int,complete:bool,error:?string}
     */
    public function pruneRawSamples(?float $deadline = null): array
    {
        $result = $this->emptyTierResult('raw');

        if ($this->rawRetentionDays < self::MIN_RAW_RETENTION_DAYS) {
            $result['reason'] = "raw retention of {$this->rawRetentionDays} day(s) is below the safe minimum; nothing pruned";
            $this->addError($result['reason']);
            return $result;
        }

        $cutoff = $this->utcCutoff($this->rawRetentionDays);

        if ($this->respectRollupWatermarks) {
            $watermark = $this->watermark(MetricRollupService::BUCKET_HOUR);
            if ($watermark === null) {
                $result['reason'] = 'hour rollups have never run; raw samples kept until they do';
                return $result;
            }
            if ($watermark < $cutoff) {
                // Rollups are behind. Prune only what has actually been aggregated.
                $cutoff = $watermark;
            }
        }

        $result['cutoff'] = $cutoff;
        $result['ran'] = true;

        $where = "`sampled_at` < '" . $this->mysqli->real_escape_string($cutoff) . "'";

        if ($this->dryRun) {
            $count = $this->countRows('device_metric_samples', $where);
            if ($count === null) {
                $result['error'] = $this->lastError();
                return $result;
            }
            $result['deleted'] = $count;
            $result['reason']  = 'dry run: rows matched, none deleted';
            return $result;
        }

        return $this->deleteInChunks($result, 'device_metric_samples', $where, $deadline);
    }

    /**
     * Delete hour rollups older than the hour horizon. Day rollups are untouched.
     *
     * @return array{tier:string,ran:bool,reason:?string,cutoff:?string,deleted:int,chunks:int,complete:bool,error:?string}
     */
    public function pruneHourRollups(?float $deadline = null): array
    {
        $result = $this->emptyTierResult('hour_rollups');

        if ($this->hourRetentionDays < self::MIN_HOUR_RETENTION_DAYS) {
            $result['reason'] = "hour retention of {$this->hourRetentionDays} day(s) is below the safe minimum; nothing pruned";
            $this->addError($result['reason']);
            return $result;
        }

        $cutoff = $this->utcCutoff($this->hourRetentionDays);

        if ($this->respectRollupWatermarks) {
            // An hour bucket may only be discarded once the day tier has consumed
            // it, or the day history develops holes that cannot be rebuilt.
            $watermark = $this->watermark(MetricRollupService::BUCKET_DAY);
            if ($watermark === null) {
                $result['reason'] = 'day rollups have never run; hour rollups kept until they do';
                return $result;
            }
            if ($watermark < $cutoff) {
                $cutoff = $watermark;
            }
        }

        $result['cutoff'] = $cutoff;
        $result['ran'] = true;

        $where = "`bucket` = 'hour' AND `period_start` < '" . $this->mysqli->real_escape_string($cutoff) . "'";

        if ($this->dryRun) {
            $count = $this->countRows('device_metric_rollups', $where);
            if ($count === null) {
                $result['error'] = $this->lastError();
                return $result;
            }
            $result['deleted'] = $count;
            $result['reason']  = 'dry run: rows matched, none deleted';
            return $result;
        }

        return $this->deleteInChunks($result, 'device_metric_rollups', $where, $deadline);
    }

    /** Failures from the most recent prune(), newest last. @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The chunked delete loop. $where is already-escaped SQL built by the caller;
     * $table is a literal from this file, never user input.
     *
     * @param array{tier:string,ran:bool,reason:?string,cutoff:?string,deleted:int,chunks:int,complete:bool,error:?string} $result
     * @return array{tier:string,ran:bool,reason:?string,cutoff:?string,deleted:int,chunks:int,complete:bool,error:?string}
     */
    private function deleteInChunks(array $result, string $table, string $where, ?float $deadline): array
    {
        $limit = max(100, $this->chunkSize);
        $sql = "DELETE FROM `{$table}` WHERE {$where} LIMIT {$limit}";

        while ($result['chunks'] < $this->maxChunksPerTier) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                $result['complete'] = false;
                $result['reason'] = 'time budget reached; re-run to continue pruning';
                return $result;
            }

            if ($this->mysqli->query($sql) === false) {
                $result['error'] = "delete from {$table} failed: " . $this->mysqli->error;
                $this->addError($result['error']);
                $result['complete'] = false;
                return $result;
            }

            $affected = (int) $this->mysqli->affected_rows;
            $result['deleted'] += max(0, $affected);
            $result['chunks']++;

            if ($affected < $limit) {
                // A short batch means the range is exhausted.
                $result['complete'] = true;
                return $result;
            }

            if ($this->chunkPauseMicroseconds > 0) {
                usleep($this->chunkPauseMicroseconds);
            }
        }

        $result['complete'] = false;
        $result['reason'] = 'chunk limit reached; re-run to continue pruning';
        return $result;
    }

    /** @return int|null null on query failure */
    private function countRows(string $table, string $where): ?int
    {
        $res = $this->mysqli->query("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        if ($res === false) {
            $this->addError("count on {$table} failed: " . $this->mysqli->error);
            return null;
        }
        $row = $res->fetch_row();
        $res->free();

        return is_array($row) ? (int) $row[0] : 0;
    }

    /**
     * A rollup tier's rolled_through, or null when the tier has never rolled.
     * Read directly rather than through MetricRollupService so retention has no
     * dependency on a service it does not otherwise need.
     */
    private function watermark(string $bucket): ?string
    {
        $stmt = $this->mysqli->prepare(
            "SELECT `rolled_through` FROM `device_metric_rollup_state` WHERE `bucket` = ? LIMIT 1"
        );
        if ($stmt === false) {
            $this->addError('rollup state read failed: ' . $this->mysqli->error);
            return null;
        }
        $stmt->bind_param('s', $bucket);
        if (!$stmt->execute()) {
            $this->addError('rollup state read failed: ' . $stmt->error);
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = ($res !== false) ? $res->fetch_row() : null;
        $stmt->close();

        if (!is_array($row) || $row[0] === null) {
            return null;
        }

        $value = trim((string) $row[0]);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return $value;
    }

    /** Now minus N days, in UTC, to match sampled_at and period_start. */
    private function utcCutoff(int $days): string
    {
        return gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    }

    /** @return array{tier:string,ran:bool,reason:?string,cutoff:?string,deleted:int,chunks:int,complete:bool,error:?string} */
    private function emptyTierResult(string $tier): array
    {
        return [
            'tier'     => $tier,
            'ran'      => false,
            'reason'   => null,
            'cutoff'   => null,
            'deleted'  => 0,
            'chunks'   => 0,
            'complete' => true,
            'error'    => null,
        ];
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
        return $this->errors !== [] ? $this->errors[count($this->errors) - 1] : 'unknown retention error';
    }
}
