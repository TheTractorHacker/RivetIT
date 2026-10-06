<?php

namespace ITFlow\Workflow;

/** The real ActionGateway: reuses the app's ticket, mail queue, notification, event bus and audit code. */
class LiveActionGateway implements ActionGateway, DirectoryActionGateway
{
    private \mysqli $mysqli;
    /** @var array<string,mixed> GraphClientFactory options; tests point graph_base / authority at a local mock */
    private array $graphOptions;

    public function __construct(\mysqli $mysqli, array $graphOptions = [])
    {
        $this->mysqli = $mysqli;
        $this->graphOptions = $graphOptions;
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

    // ----- Entra account actions (DirectoryActionGateway) --------------------------------------------------------------

    public function entraWritesAllowed(): bool
    {
        return \ITFlow\Integrations\Microsoft\GraphClientFactory::writesAllowed($this->mysqli);
    }

    private function entraService(int $runTaskId): \ITFlow\Integrations\Microsoft\EntraAccountService
    {
        if (!$this->entraWritesAllowed()) {
            throw new \RuntimeException("'Allow RivetIT to change Entra accounts' is off; nothing was sent");
        }
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli, 'SELECT * FROM microsoft_integrations WHERE enabled = 1 ORDER BY microsoft_integration_id DESC LIMIT 1'));
        if (!$row || (string) $row['tenant_id'] === '' || (string) $row['client_id'] === '') {
            throw new \RuntimeException('the Microsoft / Entra connection is not set up or not enabled');
        }
        $graph = \ITFlow\Integrations\Microsoft\GraphClientFactory::forRow($this->mysqli, $row, time() + 90, $this->graphOptions);

        return new \ITFlow\Integrations\Microsoft\EntraAccountService(
            $graph,
            fn (string $event, string $action, string $summary, array $meta) => $this->audit($event, null, 'integration', (int) $row['microsoft_integration_id'], $action, $summary, $meta + ['run_task_id' => $runTaskId]),
            fn (string $secret): bool => $this->storeTaskSecret($runTaskId, $secret)
        );
    }

    public function entraDisableAccount(string $email, bool $revokeSessions, int $runTaskId): string
    {
        return $this->entraService($runTaskId)->disableAccount($email, $revokeSessions);
    }

    public function entraCreateAccount(array $spec, int $runTaskId): string
    {
        return $this->entraService($runTaskId)->createAccount($spec);
    }

    public function entraAddToGroups(string $email, array $groupIds, int $runTaskId): string
    {
        return $this->entraService($runTaskId)->addToGroups($email, $groupIds);
    }

    /**
     * Keeps a generated secret (a temporary password) for ONE technician: the task's assignee, else whoever started the run.
     * Encrypted at rest, expires after 7 days, read once through WorkflowService::revealTaskSecret().
     */
    private function storeTaskSecret(int $runTaskId, string $secret): bool
    {
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT COALESCE(NULLIF(t.assignee_user_id, 0), r.started_by, 0) AS owner FROM workflow_run_tasks t JOIN workflow_runs r ON r.run_id = t.run_id WHERE t.run_task_id = " . (int) $runTaskId));
        $owner = (int) ($row['owner'] ?? 0);
        if ($owner <= 0) {
            return false; // nobody to show it to; better to say so than to keep a password nobody may read
        }
        $enc = encryptSetting($secret);
        $stmt = mysqli_prepare($this->mysqli, 'UPDATE workflow_run_tasks SET secret_result_enc = ?, secret_user_id = ?, secret_expires_at = NOW() + INTERVAL 7 DAY WHERE run_task_id = ?');
        mysqli_stmt_bind_param($stmt, 'sii', $enc, $owner, $runTaskId);
        $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);

        return $ok;
    }
}
