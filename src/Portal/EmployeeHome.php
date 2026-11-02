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
        'announcements' => 'Announcements',
        'kb'         => 'Help articles (popular and recently updated)',
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

    /**
     * Devices assigned to this contact with the facts an employee cares about: OS, status, warranty (and how long is left), install date and
     * when the device was last seen (the built-in endpoint agent's last check-in or the RMM link's last sync, whichever is newer). No
     * credentials, IPs, notes, logged-in user or inventory dump.
     *
     * @return list<array<string,mixed>>
     */
    public function myDeviceDetails(int $contactId, int $clientId): array
    {
        if ($contactId <= 0 || $clientId <= 0) {
            return [];
        }
        $rows = $this->rows("SELECT a.asset_id, a.asset_name, a.asset_description, a.asset_type, a.asset_make, a.asset_model, a.asset_serial, a.asset_os,
                a.asset_status, a.asset_purchase_date, a.asset_warranty_expire, a.asset_install_date, a.asset_uri_client, NOW() AS db_now,
                (SELECT MAX(d.last_checkin_at) FROM endpoint_agent_devices d WHERE d.asset_id = a.asset_id AND d.revoked_at IS NULL AND d.retired_at IS NULL) AS agent_seen,
                (SELECT d.pending_reboot FROM endpoint_agent_devices d WHERE d.asset_id = a.asset_id AND d.revoked_at IS NULL AND d.retired_at IS NULL ORDER BY d.last_checkin_at DESC LIMIT 1) AS agent_reboot,
                (SELECT MAX(l.last_seen) FROM asset_rmm_links l WHERE l.asset_id = a.asset_id) AS rmm_seen,
                (SELECT l.os_name FROM asset_rmm_links l WHERE l.asset_id = a.asset_id AND l.os_name IS NOT NULL AND l.os_name <> '' ORDER BY l.last_seen DESC LIMIT 1) AS rmm_os
            FROM assets a WHERE a.asset_contact_id = $contactId AND a.asset_client_id = $clientId AND a.asset_archived_at IS NULL
            ORDER BY a.asset_name ASC");
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::deviceFacts($r);
        }

        return $out;
    }

    /**
     * Turns a raw device row into the display/API shape. Pure (takes "now" so a test can pin it).
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function deviceFacts(array $r, ?int $now = null): array
    {
        // "Now" is the database clock when the row carries it (db_now), so PHP and database time zones can never disagree.
        $now ??= isset($r['db_now']) && strtotime((string) $r['db_now']) !== false ? strtotime((string) $r['db_now']) : time();
        $seenCandidates = array_filter([(string) ($r['agent_seen'] ?? ''), (string) ($r['rmm_seen'] ?? '')], static fn($v) => $v !== '' && strtotime($v) !== false);
        $lastSeen = $seenCandidates ? max($seenCandidates) : null;
        $seenAge = $lastSeen !== null ? max(0, $now - strtotime($lastSeen)) : null;
        $seenState = $seenAge === null ? 'unknown' : ($seenAge <= 900 ? 'online' : ($seenAge <= 3 * 86400 ? 'recent' : 'stale'));

        $warranty = (string) ($r['asset_warranty_expire'] ?? '');
        $warrantyDays = null;
        $warrantyState = 'unknown';
        if ($warranty !== '' && strtotime($warranty) !== false) {
            $warrantyDays = (int) floor((strtotime($warranty . ' 23:59:59') - $now) / 86400);
            $warrantyState = $warrantyDays < 0 ? 'expired' : ($warrantyDays <= 60 ? 'expiring' : 'ok');
        }
        $os = trim((string) ($r['asset_os'] ?? ''));
        if ($os === '') {
            $os = trim((string) ($r['rmm_os'] ?? ''));
        }

        return [
            'id' => (int) $r['asset_id'],
            'name' => (string) $r['asset_name'],
            'description' => (string) ($r['asset_description'] ?? ''),
            'type' => (string) ($r['asset_type'] ?? ''),
            'make' => (string) ($r['asset_make'] ?? ''),
            'model' => (string) ($r['asset_model'] ?? ''),
            'serial' => (string) ($r['asset_serial'] ?? ''),
            'os' => $os !== '' ? $os : null,
            'status' => (string) ($r['asset_status'] ?? ''),
            'purchase_date' => ($r['asset_purchase_date'] ?? null) ?: null,
            'install_date' => ($r['asset_install_date'] ?? null) ?: null,
            'warranty_expires' => $warranty !== '' ? $warranty : null,
            'warranty_days_left' => $warrantyDays,
            'warranty_state' => $warrantyState,
            'last_seen' => $lastSeen,
            'seen_state' => $seenState,
            'seen_age_s' => $seenAge,
            'reboot_pending' => isset($r['agent_reboot']) && $r['agent_reboot'] !== null ? ((int) $r['agent_reboot'] === 1) : null,
        ];
    }

    /** Short "last seen" wording for a deviceFacts() row: [bootstrap text colour, text]. @param array<string,mixed> $d @return array{0:string,1:string} */
    public static function seenLabel(array $d, ?int $now = null): array
    {
        if (empty($d['last_seen']) || ($d['seen_state'] ?? 'unknown') === 'unknown') {
            return ['secondary', 'Not reporting'];
        }
        $age = isset($d['seen_age_s']) ? (int) $d['seen_age_s'] : max(0, ($now ?? time()) - strtotime((string) $d['last_seen']));
        if ($d['seen_state'] === 'online') {
            return ['success', 'Online now'];
        }
        if ($age < 86400) {
            return ['success', 'Seen today'];
        }
        $days = (int) floor($age / 86400);

        return [$d['seen_state'] === 'stale' ? 'warning' : 'secondary', 'Last seen ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago'];
    }

    /** Warranty wording for a deviceFacts() row: [bootstrap colour, text]. @param array<string,mixed> $d @return array{0:string,1:string} */
    public static function warrantyLabel(array $d): array
    {
        return match ($d['warranty_state'] ?? 'unknown') {
            'expired' => ['danger', 'Warranty ended ' . $d['warranty_expires']],
            'expiring' => ['warning', 'Warranty ends in ' . (int) $d['warranty_days_left'] . ' day' . ((int) $d['warranty_days_left'] === 1 ? '' : 's')],
            'ok' => ['success', 'In warranty until ' . $d['warranty_expires']],
            default => ['secondary', 'No warranty date'],
        };
    }

    /** Help articles this department's employees may read (client visible, company-wide or their own department, not archived). */
    private function kbVisible(int $clientId): string
    {
        return "a.kb_article_client_visible = 1 AND a.kb_article_archived_at IS NULL AND a.kb_article_client_id IN (0, " . max(0, $clientId) . ")";
    }

    /** Most read articles in the last $days days. @return list<array<string,mixed>> */
    public function popularArticles(int $clientId, int $limit = 5, int $days = 30): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $limit = max(1, min(25, $limit));
        $days = max(1, min(365, $days));

        try {
            return $this->rows("SELECT a.kb_article_id, a.kb_article_title, COUNT(v.view_id) AS read_count
            FROM kb_article_views v JOIN kb_articles a ON a.kb_article_id = v.view_article_id
            WHERE v.view_at >= NOW() - INTERVAL $days DAY AND " . $this->kbVisible($clientId) . "
            GROUP BY a.kb_article_id, a.kb_article_title ORDER BY read_count DESC, MAX(v.view_at) DESC, a.kb_article_id DESC LIMIT $limit");
        } catch (\Throwable $e) {
            return []; // kb_article_views not migrated yet (DB 2.6.222)
        }
    }

    /** Newest and recently updated articles. @return list<array<string,mixed>> */
    public function recentArticles(int $clientId, int $limit = 5): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $limit = max(1, min(25, $limit));

        return $this->rows("SELECT a.kb_article_id, a.kb_article_title, COALESCE(a.kb_article_updated_at, a.kb_article_created_at) AS changed_at
            FROM kb_articles a WHERE " . $this->kbVisible($clientId) . "
            ORDER BY changed_at DESC, a.kb_article_id DESC LIMIT $limit");
    }

    /**
     * Counts one read of a help article (only when the article is visible to the reader). The same contact reading the same article again
     * inside an hour is not counted twice. Old rows are pruned now and then so the table stays small.
     */
    public function recordKbView(int $articleId, int $contactId, int $clientId): bool
    {
        if ($articleId <= 0 || $contactId <= 0 || $clientId <= 0) {
            return false;
        }
        $visible = $this->rows("SELECT a.kb_article_id FROM kb_articles a WHERE a.kb_article_id = $articleId AND " . $this->kbVisible($clientId) . " LIMIT 1");
        if (!$visible) {
            return false;
        }
        try {
            $dup = $this->rows("SELECT view_id FROM kb_article_views WHERE view_article_id = $articleId AND view_contact_id = $contactId AND view_at >= NOW() - INTERVAL 1 HOUR LIMIT 1");
            if ($dup) {
                return false;
            }
            $this->db->query("INSERT INTO kb_article_views SET view_article_id = $articleId, view_contact_id = $contactId, view_client_id = $clientId");
            if (random_int(1, 50) === 1) {
                $this->db->query("DELETE FROM kb_article_views WHERE view_at < NOW() - INTERVAL 180 DAY LIMIT 1000");
            }
        } catch (\Throwable $e) {
            return false; // kb_article_views not migrated yet (DB 2.6.222)
        }

        return true;
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
