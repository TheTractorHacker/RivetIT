<?php

namespace ITFlow\Automation;

/**
 * Adds look-up fields to an event's flat context so conditions and {placeholders} can use more than the keys the emitting code happened to
 * put in the payload: the department, location, asset type and user group of what the event is about.
 *
 *   asset_id    -> asset_name, asset_type, asset_status, asset_make, asset_model, asset_serial_number is NOT added (not needed, could be sensitive)
 *   contact_id  -> contact_name, contact_email, contact_department, job_title, employment_status, employee_type, user_group, user_role
 *   client_id   -> department (the department name; "client" and "department" are the same thing in this app) and client_name
 *   location_id -> location_name
 *
 * Rules: an existing key is NEVER overwritten (the event's own value wins), nothing here is read from the event except ids (the values come
 * from the database), values are plain strings, and a missing row or table adds nothing. Read only; callers do this once per event, before
 * rules are matched, and only when something listens, so an install with no rules pays nothing.
 *
 * `user_group` is the names of the job groups (Administration > Training) the person was added to, comma separated, so "user_group contains Finance" works.
 */
final class PayloadEnricher
{
    /** Fields this class can add, for the editor's field hints and the docs. @return array<string,string> field => meaning */
    public const FIELDS = [
        'department' => 'Department name (the client of the thing the event is about)',
        'client_name' => 'Same as department',
        'location_id' => 'Site / location id',
        'location_name' => 'Site / location name',
        'asset_name' => 'Asset name',
        'asset_type' => 'Asset type (Laptop, Server, ...)',
        'asset_status' => 'Asset status (Deployed, Ready to deploy, ...)',
        'asset_make' => 'Asset make',
        'asset_model' => 'Asset model',
        'contact_name' => 'Person name',
        'contact_email' => 'Person email',
        'contact_department' => 'Department / team text on the person record',
        'job_title' => 'Person job title',
        'employment_status' => 'pre-hire, active, leave, suspended, termination_pending, terminated, archived',
        'employee_type' => 'Employee type (employee, contractor, ...)',
        'user_group' => 'Job groups the person belongs to, comma separated',
        'user_role' => 'Role of the person\'s technician login, if they have one',
    ];

    /**
     * @param array<string,string> $context flat event context
     * @return array<string,string> the context plus the added fields
     */
    public static function enrich(\mysqli $mysqli, array $context): array
    {
        try {
            $add = [];
            $assetId = self::subjectId($context, 'asset');
            if ($assetId > 0) {
                $a = self::row($mysqli, 'SELECT asset_name, asset_type, asset_status, asset_make, asset_model, asset_client_id, asset_location_id, asset_contact_id FROM assets WHERE asset_id = ?', $assetId);
                if ($a) {
                    $add += ['asset_name' => (string) $a['asset_name'], 'asset_type' => (string) $a['asset_type'], 'asset_status' => (string) ($a['asset_status'] ?? ''),
                        'asset_make' => (string) ($a['asset_make'] ?? ''), 'asset_model' => (string) ($a['asset_model'] ?? '')];
                    self::defaults($add, $context, ['client_id' => (int) $a['asset_client_id'], 'location_id' => (int) $a['asset_location_id'], 'contact_id' => (int) $a['asset_contact_id']]);
                }
            }
            $contactId = self::subjectId($add + $context, 'contact');
            if ($contactId > 0) {
                $c = self::row($mysqli, 'SELECT contact_name, contact_email, contact_department, contact_title, contact_employment_status, contact_employee_type, contact_client_id, contact_location_id, contact_user_id FROM contacts WHERE contact_id = ?', $contactId);
                if ($c) {
                    $add += ['contact_name' => (string) $c['contact_name'], 'contact_email' => (string) ($c['contact_email'] ?? ''), 'contact_department' => (string) ($c['contact_department'] ?? ''),
                        'job_title' => (string) ($c['contact_title'] ?? ''), 'employment_status' => (string) $c['contact_employment_status'], 'employee_type' => (string) ($c['contact_employee_type'] ?? '')];
                    self::defaults($add, $context, ['client_id' => (int) $c['contact_client_id'], 'location_id' => (int) $c['contact_location_id']]);
                    try {
                        $groups = self::column($mysqli, 'SELECT g.jobgroup_name FROM training_job_group_members m JOIN training_job_groups g ON g.jobgroup_id = m.jgmember_jobgroup_id WHERE m.jgmember_contact_id = ? AND g.jobgroup_archived_at IS NULL ORDER BY g.jobgroup_name', $contactId);
                    } catch (\Throwable $e) {
                        $groups = []; // the training tables may not exist on an install that never used them
                    }
                    $add['user_group'] = implode(', ', $groups);
                    if ((int) $c['contact_user_id'] > 0) {
                        $r = self::row($mysqli, 'SELECT r.role_name FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ?', (int) $c['contact_user_id']);
                        $add['user_role'] = $r ? (string) $r['role_name'] : '';
                    }
                }
            }
            $clientId = self::id($add + $context, 'client_id');
            if ($clientId > 0) {
                $cl = self::row($mysqli, 'SELECT client_name FROM clients WHERE client_id = ?', $clientId);
                if ($cl) {
                    $add += ['department' => (string) $cl['client_name'], 'client_name' => (string) $cl['client_name']];
                }
            }
            $locationId = self::id($add + $context, 'location_id');
            if ($locationId > 0) {
                $l = self::row($mysqli, 'SELECT location_name FROM locations WHERE location_id = ?', $locationId);
                if ($l) {
                    $add['location_name'] = (string) $l['location_name'];
                }
            }
            foreach ($add as $k => $v) {
                if (!array_key_exists($k, $context)) {
                    $context[$k] = mb_substr((string) $v, 0, Template::MAX_VALUE);
                }
            }
        } catch (\Throwable $e) {
            // enrichment is best effort: an event must still reach its rules with what it carried
        }

        return $context;
    }

    /** Adds the id fields only when neither the event nor an earlier step has them (and the id is real). */
    private static function defaults(array &$add, array $context, array $ids): void
    {
        foreach ($ids as $k => $v) {
            if ($v > 0 && self::id($add + $context, $k) === 0) {
                $add[$k] = (string) $v;
            }
        }
    }

    /**
     * The id of the asset / contact an event is about: its own asset_id / contact_id, or the entity of an audit event (entity_type asset / contact).
     * One definition, used by the enricher and the person actions (Entra, Odoo) so they always agree on who the event is about.
     */
    public static function subjectId(array $ctx, string $type): int
    {
        $own = self::id($ctx, $type . '_id');
        if ($own > 0) {
            return $own;
        }

        return ($ctx['entity_type'] ?? '') === $type ? self::id($ctx, 'entity_id') : 0;
    }

    private static function id(array $ctx, string $key): int
    {
        return isset($ctx[$key]) && ctype_digit((string) $ctx[$key]) ? (int) $ctx[$key] : 0;
    }

    /** @return array<string,mixed>|null */
    private static function row(\mysqli $m, string $sql, int $id): ?array
    {
        $stmt = @mysqli_prepare($m, $sql);
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);

        return $row ?: null;
    }

    /** @return list<string> */
    private static function column(\mysqli $m, string $sql, int $id): array
    {
        $stmt = @mysqli_prepare($m, $sql);
        if (!$stmt) {
            return [];
        }
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = [];
        while ($res && ($r = mysqli_fetch_row($res))) {
            $out[] = (string) $r[0];
        }
        mysqli_stmt_close($stmt);

        return $out;
    }
}
