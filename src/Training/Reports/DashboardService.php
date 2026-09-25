<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\People\Scope;

/**
 * Training overview (M11, mockup Admin-TrainingDashboard). Returns the Dashboard shape of
 * spec §4.1. Pair statuses come from ComplianceService (via PairSource); record-based numbers
 * (completions, scores, expiring) are read from the records tables with the same scope and
 * eligibility rules. Services never authorize: the page / action has already run
 * Access::pageGuard / Access::api and built the Scope.
 */
final class DashboardService
{
    public const PERIODS = [3, 6, 12];
    public const HEATMAP_COURSES = 8;
    public const RECONCILE_EVERY_S = 600;

    public function __construct(
        private readonly Ctx $c,
        private readonly Scope $scope,
        private readonly RecordsSettings $settings,
    ) {
    }

    /**
     * S16: when the last full reconcile is more than 10 minutes old, run one now (all people,
     * trigger 'dashboard', lock wait 0 s so a busy run is simply skipped; system actor). Any
     * failure is logged and the dashboard renders from what is there. Returns the reconcile
     * stats, or null when it did not run.
     */
    public static function maybeReconcile(\mysqli $db, RecordsSettings $s): ?array
    {
        if (!$s->schemaReady || Db::depth() !== 0 || !class_exists(\ITFlow\Training\Assign\AssignmentService::class)) {
            return null;
        }
        $last = $s->reconciledAtUtc;
        if ($last !== null) {
            try {
                $at = new \DateTimeImmutable($last, new \DateTimeZone('UTC'));
                if (time() - $at->getTimestamp() < self::RECONCILE_EVERY_S) {
                    return null;
                }
            } catch (\Exception) {
                // unreadable timestamp: reconcile
            }
        }
        try {
            return (new \ITFlow\Training\Assign\AssignmentService(SystemCtx::make($db, 0, 'training_dashboard')))
                ->reconcile(null, 'dashboard', 0);
        } catch (\Throwable $e) {
            error_log('Training dashboard reconcile: ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /** Whether any active requirement rule exists (the empty state when there is none). */
    public static function hasRules(\mysqli $db): bool
    {
        try {
            return Db::one($db, 'SELECT 1 AS x FROM training_requirements WHERE requirement_archived_at IS NULL LIMIT 1') !== null;
        } catch (\mysqli_sql_exception) {
            return false;
        }
    }

    /**
     * @param array{client_id?:?int, course_id?:?int, months?:?int} $f
     * @return array<string, mixed> Dashboard (§4.1)
     */
    public function summary(array $f = []): array
    {
        $db = $this->c->db;
        $today = Clock::todayLocal();
        $months = in_array((int) ($f['months'] ?? 12), self::PERIODS, true) ? (int) $f['months'] : 12;
        $clientId = isset($f['client_id']) && $f['client_id'] !== null ? (int) $f['client_id'] : null;
        $courseId = isset($f['course_id']) && $f['course_id'] !== null ? (int) $f['course_id'] : null;
        $filter = ['client_id' => $clientId, 'course_id' => $courseId];
        $target = $this->settings->targetPct;

        $src = new PairSource($this->c, $this->scope, $this->settings);
        $pairs = $src->pairs($filter);
        $peopleList = $src->people(['client_id' => $clientId]);
        $people = [];
        foreach ($peopleList as $p) {
            $people[$p['contact_id']] = $p;
        }

        $tally = PairSource::tally($pairs);
        $pct = Labels::pct($tally['current'], $tally['required']);
        $then = SnapshotService::pctAsOf($db, $this->scope, $courseId, Clock::addMonths($today, -3), $clientId);

        $overdue = OverdueReport::fromPairs($db, $pairs, $people);
        $longest = [];
        $flat = [];
        foreach ($overdue['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                $flat[] = $r;
            }
        }
        usort($flat, static fn($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        foreach (array_slice($flat, 0, 2) as $r) {
            $longest[] = ['person' => $r['person'], 'course' => $r['course'], 'days' => $r['days_overdue'], 'assignment_id' => $r['assignment_id']];
        }

        $expiring = (new ExpiringReport($this->c, $this->scope))->rows(90, $filter);
        $matrix = MatrixService::build($db, $pairs, $peopleList, $target, self::HEATMAP_COURSES);

        $monthStart = substr($today, 0, 7) . '-01';
        $prevStart = Clock::addMonths($monthStart, -1);
        $prevEnd = Clock::addDays($monthStart, -1);
        $counts = $this->completionCounts($monthStart, $today, $prevStart, $prevEnd, $filter);
        $scores = $this->scores(Clock::addMonths($today, -$months), $today, $filter);

        $trend = SnapshotService::trend($db, $this->scope, $courseId, $months, $clientId);
        $vals = array_values(array_filter($trend['values'], static fn($v) => $v !== null));
        $depts = array_values(array_filter($matrix['rows'], static fn($r) => $r['overall_pct'] !== null));
        usort($depts, static fn($a, $b) => [$b['overall_pct'], $a['name']] <=> [$a['overall_pct'], $b['name']]);

        return [
            'as_of' => $today,
            'roster_people' => count($peopleList),
            'reconciled_at' => Clock::toIso($this->settings->reconciledAtUtc, true),
            'scope_none' => $this->scope->isNone(),
            'filters' => ['client_id' => $clientId, 'course_id' => $courseId, 'months' => $months],
            'kpis' => [
                'compliance_pct' => $pct,
                'compliance_delta_pts' => ($pct !== null && $then !== null) ? $pct - $then : null,
                'required' => $tally['required'],
                'current' => $tally['current'],
                'overdue' => $overdue['total'],
                'overdue_people' => $overdue['people'],
                'lapsed_open' => $overdue['lapsed_total'],
                'expiring_30' => $expiring['counts']['d30'],
                'renewals_assigned' => $expiring['renewals_assigned']['d30'],
                'completions_month' => $counts['month'],
                'completions_prev_month' => $counts['prev'],
                'prev_month_label' => (new \DateTimeImmutable($prevStart))->format('F'),
                'avg_score' => $scores['avg'],
                'pass_mark' => $scores['pass_mark'],
                'scored' => $scores['n'],
            ],
            'trend' => [
                'labels' => $trend['labels'],
                'months' => $trend['months'],
                'values' => $trend['values'],
                'points' => $trend['points'],
                'target' => $target,
                'gap_pts' => $pct !== null ? max(0, $target - $pct) : null,
                'change_12m' => count($vals) >= 2 ? end($vals) - $vals[0] : null,
                'strongest' => count($depts) >= 2 ? ['name' => $depts[0]['name'], 'pct' => $depts[0]['overall_pct']] : null,
                'weakest' => count($depts) >= 2 ? ['name' => $depts[count($depts) - 1]['name'], 'pct' => $depts[count($depts) - 1]['overall_pct']] : null,
            ],
            'ageing' => $overdue['ageing'],
            'longest' => $longest,
            'matrix' => $matrix,
            'expiring' => [
                'd30' => $expiring['counts']['d30'],
                'd60' => $expiring['counts']['d60'],
                'd90' => $expiring['counts']['d90'],
                'top' => array_slice($expiring['rows'], 0, 5),
            ],
            'recent' => $this->recent($filter),
        ];
    }

    /** @return array{0:string, 1:string, 2:list<int>} scope + department/course filter over contacts c / completions tc */
    private function where(array $f): array
    {
        [$sql, $types, $params] = $this->scope->sqlIn('c.contact_client_id');
        if ($f['client_id'] !== null) {
            $sql .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $params[] = $f['client_id'];
        }
        if ($f['course_id'] !== null) {
            $sql .= ' AND tc.completion_course_id = ?';
            $types .= 'i';
            $params[] = $f['course_id'];
        }
        return [$sql, $types, $params];
    }

    /** @return array{month:int, prev:int} */
    private function completionCounts(string $mFrom, string $mTo, string $pFrom, string $pTo, array $f): array
    {
        if ($this->scope->isNone()) {
            return ['month' => 0, 'prev' => 0];
        }
        [$w, $types, $params] = $this->where($f);
        $r = Db::one($this->c->db, "SELECT
                COALESCE(SUM(tc.completion_completed_on BETWEEN ? AND ?), 0) AS m,
                COALESCE(SUM(tc.completion_completed_on BETWEEN ? AND ?), 0) AS p
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_completed_on BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)" . $w,
            'ssssss' . $types, array_merge([$mFrom, $mTo, $pFrom, $pTo, $pFrom, $mTo], $params));
        return ['month' => (int) ($r['m'] ?? 0), 'prev' => (int) ($r['p'] ?? 0)];
    }

    /**
     * Average score over scored, non-voided completions completed in the period, and the pass
     * mark to show next to it: the most common pass mark among those records.
     *
     * @return array{avg:?int, pass_mark:?int, n:int}
     */
    private function scores(string $from, string $to, array $f): array
    {
        if ($this->scope->isNone()) {
            return ['avg' => null, 'pass_mark' => null, 'n' => 0];
        }
        [$w, $types, $params] = $this->where($f);
        $r = Db::one($this->c->db, "SELECT COUNT(*) AS n, AVG(tc.completion_score_pct) AS a
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_score_pct IS NOT NULL AND tc.completion_completed_on BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)" . $w,
            'ss' . $types, array_merge([$from, $to], $params));
        $n = (int) ($r['n'] ?? 0);
        $pm = Db::one($this->c->db, "SELECT tc.completion_pass_mark_pct AS pm, COUNT(*) AS k
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            WHERE tc.completion_pass_mark_pct IS NOT NULL AND tc.completion_completed_on BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)" . $w . "
            GROUP BY tc.completion_pass_mark_pct ORDER BY k DESC, pm DESC LIMIT 1",
            'ss' . $types, array_merge([$from, $to], $params));
        return [
            'avg' => $n > 0 ? (int) round((float) $r['a']) : null,
            'pass_mark' => $pm !== null ? (int) $pm['pm'] : null,
            'n' => $n,
        ];
    }

    /** The last 5 non-voided completions recorded, newest first. @return list<array> */
    private function recent(array $f): array
    {
        if ($this->scope->isNone()) {
            return [];
        }
        [$w, $types, $params] = $this->where($f);
        $rows = Db::all($this->c->db, "SELECT tc.completion_id, tc.completion_contact_id, c.contact_name, tc.completion_course_id,
                tc.completion_snap_course_name, tc.completion_course_kind, tc.completion_score_pct, tc.completion_pass_mark_pct,
                tc.completion_method, tc.completion_completed_on, tc.completion_recorded_at_utc, co.course_name
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            LEFT JOIN training_courses co ON co.course_id = tc.completion_course_id
            WHERE NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)" . $w . "
            ORDER BY tc.completion_recorded_at_utc DESC, tc.completion_id DESC LIMIT 5", $types, $params);
        $out = [];
        foreach ($rows as $r) {
            $score = $r['completion_score_pct'] !== null ? Labels::score((string) $r['completion_score_pct']) : null;
            $pm = $r['completion_pass_mark_pct'] !== null ? (int) $r['completion_pass_mark_pct'] : null;
            $out[] = [
                'completion_id' => (int) $r['completion_id'],
                'person' => ['contact_id' => (int) $r['completion_contact_id'], 'name' => (string) $r['contact_name'],
                    'initials' => Labels::initials((string) $r['contact_name'])],
                'course' => ['id' => (int) $r['completion_course_id'], 'name' => (string) ($r['course_name'] ?? $r['completion_snap_course_name'])],
                'kind' => (string) $r['completion_course_kind'],
                'method' => (string) $r['completion_method'],
                'score_pct' => $score,
                'passed' => $score === null || $pm === null || (float) $r['completion_score_pct'] >= $pm,
                'completed_on' => (string) $r['completion_completed_on'],
                'when' => Clock::toIso((string) $r['completion_recorded_at_utc'], true),
            ];
        }
        return $out;
    }
}
