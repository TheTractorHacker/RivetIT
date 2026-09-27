<?php

namespace ITFlow\Training\Kiosk\Bridge;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Compliance\LearnerSummary;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\People\Roster;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\CertSecret;
use ITFlow\Training\Records\CompletionService;

/**
 * The ONLY place Phase 3+4 reaches Phase 2's records, assignments, roster and scope (P3 spec
 * §0.7, §2.6 C-P2-*, §3.7), so a P2 rename changes only this file. Phase 2 ships in the same
 * tree (2.6.92), so its classes are called directly; available() still probes the tables, and a
 * database that has not run 2.6.92 reports records "unavailable" (503 records_unavailable).
 *
 * P2 services are constructed with a system Ctx (Core\SystemCtx, user 0, level 3) whose base URL
 * matches the calling context; the ledger actor of the records they write comes from the input
 * (actor_type 'contact' + kiosk/ksess ids), as CompletionService::actor() reads it.
 *
 * Lock order (§0.8): the caller's run/attempt rows are held; issueOnline() then enters P2 in P2's
 * own order (records mutex -> source key -> assignment -> counter) and returns the ledger events
 * for the caller to append LAST. afterCommit() runs the P2 listeners (AwardEngine included),
 * audit and the one-contact reconcile - always after COMMIT and never allowed to fail the caller.
 */
final class RecordsBridge
{
    /** @var array<int, bool> available() per connection */
    private static array $available = [];
    /** @var array<int, bool> roster table present per connection */
    private static array $roster = [];

    private ?Ctx $sys = null;

    public function __construct(private readonly Ctx $c)
    {
    }

