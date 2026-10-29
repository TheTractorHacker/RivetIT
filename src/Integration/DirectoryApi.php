<?php

namespace ITFlow\Integration;

use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * Read side of the directory API (GET /api/v1/employees, /api/v1/departments) for integration clients (the QMS).
 *
 * A department is a `clients` row (client_lead = 0, as in the Training module); an employee is a `contacts` row whose department is
 * contacts.contact_client_id (0 = none). The Odoo ids come from the directory link tables (contact_odoo_links, client_odoo_links); when a
 * record is linked to more than one Odoo integration the lowest id is returned, exactly like the LMS endpoints.
 *
 * MINIMUM FIELDS ONLY. Employee: id, odoo_employee_id, name, work_email, job_title, department_id, odoo_department_id, manager_id,
 * employment_status, active, updated_at. Department: id, odoo_department_id, name, parent_id, head_employee_id, status, active, updated_at.
 * No PIN, phone, address, notes, photo, portal role or any secret is ever selected.
 *
 * DEPARTMENT SCOPE (least privilege, OWASP API1). Every query is filtered IN SQL by the client's allowed departments
 * (IntegrationScope::allowedDepartmentIds: null = all, [] = none, otherwise the listed clients.client_id values). A restricted client sees
 * only those departments and the employees whose contact_client_id is one of them (people with no department are visible to
 * all-department clients only). A record outside the scope answers exactly like one that does not exist (404 / absent from lists).
 * The reference fields manager_id, parent_id and head_employee_id are returned only when the record they point at is in scope too, else null,
 * so a link never discloses an out-of-scope record. The scope argument has no default on purpose: a caller that forgets it must fail.
 *
 * TOMBSTONES. Archived and terminated employees, and archived or inactive departments, stay in the feed (active = false) so the QMS can
 * deprovision. A row that was hard-deleted, or that left the client's scope, simply disappears (no tombstone is possible for it).
 *
 * ORDERING / PAGING. Stable: ascending id, cursor = last id (LmsApi::paging, the same cursor and limit rules as the LMS endpoints).
 * updated_since is inclusive and compares the latest of the row's own update/creation/archive times and its Odoo link's update time.
 */
final class DirectoryApi
{
    private const EPOCH = '1970-01-01 00:00:00';

    /** active = false for these employment statuses (and for every archived contact). pre-hire, leave, suspended and *_pending stay active. */
    private const INACTIVE_EMPLOYMENT = ['terminated', 'archived'];

    /**
     * @param list<int>|null $departments the client's allowed departments (IntegrationScope::allowedDepartmentIds): null = all, [] = none.
     */
    public function __construct(private readonly \mysqli $db, private readonly ?array $departments)
    {
    }

    // ---- department scope --------------------------------------------------------------------------------------------

    /**
     * SQL condition (with a leading AND) limiting $col to the allowed departments, plus its bound values.
     * @return array{0:string,1:string,2:list<int>} sql, types, params
     */
    private function scope(string $col): array
    {
        if ($this->departments === null) {
            return ['', '', []];
        }
        if ($this->departments === []) {
            return [' AND 1 = 0', '', []];
        }
        $ids = array_values(array_map('intval', $this->departments));
        return [" AND $col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids];
    }

    /** Of the given contact ids, the ones that are employees in scope (used to null out reference fields that point out of scope). */
    private function employeesInScope(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        [$ssql, $stypes, $sparams] = $this->scope('c.contact_client_id');
        $sql = 'SELECT c.contact_id FROM contacts c WHERE c.contact_vendor_id = 0 AND c.contact_employee_type <> \'vendor\' AND c.contact_id IN ('
            . implode(',', array_fill(0, count($ids), '?')) . ')' . $ssql;
        $rows = Db::all($this->db, $sql, str_repeat('i', count($ids)) . $stypes, array_merge($ids, $sparams));
        return array_flip(array_map(static fn(array $r) => (int) $r['contact_id'], $rows));
    }

    /** Of the given client ids, the ones that are departments in scope. */
    private function departmentsInScope(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        [$ssql, $stypes, $sparams] = $this->scope('c.client_id');
        $sql = 'SELECT c.client_id FROM clients c WHERE c.client_lead = 0 AND c.client_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' . $ssql;
        $rows = Db::all($this->db, $sql, str_repeat('i', count($ids)) . $stypes, array_merge($ids, $sparams));
        return array_flip(array_map(static fn(array $r) => (int) $r['client_id'], $rows));
    }

    // ---- employees -------------------------------------------------------------------------------------------------

    private const EMP_UPDATED = "GREATEST(COALESCE(c.contact_updated_at, c.contact_created_at), c.contact_created_at,
            COALESCE(c.contact_archived_at, '" . self::EPOCH . "'),
            COALESCE((SELECT MAX(lu.updated_at) FROM contact_odoo_links lu WHERE lu.contact_id = c.contact_id), '" . self::EPOCH . "'))";

