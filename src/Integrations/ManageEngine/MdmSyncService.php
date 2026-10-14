<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * One ManageEngine MDM sync run: used by Sync now and by cron so both behave and log identically. READ-ONLY toward ManageEngine.
 * Takes a lock (no overlapping runs), writes an mdm_sync_log row, lists every device (paged), optionally enriches each with the
 * device-detail and device-summary calls, hands the result to MdmDeviceMapper and records a classified error on failure.
 */
final class MdmSyncService
{
    /** A 'running' row younger than this blocks a new run (a crashed run stops blocking after it). */
    public const LOCK_SECONDS = 300;

    /**
     * @param array<string,mixed> $row a manageengine_mdm_integrations row
     * @param array<string,mixed> $clientOptions passed to MdmClientFactory (tests point the client at a mock)
     * @return array{ok:bool,message:string,error_code:?string,stats:?array,log_id:int,locked:bool}
     */
    public static function run(\mysqli $mysqli, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $clientOptions = []): array
    {
        $id = (int) $row['id'];
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false) => ['ok' => false, 'message' => $m, 'error_code' => $code, 'stats' => null, 'log_id' => $log, 'locked' => $locked];

        if (!MdmClientFactory::hasCredentials($row)) {
            return $fail(MdmClientFactory::edition($row) === MdmClientFactory::EDITION_ONPREM ? 'Save the server address and an API key before syncing.' : 'Save a client id, client secret and refresh token before syncing.');
        }

