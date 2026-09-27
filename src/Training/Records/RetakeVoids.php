<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * "Reset (take again)" voids (Assign\AssignmentReset::retake): a void whose reason starts with PREFIX.
 *
 * After one, the person takes the course again FROM THE START, so the parts of a blended course done before it no
 * longer count toward a new record: the session attendance, practical evaluation and online run the voided record
 * used, and any part dated before the (latest) take-again void. CompletionService::tryIssueComponents and the
 * online part (Kiosk AttestedRuns through OnlineComponentSource) skip them; RecordsBridge::attendedSession too.
 * An ordinary void (Records > Void: "this record was entered wrong") keeps the parts, as before.
 *
 * Plain reads: callers run them before or after the records mutex (attest reads its parts before issue() takes it),
 * and a locking read on the completions index there could block a mutex holder's insert.
 */
final class RetakeVoids
{
    public const PREFIX = 'Reset to take again: ';

    /**
     * The parts that no longer count for (contact, course), or null when the pair has no take-again void:
     * {since_on: local date of the latest take-again void (a part dated before it does not count),
     *  attendee_ids, evaluation_ids, run_ids: the parts the take-again-voided records used}.
     *
     * @return array{since_on:string, attendee_ids:list<int>, evaluation_ids:list<int>, run_ids:list<int>}|null
     */
    public static function excluded(\mysqli $db, int $contactId, int $courseId): ?array
    {
        $rows = Db::all($db, 'SELECT c.completion_tattendee_id, c.completion_evaluation_id, c.completion_run_id, v.cvoid_at_utc
            FROM training_completion_voids v
            JOIN training_completions c ON c.completion_id = v.cvoid_completion_id
            WHERE c.completion_contact_id = ? AND c.completion_course_id = ? AND v.cvoid_reason LIKE ?',
            'iis', [$contactId, $courseId, self::PREFIX . '%']);
        if ($rows === []) {
            return null;
        }
        $out = ['since_on' => '0000-00-00', 'attendee_ids' => [], 'evaluation_ids' => [], 'run_ids' => []];
        foreach ($rows as $r) {
            $out['since_on'] = max($out['since_on'], Clock::localDate((string) $r['cvoid_at_utc']));
            foreach (['completion_tattendee_id' => 'attendee_ids', 'completion_evaluation_id' => 'evaluation_ids', 'completion_run_id' => 'run_ids'] as $col => $k) {
                if ($r[$col] !== null) {
                    $out[$k][(int) $r[$col]] = (int) $r[$col];
                }
            }
        }
        foreach (['attendee_ids', 'evaluation_ids', 'run_ids'] as $k) {
            $out[$k] = array_values($out[$k]);
        }
        return $out;
    }

    /** Was this void reason written by "Reset (take again)"? */
    public static function isRetake(?string $reason): bool
    {
        return $reason !== null && stripos($reason, self::PREFIX) === 0;
    }

    /** "AND <col> NOT IN (?, ...)" for a list of ids (empty: ''), with its bind types and values appended. */
    public static function notIn(string $col, array $ids, string &$types, array &$params): string
    {
        if ($ids === []) {
            return '';
        }
        $types .= str_repeat('i', count($ids));
        array_push($params, ...$ids);
        return ' AND ' . $col . ' NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    }
}