    private const EMP_SELECT = 'SELECT c.contact_id, c.contact_name, c.contact_email, c.contact_title, c.contact_client_id, c.contact_manager_id,
            c.contact_employment_status, c.contact_archived_at,
            (SELECT MIN(l.odoo_employee_id) FROM contact_odoo_links l WHERE l.contact_id = c.contact_id) AS odoo_employee_id,
            (SELECT MIN(d.odoo_department_id) FROM client_odoo_links d WHERE d.client_id = c.contact_client_id) AS odoo_department_id,
            ' . self::EMP_UPDATED . ' AS upd
        FROM contacts c
        WHERE c.contact_vendor_id = 0 AND c.contact_employee_type <> \'vendor\'';

    public function employees(array $q): array
    {
        $p = LmsApi::paging($q);
        [$ssql, $stypes, $sparams] = $this->scope('c.contact_client_id');
        $sql = self::EMP_SELECT . $ssql . ' AND c.contact_id > ?';
        $types = $stypes . 'i';
        $args = array_merge($sparams, [$p['after']]);
        if (isset($q['department_id'])) {
            if (!is_string($q['department_id']) || preg_match('/^[1-9][0-9]{0,9}$/D', $q['department_id']) !== 1) {
                throw new LmsApiException(400, 'invalid_department_id', 'department_id must be a department id.');
            }
            $sql .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $args[] = (int) $q['department_id'];
        }
        if (isset($q['odoo_employee_id'])) {
            if (!is_string($q['odoo_employee_id']) || preg_match('/^[1-9][0-9]{0,9}$/D', $q['odoo_employee_id']) !== 1) {
                throw new LmsApiException(400, 'invalid_odoo_employee_id', 'odoo_employee_id must be an Odoo employee id.');
            }
            $sql .= ' AND c.contact_id IN (SELECT lf.contact_id FROM contact_odoo_links lf WHERE lf.odoo_employee_id = ?)';
            $types .= 'i';
            $args[] = (int) $q['odoo_employee_id'];
        }
        if ($p['since_local'] !== null) {
            $sql .= ' AND ' . self::EMP_UPDATED . ' >= ?';
            $types .= 's';
            $args[] = $p['since_local'];
        }
        $rows = Db::all($this->db, $sql . ' ORDER BY c.contact_id LIMIT ' . ($p['limit'] + 1), $types, $args);
        $more = count($rows) > $p['limit'];
        $rows = array_slice($rows, 0, $p['limit']);
        $managers = $this->employeesInScope(array_column($rows, 'contact_manager_id'));
        $next = $more && $rows !== [] ? IntegrationAuth::b64uEncode(json_encode(['after' => (int) end($rows)['contact_id']])) : null;
        return ['data' => array_map(fn(array $r) => $this->shapeEmployee($r, $managers), $rows), 'next_cursor' => $next];
    }

    public function employee(int $id): ?array
    {
        [$ssql, $stypes, $sparams] = $this->scope('c.contact_client_id');
        $r = Db::one($this->db, self::EMP_SELECT . $ssql . ' AND c.contact_id = ?', $stypes . 'i', array_merge($sparams, [$id]));
        return $r === null ? null : $this->shapeEmployee($r, $this->employeesInScope([$r['contact_manager_id']]));
    }

    private function shapeEmployee(array $r, array $managersInScope): array
    {
        $status = (string) $r['contact_employment_status'];
        $mgr = (int) ($r['contact_manager_id'] ?? 0);
        $dept = (int) $r['contact_client_id'];
        return [
            'id' => (string) (int) $r['contact_id'],
            'odoo_employee_id' => $r['odoo_employee_id'] === null ? null : (string) (int) $r['odoo_employee_id'],
            'name' => (string) $r['contact_name'],
            'work_email' => ($r['contact_email'] ?? '') === '' ? null : (string) $r['contact_email'],
            'job_title' => ($r['contact_title'] ?? '') === '' ? null : (string) $r['contact_title'],
            'department_id' => $dept > 0 ? (string) $dept : null,
            'odoo_department_id' => $r['odoo_department_id'] === null ? null : (string) (int) $r['odoo_department_id'],
            'manager_id' => $mgr > 0 && isset($managersInScope[$mgr]) ? (string) $mgr : null,
            'employment_status' => $status,
            'active' => $r['contact_archived_at'] === null && !in_array($status, self::INACTIVE_EMPLOYMENT, true),
            'updated_at' => self::iso($r['upd']),
        ];
    }