        $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT id FROM mdm_sync_log WHERE integration_id=$id AND started_at > DATE_SUB(NOW(), INTERVAL " . self::LOCK_SECONDS . " SECOND) AND status='running' LIMIT 1"
        ));
        if ($recent) {
            return $fail('A sync is already running. Please wait for it to finish.', null, 0, true);
        }

        $timeLimitSeconds = max(10, min(600, $timeLimitSeconds));
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($timeLimitSeconds + 30);
        }
        $deadline = time() + $timeLimitSeconds;
        $mapper = new MdmDeviceMapper($mysqli, $id, $triggeredBy, MdmDeviceMapper::configFromRow($row));
        // The log row exists before anything can fail (including a refused server address), so the sync watcher sees every failed run.
        $logId = $mapper->startSyncLog();

        try {
            $client = MdmClientFactory::forRow($mysqli, $row, $deadline, $clientOptions);
            $warnings = [];
            $devices = [];
            if ((int) ($row['msp_mode'] ?? 0) === 1) {
                $customers = $client->listCustomers();
                if ($customers === []) {
                    $warnings[] = 'MSP mode is on but ManageEngine returned no customers.';
                }
                foreach ($customers as $c) {
                    $client->setCustomer($c['customer_id']);
                    foreach (self::enrich($client, $client->listAllDevices(), !empty($row['enrich_details']), $deadline, $warnings) as $dev) {
                        $dev['customer_id'] = $c['customer_id'];
                        $dev['customer_name'] = $c['customer_name'];
                        $devices[] = $dev;
                    }
                }
                $client->setCustomer(null);
            } else {
                $devices = self::enrich($client, $client->listAllDevices(), !empty($row['enrich_details']), $deadline, $warnings);
            }
            $stats = $mapper->syncDevices($devices);
            $mapper->pruneUnmatched(array_column($devices, 'mdm_device_id'));
            $stats['warnings'] = $warnings;
            $stats['retired'] = $stats['restored'] = 0;
            if ($stats['errors'] === [] && $devices !== [] && (int) ($row['stale_retire_enabled'] ?? 0) === 1) {
                $r = (new MdmStale($mysqli))->run($id, max(7, (int) ($row['stale_retire_days'] ?? 30)));
                $stats['retired'] = $r['retired'];
                $stats['restored'] = $r['restored'];
            }
            $mapper->finishSyncLog($logId, $stats);
            self::audit($triggeredBy, $id, 'success', "{$stats['total']} devices");

            return [
                'ok' => empty($stats['errors']),
                'message' => "ManageEngine MDM sync complete: {$stats['total']} devices, {$stats['matched']} newly linked, {$stats['created']} assets created, {$stats['updated']} updated, {$stats['unmatched']} need review, {$stats['skipped']} skipped"
                    . ($stats['retired'] ? ", {$stats['retired']} retired for review" : '')
                    . ($warnings ? ' (' . count($warnings) . ' warning' . (count($warnings) === 1 ? '' : 's') . ')' : ''),
                'error_code' => null, 'stats' => $stats, 'log_id' => $logId, 'locked' => false,
            ];
        } catch (MdmException $e) {
            $code = $e->errorCode;
            $message = $e->getMessage();
        } catch (\Throwable $e) {
            $code = MdmException::OTHER;
            $message = 'Sync failed: ' . $e->getMessage();
        }

        $m = mysqli_real_escape_string($mysqli, substr($message, 0, 4000));
        $c = mysqli_real_escape_string($mysqli, $code);
        mysqli_query($mysqli, "UPDATE mdm_sync_log SET finished_at=NOW(), status='failed', errors='$m', error_code='$c' WHERE id=$logId");
        self::audit($triggeredBy, $id, 'failed', $code);

        return $fail($message, $code, $logId);
    }

    private static function audit(int $userId, int $integrationId, string $outcome, string $detail): void
    {
        try {
            \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.sync', $userId > 0 ? $userId : null, 'manageengine_mdm_integration', $integrationId, $outcome, $detail);
        } catch (\Throwable) {
            // auditing never fails a sync
        }
    }

    /**
     * On-premises API keys last 6 months. Within 30 days of the date the administrator entered (and once expired) a notification goes out, at most once a day.
     * Called by cron every minute; cheap (one comparison).
     *
     * @param array<string,mixed> $row
     * @return 'none'|'warned'|'expired'|'ok'
     */
    public static function checkKeyExpiry(\mysqli $mysqli, array $row): string
    {
        if (MdmClientFactory::edition($row) !== MdmClientFactory::EDITION_ONPREM || empty($row['api_key_expires_on']) || (int) ($row['enabled'] ?? 0) !== 1) {
            return 'none';
        }
        $days = self::keyDaysLeft($row);
        if ($days === null || $days > 30) {
            return 'ok';
        }
        if (($row['api_key_warned_on'] ?? '') === date('Y-m-d')) {
            return $days < 0 ? 'expired' : 'warned';
        }
        $id = (int) $row['id'];
        mysqli_query($mysqli, 'UPDATE manageengine_mdm_integrations SET api_key_warned_on = CURDATE() WHERE id = ' . $id);
        $title = $days < 0 ? 'ManageEngine MDM API key has expired' : 'ManageEngine MDM API key expires in ' . $days . ' day' . ($days === 1 ? '' : 's');
        try {
            if (function_exists('appNotify')) {
                appNotify($title, 'Generate a new key in the MDM server (Admin > Integrations > API Key Generation) and paste it under Administration > Integrations > Device Sync. Syncs stop when the key expires.', '/admin/settings_integrations.php?tab=devicesync');
            }
        } catch (\Throwable) {
        }

        return $days < 0 ? 'expired' : 'warned';
    }

    /** Whole days from today to the expiry date the administrator entered (negative = expired), or null when none. @param array<string,mixed> $row */
    public static function keyDaysLeft(array $row): ?int
    {
        $d = (string) ($row['api_key_expires_on'] ?? '');
        if ($d === '' || $d === '0000-00-00') {
            return null;
        }
        $t = strtotime($d . ' 00:00:00');
        if ($t === false) {
            return null;
        }

        return (int) floor(($t - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400);
    }

    /**
     * Normalized devices; with $enrich each also gets the detail and summary calls. An enrichment problem never fails the run:
     * the device keeps its list data (and its previously stored extras) and a warning is recorded. A missing scope or throttling
     * stops further enrichment this run instead of repeating the same failing call for every device.
     *
     * @param list<array<string,mixed>> $listed
     * @param list<string> $warnings
     * @return list<array<string,mixed>>
     */
    private static function enrich(MdmClient $client, array $listed, bool $enrich, int $deadline, array &$warnings): array
    {
        $out = [];
        $detailOn = $enrich;
        $summaryOn = $enrich;
        foreach ($listed as $row) {
            $id = (string) ($row['device_id'] ?? '');
            $detail = null;
            $summary = null;
            $removed = !empty($row['is_removed']);
            if ($id !== '' && !$removed && time() < $deadline - 5) {
                if ($detailOn) {
                    try {
                        $detail = $client->getDevice($id);
                    } catch (MdmException $e) {
                        $detailOn = self::enrichFailed($e, $row, 'detail', $warnings);
                    }
                }
                if ($summaryOn) {
                    try {
                        $summary = $client->getDeviceSummary($id);
                    } catch (MdmException $e) {
                        $summaryOn = self::enrichFailed($e, $row, 'summary', $warnings);
                    }
                }
            }
            $out[] = MdmDeviceMapper::normalize($row, $detail, $summary);
        }

        return $out;
    }

    /**
     * Records a warning for a failed enrichment call; returns whether the same call should be tried for the next device.
     *
     * @param array<string,mixed> $row
     * @param list<string> $warnings
     */
    private static function enrichFailed(MdmException $e, array $row, string $which, array &$warnings): bool
    {
        if (in_array($e->errorCode, [MdmException::SCOPE_MISSING, MdmException::THROTTLED, MdmException::TIME_LIMIT, MdmException::AUTH_FAILED, MdmException::UNREACHABLE], true)) {
            $warnings[] = "device $which calls stopped for this run: " . $e->getMessage();

            return false;
        }
        $warnings[] = ($row['device_name'] ?? $row['device_id'] ?? 'device') . " $which skipped: " . $e->getMessage();

        return true;
    }

    /** True when the saved interval has passed since the last run started (cron calls this every minute). @param array<string,mixed> $row */
    public static function isDue(\mysqli $mysqli, array $row): bool
    {
        if ((int) ($row['enabled'] ?? 0) !== 1 || !MdmClientFactory::hasCredentials($row)) {
            return false;
        }
        $interval = max(5, min(1440, (int) ($row['sync_interval_minutes'] ?? 60)));
        $res = mysqli_query($mysqli, 'SELECT MAX(started_at) AS s, (MAX(started_at) IS NULL OR MAX(started_at) < DATE_SUB(NOW(), INTERVAL ' . $interval . ' MINUTE)) AS due FROM mdm_sync_log WHERE integration_id=' . (int) $row['id'] . " AND triggered_by=0");

        return $res && (int) (mysqli_fetch_assoc($res)['due'] ?? 1) === 1;
    }
}
