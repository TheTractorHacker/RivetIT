<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Outbound queue rules for cron/mail_queue.php (table email_queue): retry back-off, the stuck-row reaper and the
 * per-minute send budget. Statuses: 0 queued, 1 sending, 2 failed, 3 sent.
 */
final class MailQueuePolicy
{
    /** Minutes to wait after the Nth failed attempt before trying again (attempt 1 -> 5, 2 -> 15, 3 -> 60, 4 -> 240). */
    public const BACKOFF_MINUTES = [1 => 5, 2 => 15, 3 => 60, 4 => 240];

    /** A row is exhausted once it has failed this many times (the first send plus four retries). */
    public const MAX_ATTEMPTS = 5;

    /** attempts value used for permanent failures (bad address, cancelled): never retried, never alerted as exhausted. */
    public const PERMANENT = 99;

    public const STUCK_MINUTES = 10;

    public static function backoffMinutes(int $failedAttempts): ?int
    {
        return self::BACKOFF_MINUTES[$failedAttempts] ?? null;
    }

    public static function isExhausted(int $attempts): bool
    {
        return $attempts >= self::MAX_ATTEMPTS && $attempts < self::PERMANENT;
    }

    /** SQL fragment: failed rows whose back-off has elapsed and that still have retries left. */
    public static function retryDueSql(): string
    {
        return 'email_status = 2 AND email_attempts BETWEEN 1 AND ' . (self::MAX_ATTEMPTS - 1)
            . ' AND email_failed_at <= NOW() - INTERVAL (CASE email_attempts WHEN 1 THEN 5 WHEN 2 THEN 15 WHEN 3 THEN 60 ELSE 240 END) MINUTE';
    }

    /** Put rows stuck in "sending" (a crashed or killed run) back in the queue. Returns how many. */
    public static function reapStuck(\mysqli $db, int $minutes = self::STUCK_MINUTES): int
    {
        $minutes = max(1, $minutes);
        $db->query("UPDATE email_queue SET email_status = 0, email_started_at = NULL
            WHERE email_status = 1 AND COALESCE(email_started_at, email_queued_at) < NOW() - INTERVAL $minutes MINUTE");
        return max(0, $db->affected_rows);
    }

    /** How many more messages may be sent now without exceeding $perMinute over the trailing minute. */
    public static function sendBudget(\mysqli $db, int $perMinute): int
    {
        $perMinute = max(1, $perMinute);
        $sent = (int) ($db->query('SELECT COUNT(*) FROM email_queue WHERE email_status = 3 AND email_sent_at >= NOW() - INTERVAL 60 SECOND')->fetch_row()[0] ?? 0);
        return max(0, $perMinute - $sent);
    }

    /** Rows that failed their last allowed attempt and have not been alerted yet. */
    public static function exhaustedUnalerted(\mysqli $db): array
    {
        $res = $db->query('SELECT email_id, email_recipient, email_subject, email_attempts, email_failed_at FROM email_queue
            WHERE email_status = 2 AND email_attempts >= ' . self::MAX_ATTEMPTS . ' AND email_attempts < ' . self::PERMANENT . ' AND email_alerted_at IS NULL
            ORDER BY email_id LIMIT 200');
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function markAlerted(\mysqli $db, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids) {
            $db->query('UPDATE email_queue SET email_alerted_at = NOW() WHERE email_id IN (' . implode(',', $ids) . ')');
        }
    }
}
