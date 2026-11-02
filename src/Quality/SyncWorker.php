<?php

namespace ITFlow\Quality;

use ITFlow\Integration\DirectoryApi;
use ITFlow\Integration\IntegrationAuth;
use ITFlow\Integration\LmsApi;

/**
 * Everything the scheduled worker (cron/quality_worker.php) and the "Run sync now" button do. One pass:
 *
 *   1. produce  - read what changed since each stream's cursor (the same code the LMS and directory API serve, limited to the departments enabled for Quality)
 *                 and queue one signed event per change (deterministic ids: the same change is queued once);
 *   2. reconcile- once a day (setting) re-read a trailing window of each stream and queue anything the cursor missed;
 *   3. deliver  - send due events (retry with backoff, dead letter);
 *   4. health   - ask the QMS GET /integrations/health and record the result;
 *   5. prune    - old log, inbound and delivered rows.
 *
 * Every step is skipped when the module is off, the connection is not configured, or events are switched off. Nothing here changes any LMS, Odoo or
 * directory data: it only reads, and writes its own tables. The first pass on a fresh installation starts the cursors at "now": a baseline of history is sent
 * only when an administrator asks for a full resync.
 */
final class SyncWorker
{
    public const PAGE = 200;
    public const RECONCILE_WINDOW_DAYS = 7;

    /** @return array<string,mixed> a summary line's worth of facts */
    public static function run(\mysqli $db, array $o = []): array
    {
        $sum = ['status' => 'ok', 'produced' => 0, 'sent' => 0, 'retry' => 0, 'dead' => 0, 'reconciled' => false, 'health' => null, 'streams' => []];
        if (!QualityConfig::moduleOn($db)) {
            return ['status' => 'module_off'] + $sum;
        }
        $row = QualityConfig::load($db);
        if (!QualityConfig::configured($row)) {
            return ['status' => 'not_configured'] + $sum;
        }
        $client = QmsClient::fromDb($db);
        if ($client === null) {
            return ['status' => 'not_configured'] + $sum;
        }
        $maxPages = max(1, (int) ($o['max_pages'] ?? 50));
        $force = !empty($o['force']);   // ignore the minimum gap between passes

        // The minimum gap between passes (the cron entry may run more often than the interval asks for).
        $gap = QualityConfig::clamp((int) $row['qs_sync_interval_minutes'], 1, 1440) * 60;
        $last = self::state($db, 'run')['qss_last_run_at'] ?? null;
        if (!$force && $last !== null && strtotime((string) $last) > time() - $gap + 5) {
            return ['status' => 'not_due'] + $sum;
        }
        self::touch($db, 'run');

        // ---- health (always when configured: the admin page must show the truth even when events are off)
        $sum['health'] = self::health($db, $client);

        if ((int) $row['qs_events_enabled'] === 1) {
            $dept = (int) $row['qs_all_departments'] === 1 ? null : QualityConfig::departmentIds($db);
            $reconcileDue = self::reconcileDue($db, (int) $row['qs_reconcile_interval_hours']);
            $training = (int) ($GLOBALS['config_module_enable_training'] ?? self::trainingOn($db)) === 1;
            foreach (QualityConfig::STREAMS as $stream) {
                if (!$training && in_array($stream, ['assignments', 'completions'], true)) {
                    continue;
                }
                try {
                    $cur = (string) (self::state($db, $stream)['qss_cursor'] ?? '');
                    $firstPass = $cur === '' && empty($o['full']);   // a stream's very first pass only sets its cursor: no history, no window
                    $mode = (!empty($o['full']) || str_starts_with($cur, 'F')) ? 'full' : 'incremental';
                    if (!empty($o['full']) && !str_starts_with($cur, 'F')) {
                        self::saveCursorRaw($db, $stream, 'F' . gmdate('Y-m-d\TH:i:s.v\Z') . '|0');
                    }
                    $r = self::produce($db, $stream, $dept, $mode, $maxPages);
                    $sum['produced'] += $r['new'];
                    $sum['streams'][$stream] = $r;
                    if ($reconcileDue && $mode === 'incremental' && !$firstPass) {
                        $rr = self::produce($db, $stream, $dept, 'window', $maxPages);
                        $sum['produced'] += $rr['new'];
                        $sum['reconciled'] = true;
                    }
                } catch (\Throwable $e) {
                    $sum['status'] = 'error';
                    self::fail($db, $stream, $e);
                }
            }
            if ($reconcileDue) {
                self::touch($db, 'reconcile', true);
            }

            $secret = QualityConfig::secret($row, 'qs_outbound_secret_enc');
            $d = Outbox::deliverDue($db, $client, $secret, (int) ($o['batch'] ?? 50), (int) ($o['budget_s'] ?? 45));
            $sum['sent'] = $d['sent'];
            $sum['retry'] = $d['retry'];
            $sum['dead'] = $d['dead'];
            if ($d['skipped'] === 'no_signing_secret') {
                $sum['status'] = 'error';
            }
        }
        if (empty($o['no_prune'])) {
            QualityLog::prune($db);
            Outbox::prune($db);
            InboundEvents::prune($db);
        }
        return $sum;
    }

