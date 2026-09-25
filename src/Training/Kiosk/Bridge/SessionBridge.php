<?php

namespace ITFlow\Training\Kiosk\Bridge;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\HashedInsert;
use ITFlow\Training\Core\HashSpecs;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\RecordsMutex;
use ITFlow\Training\Core\SessionDigest;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Trainer\CourseInfo;

/**
 * Kiosk-channel group sessions (P3 spec §3.7, C-P2-7; lane K5). The ONLY place trainer mode
 * touches Phase 2's session tables (training_sessions, training_session_attendees) and its
 * records services.
 *
 * Seam. Phase 2 owns these tables and the completion writer. A kiosk session is a
 * training_sessions row with tsession_channel 'kiosk' (C-P2-1 pre-created columns):
 *   - while OPEN it is a mutable row, exactly like Phase 2's office sessions: every change is a
 *     Phase 2 ledger event (session.opened / session.updated / session.cancelled) written here
 *     with the kiosk actor (the trainer contact, §0.9);
 *   - FINALIZE freezes it the Phase 2 way, in Phase 2's lock order (session -> attendees ->
 *     records mutex): finalize fields (trainer signature, finalized_by_contact_id, the kiosk
 *     attestation), practical marks as 'sev:<attendee>' evaluation rows (channel 'session',
 *     proof evaluator_signed - the trainer signed and re-entered their PIN), the
 *     SessionDigest v1 over a text-protocol re-read, then per present attendee
 *     Records\CompletionService::issue('att:<attendee>', deferEvents) or
 *     tryIssueComponents(deferEvents) - the same rule as Phase 2's SessionService::finalize
 *     (v0 §6.4 T6) - and the events LAST (session.finalized, evaluation.recorded*, completion*).
 *     afterCommit() then runs Phase 2's per-completion afterCommit (listeners, awards, audit)
 *     and ONE reconcile over the present attendees.
 * Only Phase 2 contracts marked "Present" in §2.6 are used (CompletionService::issue /
 * tryIssueComponents / afterCommit / reconcile, CertSecret, and the A1 core: RecordsMutex,
 * SessionDigest, HashedInsert, TYPES_PHASE2). If Phase 2 later ships kiosk parameters on
 * SessionService (C-P2-7), only the bodies below change.
 *
 * available() is false (trainer mode hides session tiles) until the Phase 2 tables and the
 * Records completion service are both present.
 */
final class SessionBridge
{
    public const COMPLETION_SERVICE = '\\ITFlow\\Training\\Records\\CompletionService';
    public const CERT_SECRET = '\\ITFlow\\Training\\Records\\CertSecret';
    public const FINALIZE_ATTEST = 'I led this session and the people listed attended all of it.';
    public const ATTENDANCE = ['present', 'partial', 'absent'];
    public const PRACTICAL = ['not_evaluated', 'pass', 'fail'];
    public const KIOSK_PROOFS = ['self_pin_signature', 'self_pin', 'trainer_attested'];
    public const MAX_ATTENDEES = 500;

    private const SESSION_COLS = 'tsession_id, tsession_course_id, tsession_revision_id, tsession_status, tsession_held_on, tsession_start_time,
        tsession_duration_minutes, tsession_client_id, tsession_location, tsession_topic, tsession_trainer_contact_id, tsession_trainer_user_id,
        tsession_trainer_name, tsession_channel, tsession_created_kiosk_id, tsession_started_at_utc, tsession_finalized_at_utc, tsession_version';
    private const ATT_COLS = 'tattendee_id, tattendee_tsession_id, tattendee_contact_id, tattendee_proof, tattendee_attest_reason, tattendee_checked_in_at_utc,
        tattendee_kiosk_id, tattendee_tsig_id, tattendee_attendance, tattendee_practical, tattendee_notes, tattendee_marked_by_contact_id,
        tattendee_removed_at_utc, tattendee_removed_reason';

    /** @var array<string, bool> */
    private static array $avail = [];

    /** @param array $actor the kiosk ledger actor (KioskCtx::eventBase()) - trainer actions are 'contact' (§0.9) */
    public function __construct(private readonly Ctx $c, private readonly array $actor = [])
    {
    }

