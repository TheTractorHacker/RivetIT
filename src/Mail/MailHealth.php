<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Mailbox health bookkeeping and admin alerts. An alert is an in-app notification plus an email, de-duplicated per
 * alert key for N hours (mail_alerts), so a mailbox that is down for a day produces four messages, not 288.
 *
 * The notifier and the mailer are injectable: by default they call appNotify() and addToMailQueue() from functions.php
 * when those exist, which is what the cron scripts have loaded; tests pass closures.
 */
final class MailHealth
{
    /** @var callable|null fn(string $type, string $details, string $action): void */
    public static $notifier = null;
    /** @var callable|null fn(array $emails): void  (list of addToMailQueue rows) */
    public static $mailer = null;

    public static function recordPollSuccess(\mysqli $db, int $mailboxId): void
    {
        $db->query("UPDATE mailboxes SET mailbox_last_polled_at = NOW(), mailbox_last_success_at = NOW(), mailbox_consecutive_failures = 0, mailbox_last_error = NULL WHERE mailbox_id = " . (int) $mailboxId);
    }

    /** @return int consecutive failures including this one */
    public static function recordPollFailure(\mysqli $db, int $mailboxId, string $error): int
    {
        $error = mb_substr($error, 0, 500);
        $stmt = $db->prepare('UPDATE mailboxes SET mailbox_last_polled_at = NOW(), mailbox_last_error = ?, mailbox_last_error_at = NOW(), mailbox_consecutive_failures = mailbox_consecutive_failures + 1 WHERE mailbox_id = ?');
        $stmt->bind_param('si', $error, $mailboxId);
        $stmt->execute();
        $stmt->close();
        return (int) ($db->query('SELECT mailbox_consecutive_failures FROM mailboxes WHERE mailbox_id = ' . (int) $mailboxId)->fetch_row()[0] ?? 1);
    }

    /**
     * Send an alert unless the same key was sent within the de-dupe window.
     *
     * @return bool true when it was sent now
     */
    public static function alert(\mysqli $db, string $key, string $title, string $detail, string $action = '/admin/mailbox.php'): bool
    {
        $hours = max(1, MailSettings::int($db, 'alert_dedupe_hours'));
        $stmt = $db->prepare('SELECT 1 FROM mail_alerts WHERE alert_key = ? AND alert_last_sent_at > NOW() - INTERVAL ? HOUR');
        $stmt->bind_param('si', $key, $hours);
        $stmt->execute();
        $recent = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($recent) {
            return false;
        }

        $detail = mb_substr($detail, 0, 480);
        $stmt = $db->prepare('INSERT INTO mail_alerts (alert_key, alert_last_sent_at, alert_last_detail, alert_count) VALUES (?, NOW(), ?, 1)
            ON DUPLICATE KEY UPDATE alert_last_sent_at = NOW(), alert_last_detail = VALUES(alert_last_detail), alert_count = alert_count + 1');
        $stmt->bind_param('ss', $key, $detail);
        $stmt->execute();
        $stmt->close();

        $message = "$title: $detail";
        if (self::$notifier !== null) {
            (self::$notifier)('Mail', $message, $action);
        } elseif (function_exists('appNotify')) {
            appNotify('Mail', $message, $action, 0);
        }

        $emails = [];
        $row = $db->query('SELECT config_mail_from_email, config_mail_from_name, config_ticket_from_email, config_ticket_from_name FROM settings WHERE company_id = 1')->fetch_assoc() ?: [];
        $from = $row['config_mail_from_email'] ?: ($row['config_ticket_from_email'] ?? '');
        $fromName = $row['config_mail_from_name'] ?? ($row['config_ticket_from_name'] ?? '') ?: 'RivetIT';
        $base = (string) ($GLOBALS['config_base_url'] ?? '');
        foreach (self::alertRecipients($db) as $to) {
            if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                break;
            }
            $link = $base !== '' ? '<br><br><a href="https://' . htmlspecialchars($base, ENT_QUOTES) . htmlspecialchars($action, ENT_QUOTES) . '">Open in RivetIT</a>' : '';
            $emails[] = [
                'from' => $from, 'from_name' => (string) $fromName, 'recipient' => $to, 'recipient_name' => $to,
                'subject' => 'RivetIT mail alert - ' . $title,
                'body' => '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>You receive this at most once every ' . $hours . ' hours per problem. See docs/MAIL_INTAKE.md for what each alert means.</p>' . $link,
            ];
        }
        if ($emails) {
            if (self::$mailer !== null) {
                (self::$mailer)($emails);
            } elseif (function_exists('addToMailQueue')) {
                addToMailQueue($emails);
            }
        }
        return true;
    }