    // ---- departments -----------------------------------------------------------------------------------------------

    private const DEPT_UPDATED = "GREATEST(COALESCE(c.client_updated_at, c.client_created_at), c.client_created_at,
            COALESCE(c.client_archived_at, '" . self::EPOCH . "'),
            COALESCE((SELECT MAX(lu.updated_at) FROM client_odoo_links lu WHERE lu.client_id = c.client_id), '" . self::EPOCH . "'))";

    private const DEPT_SELECT = 'SELECT c.client_id, c.client_name, c.client_parent_id, c.client_head_contact_id, c.client_status, c.client_archived_at,
            (SELECT MIN(l.odoo_department_id) FROM client_odoo_links l WHERE l.client_id = c.client_id) AS odoo_department_id,
            ' . self::DEPT_UPDATED . ' AS upd
        FROM clients c
        WHERE c.client_lead = 0';

    public function departments(array $q): array
    {
        $p = LmsApi::paging($q);
        [$ssql, $stypes, $sparams] = $this->scope('c.client_id');
        $sql = self::DEPT_SELECT . $ssql . ' AND c.client_id > ?';
        $types = $stypes . 'i';
        $args = array_merge($sparams, [$p['after']]);
        if ($p['since_local'] !== null) {
            $sql .= ' AND ' . self::DEPT_UPDATED . ' >= ?';
            $types .= 's';
            $args[] = $p['since_local'];
        }
        $rows = Db::all($this->db, $sql . ' ORDER BY c.client_id LIMIT ' . ($p['limit'] + 1), $types, $args);
        $more = count($rows) > $p['limit'];
        $rows = array_slice($rows, 0, $p['limit']);
        $next = $more && $rows !== [] ? IntegrationAuth::b64uEncode(json_encode(['after' => (int) end($rows)['client_id']])) : null;
        [$parents, $heads] = $this->departmentRefs($rows);
        return ['data' => array_map(fn(array $r) => $this->shapeDepartment($r, $parents, $heads), $rows), 'next_cursor' => $next];
    }

    public function department(int $id): ?array
    {
        [$ssql, $stypes, $sparams] = $this->scope('c.client_id');
        $r = Db::one($this->db, self::DEPT_SELECT . $ssql . ' AND c.client_id = ?', $stypes . 'i', array_merge($sparams, [$id]));
        if ($r === null) {
            return null;
        }
        [$parents, $heads] = $this->departmentRefs([$r]);
        return $this->shapeDepartment($r, $parents, $heads);
    }

    /** @return array{0:array,1:array} in-scope parent departments and head employees referenced by the rows */
    private function departmentRefs(array $rows): array
    {
        return [$this->departmentsInScope(array_column($rows, 'client_parent_id')), $this->employeesInScope(array_column($rows, 'client_head_contact_id'))];
    }

    private function shapeDepartment(array $r, array $parentsInScope, array $headsInScope): array
    {
        $archived = $r['client_archived_at'] !== null;
        $status = $archived ? 'archived' : strtolower(str_replace(' ', '_', trim((string) $r['client_status'])));
        $parent = (int) ($r['client_parent_id'] ?? 0);
        $head = (int) ($r['client_head_contact_id'] ?? 0);
        return [
            'id' => (string) (int) $r['client_id'],
            'odoo_department_id' => $r['odoo_department_id'] === null ? null : (string) (int) $r['odoo_department_id'],
            'name' => (string) $r['client_name'],
            'parent_id' => $parent > 0 && $parent !== (int) $r['client_id'] && isset($parentsInScope[$parent]) ? (string) $parent : null,
            'head_employee_id' => $head > 0 && isset($headsInScope[$head]) ? (string) $head : null,
            'status' => $status,
            'active' => !$archived && $status !== 'inactive',
            'updated_at' => self::iso($r['upd']),
        ];
    }

    // ---- shared ----------------------------------------------------------------------------------------------------

    /** A company-local DATETIME string as UTC ISO 8601 with milliseconds (same shape as the LMS updated_at). */
    private static function iso(?string $v): ?string
    {
        $iso = Clock::toIso($v, false);
        return $iso === null ? null : (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }
}
