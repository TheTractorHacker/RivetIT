<?php

namespace ITFlow\Training\Compliance;

use ITFlow\Training\Assign\AssignmentStore;
use ITFlow\Training\Assign\CourseCards;
use ITFlow\Training\Assign\RecordFacts;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;

/**
 * What one learner has to do and has done - the Phase 3 kiosk contract (Phase 2 spec §3.3).
 * UNSCOPED: the kiosk session has already resolved (and authenticated) the contact. Read-only.
 *
 * {required:[Item], optional:[Item],
 *  completed:[{completion_id, course_id, course_name, kind, completed_on, score_pct, passed}],
 *  certificates:[{completion_id, course_id, course_name, cert_number, completed_on, expires_on, status}],
 *  counts:{required, overdue, completed, documents_to_sign}}
 * Item = {course_id, course_name, kind, assignment_id, due_on,
 *         status:'overdue'|'due_soon'|'due'|'expiring'|'retrain_due'|'expired', anchor, reason_label}
 * Lists are open assignments, most urgent first; completed is newest first (at most COMPLETED_MAX);
 * certificates are the latest non-voided training record per course that carries a number.
 */
final class LearnerSummary
{
    public const COMPLETED_MAX = 50;
    private const URGENCY = ['overdue' => 0, 'expired' => 1, 'due_soon' => 2, 'retrain_due' => 3, 'expiring' => 4, 'due' => 5];

