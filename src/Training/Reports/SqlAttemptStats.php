<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Db;

/**
 * Attempt statistics over the Phase 3 kiosk tables (runs, attempts, results, answers). Used only
 * when AttemptStats::probe() finds every REQUIRED_COLUMNS column (spec §3.6, §1.5: the Phase 3
 * integrator updates the list if its names differ). Exams only (tattempt_kind = 'exam'); an
 * attempt belongs to the course through its revision. Read-only, prepared statements only.
 */
final class SqlAttemptStats implements AttemptStatsProvider
{
    public const REQUIRED_COLUMNS = [   // v0 §4.3 2.6.93 names; the Phase 3 integrator updates these if its spec differs
        'training_runs' => ['trun_id', 'trun_contact_id', 'trun_course_id', 'trun_started_at_utc', 'trun_locked_at_utc'],
        'training_attempts' => ['tattempt_id', 'tattempt_run_id', 'tattempt_contact_id', 'tattempt_revision_id', 'tattempt_number', 'tattempt_kind'],
        'training_attempt_results' => ['tresult_attempt_id', 'tresult_submitted_at_utc', 'tresult_score_pct', 'tresult_passed'],
        'training_attempt_answers' => ['tanswer_attempt_id', 'tanswer_question_uid', 'tanswer_selected', 'tanswer_is_correct'],
    ];

    public function __construct(private readonly \mysqli $db)
    {
    }

    public function available(): bool
    {
        return true;
    }

    public function courseSummary(int $courseId, string $fromUtc, string $toUtc, array $contactIds): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($i) => $i > 0)));
        $out = ['started_people' => 0, 'attempts' => 0, 'attempt_people' => 0, 'passed_people' => 0,
            'first_try_pass' => 0, 'locked_people' => 0, 'score_buckets' => array_fill(0, 10, 0), 'median_score' => null];
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $it = str_repeat('i', count($ids));

        $r = Db::one($this->db, "SELECT COUNT(DISTINCT trun_contact_id) AS started,
                COUNT(DISTINCT CASE WHEN trun_locked_at_utc IS NOT NULL THEN trun_contact_id END) AS locked
            FROM training_runs
            WHERE trun_course_id = ? AND trun_started_at_utc BETWEEN ? AND ? AND trun_contact_id IN ($in)",
            'iss' . $it, array_merge([$courseId, $fromUtc, $toUtc], $ids));
        $out['started_people'] = (int) ($r['started'] ?? 0);
        $out['locked_people'] = (int) ($r['locked'] ?? 0);

        $rows = Db::all($this->db, "SELECT t.tattempt_contact_id AS contact_id, t.tattempt_number AS n,
                res.tresult_score_pct AS score, res.tresult_passed AS passed
            FROM training_attempts t
            JOIN training_attempt_results res ON res.tresult_attempt_id = t.tattempt_id AND res.tresult_submitted_at_utc BETWEEN ? AND ?
            JOIN training_revisions r ON r.revision_id = t.tattempt_revision_id AND r.revision_course_id = ?
            WHERE t.tattempt_kind = 'exam' AND t.tattempt_contact_id IN ($in)",
            'ssi' . $it, array_merge([$fromUtc, $toUtc, $courseId], $ids));
        $people = [];
        $passed = [];
        $firstTry = [];
        $scores = [];
        foreach ($rows as $row) {
            $cid = (int) $row['contact_id'];
            $people[$cid] = true;
            $score = (float) $row['score'];
            $scores[] = $score;
            $out['score_buckets'][min(9, max(0, (int) floor($score / 10)))]++;
            if ((int) $row['passed'] === 1) {
                $passed[$cid] = true;
                if ((int) $row['n'] === 1) {
                    $firstTry[$cid] = true;
                }
            }
        }
        $out['attempts'] = count($rows);
        $out['attempt_people'] = count($people);
        $out['passed_people'] = count($passed);
        $out['first_try_pass'] = count($firstTry);
        $out['median_score'] = self::median($scores);
        return $out;
    }

    public function hardestQuestions(int $courseId, string $fromUtc, string $toUtc, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $rows = Db::all($this->db, "SELECT a.tanswer_question_uid AS q, COUNT(*) AS answered, SUM(a.tanswer_is_correct) AS correct,
                MIN(r.revision_number) AS rev_min, MAX(r.revision_number) AS rev_max
            FROM training_attempt_answers a
            JOIN training_attempts t ON t.tattempt_id = a.tanswer_attempt_id AND t.tattempt_kind = 'exam'
            JOIN training_attempt_results res ON res.tresult_attempt_id = t.tattempt_id AND res.tresult_submitted_at_utc BETWEEN ? AND ?
            JOIN training_revisions r ON r.revision_id = t.tattempt_revision_id AND r.revision_course_id = ?
            GROUP BY a.tanswer_question_uid HAVING answered >= 5 ORDER BY correct / answered ASC, a.tanswer_question_uid LIMIT ?",
            'ssii', [$fromUtc, $toUtc, $courseId, $limit]);
        if ($rows === []) {
            return [];
        }
        $uids = array_map(static fn($r) => (string) $r['q'], $rows);
        $in = implode(',', array_fill(0, count($uids), '?'));
        $wrong = [];
        foreach (Db::all($this->db, "SELECT a.tanswer_question_uid AS q, a.tanswer_selected AS sel, COUNT(*) AS k
                FROM training_attempt_answers a
                JOIN training_attempts t ON t.tattempt_id = a.tanswer_attempt_id AND t.tattempt_kind = 'exam'
                JOIN training_attempt_results res ON res.tresult_attempt_id = t.tattempt_id AND res.tresult_submitted_at_utc BETWEEN ? AND ?
                JOIN training_revisions r ON r.revision_id = t.tattempt_revision_id AND r.revision_course_id = ?
                WHERE a.tanswer_is_correct = 0 AND a.tanswer_question_uid IN ($in)
                GROUP BY a.tanswer_question_uid, a.tanswer_selected
                ORDER BY k DESC, sel", 'ssi' . str_repeat('s', count($uids)), array_merge([$fromUtc, $toUtc, $courseId], $uids)) as $w) {
            $q = (string) $w['q'];
            if (!isset($wrong[$q])) {
                $wrong[$q] = ['selected' => (string) $w['sel'], 'count' => (int) $w['k']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $q = (string) $r['q'];
            $out[] = [
                'question_uid' => $q,
                'answered' => (int) $r['answered'],
                'correct' => (int) $r['correct'],
                'top_wrong' => $wrong[$q] ?? null,
                'revision_min' => (int) $r['rev_min'],
                'revision_max' => (int) $r['rev_max'],
            ];
        }
        return $out;
    }

    /** Median of a list of scores as a '%.2f' string (null for an empty list). */
    public static function median(array $values): ?string
    {
        $n = count($values);
        if ($n === 0) {
            return null;
        }
        sort($values, SORT_NUMERIC);
        $mid = intdiv($n, 2);
        $m = $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
        return sprintf('%.2f', $m);
    }
}
