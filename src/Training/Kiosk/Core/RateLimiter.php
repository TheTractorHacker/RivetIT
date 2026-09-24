<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Db;

/**
 * Fixed-window counters in training_rate_buckets (P3 spec §2.1, §4.2): a proxy IP is useless on
 * a shop-floor network, so limits are per kiosk, per contact or system-wide keys.
 *
 * hit() counts one event in the current window and returns false once the count exceeds $cap.
 * The window start is computed in PHP (UTC, aligned to $windowS). One atomic upsert, then a
 * read of the count: under concurrency the answer may be off by the racers, never unbounded.
 * Old rows are deleted by cron/training_kiosk_cron.php (older than 2 days).
 */
final class RateLimiter
{
    public static function hit(\mysqli $db, string $key, int $windowS, int $cap): bool
    {
        return self::count($db, $key, $windowS) <= $cap;
    }

    /** Counts one event and returns the window's count including it. */
    public static function count(\mysqli $db, string $key, int $windowS): int
    {
        $key = self::key($key);
        $windowS = max(1, $windowS);
        $start = gmdate('Y-m-d H:i:s', intdiv(time(), $windowS) * $windowS);
        Db::exec($db, 'INSERT INTO training_rate_buckets (trate_key, trate_window_start_utc, trate_count) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE trate_count = trate_count + 1', 'ss', [$key, $start]);
        $row = Db::one($db, 'SELECT trate_count FROM training_rate_buckets WHERE trate_key = ? AND trate_window_start_utc = ?', 'ss', [$key, $start]);
        return (int) ($row['trate_count'] ?? 1);
    }

    /** The current window's count without counting (0 when none). */
    public static function peek(\mysqli $db, string $key, int $windowS): int
    {
        $key = self::key($key);
        $windowS = max(1, $windowS);
        $start = gmdate('Y-m-d H:i:s', intdiv(time(), $windowS) * $windowS);
        $row = Db::one($db, 'SELECT trate_count FROM training_rate_buckets WHERE trate_key = ? AND trate_window_start_utc = ?', 'ss', [$key, $start]);
        return (int) ($row['trate_count'] ?? 0);
    }

    /** Keys longer than the column are shortened to a stable hash suffix (never truncated into a shared key). */
    private static function key(string $key): string
    {
        return strlen($key) <= 80 ? $key : substr($key, 0, 47) . ':' . substr(hash('sha256', $key), 0, 32);
    }
}
