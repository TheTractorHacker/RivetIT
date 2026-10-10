<?php

namespace ITFlow\Recovery;

/**
 * Small key/value settings for the recovery features (restore drill, backup/sync alerts), kept in recovery_settings
 * (DB 2.6.153) instead of settings columns because the settings table is close to the row-size limit. Every read is
 * defensive: on an install that has not run the migration yet, defaults are returned and nothing throws.
 */
final class RecoverySettings
{
    /** key => default. Secrets (the drill DB password) are stored encrypted by the caller, never plain. */
    public const DEFAULTS = [
        'drill_enabled'            => '0',       // OFF until the scoped drill_% database account exists
        'drill_db_host'            => '',        // blank: the application's own database host
        'drill_db_user'            => '',
        'drill_db_pass'            => '',        // stored via encryptSetting() when available
        'drill_tolerance_pct'      => '5',       // row-count tolerance against the snapshot stored in the backup
        'drill_passphrase_file'    => '/etc/itflow/backup-passphrase', // only consulted by deploy/restore_drill.sh (root)
        'drill_max_age_days'       => '35',      // Compliance check: a restore must have been proven this recently
        'backup_stale_hours'       => '26',      // alert when the newest successful backup is older than this
        'sync_error_threshold'    => '3',       // alert after this many consecutive errored sync runs
        'sync_interval_rmm'        => '15',      // minutes; stale = no run in 3x. 0 turns the watch off for that source
        'sync_interval_intune'     => '15',
        'sync_interval_unifi'      => 'auto',    // 'auto' = infer from its own scheduled runs; a number = minutes; 0 = off
        'alert_email'              => '',        // blank: every active administrator
    ];

    public static function ready(\mysqli $db): bool
    {
        static $cache = [];
        $key = spl_object_id($db);
        if (!isset($cache[$key])) {
            $r = @mysqli_query($db, "SHOW TABLES LIKE 'recovery_settings'");
            $cache[$key] = $r && mysqli_num_rows($r) > 0;
        }

        return $cache[$key];
    }

    public static function get(\mysqli $db, string $key): string
    {
        $default = self::DEFAULTS[$key] ?? '';
        if (!self::ready($db)) {
            return $default;
        }
        $stmt = mysqli_prepare($db, 'SELECT setting_value FROM recovery_settings WHERE setting_key = ?');
        if (!$stmt) {
            return $default;
        }
        mysqli_stmt_bind_param($stmt, 's', $key);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $value);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        return $found && $value !== null ? (string) $value : $default;
    }

    public static function int(\mysqli $db, string $key): int
    {
        return (int) self::get($db, $key);
    }

    public static function set(\mysqli $db, string $key, string $value): bool
    {
        if (!array_key_exists($key, self::DEFAULTS) || !self::ready($db)) {
            return false;
        }
        $stmt = mysqli_prepare($db, 'INSERT INTO recovery_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    /** The drill DB password, decrypted. Falls back to a plain value (older/manual rows) when it is not an encrypted blob. */
    public static function drillPassword(\mysqli $db): string
    {
        $raw = self::get($db, 'drill_db_pass');
        if ($raw !== '' && function_exists('decryptSetting')) {
            return decryptSetting($raw);
        }

        return $raw;
    }
}
