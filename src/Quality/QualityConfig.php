<?php

namespace ITFlow\Quality;

/**
 * Quality module settings and the one server-side gate.
 *
 * The module switch is settings.config_module_enable_quality (default 0). It is read from the database on every call (never from a cached
 * global), so switching it off takes effect at the next request, exactly like the SSO provider's kill switch. Everything that serves Quality
 * (portal pages, the inbound API route, the cron worker, the SSO policy, navigation) asks {@see moduleOn()} or {@see access()}; hiding a menu entry is only
 * a convenience on top of that.
 *
 * Switching the module off keeps every setting, mapping, event and log row; it stops the work only. The Training module (the LMS) is never touched.
 */
final class QualityConfig
{
    /** feature key => label shown on the admin page and used as the portal tab name */
    public const FEATURES = [
        'tasks'         => 'My tasks',
        'documents'     => 'Documents to acknowledge',
        'training'      => 'Required training',
        'actions'       => 'Corrective actions',
        'notifications' => 'Notifications',
        'history'       => 'History',
        'reports'       => 'Department reporting (managers)',
        'kiosk'         => 'Kiosk: counts, training and acknowledgments',
    ];

    public const STREAMS = ['assignments', 'completions', 'employees', 'departments'];

    public static function ready(\mysqli $db): bool
    {
        static $ok = false;
        if ($ok) {
            return true;
        }
        try {
            $r = $db->query("SHOW TABLES LIKE 'quality_settings'");
            $has = $r && $r->num_rows > 0;
            $c = $db->query("SHOW COLUMNS FROM settings LIKE 'config_module_enable_quality'");
            $ok = $has && $c && $c->num_rows > 0;
        } catch (\Throwable) {
            $ok = false;
        }
        return $ok;
    }