    /** Phase 2 session tables + the Records completion service are present (memoised per database). */
    public static function available(\mysqli $db): bool
    {
        $res = $db->query('SELECT DATABASE() AS d');
        $name = (string) ($res->fetch_assoc()['d'] ?? '');
        $res->free();
        if (isset(self::$avail[$name])) {
            return self::$avail[$name];
        }
        $ok = false;
        try {
            $cs = self::COMPLETION_SERVICE;
            $row = Db::one($db, "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
                ('training_sessions', 'training_session_attendees', 'training_evaluations', 'training_completions', 'training_cert_counters')");
            $ok = (int) ($row['n'] ?? 0) === 5
                && class_exists($cs) && method_exists($cs, 'issue') && method_exists($cs, 'tryIssueComponents') && method_exists($cs, 'afterCommit')
                && class_exists(self::CERT_SECRET)
                && isset(HashSpecs::DIGESTS['training_sessions'][1]);
        } catch (\Throwable $e) {
            error_log('Kiosk SessionBridge::available: ' . get_class($e));
            $ok = false;
        }
        return self::$avail[$name] = $ok;
    }

    /** Tests only. */
    public static function reset(): void
    {
        self::$avail = [];
    }

    // =========================================================================================
    // open / reads
    // =========================================================================================

    /**
     * Opens a kiosk session (own tx; event session.opened). $s: course_id, revision_id, client_id,
     * location, topic, trainer_contact_id, trainer_name, kiosk_id. held_on = today (local),
     * start_time = now (local), started_at_utc = now.
     */
    public function open(array $s): int
    {
        $db = $this->c->db;
        $now = KTime::now();
        $today = Clock::todayLocal();
        $courseId = (int) $s['course_id'];
        return Db::tx($db, function () use ($db, $s, $now, $today, $courseId): int {
            $id = Db::insert($db, "INSERT INTO training_sessions (tsession_course_id, tsession_revision_id, tsession_status, tsession_held_on, tsession_start_time,
                    tsession_client_id, tsession_location, tsession_topic, tsession_trainer_contact_id, tsession_trainer_name, tsession_channel,
                    tsession_is_backfill, tsession_created_kiosk_id, tsession_started_at_utc)
                VALUES (?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, 'kiosk', 0, ?, ?)",
                'iississisis',
                [$courseId, (int) $s['revision_id'], $today, date('H:i:s'), max(0, (int) $s['client_id']),
                 Text::clip($s['location'] ?? null, 200), Text::clip($s['topic'] ?? null, 200), (int) $s['trainer_contact_id'],
                 (string) Text::clip((string) $s['trainer_name'], 200), (int) $s['kiosk_id'], $now]);
            Ledger::append($db, $this->actor + [
                'type' => 'session.opened',
                'course_id' => $courseId,
                'entity_type' => 'session',
                'entity_id' => $id,
                'payload' => ['course_id' => $courseId, 'held_on' => $today, 'attendee_ids' => []],
            ]);
            return $id;
        });
    }

    /** The session row (no lock), or null. */
    public function row(int $tsessionId): ?array
    {
        if ($tsessionId < 1) {
            return null;
        }
        $r = Db::one($this->c->db, 'SELECT ' . self::SESSION_COLS . ' FROM training_sessions WHERE tsession_id = ?', 'i', [$tsessionId]);
        return $r === null ? null : self::castSession($r);
    }

