<?php

namespace ITFlow\Workflow;

/** The real ActionGateway: reuses the app's ticket, mail queue, notification, event bus and audit code. */
class LiveActionGateway implements ActionGateway
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
    }

    public function createTicket(string $subject, string $detailsHtml, string $priority, int $clientId, string $source): int
    {
        return rivetCreateAutomationTicket($this->mysqli, $subject, $detailsHtml, $priority, $clientId, $source);
    }

    public function queueMail(string $to, string $toName, string $subject, string $bodyHtml): void
    {
        global $config_ticket_from_email, $config_ticket_from_name;
        // A CR/LF in a subject or name could add mail headers; the placeholders may carry anything from the directory.
        $clean = static fn (string $v): string => trim(preg_replace('/[\r\n\x00]+/', ' ', $v));
        addToMailQueue([[
            'from' => (string) $config_ticket_from_email,
            'from_name' => (string) $config_ticket_from_name,
            'recipient' => $to,
            'recipient_name' => $clean($toName),
            'subject' => $clean($subject),
            'body' => $bodyHtml,
        ]]);
    }

    public function notifyUser(int $userId, string $type, string $message, ?string $action, int $clientId, int $entityId): void
    {
        if ($userId > 0) {
            \notifyUser($userId, $type, $message, $action, $clientId, $entityId);
        } else {
            \appNotify($type, $message, $action, $clientId, $entityId, true);
        }
    }

    public function emitEvent(string $event, array $data): void
    {
        rivetEmitEvent($event, $data);
    }

    public function audit(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $metadata = []): void
    {
        rivetAudit($event, $actor, $entityType, $entityId, $action, $summary, $metadata);
    }

    public function disablePortalLogin(int $contactId): string
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT c.contact_user_id, u.user_type, u.user_archived_at FROM contacts c LEFT JOIN users u ON u.user_id = c.contact_user_id WHERE c.contact_id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $contactId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$row || (int) $row['contact_user_id'] <= 0 || $row['user_type'] === null) {
            return 'no portal login is linked to this person; nothing to disable';
        }
        // Only a client-portal login (user_type 2) is ever touched here. A linked agent account is left alone on purpose.
        if ((int) $row['user_type'] !== 2) {
            throw new \RuntimeException('the linked login is not a portal login; it was left unchanged');
        }
        if ($row['user_archived_at'] !== null) {
            return 'the portal login was already disabled';
        }
        $userId = (int) $row['contact_user_id'];
        // Same mechanism as archiving a contact (agent/post/contact.php): the login is archived, so it can no longer sign in; the
        // user row and everything attached to it stay, and restoring the contact restores the login.
        mysqli_query($this->mysqli, "UPDATE users SET user_archived_at = NOW(), user_php_session = '' WHERE user_id = $userId AND user_type = 2");

        return 'portal login disabled (archived)';
    }
}
