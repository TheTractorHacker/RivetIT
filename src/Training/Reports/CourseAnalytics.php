<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;

/**
 * Course analytics (S8, mockup Admin-CourseAnalytics) for one course over a local date range.
 *
 * Every count is restricted to in-scope people (spec §8). Before Phase 3 there are no quiz
 * attempts, so (spec §3.6):
 *   funnel      Assigned (people with an assignment created in the period) -> Started ("—")
 *               -> Completed (those people with a completion on/after their assignment, in the
 *               period) -> Passed (score at least the pass mark, or no score);
 *   pass rate   completions with a score >= pass mark / completions with a score;
 *   avg score   completion_score_pct; first-try pass: completion_attempts_used = 1;
 *   median time completion_duration_minutes;
 *   scores      completion scores in 10 buckets.
 * With the Phase 3 provider the attempt numbers replace the completion-based ones.
 */
final class CourseAnalytics
{
    public function __construct(private readonly Ctx $c, private readonly ?RecordsSettings $settings = null)
    {
    }

    /** @return array<string, mixed> */
    public function build(int $courseId, string $fromOn, string $toOn, Scope $s): array
    {
        if (!Clock::isYmd($fromOn) || !Clock::isYmd($toOn) || $fromOn > $toOn) {
            throw ApiException::validation(['from' => 'Choose a valid period.']);
        }
        $db = $this->c->db;
        $settings = $this->settings ?? RecordsSettings::fromDb($db);
        $course = Lookup::course($db, $courseId, true);
        if ($course === null) {
            throw new ApiException(404, 'not_found', 'That course was not found.');
        }
        $passMark = $course['pass_pct'];
        $days = Labels::days($fromOn, $toOn) + 1;
        $prevTo = Clock::addDays($fromOn, -1);
        $prevFrom = Clock::addDays($prevTo, -($days - 1));

        $cur = $this->period($courseId, $fromOn, $toOn, $s, $passMark);
        $prev = $this->period($courseId, $prevFrom, $prevTo, $s, $passMark);

        $provider = AttemptStats::provider($db);
        $attempts = null;
        $hardest = [];
        if ($provider->available() && !$s->isNone()) {
            [$fromUtc, $toUtc] = self::utcRange($fromOn, $toOn);
            $attempts = $provider->courseSummary($courseId, $fromUtc, $toUtc, $this->scopePeople($s));
            $hardest = $this->resolveQuestions($provider->hardestQuestions($courseId, $fromUtc, $toUtc, 5), $course['json']);
        }

        // Funnel ------------------------------------------------------------------------------
        $assigned = count($cur['assigned']);
        $started = $attempts !== null ? min($assigned, (int) $attempts['started_people']) : null;
        $funnel = [
            ['key' => 'assigned', 'label' => 'Assigned', 'count' => $assigned],
            ['key' => 'started', 'label' => 'Started', 'count' => $started],
            ['key' => 'completed', 'label' => 'Completed', 'count' => $cur['funnel_completed']],
            ['key' => 'passed', 'label' => 'Passed', 'count' => $cur['funnel_passed']],
        ];
        foreach ($funnel as &$step) {
            $step['pct'] = $step['count'] === null ? null : Labels::pct((int) $step['count'], $assigned);
        }
        unset($step);
        $drops = [];
        $prevCount = $assigned;
        foreach (array_slice($funnel, 1) as $step) {
            if ($step['count'] === null) {
                continue;
            }
            $drops[] = ['to' => $step['key'], 'lost' => max(0, $prevCount - (int) $step['count']),
                'kept_pct' => Labels::pct((int) $step['count'], $prevCount)];
            $prevCount = (int) $step['count'];
        }

        // KPIs ------------------------------------------------------------------------------
        $kpi = static function (array $p): array {
            return [
                'pass_rate' => Labels::pct($p['passed_scored'], $p['scored']),
                'avg_score' => $p['avg_score'],
                'first_try' => Labels::pct($p['first_try'], $p['with_attempts']),
                'median_minutes' => $p['median_minutes'],
            ];
        };
        $k = $kpi($cur);
        $kp = $kpi($prev);
        $delta = static fn(?int $a, ?int $b) => ($a !== null && $b !== null) ? $a - $b : null;

        // Score distribution --------------------------------------------------------------------
        $buckets = $attempts !== null ? $attempts['score_buckets'] : $cur['buckets'];
        $basis = $attempts !== null ? 'attempts' : 'completions';

        // By department (required / current from ComplianceService; scores from the period) --------
        $pairs = (new PairSource($this->c, $s, $settings))->pairs(['course_id' => $courseId]);
        $byDept = [];
        foreach ($pairs as $p) {
            if (!$p['required'] || $p['waived']) {
                continue;
            }
            $d = $p['client_id'];
            $byDept[$d] ??= ['client_id' => $d, 'required' => 0, 'current' => 0, 'score_sum' => 0.0, 'score_n' => 0];
            $byDept[$d]['required']++;
            if ($p['counts_current']) {
                $byDept[$d]['current']++;
            }
        }
        foreach ($cur['scores_by_dept'] as $d => [$sum, $n]) {
            $byDept[$d] ??= ['client_id' => $d, 'required' => 0, 'current' => 0, 'score_sum' => 0.0, 'score_n' => 0];
            $byDept[$d]['score_sum'] += $sum;
            $byDept[$d]['score_n'] += $n;
        }
        $names = Lookup::departmentNames($db, array_keys($byDept));
        $deptRows = [];
        $tot = ['required' => 0, 'current' => 0, 'score_sum' => 0.0, 'score_n' => 0];
        foreach ($byDept as $d => $r) {
            $pct = Labels::pct($r['current'], $r['required']);
            $deptRows[] = [
                'client_id' => $d,
                'name' => $names[$d] ?? ('Department #' . $d),
                'required' => $r['required'],
                'current' => $r['current'],
                'pct' => $pct,
                'avg_score' => $r['score_n'] > 0 ? (int) round($r['score_sum'] / $r['score_n']) : null,
                'behind' => $pct !== null && $pct < $settings->targetPct,
            ];
            foreach (['required', 'current', 'score_sum', 'score_n'] as $key) {
                $tot[$key] += $r[$key];
            }
        }
        usort($deptRows, static fn($a, $b) => [$b['required'], $a['name']] <=> [$a['required'], $b['name']]);

        $scored = $attempts !== null ? (int) $attempts['attempts'] : $cur['scored'];
        $below = 0;
        foreach ($buckets as $i => $n) {
            if ($passMark !== null && ($i + 1) * 10 <= $passMark) {
                $below += $n;
            }
        }
        return [
            'course' => [
                'id' => $course['id'], 'name' => $course['name'], 'code' => $course['code'], 'kind' => $course['kind'],
                'revision_number' => $course['revision_number'], 'validity_months' => $course['validity_months'],
                'validity_label' => Labels::validity($course['validity_months']), 'pass_pct' => $passMark,
                'est_minutes' => $course['est_minutes'], 'archived' => $course['archived'],
            ],
            'period' => ['from' => $fromOn, 'to' => $toOn, 'days' => $days, 'prev_from' => $prevFrom, 'prev_to' => $prevTo],
            'attempts_available' => $attempts !== null,
            'funnel' => $funnel,
            'drops' => $drops,
            'kpis' => [
                'pass_rate' => $k['pass_rate'], 'pass_rate_delta' => $delta($k['pass_rate'], $kp['pass_rate']),
                'passed' => $cur['passed_scored'], 'scored' => $cur['scored'],
                'avg_score' => $k['avg_score'], 'avg_score_delta' => $delta($k['avg_score'], $kp['avg_score']),
                'first_try' => $k['first_try'], 'first_try_delta' => $delta($k['first_try'], $kp['first_try']),
                'first_try_count' => $cur['first_try'], 'with_attempts' => $cur['with_attempts'],
                'median_minutes' => $k['median_minutes'], 'median_minutes_delta' => $delta($k['median_minutes'], $kp['median_minutes']),
                'completed' => $cur['completed'],
            ],
            'scores' => [
                'basis' => $basis,
                'buckets' => array_values(array_map('intval', $buckets)),
                'pass_mark' => $passMark,
                'total' => $scored,
                'people' => $attempts !== null ? (int) $attempts['attempt_people'] : $cur['scored'],
                'median' => $attempts !== null ? Labels::score($attempts['median_score']) : Labels::score($cur['median_score']),
                'below_pass' => $passMark !== null ? $below : null,
                'retakes' => $cur['retakes'],
                'locked' => $attempts !== null ? (int) $attempts['locked_people'] : null,
            ],
            'by_department' => $deptRows,
            'by_department_total' => [
                'required' => $tot['required'], 'current' => $tot['current'], 'pct' => Labels::pct($tot['current'], $tot['required']),
                'avg_score' => $tot['score_n'] > 0 ? (int) round($tot['score_sum'] / $tot['score_n']) : null,
            ],
            'target' => $settings->targetPct,
            'hardest' => $hardest,
        ];
    }

