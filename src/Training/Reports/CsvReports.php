<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Directory;
use ITFlow\Training\People\Scope;
use ITFlow\Training\Records\EvidenceStrength;

/**
 * The CSV exports behind `report_csv` (S8). Each builder returns [filename, header, rows]; the
 * action streams it with Core\Csv::send, whose formula guard neutralises cells such as "=cmd".
 * Every export is scoped (Scope::sqlIn on the person's current department) and read-only.
 */
final class CsvReports
{
    public const REPORTS = ['records', 'assignments', 'matrix', 'overdue', 'expiring', 'course', 'doc_acks', 'roster'];

    public function __construct(
        private readonly Ctx $c,
        private readonly Scope $scope,
        private readonly RecordsSettings $settings,
    ) {
    }

    /** @return array{0:string, 1:list<string>, 2:list<list<mixed>>} */
    public function build(string $report, array $f): array
    {
        $stamp = Clock::todayLocal();
        return match ($report) {
            'records' => ['training-records-' . $stamp . '.csv', ...$this->records($f)],
            'assignments' => ['training-assignments-' . $stamp . '.csv', ...$this->assignments($f)],
            'matrix' => ['training-matrix-' . $stamp . '.csv', ...$this->matrix($f)],
            'overdue' => ['training-overdue-' . $stamp . '.csv', ...$this->overdue($f)],
            'expiring' => ['training-expiring-' . $stamp . '.csv', ...$this->expiring($f)],
            'course' => ['training-course-' . (int) ($f['course_id'] ?? 0) . '-' . $stamp . '.csv', ...$this->course($f)],
            'doc_acks' => ['training-acknowledgments-' . $stamp . '.csv', ...$this->docAcks($f)],
            'roster' => ['training-roster-' . $stamp . '.csv', ...$this->roster($f)],
            default => throw ApiException::validation(['report' => 'Not a valid choice.']),
        };
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function records(array $f): array
    {
        $header = ['Record #', 'Certificate no.', 'Person', 'Department', 'Course', 'Kind', 'Method', 'Evidence', 'Completed on',
            'Trained on', 'Evaluated on', 'Expires on', 'Score %', 'Trainer / evaluator', 'Issuer', 'Card no.', 'Language',
            'Voided on', 'Void reason', 'Recorded at', 'Notes'];
        if ($this->scope->isNone()) {
            return [$header, []];
        }
        [$w, $types, $params] = $this->scope->sqlIn('c.contact_client_id');
        $sql = "SELECT tc.completion_id, tc.completion_cert_number, tc.completion_snap_contact_name, c.contact_client_id, cl.client_name,
                tc.completion_snap_course_name, tc.completion_course_kind, tc.completion_method, tc.completion_proof,
                tc.completion_completed_on, tc.completion_trained_on, tc.completion_evaluated_on, tc.completion_expires_on,
                tc.completion_score_pct, tc.completion_trainer_name, tc.completion_evaluator_name, tc.completion_external_issuer,
                tc.completion_external_ref, tc.completion_language, tc.completion_recorded_at_utc, tc.completion_notes,
                v.cvoid_at_utc, v.cvoid_reason
            FROM training_completions tc
            JOIN contacts c ON c.contact_id = tc.completion_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = tc.completion_id
            WHERE 1=1" . $w;
        foreach (['course_id' => 'tc.completion_course_id', 'client_id' => 'c.contact_client_id'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] !== null) {
                $sql .= " AND $col = ?";
                $types .= 'i';
                $params[] = (int) $f[$k];
            }
        }
        if (!empty($f['method'])) {
            $sql .= ' AND tc.completion_method = ?';
            $types .= 's';
            $params[] = (string) $f['method'];
        }
        if (in_array($f['strength'] ?? null, array_keys(EvidenceStrength::LABELS), true)) {
            // The same grade the records log filters on (CompletionView::list).
            $sql .= ' AND ' . EvidenceStrength::sqlCase('tc.completion_method', 'tc.completion_proof') . ' = ?';
            $types .= 's';
            $params[] = (string) $f['strength'];
        }
        if (isset($f['voided']) && $f['voided'] !== null) {
            $sql .= $f['voided'] ? ' AND v.cvoid_id IS NOT NULL' : ' AND v.cvoid_id IS NULL';
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($f[$k])) {
                $sql .= " AND tc.completion_completed_on $op ?";
                $types .= 's';
                $params[] = (string) $f[$k];
            }
        }
        if (!empty($f['q'])) {
            // The same columns the records log searches (CompletionView::list).
            $sql .= ' AND (tc.completion_snap_contact_name LIKE ? OR c.contact_name LIKE ? OR tc.completion_cert_number LIKE ?'
                . ' OR tc.completion_snap_course_name LIKE ? OR tc.completion_external_ref LIKE ?)';
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sql .= ' ORDER BY tc.completion_completed_on DESC, tc.completion_id DESC';
        $rows = [];
        foreach (Db::all($this->c->db, $sql, $types, $params) as $r) {
            $grade = Labels::grade((string) $r['completion_method'], (string) $r['completion_proof']);
            $rows[] = [
                (int) $r['completion_id'], $r['completion_cert_number'], $r['completion_snap_contact_name'],
                (int) $r['contact_client_id'] > 0 ? $r['client_name'] : Lookup::NO_DEPARTMENT,
                $r['completion_snap_course_name'], $r['completion_course_kind'], Labels::methodLabel((string) $r['completion_method']),
                $grade . ' ' . Labels::strengthLabel($grade, (string) $r['completion_method']),
                $r['completion_completed_on'], $r['completion_trained_on'], $r['completion_evaluated_on'], $r['completion_expires_on'],
                $r['completion_score_pct'], $r['completion_evaluator_name'] ?: $r['completion_trainer_name'],
                $r['completion_external_issuer'], $r['completion_external_ref'], $r['completion_language'],
                $r['cvoid_at_utc'] !== null ? Clock::localDate((string) $r['cvoid_at_utc']) : null, $r['cvoid_reason'],
                Clock::toIso((string) $r['completion_recorded_at_utc'], true), $r['completion_notes'],
            ];
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function assignments(array $f): array
    {
        $header = ['Assignment #', 'Person', 'Department', 'Course', 'Reason', 'Required by', 'Required', 'Due on', 'Original due on',
            'Status', 'Days overdue', 'Waived until', 'Created', 'Closed', 'Close reason', 'Close note'];
        if ($this->scope->isNone()) {
            return [$header, []];
        }
        $today = Clock::todayLocal();
        [$w, $types, $params] = $this->scope->sqlIn('c.contact_client_id');
        $sql = "SELECT a.tassign_id, a.tassign_course_id, a.tassign_reason, a.tassign_anchor, a.tassign_requirement_id, a.tassign_required,
                a.tassign_due_on, a.tassign_original_due_on, a.tassign_status, a.tassign_waived_until, a.tassign_completion_id,
                a.tassign_created_at_utc, a.tassign_created_by, a.tassign_closed_at_utc, a.tassign_close_reason, a.tassign_close_note,
                a.tassign_reopened_count, co.course_name, co.course_code, co.course_kind, r.requirement_name, r.requirement_is_manual,
                c.contact_name, c.contact_client_id, cl.client_name
            FROM training_assignments a
            JOIN contacts c ON c.contact_id = a.tassign_contact_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            LEFT JOIN training_courses co ON co.course_id = a.tassign_course_id
            LEFT JOIN training_requirements r ON r.requirement_id = a.tassign_requirement_id
            WHERE 1=1" . $w;
        foreach (['course_id' => 'a.tassign_course_id', 'client_id' => 'c.contact_client_id', 'contact_id' => 'a.tassign_contact_id'] as $k => $col) {
            if (isset($f[$k]) && $f[$k] !== null) {
                $sql .= " AND $col = ?";
                $types .= 'i';
                $params[] = (int) $f[$k];
            }
        }
        $status = $f['status'] ?? null;
        if (in_array($status, ['completed', 'waived', 'cancelled'], true)) {
            $sql .= ' AND a.tassign_status = ?';
            $types .= 's';
            $params[] = $status;
        } elseif ($status === 'open') {
            $sql .= " AND a.tassign_status = 'open'";
        } elseif ($status === 'overdue') {
            $sql .= " AND a.tassign_status = 'open' AND a.tassign_due_on < ?";
            $types .= 's';
            $params[] = $today;
        } elseif ($status === 'due_soon') {
            $sql .= " AND a.tassign_status = 'open' AND a.tassign_due_on BETWEEN ? AND ?";
            $types .= 'ss';
            array_push($params, $today, Clock::addDays($today, $this->settings->dueSoonDays));
        }
        $sql .= ' ORDER BY c.contact_name, a.tassign_due_on, a.tassign_id';
        $raw = Db::all($this->c->db, $sql, $types, $params);
        $details = OverdueReport::assignmentDetails($this->c->db, array_map(static fn($r) => (int) $r['tassign_id'], $raw));
        $rows = [];
        foreach ($raw as $r) {
            $a = TranscriptService::assignment($r, $details[(int) $r['tassign_id']] ?? null, $today, $this->settings->dueSoonDays);
            $rows[] = [
                $a['id'], $r['contact_name'], (int) $r['contact_client_id'] > 0 ? $r['client_name'] : Lookup::NO_DEPARTMENT,
                $a['course']['name'], $a['anchor_label'], $r['requirement_name'], $a['required'] ? 'Yes' : 'No',
                $a['due_on'], $a['original_due_on'], $a['display_status'], $a['days_overdue'] ?: null, $a['waived_until'],
                $a['created_on'], $r['tassign_closed_at_utc'] !== null ? Clock::localDate((string) $r['tassign_closed_at_utc']) : null,
                $a['close_reason'], $a['close_note'],
            ];
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function matrix(array $f): array
    {
        $m = (new MatrixService($this->c, $this->scope, $this->settings))->matrix(self::pick($f, ['client_id', 'course_id', 'job_id', 'location_id']));
        $header = ['Department', 'Headcount'];
        foreach ($m['courses'] as $course) {
            $header[] = $course['name'];
        }
        $header[] = 'Overall %';
        $rows = [];
        foreach ($m['rows'] as $r) {
            $line = [$r['name'], $r['headcount']];
            foreach ($m['courses'] as $course) {
                $cell = $r['cells']['c' . $course['id']] ?? null;
                $line[] = $cell === null ? 'Not required' : ($cell['pct'] === null ? 'Waived' : $cell['pct'] . '% (' . $cell['current'] . '/' . $cell['required'] . ')');
            }
            $line[] = $r['overall_pct'];
            $rows[] = $line;
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function overdue(array $f): array
    {
        $o = (new OverdueReport($this->c, $this->scope, $this->settings))->rows(self::pick($f, ['client_id', 'course_id', 'job_id', 'location_id']));
        $header = ['Department', 'Person', 'Title', 'Course', 'Reason', 'Due on', 'Days overdue', 'Scheduled for'];
        $rows = [];
        foreach ($o['groups'] as $g) {
            foreach ($g['rows'] as $r) {
                $rows[] = [$g['name'], $r['person']['name'], $r['person']['title'], $r['course']['name'], $r['reason_label'],
                    $r['due_on'], $r['days_overdue'], ''];
            }
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function expiring(array $f): array
    {
        $days = (int) ($f['days'] ?? 30);
        $e = (new ExpiringReport($this->c, $this->scope))->rows($days, self::pick($f, ['client_id', 'course_id']));
        $header = ['Person', 'Department', 'Course', 'Certificate no.', 'Expires on', 'Days left', 'Renewal assigned', 'Renewal due on'];
        $rows = [];
        foreach ($e['rows'] as $r) {
            $rows[] = [$r['person']['name'], $r['person']['department']['name'], $r['course']['name'], $r['cert_number'],
                $r['expires_on'], $r['days_left'], $r['renewal'] !== null ? 'Yes' : 'No', $r['renewal']['due_on'] ?? null];
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function course(array $f): array
    {
        $courseId = (int) ($f['course_id'] ?? 0);
        if ($courseId <= 0) {
            throw ApiException::validation(['course_id' => 'Required.']);
        }
        $a = (new CourseAnalytics($this->c, $this->settings))->build($courseId, (string) $f['from'], (string) $f['to'], $this->scope);
        $header = ['Section', 'Item', 'Count / value', 'Of', 'Percent'];
        $rows = [];
        foreach ($a['funnel'] as $step) {
            $rows[] = ['Funnel', $step['label'], $step['count'] ?? '—', $a['funnel'][0]['count'], $step['pct']];
        }
        $k = $a['kpis'];
        $rows[] = ['KPI', 'Pass rate', $k['passed'], $k['scored'], $k['pass_rate']];
        $rows[] = ['KPI', 'Average score', $k['avg_score'], null, null];
        $rows[] = ['KPI', 'First-try pass', $k['first_try_count'], $k['with_attempts'], $k['first_try']];
        $rows[] = ['KPI', 'Median time (minutes)', $k['median_minutes'], null, null];
        foreach ($a['scores']['buckets'] as $i => $n) {
            $rows[] = ['Scores', ($i * 10) . '–' . ($i === 9 ? 100 : $i * 10 + 9), $n, $a['scores']['total'], null];
        }
        foreach ($a['by_department'] as $d) {
            $rows[] = ['Department', $d['name'], $d['current'], $d['required'], $d['pct']];
        }
        foreach ($a['hardest'] as $q) {
            $rows[] = ['Hardest question', ($q['text'] ?? $q['question_uid']), $q['correct'], $q['answered'], $q['correct_pct']];
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function docAcks(array $f): array
    {
        $courseId = isset($f['course_id']) && $f['course_id'] !== null ? (int) $f['course_id'] : null;
        $d = (new DocAckReport($this->c, $this->settings))->build($courseId, self::pick($f, ['client_id']), $this->scope);
        $header = ['Document', 'Version', 'Department', 'Required', 'Current version', 'Older version', 'Not acknowledged', 'Acknowledged %'];
        $rows = [];
        foreach ($d['courses'] as $c) {
            foreach ($c['departments'] as $r) {
                $rows[] = [$c['course']['name'], $c['course']['revision_number'], $r['name'], $r['required'], $r['current_version'],
                    $r['older_version'], $r['not_acknowledged'], $r['pct']];
            }
        }
        return [$header, $rows];
    }

    /** @return array{0:list<string>, 1:list<list<mixed>>} */
    private function roster(array $f): array
    {
        $header = ['Name', 'Department', 'Title', 'Job position', 'Work location', 'Hire date', 'Employee #', 'Eligible', 'Roster state', 'Odoo link'];
        if ($this->scope->isNone()) {
            return [$header, []];
        }
        $rows = [];
        foreach (Directory::load($this->c->db, $this->scope, null, true) as $p) {
            if (isset($f['client_id']) && $f['client_id'] !== null && (int) ($p['client_id'] ?? 0) !== (int) $f['client_id']) {
                continue;
            }
            if (!empty($p['archived'])) {
                continue;
            }
            $rows[] = [$p['name'] ?? '', (int) ($p['client_id'] ?? 0) > 0 ? ($p['client_name'] ?? '') : Lookup::NO_DEPARTMENT,
                $p['title'] ?? null, $p['job_name'] ?? null, $p['location_name'] ?? null, $p['hire_date'] ?? null,
                $p['employee_no'] ?? null, !empty($p['eligible']) ? 'Yes' : 'No', $p['roster_state'] ?? 'auto', $p['link_state'] ?? null];
        }
        usort($rows, static fn($a, $b) => strcasecmp((string) $a[0], (string) $b[0]));
        return [$header, $rows];
    }

    private static function pick(array $f, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $f[$k] ?? null;
        }
        return $out;
    }
}
