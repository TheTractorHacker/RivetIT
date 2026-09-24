<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Core\Db;

/**
 * The one person-facts loader (Phase 2 spec §3.2): the rule matcher, compliance, reports and the
 * people pickers all read people through here, so "who is this person for Training" has one answer.
 *
 * Person = {contact_id, name, title, client_id, client_name, job_id, job_name, location_id, location_name,
 *           hire_date, employee_no, manager_id, user_id, archived, eligible, roster_state, roster_reason,
 *           jobgroup_ids, link_state, odoo_linked}
 * Department = the contact's client (contact_client_id; 0 = "no department"). Job and work location come
 * from contact_odoo_attributes (the Odoo extension of the directory sync). Hire date is
 * contacts.contact_start_date (authoritative; §1.4 #6).
 */
final class Directory
{
    private const COLS = "c.contact_id, c.contact_name, c.contact_title, c.contact_client_id, cl.client_name, c.contact_start_date,
        c.contact_employee_id, c.contact_manager_id, c.contact_user_id, c.contact_archived_at,
        COALESCE(tr_r.roster_state, 'auto') AS roster_state, tr_r.roster_reason,
        a.coattr_job_id, a.coattr_job_name, a.coattr_work_location_id, a.coattr_work_location_name, a.coattr_link_state,
        EXISTS (SELECT 1 FROM contact_odoo_links l WHERE l.contact_id = c.contact_id) AS odoo_linked";

    private const FROM = ' FROM contacts c
        LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
        ' . Roster::JOIN . '
        LEFT JOIN contact_odoo_attributes a ON a.coattr_contact_id = c.contact_id';

    /**
     * @param list<int>|null $contactIds null = everyone in scope
     * @return array<int, array> keyed by contact_id, ascending
     */
    public static function load(\mysqli $db, Scope $s, ?array $contactIds = null, bool $includeIneligible = false): array
    {
        if ($s->isNone()) {
            return [];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $where = 'WHERE 1=1' . $scopeSql;
        if ($contactIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn($i) => $i > 0)));
            if ($ids === []) {
                return [];
            }
            $where .= ' AND c.contact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $types .= str_repeat('i', count($ids));
            array_push($params, ...$ids);
        }
        if (!$includeIneligible) {
            $where .= ' AND ' . Roster::ELIGIBLE;
        }
        $rows = Db::all($db, 'SELECT ' . self::COLS . ', (' . Roster::ELIGIBLE . ') AS eligible' . self::FROM . " $where ORDER BY c.contact_id", $types, $params);
        return self::shape($db, $rows);
    }

    /**
     * Eligible, in-scope people whose name, title or department matches $q (utf8mb4_general_ci LIKE),
     * best matches (name prefix) first.
     *
     * @return list<array>
     */
    public static function search(\mysqli $db, Scope $s, string $q, int $limit = 20): array
    {
        $q = trim($q);
        if ($s->isNone() || $q === '') {
            return [];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $prefix = addcslashes($q, '%_\\') . '%';
        $rows = Db::all($db, 'SELECT ' . self::COLS . ', 1 AS eligible' . self::FROM . '
            WHERE ' . Roster::ELIGIBLE . $scopeSql . ' AND (c.contact_name LIKE ? OR c.contact_title LIKE ? OR cl.client_name LIKE ? OR c.contact_employee_id = ?)
            ORDER BY (c.contact_name LIKE ?) DESC, c.contact_name, c.contact_id
            LIMIT ?', $types . 'sssssi', array_merge($params, [$like, $like, $like, $q, $prefix, max(1, min(50, $limit))]));
        return array_values(self::shape($db, $rows));
    }

    /** PersonRef (spec §4.1) from a person. */
    public static function ref(array $p): array
    {
        return [
            'contact_id' => (int) $p['contact_id'],
            'name' => (string) $p['name'],
            'department' => (int) $p['client_id'] > 0 ? ['id' => (int) $p['client_id'], 'name' => $p['client_name']] : null,
            'title' => $p['title'],
            'job' => $p['job_id'] !== null ? ['id' => (int) $p['job_id'], 'name' => $p['job_name']] : null,
            'location' => $p['location_id'] !== null ? ['id' => (int) $p['location_id'], 'name' => $p['location_name']] : null,
            'hire_date' => $p['hire_date'],
            'employee_no' => $p['employee_no'],
            'archived' => (bool) $p['archived'],
        ];
    }

    /** @return array<int, array> */
    private static function shape(\mysqli $db, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $groups = JobGroupService::membership($db);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['contact_id'];
            $out[$id] = [
                'contact_id' => $id,
                'name' => (string) $r['contact_name'],
                'title' => $r['contact_title'] === null || trim((string) $r['contact_title']) === '' ? null : (string) $r['contact_title'],
                'client_id' => (int) $r['contact_client_id'],
                'client_name' => $r['client_name'] === null ? null : (string) $r['client_name'],
                'job_id' => $r['coattr_job_id'] === null ? null : (int) $r['coattr_job_id'],
                'job_name' => $r['coattr_job_name'] === null ? null : (string) $r['coattr_job_name'],
                'location_id' => $r['coattr_work_location_id'] === null ? null : (int) $r['coattr_work_location_id'],
                'location_name' => $r['coattr_work_location_name'] === null ? null : (string) $r['coattr_work_location_name'],
                'hire_date' => $r['contact_start_date'] === null ? null : (string) $r['contact_start_date'],
                'employee_no' => $r['contact_employee_id'] === null || $r['contact_employee_id'] === '' ? null : (string) $r['contact_employee_id'],
                'manager_id' => $r['contact_manager_id'] === null ? null : (int) $r['contact_manager_id'],
                'user_id' => (int) $r['contact_user_id'] > 0 ? (int) $r['contact_user_id'] : null,
                'archived' => $r['contact_archived_at'] !== null,
                'eligible' => (int) $r['eligible'] === 1,
                'roster_state' => (string) $r['roster_state'],
                'roster_reason' => $r['roster_reason'] === null ? null : (string) $r['roster_reason'],
                'jobgroup_ids' => $groups[$id] ?? [],
                'link_state' => $r['coattr_link_state'] === null ? null : (string) $r['coattr_link_state'],
                'odoo_linked' => (int) $r['odoo_linked'] === 1,
            ];
        }
        return $out;
    }
}
