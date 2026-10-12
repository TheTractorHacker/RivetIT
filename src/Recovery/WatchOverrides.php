<?php

namespace ITFlow\Recovery;

/**
 * Per-integration controls for the sync watcher (table recovery_watch_overrides, DB 2.6.165): Mute, an expected-interval override
 * (so an external daily script can be declared as "every 24 h") and Forget history. One row per (source, integration id), source
 * being rmm, intune or unifi. Every read is defensive: before the migration there are simply no overrides and nothing throws.
 */
final class WatchOverrides
{
    public const SOURCES = ['rmm', 'intune', 'unifi'];
    public const MIN_INTERVAL_S = 60;
    public const MAX_INTERVAL_S = 90 * 86400;

    public static function ready(\mysqli $db): bool
    {
        static $cache = [];
        $key = spl_object_id($db);
        if (empty($cache[$key])) {   // a negative answer is not cached, so the table appearing mid-request (the migration) is seen
            $r = @mysqli_query($db, "SHOW TABLES LIKE 'recovery_watch_overrides'");
            $cache[$key] = $r && mysqli_num_rows($r) > 0;
        }

        return $cache[$key];
    }

    /** @return array<string,array<string,mixed>> keyed "source:integration_id"; forget_before_ts is a unix timestamp or null */
    public static function all(\mysqli $db): array
    {
        $out = [];
        if (!self::ready($db)) {
            return $out;
        }
        $q = @mysqli_query($db, 'SELECT override_id, override_source AS source, override_integration_id AS integration_id, override_muted AS muted,
                                        override_expected_interval_s AS expected_interval_s, UNIX_TIMESTAMP(override_forget_before) AS forget_before_ts,
                                        override_note AS note, override_updated_by AS updated_by, override_updated_at AS updated_at
                                 FROM recovery_watch_overrides');
        while ($q && ($r = mysqli_fetch_assoc($q))) {
            $out[$r['source'] . ':' . $r['integration_id']] = [
                'muted' => (int) $r['muted'],
                'expected_interval_s' => $r['expected_interval_s'] === null ? null : (int) $r['expected_interval_s'],
                'forget_before_ts' => $r['forget_before_ts'] === null ? null : (int) $r['forget_before_ts'],
                'note' => (string) $r['note'],
                'updated_by' => (int) $r['updated_by'],
                'updated_at' => (string) $r['updated_at'],
            ];
        }

        return $out;
    }

    private static function valid(string $source, int $id): bool
    {
        return in_array($source, self::SOURCES, true) && $id > 0;
    }

    /** Make sure the row exists (all columns default), without touching an existing one. */
    private static function ensure(\mysqli $db, string $source, int $id, int $userId): bool
    {
        if (!self::valid($source, $id) || !self::ready($db)) {
            return false;
        }
        $stmt = mysqli_prepare($db, 'INSERT IGNORE INTO recovery_watch_overrides (override_source, override_integration_id, override_updated_by) VALUES (?, ?, ?)');
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'sii', $source, $id, $userId);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    public static function setMuted(\mysqli $db, string $source, int $id, bool $muted, int $userId): bool
    {
        if (!self::ensure($db, $source, $id, $userId)) {
            return false;
        }
        $m = $muted ? 1 : 0;
        $stmt = mysqli_prepare($db, 'UPDATE recovery_watch_overrides SET override_muted = ?, override_updated_by = ? WHERE override_source = ? AND override_integration_id = ?');
        mysqli_stmt_bind_param($stmt, 'iisi', $m, $userId, $source, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    /** $seconds null = remove the override (back to the setting). Out-of-range values are refused (false). */
    public static function setInterval(\mysqli $db, string $source, int $id, ?int $seconds, string $note, int $userId): bool
    {
        if ($seconds !== null && ($seconds < self::MIN_INTERVAL_S || $seconds > self::MAX_INTERVAL_S)) {
            return false;
        }
        if (!self::ensure($db, $source, $id, $userId)) {
            return false;
        }
        $note = mb_substr(trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $note) ?? ''), 0, 255);
        $stmt = mysqli_prepare($db, 'UPDATE recovery_watch_overrides SET override_expected_interval_s = ?, override_note = ?, override_updated_by = ? WHERE override_source = ? AND override_integration_id = ?');
        mysqli_stmt_bind_param($stmt, 'isisi', $seconds, $note, $userId, $source, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    /** Ignore every run up to now: the integration shows "history cleared" and stays quiet until it runs again. Nothing is deleted. */
    public static function forgetHistory(\mysqli $db, string $source, int $id, int $userId): bool
    {
        if (!self::ensure($db, $source, $id, $userId)) {
            return false;
        }
        $stmt = mysqli_prepare($db, 'UPDATE recovery_watch_overrides SET override_forget_before = NOW(), override_updated_by = ? WHERE override_source = ? AND override_integration_id = ?');
        mysqli_stmt_bind_param($stmt, 'isi', $userId, $source, $id);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return (bool) $ok;
    }

    /** Human wording for a number of seconds the admin typed ("24 h", "17 min"). */
    public static function describe(int $seconds): string
    {
        if ($seconds % 86400 === 0 && $seconds >= 2 * 86400) {
            return ($seconds / 86400) . ' d';
        }
        if ($seconds % 3600 === 0) {
            return ($seconds / 3600) . ' h';
        }

        return max(1, (int) round($seconds / 60)) . ' min';
    }
}
