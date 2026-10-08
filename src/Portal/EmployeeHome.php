<?php

namespace ITFlow\Portal;

use ITFlow\ITSM\ServiceCatalogService;
use ITFlow\Workflow\WorkflowService;

/**
 * Employee self-service portal: every query here is scoped to ONE contact AND that contact's own department (client). Nothing takes
 * a contact or client id from request data; the portal pages pass $session_contact_id / $session_client_id. A contact id of 0
 * (an admin preview, which has no contact) returns nothing.
 *
 * The second half is the HR-initiated onboarding request: pure validation (validateOnboarding) and the create step
 * (createOnboarding), which makes the new hire's contact record in the requester's own department and starts the department's
 * onboarding workflow once, or reports that a ticket is needed instead.
 */
class EmployeeHome
{
    /** Home sections an administrator can switch off. */
    public const SECTIONS = [
        'requests'   => 'My open requests',
        'approvals'  => 'Waiting on me',
        'devices'    => 'My devices',
        'onboarding' => 'My onboarding / offboarding checklist',
        'training'   => 'My training due',
        'catalog'    => 'Request something (search, popular, recent)',
    ];

    public function __construct(private \mysqli $db)
    {
    }

    /** @return list<string> the enabled section keys; NULL (column missing / not migrated) means all of them */
    public static function parseSections(?string $csv): array
    {
        if ($csv === null) {
            return array_keys(self::SECTIONS);
        }
        $out = [];
        foreach (explode(',', $csv) as $k) {
            $k = trim($k);
            if (isset(self::SECTIONS[$k]) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** @param array<int|string,mixed> $posted */
    public static function formatSections(array $posted): string
    {
        return implode(',', self::parseSections(implode(',', array_map('strval', $posted))));
    }

    /** Open tickets this contact raised, most recently touched first. @return list<array<string,mixed>> */
    public function myOpenRequests(int $contactId, int $clientId, int $limit = 5): array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));

        return $this->rows("SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, s.ticket_status_name,
                COALESCE(t.ticket_updated_at, t.ticket_created_at) AS last_update
            FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
            WHERE t.ticket_contact_id = $contactId AND t.ticket_client_id = $clientId AND t.ticket_closed_at IS NULL AND t.ticket_archived_at IS NULL
            ORDER BY last_update DESC, t.ticket_id DESC LIMIT $limit");
    }

    /** Approvals waiting on this contact (service catalog requests from their team). @return list<array<string,mixed>> */
    public function waitingOnMe(int $contactId, int $clientId): array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return [];
        }