    /** Configured alert address(es), else every active admin user. */
    public static function alertRecipients(\mysqli $db): array
    {
        $configured = trim(MailSettings::get($db, 'alert_email'));
        $list = [];
        if ($configured !== '') {
            $list = array_filter(preg_split('/[\s,;]+/', $configured) ?: [], static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        } else {
            $res = $db->query('SELECT u.user_email FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
                WHERE r.role_is_admin = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND u.user_email <> \'\'');
            while ($res && ($u = $res->fetch_row())) {
                if (filter_var($u[0], FILTER_VALIDATE_EMAIL)) {
                    $list[] = $u[0];
                }
            }
        }
        return array_values(array_unique($list));
    }

    /** OAuth refresh failed for a mailbox (called from the token helpers with the provider's own reason). */
    public static function oauthFailure(\mysqli $db, int $mailboxId, string $provider, string $reason): bool
    {
        $name = self::mailboxLabel($db, $mailboxId);
        return self::alert($db, "oauth:$mailboxId", 'Mailbox sign-in failed', "$name ($provider): token refresh failed - " . $reason . '. Reconnect the mailbox on the Mailboxes page.');
    }

    public static function mailboxLabel(\mysqli $db, int $mailboxId): string
    {
        $row = $db->query('SELECT mailbox_name, mailbox_email FROM mailboxes WHERE mailbox_id = ' . (int) $mailboxId)->fetch_assoc();
        return $row ? ($row['mailbox_email'] ?: $row['mailbox_name']) : "mailbox #$mailboxId";
    }

    /**
     * Evaluate every standing condition and alert. Safe to call from any cron run; cheap (a handful of indexed queries).
     *
     * @param bool $parserEnabled whether email-to-ticket parsing is switched on (a silent poller is only a problem then)
     * @return string[] alert keys sent this call
     */
    public static function runChecks(\mysqli $db, bool $parserEnabled = true): array
    {
        $sent = [];

        // Mailbox unreachable N polls in a row.
        $threshold = max(1, MailSettings::int($db, 'mailbox_fail_threshold'));
        $res = $db->query("SELECT mailbox_id, mailbox_name, mailbox_email, mailbox_consecutive_failures, mailbox_last_error FROM mailboxes
            WHERE mailbox_active = 1 AND mailbox_archived_at IS NULL AND mailbox_consecutive_failures >= $threshold");
        while ($res && ($m = $res->fetch_assoc())) {
            $key = 'unreachable:' . $m['mailbox_id'];
            if (self::alert($db, $key, 'Mailbox unreachable', ($m['mailbox_email'] ?: $m['mailbox_name']) . ' failed ' . $m['mailbox_consecutive_failures'] . ' polls in a row. Last error: ' . ($m['mailbox_last_error'] ?: 'unknown'))) {
                $sent[] = $key;
            }
        }

        // Poller silent: parsing is enabled but nothing has polled this mailbox recently.
        if ($parserEnabled) {
            $silent = max(5, MailSettings::int($db, 'poller_silent_minutes'));
            $res = $db->query("SELECT mailbox_id, mailbox_name, mailbox_email, mailbox_last_polled_at FROM mailboxes
                WHERE mailbox_active = 1 AND mailbox_archived_at IS NULL
                AND COALESCE(mailbox_last_polled_at, mailbox_created_at) < NOW() - INTERVAL $silent MINUTE");
            while ($res && ($m = $res->fetch_assoc())) {
                $key = 'silent:' . $m['mailbox_id'];
                $since = $m['mailbox_last_polled_at'] ?: 'never';
                if (self::alert($db, $key, 'Mail poller silent', ($m['mailbox_email'] ?: $m['mailbox_name']) . " has not been polled for over $silent minutes (last poll: $since). Check that cron/ticket_email_parser.php is scheduled and running.")) {
                    $sent[] = $key;
                }
            }
        }

        // Outbound mail that ran out of retries.
        $exhausted = MailQueuePolicy::exhaustedUnalerted($db);
        if ($exhausted) {
            $first = $exhausted[0];
            $detail = count($exhausted) . ' email(s) failed all ' . MailQueuePolicy::MAX_ATTEMPTS . ' send attempts and will not be retried. First: #' . $first['email_id'] . ' to ' . $first['email_recipient'] . ' ("' . mb_substr((string) $first['email_subject'], 0, 80) . '")';
            if (self::alert($db, 'queue_exhausted', 'Outbound email gave up', $detail, '/admin/mail_queue.php')) {
                $sent[] = 'queue_exhausted';
                MailQueuePolicy::markAlerted($db, array_column($exhausted, 'email_id'));
            }
        }

        return $sent;
    }

    /**
     * Counters for the admin pages.
     *
     * @return array{mailboxes: int, unhealthy: int, quarantined: int, exhausted: int, attention: int}
     */
    public static function summary(\mysqli $db): array
    {
        try {
            return self::computeSummary($db);
        } catch (\Throwable $e) {
            // Tables not migrated yet (database update pending): show nothing rather than break the admin page.
            return ['mailboxes' => 0, 'unhealthy' => 0, 'quarantined' => 0, 'exhausted' => 0, 'attention' => 0];
        }
    }

    private static function computeSummary(\mysqli $db): array
    {
        $threshold = max(1, MailSettings::int($db, 'mailbox_fail_threshold'));
        $silent = max(5, MailSettings::int($db, 'poller_silent_minutes'));
        $one = static fn (string $sql): int => (int) ($db->query($sql)->fetch_row()[0] ?? 0);
        $mailboxes = $one('SELECT COUNT(*) FROM mailboxes WHERE mailbox_active = 1 AND mailbox_archived_at IS NULL');
        $unhealthy = $one("SELECT COUNT(*) FROM mailboxes WHERE mailbox_active = 1 AND mailbox_archived_at IS NULL
            AND (mailbox_consecutive_failures >= $threshold OR COALESCE(mailbox_last_polled_at, mailbox_created_at) < NOW() - INTERVAL $silent MINUTE)");
        $quarantined = $one("SELECT COUNT(*) FROM mail_intake_state WHERE intake_state = 'quarantined'");
        $exhausted = $one('SELECT COUNT(*) FROM email_queue WHERE email_status = 2 AND email_attempts >= ' . MailQueuePolicy::MAX_ATTEMPTS . ' AND email_attempts < ' . MailQueuePolicy::PERMANENT);
        return ['mailboxes' => $mailboxes, 'unhealthy' => $unhealthy, 'quarantined' => $quarantined, 'exhausted' => $exhausted, 'attention' => $unhealthy + $quarantined + ($exhausted > 0 ? 1 : 0)];
    }
}