    /** Phase 2 records are usable: its 2.6.92 tables exist (memoised per connection). */
    public static function available(\mysqli $db): bool
    {
        $id = spl_object_id($db);
        if (!array_key_exists($id, self::$available)) {
            $ok = false;
            try {
                $row = Db::one($db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('training_completions', 'training_assignments', 'training_completion_voids')");
                $ok = (int) ($row['n'] ?? 0) === 3;
            } catch (\Throwable $e) {
                error_log('Kiosk RecordsBridge::available: ' . get_class($e));
                $ok = false;
            }
            self::$available[$id] = $ok;
        }
        return self::$available[$id];
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$available = [];
        self::$roster = [];
    }

    // ---- roster / eligibility (C-P2-5) ----------------------------------------------------------

    /**
     * Roster eligibility as SQL over a contacts alias: {join, where, types, params}. P2's
     * Roster::JOIN/ELIGIBLE (alias c) re-aliased; before 2.6.92 (no training_roster table) the
     * fallback is "not archived and in a department".
     *
     * @return array{join:string, where:string, types:string, params:list<mixed>}
     */
    public function eligibleSql(string $alias): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('RecordsBridge::eligibleSql: bad alias');
        }
        if ($this->rosterReady()) {
            $rAlias = 'tr_r_' . $alias;
            $join = str_replace(['tr_r', 'c.contact_'], [$rAlias, $alias . '.contact_'], Roster::JOIN);
            $where = str_replace(['tr_r.', 'c.contact_'], [$rAlias . '.', $alias . '.contact_'], Roster::ELIGIBLE);
            return ['join' => $join, 'where' => '(' . $where . ')', 'types' => '', 'params' => []];
        }
        return ['join' => '', 'where' => "($alias.contact_archived_at IS NULL AND $alias.contact_client_id > 0)", 'types' => '', 'params' => []];
    }

    public function isEligible(int $cid): bool
    {
        if ($cid < 1) {
            return false;
        }
        if ($this->rosterReady()) {
            return Roster::isEligible($this->c->db, $cid);
        }
        return Db::one($this->c->db, 'SELECT 1 AS ok FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL AND contact_client_id > 0', 'i', [$cid]) !== null;
    }

    // ---- completions (C-P2-2 / C-P2-3) ------------------------------------------------------------

    /**
     * INSIDE the caller's Db::tx (asserts depth >= 1). Issues the online record for an attested
     * run, or - for a course that also needs a session or practical - the blended record when every
     * part is present (tryIssueComponents reads the online part through Kiosk\RunComponentSource on
     * THIS connection, so it sees the caller's uncommitted attestation).
     *
     * $in: contact_id, course_id, revision_id, run_id, attempt_id, score_pct, pass_mark_pct, attempts_used, duration_minutes,
     *      language, learner_tsig_id, proof, pin_source, odoo_employee_id, kiosk_id, asset_id, ksess_id, attestation_text,
     *      completed_on (local Y-m-d), components {online, session, practical}
     *
     * @return array{completion_id:int, cert_number:?string, completed_on:string, expires_on:?string, kind:string, events:list<array>, opaque:array}|null
     *         null = another component is still pending (the run waits for a session or an evaluation)
     */
    public function issueOnline(array $in): ?array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('RecordsBridge::issueOnline must run inside the caller\'s Db::tx');
        }
        if (!self::available($this->c->db)) {
            throw new ApiException(503, 'records_unavailable', 'Training records are not available yet. Your work is saved - see your trainer.');
        }
        $svc = $this->completionService();
        $cid = (int) $in['contact_id'];
        $courseId = (int) $in['course_id'];
        $comp = $in['components'] ?? [];
        if (!empty($comp['session']) || !empty($comp['practical'])) {
            $r = $svc->tryIssueComponents($cid, $courseId, true);
        } else {
            $r = $svc->issue([
                'contact_id' => $cid,
                'course_id' => $courseId,
                'method' => 'online',
                'proof' => (string) $in['proof'],
                'source_key' => 'run:' . (int) $in['run_id'],
                'completed_on' => (string) $in['completed_on'],
                'revision_id' => (int) $in['revision_id'],
                'language' => (string) ($in['language'] ?? 'en'),
                'score_pct' => $in['score_pct'] ?? null,
                'pass_mark_pct' => $in['pass_mark_pct'] ?? null,
                'attempts_used' => $in['attempts_used'] ?? null,
                'duration_minutes' => $in['duration_minutes'] ?? null,
                'run_id' => (int) $in['run_id'],
                'attempt_id' => $in['attempt_id'] ?? null,
                'learner_tsig_id' => $in['learner_tsig_id'] ?? null,
                'kiosk_id' => $in['kiosk_id'] ?? null,
                'asset_id' => $in['asset_id'] ?? null,
                'pin_source' => $in['pin_source'] ?? null,
                'odoo_employee_id' => $in['odoo_employee_id'] ?? null,
                'attestation_text' => $in['attestation_text'] ?? null,
                'actor_type' => 'contact',
                'actor_contact_id' => $cid,
                'ksess_id' => $in['ksess_id'] ?? null,
            ], true);
        }
        if (!is_array($r) || empty($r['completion_id'])) {
            return null;
        }
        $row = $this->receipt((int) $r['completion_id']) ?? [];
        return [
            'completion_id' => (int) $r['completion_id'],
            'cert_number' => isset($r['cert_number']) && $r['cert_number'] !== null ? (string) $r['cert_number'] : ($row['cert_number'] ?? null),
            'completed_on' => (string) ($row['completed_on'] ?? $in['completed_on']),
            'expires_on' => $row['expires_on'] ?? null,
            'kind' => (string) ($r['kind'] ?? ($row['kind'] ?? 'training')),
            'events' => array_values($r['events'] ?? []),
            'opaque' => $r,
        ];
    }

    /** After COMMIT: P2's listeners, audit and reconcile for an issueOnline() result. Never throws. */
    public function afterCommit(?array $r): void
    {
        if ($r === null || empty($r['opaque'])) {
            return;
        }
        if (Db::depth() !== 0) {
            error_log('Kiosk RecordsBridge::afterCommit called inside a transaction; skipped');
            return;
        }
        try {
            $this->completionService()->afterCommit($r['opaque']);
        } catch (\Throwable $e) {
            error_log('Kiosk RecordsBridge::afterCommit: ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Own transaction (depth 0): issues the blended record once every part is present. Idempotent
     * by P2's source key; P2 runs its own afterCommit. For RunService::settleAwaiting and cron.
     *
     * @return array{completion_id:int, cert_number:?string, kind:string}|null
     */
    public function tryIssueComponents(int $cid, int $courseId): ?array
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        if (Db::depth() !== 0) {
            throw new \LogicException('RecordsBridge::tryIssueComponents runs outside any transaction');
        }
        $r = $this->completionService()->tryIssueComponents($cid, $courseId, false);
        if (!is_array($r) || empty($r['completion_id'])) {
            return null;
        }
        return ['completion_id' => (int) $r['completion_id'], 'cert_number' => $r['cert_number'] ?? null, 'kind' => (string) ($r['kind'] ?? 'training')];
    }

    /** P2's one-contact reconcile after a kiosk sign-in (trigger 'kiosk', lock wait 1 s). Outside any transaction; never throws. */
    public function reconcileContact(int $cid): void
    {
        if ($cid < 1 || Db::depth() !== 0 || !self::available($this->c->db)) {
            return;
        }
        try {
            (new AssignmentService($this->sys()))->reconcile([$cid], 'kiosk', 1);
        } catch (\Throwable $e) {
            error_log('Kiosk RecordsBridge::reconcileContact: ' . get_class($e));
        }
    }

    /**
     * P2's LearnerSummary (C-P2-6) or {available:false}.
     *
     * @return array{available:bool, required?:list, optional?:list, completed?:list, certificates?:list, counts?:array}
     */
    public function learnerSummary(int $cid): array
    {
        if (!self::available($this->c->db)) {
            return ['available' => false];
        }
        $out = LearnerSummary::forContact($this->c->db, $cid, RecordsSettings::fromDb($this->c->db), Clock::todayLocal());
        return ['available' => true] + $out;
    }

    public function openAssignmentId(int $cid, int $courseId): ?int
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        $row = Db::one($this->c->db, "SELECT tassign_id FROM training_assignments
            WHERE tassign_contact_id = ? AND tassign_course_id = ? AND tassign_status = 'open' ORDER BY tassign_id DESC LIMIT 1", 'ii', [$cid, $courseId]);
        return $row === null ? null : (int) $row['tassign_id'];
    }

    /**
     * The newest non-voided, unexpired record of the pair (prerequisites, awards).
     *
     * @return array{completion_id:int, completed_on:string, expires_on:?string, cert_number:?string}|null
     */
    public function validCompletion(int $cid, int $courseId): ?array
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        $row = Db::one($this->c->db, 'SELECT t.completion_id, t.completion_completed_on, t.completion_expires_on, t.completion_cert_number
            FROM training_completions t
            WHERE t.completion_contact_id = ? AND t.completion_course_id = ?
              AND (t.completion_expires_on IS NULL OR t.completion_expires_on >= ?)
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id)
            ORDER BY t.completion_completed_on DESC, t.completion_id DESC LIMIT 1', 'iis', [$cid, $courseId, Clock::todayLocal()]);
        return $row === null ? null : [
            'completion_id' => (int) $row['completion_id'],
            'completed_on' => (string) $row['completion_completed_on'],
            'expires_on' => $row['completion_expires_on'] === null ? null : (string) $row['completion_expires_on'],
            'cert_number' => $row['completion_cert_number'] === null ? null : (string) $row['completion_cert_number'],
        ];
    }

    /** @return list<int> courses the contact holds a non-voided, unexpired record for */
    public function validCourseIds(int $cid): array
    {
        if (!self::available($this->c->db)) {
            return [];
        }
        $rows = Db::all($this->c->db, 'SELECT DISTINCT t.completion_course_id AS id FROM training_completions t
            WHERE t.completion_contact_id = ? AND (t.completion_expires_on IS NULL OR t.completion_expires_on >= ?)
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id)
            ORDER BY t.completion_course_id', 'is', [$cid, Clock::todayLocal()]);
        return array_map(static fn($r) => (int) $r['id'], $rows);
    }

    /** @return array{contact_id:int, course_id:int, run_id:?int, recorded_at_utc:string, voided:bool}|null */
    public function completion(int $completionId): ?array
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        $row = Db::one($this->c->db, 'SELECT t.completion_contact_id, t.completion_course_id, t.completion_run_id, t.completion_recorded_at_utc,
                EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id) AS voided
            FROM training_completions t WHERE t.completion_id = ?', 'i', [$completionId]);
        return $row === null ? null : [
            'contact_id' => (int) $row['completion_contact_id'],
            'course_id' => (int) $row['completion_course_id'],
            'run_id' => $row['completion_run_id'] === null ? null : (int) $row['completion_run_id'],
            'recorded_at_utc' => (string) $row['completion_recorded_at_utc'],
            'voided' => (int) $row['voided'] === 1,
        ];
    }

    /**
     * The record that closes an awaiting run (RunService::settleAwaiting): non-voided, for the pair,
     * with completion_run_id = the run, or recorded at/after the run's attestation.
     *
     * @return array{completion_id:int, cert_number:?string}|null
     */
    public function completionForRun(int $cid, int $courseId, int $runId, ?string $attestedAtUtc): ?array
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        $row = Db::one($this->c->db, 'SELECT t.completion_id, t.completion_cert_number FROM training_completions t
            WHERE t.completion_contact_id = ? AND t.completion_course_id = ?
              AND (t.completion_run_id = ? OR (? IS NOT NULL AND t.completion_recorded_at_utc >= ?))
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id)
            ORDER BY (t.completion_run_id = ?) DESC, t.completion_id DESC LIMIT 1', 'iiissi',
            [$cid, $courseId, $runId, $attestedAtUtc, $attestedAtUtc, $runId]);
        return $row === null ? null : ['completion_id' => (int) $row['completion_id'],
            'cert_number' => $row['completion_cert_number'] === null ? null : (string) $row['completion_cert_number']];
    }

    /**
     * Receipt facts of a record (sign.php, attest).
     *
     * @return array{completion_id:int, cert_number:?string, completed_on:string, expires_on:?string, kind:string, course_name:string, score_pct:?string, voided:bool}|null
     */
    public function receipt(int $completionId): ?array
    {
        if (!self::available($this->c->db)) {
            return null;
        }
        $row = Db::one($this->c->db, 'SELECT t.completion_id, t.completion_cert_number, t.completion_completed_on, t.completion_expires_on,
                t.completion_course_kind, t.completion_snap_course_name, t.completion_score_pct,
                EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id) AS voided
            FROM training_completions t WHERE t.completion_id = ?', 'i', [$completionId]);
        return $row === null ? null : [
            'completion_id' => (int) $row['completion_id'],
            'cert_number' => $row['completion_cert_number'] === null ? null : (string) $row['completion_cert_number'],
            'completed_on' => (string) $row['completion_completed_on'],
            'expires_on' => $row['completion_expires_on'] === null ? null : (string) $row['completion_expires_on'],
            'kind' => (string) $row['completion_course_kind'],
            'course_name' => (string) $row['completion_snap_course_name'],
            'score_pct' => $row['completion_score_pct'] === null ? null : (string) $row['completion_score_pct'],
            'voided' => (int) $row['voided'] === 1,
        ];
    }

    /**
     * [S] Consecutive calendar months, counting back from last month, in which every required
     * assignment due that month was completed on time (a record completed on or before the due
     * date). Months with nothing due neither count nor break the streak; at most 24 months back.
     * 0 when records are unavailable.
     */
    public function onTimeMonths(int $cid): int
    {
        if (!self::available($this->c->db) || $cid < 1) {
            return 0;
        }
        $today = Clock::todayLocal();
        $from = Clock::addMonths(substr($today, 0, 8) . '01', -24);
        $rows = Db::all($this->c->db, "SELECT a.tassign_due_on, a.tassign_status, c.completion_completed_on
            FROM training_assignments a
            LEFT JOIN training_completions c ON c.completion_id = a.tassign_completion_id
            WHERE a.tassign_contact_id = ? AND a.tassign_required = 1 AND a.tassign_due_on >= ? AND a.tassign_due_on < ?
              AND a.tassign_status IN ('open', 'completed')", 'iss', [$cid, $from, substr($today, 0, 8) . '01']);
        $months = [];
        foreach ($rows as $r) {
            $m = substr((string) $r['tassign_due_on'], 0, 7);
            $ok = $r['tassign_status'] === 'completed' && $r['completion_completed_on'] !== null
                && (string) $r['completion_completed_on'] <= (string) $r['tassign_due_on'];
            $months[$m] = ($months[$m] ?? true) && $ok;
        }
        $n = 0;
        $cursor = Clock::addMonths(substr($today, 0, 8) . '01', -1);
        for ($i = 0; $i < 24; $i++) {
            $m = substr($cursor, 0, 7);
            if (isset($months[$m])) {
                if (!$months[$m]) {
                    break;
                }
                $n++;
            }
            $cursor = Clock::addMonths($cursor, -1);
        }
        return $n;
    }

    /**
     * A finalized session of the course where the person was present (the session part of a blended course is on file).
     * After a "Reset (take again)" only a session from then on counts (P2 RetakeVoids, as tryIssueComponents).
     */
    public function attendedSession(int $cid, int $courseId): bool
    {
        if (!self::available($this->c->db)) {
            return false;
        }
        try {
            $types = 'ii';
            $params = [$cid, $courseId];
            $not = '';
            $gone = \ITFlow\Training\Records\RetakeVoids::excluded($this->c->db, $cid, $courseId);
            if ($gone !== null) {
                $not = ' AND s.tsession_held_on >= ?';
                $types .= 's';
                $params[] = $gone['since_on'];
                $not .= \ITFlow\Training\Records\RetakeVoids::notIn('ta.tattendee_id', $gone['attendee_ids'], $types, $params);
            }
            return Db::one($this->c->db, "SELECT 1 AS ok FROM training_session_attendees ta
                JOIN training_sessions s ON s.tsession_id = ta.tattendee_tsession_id
                WHERE ta.tattendee_contact_id = ? AND s.tsession_course_id = ? AND s.tsession_status = 'finalized'
                  AND ta.tattendee_attendance = 'present' AND ta.tattendee_removed_at_utc IS NULL$not LIMIT 1", $types, $params) !== null;
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146 || (int) $e->getCode() === 1054) {
                return false;
            }
            throw $e;
        }
    }

    /** P2's link state for the contact's Odoo link (C-P2-10), or null (no link, or P2 absent). */
    public function odooLinkState(int $cid): ?string
    {
        try {
            $row = Db::one($this->c->db, 'SELECT coattr_link_state FROM contact_odoo_attributes WHERE coattr_contact_id = ?', 'i', [$cid]);
            return $row === null ? null : (string) $row['coattr_link_state'];
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() === 1146) {
                return null;
            }
            throw $e;
        }
    }

    // ---- agent scope --------------------------------------------------------------------------------

    /**
     * Agent actions on one person: 404 unless the contact is in the user's department scope
     * (P2's People\Scope, fail-closed: admin or level 3 sees all, otherwise only departments with
     * a user_client_permissions row; none => nothing).
     */
    public function assertContactInScope(Ctx $user, int $cid): void
    {
        Scope::forCtx($user)->assertContact($user->db, $cid);
    }

    /** @return list<int>|null null = every department; [] = none */
    public function scopeClientIds(Ctx $user): ?array
    {
        $s = Scope::forCtx($user);
        return $s->isAll() ? null : array_values(array_map('intval', $s->clientIds()));
    }

    // ---- internals ------------------------------------------------------------------------------------

    private function completionService(): CompletionService
    {
        return new CompletionService($this->sys(), CertSecret::fromGlobals());
    }

    /** The system Ctx P2 services run with (same connection, same base URL as the caller). */
    private function sys(): Ctx
    {
        if ($this->sys === null) {
            $host = (string) preg_replace('#^https?://#i', '', $this->c->baseUrl);
            $this->sys = SystemCtx::make($this->c->db, 0, (string) ($this->c->userAgent ?? 'training_kiosk'), $host);
        }
        return $this->sys;
    }

    private function rosterReady(): bool
    {
        $id = spl_object_id($this->c->db);
        if (!array_key_exists($id, self::$roster)) {
            $row = Db::one($this->c->db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training_roster'");
            self::$roster[$id] = (int) ($row['n'] ?? 0) === 1;
        }
        return self::$roster[$id];
    }
}
