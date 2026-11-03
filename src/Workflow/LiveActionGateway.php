<?php

namespace ITFlow\Workflow;

/** The real ActionGateway: reuses the app's ticket, mail queue, notification, event bus and audit code. */
class LiveActionGateway implements ActionGateway, DirectoryActionGateway, LifecycleGateway
{
    private \mysqli $mysqli;
    /** @var array<string,mixed> GraphClientFactory options; tests point graph_base / authority at a local mock */
    private array $graphOptions;
    private ?Connectors\ConnectorRegistry $connectors = null;

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
            throw new \RuntimeException(("'Allow " . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . " to change Entra accounts' is off; nothing was sent"));
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

    public function entraDisableAccount(string $email, bool $revokeSessions, int $runTaskId, string $expectedDepartment = ''): string
    {
        return $this->entraService($runTaskId)->disableAccount($email, $revokeSessions, $expectedDepartment);
    }

    public function entraCreateAccount(array $spec, int $runTaskId): string
    {
        return $this->entraService($runTaskId)->createAccount($spec);
    }

    public function entraAddToGroups(string $email, array $groupIds, int $runTaskId, string $expectedDepartment = ''): string
    {
        return $this->entraService($runTaskId)->addToGroups($email, $groupIds, $expectedDepartment);
    }

    public function entraChangeLicenses(string $email, array $addSkus, array $removeSkus, int $runTaskId, string $expectedDepartment = ''): string
    {
        return $this->entraService($runTaskId)->changeLicenses($email, $addSkus, $removeSkus, $expectedDepartment);
    }

