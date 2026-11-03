<?php

namespace ITFlow\Portal;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\LifecycleEvents;
use ITFlow\Workflow\WorkflowService;

/**
 * HR-initiated requests from the employee portal (issue #31): a manager or department administrator asks for an onboarding, an offboarding or a
 * role-change workflow. The request is only a row until an HR agent approves it; approving starts the run through WorkflowService (the same code as
 * every other start), rejecting or cancelling never touches a person. Nothing here takes a department or a person from the request that the
 * requester is not allowed to name: the department is the requester's own, the person must be one of their reports (a department administrator:
 * anyone active in the department except themselves).
 *
 * Off unless settings.config_portal_hr_requests = 1 (Administration > Settings > Portal). Every state change is audited.
 */
final class HrRequests
{
    public const TYPES = ['onboarding' => 'Onboarding (new hire)', 'offboarding' => 'Offboarding (leaver)', 'role_change' => 'Role change'];
    /** A request can ask for at most this long ahead / back. */
    private const WINDOW_BACK = 30;
    private const WINDOW_FORWARD = 366;

    /** @var callable(string,?int,string,int,string,string,array):void */
    private $audit;
    /** @var callable(int,string,string,int,int):void */
    private $notify;

    /**
     * @param callable|null $audit  (event, actorUserId, entityType, entityId, action, summary, metadata); default rivetAudit()
     * @param callable|null $notify (userId, message, actionUrl, clientId, requestId); default notifyUser()
     */
    public function __construct(private \mysqli $db, ?callable $audit = null, ?callable $notify = null, private ?ActionGateway $gateway = null)
    {
        $this->audit = $audit ?? static function (string $event, ?int $actor, string $type, int $id, string $action, string $summary, array $meta = []): void {
            if (function_exists('rivetAudit')) {
                rivetAudit($event, $actor, $type, $id, $action, $summary, $meta);
            }
        };
        $this->notify = $notify ?? static function (int $userId, string $message, string $url, int $clientId, int $requestId): void {
            if (function_exists('notifyUser')) {
                notifyUser($userId, 'Workflow', $message, $url, $clientId, $requestId);
            }
        };
    }

    public static function available(\mysqli $db): bool
    {
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'hr_workflow_requests'");

        return (bool) ($res && mysqli_num_rows($res) > 0);
    }

    // ----- who may ask ----------------------------------------------------------------------------------------------------------

    /** The setting is on AND the contact is a manager (has direct reports) or a department administrator. */
    public function canRequest(bool $featureOn, int $contactId, int $clientId, bool $isDeptAdmin): bool
    {
        return $featureOn && $contactId > 0 && $clientId > 0 && ($isDeptAdmin || (new EmployeeHome($this->db))->isManager($contactId, $clientId));
    }

    /**
     * People the requester may name for an offboarding / role change: their direct reports, or (department administrator) anyone active in the
     * department. Never the requester themselves, never someone already gone. @return list<array{contact_id:int,contact_name:string,contact_title:string}>
     */
    public function subjects(int $requesterId, int $clientId, bool $isDeptAdmin): array
    {
        $scope = $isDeptAdmin ? '' : " AND contact_manager_id = $requesterId";
        $res = $this->db->query("SELECT contact_id, contact_name, COALESCE(contact_title, '') AS contact_title FROM contacts
            WHERE contact_client_id = $clientId AND contact_id <> $requesterId AND contact_archived_at IS NULL
              AND contact_employment_status NOT IN ('terminated','archived','pre-hire')$scope ORDER BY contact_name ASC LIMIT 500");
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = ['contact_id' => (int) $r['contact_id'], 'contact_name' => (string) $r['contact_name'], 'contact_title' => (string) $r['contact_title']];
        }

        return $out;
    }

