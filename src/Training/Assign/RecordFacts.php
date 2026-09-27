<?php

namespace ITFlow\Training\Assign;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;

/**
 * The facts PairRules decides on, per (contact, course) pair (Phase 2 spec §3.3). Read-only on
 * Lane C's record tables and on training_assignments.
 *
 * Per pair, facts f = {
 *   latest:        the latest NON-voided completion by (completed_on, completion_id), counting only
 *                  completed_on >= onboarding_since when that is set (expired records included),
 *   latestVoided:  the latest voided completion (same floor), only when newer than latest,
 *   waiver:        the latest `waived` assignment with waived_until NULL or >= today, or null,
 *   rr:            the course's latest requires_retraining revision (PairRules RR shape), or null,
 *   recentCancelled: cancelled assignments closed 'no_longer_required'/'contact_ineligible' within the
 *                  reopen window (local close date >= today - reopenWindowDays), newest first,
 *   anchorRefs:    {c: {completion_id: completion}, r: {revision_id: RR}} - every completion of the pair
 *                  (voided and pre-floor ones included) and every retrain revision of the course, so
 *                  PairRules::satisfies can resolve any anchor,
 * }
 * Completion shape: {completion_id, completed_on, expires_on, revision_number, supersedes_id, voided_at_utc,
 *                    cert_number, course_kind, score_pct}.
 *
 * $locking = true (reconcile chunks and CompletionService::issue(), both already holding the records
 * mutex) reads completions, voids and assignments with LOCK IN SHARE MODE: a locking read always sees
 * the latest committed rows, so a chunk never decides on a REPEATABLE-READ snapshot older than the
 * mutex. Revisions are insert-only and read plainly.
 */
final class RecordFacts
{
    private const CLOSE_REOPENABLE = ['no_longer_required', 'contact_ineligible'];

