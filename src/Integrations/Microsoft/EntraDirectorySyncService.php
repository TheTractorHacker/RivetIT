<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * One Entra directory sync run (people -> departments and contacts): used by the Sync Now / Preview / Full resync buttons and by cron, so
 * all of them lock, log, classify errors and count the same way.
 *
 * Modes:
 *   full   every user is read (GET /users), as before DB 2.6.225. Used when "Incremental sync" is off, on "Full resync", and as the fallback.
 *   delta  Graph delta query (/users/delta). The first round returns every user plus a delta link, which is saved; later rounds return
 *          only users that changed (and ids of users deleted in Entra). An invalid or expired token (Graph 410 / syncStateNotFound) makes the
 *          run start over as a full round and take a fresh token, and the log says so.
 * Dry run: everything is done inside a database transaction that is rolled back, so the counts are exactly what a real run would do and
 * nothing is changed (no contacts, departments, links, delta token or last-sync time). The log row is written outside the transaction and
 * marked dry_run, and the sync watch ignores it.
 *
 * Read-only towards Microsoft. Tested only against tests/mock/graph_mock.php: UNVERIFIED LIVE (see docs/ENTRA_INTUNE_SETUP.md).
 */
final class EntraDirectorySyncService
{
    /** A 'running' row younger than this blocks a new run (a crashed run stops blocking after it). */
    public const LOCK_SECONDS = 300;

    /**
     * @param array<string,mixed> $row a microsoft_integrations row
     * @param array{dry_run?:bool,force_full?:bool,client_options?:array<string,mixed>} $opts
     * @return array{ok:bool,message:string,error_code:?string,mode:?string,stats:?array,log_id:int,locked:bool,dry_run:bool,fell_back:bool}
     */
    public static function run(\mysqli $mysqli, array $row, int $triggeredBy = 0, int $timeLimitSeconds = 120, array $opts = []): array
    {
        $dry = !empty($opts['dry_run']);
        $forceFull = !empty($opts['force_full']);
        $id = (int) $row['microsoft_integration_id'];
        $fail = static fn (string $m, ?string $code = null, int $log = 0, bool $locked = false, ?string $mode = null, bool $fell = false) =>
            ['ok' => false, 'message' => $m, 'error_code' => $code, 'mode' => $mode, 'stats' => null, 'log_id' => $log, 'locked' => $locked, 'dry_run' => $dry, 'fell_back' => $fell];

        if (empty($row['client_secret_enc']) || empty($row['tenant_id']) || empty($row['client_id'])) {
            return $fail('Save a tenant ID, client ID, and client secret before syncing.');
        }
        $recent = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT id FROM microsoft_directory_sync_log WHERE microsoft_integration_id=$id
             AND started_at > DATE_SUB(NOW(), INTERVAL " . self::LOCK_SECONDS . " SECOND) AND status='running' LIMIT 1"));
        if ($recent) {
            return $fail('A sync is already running. Please wait for it to finish.', null, 0, true);
        }

        $timeLimitSeconds = max(10, min(600, $timeLimitSeconds));
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($timeLimitSeconds + 30);
        }
        $client = GraphClientFactory::forRow($mysqli, $row, time() + $timeLimitSeconds, $opts['client_options'] ?? []);
        $mapper = new MicrosoftDirectoryMapper($mysqli, $id, $triggeredBy);
        $logId = $mapper->startSyncLog();   // outside the dry-run transaction: it is also the lock

        $deltaOn = !empty($row['directory_delta_enabled']);
        $savedLink = trim((string) ($row['directory_delta_link'] ?? ''));
        $mode = 'full';
        $fellBack = false;
        $newLink = null;
        $removed = [];
        $inTx = false;