    public static function forContact(\mysqli $db, int $contactId, RecordsSettings $s, ?string $today = null): array
    {
        $today ??= Clock::todayLocal();
        $open = array_map([AssignmentStore::class, 'normalize'], Db::all($db, 'SELECT ' . AssignmentStore::COLUMNS . "
            FROM training_assignments WHERE tassign_contact_id = ? AND tassign_status = 'open' ORDER BY tassign_due_on, tassign_id", 'i', [$contactId]));
        $completions = Db::all($db, 'SELECT t.completion_id, t.completion_course_id, t.completion_course_kind, t.completion_completed_on,
                t.completion_expires_on, t.completion_score_pct, t.completion_pass_mark_pct, t.completion_cert_number,
                t.completion_snap_course_name, t.completion_snap_revision_number, t.completion_supersedes_id
            FROM training_completions t
            WHERE t.completion_contact_id = ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = t.completion_id)
            ORDER BY t.completion_completed_on DESC, t.completion_id DESC', 'i', [$contactId]);

        $courseIds = array_merge(array_map(static fn($a) => $a['course_id'], $open),
            array_map(static fn($r) => (int) $r['completion_course_id'], $completions));
        $courses = CourseCards::load($db, $courseIds);
        $facts = $open === [] ? [] : (RecordFacts::load($db, [$contactId], array_map(static fn($a) => $a['course_id'], $open), false,
            self::floors($contactId, $open), $s, $today)[$contactId] ?? []);
        $rr = RecordFacts::retrainRevisions($db, $courseIds);
        $labels = self::anchorLabels($db, $open);

        $required = [];
        $optional = [];
        foreach ($open as $a) {
            $k = $a['course_id'];
            $st = PairRules::pairStatus(null, $a, $facts[$k] ?? RecordFacts::none(), $today, $s);
            $item = [
                'course_id' => $k,
                'course_name' => $courses[$k]['name'] ?? ('Course #' . $k),
                'kind' => $courses[$k]['kind'] ?? 'training',
                'assignment_id' => $a['id'],
                'due_on' => $a['due_on'],
                'status' => $st['status'],
                'anchor' => $a['anchor'],
                'reason_label' => $labels[$a['id']],
            ];
            if ($a['required']) {
                $required[] = $item;
            } else {
                $optional[] = $item;
            }
        }
        $sort = static function (array &$list): void {
            usort($list, static fn($x, $y) => [self::URGENCY[$x['status']] ?? 9, $x['due_on'], $x['course_name']]
                                               <=> [self::URGENCY[$y['status']] ?? 9, $y['due_on'], $y['course_name']]);
        };
        $sort($required);
        $sort($optional);

        $completed = [];
        $certs = [];
        foreach ($completions as $r) {
            $k = (int) $r['completion_course_id'];
            $name = $courses[$k]['name'] ?? (string) $r['completion_snap_course_name'];
            $score = $r['completion_score_pct'] === null ? null : (string) $r['completion_score_pct'];
            $mark = $r['completion_pass_mark_pct'] === null ? null : (int) $r['completion_pass_mark_pct'];
            if (count($completed) < self::COMPLETED_MAX) {
                $completed[] = [
                    'completion_id' => (int) $r['completion_id'],
                    'course_id' => $k,
                    'course_name' => $name,
                    'kind' => (string) $r['completion_course_kind'],
                    'completed_on' => (string) $r['completion_completed_on'],
                    'score_pct' => $score,
                    'passed' => $score === null || $mark === null || (float) $score >= $mark,
                ];
            }
            if ($r['completion_cert_number'] !== null && !isset($certs[$k])) {   // newest first: the first per course is the latest
                $c = [
                    'completion_id' => (int) $r['completion_id'],
                    'completed_on' => (string) $r['completion_completed_on'],
                    'expires_on' => $r['completion_expires_on'] === null ? null : (string) $r['completion_expires_on'],
                    'revision_number' => $r['completion_snap_revision_number'] === null ? null : (int) $r['completion_snap_revision_number'],
                    'supersedes_id' => null,
                    'voided_at_utc' => null,
                ];
                $certs[$k] = [
                    'completion_id' => $c['completion_id'],
                    'course_id' => $k,
                    'course_name' => $name,
                    'cert_number' => (string) $r['completion_cert_number'],
                    'completed_on' => $c['completed_on'],
                    'expires_on' => $c['expires_on'],
                    'status' => PairRules::certStatus($c, $rr[$k]['latest'] ?? null, $today, $s->dueSoonDays)['status'],
                ];
            }
        }
        $certs = array_values($certs);
        usort($certs, static fn($x, $y) => strcasecmp($x['course_name'], $y['course_name']));

        $docs = 0;
        foreach (array_merge($required, $optional) as $i) {
            if ($i['kind'] === 'document') {
                $docs++;
            }
        }
        return [
            'required' => $required,
            'optional' => $optional,
            'completed' => $completed,
            'certificates' => $certs,
            'counts' => [
                'required' => count($required),
                'overdue' => count(array_filter($required, static fn($i) => $i['status'] === 'overdue')),
                'completed' => count($completions),
                'documents_to_sign' => $docs,
            ],
        ];
    }

    /** "Required by {rule}" | "Renewal · expires {date}" | "Retrain · Version {n}" | "Record voided · redo", per assignment id. */
    public static function anchorLabels(\mysqli $db, array $assignments): array
    {
        $req = [];
        $comp = [];
        $rev = [];
        foreach ($assignments as $a) {
            $p = PairRules::parseAnchor($a['anchor']);
            if ($p['kind'] === 'renew') {
                $comp[$p['id']] = $p['id'];
            } elseif ($p['kind'] === 'retrain') {
                $rev[$p['id']] = $p['id'];
            } elseif ($p['kind'] === 'initial' && $a['requirement_id'] !== null) {
                $req[$a['requirement_id']] = $a['requirement_id'];
            }
        }
        $in = static fn(array $ids) => implode(',', array_fill(0, count($ids), '?'));
        $names = [];
        if ($req !== []) {
            foreach (Db::all($db, 'SELECT requirement_id, requirement_name FROM training_requirements WHERE requirement_id IN (' . $in($req) . ')',
                str_repeat('i', count($req)), array_values($req)) as $r) {
                $names[(int) $r['requirement_id']] = (string) $r['requirement_name'];
            }
        }
        $exp = [];
        if ($comp !== []) {
            foreach (Db::all($db, 'SELECT completion_id, completion_expires_on FROM training_completions WHERE completion_id IN (' . $in($comp) . ')',
                str_repeat('i', count($comp)), array_values($comp)) as $r) {
                $exp[(int) $r['completion_id']] = $r['completion_expires_on'];
            }
        }
        $num = [];
        if ($rev !== []) {
            foreach (Db::all($db, 'SELECT revision_id, revision_number FROM training_revisions WHERE revision_id IN (' . $in($rev) . ')',
                str_repeat('i', count($rev)), array_values($rev)) as $r) {
                $num[(int) $r['revision_id']] = (int) $r['revision_number'];
            }
        }
        $out = [];
        foreach ($assignments as $a) {
            $p = PairRules::parseAnchor($a['anchor']);
            $out[$a['id']] = match ($p['kind']) {
                'renew' => 'Renewal · expires ' . ($exp[$p['id']] ?? '?'),
                'retrain' => 'Retrain · Version ' . ($num[$p['id']] ?? '?'),
                'reissue' => 'Record voided · redo',
                default => 'Required by ' . ($a['requirement_id'] !== null ? ($names[$a['requirement_id']] ?? 'a rule') : 'a rule'),
            };
        }
        return $out;
    }

    /** Onboarding floors of the open assignments: [contact][course] => date. */
    private static function floors(int $contactId, array $open): ?array
    {
        $out = [];
        foreach ($open as $a) {
            if ($a['onboarding_from_on'] !== null) {
                $out[$contactId][$a['course_id']] = $a['onboarding_from_on'];
            }
        }
        return $out === [] ? null : $out;
    }
}