    /** Local [from 00:00, to 23:59:59.999] as UTC 'Y-m-d H:i:s.v'. @return array{0:string,1:string} */
    public static function utcRange(string $fromOn, string $toOn): array
    {
        $tz = new \DateTimeZone(date_default_timezone_get());
        $utc = new \DateTimeZone('UTC');
        $a = (new \DateTimeImmutable($fromOn . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.v');
        $b = (new \DateTimeImmutable($toOn . ' 23:59:59.999', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.v');
        return [$a, $b];
    }

    /** @return list<int> in-scope contact ids (eligible or not: attempts may predate a roster change) */
    private function scopePeople(Scope $s): array
    {
        [$sql, $types, $params] = $s->sqlIn('contact_client_id');
        $rows = Db::all($this->c->db, 'SELECT contact_id FROM contacts WHERE 1=1' . $sql, $types, $params);
        return array_map(static fn($r) => (int) $r['contact_id'], $rows);
    }

    /** Numbers for one period, in scope. @return array<string, mixed> */
    private function period(int $courseId, string $fromOn, string $toOn, Scope $s, ?int $passMark): array
    {
        $out = ['assigned' => [], 'funnel_completed' => 0, 'funnel_passed' => 0, 'completed' => 0, 'scored' => 0,
            'passed_scored' => 0, 'avg_score' => null, 'first_try' => 0, 'with_attempts' => 0, 'median_minutes' => null,
            'buckets' => array_fill(0, 10, 0), 'median_score' => null, 'retakes' => 0, 'scores_by_dept' => []];
        if ($s->isNone()) {
            return $out;
        }
        $db = $this->c->db;
        [$scopeSql, $scopeTypes, $scopeParams] = $s->sqlIn('c.contact_client_id');
        [$fromUtc, $toUtc] = self::utcRange($fromOn, $toOn);

        foreach (Db::all($db, "SELECT a.tassign_contact_id, MIN(a.tassign_created_at_utc) AS first_at
                FROM training_assignments a JOIN contacts c ON c.contact_id = a.tassign_contact_id
                WHERE a.tassign_course_id = ? AND a.tassign_created_at_utc BETWEEN ? AND ?" . $scopeSql . '
                GROUP BY a.tassign_contact_id', 'iss' . $scopeTypes, array_merge([$courseId, $fromUtc, $toUtc], $scopeParams)) as $r) {
            $out['assigned'][(int) $r['tassign_contact_id']] = Clock::localDate((string) $r['first_at']);
        }

        $rows = Db::all($db, "SELECT tc.completion_contact_id, tc.completion_completed_on, tc.completion_score_pct,
                tc.completion_pass_mark_pct, tc.completion_attempts_used, tc.completion_duration_minutes, c.contact_client_id
            FROM training_completions tc JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_course_id = ? AND tc.completion_completed_on BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)" . $scopeSql . '
            ORDER BY tc.completion_completed_on, tc.completion_id', 'iss' . $scopeTypes, array_merge([$courseId, $fromOn, $toOn], $scopeParams));
        $scores = [];
        $minutes = [];
        $funnelDone = [];
        $funnelPassed = [];
        foreach ($rows as $r) {
            $cid = (int) $r['completion_contact_id'];
            $score = $r['completion_score_pct'] !== null ? (float) $r['completion_score_pct'] : null;
            $pm = $r['completion_pass_mark_pct'] !== null ? (int) $r['completion_pass_mark_pct'] : $passMark;
            $passed = $score === null || $pm === null || $score >= $pm;
            $out['completed']++;
            if ($score !== null) {
                $out['scored']++;
                $scores[] = $score;
                $out['buckets'][min(9, max(0, (int) floor($score / 10)))]++;
                if ($passed) {
                    $out['passed_scored']++;
                }
                $d = (int) $r['contact_client_id'];
                $out['scores_by_dept'][$d] ??= [0.0, 0];
                $out['scores_by_dept'][$d][0] += $score;
                $out['scores_by_dept'][$d][1]++;
            }
            if ($r['completion_attempts_used'] !== null) {
                $out['with_attempts']++;
                if ((int) $r['completion_attempts_used'] === 1) {
                    $out['first_try']++;
                } elseif ((int) $r['completion_attempts_used'] > 1) {
                    $out['retakes']++;
                }
            }
            if ($r['completion_duration_minutes'] !== null) {
                $minutes[] = (int) $r['completion_duration_minutes'];
            }
            if (isset($out['assigned'][$cid]) && (string) $r['completion_completed_on'] >= $out['assigned'][$cid]) {
                $funnelDone[$cid] = true;
                if ($passed) {
                    $funnelPassed[$cid] = true;
                }
            }
        }
        $out['funnel_completed'] = count($funnelDone);
        $out['funnel_passed'] = count($funnelPassed);
        $out['avg_score'] = $scores !== [] ? (int) round(array_sum($scores) / count($scores)) : null;
        $out['median_score'] = SqlAttemptStats::median($scores);
        $med = SqlAttemptStats::median($minutes);
        $out['median_minutes'] = $med !== null ? (int) round((float) $med) : null;
        return $out;
    }

    /**
     * Adds question text and the most-chosen wrong option's label from the current revision JSON
     * (questions[uid].text[lang].q; options[].text[lang].label), English first.
     */
    private function resolveQuestions(array $rows, ?array $json): array
    {
        $questions = is_array($json['questions'] ?? null) ? $json['questions'] : [];
        $lang = (string) ($json['course']['default_language'] ?? 'en');
        $text = static function (?array $t) use ($lang): ?string {
            if (!is_array($t)) {
                return null;
            }
            foreach ([$lang, 'en'] as $l) {
                if (isset($t[$l]) && is_array($t[$l])) {
                    return $t[$l]['q'] ?? $t[$l]['label'] ?? null;
                }
            }
            $first = reset($t);
            return is_array($first) ? ($first['q'] ?? $first['label'] ?? null) : null;
        };
        $out = [];
        foreach ($rows as $r) {
            $q = $questions[$r['question_uid']] ?? null;
            $wrongLabel = null;
            if ($r['top_wrong'] !== null && is_array($q['options'] ?? null)) {
                $labels = [];
                foreach (explode(',', $r['top_wrong']['selected']) as $uid) {
                    foreach ($q['options'] as $opt) {
                        if (($opt['uid'] ?? null) === trim($uid)) {
                            $labels[] = (string) $text($opt['text'] ?? null);
                        }
                    }
                }
                $wrongLabel = $labels !== [] ? implode(' + ', array_filter($labels)) : null;
            }
            $out[] = $r + [
                'text' => $q !== null ? $text($q['text'] ?? null) : null,
                'correct_pct' => Labels::pct((int) $r['correct'], (int) $r['answered']),
                'top_wrong_label' => $wrongLabel,
                'top_wrong_pct' => $r['top_wrong'] !== null ? Labels::pct((int) $r['top_wrong']['count'], (int) $r['answered']) : null,
            ];
        }
        return $out;
    }
}
