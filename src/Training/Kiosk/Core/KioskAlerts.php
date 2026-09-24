<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Db;

/**
 * Agent notifications for kiosk security events (P3 spec §3.1). FIXED texts only - never a
 * name, a PIN, a code or a connector message (§0.12). Called after COMMIT only (asserted), at
 * most once per 15 minutes per type (rate bucket 'alert:<type>').
 *
 * Recipients: active admins, plus active users whose role has module_training_kiosk >= 2.
 */
final class KioskAlerts
{
    public const TEXTS = [
        'pin_hard_lock' => 'A training PIN was locked after repeated wrong entries.',
        'kiosk_cooldown' => 'A training device paused sign-in after repeated wrong PINs.',
        'pin_pause' => 'Training sign-in was paused system-wide after repeated wrong PINs.',
        'odoo_auth' => 'Odoo rejected the training sign-in check (key or rights problem, HTTP 401/403).',
        'odoo_down' => 'Odoo did not answer training sign-in checks.',
        'odoo_repoint' => 'An employee\'s Odoo link changed; their Odoo-PIN sign-in is blocked until reviewed.',
        'trainer_slip' => 'A PIN setup slip was issued for a trainer.',
    ];

    public const ACTION_URL = '/agent/training_devices.php';

    /** @return int how many users were notified (0 when throttled) */
    public static function notify(\mysqli $db, string $type, ?int $contactId = null): int
    {
        if (!isset(self::TEXTS[$type])) {
            throw new \InvalidArgumentException("KioskAlerts: unknown type $type");
        }
        if (Db::depth() > 0) {
            throw new \LogicException('KioskAlerts::notify runs after COMMIT only');
        }
        if (!RateLimiter::hit($db, 'alert:' . $type, 900, 1)) {
            return 0;
        }
        $text = self::TEXTS[$type] . ($contactId !== null && $contactId > 0 ? ' (person #' . $contactId . ')' : '');
        $users = self::recipients($db);
        if (!function_exists('notifyUser')) {
            return 0;
        }
        $n = 0;
        foreach ($users as $uid) {
            try {
                notifyUser($uid, 'Training', $text, self::ACTION_URL);
                $n++;
            } catch (\Throwable $e) {
                error_log('Kiosk alert: ' . get_class($e));
            }
        }
        return $n;
    }

    /** @return list<int> */
    public static function recipients(\mysqli $db): array
    {
        $rows = Db::all($db, "SELECT DISTINCT u.user_id FROM users u
              JOIN user_roles r ON r.role_id = u.user_role_id
              LEFT JOIN user_role_permissions p ON p.user_role_id = r.role_id
              LEFT JOIN modules m ON m.module_id = p.module_id AND m.module_name = 'module_training_kiosk'
             WHERE u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND r.role_archived_at IS NULL
               AND (r.role_is_admin = 1 OR (m.module_id IS NOT NULL AND p.user_role_permission_level >= 2))
             ORDER BY u.user_id");
        return array_map(static fn(array $r) => (int) $r['user_id'], $rows);
    }
}
