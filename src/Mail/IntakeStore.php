<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * Database side of mail intake: Message-ID dedupe and threading, the poison-message attempt counter
 * (mail_intake_state) and the per-sender rate count. Works on any mysqli connection (the cron script's, or a scratch
 * database in tests); it never touches functions.php.
 */
final class IntakeStore
{
    public function __construct(private \mysqli $db)
    {
    }

    /** True when an inbound message with this id was already turned into a ticket, reply or mail request. */
    public function isImported(string $messageId): bool
    {
        $id = MessageId::normalize($messageId);
        if ($id === '') {
            return false;
        }
        foreach ([
            ['tickets', 'ticket_mail_message_id'],
            ['ticket_replies', 'ticket_reply_mail_message_id'],
            ['mail_requests', 'mail_request_message_id'], // dismissed requests count: a human already decided
        ] as [$table, $col]) {
            $stmt = $this->db->prepare("SELECT 1 FROM `$table` WHERE `$col` = ? LIMIT 1");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $found = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($found) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ticket a message belongs to, found through its In-Reply-To / References ids matched against ids we stored for
     * inbound tickets/replies/mail requests and ids we generated for outbound mail. $candidates is ordered most
     * specific first (MessageId::threadCandidates); the first candidate that resolves wins.
     *
     * @return array{ticket_id: int, via: string, message_id: string}|null
     */
    public function resolveThread(array $candidates): ?array
    {
        $candidates = array_values(array_filter(array_map([MessageId::class, 'normalize'], $candidates)));
        if ($candidates === []) {
            return null;
        }
        $found = []; // normalized id => [ticket_id, via]
        $in = implode(',', array_fill(0, count($candidates), '?'));
        $types = str_repeat('s', count($candidates));

        $queries = [
            ['inbound ticket', "SELECT ticket_mail_message_id AS mid, ticket_id AS tid FROM tickets WHERE ticket_mail_message_id IN ($in)"],
            ['inbound reply', "SELECT ticket_reply_mail_message_id AS mid, ticket_reply_ticket_id AS tid FROM ticket_replies WHERE ticket_reply_mail_message_id IN ($in)"],
            ['outbound mail', "SELECT email_message_id AS mid, email_ticket_id AS tid FROM email_queue WHERE email_message_id IN ($in) AND email_ticket_id IS NOT NULL"],
            ['converted request', "SELECT mail_request_message_id AS mid, mail_request_converted_ticket_id AS tid FROM mail_requests WHERE mail_request_message_id IN ($in) AND mail_request_converted_ticket_id IS NOT NULL"],
        ];
        foreach ($queries as [$via, $sql]) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$candidates);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                if (!isset($found[$row['mid']])) {
                    $found[$row['mid']] = ['ticket_id' => (int) $row['tid'], 'via' => $via];
                }
            }
            $stmt->close();
        }
        foreach ($candidates as $id) {
            if (isset($found[$id]) && $found[$id]['ticket_id'] > 0) {
                return ['ticket_id' => $found[$id]['ticket_id'], 'via' => $found[$id]['via'], 'message_id' => $id];
            }
        }
        return null;
    }

    /** Stable key for a message within one mailbox: its Message-ID, else the folder UID, else its content fingerprint. */
    public static function messageKey(int $mailboxId, ?string $messageId, string $fallback): string
    {
        $id = MessageId::normalize($messageId);
        return sha1($mailboxId . '|' . ($id !== '' ? 'mid:' . $id : 'fb:' . $fallback));
    }

    /** True when this message already exhausted its attempts and was set aside. */
    public function isQuarantined(int $mailboxId, string $key): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM mail_intake_state WHERE intake_mailbox_id = ? AND intake_key = ? AND intake_state = 'quarantined' LIMIT 1");
        $stmt->bind_param('is', $mailboxId, $key);
        $stmt->execute();
        $q = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $q;
    }

    /**
     * Count one failed attempt. Returns the new attempt count and whether this attempt tipped the message into
     * quarantine (so the caller moves it exactly once).
     *
     * @return array{attempts: int, quarantined: bool}
     */
    public function recordFailure(int $mailboxId, string $key, ?string $messageId, ?string $from, ?string $subject, string $error, int $maxAttempts): array
    {
        $mid = MessageId::normalize($messageId);
        $mid = $mid === '' ? null : $mid;
        $from = $from === null ? null : mb_substr($from, 0, 200);
        $subject = $subject === null ? null : mb_substr($subject, 0, 500);
        $error = mb_substr($error, 0, 500);
        $stmt = $this->db->prepare('INSERT INTO mail_intake_state
            (intake_mailbox_id, intake_key, intake_message_id, intake_from_email, intake_subject, intake_attempts, intake_last_error, intake_last_attempt_at)
            VALUES (?, ?, ?, ?, ?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE intake_attempts = intake_attempts + 1, intake_last_error = VALUES(intake_last_error), intake_last_attempt_at = NOW()');
        $stmt->bind_param('isssss', $mailboxId, $key, $mid, $from, $subject, $error);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('SELECT intake_attempts, intake_state FROM mail_intake_state WHERE intake_mailbox_id = ? AND intake_key = ?');
        $stmt->bind_param('is', $mailboxId, $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $attempts = (int) ($row['intake_attempts'] ?? 1);

        $tipped = false;
        if ($attempts >= max(1, $maxAttempts) && ($row['intake_state'] ?? '') !== 'quarantined') {
            $stmt = $this->db->prepare("UPDATE mail_intake_state SET intake_state = 'quarantined', intake_quarantined_at = NOW() WHERE intake_mailbox_id = ? AND intake_key = ?");
            $stmt->bind_param('is', $mailboxId, $key);
            $stmt->execute();
            $stmt->close();
            $tipped = true;
        }
        return ['attempts' => $attempts, 'quarantined' => $tipped];
    }

    /** The message was handled: forget its failure history. */
    public function clear(int $mailboxId, string $key): void
    {
        $stmt = $this->db->prepare('DELETE FROM mail_intake_state WHERE intake_mailbox_id = ? AND intake_key = ?');
        $stmt->bind_param('is', $mailboxId, $key);
        $stmt->execute();
        $stmt->close();
    }

    /** Admin action: give a quarantined message another full set of attempts. */
    public function release(int $intakeId): bool
    {
        $stmt = $this->db->prepare("UPDATE mail_intake_state SET intake_state = 'pending', intake_attempts = 0, intake_quarantined_at = NULL WHERE intake_id = ? AND intake_state = 'quarantined'");
        $stmt->bind_param('i', $intakeId);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return $n > 0;
    }

    /** Admin action: acknowledge and remove a quarantine entry (the message itself stays in the quarantine folder). */
    public function discard(int $intakeId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM mail_intake_state WHERE intake_id = ? AND intake_state = 'quarantined'");
        $stmt->bind_param('i', $intakeId);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return $n > 0;
    }

    public function quarantinedCount(): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) FROM mail_intake_state WHERE intake_state = 'quarantined'")->fetch_row()[0] ?? 0);
    }

    /** Messages from this sender that intake handled in the last $seconds (machine-generated mail and duplicates do not count). */
    public function senderMessageCount(string $fromEmail, int $seconds = 3600): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM mail_log WHERE mail_log_from_email = ? AND mail_log_outcome NOT IN ('suppressed','ndr','duplicate') AND mail_log_created_at >= NOW() - INTERVAL ? SECOND');
        $stmt->bind_param('si', $fromEmail, $seconds);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $n;
    }

    /** Rate-limit quarantine rows already stored for this sender in the window (caps the quarantine itself). */
    public function quarantinedForSender(string $fromEmail, int $seconds = 3600): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM mail_requests WHERE mail_request_from_email = ? AND mail_request_reason = 'rate_limited' AND mail_request_created_at >= NOW() - INTERVAL ? SECOND");
        $stmt->bind_param('si', $fromEmail, $seconds);
        $stmt->execute();
        $n = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();
        return $n;
    }
}