    // ----- validation (pure) ----------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $in raw POST
     * @param list<int> $managerIds acceptable managers for an onboarding (this department)
     * @param list<int> $subjectIds acceptable people for the other two types
     * @param array<int,string> $currentTitles contact_id => current title (a role change must change it)
     * @return array{errors:list<string>,clean:array<string,mixed>}
     */
    public static function validate(string $type, array $in, array $managerIds, array $subjectIds, int $defaultManagerId, array $currentTitles = [], ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        if (!isset(self::TYPES[$type])) {
            return ['errors' => ['Choose what you are asking for.'], 'clean' => []];
        }
        if ($type === 'onboarding') {
            $v = EmployeeHome::validateOnboarding($in, $managerIds, $defaultManagerId, $today);

            return ['errors' => $v['errors'], 'clean' => $v['clean'] + ['type' => 'onboarding']];
        }
        $errors = [];
        $subject = (int) ($in['subject_contact_id'] ?? 0);
        if (!in_array($subject, array_map('intval', $subjectIds), true)) {
            $errors[] = 'Choose a person from your team.';
        }
        $notes = mb_substr(trim(strip_tags((string) ($in['notes'] ?? ''))), 0, 2000);
        $date = trim((string) ($in['effective_date'] ?? ''));
        $dt = \DateTime::createFromFormat('!Y-m-d', $date);
        $label = $type === 'offboarding' ? 'last working day' : 'effective date';
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $errors[] = 'Enter the ' . $label . '.';
        } elseif ($date < date('Y-m-d', strtotime("$today -" . self::WINDOW_BACK . ' days')) || $date > date('Y-m-d', strtotime("$today +" . self::WINDOW_FORWARD . ' days'))) {
            $errors[] = 'The ' . $label . ' must be within the last ' . self::WINDOW_BACK . ' days and the next year.';
        }
        $clean = ['type' => $type, 'subject_contact_id' => $subject, 'effective_date' => $date, 'notes' => $notes];
        if ($type === 'role_change') {
            $title = mb_substr(trim(strip_tags((string) ($in['new_title'] ?? ''))), 0, 200);
            if ($title === '') {
                $errors[] = 'Enter the new job title.';
            } elseif (isset($currentTitles[$subject]) && mb_strtolower(trim($currentTitles[$subject])) === mb_strtolower($title)) {
                $errors[] = 'That is already their job title.';
            }
            $clean['new_title'] = $title;
        }