    public function entraSetAutoReply(string $email, string $message, int $runTaskId, string $expectedDepartment = ''): string
    {
        return $this->entraService($runTaskId)->setAutoReply($email, $message, $expectedDepartment);
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

    // ----- Lifecycle actions beyond tickets/mail/Entra (LifecycleGateway) --------------------------------------------------

    private function category(string $type, string $name): ?string
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT category_name FROM categories WHERE category_type = ? AND LOWER(category_name) = LOWER(?) AND category_archived_at IS NULL LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $type, $name);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return $row ? (string) $row[0] : null;
    }

    private function assetHistory(int $assetId, string $status, string $text): void
    {
        $stmt = mysqli_prepare($this->mysqli, 'INSERT INTO asset_history (asset_history_status, asset_history_description, asset_history_asset_id) VALUES (?, ?, ?)');
        $text = mb_substr($text, 0, 250);
        mysqli_stmt_bind_param($stmt, 'ssi', $status, $text, $assetId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    public function assignAsset(int $contactId, array $spec, int $runTaskId): string
    {
        $c = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT contact_name, contact_client_id FROM contacts WHERE contact_id = $contactId"));
        if (!$c) {
            throw new \RuntimeException('the person no longer exists');
        }
        $clientId = (int) $c['contact_client_id'];
        if ($clientId <= 0) {
            throw new \RuntimeException('the person has no department, so no device of their department can be assigned');
        }
        $from = $this->category('asset_status', (string) ($spec['from_status'] ?? 'Ready to Deploy'));
        $to = $this->category('asset_status', (string) ($spec['to_status'] ?? 'Deployed'));
        if ($to === null || (empty($spec['asset_id']) && $from === null)) {
            throw new \RuntimeException('the asset status "' . ($to === null ? ($spec['to_status'] ?? 'Deployed') : ($spec['from_status'] ?? 'Ready to Deploy')) . '" does not exist (Administration > Tags & Categories > Asset statuses)');
        }
        $assetId = (int) ($spec['asset_id'] ?? 0);
        $type = trim((string) ($spec['asset_type'] ?? ''));

        if ($assetId > 0) {
            $a = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT asset_id, asset_name, asset_client_id, asset_contact_id, asset_archived_at FROM assets WHERE asset_id = $assetId"));
            if (!$a || $a['asset_archived_at'] !== null) {
                throw new \RuntimeException("asset #$assetId does not exist or is archived");
            }
            if ((int) $a['asset_client_id'] !== $clientId) {
                throw new \RuntimeException("asset #$assetId belongs to another department than " . $c['contact_name'] . '; nothing was assigned');
            }
            if ((int) $a['asset_contact_id'] === $contactId) {
                return $a['asset_name'] . ' is already assigned to ' . $c['contact_name'];
            }
            $candidates = [$assetId];
        } else {
            // Idempotent for a retry: a person who already holds a device of this type from this workflow's stock does not get a second one.
            $stmt = mysqli_prepare($this->mysqli, 'SELECT asset_id, asset_name FROM assets WHERE asset_contact_id = ? AND asset_type = ? AND asset_archived_at IS NULL AND LOWER(asset_status) = LOWER(?) LIMIT 1');
            mysqli_stmt_bind_param($stmt, 'iss', $contactId, $type, $to);
            mysqli_stmt_execute($stmt);
            $have = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($have) {
                return $have['asset_name'] . ' (' . $type . ') is already assigned to ' . $c['contact_name'] . '; nothing more was assigned';
            }
            $stmt = mysqli_prepare($this->mysqli, 'SELECT asset_id FROM assets WHERE asset_client_id = ? AND asset_type = ? AND asset_contact_id = 0 AND asset_archived_at IS NULL AND LOWER(asset_status) = LOWER(?) ORDER BY asset_id LIMIT 5');
            mysqli_stmt_bind_param($stmt, 'iss', $clientId, $type, $from);
            mysqli_stmt_execute($stmt);
            $candidates = array_map('intval', array_column(mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC), 'asset_id'));
            mysqli_stmt_close($stmt);
            if (!$candidates) {
                throw new \RuntimeException("no unassigned $type with status \"$from\" in the person's department; assign one by hand");
            }
        }
        foreach ($candidates as $id) {
            // One UPDATE claims it: two workflows racing for the last laptop cannot both get it.
            $stmt = mysqli_prepare($this->mysqli, 'UPDATE assets SET asset_contact_id = ?, asset_status = ? WHERE asset_id = ? AND asset_client_id = ? AND asset_contact_id = 0 AND asset_archived_at IS NULL');
            mysqli_stmt_bind_param($stmt, 'isii', $contactId, $to, $id, $clientId);
            mysqli_stmt_execute($stmt);
            $won = mysqli_stmt_affected_rows($stmt) === 1;
            mysqli_stmt_close($stmt);
            if ($won) {
                $name = (string) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT asset_name FROM assets WHERE asset_id = $id"))[0];
                $this->assetHistory($id, $to, 'Workflow assigned ' . $name . ' to ' . $c['contact_name']);
                $this->audit('workflow.asset_assigned', null, 'asset', $id, 'assigned', "Device $name assigned to " . $c['contact_name'] . ' by a lifecycle workflow', ['contact_id' => $contactId, 'run_task_id' => $runTaskId]);

                return "$name assigned to " . $c['contact_name'] . " (status $to)";
            }
        }
        throw new \RuntimeException('the device was taken by someone else just now; run the task again');
    }

    public function collectAssets(int $contactId, array $spec, int $runTaskId): string
    {
        $to = $this->category('asset_status', (string) ($spec['to_status'] ?? 'Ready to Deploy'));
        if ($to === null) {
            throw new \RuntimeException('the asset status "' . ($spec['to_status'] ?? 'Ready to Deploy') . '" does not exist (Administration > Tags & Categories > Asset statuses)');
        }
        $cn = (string) (mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT contact_name FROM contacts WHERE contact_id = $contactId"))[0] ?? '');
        $types = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string) ($spec['asset_types'] ?? '')) ?: [])));
        $res = mysqli_query($this->mysqli, "SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_contact_id = $contactId AND asset_archived_at IS NULL ORDER BY asset_id");
        $n = 0;
        $names = [];
        while ($res && ($a = mysqli_fetch_assoc($res))) {
            if ($types && !in_array(strtolower($a['asset_type']), array_map('strtolower', $types), true)) {
                continue;
            }
            $id = (int) $a['asset_id'];
            $stmt = mysqli_prepare($this->mysqli, 'UPDATE assets SET asset_contact_id = 0, asset_status = ? WHERE asset_id = ? AND asset_contact_id = ?');
            mysqli_stmt_bind_param($stmt, 'sii', $to, $id, $contactId);
            mysqli_stmt_execute($stmt);
            if (mysqli_stmt_affected_rows($stmt) === 1) {
                $n++;
                $names[] = $a['asset_name'];
                $this->assetHistory($id, $to, 'Workflow collected ' . $a['asset_name'] . ' from ' . $cn);
                $this->audit('workflow.asset_collected', null, 'asset', $id, 'collected', 'Device ' . $a['asset_name'] . " collected from $cn by a lifecycle workflow", ['contact_id' => $contactId, 'run_task_id' => $runTaskId]);
            }
            mysqli_stmt_close($stmt);
        }

        return $n === 0 ? "$cn has no devices to collect" : "$n device(s) collected from $cn and set to \"$to\": " . implode(', ', array_slice($names, 0, 10));
    }

    public function archiveContact(int $contactId): string
    {
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT contact_name, contact_primary, contact_user_id, contact_archived_at FROM contacts WHERE contact_id = $contactId"));
        if (!$row) {
            throw new \RuntimeException('the person no longer exists');
        }
        if ($row['contact_archived_at'] !== null) {
            return $row['contact_name'] . ' was already archived';
        }
        if ((int) $row['contact_primary'] === 1) {
            throw new \RuntimeException('the person is the primary contact of their department; archive by hand after choosing a new primary contact');
        }
        // Same as agent/post/contact.php: the portal login (client logins only) is archived, then the contact.
        if ((int) $row['contact_user_id'] > 0) {
            mysqli_query($this->mysqli, 'UPDATE users SET user_archived_at = NOW(), user_php_session = \'\' WHERE user_type = 2 AND user_id = ' . (int) $row['contact_user_id']);
        }
        mysqli_query($this->mysqli, "UPDATE contacts SET contact_important = 0, contact_billing = 0, contact_technical = 0, contact_archived_at = NOW() WHERE contact_id = $contactId");
        $this->audit('workflow.contact_archived', null, 'contact', $contactId, 'archived', $row['contact_name'] . ' archived by a lifecycle workflow', []);

        return $row['contact_name'] . ' archived (not deleted; restore the contact to undo)';
    }

    public function odooActionsAllowed(): bool
    {
        $res = @mysqli_query($this->mysqli, 'SELECT config_lifecycle_odoo_actions FROM settings WHERE company_id = 1');
        $row = $res ? mysqli_fetch_row($res) : null;

        return $row !== null && (int) $row[0] === 1;
    }

    private function odooConnector(): \ITFlow\Integrations\Odoo\OdooConnectorInterface
    {
        if (!$this->odooActionsAllowed()) {
            throw new \RuntimeException("'Allow lifecycle workflows to change Odoo' is off; nothing was sent to Odoo");
        }
        if (isset($this->graphOptions['odoo_connector'])) {
            return $this->graphOptions['odoo_connector']; // tests only: a mock, never a real Odoo
        }
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli, 'SELECT * FROM odoo_integrations WHERE enabled = 1 ORDER BY odoo_integration_id DESC LIMIT 1'));
        if (!$row) {
            throw new \RuntimeException('the Odoo connection is not set up or not enabled');
        }

        return \ITFlow\Integrations\Odoo\OdooConnectorFactory::fromRow($row);
    }

    public function odooProvisionEmployee(array $spec, int $runTaskId): string
    {
        $odoo = $this->odooConnector();
        $found = $odoo->call('hr.employee', 'search_read', [], ['domain' => [['work_email', '=ilike', $spec['email']], ['active', 'in', [true, false]]], 'fields' => ['id', 'active'], 'limit' => 2]);
        if (is_array($found) && count($found) > 0) {
            return $spec['email'] . ' already exists in Odoo (hr.employee #' . (int) $found[0]['id'] . '); nothing created';
        }
        $id = $odoo->call('hr.employee', 'create', [], ['vals_list' => [['name' => $spec['name'], 'work_email' => $spec['email'], 'job_title' => $spec['title']]]]);
        $id = is_array($id) ? ($id[0] ?? 0) : $id;
        $this->audit('workflow.odoo_employee_created', null, 'integration', 0, 'created', 'Odoo employee created for ' . $spec['email'], ['odoo_id' => (int) $id, 'run_task_id' => $runTaskId]);

        return $spec['email'] . ' created in Odoo (hr.employee #' . (int) $id . ')';
    }

    public function odooDisableEmployee(string $email, int $runTaskId): string
    {
        $odoo = $this->odooConnector();
        $found = $odoo->call('hr.employee', 'search_read', [], ['domain' => [['work_email', '=ilike', $email]], 'fields' => ['id', 'active'], 'limit' => 3]);
        if (!is_array($found) || count($found) === 0) {
            throw new \RuntimeException("no Odoo employee with work email $email; nothing was archived");
        }
        if (count($found) > 1) {
            throw new \RuntimeException("more than one Odoo employee has work email $email; nothing was archived");
        }
        $id = (int) $found[0]['id'];
        if (empty($found[0]['active'])) {
            return "Odoo employee #$id was already archived";
        }
        $odoo->call('hr.employee', 'write', [], ['ids' => [$id], 'vals' => ['active' => false]]);
        $this->audit('workflow.odoo_employee_archived', null, 'integration', 0, 'archived', "Odoo employee archived for $email", ['odoo_id' => $id, 'run_task_id' => $runTaskId]);

        return "Odoo employee #$id archived (never deleted)";
    }

    public function identityConnector(string $key): Connectors\IdentityConnector
    {
        $this->connectors ??= $this->graphOptions['connectors'] ?? $this->defaultConnectors();

        return $this->connectors->get($key);
    }

    /** The stubs, plus Okta when an administrator saved a domain and token (it still refuses until 'Allow workflows to change Okta' is on, read at call time). */
    private function defaultConnectors(): Connectors\ConnectorRegistry
    {
        $registry = new Connectors\ConnectorRegistry();
        $row = null;
        try {
            $res = @mysqli_query($this->mysqli, 'SELECT config_lifecycle_okta_domain, config_lifecycle_okta_token FROM settings WHERE company_id = 1');
            $row = $res ? mysqli_fetch_row($res) : null;
        } catch (\Throwable) {
            $row = null; // columns missing: stays a stub
        }
        if ($row && (string) $row[0] !== '' && (string) $row[1] !== '') {
            $registry->register(new Connectors\OktaConnector(
                (string) $row[0],
                function_exists('decryptSetting') ? (string) decryptSetting((string) $row[1]) : '',
                fn (): bool => $this->oktaActionsAllowed(),
                $this->graphOptions['okta_http'] ?? null,
                fn (string $event, string $action, string $summary, array $meta) => $this->audit($event, null, 'integration', 0, $action, $summary, $meta)
            ));
        }

        return $registry;
    }

    /** Is 'Allow workflows to change Okta' (settings.config_lifecycle_okta_actions) on? False on any doubt. */
    public function oktaActionsAllowed(): bool
    {
        try {
            $res = @mysqli_query($this->mysqli, 'SELECT config_lifecycle_okta_actions FROM settings WHERE company_id = 1');
            $row = $res ? mysqli_fetch_row($res) : null;
        } catch (\Throwable) {
            return false;
        }

        return $row !== null && (int) $row[0] === 1;
    }
}
