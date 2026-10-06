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
     * @return array{ok:bool,message:string,error_code:?string,stats:?array,log_id:int,locked:bool}
     */
    public static function run(\mysqli $mysqli, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $clientOptions = []): array
    {
        $id = (int) $row['microsoft_integration_id'];
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false) => ['ok' => false, 'message' => $m, 'error_code' => $code, 'stats' => null, 'log_id' => $log, 'locked' => $locked];

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

        try {
            $devices = $client->listAllManagedDevices();
            $stats = $mapper->syncDevices($devices);
            $mapper->finishSyncLog($logId, $stats);

            return [
                'ok' => empty($stats['errors']),
                'message' => "Intune sync complete: {$stats['created']} created, {$stats['updated']} updated, {$stats['matched']} matched, {$stats['skipped']} skipped",
                'error_code' => null, 'stats' => $stats, 'log_id' => $logId, 'locked' => false,
            ];
        } catch (GraphException $e) {
            $code = $e->errorCode;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $code = GraphException::OTHER;
            $message = 'Sync failed: ' . $e->getMessage();
        }

        $m = mysqli_real_escape_string($mysqli, substr($message, 0, 4000));
        $c = mysqli_real_escape_string($mysqli, $code);
        mysqli_query($mysqli, "UPDATE intune_sync_log SET finished_at=NOW(), status='failed', errors='$m', error_code='$c' WHERE id=$logId");

        return $fail($message, $code, $logId);
    }
}
