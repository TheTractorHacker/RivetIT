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

        if (empty($row['client_secret_enc']) || empty($row['client_id']) || empty($row['refresh_token_enc'])) {
            return $fail('Save a client id, client secret and refresh token before syncing.');
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
        $client = MdmClientFactory::forRow($mysqli, $row, $deadline, $clientOptions);
        $mapper = new MdmDeviceMapper($mysqli, $id, $triggeredBy);
        $logId = $mapper->startSyncLog();

        try {
            $listed = $client->listAllDevices();
            $warnings = [];
            $devices = self::enrich($client, $listed, !empty($row['enrich_details']), $deadline, $warnings);
            $stats = $mapper->syncDevices($devices);
            $mapper->pruneUnmatched(array_column($devices, 'mdm_device_id'));
            $stats['notes'] = self::applyMapping($mysqli, $id, $logId, $triggeredBy, $warnings);
            $stats['warnings'] = $warnings;
            $mapper->finishSyncLog($logId, $stats);

            return [
                'ok' => empty($stats['errors']),
                'message' => "ManageEngine MDM sync complete: {$stats['total']} devices, {$stats['matched']} newly linked, {$stats['updated']} updated, {$stats['unmatched']} need review, {$stats['skipped']} skipped"
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

        return $fail($message, $code, $logId);
    }

    /**
     * The mapping options (docs/MANAGEENGINE_MDM.md), after the devices are linked and refreshed. Everything here is OFF by default and
     * a problem in it never fails the run (it becomes a warning):
     *   1. note every device user in the user -> contact table, auto-mapping exact e-mail matches (own table only);
     *   2. "Assign contact from MDM user" (only when switched on): fill empty asset contacts, history recorded;
     *   3. field mapping (only when the master "Allow MDM to write asset fields" switch is on): write the chosen fields, one log line.
     *
     * @param list<string> $warnings
     * @return list<string> notes for the sync log (counts)
     */
    private static function applyMapping(\mysqli $mysqli, int $integrationId, int $logId, int $triggeredBy, array &$warnings): array
    {
        $notes = [];
        if (!MdmMappingSettings::schemaReady($mysqli)) {
            return $notes;   // database update 2.6.169 not run yet: nothing to apply
        }
        $settings = MdmMappingSettings::load($mysqli, $integrationId);
        try {
            $users = new MdmUserContactMapper($mysqli, $integrationId);
            $u = $users->refresh();
            if ($u['auto_mapped'] > 0) {
                $notes[] = "{$u['auto_mapped']} MDM user(s) mapped to a contact by e-mail";
            }
            if ($settings['assign_contact']) {
                $c = $users->assignAll($settings, $triggeredBy);
                $notes[] = 'asset contacts set from MDM users: ' . ($c['assign'] ?? 0) . ' (kept existing ' . ($c['kept_existing'] ?? 0) . ', needs mapping ' . ($c['no_mapping'] ?? 0) . ')';
            }
        } catch (\Throwable $e) {
            $warnings[] = 'user mapping skipped: ' . $e->getMessage();
        }
        if ($settings['allow_asset_writes']) {
            try {
                $w = (new MdmFieldWriter($mysqli, $integrationId))->apply($settings, $logId, $triggeredBy);
                $notes[] = $w['blocked'] !== '' ? 'asset field writes: none (' . $w['blocked'] . ')' : "asset field writes: {$w['written']} field(s) on {$w['assets']} asset(s)" . ($w['skipped'] ? ", {$w['skipped']} skipped" : '');
            } catch (\Throwable $e) {
                $warnings[] = 'asset field writes failed: ' . $e->getMessage();
            }
        }

        return $notes;
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
            $removed = filter_var($row['is_removed'] ?? false, FILTER_VALIDATE_BOOLEAN);   // the API sends the string "false"
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
        if ((int) ($row['enabled'] ?? 0) !== 1 || empty($row['refresh_token_enc'])) {
            return false;
        }
        $interval = max(5, min(1440, (int) ($row['sync_interval_minutes'] ?? 60)));
        $res = mysqli_query($mysqli, 'SELECT MAX(started_at) AS s, (MAX(started_at) IS NULL OR MAX(started_at) < DATE_SUB(NOW(), INTERVAL ' . $interval . ' MINUTE)) AS due FROM mdm_sync_log WHERE integration_id=' . (int) $row['id'] . " AND triggered_by=0");

        return $res && (int) (mysqli_fetch_assoc($res)['due'] ?? 1) === 1;
    }
}
