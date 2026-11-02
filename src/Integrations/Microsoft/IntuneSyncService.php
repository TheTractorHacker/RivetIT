<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * One Intune device sync run: used by the Sync Now button and by cron, so both behave and log identically.
 * Takes a lock (no overlapping runs), writes an intune_sync_log row, fetches devices with a hard time limit, upserts them
 * through IntuneAssetMapper, and on failure records a classified error (auth failed / admin consent missing / throttled ...)
 * in intune_sync_log.errors and .error_code.
 */
final class IntuneSyncService
{
    /** A 'running' row younger than this blocks a new run (a crashed run stops blocking after it). */
    public const LOCK_SECONDS = 300;

    /**
     * @param array<string,mixed> $row a microsoft_integrations row
     * @param array<string,mixed> $clientOptions passed to GraphClientFactory (tests point the client at a mock)
     * @param bool $dryRun true: do everything inside a transaction that is rolled back (the counts are real, no asset is changed) and log the
     *                     run as dry_run so the sync watch ignores it
     * @return array{ok:bool,message:string,error_code:?string,stats:?array,log_id:int,locked:bool,dry_run:bool}
     */
    public static function run(\mysqli $mysqli, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $clientOptions = [], bool $dryRun = false): array
    {
        $id = (int) $row['microsoft_integration_id'];
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false) => ['ok' => false, 'message' => $m, 'error_code' => $code, 'stats' => null, 'log_id' => $log, 'locked' => $locked, 'dry_run' => $dryRun];

        if (empty($row['client_secret_enc']) || empty($row['tenant_id']) || empty($row['client_id'])) {
            return $fail('Save a tenant ID, client ID, and client secret before syncing.');
        }

        $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT id FROM intune_sync_log WHERE microsoft_integration_id=$id
             AND started_at > DATE_SUB(NOW(), INTERVAL " . self::LOCK_SECONDS . " SECOND) AND status='running' LIMIT 1"
        ));
        if ($recent) {
            return $fail('A sync is already running. Please wait for it to finish.', null, 0, true);
        }

        $timeLimitSeconds = max(10, min(600, $timeLimitSeconds));
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($timeLimitSeconds + 30);
        }
        $client = GraphClientFactory::forRow($mysqli, $row, time() + $timeLimitSeconds, $clientOptions);
        $mapper = new IntuneAssetMapper($mysqli, $id, $triggeredBy);
        $logId = $mapper->startSyncLog();

        $inTx = false;
        try {
            $devices = $client->listAllManagedDevices();
            if ($dryRun) {
                mysqli_begin_transaction($mysqli);
                $inTx = true;
            }
            $stats = $mapper->syncDevices($devices);
            if ($inTx) {
                mysqli_rollback($mysqli);
                $inTx = false;
            }
            $mapper->finishSyncLog($logId, $stats, !$dryRun);
            if ($dryRun) {
                self::markDryRun($mysqli, $logId);
            }

            return [
                'ok' => empty($stats['errors']),
                'message' => ($dryRun ? 'Preview (nothing was changed): ' : 'Intune sync complete: ')
                    . "{$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched, {$stats['skipped']} skipped",
                'error_code' => null, 'stats' => $stats, 'log_id' => $logId, 'locked' => false, 'dry_run' => $dryRun,
            ];
        } catch (GraphException $e) {
            $code = $e->errorCode;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $code = GraphException::OTHER;
            $message = 'Sync failed: ' . $e->getMessage();
        }

        if ($inTx) {
            @mysqli_rollback($mysqli);
        }
        if ($dryRun) {
            self::markDryRun($mysqli, $logId);
        }
        $m = mysqli_real_escape_string($mysqli, substr($message, 0, 4000));
        $c = mysqli_real_escape_string($mysqli, $code);
        mysqli_query($mysqli, "UPDATE intune_sync_log SET finished_at=NOW(), status='failed', errors='$m', error_code='$c' WHERE id=$logId");

        return $fail($message, $code, $logId);
    }

    /** intune_sync_log.dry_run exists from DB 2.6.225; before it, a preview simply is not flagged. */
    private static function markDryRun(\mysqli $db, int $logId): void
    {
        try {
            mysqli_query($db, 'UPDATE intune_sync_log SET dry_run = 1 WHERE id = ' . (int) $logId);
        } catch (\Throwable) {
        }
    }
}