    /** The module switch. False before the database update, so nothing of Quality is reachable on an installation that has not taken it. */
    public static function moduleOn(\mysqli $db): bool
    {
        if (!self::ready($db)) {
            return false;
        }
        try {
            $r = $db->query('SELECT config_module_enable_quality AS m FROM settings WHERE company_id = 1');
            return $r && (int) ($r->fetch_assoc()['m'] ?? 0) === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function setModule(\mysqli $db, bool $on): void
    {
        $v = $on ? 1 : 0;
        $db->query("UPDATE settings SET config_module_enable_quality = $v WHERE company_id = 1");
    }

    /** @return array<string,mixed> the single settings row, with defaults when the table is not there yet */
    public static function load(\mysqli $db): array
    {
        $defaults = ['qs_qms_url' => null, 'qs_token_url' => null, 'qs_client_id' => null, 'qs_client_secret_enc' => null, 'qs_scope' => 'quality:read quality:write',
            'qs_outbound_secret_enc' => null, 'qs_inbound_secret_enc' => null, 'qs_token_enc' => null, 'qs_token_expires_at' => null, 'qs_all_departments' => 0,
            'qs_events_enabled' => 0, 'qs_inbound_enabled' => 0, 'qs_sync_interval_minutes' => 5, 'qs_reconcile_interval_hours' => 24, 'qs_reauth_minutes' => 15,
            'qs_connect_timeout_s' => 3, 'qs_total_timeout_s' => 10, 'qs_last_test_at' => null, 'qs_last_test_ok' => null, 'qs_last_test_ms' => null,
            'qs_last_test_message' => null, 'qs_last_ok_at' => null];
        if (!self::ready($db)) {
            return $defaults;
        }
        $r = $db->query('SELECT * FROM quality_settings WHERE qs_id = 1');
        $row = $r ? $r->fetch_assoc() : null;
        return is_array($row) ? $row + $defaults : $defaults;
    }

    /** A column of quality_settings. Column names are fixed by the caller (never request data). */
    public static function set(\mysqli $db, array $values, int $userId = 0): void
    {
        $allowed = ['qs_qms_url' => 's', 'qs_token_url' => 's', 'qs_client_id' => 's', 'qs_client_secret_enc' => 's', 'qs_scope' => 's', 'qs_outbound_secret_enc' => 's',
            'qs_inbound_secret_enc' => 's', 'qs_token_enc' => 's', 'qs_token_expires_at' => 's', 'qs_all_departments' => 'i', 'qs_events_enabled' => 'i',
            'qs_inbound_enabled' => 'i', 'qs_sync_interval_minutes' => 'i', 'qs_reconcile_interval_hours' => 'i', 'qs_reauth_minutes' => 'i', 'qs_connect_timeout_s' => 'i',
            'qs_total_timeout_s' => 'i', 'qs_last_test_at' => 's', 'qs_last_test_ok' => 'i', 'qs_last_test_ms' => 'i', 'qs_last_test_message' => 's', 'qs_last_ok_at' => 's'];
        $sets = [];
        $types = '';
        $args = [];
        foreach ($values as $col => $val) {
            if (!isset($allowed[$col])) {
                throw new \InvalidArgumentException('Unknown setting');
            }
            $sets[] = "$col = ?";
            $types .= $allowed[$col];
            $args[] = $val;
        }
        if ($sets === []) {
            return;
        }
        if ($userId > 0) {
            $sets[] = 'qs_updated_by_user_id = ?';
            $types .= 'i';
            $args[] = $userId;
        }
        $st = $db->prepare('UPDATE quality_settings SET ' . implode(', ', $sets) . ' WHERE qs_id = 1');
        $st->bind_param($types, ...$args);
        $st->execute();
        $st->close();
    }

    public static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    // ---- secrets (sealed with encryptSetting, generic context; registered in ColumnRegistry) --------------------------------------------

    public static function secret(array $row, string $col): string
    {
        $v = (string) ($row[$col] ?? '');
        return $v === '' ? '' : decryptSetting($v);
    }

    public static function sealSecret(string $plain): string
    {
        return $plain === '' ? '' : encryptSetting($plain);
    }

    /** A new signing secret: shown to the administrator once, then only its sealed form is kept. */
    public static function newSigningSecret(): string
    {
        return 'qsk_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** True when the connection has everything a call needs (address, client id and client secret). */
    public static function configured(array $row): bool
    {
        return trim((string) ($row['qs_qms_url'] ?? '')) !== '' && trim((string) ($row['qs_client_id'] ?? '')) !== '' && (string) ($row['qs_client_secret_enc'] ?? '') !== '';
    }

    // ---- features and departments --------------------------------------------------------------------------------------------------------

    /** @return array<string,bool> every known feature key => enabled (all false when the table is empty) */
    public static function features(\mysqli $db): array
    {
        $out = array_fill_keys(array_keys(self::FEATURES), false);
        if (!self::ready($db)) {
            return $out;
        }
        $r = $db->query('SELECT qf_key, qf_enabled FROM quality_features');
        while ($r && ($f = $r->fetch_assoc())) {
            if (array_key_exists($f['qf_key'], $out)) {
                $out[$f['qf_key']] = (int) $f['qf_enabled'] === 1;
            }
        }
        return $out;
    }

    /** A feature is on only while the module is on. */
    public static function featureOn(\mysqli $db, string $key): bool
    {
        return self::moduleOn($db) && (self::features($db)[$key] ?? false);
    }

    public static function setFeatures(\mysqli $db, array $enabledKeys): void
    {
        foreach (array_keys(self::FEATURES) as $k) {
            $v = in_array($k, $enabledKeys, true) ? 1 : 0;
            $st = $db->prepare('INSERT INTO quality_features (qf_key, qf_enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE qf_enabled = VALUES(qf_enabled)');
            $st->bind_param('si', $k, $v);
            $st->execute();
            $st->close();
        }
    }

    /** @return list<int> */
    public static function departmentIds(\mysqli $db): array
    {
        if (!self::ready($db)) {
            return [];
        }
        $r = $db->query('SELECT qd_client_id FROM quality_departments ORDER BY qd_client_id');
        $out = [];
        while ($r && ($row = $r->fetch_row())) {
            $out[] = (int) $row[0];
        }
        return $out;
    }

    public static function setDepartments(\mysqli $db, array $clientIds, int $userId): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $clientIds), static fn(int $i) => $i > 0)));
        $db->begin_transaction();
        try {
            $keep = $ids === [] ? '0' : implode(',', $ids);
            $db->query("DELETE FROM quality_departments WHERE qd_client_id NOT IN ($keep)");
            foreach ($ids as $id) {
                $st = $db->prepare('INSERT IGNORE INTO quality_departments (qd_client_id, qd_enabled_by_user_id) VALUES (?, ?)');
                $st->bind_param('ii', $id, $userId);
                $st->execute();
                $st->close();
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    public static function departmentEnabled(\mysqli $db, int $clientId): bool
    {
        if ($clientId <= 0 || !self::ready($db)) {
            return false;
        }
        if ((int) (self::load($db)['qs_all_departments'] ?? 0) === 1) {
            return true;
        }
        $st = $db->prepare('SELECT 1 FROM quality_departments WHERE qd_client_id = ?');
        $st->bind_param('i', $clientId);
        $st->execute();
        $has = $st->get_result()->num_rows > 0;
        $st->close();
        return $has;
    }

    // ---- who may use Quality -----------------------------------------------------------------------------------------------------------

    /**
     * May this department person use Quality, and as what? Fail closed: any doubt is a refusal with a short reason code for the log (the person
     * only ever sees a generic message). The module switch and the department list are checked HERE and nowhere is a cached answer used.
     *
     * The caller still has to establish who the contact is (the portal session, or the SSO policy's own checks for an active, enabled, portal-linked
     * person). This function adds only what Quality adds on top: module on, department enabled, and the manager rule.
     *
     * @return array{ok:bool, reason:string, client_id:int, manager:bool}
     */
    public static function access(\mysqli $db, int $contactId): array
    {
        $no = static fn(string $why): array => ['ok' => false, 'reason' => $why, 'client_id' => 0, 'manager' => false];
        if (!self::moduleOn($db)) {
            return $no('module_disabled');
        }
        if ($contactId <= 0) {
            return $no('contact_not_found');
        }
        $st = $db->prepare('SELECT contact_client_id, contact_primary, contact_technical, contact_portal_role FROM contacts WHERE contact_id = ?');
        $st->bind_param('i', $contactId);
        $st->execute();
        $c = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$c) {
            return $no('contact_not_found');
        }
        $client = (int) $c['contact_client_id'];
        if (!self::departmentEnabled($db, $client)) {
            return $no('department_not_enabled');
        }
        // Same rule the portal's Training page uses for "sees the whole department".
        $manager = (int) $c['contact_primary'] === 1 || (int) $c['contact_technical'] === 1 || (string) $c['contact_portal_role'] === 'manager';
        return ['ok' => true, 'reason' => 'ok', 'client_id' => $client, 'manager' => $manager];
    }

    /**
     * For the portal's navigation and tiles: the access result when this portal login (contact + department of the session) may use Quality, else null.
     * Fails closed on every doubt (module off, department not enabled, session department differs from the contact's own, no contact).
     *
     * @return array{ok:bool, reason:string, client_id:int, manager:bool, pages:bool}|null
     */
    public static function portalAccess(\mysqli $db, int $contactId, int $sessionClientId): ?array
    {
        if ($contactId <= 0 || !self::moduleOn($db)) {
            return null;
        }
        $a = self::access($db, $contactId);
        if (!$a['ok'] || $a['client_id'] !== $sessionClientId) {
            return null;
        }
        $f = self::features($db);
        $pages = false;
        foreach (['tasks', 'actions', 'documents', 'training', 'notifications', 'history', 'reports'] as $k) {
            $pages = $pages || $f[$k];
        }
        return $a + ['pages' => $pages];
    }
}
