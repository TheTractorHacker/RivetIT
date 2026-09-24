<?php

namespace ITFlow\Training\Reports;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\People\Roster;
use ITFlow\Training\People\Scope;

/**
 * Qualifications expiring in the next 30 / 60 / 90 days (dashboard "Expiring soon", S8 tab).
 *
 * Same definition as Compliance\NavCounts::forCtx(..., true)['expiring_30'], so the dashboard
 * chip, the KPI and this list agree: the LATEST non-voided completion per person × course (by
 * completed_on, then id) whose expires_on falls between today and today + N, for eligible,
 * in-scope people, on courses that are not archived. Each row says whether the renewal is
 * already assigned (an open assignment for that pair).
 */
final class ExpiringReport
{
    public const WINDOWS = [30, 60, 90];

    public function __construct(private readonly Ctx $c, private readonly Scope $scope)
    {
    }

    /**
     * @param array{client_id?:?int, course_id?:?int} $f
     * @return array{days:int, today:string, rows:list<array>, counts:array{d30:int,d60:int,d90:int}, renewals_assigned:array{d30:int,d60:int,d90:int}}
     */
    public function rows(int $days, array $f = [], ?int $limit = null): array
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $today = Clock::todayLocal();
        $all = $this->load($today, Clock::addDays($today, 90), $f);
        $counts = ['d30' => 0, 'd60' => 0, 'd90' => 0];
        $assigned = ['d30' => 0, 'd60' => 0, 'd90' => 0];
        $rows = [];
        foreach ($all as $r) {
            foreach (self::WINDOWS as $w) {
                if ($r['days_left'] <= $w) {
                    $counts['d' . $w]++;
                    if ($r['renewal'] !== null) {
                        $assigned['d' . $w]++;
                    }
                }
            }
            if ($r['days_left'] <= $days && ($limit === null || count($rows) < $limit)) {
                $rows[] = $r;
            }
        }
        return ['days' => $days, 'today' => $today, 'rows' => $rows, 'counts' => $counts, 'renewals_assigned' => $assigned];
    }

    /** @return list<array<string, mixed>> */
    private function load(string $from, string $to, array $f): array
    {
        if ($this->scope->isNone()) {
            return [];
        }
        [$scopeSql, $scopeTypes, $scopeParams] = $this->scope->sqlIn('c.contact_client_id');
        $sql = "SELECT tc.completion_id, tc.completion_contact_id, tc.completion_course_id, tc.completion_expires_on,
                    tc.completion_completed_on, tc.completion_cert_number, c.contact_name, c.contact_title, c.contact_client_id,
                    cl.client_name, co.course_name, co.course_code, co.course_kind,
                    oa.tassign_id AS open_id, oa.tassign_due_on AS open_due_on, oa.tassign_reason AS open_reason
                FROM training_completions tc
                JOIN contacts c ON c.contact_id = tc.completion_contact_id
                " . Roster::JOIN . "
                LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
                JOIN training_courses co ON co.course_id = tc.completion_course_id
                LEFT JOIN training_assignments oa ON oa.tassign_contact_id = tc.completion_contact_id
                     AND oa.tassign_course_id = tc.completion_course_id AND oa.tassign_status = 'open'
                WHERE tc.completion_expires_on BETWEEN ? AND ?
                  AND co.course_archived_at IS NULL
                  AND NOT EXISTS (SELECT 1 FROM training_completion_voids v WHERE v.cvoid_completion_id = tc.completion_id)
                  AND NOT EXISTS (
                      SELECT 1 FROM training_completions n
                      WHERE n.completion_contact_id = tc.completion_contact_id
                        AND n.completion_course_id = tc.completion_course_id
                        AND (n.completion_completed_on > tc.completion_completed_on
                             OR (n.completion_completed_on = tc.completion_completed_on AND n.completion_id > tc.completion_id))
                        AND NOT EXISTS (SELECT 1 FROM training_completion_voids v2 WHERE v2.cvoid_completion_id = n.completion_id))
                  AND " . Roster::ELIGIBLE . $scopeSql;
        $types = 'ss' . $scopeTypes;
        $params = array_merge([$from, $to], $scopeParams);
        if (isset($f['client_id']) && $f['client_id'] !== null) {
            $sql .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $params[] = (int) $f['client_id'];
        }
        if (isset($f['course_id']) && $f['course_id'] !== null) {
            $sql .= ' AND tc.completion_course_id = ?';
            $types .= 'i';
            $params[] = (int) $f['course_id'];
        }
        $sql .= ' ORDER BY tc.completion_expires_on, c.contact_name, tc.completion_id';
        $out = [];
        $seen = [];
        foreach (Db::all($this->c->db, $sql, $types, $params) as $r) {
            $id = (int) $r['completion_id'];
            if (isset($seen[$id])) {
                continue; // defensive: at most one open assignment per pair, by the open-guard key
            }
            $seen[$id] = true;
            $name = (string) $r['contact_name'];
            $clientId = (int) $r['contact_client_id'];
            $out[] = [
                'completion_id' => $id,
                'person' => [
                    'contact_id' => (int) $r['completion_contact_id'],
                    'name' => $name,
                    'title' => $r['contact_title'],
                    'initials' => Labels::initials($name),
                    'department' => ['id' => $clientId, 'name' => $clientId > 0 ? (string) $r['client_name'] : Lookup::NO_DEPARTMENT],
                ],
                'course' => ['id' => (int) $r['completion_course_id'], 'name' => (string) $r['course_name'],
                    'code' => $r['course_code'], 'kind' => (string) $r['course_kind']],
                'completed_on' => (string) $r['completion_completed_on'],
                'expires_on' => (string) $r['completion_expires_on'],
                'days_left' => Labels::days($from, (string) $r['completion_expires_on']),
                'cert_number' => $r['completion_cert_number'],
                'renewal' => $r['open_id'] !== null
                    ? ['assignment_id' => (int) $r['open_id'], 'due_on' => (string) $r['open_due_on'], 'reason' => (string) $r['open_reason']]
                    : null,
            ];
        }
        return $out;
    }
}
