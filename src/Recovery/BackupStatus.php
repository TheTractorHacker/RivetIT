<?php

namespace ITFlow\Recovery;

/**
 * The status record every backup run leaves behind (table backup_runs, DB 2.6.153): the in-app builder
 * (admin/post/backup.php build_backup, which cron/cron.php and cron/backup_cron.php both call) and deploy/backup.sh.
 *
 * A run is opened with begin() and closed with finish(). If the process dies in between (the builder ends with exit() on a
 * failed mkdir/zip, PHP can hit a fatal error) the shutdown hook closes it as failed, so a crashed backup is never silent.
 * A failed run raises the 'backup.failed' alert; the stale watcher (watchStale) raises 'backup.stale'.
 */
final class BackupStatus
{
    /** @var array<int,\mysqli> runs begun in this process and not yet finished */
    private static array $open = [];
    private static bool $hooked = false;

    /** Pure: ok | stale | none. $ageSeconds is the age of the newest good backup, null when there is none. */
    public static function staleState(?int $ageSeconds, int $staleHours): string
    {
        if ($ageSeconds === null) {
            return 'none';
        }

        return $ageSeconds > $staleHours * 3600 ? 'stale' : 'ok';
    }

    public static function available(\mysqli $db): bool
    {
        $r = @mysqli_query($db, "SHOW TABLES LIKE 'backup_runs'");

        return $r && mysqli_num_rows($r) > 0;
    }

    /** Opens a run record. Returns its id, or null when the table does not exist yet (nothing is recorded, the backup proceeds). */
    public static function begin(\mysqli $db, string $kind): ?int
    {
        if (!self::available($db)) {
            return null;
        }
        $k = mysqli_real_escape_string($db, substr($kind, 0, 20));
        if (!@mysqli_query($db, "INSERT INTO backup_runs (run_kind, run_started_at, run_ok) VALUES ('$k', NOW(), 0)")) {
            return null;
        }
        $id = (int) mysqli_insert_id($db);
        self::$open[$id] = $db;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function([self::class, 'closeOrphans']);
        }