        return ['errors' => $errors, 'clean' => $clean];
    }

    // ----- submit / cancel ------------------------------------------------------------------------------------------------------

    /**
     * Store a validated request and tell the HR agents. A repeat of a still-pending request (same type and person, or the same new hire email) is not stored twice.
     *
     * @param array<string,mixed> $clean validate()['clean']
     * @return array{status:string,request_id:int,message:string} status: created | duplicate | error
     */
    public function submit(array $clean, int $clientId, int $requesterContactId): array
    {
        $type = (string) ($clean['type'] ?? '');
        if (!isset(self::TYPES[$type]) || $clientId <= 0 || $requesterContactId <= 0) {
            return ['status' => 'error', 'request_id' => 0, 'message' => 'The request could not be saved.'];
        }
        $subjectId = null;
        $subjectName = '';
        $effective = null;
        if ($type === 'onboarding') {
            $subjectName = (string) $clean['name'];
            $effective = (string) $clean['start_date'];
            $email = $this->db->real_escape_string(strtolower((string) $clean['email']));
            $dupe = $this->scalar("SELECT hr_request_id FROM hr_workflow_requests WHERE hr_type = 'onboarding' AND hr_status = 'pending' AND hr_client_id = $clientId AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(hr_payload, '$.email'))) = '$email' LIMIT 1");
        } else {
            $subjectId = (int) $clean['subject_contact_id'];
            $row = $this->db->query("SELECT contact_name FROM contacts WHERE contact_id = $subjectId AND contact_client_id = $clientId AND contact_archived_at IS NULL")->fetch_row();
            if (!$row) {
                return ['status' => 'error', 'request_id' => 0, 'message' => 'That person is not in your department.'];
            }
            $subjectName = (string) $row[0];
            $effective = (string) $clean['effective_date'];
            $dupe = $this->scalar("SELECT hr_request_id FROM hr_workflow_requests WHERE hr_type = '" . $this->db->real_escape_string($type) . "' AND hr_status = 'pending' AND hr_subject_contact_id = $subjectId LIMIT 1");
        }
        if ($dupe) {
            return ['status' => 'duplicate', 'request_id' => (int) $dupe, 'message' => 'There is already a pending request of this kind for ' . $subjectName . '.'];
        }
        $payload = $clean;
        unset($payload['type']);
        $json = $this->db->real_escape_string((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $name = $this->db->real_escape_string(mb_substr($subjectName, 0, 200));
        $stmtType = $this->db->real_escape_string($type);
        $this->db->query("INSERT INTO hr_workflow_requests SET hr_type = '$stmtType', hr_client_id = $clientId, hr_requested_by_contact_id = $requesterContactId,
            hr_subject_contact_id = " . ($subjectId === null ? 'NULL' : $subjectId) . ", hr_subject_name = '$name', hr_effective_date = " . ($effective ? "'" . $this->db->real_escape_string($effective) . "'" : 'NULL') . ", hr_payload = '$json'");
        $id = (int) $this->db->insert_id;
        if ($id <= 0) {
            return ['status' => 'error', 'request_id' => 0, 'message' => 'The request could not be saved.'];
        }
        ($this->audit)('hr_request.submitted', null, 'hr_request', $id, 'submit', ucfirst(self::TYPES[$type]) . ' requested for ' . $subjectName . ' from the portal', ['type' => $type, 'client_id' => $clientId, 'requested_by_contact_id' => $requesterContactId, 'subject_contact_id' => $subjectId]);
        $this->tellApprovers($clientId, $id, 'HR request waiting: ' . strtolower(self::TYPES[$type]) . ' for ' . $subjectName);

        return ['status' => 'created', 'request_id' => $id, 'message' => 'Request sent. HR will review it before anything starts.'];
    }

    /** The requester withdraws their own pending request. */
    public function cancel(int $requestId, int $requesterContactId, int $clientId): bool
    {
        $stmt = $this->db->prepare("UPDATE hr_workflow_requests SET hr_status = 'cancelled', hr_decided_at = NOW() WHERE hr_request_id = ? AND hr_requested_by_contact_id = ? AND hr_client_id = ? AND hr_status = 'pending'");
        $stmt->bind_param('iii', $requestId, $requesterContactId, $clientId);
        $stmt->execute();
        $ok = $stmt->affected_rows === 1;
        $stmt->close();
        if ($ok) {
            ($this->audit)('hr_request.cancelled', null, 'hr_request', $requestId, 'cancel', 'HR request withdrawn by the requester', ['client_id' => $clientId, 'requested_by_contact_id' => $requesterContactId]);
        }

        return $ok;
    }

    // ----- lists ----------------------------------------------------------------------------------------------------------------

    /** The requester's own requests (newest first). @return list<array<string,mixed>> */
    public function mine(int $requesterContactId, int $clientId, int $limit = 50): array
    {
        return $this->rows("SELECT * FROM hr_workflow_requests WHERE hr_requested_by_contact_id = $requesterContactId AND hr_client_id = $clientId ORDER BY hr_request_id DESC LIMIT " . max(1, min(200, $limit)));
    }

    /**
     * What an agent may see: requests of departments they can access, newest pending first. $allowedClientIds = null means every department.
     * @param list<int>|null $allowedClientIds @return list<array<string,mixed>>
     */
    public function queue(?array $allowedClientIds, string $status = 'pending', int $limit = 100): array
    {
        $where = in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true) ? "r.hr_status = '$status'" : '1=1';
        if ($allowedClientIds !== null) {
            if (!$allowedClientIds) {
                return [];
            }
            $where .= ' AND r.hr_client_id IN (' . implode(',', array_map('intval', $allowedClientIds)) . ')';
        }

        return $this->rows("SELECT r.*, cl.client_name, rq.contact_name AS requester_name FROM hr_workflow_requests r
            LEFT JOIN clients cl ON cl.client_id = r.hr_client_id LEFT JOIN contacts rq ON rq.contact_id = r.hr_requested_by_contact_id
            WHERE $where ORDER BY r.hr_request_id DESC LIMIT " . max(1, min(300, $limit)));
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $r = $this->rows("SELECT r.*, cl.client_name, rq.contact_name AS requester_name FROM hr_workflow_requests r
            LEFT JOIN clients cl ON cl.client_id = r.hr_client_id LEFT JOIN contacts rq ON rq.contact_id = r.hr_requested_by_contact_id WHERE r.hr_request_id = $id");

        return $r[0] ?? null;
    }

    /** Active templates of the type this request needs. @return list<array{workflow_template_id:int,name:string}> */
    public function templatesFor(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            return [];
        }
        $t = $this->db->real_escape_string($type);

        return $this->rows("SELECT workflow_template_id, name FROM workflow_templates WHERE type = '$t' AND is_active = 1 AND archived_at IS NULL ORDER BY name ASC");
    }

    // ----- who may decide -------------------------------------------------------------------------------------------------------

    /** Department administrators of HR: admin roles, or a role with Departments edit access, within their department access. */
    public function canDecide(int $userId, int $clientId): bool
    {
        $u = $this->db->query("SELECT u.user_role_id, COALESCE(r.role_is_admin, 0) AS is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
            WHERE u.user_id = $userId AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL")->fetch_assoc();
        if (!$u) {
            return false;
        }
        if ((int) $u['is_admin'] === 1) {
            return true;
        }
        $lvl = $this->db->query("SELECT p.user_role_permission_level FROM modules m JOIN user_role_permissions p ON p.module_id = m.module_id WHERE m.module_name = 'module_client' AND p.user_role_id = " . (int) $u['user_role_id'])->fetch_row();
        if (!$lvl || (int) $lvl[0] < 2) {
            return false;
        }

        return $this->clientAllowed($userId, $clientId);
    }

    /** Same rule as enforceClientAccess(): no permission rows = every department; otherwise only the listed ones. */
    public function clientAllowed(int $userId, int $clientId): bool
    {
        $any = (int) $this->db->query("SELECT COUNT(*) FROM user_client_permissions WHERE user_id = $userId")->fetch_row()[0];
        if ($any === 0) {
            return true;
        }

        return (int) $this->db->query("SELECT COUNT(*) FROM user_client_permissions WHERE user_id = $userId AND client_id = $clientId")->fetch_row()[0] > 0;
    }

    /** Departments an agent may act on; null = all (admin, or no restriction rows). @return list<int>|null */
    public function allowedClients(int $userId): ?array
    {
        $u = $this->db->query("SELECT COALESCE(r.role_is_admin, 0) FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = $userId")->fetch_row();
        if ($u && (int) $u[0] === 1) {
            return null;
        }
        $ids = array_map(static fn ($r) => (int) $r['client_id'], $this->rows("SELECT client_id FROM user_client_permissions WHERE user_id = $userId"));

        return $ids ?: null;
    }

    /** @return list<int> */
    public function approverUserIds(int $clientId): array
    {
        $out = [];
        foreach ($this->rows("SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL") as $u) {
            if ($this->canDecide((int) $u['user_id'], $clientId)) {
                $out[] = (int) $u['user_id'];
            }
        }

        return $out;
    }

    private function tellApprovers(int $clientId, int $requestId, string $message): void
    {
        try {
            foreach ($this->approverUserIds($clientId) as $uid) {
                ($this->notify)($uid, $message, 'hr_requests.php?request_id=' . $requestId, $clientId, $requestId);
            }
        } catch (\Throwable $e) {
            error_log('hr request notify skipped: ' . get_class($e));
        }
    }

    // ----- decide ---------------------------------------------------------------------------------------------------------------

    /**
     * Approve (start the workflow) or reject a pending request. Claimed atomically first, so two agents cannot both act; if starting fails the claim is released.
     *
     * @return array{status:string,run_id:int,contact_id:int,message:string}
     * @throws \DomainException with a message safe to show
     */
    public function decide(int $requestId, int $userId, bool $approve, int $templateId = 0, string $comment = ''): array
    {
        $req = $this->get($requestId);
        if (!$req) {
            throw new \DomainException('That request does not exist.');
        }
        $clientId = (int) $req['hr_client_id'];
        if (!$this->canDecide($userId, $clientId)) {
            throw new \DomainException('You cannot decide requests for this department.');
        }
        if ($req['hr_status'] !== 'pending') {
            throw new \DomainException('That request was already ' . $req['hr_status'] . '.');
        }
        $comment = mb_substr(trim(strip_tags($comment)), 0, 500);
        $type = (string) $req['hr_type'];
        $payload = json_decode((string) $req['hr_payload'], true) ?: [];

        if (!$approve) {
            if ($comment === '') {
                throw new \DomainException('Say why you are rejecting it; the requester sees this.');
            }
            $this->claim($requestId, 'rejected', $userId, $comment, null);
            ($this->audit)('hr_request.rejected', $userId, 'hr_request', $requestId, 'reject', 'HR request rejected: nothing was started', ['type' => $type, 'client_id' => $clientId]);

            return ['status' => 'rejected', 'run_id' => 0, 'contact_id' => (int) ($req['hr_subject_contact_id'] ?? 0), 'message' => 'Rejected. Nothing was started.'];
        }

        $usable = array_column($this->templatesFor($type), 'workflow_template_id');
        if (!in_array($templateId, array_map('intval', $usable), true)) {
            throw new \DomainException($usable ? 'Choose the workflow template to start.' : 'There is no active ' . strtolower(self::TYPES[$type]) . ' workflow template. Create one first (Administration > Employee Workflow Templates).');
        }
        $this->claim($requestId, 'approved', $userId, $comment, $templateId);
        try {
            [$contactId, $runId] = $this->start($req, $payload, $templateId, $userId);
        } catch (\Throwable $e) {
            $this->db->query("UPDATE hr_workflow_requests SET hr_status = 'pending', hr_decided_by_user_id = NULL, hr_decided_at = NULL, hr_decision_comment = NULL, hr_template_id = NULL, hr_run_id = NULL WHERE hr_request_id = $requestId AND hr_status = 'approved' AND hr_run_id IS NULL");
            throw new \DomainException($e instanceof \DomainException || $e instanceof \InvalidArgumentException ? $e->getMessage() : 'The workflow could not be started; the request is still pending.');
        }
        $this->db->query("UPDATE hr_workflow_requests SET hr_run_id = " . ($runId > 0 ? $runId : 'NULL') . ", hr_subject_contact_id = $contactId WHERE hr_request_id = $requestId");
        ($this->audit)('hr_request.approved', $userId, 'hr_request', $requestId, 'approve', 'HR request approved: ' . strtolower(self::TYPES[$type]) . ' workflow started', ['type' => $type, 'client_id' => $clientId, 'contact_id' => $contactId, 'run_id' => $runId, 'template_id' => $templateId]);

        return ['status' => 'approved', 'run_id' => $runId, 'contact_id' => $contactId, 'message' => 'Approved. The workflow has started.'];
    }

    private function claim(int $requestId, string $status, int $userId, string $comment, ?int $templateId): void
    {
        $stmt = $this->db->prepare("UPDATE hr_workflow_requests SET hr_status = ?, hr_decided_by_user_id = ?, hr_decided_at = NOW(), hr_decision_comment = ?, hr_template_id = ? WHERE hr_request_id = ? AND hr_status = 'pending'");
        $c = $comment === '' ? null : $comment;
        $stmt->bind_param('sisii', $status, $userId, $c, $templateId, $requestId);
        $stmt->execute();
        $won = $stmt->affected_rows === 1;
        $stmt->close();
        if (!$won) {
            throw new \DomainException('Someone else just decided that request.');
        }
    }

    /**
     * @param array<string,mixed> $req
     * @param array<string,mixed> $payload
     * @return array{0:int,1:int} contact id, run id
     */
    private function start(array $req, array $payload, int $templateId, int $userId): array
    {
        $clientId = (int) $req['hr_client_id'];
        $type = (string) $req['hr_type'];
        $workflow = new WorkflowService($this->db, $this->gateway);

        if ($type === 'onboarding') {
            $eh = new EmployeeHome($this->db);
            // The manager must still be an active person of the same department (they may have left since the request).
            $managers = array_map(static fn ($m) => (int) $m['contact_id'], $eh->departmentManagers($clientId));
            if (!in_array((int) ($payload['manager_id'] ?? 0), $managers, true)) {
                throw new \DomainException('The manager named in the request is no longer active in this department. Reject it and ask for a new one.');
            }
            $res = $eh->createOnboarding($payload, $clientId, $templateId, $workflow);
            if ($res['status'] === 'exists_active' || $res['status'] === 'error') {
                throw new \DomainException($res['message']);
            }
            if ($res['status'] === 'created') {
                LifecycleEvents::afterChange($this->db, $res['contact_id'], null, $this->gateway);
            }

            return [$res['contact_id'], $res['run_id']];
        }

        $contactId = (int) ($req['hr_subject_contact_id'] ?? 0);
        $person = $this->db->query("SELECT contact_id, contact_title, contact_employment_status FROM contacts WHERE contact_id = $contactId AND contact_client_id = $clientId AND contact_archived_at IS NULL")->fetch_assoc();
        if (!$person || in_array($person['contact_employment_status'], ['terminated', 'archived'], true)) {
            throw new \DomainException('That person is no longer active in this department.');
        }
        $notes = 'HR request #' . (int) $req['hr_request_id'] . ' from ' . ($req['requester_name'] ?? 'the portal') . '. ';
        if ($type === 'offboarding') {
            $before = LifecycleEvents::snapshot($this->db, $contactId);
            $end = $this->db->real_escape_string((string) ($payload['effective_date'] ?? ''));
            // The end date anchors the template's due dates, so it is set before the run resolves them.
            $this->db->query("UPDATE contacts SET contact_expected_end_date = '$end' WHERE contact_id = $contactId AND contact_client_id = $clientId");
            $notes .= 'Last working day ' . ($payload['effective_date'] ?? '') . '.';
        } else {
            $before = null;
            $notes .= 'New job title "' . ($payload['new_title'] ?? '') . '" from ' . ($payload['effective_date'] ?? '') . ' (the record is not changed until HR does it).';
        }
        if (!empty($payload['notes'])) {
            $notes .= ' ' . $payload['notes'];
        }
        $runId = $workflow->startRunIfNone($templateId, $contactId, $userId);
        $runId ??= $workflow->openRunId($templateId, $contactId);
        if ($runId > 0) {
            $stmt = $this->db->prepare('UPDATE workflow_runs SET notes = ? WHERE run_id = ? AND (notes IS NULL OR notes = \'\')');
            $n = mb_substr($notes, 0, 4000);
            $stmt->bind_param('si', $n, $runId);
            $stmt->execute();
            $stmt->close();
        }
        if ($type === 'offboarding' && $before !== null) {
            LifecycleEvents::afterChange($this->db, $contactId, $before, $this->gateway);
        }

        return [$contactId, $runId];
    }

    // ----- helpers --------------------------------------------------------------------------------------------------------------

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

    private function scalar(string $sql): mixed
    {
        $res = $this->db->query($sql);
        $row = $res ? $res->fetch_row() : null;

        return $row[0] ?? null;
    }
}