    /**
     * INSIDE the caller's tx: the session FOR UPDATE, which must be an OPEN kiosk-or-agent
     * session led by $trainerContactId (null = any trainer, used by check-in which is bound to
     * the ksess). 404 when it is not this trainer's; 409 session_closed when no longer open.
     */
    public function lockOpen(int $tsessionId, ?int $trainerContactId): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('SessionBridge::lockOpen must run inside Db::tx');
        }
        $r = Db::one($this->c->db, 'SELECT ' . self::SESSION_COLS . ' FROM training_sessions WHERE tsession_id = ? FOR UPDATE', 'i', [$tsessionId]);
        if ($r === null || ($trainerContactId !== null && (int) $r['tsession_trainer_contact_id'] !== $trainerContactId)) {
            throw ApiException::notFound('That session was not found.');
        }
        if ($r['tsession_status'] !== 'open') {
            throw new ApiException(409, 'session_closed', 'This session is already finished.');
        }
        return self::castSession($r);
    }

    /**
     * Session + attendees for the trainer screens.
     *
     * @return array{session:array, attendees:list<array>}
     */
    public function get(int $tsessionId): array
    {
        $s = $this->row($tsessionId);
        if ($s === null) {
            throw ApiException::notFound('That session was not found.');
        }
        $rows = Db::all($this->c->db, 'SELECT a.tattendee_id, a.tattendee_contact_id, a.tattendee_proof, a.tattendee_attest_reason, a.tattendee_checked_in_at_utc,
                a.tattendee_kiosk_id, a.tattendee_tsig_id, a.tattendee_attendance, a.tattendee_practical, a.tattendee_notes, a.tattendee_removed_at_utc,
                a.tattendee_removed_reason, c.contact_name, cl.client_name
            FROM training_session_attendees a
            JOIN contacts c ON c.contact_id = a.tattendee_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE a.tattendee_tsession_id = ?
            ORDER BY a.tattendee_removed_at_utc IS NOT NULL, c.contact_name, a.tattendee_id', 'i', [$tsessionId]);
        $att = [];
        foreach ($rows as $r) {
            $name = trim((string) $r['contact_name']);
            $att[] = [
                'attendee_id' => (int) $r['tattendee_id'],
                'contact_id' => (int) $r['tattendee_contact_id'],
                'name' => $name,
                'first' => KioskAuth::firstName($name),
                'initials' => KioskAuth::initials($name),
                'dept' => (string) ($r['client_name'] ?? ''),
                'proof' => (string) $r['tattendee_proof'],
                'attest_reason' => $r['tattendee_attest_reason'] === null ? null : (string) $r['tattendee_attest_reason'],
                'checked_in_at' => Clock::toIso((string) $r['tattendee_checked_in_at_utc'], true),
                'signed' => $r['tattendee_tsig_id'] !== null,
                'attendance' => (string) $r['tattendee_attendance'],
                'practical' => (string) $r['tattendee_practical'],
                'notes' => $r['tattendee_notes'] === null ? null : (string) $r['tattendee_notes'],
                'removed' => $r['tattendee_removed_at_utc'] !== null,
                'removed_reason' => $r['tattendee_removed_reason'] === null ? null : (string) $r['tattendee_removed_reason'],
            ];
        }
        return ['session' => $s, 'attendees' => $att];
    }

    /**
     * Open kiosk sessions started within $maxAgeHours, optionally limited to departments
     * ($clientIds null = all). Newest first.
     *
     * @return list<array{tsession_id:int, course_id:int, trainer_contact_id:int, trainer_name:string, started_at_utc:?string, client_id:int, location:?string, topic:?string}>
     */
    public function listOpen(?array $clientIds, int $maxAgeHours): array
    {
        $since = KTime::plus(-max(1, $maxAgeHours) * 3600);
        $sql = "SELECT tsession_id, tsession_course_id, tsession_trainer_contact_id, tsession_trainer_name, tsession_started_at_utc, tsession_client_id,
                tsession_location, tsession_topic
            FROM training_sessions WHERE tsession_status = 'open' AND tsession_channel = 'kiosk' AND tsession_started_at_utc >= ?";
        $types = 's';
        $p = [$since];
        if ($clientIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn(int $i) => $i > 0)));
            if ($ids === []) {
                return [];
            }
            $sql .= ' AND tsession_client_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            $p = array_merge($p, $ids);
        }
        $sql .= ' ORDER BY tsession_started_at_utc DESC, tsession_id DESC LIMIT 50';
        return array_map(static fn(array $r) => [
            'tsession_id' => (int) $r['tsession_id'],
            'course_id' => (int) $r['tsession_course_id'],
            'trainer_contact_id' => (int) $r['tsession_trainer_contact_id'],
            'trainer_name' => (string) $r['tsession_trainer_name'],
            'started_at_utc' => $r['tsession_started_at_utc'] === null ? null : (string) $r['tsession_started_at_utc'],
            'client_id' => (int) $r['tsession_client_id'],
            'location' => $r['tsession_location'] === null ? null : (string) $r['tsession_location'],
            'topic' => $r['tsession_topic'] === null ? null : (string) $r['tsession_topic'],
        ], Db::all($this->c->db, $sql, $types, $p));
    }

    /**
     * People in $clientIds (null = every department) with an OPEN assignment for the course who
     * are eligible and not already on the session: the suggested roster (v0 §6.4 T2).
     *
     * @return list<array{contact_id:int, name:string, first:string, initials:string, dept:string, client_id:int, due_on:string}>
     */
    public function suggested(int $courseId, ?array $clientIds, int $excludeSessionId = 0, int $limit = 200): array
    {
        $sql = "SELECT a.tassign_contact_id, a.tassign_due_on, c.contact_name, c.contact_client_id, cl.client_name
            FROM training_assignments a
            JOIN contacts c ON c.contact_id = a.tassign_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE a.tassign_course_id = ? AND a.tassign_status = 'open' AND c.contact_archived_at IS NULL";
        $types = 'i';
        $p = [$courseId];
        if ($clientIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn(int $i) => $i > 0)));
            if ($ids === []) {
                return [];
            }
            $sql .= ' AND c.contact_client_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            $p = array_merge($p, $ids);
        }
        if ($excludeSessionId > 0) {
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM training_session_attendees x WHERE x.tattendee_tsession_id = ? AND x.tattendee_contact_id = a.tassign_contact_id
                AND x.tattendee_removed_at_utc IS NULL)';
            $types .= 'i';
            $p[] = $excludeSessionId;
        }
        $sql .= ' ORDER BY a.tassign_due_on, c.contact_name, a.tassign_contact_id LIMIT ' . max(1, min(500, $limit));
        $out = [];
        foreach (Db::all($this->c->db, $sql, $types, $p) as $r) {
            $cid = (int) $r['tassign_contact_id'];
            if (!Eligibility::isEligible($this->c->db, $cid, $this->c)) {
                continue;
            }
            $name = trim((string) $r['contact_name']);
            $out[] = [
                'contact_id' => $cid,
                'name' => $name,
                'first' => KioskAuth::firstName($name),
                'initials' => KioskAuth::initials($name),
                'dept' => (string) ($r['client_name'] ?? ''),
                'client_id' => (int) $r['contact_client_id'],
                'due_on' => (string) $r['tassign_due_on'],
            ];
        }
        return $out;
    }

    // =========================================================================================
    // attendee writes (session must be open; the caller checked ownership)
    // =========================================================================================

    /** INSIDE the caller's tx (after lockOpen): the attendee row of this person on this session, FOR UPDATE, or null. */
    public function attendeeFor(int $tsessionId, int $contactId): ?array
    {
        return Db::one($this->c->db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_tsession_id = ? AND tattendee_contact_id = ? FOR UPDATE',
            'ii', [$tsessionId, $contactId]);
    }

    /**
     * INSIDE the caller's tx, after lockOpen(): records one attendee (a new row, or a removed
     * row brought back). An attendee already on the session => ['already' => true] and nothing
     * changes. Returns ['already' => bool, 'attendee_id' => int, 'events' => [session.updated]].
     */
    public function checkIn(int $tsessionId, int $contactId, string $proof, ?int $tsigId, ?string $attestReason, int $kioskId, ?int $byContactId): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('SessionBridge::checkIn must run inside Db::tx');
        }
        if (!in_array($proof, self::KIOSK_PROOFS, true)) {
            throw new \InvalidArgumentException('SessionBridge::checkIn: bad proof');
        }
        $db = $this->c->db;
        $s = $this->lockedRow($tsessionId);
        $old = $this->attendeeFor($tsessionId, $contactId);
        if ($old !== null && $old['tattendee_removed_at_utc'] === null) {
            return ['already' => true, 'attendee_id' => (int) $old['tattendee_id'], 'events' => []];
        }
        $count = Db::one($db, 'SELECT COUNT(*) AS n FROM training_session_attendees WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL', 'i', [$tsessionId]);
        if ((int) ($count['n'] ?? 0) >= self::MAX_ATTENDEES) {
            throw ApiException::validation(['contact_id' => 'This session is full.']);
        }
        $now = KTime::now();
        $reason = Text::clip($attestReason, 255);
        if ($old === null) {
            $attId = Db::insert($db, "INSERT INTO training_session_attendees (tattendee_tsession_id, tattendee_contact_id, tattendee_proof, tattendee_attest_reason,
                    tattendee_checked_in_at_utc, tattendee_kiosk_id, tattendee_tsig_id, tattendee_attendance, tattendee_practical, tattendee_marked_by_contact_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'present', 'not_evaluated', ?)", 'iisssiii',
                [$tsessionId, $contactId, $proof, $reason, $now, $kioskId > 0 ? $kioskId : null, $tsigId, $byContactId]);
        } else {
            $attId = (int) $old['tattendee_id'];
            Db::exec($db, "UPDATE training_session_attendees SET tattendee_proof = ?, tattendee_attest_reason = ?, tattendee_checked_in_at_utc = ?,
                    tattendee_kiosk_id = ?, tattendee_tsig_id = ?, tattendee_attendance = 'present', tattendee_practical = 'not_evaluated',
                    tattendee_marked_by_contact_id = ?, tattendee_removed_at_utc = NULL, tattendee_removed_reason = NULL
                WHERE tattendee_id = ?", 'sssiiii',
                [$proof, $reason, $now, $kioskId > 0 ? $kioskId : null, $tsigId, $byContactId, $attId]);
        }
        Db::exec($db, 'UPDATE training_sessions SET tsession_version = tsession_version + 1 WHERE tsession_id = ?', 'i', [$tsessionId]);
        return ['already' => false, 'attendee_id' => $attId, 'events' => [$this->updatedEvent($s, $contactId, 'checkin')]];
    }

    /** Attendance / practical / notes on one attendee (own tx; session.updated). The caller verified the session is this trainer's. */
    public function mark(int $attendeeId, ?string $attendance, ?string $practical, ?string $notes, int $byContactId): void
    {
        if ($attendance !== null && !in_array($attendance, self::ATTENDANCE, true)) {
            throw ApiException::validation(['attendance' => 'Not a valid choice.']);
        }
        if ($practical !== null && !in_array($practical, self::PRACTICAL, true)) {
            throw ApiException::validation(['practical' => 'Not a valid choice.']);
        }
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $attendeeId, $attendance, $practical, $notes, $byContactId): void {
            [$s, $a] = $this->lockAttendee($attendeeId);
            if ($a['tattendee_removed_at_utc'] !== null) {
                throw new ApiException(409, 'validation', 'This person was removed from the session.');
            }
            Db::exec($db, 'UPDATE training_session_attendees SET tattendee_attendance = COALESCE(?, tattendee_attendance),
                    tattendee_practical = COALESCE(?, tattendee_practical), tattendee_notes = ?, tattendee_marked_by_contact_id = ?
                WHERE tattendee_id = ?', 'sssii',
                [$attendance, $practical, $notes === null ? $a['tattendee_notes'] : Text::clip($notes, 500), $byContactId, $attendeeId]);
            Db::exec($db, 'UPDATE training_sessions SET tsession_version = tsession_version + 1 WHERE tsession_id = ?', 'i', [(int) $s['tsession_id']]);
            Ledger::append($db, $this->updatedEvent($s, (int) $a['tattendee_contact_id'], 'mark'));
        });
    }

    /** Soft-removes one attendee with a reason (own tx; session.updated). */
    public function remove(int $attendeeId, string $reason, int $byContactId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $attendeeId, $reason, $byContactId): void {
            [$s, $a] = $this->lockAttendee($attendeeId);
            if ($a['tattendee_removed_at_utc'] !== null) {
                return;
            }
            Db::exec($db, 'UPDATE training_session_attendees SET tattendee_removed_at_utc = ?, tattendee_removed_reason = ?, tattendee_marked_by_contact_id = ?
                WHERE tattendee_id = ?', 'ssii', [KTime::now(), (string) Text::clip($reason, 255), $byContactId, $attendeeId]);
            Db::exec($db, 'UPDATE training_sessions SET tsession_version = tsession_version + 1 WHERE tsession_id = ?', 'i', [(int) $s['tsession_id']]);
            Ledger::append($db, $this->updatedEvent($s, (int) $a['tattendee_contact_id'], 'remove'));
        });
    }

    /** Is this person on the session now (a row that is not removed)? Plain read; checkIn() re-checks under the lock. */
    public function isCheckedIn(int $tsessionId, int $contactId): bool
    {
        return Db::one($this->c->db, 'SELECT tattendee_id FROM training_session_attendees WHERE tattendee_tsession_id = ? AND tattendee_contact_id = ?
            AND tattendee_removed_at_utc IS NULL', 'ii', [$tsessionId, $contactId]) !== null;
    }

    /** How many people are marked present (not removed) - finalize needs at least one. */
    public function presentCount(int $tsessionId): int
    {
        $r = Db::one($this->c->db, "SELECT COUNT(*) AS n FROM training_session_attendees WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL
            AND tattendee_attendance = 'present'", 'i', [$tsessionId]);
        return (int) ($r['n'] ?? 0);
    }

    /** The session an attendee belongs to (no lock) - for ownership checks. */
    public function sessionOfAttendee(int $attendeeId): ?array
    {
        $a = Db::one($this->c->db, 'SELECT tattendee_tsession_id FROM training_session_attendees WHERE tattendee_id = ?', 'i', [$attendeeId]);
        return $a === null ? null : $this->row((int) $a['tattendee_tsession_id']);
    }

    // =========================================================================================
    // finalize / cancel
    // =========================================================================================

    /**
     * INSIDE the caller's Db::tx (the caller has inserted the trainer's signature and appends
     * its signature.captured event BEFORE the returned events). Freezes the session and issues
     * the records - see the class comment.
     *
     * @return array{completions:list<array{contact_id:int, name:string, completion_id:int, cert_number:?string}>,
     *   pending:list<array{contact_id:int, name:string, reason:string}>, events:list<array>, opaque:array}
     */
    public function finalize(int $tsessionId, int $trainerContactId, int $trainerTsigId, int $kioskId): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('SessionBridge::finalize must run inside Db::tx');
        }
        $db = $this->c->db;
        $s = $this->lockOpen($tsessionId, $trainerContactId);
        $atts = Db::all($db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_tsession_id = ? ORDER BY tattendee_contact_id FOR UPDATE',
            'i', [$tsessionId]);
        RecordsMutex::acquire($db);

        $present = array_values(array_filter($atts, static fn($a) => $a['tattendee_removed_at_utc'] === null && $a['tattendee_attendance'] === 'present'));
        if ($present === []) {
            throw new ApiException(422, 'validation', 'Check in at least one person, or mark someone present, before finishing.', ['attendees' => 'Nobody is present.']);
        }
        $courseId = (int) $s['tsession_course_id'];
        $revisionId = (int) $s['tsession_revision_id'];
        $course = CourseInfo::atRevision($db, $courseId, $revisionId);
        if ($course === null) {
            throw new ApiException(409, 'session_closed', 'This course is no longer published. Ask the office to record the session.');
        }
        $heldOn = (string) $s['tsession_held_on'];
        $today = Clock::todayLocal();
        if ($heldOn > $today) {
            throw new ApiException(422, 'validation', 'This session starts in the future.');
        }
        $now = KTime::now();
        $minutes = null;
        if ($s['tsession_started_at_utc'] !== null) {
            $minutes = max(1, min(65535, (int) round(((KTime::epoch($now) ?? 0) - (KTime::epoch((string) $s['tsession_started_at_utc']) ?? 0)) / 60)));
        }
        $trainerName = (string) $s['tsession_trainer_name'];
        $names = self::names($db, array_map(static fn($a) => (int) $a['tattendee_contact_id'], $present));

        Db::exec($db, "UPDATE training_sessions SET tsession_status = 'finalized', tsession_duration_minutes = ?, tsession_trainer_tsig_id = ?,
                tsession_finalized_at_utc = ?, tsession_finalized_by_contact_id = ?, tsession_finalize_attest = ?, tsession_digest_v = 1,
                tsession_version = tsession_version + 1
            WHERE tsession_id = ?", 'iisisi', [$minutes, $trainerTsigId, $now, $trainerContactId, self::FINALIZE_ATTEST, $tsessionId]);

        $actor = $this->actor;
        $evalEvents = [];
        $evalIds = [];
        if ($course['needs_practical']) {
            foreach ($present as $a) {
                $mark = (string) $a['tattendee_practical'];
                if ($mark !== 'pass' && $mark !== 'fail') {
                    continue;
                }
                $cid = (int) $a['tattendee_contact_id'];
                if ($cid === $trainerContactId) {
                    throw new ApiException(422, 'validation', 'The trainer cannot mark their own practical.');
                }
                $ins = HashedInsert::insert($db, 'training_evaluations', [
                    'evaluation_source_key' => 'sev:' . (int) $a['tattendee_id'],
                    'evaluation_contact_id' => (string) $cid,
                    'evaluation_course_id' => (string) $courseId,
                    'evaluation_revision_id' => (string) $revisionId,
                    'evaluation_run_id' => null,
                    'evaluation_tsession_id' => (string) $tsessionId,
                    'evaluation_channel' => 'session',
                    'evaluation_evaluator_contact_id' => (string) $trainerContactId,
                    'evaluation_evaluator_user_id' => null,
                    'evaluation_evaluator_name' => (string) Text::clip($trainerName, 200),
                    'evaluation_evaluated_on' => $heldOn,
                    'evaluation_result' => $mark,
                    'evaluation_equipment' => null,
                    'evaluation_checklist_json' => null,
                    'evaluation_notes' => $a['tattendee_notes'] === null ? null : (string) $a['tattendee_notes'],
                    'evaluation_proof' => 'evaluator_signed',
                    'evaluation_evaluator_tsig_id' => (string) $trainerTsigId,
                    'evaluation_evaluatee_tsig_id' => $a['tattendee_tsig_id'] === null ? null : (string) $a['tattendee_tsig_id'],
                    'evaluation_evidence_media_id' => null,
                    'evaluation_kiosk_id' => $kioskId > 0 ? (string) $kioskId : null,
                    'evaluation_recorded_by_user_id' => null,
                    'evaluation_recorded_at_utc' => KTime::now(),
                    'evaluation_hash_v' => '1',
                ]);
                $evalIds[(int) $a['tattendee_id']] = $ins['id'];
                $evalEvents[] = $actor + [
                    'type' => 'evaluation.recorded',
                    'subject_contact_id' => $cid,
                    'course_id' => $courseId,
                    'entity_type' => 'evaluation',
                    'entity_id' => $ins['id'],
                    'entity_sha256' => $ins['sha'],
                    'payload' => ['course_id' => $courseId, 'result' => $mark, 'evaluated_on' => $heldOn, 'channel' => 'session', 'tsession_id' => $tsessionId],
                ];
            }
        }

        $digest = SessionDigest::computeFromDb($db, $tsessionId, 1);
        Db::exec($db, 'UPDATE training_sessions SET tsession_sha256 = ? WHERE tsession_id = ?', 'si', [$digest, $tsessionId]);

        $cs = $this->completionService();
        $issued = [];
        $completions = [];
        $pending = [];
        $completionEvents = [];
        foreach ($present as $a) {
            $cid = (int) $a['tattendee_contact_id'];
            $attId = (int) $a['tattendee_id'];
            $mark = (string) $a['tattendee_practical'];
            $res = null;
            if (!$course['needs_online'] && (!$course['needs_practical'] || $mark === 'pass')) {
                $res = $cs->issue([
                    'contact_id' => $cid,
                    'course_id' => $courseId,
                    'method' => $course['needs_practical'] ? ($course['needs_session'] ? 'blended' : 'evaluation') : 'session',
                    'proof' => (string) $a['tattendee_proof'],
                    'source_key' => 'att:' . $attId,
                    'completed_on' => $heldOn,
                    'trained_on' => $heldOn,
                    'evaluated_on' => $course['needs_practical'] ? $heldOn : null,
                    'revision_id' => $revisionId,
                    'duration_minutes' => $minutes,
                    'tsession_id' => $tsessionId,
                    'tattendee_id' => $attId,
                    'evaluation_id' => $evalIds[$attId] ?? null,
                    'trainer_contact_id' => $trainerContactId,
                    'trainer_name' => $trainerName,
                    'evaluator_name' => $course['needs_practical'] ? $trainerName : null,
                    'learner_tsig_id' => $a['tattendee_tsig_id'] === null ? null : (int) $a['tattendee_tsig_id'],
                    'trainer_tsig_id' => $trainerTsigId,
                    'kiosk_id' => $a['tattendee_kiosk_id'] !== null ? (int) $a['tattendee_kiosk_id'] : ($kioskId > 0 ? $kioskId : null),
                    'actor_type' => (string) ($actor['actor_type'] ?? 'contact'),
                    'actor_contact_id' => $actor['actor_contact_id'] ?? $trainerContactId,
                    'ksess_id' => $actor['ksess_id'] ?? null,
                ], true);
            } elseif ($course['needs_practical'] && $mark === 'fail') {
                $pending[] = ['contact_id' => $cid, 'name' => $names[$cid] ?? '', 'reason' => 'practical_failed'];
            } else {
                $res = $cs->tryIssueComponents($cid, $courseId, true);
                if ($res === null) {
                    $why = method_exists($cs, 'lastPendingReason') ? ($cs->lastPendingReason() ?? 'pending') : 'pending';
                    $pending[] = ['contact_id' => $cid, 'name' => $names[$cid] ?? '', 'reason' => (string) $why];
                }
            }
            if ($res !== null) {
                foreach ($res['events'] ?? [] as $e) {
                    $completionEvents[] = $e;
                }
                unset($res['events']);
                $issued[] = $res;
                $completions[] = ['contact_id' => $cid, 'name' => $names[$cid] ?? '', 'completion_id' => (int) $res['completion_id'],
                                  'cert_number' => isset($res['cert_number']) && $res['cert_number'] !== null ? (string) $res['cert_number'] : null];
            }
        }

        $events = [$actor + [
            'type' => 'session.finalized',
            'course_id' => $courseId,
            'entity_type' => 'session',
            'entity_id' => $tsessionId,
            'entity_sha256' => $digest,
            'payload' => ['course_id' => $courseId, 'held_on' => $heldOn, 'present' => count($present),
                          'total' => count(array_filter($atts, static fn($a) => $a['tattendee_removed_at_utc'] === null)), 'digest_v' => 1],
        ]];
        foreach (array_merge($evalEvents, $completionEvents) as $e) {
            $events[] = $e;
        }
        return [
            'completions' => $completions,
            'pending' => $pending,
            'events' => $events,
            'opaque' => ['issued' => $issued, 'present' => array_map(static fn($a) => (int) $a['tattendee_contact_id'], $present)],
        ];
    }

    /** After COMMIT of finalize(): Phase 2's afterCommit per completion (reconcile suppressed), then ONE reconcile. Best-effort. */
    public function afterCommit(array $r): void
    {
        $opaque = $r['opaque'] ?? [];
        $cs = null;
        try {
            $cs = $this->completionService();
        } catch (\Throwable $e) {
            error_log('Kiosk SessionBridge::afterCommit: ' . get_class($e));
            return;
        }
        foreach ($opaque['issued'] ?? [] as $res) {
            try {
                $res['suppress_reconcile'] = true;
                $cs->afterCommit($res);
            } catch (\Throwable $e) {
                error_log('Kiosk SessionBridge::afterCommit completion: ' . get_class($e));
            }
        }
        $cls = self::COMPLETION_SERVICE;
        if (method_exists($cls, 'reconcile') && ($opaque['present'] ?? []) !== []) {
            try {
                $cls::reconcile($this->c, $opaque['present'], 'session');
            } catch (\Throwable $e) {
                error_log('Kiosk SessionBridge::afterCommit reconcile: ' . get_class($e));
            }
        }
    }

    /** Cancels an open session with a reason (own tx; session.cancelled). The caller verified ownership. */
    public function cancel(int $tsessionId, string $reason, int $byContactId): void
    {
        $db = $this->c->db;
        Db::tx($db, function () use ($db, $tsessionId, $reason, $byContactId): void {
            $s = $this->lockOpen($tsessionId, $byContactId);
            $reason = (string) Text::clip($reason, 255);
            Db::exec($db, "UPDATE training_sessions SET tsession_status = 'cancelled', tsession_cancel_reason = ?, tsession_version = tsession_version + 1
                WHERE tsession_id = ?", 'si', [$reason, $tsessionId]);
            Ledger::append($db, $this->actor + [
                'type' => 'session.cancelled',
                'course_id' => (int) $s['tsession_course_id'],
                'entity_type' => 'session',
                'entity_id' => $tsessionId,
                'payload' => ['course_id' => (int) $s['tsession_course_id'], 'held_on' => (string) $s['tsession_held_on'],
                              'attendee_ids' => $this->activeIds($tsessionId), 'reason' => $reason],
            ]);
        });
    }

    // =========================================================================================
    // internals
    // =========================================================================================

    private function completionService(): object
    {
        $cls = self::COMPLETION_SERVICE;
        $secret = self::CERT_SECRET;
        if (!class_exists($cls) || !class_exists($secret)) {
            throw new ApiException(503, 'records_unavailable', "Training records aren't available right now.");
        }
        return new $cls($this->c, $secret::fromGlobals());
    }

    private function lockedRow(int $tsessionId): array
    {
        $r = Db::one($this->c->db, 'SELECT ' . self::SESSION_COLS . ' FROM training_sessions WHERE tsession_id = ? FOR UPDATE', 'i', [$tsessionId]);
        if ($r === null) {
            throw ApiException::notFound('That session was not found.');
        }
        if ($r['tsession_status'] !== 'open') {
            throw new ApiException(409, 'session_closed', 'This session is already finished.');
        }
        return self::castSession($r);
    }

    /** @return array{0:array, 1:array} session (open, FOR UPDATE) and attendee (FOR UPDATE), session first (P2 order). */
    private function lockAttendee(int $attendeeId): array
    {
        $db = $this->c->db;
        $a = Db::one($db, 'SELECT tattendee_tsession_id FROM training_session_attendees WHERE tattendee_id = ?', 'i', [$attendeeId]);
        if ($a === null) {
            throw ApiException::notFound('That person is not on this session.');
        }
        $s = $this->lockedRow((int) $a['tattendee_tsession_id']);
        $row = Db::one($db, 'SELECT ' . self::ATT_COLS . ' FROM training_session_attendees WHERE tattendee_id = ? FOR UPDATE', 'i', [$attendeeId]);
        if ($row === null) {
            throw ApiException::notFound('That person is not on this session.');
        }
        return [$s, $row];
    }

    private function activeIds(int $tsessionId): array
    {
        return array_map('intval', array_column(Db::all($this->c->db, 'SELECT tattendee_contact_id FROM training_session_attendees
            WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL ORDER BY tattendee_contact_id', 'i', [$tsessionId]), 'tattendee_contact_id'));
    }

    private function updatedEvent(array $s, int $subjectContactId, string $reason): array
    {
        return $this->actor + [
            'type' => 'session.updated',
            'subject_contact_id' => $subjectContactId,
            'course_id' => (int) $s['tsession_course_id'],
            'entity_type' => 'session',
            'entity_id' => (int) $s['tsession_id'],
            'payload' => ['course_id' => (int) $s['tsession_course_id'], 'held_on' => (string) $s['tsession_held_on'],
                          'attendee_ids' => $this->activeIds((int) $s['tsession_id']), 'reason' => $reason],
        ];
    }

    /** @return array<int, string> */
    private static function names(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (Db::all($db, 'SELECT contact_id, contact_name FROM contacts WHERE contact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            str_repeat('i', count($ids)), $ids) as $r) {
            $out[(int) $r['contact_id']] = trim((string) $r['contact_name']);
        }
        return $out;
    }

    private static function castSession(array $r): array
    {
        foreach (['tsession_id', 'tsession_course_id', 'tsession_client_id', 'tsession_version'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['tsession_revision_id', 'tsession_trainer_contact_id', 'tsession_trainer_user_id', 'tsession_created_kiosk_id', 'tsession_duration_minutes'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        return $r;
    }
}
