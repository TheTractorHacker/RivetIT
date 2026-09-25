<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * The assignment row operations Records (Lane C) needs inside its own transaction; the only
 * non-Lane-B writer of training_assignments (Phase 2 spec §3.3).
 *
 * Lock order (spec §0 #4): records mutex -> source-key read -> lockOpenForPair -> … -> ledger head.
 * closeCompleted() returns the ledger event; the caller appends it LAST with its other events.
 */
final class AssignmentStore
{
    public const COLUMNS = 'tassign_id, tassign_contact_id, tassign_course_id, tassign_status, tassign_anchor, tassign_reason,
        tassign_requirement_id, tassign_required, tassign_due_on, tassign_original_due_on, tassign_onboarding_from_on,
        tassign_waived_until, tassign_completion_id, tassign_created_at_utc, tassign_closed_at_utc, tassign_close_reason,
        tassign_close_note, tassign_reopened_count';

    /** The open assignment of a pair, locked FOR UPDATE (caller holds the records mutex), or null. */
    public static function lockOpenForPair(\mysqli $db, int $contactId, int $courseId): ?array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('AssignmentStore::lockOpenForPair must run inside Db::tx');
        }
        $row = Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_assignments
            WHERE tassign_contact_id = ? AND tassign_course_id = ? AND tassign_open_guard = 1 FOR UPDATE', 'ii', [$contactId, $courseId]);
        return $row === null ? null : self::normalize($row);
    }

    /**
     * Closes the (locked) open assignment $open as completed by $completionId. Returns the
     * assignment.completed ledger event for the caller to append last.
     *
     * $actor (optional) overrides the event's actor fields: actor_type, actor_user_id, actor_contact_id,
     * kiosk_id, ksess_id, user_agent. Default: user $userId, or system when $userId is null.
     */
    public static function closeCompleted(\mysqli $db, array $open, int $completionId, ?int $userId, array $actor = [],
                                          string $trigger = 'completion'): array
    {
        if (Db::depth() < 1) {
            throw new \LogicException('AssignmentStore::closeCompleted must run inside Db::tx');
        }
        $id = (int) $open['id'];
        $n = Db::exec($db, "UPDATE training_assignments SET tassign_status = 'completed', tassign_open_guard = NULL,
                tassign_completion_id = ?, tassign_closed_at_utc = ?, tassign_closed_by_user_id = ?, tassign_close_reason = 'completed'
            WHERE tassign_id = ? AND tassign_status = 'open'", 'isii', [$completionId, Clock::nowUtc(), $userId, $id]);
        if ($n !== 1) {
            throw new \LogicException("AssignmentStore::closeCompleted: assignment #$id is not open (lock it first)");
        }
        return self::event('assignment.completed', $open, [
            'course_id' => (int) $open['course_id'],
            'anchor' => (string) $open['anchor'],
            'completion_id' => $completionId,
            'close_reason' => 'completed',
            'trigger' => $trigger,
        ], $userId, $actor);
    }

    /** A ledger event array for an assignment. */
    public static function event(string $type, array $a, array $payload, ?int $userId, array $actor = []): array
    {
        $e = [
            'type' => $type,
            'actor_type' => $userId !== null ? 'user' : 'system',
            'actor_user_id' => $userId,
            'subject_contact_id' => (int) $a['contact_id'],
            'course_id' => (int) $a['course_id'],
            'entity_type' => 'assignment',
            'entity_id' => (int) $a['id'],
            'payload' => $payload,
        ];
        foreach (['actor_type', 'actor_user_id', 'actor_contact_id', 'kiosk_id', 'ksess_id', 'user_agent'] as $k) {
            if (array_key_exists($k, $actor)) {
                $e[$k] = $actor[$k];
            }
        }
        return $e;
    }

    /** A training_assignments row (any subset of COLUMNS) as a typed array. */
    public static function normalize(array $r): array
    {
        $i = static fn(string $k) => isset($r[$k]) ? (int) $r[$k] : null;
        $s = static fn(string $k) => isset($r[$k]) ? (string) $r[$k] : null;
        return [
            'id' => (int) $r['tassign_id'],
            'contact_id' => (int) $r['tassign_contact_id'],
            'course_id' => (int) $r['tassign_course_id'],
            'status' => (string) $r['tassign_status'],
            'anchor' => (string) $r['tassign_anchor'],
            'reason' => (string) $r['tassign_reason'],
            'requirement_id' => $i('tassign_requirement_id'),
            'required' => (int) ($r['tassign_required'] ?? 1) === 1,
            'due_on' => (string) $r['tassign_due_on'],
            'original_due_on' => (string) ($r['tassign_original_due_on'] ?? $r['tassign_due_on']),
            'onboarding_from_on' => $s('tassign_onboarding_from_on'),
            'waived_until' => $s('tassign_waived_until'),
            'completion_id' => $i('tassign_completion_id'),
            'created_at_utc' => $s('tassign_created_at_utc'),
            'closed_at_utc' => $s('tassign_closed_at_utc'),
            'close_reason' => $s('tassign_close_reason'),
            'close_note' => $s('tassign_close_note'),
            'reopened_count' => (int) ($r['tassign_reopened_count'] ?? 0),
        ];
    }
}
