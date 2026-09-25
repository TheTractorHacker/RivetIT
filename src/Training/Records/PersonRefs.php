<?php

namespace ITFlow\Training\Records;

use ITFlow\Training\Core\Db;

/**
 * PersonRef (Phase 2 spec §4.1) for the records engine's responses (Lane C internal):
 *   {contact_id, name, department:{id,name}|null, title, job:{id,name}|null, location:{id,name}|null,
 *    hire_date, employee_no, archived:bool}
 *
 * Callers pass ids they have already authorized (Scope); this only loads. Odoo job and
 * location come from contact_odoo_attributes when the 2.6.92 table is there.
 */
final class PersonRefs
{
    /**
     * @param list<int> $ids
     * @return array<int, array> contact_id => PersonRef (missing contacts are absent)
     */
    public static function load(\mysqli $db, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i) => $i > 0)));
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::all($db, "SELECT c.contact_id, c.contact_name, c.contact_title, c.contact_client_id, cl.client_name,
                    c.contact_start_date, c.contact_employee_id, c.contact_archived_at,
                    a.coattr_job_id, a.coattr_job_name, a.coattr_work_location_id, a.coattr_work_location_name
                FROM contacts c
                LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
                LEFT JOIN contact_odoo_attributes a ON a.coattr_contact_id = c.contact_id
                WHERE c.contact_id IN ($in)", str_repeat('i', count($chunk)), $chunk);
            foreach ($rows as $r) {
                $out[(int) $r['contact_id']] = self::fromRow($r);
            }
        }
        return $out;
    }

    public static function fromRow(array $r): array
    {
        $clientId = (int) ($r['contact_client_id'] ?? 0);
        $str = static fn($v): ?string => ($v === null || $v === '') ? null : (string) $v;
        return [
            'contact_id' => (int) $r['contact_id'],
            'name' => (string) ($r['contact_name'] ?? ''),
            'department' => $clientId > 0 ? ['id' => $clientId, 'name' => (string) ($r['client_name'] ?? '')] : null,
            'title' => $str($r['contact_title'] ?? null),
            'job' => isset($r['coattr_job_id']) && $r['coattr_job_id'] !== null
                ? ['id' => (int) $r['coattr_job_id'], 'name' => (string) ($r['coattr_job_name'] ?? '')] : null,
            'location' => isset($r['coattr_work_location_id']) && $r['coattr_work_location_id'] !== null
                ? ['id' => (int) $r['coattr_work_location_id'], 'name' => (string) ($r['coattr_work_location_name'] ?? '')] : null,
            'hire_date' => $str($r['contact_start_date'] ?? null),
            'employee_no' => $str($r['contact_employee_id'] ?? null),
            'archived' => ($r['contact_archived_at'] ?? null) !== null,
        ];
    }

    /** user_id => user_name for display ("recorded by"). */
    public static function userNames(\mysqli $db, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $i) => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all($db, "SELECT user_id, user_name FROM users WHERE user_id IN ($in)", str_repeat('i', count($chunk)), $chunk) as $u) {
                $out[(int) $u['user_id']] = (string) $u['user_name'];
            }
        }
        return $out;
    }
}