    /**
     * @param list<int> $contactIds
     * @param list<int> $courseIds
     * @param array<int, array<int, string>>|null $onboardingSince [contact_id][course_id] => 'Y-m-d'
     * @return array<int, array<int, array>> [contact_id][course_id] => facts, for EVERY requested pair
     */
    public static function load(\mysqli $db, array $contactIds, array $courseIds, bool $locking, ?array $onboardingSince = null,
                                ?RecordsSettings $s = null, ?string $today = null): array
    {
        $contactIds = self::ids($contactIds);
        $courseIds = self::ids($courseIds);
        if ($contactIds === [] || $courseIds === []) {
            return [];
        }
        $today ??= Clock::todayLocal();
        $s ??= RecordsSettings::fromDb($db);
        $lock = $locking ? ' LOCK IN SHARE MODE' : '';
        $cIn = implode(',', array_fill(0, count($contactIds), '?'));
        $kIn = implode(',', array_fill(0, count($courseIds), '?'));
        $types = str_repeat('i', count($contactIds) + count($courseIds));
        $params = array_merge($contactIds, $courseIds);

        $rrByCourse = self::retrainRevisions($db, $courseIds);

        // Completions (+ their void, if any) of the requested pairs.
        $rows = Db::all($db, "SELECT c.completion_id, c.completion_contact_id, c.completion_course_id, c.completion_course_kind,
                c.completion_completed_on, c.completion_expires_on, c.completion_snap_revision_number, c.completion_supersedes_id,
                c.completion_cert_number, c.completion_score_pct, v.cvoid_at_utc
            FROM training_completions c
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            WHERE c.completion_contact_id IN ($cIn) AND c.completion_course_id IN ($kIn)
            ORDER BY c.completion_contact_id, c.completion_course_id, c.completion_completed_on, c.completion_id$lock", $types, $params);
        $completions = [];
        foreach ($rows as $r) {
            $completions[(int) $r['completion_contact_id']][(int) $r['completion_course_id']][] = self::completion($r);
        }

        // Waivers and reopenable cancellations.
        $rows = Db::all($db, "SELECT tassign_id, tassign_contact_id, tassign_course_id, tassign_status, tassign_anchor, tassign_reason,
                tassign_requirement_id, tassign_required, tassign_due_on, tassign_original_due_on, tassign_onboarding_from_on,
                tassign_waived_until, tassign_completion_id, tassign_created_at_utc, tassign_closed_at_utc, tassign_close_reason,
                tassign_close_note, tassign_reopened_count
            FROM training_assignments
            WHERE tassign_contact_id IN ($cIn) AND tassign_course_id IN ($kIn) AND tassign_status IN ('waived', 'cancelled')
            ORDER BY tassign_id DESC$lock", $types, $params);
        $windowFrom = Clock::addDays($today, -$s->reopenWindowDays);
        $waivers = [];
        $cancelled = [];
        foreach ($rows as $r) {
            $cid = (int) $r['tassign_contact_id'];
            $kid = (int) $r['tassign_course_id'];
            $a = AssignmentStore::normalize($r);
            if ($a['status'] === 'waived') {
                if (!isset($waivers[$cid][$kid]) && ($a['waived_until'] === null || $a['waived_until'] >= $today)) {
                    $waivers[$cid][$kid] = $a;
                }
                continue;
            }
            if (in_array($a['close_reason'], self::CLOSE_REOPENABLE, true) && $a['closed_at_utc'] !== null
                && Clock::localDate($a['closed_at_utc']) >= $windowFrom) {
                $cancelled[$cid][$kid][] = $a;
            }
        }

        $out = [];
        foreach ($contactIds as $cid) {
            foreach ($courseIds as $kid) {
                $floor = $onboardingSince[$cid][$kid] ?? null;
                $out[$cid][$kid] = self::build($completions[$cid][$kid] ?? [], $floor, $rrByCourse[$kid] ?? null,
                    $waivers[$cid][$kid] ?? null, $cancelled[$cid][$kid] ?? []);
            }
        }
        return $out;
    }

    /** Facts for one pair (CompletionService::issue passes the open assignment's onboarding floor). */
    public static function forPair(\mysqli $db, int $contactId, int $courseId, ?string $onboardingSince, bool $locking,
                                   ?RecordsSettings $s = null, ?string $today = null): array
    {
        $since = $onboardingSince === null ? null : [$contactId => [$courseId => $onboardingSince]];
        return self::load($db, [$contactId], [$courseId], $locking, $since, $s, $today)[$contactId][$courseId];
    }

    /** Empty facts for a pair nobody has records for (course's RR still applies). */
    public static function none(?array $rr = null): array
    {
        return self::build([], null, $rr, null, []);
    }

    /**
     * The latest requires_retraining revision per course plus all of them by id:
     * [course_id => ['latest' => RR, 'all' => [revision_id => RR]]]
     */
    public static function retrainRevisions(\mysqli $db, array $courseIds): array
    {
        $courseIds = self::ids($courseIds);
        if ($courseIds === []) {
            return [];
        }
        $rows = Db::all($db, 'SELECT revision_id, revision_course_id, revision_number, revision_published_at_utc, revision_retrain_due_days
            FROM training_revisions
            WHERE revision_requires_retraining = 1 AND revision_course_id IN (' . implode(',', array_fill(0, count($courseIds), '?')) . ')
            ORDER BY revision_course_id, revision_number', str_repeat('i', count($courseIds)), $courseIds);
        $out = [];
        foreach ($rows as $r) {
            $rr = [
                'revision_id' => (int) $r['revision_id'],
                'revision_number' => (int) $r['revision_number'],
                'published_on' => Clock::localDate((string) $r['revision_published_at_utc']),
                'retrain_due_days' => (int) ($r['revision_retrain_due_days'] ?? 0),
            ];
            $k = (int) $r['revision_course_id'];
            $out[$k]['all'][$rr['revision_id']] = $rr;
            $out[$k]['latest'] = $rr;   // ascending revision_number: the last one wins
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The facts f as they will read once completion $completionId is voided at $voidedAtUtc: latest and latestVoided
     * re-derived from anchorRefs by the same rule as load() (the same onboarding floor $floor; a voided record counts
     * only when newer than the latest valid one), so a caller can predict the reconcile that follows a void
     * (Assign\AssignmentReset "take again"). Waiver, rr and recentCancelled are unchanged.
     */
    public static function withVoided(array $f, int $completionId, string $voidedAtUtc, ?string $floor): array
    {
        $refs = $f['anchorRefs']['c'] ?? [];
        if (isset($refs[$completionId]) && $refs[$completionId]['voided_at_utc'] === null) {
            $refs[$completionId]['voided_at_utc'] = $voidedAtUtc;
        }
        [$f['latest'], $f['latestVoided']] = self::pick($refs, $floor);
        $f['anchorRefs']['c'] = $refs;
        return $f;
    }

    private static function build(array $list, ?string $floor, ?array $rrInfo, ?array $waiver, array $cancelled): array
    {
        $refs = [];
        foreach ($list as $c) {             // ascending (completed_on, completion_id)
            $refs[$c['completion_id']] = $c;
        }
        [$latest, $latestVoided] = self::pick($refs, $floor);
        return [
            'latest' => $latest,
            'latestVoided' => $latestVoided,
            'waiver' => $waiver,
            'rr' => $rrInfo['latest'] ?? null,
            'recentCancelled' => $cancelled,
            'anchorRefs' => ['c' => $refs, 'r' => $rrInfo['all'] ?? []],
        ];
    }

    /**
     * [latest, latestVoided] of a pair's completions (ascending (completed_on, completion_id), keyed by id): pre-floor
     * ones do not count; latestVoided only when newer than latest.
     */
    private static function pick(array $refs, ?string $floor): array
    {
        $latest = null;
        $latestVoided = null;
        foreach ($refs as $c) {
            if ($floor !== null && $c['completed_on'] < $floor) {
                continue;
            }
            if ($c['voided_at_utc'] === null) {
                $latest = $c;
            } else {
                $latestVoided = $c;
            }
        }
        if ($latestVoided !== null && $latest !== null && self::key($latestVoided) <= self::key($latest)) {
            $latestVoided = null;
        }
        return [$latest, $latestVoided];
    }

    private static function key(array $c): string
    {
        return $c['completed_on'] . sprintf('#%012d', (int) $c['completion_id']);
    }

    private static function completion(array $r): array
    {
        return [
            'completion_id' => (int) $r['completion_id'],
            'completed_on' => (string) $r['completion_completed_on'],
            'expires_on' => $r['completion_expires_on'] === null ? null : (string) $r['completion_expires_on'],
            'revision_number' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
            'supersedes_id' => $r['completion_supersedes_id'] === null ? null : (int) $r['completion_supersedes_id'],
            'voided_at_utc' => $r['cvoid_at_utc'] === null ? null : (string) $r['cvoid_at_utc'],
            'cert_number' => $r['completion_cert_number'],
            'course_kind' => (string) $r['completion_course_kind'],
            'score_pct' => $r['completion_score_pct'] === null ? null : (string) $r['completion_score_pct'],
        ];
    }

    /** @return list<int> positive, unique, ascending */
    private static function ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        ksort($out);
        return array_values($out);
    }
}