        try {
            // 1. read from Graph (nothing is written yet)
            if ($deltaOn) {
                $mode = 'delta';
                $useLink = (!$forceFull && $savedLink !== '') ? $savedLink : null;
                try {
                    $res = $client->usersDelta($useLink);
                } catch (GraphException $e) {
                    if ($e->errorCode !== GraphException::DELTA_RESET || $useLink === null) {
                        throw $e;
                    }
                    $fellBack = true;          // the saved token is dead: start over with a full round
                    $res = $client->usersDelta(null);
                }
                $users = $res['items'];
                $removed = $res['removed'];
                $newLink = $res['delta_link'];
                $incremental = $res['incremental'] && !$fellBack;
                $modeLabel = $incremental ? 'delta' : 'full';
            } else {
                $users = $client->getUsers();
                $incremental = false;
                $modeLabel = 'full';
            }

            // 2. apply (inside a transaction that is rolled back for a dry run)
            if ($dry) {
                mysqli_begin_transaction($mysqli);
                $inTx = true;
            }
            $deptStats = $mapper->syncDepartments($users);
            $empStats = $mapper->syncEmployees($users, $deptStats['idMap']);
            $removedCount = ($incremental && $removed) ? $mapper->applyRemovedUsers($removed) : 0;
            if ($inTx) {
                mysqli_rollback($mysqli);
                $inTx = false;
            }

            $errors = array_merge($deptStats['errors'] ?? [], $empStats['errors'] ?? []);
            $status = $errors ? 'failed' : 'success';
            self::finish($mysqli, $logId, $status, $deptStats, $empStats, implode('; ', $errors), null, $modeLabel, $dry, count($users), $removedCount);
            if (!$dry) {
                mysqli_query($mysqli, "UPDATE microsoft_integrations SET last_directory_sync_at = NOW() WHERE microsoft_integration_id = $id");
                // Keep the token only after a clean run: after errors the same changes are read again next time (the run is idempotent).
                if ($deltaOn && !$errors && $newLink !== null) {
                    $stmt = mysqli_prepare($mysqli, 'UPDATE microsoft_integrations SET directory_delta_link = ?, directory_delta_at = NOW() WHERE microsoft_integration_id = ?');
                    mysqli_stmt_bind_param($stmt, 'si', $newLink, $id);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                self::afterSync($mysqli);
            }

            $stats = ['departments' => $deptStats, 'employees' => $empStats, 'users_seen' => count($users), 'users_removed' => $removedCount, 'mode' => $modeLabel];
            $text = ($dry ? 'Preview (nothing was changed): ' : 'Entra directory sync complete: ')
                . "mode $modeLabel" . ($fellBack ? ' (the saved delta token was no longer valid, so a full round was run)' : '')
                . ", " . count($users) . " users read; departments {$deptStats['created']} created, {$deptStats['updated']} updated; "
                . "employees {$empStats['created']} created, {$empStats['updated']} updated, {$empStats['matched']} matched, {$empStats['skipped']} skipped"
                . ($removedCount > 0 ? ", $removedCount archived because deleted in Entra" : '');

            return ['ok' => !$errors, 'message' => $text, 'error_code' => null, 'mode' => $modeLabel, 'stats' => $stats, 'log_id' => $logId, 'locked' => false, 'dry_run' => $dry, 'fell_back' => $fellBack];
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
        self::finish($mysqli, $logId, 'failed', null, null, $message, $code, $mode, $dry, 0, 0);

        return $fail($message, $code, $logId, false, $mode, $fellBack);
    }

    /** Is a scheduled run due? Pure. */
    public static function isDue(?int $lastStartTs, int $intervalMin, int $nowTs): bool
    {
        $intervalMin = max(15, $intervalMin);

        return $lastStartTs === null || ($nowTs - $lastStartTs) >= $intervalMin * 60;
    }

    /** Start over: forget the saved delta token (the next run in delta mode is a full round that takes a new one). */
    public static function forgetDelta(\mysqli $mysqli, int $integrationId): void
    {
        mysqli_query($mysqli, "UPDATE microsoft_integrations SET directory_delta_link = NULL, directory_delta_at = NULL WHERE microsoft_integration_id = " . (int) $integrationId);
    }

    private static function finish(\mysqli $db, int $logId, string $status, ?array $dept, ?array $emp, string $errors, ?string $code, string $mode, bool $dry, int $seen, int $removed): void
    {
        $d = $dept ?? ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0];
        $e = $emp ?? ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0];
        $stmt = mysqli_prepare($db,
            "UPDATE microsoft_directory_sync_log SET finished_at=NOW(), status=?, departments_created=?, departments_updated=?, departments_matched=?, departments_skipped=?,
             employees_created=?, employees_updated=?, employees_matched=?, employees_skipped=?, errors=?, error_code=?, sync_mode=?, dry_run=?, users_seen=?, users_removed=? WHERE id=?");
        $errors = substr($errors, 0, 4000);
        $dryI = $dry ? 1 : 0;
        mysqli_stmt_bind_param($stmt, 'siiiiiiiisssiiii', $status, $d['created'], $d['updated'], $d['matched'], $d['skipped'], $e['created'], $e['updated'], $e['matched'], $e['skipped'], $errors, $code, $mode, $dryI, $seen, $removed, $logId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    /** Training: departments this sync created or changed get their department job group in step. Guarded like every Training hook; never fails the sync. */
    private static function afterSync(\mysqli $mysqli): void
    {
        try {
            $r = @mysqli_query($mysqli, 'SELECT config_module_enable_training FROM settings WHERE company_id = 1');
            $on = $r && (int) (mysqli_fetch_row($r)[0] ?? 0) === 1;
            if ($on && class_exists(\ITFlow\Training\People\DepartmentGroups::class)) {
                \ITFlow\Training\People\DepartmentGroups::afterDirectorySync(\ITFlow\Training\Core\Access::ctx($mysqli));
            }
        } catch (\Throwable $e) {
            error_log('Training: after the directory sync, failed: ' . $e->getMessage());
        }
    }
}
