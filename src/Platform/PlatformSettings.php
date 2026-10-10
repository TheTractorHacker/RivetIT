<?php

declare(strict_types=1);

namespace ITFlow\Platform;

/**
 * Small key/value switches kept in the platform_settings table (settings itself is close to the row-size limit, so new switches do
 * not become settings columns). Reads fall back to the default when the table is missing (between a code update and the DB update).
 */
final class PlatformSettings
{
    /** @var array<string,string> key => value, in code so the admin page and the cron agree on the names and defaults */
    public const DEFAULTS = [
        'stale_asset_retire_enabled' => '0',   // off by default: nothing is retired until an administrator turns this on
        'stale_asset_retire_days' => '90',
        'audit_sink_path' => '',               // JSON-lines file; empty = off
        'audit_sink_syslog' => '0',
        'audit_chain_seal_enabled' => '1',
        'doc_review_reminders' => '1',
    ];

    public static function get(\mysqli $db, string $key, ?string $default = null): string
    {
        $default ??= self::DEFAULTS[$key] ?? '';
        $k = mysqli_real_escape_string($db, $key);
        try {
            $r = mysqli_query($db, "SELECT setting_value FROM platform_settings WHERE setting_key = '$k' LIMIT 1");
            $row = $r ? mysqli_fetch_row($r) : null;
        } catch (\Throwable $e) {
            $row = null;   // the table is not there yet (code updated, database not): the default
        }

        return $row && $row[0] !== null ? (string) $row[0] : $default;
    }

    public static function set(\mysqli $db, string $key, string $value): void
    {
        $k = mysqli_real_escape_string($db, substr($key, 0, 60));
        $v = mysqli_real_escape_string($db, $value);
        mysqli_query($db, "INSERT INTO platform_settings (setting_key, setting_value) VALUES ('$k', '$v') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }

    public static function bool(\mysqli $db, string $key): bool
    {
        return self::get($db, $key) === '1';
    }

    public static function int(\mysqli $db, string $key, int $min, int $max): int
    {
        return max($min, min($max, (int) self::get($db, $key)));
    }
}
