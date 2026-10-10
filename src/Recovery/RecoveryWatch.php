<?php

namespace ITFlow\Recovery;

/**
 * The recovery watcher run by cron/cron.php (or, when the main cron is off, cron/backup_cron.php): stale-backup alert,
 * failed/stale integration-sync alerts, a "the restore drill has stopped running" alert, and housekeeping of the logs it owns.
 * Each alert is de-duplicated per 6 hours (RecoveryAlerts). Safe to call every cron tick; the work is a handful of indexed reads.
 */
final class RecoveryWatch
{
    /** @return array<string,mixed> what it found (for the cron log line) */
    public static function run(\mysqli $db, string $appRoot, bool $backupsScheduled, bool $rmmModuleOn = true): array
    {
        $out = [];
        try {
            $out['backup'] = BackupStatus::watchStale($db, $appRoot . '/backups', $backupsScheduled);
        } catch (\Throwable $e) {
            $out['backup'] = 'error: ' . $e->getMessage();
        }
        try {
            $out['sync'] = SyncWatch::run($db, $rmmModuleOn);
        } catch (\Throwable $e) {
            $out['sync'] = 'error: ' . $e->getMessage();
        }
        try {
            $out['drill'] = self::watchDrill($db);
        } catch (\Throwable $e) {
            $out['drill'] = 'error: ' . $e->getMessage();
        }
        try {
            self::housekeeping($db);
        } catch (\Throwable $e) {
            $out['housekeeping'] = 'error: ' . $e->getMessage();
        }

        return $out;
    }

    /** Enabled drill that has not finished a run in 48 hours: the nightly job itself is not running. */
    private static function watchDrill(\mysqli $db): string
    {
        if (RecoverySettings::get($db, 'drill_enabled') !== '1') {
            return 'off';
        }
        $r = @mysqli_query($db, "SELECT TIMESTAMPDIFF(HOUR, MAX(drill_finished_at), NOW()) AS h FROM restore_drill_log WHERE drill_status <> 'running' AND drill_finished_at IS NOT NULL");
        $row = $r ? mysqli_fetch_assoc($r) : null;
        $h = $row && $row['h'] !== null ? (int) $row['h'] : null;
        if ($h !== null && $h <= 48) {
            RecoveryAlerts::clear($db, 'restore_drill.stale');

            return 'ok';
        }
        $what = $h === null ? 'The restore drill is enabled but has never run' : "The restore drill has not run for $h hours";
        RecoveryAlerts::raise($db, 'restore_drill.stale', $what, "$what. Check that the restore_drill.php cron job is installed (Admin > Server Status > Scheduled work).",
            'restore_drill.failed', ['status' => 'stale', 'hours' => $h]);

        return 'stale';
    }

    private static function housekeeping(\mysqli $db): void
    {
        // The main cron now writes an rmm_sync_log row per scheduled tick; keep a month of them (manual runs, triggered_by > 0, are kept).
        @mysqli_query($db, 'DELETE FROM rmm_sync_log WHERE triggered_by = 0 AND started_at < NOW() - INTERVAL 30 DAY LIMIT 5000');
        @mysqli_query($db, 'DELETE FROM backup_runs WHERE run_started_at < NOW() - INTERVAL 800 DAY LIMIT 1000');
        @mysqli_query($db, 'DELETE FROM restore_drill_log WHERE drill_started_at < NOW() - INTERVAL 800 DAY LIMIT 1000');
    }
}
