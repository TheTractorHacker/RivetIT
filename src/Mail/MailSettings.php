<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Mail intake settings. Stored in mail_intake_settings (key/value) rather than as `settings` columns, because the
 * settings table is close to MariaDB's row-size limit. Anything not saved falls back to DEFAULTS.
 */
final class MailSettings
{
    public const DEFAULTS = [
        'rate_cap_per_hour'          => '20',    // inbound messages per sender per hour before quarantine
        'poison_max_attempts'        => '3',     // failures before a message is moved to quarantine
        'max_attachment_mb'          => '25',    // per file
        'max_message_attachments_mb' => '50',    // per message
        'inline_cid_max_kb'          => '1024',  // inline images larger than this become attachments
        'clamav_enabled'             => '0',     // scan attachments with clamdscan when installed
        'alert_email'                => '',      // blank: all admin users
        'alert_dedupe_hours'         => '6',
        'mailbox_fail_threshold'     => '3',     // consecutive failed polls before an alert
        'poller_silent_minutes'      => '15',
        'outbound_rate_per_min'      => '120',
    ];

    /** Keys that hold whole numbers, with inclusive bounds, for validation of admin input. */
    public const NUMERIC = [
        'rate_cap_per_hour' => [1, 100000], 'poison_max_attempts' => [1, 50], 'max_attachment_mb' => [1, 1024],
        'max_message_attachments_mb' => [1, 4096], 'inline_cid_max_kb' => [0, 102400], 'clamav_enabled' => [0, 1],
        'alert_dedupe_hours' => [1, 720], 'mailbox_fail_threshold' => [1, 1000], 'poller_silent_minutes' => [5, 10080],
        'outbound_rate_per_min' => [1, 100000],
    ];

    /** @var array<string, string>|null */
    private static ?array $cache = null;
    private static ?\mysqli $cacheFor = null;

    /** @return array<string, string> every key with its effective value */
    public static function all(\mysqli $db): array
    {
        if (self::$cache !== null && self::$cacheFor === $db) {
            return self::$cache;
        }
        $values = self::DEFAULTS;
        try {
            $res = $db->query('SELECT setting_key, setting_value FROM mail_intake_settings');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    if (array_key_exists($row['setting_key'], self::DEFAULTS)) {
                        $values[$row['setting_key']] = (string) $row['setting_value'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Table missing (update not run yet): defaults.
        }
        self::$cache = $values;
        self::$cacheFor = $db;
        return $values;
    }

    public static function get(\mysqli $db, string $key): string
    {
        return self::all($db)[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public static function int(\mysqli $db, string $key): int
    {
        return (int) self::get($db, $key);
    }

    /** Validate and store; returns the stored value. Throws \InvalidArgumentException for an unknown key or bad number. */
    public static function set(\mysqli $db, string $key, string $value): string
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            throw new \InvalidArgumentException("Unknown mail intake setting: $key");
        }
        $value = trim($value);
        if (isset(self::NUMERIC[$key])) {
            if (!preg_match('/^\d+$/', $value)) {
                throw new \InvalidArgumentException("$key must be a whole number");
            }
            [$lo, $hi] = self::NUMERIC[$key];
            $value = (string) max($lo, min($hi, (int) $value));
        } elseif ($key === 'alert_email' && $value !== '') {
            foreach (preg_split('/[\s,;]+/', $value) ?: [] as $addr) {
                if ($addr !== '' && !filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException("Invalid alert email address: $addr");
                }
            }
            $value = implode(',', array_filter(preg_split('/[\s,;]+/', $value) ?: []));
        }
        $stmt = $db->prepare('INSERT INTO mail_intake_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        self::$cache = null;
        return $value;
    }

    public static function flush(): void
    {
        self::$cache = null;
        self::$cacheFor = null;
    }
}
