<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;

/**
 * Department × course compliance matrix (dashboard heatmap, S8 full matrix) and the cell
 * drill-down (who is missing). All counts are over eligible, in-scope people only; a cell is
 * required pairs (waivers excluded) and those that count as current (spec §3.3).
 */
final class MatrixService
{
    private PairSource $src;

    public function __construct(
        private readonly Ctx $c,
        private readonly Scope $scope,
        private readonly RecordsSettings $settings,
    ) {
        $this->src = new PairSource($c, $scope, $settings);
    }

    public function source(): PairSource
    {
        return $this->src;
    }

    /**
     * @param array{client_id?:?int, course_id?:?int, job_id?:?int, location_id?:?int} $f
     * @return array{courses:list<array>, rows:list<array>, totals:array, target:int, people:int}
     */
    public function matrix(array $f = [], ?int $maxCourses = null): array
    {
        $pairs = $this->src->pairs($f);
        $people = $this->src->people($f);
        return self::build($this->c->db, $pairs, $people, $this->settings->targetPct, $maxCourses);
    }

    /**
     * Builds the matrix from already-loaded pairs and people (the dashboard reuses its load).
     *
     * Columns are the courses with at least one required pair, most-required first. Rows are
     * departments with eligible people, by name, "No department" last.
     */
    public static function build(\mysqli $db, array $pairs, array $people, int $target, ?int $maxCourses = null): array
    {
        $courses = [];
        $cells = [];
        $rowTally = [];
        $colTally = [];
        foreach ($pairs as $p) {
            if (!$p['required']) {
                continue;
            }
            $cid = $p['course_id'];
            $dep = $p['client_id'];
            if (!isset($courses[$cid])) {
                $courses[$cid] = ['id' => $cid, 'name' => $p['course']['name'], 'code' => $p['course']['code'],
                    'kind' => $p['course']['kind'], 'pairs' => 0];
            }
            $courses[$cid]['pairs']++;
            $cells[$dep][$cid] ??= ['required' => 0, 'current' => 0, 'waived' => 0, 'overdue' => 0];
            $rowTally[$dep] ??= ['required' => 0, 'current' => 0];
            $colTally[$cid] ??= ['required' => 0, 'current' => 0];
            if ($p['waived']) {
                $cells[$dep][$cid]['waived']++;
                continue;
            }
            $cells[$dep][$cid]['required']++;
            $rowTally[$dep]['required']++;
            $colTally[$cid]['required']++;
            if ($p['counts_current']) {
                $cells[$dep][$cid]['current']++;
                $rowTally[$dep]['current']++;
                $colTally[$cid]['current']++;
            }
            if ($p['status'] === 'overdue') {
                $cells[$dep][$cid]['overdue']++;
            }
        }
        uasort($courses, static fn($a, $b) => [$b['pairs'], $a['name']] <=> [$a['pairs'], $b['name']]);
        $shown = array_values($courses);
        $hidden = 0;
        if ($maxCourses !== null && count($shown) > $maxCourses) {
            $hidden = count($shown) - $maxCourses;
            $shown = array_slice($shown, 0, $maxCourses);
        }

        $headcount = [];
        foreach ($people as $person) {
            $headcount[$person['client_id']] = ($headcount[$person['client_id']] ?? 0) + 1;
        }
        $deptIds = array_unique(array_merge(array_keys($headcount), array_keys($cells)));
        $names = Lookup::departmentNames($db, $deptIds);
        foreach ($people as $person) {
            if ($person['client_name'] !== null && $person['client_name'] !== '' && $person['client_id'] > 0) {
                $names[$person['client_id']] = $person['client_name'];
            }
        }

        $rows = [];
        foreach ($deptIds as $dep) {
            $row = [
                'client_id' => (int) $dep,
                'name' => $names[$dep] ?? ('Department #' . $dep),
                'headcount' => $headcount[$dep] ?? 0,
                'cells' => [],
                'required' => $rowTally[$dep]['required'] ?? 0,
                'current' => $rowTally[$dep]['current'] ?? 0,
            ];
            foreach ($shown as $course) {
                $cell = $cells[$dep][$course['id']] ?? null;
                if ($cell === null || ($cell['required'] === 0 && $cell['waived'] === 0)) {
                    $row['cells']['c' . $course['id']] = null;
                    continue;
                }
                $pct = Labels::pct($cell['current'], $cell['required']);
                $row['cells']['c' . $course['id']] = $cell + ['pct' => $pct, 'band' => Labels::band($pct, $target)];
            }
            $row['overall_pct'] = Labels::pct($row['current'], $row['required']);
            $row['overall_band'] = Labels::band($row['overall_pct'], $target);
            $rows[] = $row;
        }
        usort($rows, static function ($a, $b) {
            if (($a['client_id'] === 0) !== ($b['client_id'] === 0)) {
                return $a['client_id'] === 0 ? 1 : -1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        $totCells = [];
        $req = 0;
        $cur = 0;
        foreach ($shown as $course) {
            $t = $colTally[$course['id']] ?? ['required' => 0, 'current' => 0];
            $pct = Labels::pct($t['current'], $t['required']);
            $totCells['c' . $course['id']] = $t + ['pct' => $pct, 'band' => Labels::band($pct, $target)];
        }
        foreach ($rowTally as $t) {
            $req += $t['required'];
            $cur += $t['current'];
        }
        $overall = Labels::pct($cur, $req);

        return [
            'courses' => array_map(static fn($c) => [
                'id' => $c['id'],
                'short' => Labels::shortCourse($c['code'], $c['name']),
                'name' => $c['name'],
                'code' => $c['code'],
                'kind' => $c['kind'],
            ], $shown),
            'hidden_courses' => $hidden,
            'rows' => $rows,
            'totals' => ['cells' => $totCells, 'required' => $req, 'current' => $cur, 'overall_pct' => $overall,
                'overall_band' => Labels::band($overall, $target)],
            'target' => $target,
            'people' => count($people),
        ];
    }

    /**
     * Who is missing in one department × course cell: every required pair there that does not
     * count as current (waived pairs are listed last, for context), with the PairStatus.
     *
     * @return array{department:array, course:array, people:list<array>, required:int, current:int}
     */
    public function cellPeople(int $clientId, int $courseId): array
    {
        if ($this->scope->isNone() || !$this->scope->allows($clientId)) {
            throw new ApiException(404, 'not_found', 'That department was not found.');
        }
        $course = Lookup::course($this->c->db, $courseId);
        if ($course === null) {
            throw new ApiException(404, 'not_found', 'That course was not found.');
        }
        $pairs = $this->src->pairs(['client_id' => $clientId, 'course_id' => $courseId]);
        $names = [];
        foreach ($this->src->people(['client_id' => $clientId]) as $p) {
            $names[$p['contact_id']] = $p;
        }
        $order = ['overdue' => 0, 'expired' => 1, 'not_started' => 2, 'due_soon' => 3, 'due' => 4, 'waived' => 9];
        $people = [];
        $req = 0;
        $cur = 0;
        foreach ($pairs as $p) {
            if ($p['client_id'] !== $clientId || $p['course_id'] !== $courseId || !$p['required']) {
                continue;
            }
            if (!$p['waived']) {
                $req++;
            }
            if ($p['counts_current']) {
                $cur++;
                continue;
            }
            $person = $names[$p['contact_id']] ?? ['contact_id' => $p['contact_id'], 'name' => 'Contact #' . $p['contact_id'], 'title' => null];
            $people[] = [
                'person' => ['contact_id' => $p['contact_id'], 'name' => $person['name'], 'title' => $person['title'] ?? null,
                    'initials' => Labels::initials($person['name'])],
                'pair' => PairSource::toStatus($p),
                '_o' => $order[$p['status']] ?? 5,
            ];
        }
        usort($people, static fn($a, $b) => [$a['_o'], -$a['pair']['days_overdue'], $a['person']['name']]
            <=> [$b['_o'], -$b['pair']['days_overdue'], $b['person']['name']]);
        foreach ($people as &$row) {
            unset($row['_o']);
        }
        unset($row);
        $deptName = Lookup::departmentNames($this->c->db, [$clientId])[$clientId] ?? ('Department #' . $clientId);
        return [
            'department' => ['id' => $clientId, 'name' => $deptName],
            'course' => ['id' => $course['id'], 'name' => $course['name'], 'code' => $course['code'], 'kind' => $course['kind']],
            'people' => $people,
            'required' => $req,
            'current' => $cur,
            'pct' => Labels::pct($cur, $req),
        ];
    }
}