        return (new ServiceCatalogService($this->db))->pendingApprovals(null, $contactId, $clientId);
    }

    /** Devices assigned to this contact. Name, type, make/model, serial, purchase and warranty only: no credentials, IPs or notes. */
    public function myDevices(int $contactId, int $clientId): array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return [];
        }

        return $this->rows("SELECT asset_id, asset_name, asset_description, asset_type, asset_make, asset_model, asset_serial,
                asset_purchase_date, asset_warranty_expire, asset_status, asset_uri_client
            FROM assets WHERE asset_contact_id = $contactId AND asset_client_id = $clientId AND asset_archived_at IS NULL
            ORDER BY asset_name ASC");
    }

    /** One asset, only if it is assigned to this contact (used to prefill "report a problem"). */
    public function myDevice(int $assetId, int $contactId, int $clientId): ?array
    {
        if ($assetId <= 0 || $contactId <= 0 || $clientId <= 0) {
            return null;
        }
        $r = $this->rows("SELECT asset_id, asset_name, asset_type FROM assets WHERE asset_id = $assetId AND asset_contact_id = $contactId AND asset_client_id = $clientId AND asset_archived_at IS NULL LIMIT 1");

        return $r[0] ?? null;
    }

    /**
     * The employee's own most relevant onboarding/offboarding run (open first, then newest) and its tasks, read-only.
     * Only title, owner label, state and due date are returned: template instructions, automation settings and errors stay internal,
     * and automated (action) tasks are left out.
     *
     * @return array{run: array<string,mixed>, tasks: list<array<string,mixed>>, done: int, total: int}|null null when no run exists
     */
    public function myChecklist(int $contactId, int $clientId): ?array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return null;
        }
        $runs = $this->rows("SELECT r.run_id, r.type, r.status, r.started_at, t.name AS template_name
            FROM workflow_runs r JOIN contacts c ON c.contact_id = r.contact_id
            LEFT JOIN workflow_templates t ON t.workflow_template_id = r.workflow_template_id
            WHERE r.contact_id = $contactId AND c.contact_client_id = $clientId AND r.status <> 'cancelled'
            ORDER BY (r.status IN ('in_progress','paused')) DESC, r.run_id DESC LIMIT 1");
        if (!$runs) {
            return null;
        }
        $runId = (int) $runs[0]['run_id'];
        $tasks = $this->rows("SELECT run_task_id, title, default_owner, status, task_type, approval_status, due_at, required
            FROM workflow_run_tasks WHERE run_id = $runId AND task_type <> 'action' ORDER BY sort_order ASC, run_task_id ASC");
        $done = 0;
        foreach ($tasks as $t) {
            if (in_array($t['status'], ['completed', 'skipped'], true)) {
                $done++;
            }
        }

        return ['run' => $runs[0], 'tasks' => $tasks, 'done' => $done, 'total' => count($tasks)];
    }

    /** Read-only profile facts: manager, location, start date. @return array<string,mixed>|null */
    public function profileFacts(int $contactId, int $clientId): ?array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return null;
        }
        $r = $this->rows("SELECT c.contact_title, c.contact_department, c.contact_start_date, c.contact_phone, c.contact_mobile,
                m.contact_name AS manager_name, l.location_name
            FROM contacts c
            LEFT JOIN contacts m ON m.contact_id = c.contact_manager_id AND m.contact_client_id = c.contact_client_id
            LEFT JOIN locations l ON l.location_id = c.contact_location_id
            WHERE c.contact_id = $contactId AND c.contact_client_id = $clientId LIMIT 1");

        return $r[0] ?? null;
    }

    /** Does this contact have direct reports in the department (i.e. are they a manager)? */
    public function isManager(int $contactId, int $clientId): bool
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return false;
        }

        return (int) $this->db->query("SELECT COUNT(*) FROM contacts WHERE contact_manager_id = $contactId AND contact_client_id = $clientId AND contact_archived_at IS NULL")->fetch_row()[0] > 0;
    }

    /**
     * Who may request onboarding: the setting must be on AND the contact is a manager (has direct reports) or a department
     * administrator (primary / technical contact). Module-only logins and ordinary employees never qualify.
     */
    public function canRequestOnboarding(bool $featureOn, int $contactId, int $clientId, bool $isDeptAdmin): bool
    {
        return $featureOn && $contactId > 0 && ($isDeptAdmin || $this->isManager($contactId, $clientId));
    }

    /** Active onboarding template ids/names, for the settings page. @return list<array<string,mixed>> */
    public function onboardingTemplates(): array
    {
        return $this->rows("SELECT workflow_template_id, name FROM workflow_templates WHERE type = 'onboarding' AND is_active = 1 AND archived_at IS NULL ORDER BY name ASC");
    }

    public function templateUsable(int $templateId): bool
    {
        return $templateId > 0 && $this->rows("SELECT 1 FROM workflow_templates WHERE workflow_template_id = $templateId AND type = 'onboarding' AND is_active = 1 AND archived_at IS NULL")  !== [];
    }

    /** Active contacts of this department who may be named manager. @return list<array<string,mixed>> */
    public function departmentManagers(int $clientId): array
    {
        return $this->rows("SELECT contact_id, contact_name FROM contacts WHERE contact_client_id = $clientId AND contact_archived_at IS NULL AND contact_employment_status NOT IN ('terminated','archived') ORDER BY contact_name ASC");
    }

    /**
     * Pure validation of the onboarding form. $managerIds is the set of acceptable manager contact ids (this department only).
     *
     * @param array<string,mixed> $in raw POST
     * @return array{errors: list<string>, clean: array<string,mixed>}
     */
    public static function validateOnboarding(array $in, array $managerIds, int $defaultManagerId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $errors = [];
        $text = static fn(string $k, int $max): string => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);

        $name = $text('name', 200);
        if ($name === '' || mb_strlen(trim(strip_tags((string) ($in['name'] ?? '')))) > 200) {
            $errors[] = 'Enter the new hire\'s full name (200 characters at most).';
        }

        $email = trim((string) ($in['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            $errors[] = 'Enter a valid email address (personal or work).';
        }

        $start = trim((string) ($in['start_date'] ?? ''));
        $dt = \DateTime::createFromFormat('!Y-m-d', $start);
        if (!$dt || $dt->format('Y-m-d') !== $start) {
            $errors[] = 'Enter the start date.';
        } elseif ($start < date('Y-m-d', strtotime("$today -30 days")) || $start > date('Y-m-d', strtotime("$today +366 days"))) {
            $errors[] = 'The start date must be within the last 30 days and the next year.';
        }

        $managerId = intval($in['manager_id'] ?? 0) ?: $defaultManagerId;
        if (!in_array($managerId, array_map('intval', $managerIds), true)) {
            $errors[] = 'Choose a manager from your department.';
        }

        $title = $text('title', 200);
        $notes = mb_substr(trim(strip_tags((string) ($in['notes'] ?? ''))), 0, 2000);

        return ['errors' => $errors, 'clean' => [
            'name' => $name, 'email' => $email, 'start_date' => $start, 'manager_id' => $managerId, 'title' => $title, 'notes' => $notes,
        ]];
    }

    /**
     * Create the new hire and start onboarding. Idempotent: the same email in the same department that is already a pre-hire
     * (or has an open onboarding run) is reused, never duplicated, and no second run starts.
     *
     * @param array<string,mixed> $clean output of validateOnboarding()['clean'] (already validated)
     * @return array{status: string, contact_id: int, run_id: int, needs_ticket: bool, message: string}
     *         status: created | existing | exists_active | error
     */
    public function createOnboarding(array $clean, int $clientId, int $templateId, ?WorkflowService $workflow = null): array
    {
        $e = $this->db->real_escape_string(...);
        $email = $e($clean['email']);
        $found = $this->rows("SELECT contact_id, contact_employment_status FROM contacts WHERE contact_client_id = $clientId AND contact_archived_at IS NULL AND LOWER(contact_email) = LOWER('$email') LIMIT 1");
        $existing = false;
        if ($found) {
            if (strtolower((string) $found[0]['contact_employment_status']) !== 'pre-hire') {
                return ['status' => 'exists_active', 'contact_id' => (int) $found[0]['contact_id'], 'run_id' => 0, 'needs_ticket' => false, 'message' => 'Someone with that email address already exists in your department.'];
            }
            $contactId = (int) $found[0]['contact_id'];
            $existing = true;
        } else {
            $name = $e($clean['name']);
            $title = $e($clean['title']);
            $notes = $e($clean['notes']);
            $manager = (int) $clean['manager_id'];
            $start = $e($clean['start_date']);
            $this->db->query("INSERT INTO contacts SET contact_name = '$name', contact_email = '$email', contact_title = '$title', contact_notes = '$notes',
                contact_manager_id = $manager, contact_employment_status = 'pre-hire', contact_employee_type = 'employee',
                contact_start_date = '$start', contact_client_id = $clientId");
            $contactId = (int) $this->db->insert_id;
            if ($contactId <= 0) {
                return ['status' => 'error', 'contact_id' => 0, 'run_id' => 0, 'needs_ticket' => false, 'message' => 'The new hire could not be saved.'];
            }
        }

        $runId = 0;
        $needsTicket = !$existing;
        if ($this->templateUsable($templateId)) {
            $workflow ??= new WorkflowService($this->db);
            $started = $workflow->startRunIfNone($templateId, $contactId, null);
            $runId = $started ?? $workflow->openRunId($templateId, $contactId);
            $needsTicket = false;
        }

        return ['status' => $existing ? 'existing' : 'created', 'contact_id' => $contactId, 'run_id' => $runId, 'needs_ticket' => $needsTicket,
            'message' => $existing ? 'Onboarding was already requested for this person.' : 'Onboarding requested.'];
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql): array
    {
        $out = [];
        $res = $this->db->query($sql);
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }

        return $out;
    }
}
