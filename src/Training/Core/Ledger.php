<?php

namespace ITFlow\Training\Core;

/**
 * The training hash chain (plan A8): an append-only sequence of events in training_events,
 * each one's hash covering its predecessor's, with a singleton head row
 * (training_ledger_head, lhead_id = 1) holding the last seq and hash.
 *
 *   tevent_hash = sha256(tevent_prev_hash . "\n" . Canonical::row(the v1 columns))
 *
 * There are no database triggers (they break backup, restore and the Docker DR path); the
 * guarantees come from this class being the only writer, from LedgerVerifier walking the
 * chain, and from the head being anchored outside the database (every in-app backup's
 * version.txt).
 *
 * HEAD LOCKING. append() takes `SELECT ... FOR UPDATE` on the existing head row and never
 * INSERTs it: the head is created only by the 2.6.91 migration and db.sql. An
 * `INSERT IGNORE` on an existing key takes a shared lock, and two appenders both holding
 * S and both upgrading to X is a guaranteed deadlock - so a missing head is an error
 * (\RuntimeException 'ledger_uninitialized'), never something to repair here.
 *
 * append() must run inside the caller's Db::tx and be its LAST locking statement (lock order:
 * entity rows -> owning course row -> ledger head), so the head's X lock is held for as short
 * a time as possible and every appender acquires locks in the same order.
 *
 * EVENT TYPES. Each phase declares its own `TYPES_PHASEn` constant; append() accepts the union
 * of every constant named /^TYPES_PHASE\d+$/ (allowedTypes(), by reflection), so a later phase
 * adds a constant and never edits the check. An unknown type throws before anything is written.
 */
final class Ledger
{
    public const TYPES_PHASE1 = ['media.stored', 'media.pages_linked', 'media.file_purged', 'media.file_restored',
                                 'revision.published', 'course.archived', 'course.restored'];

    /** Phase 2 (spec §3.1 / §3.7). Each phase adds its own TYPES_PHASEn; allowedTypes() picks them all up. */
    public const TYPES_PHASE2 = ['requirement.saved', 'requirement.archived',
                                 'assignment.created', 'assignment.reopened', 'assignment.completed', 'assignment.cancelled',
                                 'assignment.waived', 'assignment.due_changed',
                                 'completion.recorded', 'completion.voided', 'cert.token_issued', 'evaluation.recorded',
                                 'session.opened', 'session.updated', 'session.finalized', 'session.cancelled',
                                 'roster.changed', 'jobgroup.saved', 'jobgroup.archived', 'trainer.changed', 'contact.hire_date_set',
                                 // Assignment reset / un-waive (agent, no schema change): the assignment's own event, and the
                                 // pair's kiosk run the reset abandoned (actor user + reason; the kiosk tells the learner).
                                 'assignment.unwaived', 'assignment.progress_reset', 'assignment.retake', 'run.reset'];

    public const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const ACTOR_TYPES = ['user', 'contact', 'kiosk', 'system'];

    /** Phase 3+4 kiosk (P3 spec §3.8 / §7.1). Payloads never carry PINs, codes, tokens or connector messages. */
    public const TYPES_PHASE3 = [
        'kiosk.enroll_code_issued', 'kiosk.enrolled', 'kiosk.enroll_failed', 'kiosk.token_reissued', 'kiosk.revoked', 'kiosk.cooldown', 'kiosk.cooldown_cleared',
        'kiosk.expiry_changed', 'kiosk.hidden', 'kiosk.mode_changed',
        'pin.attempt', 'pin.ok', 'pin.fail', 'pin.unavailable', 'pin.locked', 'pin.unlocked', 'pin.pause', 'pin.pause_cleared',
        'pin.setup_code_issued', 'pin.set', 'pin.source_changed', 'pin.odoo_blocked', 'pin.odoo_unblocked',
        'ksession.start', 'ksession.end',
        'run.start', 'run.superseded', 'run.failed', 'run.unlocked', 'run.blocked', 'run.abandoned', 'run.lesson_complete',
        'run.reopened',   // kiosk quick checks: a run from before them, awaiting sign-off with a must-pass check never passed
        'attempt.start', 'attempt.submit', 'signature.captured', 'online.attested', 'lesson.video_error', 'achievement.awarded',
    ];

    /** @var array<string, true>|null memoised allowedTypes() as a set */
    private static ?array $allowed = null;