    private static function trainingOn(\mysqli $db): int
    {
        try {
            return (int) ($db->query('SELECT config_module_enable_training AS t FROM settings WHERE company_id = 1')->fetch_assoc()['t'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed> */
    private static function health(\mysqli $db, QmsClient $client): array
    {
        $t = $client->test();
        $now = date('Y-m-d H:i:s');
        $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_last_run_at, qss_last_ok_at, qss_last_error) VALUES (\'health\', ?, ?, ?)
            ON DUPLICATE KEY UPDATE qss_last_run_at = VALUES(qss_last_run_at), qss_last_ok_at = IF(VALUES(qss_last_ok_at) IS NULL, qss_last_ok_at, VALUES(qss_last_ok_at)), qss_last_error = VALUES(qss_last_error)');
        $ok = $t['ok'] ? $now : null;
        $err = $t['ok'] ? null : substr(QualityLog::scrub($t['message']), 0, 300);
        $st->bind_param('sss', $now, $ok, $err);
        $st->execute();
        $st->close();
        if (!$t['ok']) {
            QualityLog::record($db, 'sync', 'health_check_failed', $t['message'], null, $t['status']);
        }
        return $t;
    }

    // ---- streams -------------------------------------------------------------------------------------------------------------------------

    /**
     * @param ?list<int> $dept department scope (null = all departments)
     * @param 'incremental'|'window'|'full' $mode
     * @return array{new:int, seen:int, complete:bool}
     */
    public static function produce(\mysqli $db, string $stream, ?array $dept, string $mode, int $maxPages): array
    {
        $state = self::state($db, $stream);
        $cursor = (string) ($state['qss_cursor'] ?? '');
        $now = gmdate('Y-m-d\TH:i:s.v\Z');
        if ($mode === 'incremental' && $cursor === '') {
            self::saveCursor($db, $stream, $now, 0, false);   // first pass: start at "now", send no history
            return ['new' => 0, 'seen' => 0, 'complete' => true];
        }
        // Cursor forms: "<iso>" (normal), "<iso>|<after>" (a pass cut short by the page cap resumes after that id),
        // "F<start iso>|<after>" (a full pass over history is pending or in progress).
        $resumeAfter = 0;
        $base = $cursor;
        if (str_contains($cursor, '|')) {
            [$base, $a] = explode('|', $cursor, 2);
            $resumeAfter = max(0, (int) $a);
        }
        $fullStart = null;
        if ($mode === 'full') {
            $fullStart = str_starts_with($base, 'F') ? substr($base, 1) : $now;
        }
        $since = match ($mode) {
            'full' => null,
            'window' => gmdate('Y-m-d\TH:i:s\Z', time() - self::RECONCILE_WINDOW_DAYS * 86400),
            default => $base,
        };
        $lms = new LmsApi($db, $dept);
        $dir = new DirectoryApi($db, $dept);
        $after = $mode === 'window' ? 0 : $resumeAfter;
        $new = 0;
        $seen = 0;
        $max = '';
        $complete = false;
        for ($page = 0; $page < $maxPages; $page++) {
            $q = ['limit' => (string) self::PAGE];
            if ($since !== null) {
                $q['updated_since'] = $since;
            }
            if ($after > 0) {
                $q['cursor'] = IntegrationAuth::b64uEncode(json_encode(['after' => $after]));
            }
            $res = match ($stream) {
                'assignments' => $lms->assignments($q),
                'completions' => $lms->completions($q),
                'employees' => $dir->employees($q),
                'departments' => $dir->departments($q),
                default => throw new \InvalidArgumentException('Unknown stream'),
            };
            foreach ($res['data'] as $item) {
                $seen++;
                if (self::enqueueItem($db, $stream, $item)) {
                    $new++;
                }
                $u = (string) ($item['updated_at'] ?? '');
                if ($u > $max) {
                    $max = $u;
                }
            }
            $next = $res['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                $complete = true;
                break;
            }
            $dec = json_decode((string) IntegrationAuth::b64uDecode($next), true);
            $after = is_array($dec) ? (int) ($dec['after'] ?? 0) : 0;
            if ($after <= 0) {
                $complete = true;
                break;
            }
        }
        if ($mode === 'window') {
            // A window pass never moves the cursor; it only queues what the cursor missed.
            self::recordRun($db, $stream, $new, $complete ? null : 'Reconciliation window cut short after ' . $maxPages . ' pages.');
            return ['new' => $new, 'seen' => $seen, 'complete' => $complete];
        }
        if ($complete) {
            // Normal pass: continue from the newest change seen (never later than it, so nothing is skipped). Full pass: from when it started.
            $next = $mode === 'full' ? (string) $fullStart : ($max !== '' && $max > $base ? $max : $base);
            self::saveCursor($db, $stream, $next, $new, $mode === 'full');
        } else {
            // Cut short by the page cap: remember where to resume; the cursor itself does not move, and the deterministic ids absorb any repeat.
            $prefix = $mode === 'full' ? 'F' . $fullStart : $base;
            self::saveCursorRaw($db, $stream, $prefix . '|' . $after);
            self::recordRun($db, $stream, $new, 'Pass cut short after ' . $maxPages . ' pages; it continues on the next run.');
        }
        return ['new' => $new, 'seen' => $seen, 'complete' => $complete];
    }

    /** @param array<string,mixed> $item one API item */
    private static function enqueueItem(\mysqli $db, string $stream, array $item): bool
    {
        $id = (string) ($item['id'] ?? '');
        $upd = (string) ($item['updated_at'] ?? '');
        if ($id === '') {
            return false;
        }
        $emp = static fn($e): array => ['rivetit_id' => $e['rivetit_id'] ?? null, 'odoo_employee_id' => $e['odoo_employee_id'] ?? null];
        switch ($stream) {
            case 'assignments':
                $rev = (int) ($item['revision'] ?? 1);
                return Outbox::enqueue($db, $rev <= 1 ? 'lms.assignment.created' : 'lms.assignment.updated', 'assignment', $id, (string) $rev, [
                    'employee' => $emp($item['employee'] ?? []), 'course_id' => $item['course_id'] ?? null, 'status' => $item['status'] ?? null, 'revision' => $rev,
                    'external_system' => $item['external_system'] ?? null, 'external_ref' => $item['external_ref'] ?? null, 'due_on' => $item['due_on'] ?? null, 'updated_at' => $upd,
                ], $upd !== '' ? $upd : null);
            case 'completions':
                $rev = (int) ($item['revision'] ?? 1);
                $first = $rev <= 1 && ($item['status'] ?? '') === 'completed';
                return Outbox::enqueue($db, $first ? 'lms.completion.recorded' : 'lms.completion.corrected', 'completion', $id, (string) $rev, [
                    'employee' => $emp($item['employee'] ?? []), 'course_id' => $item['course_id'] ?? null, 'assignment_id' => $item['assignment_id'] ?? null,
                    'status' => $item['status'] ?? null, 'revision' => $rev, 'completed_on' => $item['completed_on'] ?? null, 'expires_on' => $item['expires_on'] ?? null, 'updated_at' => $upd,
                ], $upd !== '' ? $upd : null);
            case 'employees':
                $active = ($item['active'] ?? true) !== false;
                return Outbox::enqueue($db, $active ? 'employee.updated' : 'employee.deactivated', 'employee', $id, $upd, [
                    'rivetit_id' => $id, 'odoo_employee_id' => $item['odoo_employee_id'] ?? null, 'department_id' => $item['department_id'] ?? null,
                    'odoo_department_id' => $item['odoo_department_id'] ?? null, 'active' => $active, 'updated_at' => $upd,
                ], $upd !== '' ? $upd : null);
            case 'departments':
                return Outbox::enqueue($db, 'department.updated', 'department', $id, $upd, [
                    'rivetit_id' => $id, 'odoo_department_id' => $item['odoo_department_id'] ?? null, 'status' => $item['status'] ?? null,
                    'active' => ($item['active'] ?? true) !== false, 'updated_at' => $upd,
                ], $upd !== '' ? $upd : null);
        }
        return false;
    }

    // ---- state -----------------------------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function state(\mysqli $db, string $stream): array
    {
        $st = $db->prepare('SELECT * FROM quality_sync_state WHERE qss_stream = ?');
        $st->bind_param('s', $stream);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        return is_array($r) ? $r : [];
    }

    private static function touch(\mysqli $db, string $stream, bool $full = false): void
    {
        $now = date('Y-m-d H:i:s');
        $fullAt = $full ? $now : null;
        $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_last_run_at, qss_last_full_at) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qss_last_run_at = VALUES(qss_last_run_at), qss_last_full_at = IF(VALUES(qss_last_full_at) IS NULL, qss_last_full_at, VALUES(qss_last_full_at))');
        $st->bind_param('sss', $stream, $now, $fullAt);
        $st->execute();
        $st->close();
    }

    private static function saveCursor(\mysqli $db, string $stream, string $cursor, int $new, bool $full): void
    {
        $now = date('Y-m-d H:i:s');
        $fullAt = $full ? $now : null;
        $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_cursor, qss_last_run_at, qss_last_ok_at, qss_last_full_at, qss_last_error, qss_events_total) VALUES (?, ?, ?, ?, ?, NULL, ?)
            ON DUPLICATE KEY UPDATE qss_cursor = VALUES(qss_cursor), qss_last_run_at = VALUES(qss_last_run_at), qss_last_ok_at = VALUES(qss_last_ok_at), qss_last_error = NULL,
              qss_last_full_at = IF(VALUES(qss_last_full_at) IS NULL, qss_last_full_at, VALUES(qss_last_full_at)), qss_events_total = qss_events_total + VALUES(qss_events_total)');
        $st->bind_param('sssssi', $stream, $cursor, $now, $now, $fullAt, $new);
        $st->execute();
        $st->close();
    }

    private static function saveCursorRaw(\mysqli $db, string $stream, string $cursor): void
    {
        $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_cursor) VALUES (?, ?) ON DUPLICATE KEY UPDATE qss_cursor = VALUES(qss_cursor)');
        $st->bind_param('ss', $stream, $cursor);
        $st->execute();
        $st->close();
    }

    private static function recordRun(\mysqli $db, string $stream, int $new, ?string $note): void
    {
        $now = date('Y-m-d H:i:s');
        $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_last_run_at, qss_last_error, qss_events_total) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qss_last_run_at = VALUES(qss_last_run_at), qss_last_error = VALUES(qss_last_error), qss_events_total = qss_events_total + VALUES(qss_events_total)');
        $st->bind_param('sssi', $stream, $now, $note, $new);
        $st->execute();
        $st->close();
    }

    private static function fail(\mysqli $db, string $stream, \Throwable $e): void
    {
        $msg = 'Stream ' . $stream . ' failed: ' . get_class($e);
        self::recordRun($db, $stream, 0, $msg);
        QualityLog::record($db, 'sync', 'stream_failed', $msg . ': ' . $e->getMessage());
    }

    private static function reconcileDue(\mysqli $db, int $hours): bool
    {
        $last = self::state($db, 'reconcile')['qss_last_full_at'] ?? null;
        return $last === null || strtotime((string) $last) < time() - QualityConfig::clamp($hours, 1, 720) * 3600;
    }

    /** Administrator action: forget the cursors so the next pass is a FULL pass over history (queues one event per record not yet queued). */
    public static function requestFullResync(\mysqli $db): void
    {
        $mark = 'F' . gmdate('Y-m-d\TH:i:s.v\Z') . '|0';
        foreach (QualityConfig::STREAMS as $stream) {
            self::saveCursorRaw($db, $stream, $mark);
        }
        $db->query("DELETE FROM quality_sync_state WHERE qss_stream = 'run'");
    }
}
