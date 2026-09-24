<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Compliance\ComplianceService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\RecordsSettings;
use ITFlow\Training\People\Scope;

/**
 * The reports' single door into Lane B's compliance engine (spec §3.6: "all reads go through
 * ComplianceService(scope)").
 *
 * ComplianceService::pairs() returns PairRow = PairStatus (§4.1) plus the person it belongs to.
 * This class flattens each row into the few fields the reports aggregate, so every report
 * counts a pair the same way:
 *
 *   {contact_id, client_id, course_id, course:{id,name,code,kind,is_qualification}, required,
 *    status, label, due_on, expires_on, days_overdue, assignment_id, completion_id, cert_number,
 *    anchor, counts_current:bool, waived:bool}
 *
 * "Counts as current" and "excluded from the denominator" follow the frozen pair-status table
 * (§3.3): current, expiring and retrain_due count; waived is excluded; everything else does not.
 */
final class PairSource
{
    public const CURRENT = ['current', 'expiring', 'retrain_due'];

    private ?ComplianceService $svc = null;

    public function __construct(
        private readonly Ctx $c,
        private readonly Scope $scope,
        private readonly RecordsSettings $settings,
    ) {
    }

    public function service(): ComplianceService
    {
        return $this->svc ??= new ComplianceService($this->c, $this->scope, $this->settings);
    }

    /**
     * Normalized pairs for eligible in-scope people.
     *
     * @param array{client_id?:?int, course_id?:?int, job_id?:?int, location_id?:?int, required_only?:bool} $f
     * @return list<array<string, mixed>>
     */
    public function pairs(array $f = []): array
    {
        if ($this->scope->isNone()) {
            return [];
        }
        $out = [];
        foreach ($this->service()->pairs(self::filters($f)) as $row) {
            if (is_array($row)) {
                $out[] = self::normalize($row);
            }
        }
        return $out;
    }

    /**
     * Eligible in-scope people: list of {contact_id, name, title, client_id, client_name,
     * job_name, location_name, hire_date, employee_no, manager_id}.
     *
     * @return list<array<string, mixed>>
     */
    public function people(array $f = []): array
    {
        if ($this->scope->isNone()) {
            return [];
        }
        $out = [];
        foreach ($this->service()->people(self::filters($f)) as $p) {
            if (!is_array($p) || !isset($p['contact_id'])) {
                continue;
            }
            $out[] = [
                'contact_id' => (int) $p['contact_id'],
                'name' => (string) ($p['name'] ?? $p['contact_name'] ?? ''),
                'title' => isset($p['title']) ? (string) $p['title'] : null,
                'client_id' => (int) ($p['client_id'] ?? 0),
                'client_name' => isset($p['client_name']) ? (string) $p['client_name'] : null,
                'job_name' => isset($p['job_name']) ? (string) $p['job_name'] : null,
                'location_name' => isset($p['location_name']) ? (string) $p['location_name'] : null,
                'hire_date' => $p['hire_date'] ?? null,
                'employee_no' => isset($p['employee_no']) ? (string) $p['employee_no'] : null,
                'manager_id' => isset($p['manager_id']) ? (int) $p['manager_id'] : null,
            ];
        }
        return $out;
    }

    /** Keeps only the filter keys ComplianceService documents, as ints. */
    private static function filters(array $f): array
    {
        $out = [];
        foreach (['client_id', 'course_id', 'job_id', 'location_id'] as $k) {
            if (isset($f[$k]) && $f[$k] !== '' && $f[$k] !== null) {
                $out[$k] = (int) $f[$k];
            }
        }
        if (array_key_exists('required_only', $f)) {
            $out['required_only'] = (bool) $f['required_only'];
        }
        if (isset($f['q']) && is_string($f['q']) && $f['q'] !== '') {
            $out['q'] = $f['q'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function normalize(array $r): array
    {
        $person = is_array($r['person'] ?? null) ? $r['person'] : [];
        $course = is_array($r['course'] ?? null) ? $r['course'] : [];
        $dept = is_array($person['department'] ?? null) ? $person['department'] : null;
        $clientId = $r['client_id'] ?? $person['client_id'] ?? ($dept['id'] ?? 0);
        $status = (string) ($r['status'] ?? 'not_started');
        return [
            'contact_id' => (int) ($r['contact_id'] ?? $person['contact_id'] ?? 0),
            'client_id' => (int) $clientId,
            'course_id' => (int) ($r['course_id'] ?? $course['id'] ?? 0),
            'course' => [
                'id' => (int) ($course['id'] ?? $r['course_id'] ?? 0),
                'name' => (string) ($course['name'] ?? ''),
                'code' => isset($course['code']) && $course['code'] !== '' ? (string) $course['code'] : null,
                'kind' => (string) ($course['kind'] ?? 'training'),
                'is_qualification' => !empty($course['is_qualification']),
            ],
            'required' => !array_key_exists('required', $r) || (bool) $r['required'],
            'status' => $status,
            'label' => (string) ($r['label'] ?? ''),
            'due_on' => $r['due_on'] ?? null,
            'expires_on' => $r['expires_on'] ?? null,
            'days_overdue' => isset($r['days_overdue']) ? (int) $r['days_overdue'] : 0,
            'assignment_id' => isset($r['assignment_id']) ? (int) $r['assignment_id'] : null,
            'completion_id' => isset($r['completion_id']) ? (int) $r['completion_id'] : null,
            'cert_number' => $r['cert_number'] ?? null,
            'anchor' => $r['anchor'] ?? null,
            'counts_current' => in_array($status, self::CURRENT, true),
            'waived' => $status === 'waived',
        ];
    }

    /** The PairStatus subset sent to the browser for one pair. */
    public static function toStatus(array $p): array
    {
        return [
            'course' => $p['course'],
            'required' => $p['required'],
            'status' => $p['status'],
            'label' => $p['label'],
            'due_on' => $p['due_on'],
            'expires_on' => $p['expires_on'],
            'days_overdue' => $p['days_overdue'],
            'assignment_id' => $p['assignment_id'],
            'completion_id' => $p['completion_id'],
            'cert_number' => $p['cert_number'],
            'anchor' => $p['anchor'],
        ];
    }

    /**
     * Required, non-waived pairs = the compliance denominator; current = those that count.
     *
     * @param iterable<array<string, mixed>> $pairs
     * @return array{required:int, current:int, waived:int, overdue:int}
     */
    public static function tally(iterable $pairs): array
    {
        $t = ['required' => 0, 'current' => 0, 'waived' => 0, 'overdue' => 0];
        foreach ($pairs as $p) {
            if (!$p['required']) {
                continue;
            }
            if ($p['waived']) {
                $t['waived']++;
                continue;
            }
            $t['required']++;
            if ($p['counts_current']) {
                $t['current']++;
            }
            if ($p['status'] === 'overdue') {
                $t['overdue']++;
            }
        }
        return $t;
    }
}