    /**
     * @param array{type:string, actor_type?:string, actor_user_id?:?int, actor_contact_id?:?int,
     *              kiosk_id?:?int, ksess_id?:?int, subject_contact_id?:?int, course_id?:?int,
     *              entity_type?:?string, entity_id?:?int, entity_sha256?:?string,
     *              payload?:array, user_agent?:?string} $e
     * @return array{seq:int, hash:string}
     */
    public static function append(\mysqli $db, array $e): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('Ledger::append must run inside Db::tx');
        }
        $type = (string) ($e['type'] ?? '');
        if (!in_array($type, self::allowedTypes(), true)) {
            throw new \InvalidArgumentException("Ledger: unknown event type '$type'");
        }
        $actorUserId = self::optInt($e, 'actor_user_id');
        $actorType = (string) ($e['actor_type'] ?? ($actorUserId !== null ? 'user' : 'system'));
        if (!in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException("Ledger: bad actor_type '$actorType'");
        }
        $entityType = isset($e['entity_type']) ? (string) $e['entity_type'] : null;
        if ($entityType !== null && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $entityType) !== 1) {
            throw new \InvalidArgumentException('Ledger: bad entity_type');
        }
        $entitySha = isset($e['entity_sha256']) ? (string) $e['entity_sha256'] : null;
        if ($entitySha !== null && preg_match('/^[0-9a-f]{64}$/', $entitySha) !== 1) {
            throw new \InvalidArgumentException('Ledger: entity_sha256 must be 64 lowercase hex');
        }
        $payload = $e['payload'] ?? [];
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Ledger: payload must be an array');
        }
        $payloadJson = Canonical::doc($payload);
        Db::ensureUtf8mb4($db);

        // The last locking statement of the caller's transaction.
        $res = $db->query("SELECT lhead_last_seq, lhead_last_hash FROM training_ledger_head WHERE lhead_id = 1 FOR UPDATE");
        $head = $res->fetch_assoc();
        $res->free();
        if (!$head) {
            throw new \RuntimeException('ledger_uninitialized');
        }
        $seq = (int) $head['lhead_last_seq'] + 1;
        $prev = (string) $head['lhead_last_hash'];
        $at = Clock::nowUtc();

        // Built in text-protocol shape: exactly what a plain SELECT will hand the verifier back.
        $row = [
            'tevent_seq' => (string) $seq,
            'tevent_at_utc' => $at,
            'tevent_type' => $type,
            'tevent_actor_type' => $actorType,
            'tevent_actor_user_id' => self::str($actorUserId),
            'tevent_actor_contact_id' => self::str(self::optInt($e, 'actor_contact_id')),
            'tevent_kiosk_id' => self::str(self::optInt($e, 'kiosk_id')),
            'tevent_ksess_id' => self::str(self::optInt($e, 'ksess_id')),
            'tevent_subject_contact_id' => self::str(self::optInt($e, 'subject_contact_id')),
            'tevent_course_id' => self::str(self::optInt($e, 'course_id')),
            'tevent_entity_type' => $entityType,
            'tevent_entity_id' => self::str(self::optInt($e, 'entity_id')),
            'tevent_entity_sha256' => $entitySha,
            'tevent_payload_json' => $payloadJson,
            'tevent_user_agent' => Text::clip(isset($e['user_agent']) ? (string) $e['user_agent'] : null, 255),
            'tevent_hash_v' => (string) HashSpecs::current('training_events'),
            'tevent_prev_hash' => $prev,
        ];
        $hash = RowHasher::hash('training_events', $row);
        $row['tevent_hash'] = $hash;

        $cols = array_keys($row);
        Db::exec(
            $db,
            'INSERT INTO training_events (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            str_repeat('s', count($cols)),
            array_values($row)
        );
        Db::exec(
            $db,
            'UPDATE training_ledger_head SET lhead_last_seq = ?, lhead_last_hash = ?, lhead_updated_at_utc = ? WHERE lhead_id = 1',
            'sss',
            [(string) $seq, $hash, $at]
        );

        return ['seq' => $seq, 'hash' => $hash];
    }

    /**
     * entity_sha256 of a media.pages_linked event (spec §3.8): sha256 of the canonical JSON list
     * of the linked page rows' mpage_row_sha256 values, in page order.
     */
    public static function pagesEntitySha(array $rowShas): string
    {
        return Canonical::sha256(Canonical::doc(array_values(array_map('strval', $rowShas))));
    }

    /** @return array{seq:int, hash:string, updated_at_utc:?string} */
    public static function head(\mysqli $db): array
    {
        $res = $db->query("SELECT lhead_last_seq, lhead_last_hash, lhead_updated_at_utc FROM training_ledger_head WHERE lhead_id = 1");
        $row = $res->fetch_assoc();
        $res->free();
        if (!$row) {
            throw new \RuntimeException('ledger_uninitialized');
        }
        return [
            'seq' => (int) $row['lhead_last_seq'],
            'hash' => (string) $row['lhead_last_hash'],
            'updated_at_utc' => $row['lhead_updated_at_utc'],
        ];
    }

    /**
     * Every event type any phase registered: the array_merge of each class constant whose name
     * matches /^TYPES_PHASE\d+$/ (spec §1.5), found by reflection so a later phase only adds its
     * own constant and never edits this method. Static-memoised for the process.
     *
     * @return list<string>
     */
    private static function allowedTypes(): array
    {
        if (self::$allowed === null) {
            $types = [];
            foreach ((new \ReflectionClass(self::class))->getConstants() as $name => $value) {
                if (preg_match('/^TYPES_PHASE\d+$/', (string) $name) === 1 && is_array($value)) {
                    $types = array_merge($types, array_values($value));
                }
            }
            self::$allowed = array_fill_keys(array_map('strval', $types), true);
        }
        return array_keys(self::$allowed);
    }

    private static function optInt(array $e, string $k): ?int
    {
        if (!isset($e[$k])) {
            return null;
        }
        $v = $e[$k];
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?[0-9]{1,18}$/', $v)) {
            return (int) $v;
        }
        throw new \InvalidArgumentException("Ledger: '$k' must be an integer");
    }

    private static function str(?int $v): ?string
    {
        return $v === null ? null : (string) $v;
    }
}