        return $id;
    }

    /** @param array{file?:?string,size?:?int,sha256?:?string,ok:bool,error?:?string,offsite?:?string} $r */
    public static function finish(\mysqli $db, ?int $id, array $r): void
    {
        if ($id === null) {
            return;
        }
        unset(self::$open[$id]);
        $ok = !empty($r['ok']) ? 1 : 0;
        $file = isset($r['file']) ? "'" . mysqli_real_escape_string($db, substr(basename((string) $r['file']), 0, 255)) . "'" : 'NULL';
        $size = isset($r['size']) ? max(0, (int) $r['size']) : 'NULL';
        $sha = isset($r['sha256']) && preg_match('/^[0-9a-f]{64}$/', (string) $r['sha256']) ? "'" . $r['sha256'] . "'" : 'NULL';
        $err = isset($r['error']) && $r['error'] !== '' ? "'" . mysqli_real_escape_string($db, substr((string) $r['error'], 0, 2000)) . "'" : 'NULL';
        $off = isset($r['offsite']) ? "'" . mysqli_real_escape_string($db, substr((string) $r['offsite'], 0, 255)) . "'" : 'NULL';
        @mysqli_query($db, "UPDATE backup_runs SET run_finished_at = NOW(), run_ok = $ok, run_file = $file, run_size = $size, run_sha256 = $sha,
                            run_error = $err, run_offsite_result = $off WHERE run_id = " . (int) $id);
        if ($ok) {
            RecoveryAlerts::clear($db, 'backup.failed');
            RecoveryAlerts::clear($db, 'backup.stale');

            return;
        }
        $msg = 'Backup failed' . ($r['error'] ?? '' ? ': ' . $r['error'] : '.');
        RecoveryAlerts::raise($db, 'backup.failed', substr($msg, 0, 200),
            "A RivetIT backup run did not complete.\n\n" . $msg . "\n\nOpen Admin > Backup to check the history, free disk space and permissions on the backups folder, then run a manual backup to confirm.",
            'backup.failed', ['error' => (string) ($r['error'] ?? ''), 'file' => (string) ($r['file'] ?? '')]);
    }

    /**
     * One call for a run that already happened elsewhere (deploy/backup.sh, run as root, reports through deploy/lib/backup_status.php):
     * records the row with its real start time and raises the same failure alert as an in-app failure. The off-site result
     * ('ok: ...', 'failed: ...', 'not configured') is part of the record; an off-site failure on an otherwise good backup also alerts.
     *
     * @param array{file?:?string,size?:?int,sha256?:?string,ok:bool,error?:?string,offsite?:?string} $r
     */
    public static function recordExternal(\mysqli $db, string $kind, int $startedAt, array $r): ?int
    {
        $id = self::begin($db, $kind);
        if ($id === null) {
            return null;
        }
        @mysqli_query($db, 'UPDATE backup_runs SET run_started_at = FROM_UNIXTIME(' . max(0, $startedAt) . ') WHERE run_id = ' . (int) $id);
        $offsite = $r['offsite'] ?? null;
        self::finish($db, $id, $r);
        if (!empty($r['ok']) && is_string($offsite) && str_starts_with($offsite, 'failed')) {
            self::noteOffsite($db, (string) ($r['file'] ?? ''), $offsite);
        }

        return $id;
    }

    /** Shutdown hook: any run still open here never reached finish(). */
    public static function closeOrphans(): void
    {
        foreach (self::$open as $id => $db) {
            try {
                self::finish($db, $id, ['ok' => false, 'error' => 'the backup process ended before it finished (see the PHP error log)']);
            } catch (\Throwable) {
                // shutting down: nothing more can be done
            }
        }
    }

    /** Records the off-site (S3) outcome on the newest run for this file. */
    public static function noteOffsite(\mysqli $db, string $file, string $result): void
    {
        if (!self::available($db)) {
            return;
        }
        $f = mysqli_real_escape_string($db, basename($file));
        $r = mysqli_real_escape_string($db, substr($result, 0, 255));
        @mysqli_query($db, "UPDATE backup_runs SET run_offsite_result = '$r' WHERE run_file = '$f' ORDER BY run_id DESC LIMIT 1");
        if (str_starts_with($result, 'failed')) {
            RecoveryAlerts::raise($db, 'backup.offsite_failed', 'Off-site backup copy failed: ' . substr($result, 0, 150),
                "The backup $file was saved locally but copying it off-site failed.\n\n$result\n\nCheck Admin > Backup > Remote Storage.",
                'backup.failed', ['error' => $result, 'file' => $file, 'stage' => 'offsite']);
        }
    }

    /**
     * Age in seconds of the newest good backup: the newer of the newest ok run record and the newest backup file on disk
     * (files count so a fresh upgrade, before any run is recorded, is judged by what is really there). Null when neither exists.
     */
    public static function newestGoodAge(\mysqli $db, string $backupDir): ?int
    {
        $ages = [];
        if (self::available($db)) {
            $r = @mysqli_query($db, 'SELECT TIMESTAMPDIFF(SECOND, MAX(run_finished_at), NOW()) AS age FROM backup_runs WHERE run_ok = 1 AND run_finished_at IS NOT NULL');
            $row = $r ? mysqli_fetch_assoc($r) : null;
            if ($row && $row['age'] !== null) {
                $ages[] = max(0, (int) $row['age']);
            }
        }
        $newest = 0;
        foreach (array_merge(glob($backupDir . '/itflow_*.zip') ?: [], glob($backupDir . '/backup-*.enc') ?: []) as $f) {
            $newest = max($newest, (int) @filemtime($f));
        }
        if ($newest > 0) {
            $ages[] = max(0, time() - $newest);
        }

        return $ages === [] ? null : min($ages);
    }

    /** @return array<string,mixed>|null the newest finished run row, with age_seconds */
    public static function last(\mysqli $db): ?array
    {
        if (!self::available($db)) {
            return null;
        }
        $r = @mysqli_query($db, 'SELECT *, TIMESTAMPDIFF(SECOND, run_finished_at, NOW()) AS age_seconds FROM backup_runs WHERE run_finished_at IS NOT NULL ORDER BY run_id DESC LIMIT 1');

        return ($r ? mysqli_fetch_assoc($r) : null) ?: null;
    }

    /**
     * The stale watcher: alert when the newest good backup is older than the threshold. Only when backups are expected at all
     * (scheduled backups on, or at least one backup / run has ever existed).
     *
     * @return string ok | stale | none | not_expected
     */
    public static function watchStale(\mysqli $db, string $backupDir, bool $scheduledOn): string
    {
        $age = self::newestGoodAge($db, $backupDir);
        $hours = max(1, RecoverySettings::int($db, 'backup_stale_hours'));
        $state = self::staleState($age, $hours);
        if ($state === 'none' && !$scheduledOn) {
            return 'not_expected';
        }
        if ($state === 'ok') {
            RecoveryAlerts::clear($db, 'backup.stale');

            return 'ok';
        }
        $what = $age === null ? 'No successful backup exists' : 'The newest successful backup is ' . round($age / 3600, 1) . ' hours old';
        RecoveryAlerts::raise($db, 'backup.stale', $what . " (limit $hours h)",
            "$what, and the limit is $hours hours.\n\nA backup that is not recent is a backup you cannot restore from. Check that the scheduled backup cron job is running (Admin > Server Status), look at Admin > Backup for the last error, and run a manual backup.",
            'backup.stale', ['age_seconds' => $age, 'limit_hours' => $hours]);

        return $state;
    }
}
