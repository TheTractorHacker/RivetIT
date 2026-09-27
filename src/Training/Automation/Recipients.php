<?php

namespace ITFlow\Training\Automation;

use ITFlow\Training\Core\Db;

/**
 * Who Phase 5 notifies (spec §3.2), from the database - never from session globals.
 * Active agents: users.user_type = 1, user_status = 1, not archived, role not archived.
 * level = 3 for an admin role, else the role's module_training level (0 when not granted).
 */
final class Recipients
{
    /** @return list<array{user_id:int, name:string, level:int, is_admin:bool}> level >= $minLevel, by user id */
    public static function withLevel(\mysqli $db, int $minLevel): array
    {
        $rows = Db::all($db, "SELECT u.user_id, u.user_name, r.role_is_admin, COALESCE(p.user_role_permission_level, 0) AS lvl
            FROM users u
            JOIN user_roles r ON r.role_id = u.user_role_id AND r.role_archived_at IS NULL
            LEFT JOIN modules m ON m.module_name = 'module_training'
            LEFT JOIN user_role_permissions p ON p.user_role_id = r.role_id AND p.module_id = m.module_id
            WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL
            ORDER BY u.user_id");
        $out = [];
        foreach ($rows as $r) {
            $admin = (int) $r['role_is_admin'] === 1;
            $level = $admin ? 3 : max(0, min(3, (int) $r['lvl']));
            if ($level >= $minLevel && $level >= 1) {
                $out[] = ['user_id' => (int) $r['user_id'], 'name' => (string) $r['user_name'], 'level' => $level, 'is_admin' => $admin];
            }
        }
        return $out;
    }

    /** Active admins only (Odoo, key-expiry and verify-integrity alerts). */
    public static function admins(\mysqli $db): array
    {
        return array_values(array_filter(self::withLevel($db, 3), static fn($r) => $r['is_admin']));
    }
}
