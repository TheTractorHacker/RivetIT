<?php

namespace ITFlow\Chat;

use ITFlow\ITSM\ServiceCatalogService;
use ITFlow\Workflow\WorkflowService;

/**
 * Everything a chat user can DO, independent of the chat platform. Slack, Teams and any other code (other modules that want to offer an action
 * from a chat message) call this one service; it never knows what a Slack payload or a Teams activity looks like.
 *
 * An $actor is what ChatIdentity::resolve() returns: an agent (kind 'agent', user_id) or a portal contact (kind 'contact', contact_id, client_id).
 * Every method re-checks the actor's rights the same way the web app does, so the platform layers can stay thin:
 *   - agents need the support module (read for viewing, write for changing) and department access (user_client_permissions); administrators may do all;
 *   - a contact can only create requests for, look at and reply to tickets they raised themselves, in their own department;
 *   - closing or resolving a ticket is NOT offered from chat (the web app does the SLA, billing and notification work around it);
 *   - approvals can only be decided by an approver the request was actually addressed to (no administrator override from chat).
 * Each method returns ['ok' => bool, 'text' => string, ...]; text is plain text for the user, safe to put into a chat message after escaping.
 * Every change is audited with the actor and the platform ($via) and puts the same events on the event bus as the web app does.
 */
final class ChatActions
{
    public const PRIORITIES = ['Low', 'Medium', 'High'];

    /** @var callable */
    private $audit;
    private ChatSettings $settings;

    public function __construct(private \mysqli $db, ?callable $audit = null, ?ChatSettings $settings = null, private ?\ITFlow\Workflow\ActionGateway $workflowGateway = null)
    {
        $this->settings = $settings ?? new ChatSettings($db);
        $this->audit = $audit ?? static function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []): void {
            if (function_exists('rivetAudit')) {
                \rivetAudit($e, $a, $et, $id, $act, $sum, $meta);
            }
        };
    }

    // ----- rights ------------------------------------------------------------------------------------------------------

    /** @param array<string,mixed> $actor */
    public function mayRead(array $actor, int $clientId): bool
    {
        return $actor['kind'] === 'agent' && $this->agentAllowed((int) $actor['user_id'], $clientId, 1);
    }

    /** @param array<string,mixed> $actor */
    public function mayWork(array $actor, int $clientId): bool
    {
        return $actor['kind'] === 'agent' && $this->agentAllowed((int) $actor['user_id'], $clientId, 2);
    }

    public function agentAllowed(int $userId, int $clientId, int $level, string $module = 'module_support'): bool
    {
        $u = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT u.user_role_id, COALESCE(r.role_is_admin, 0) AS is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = $userId AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL"));
        if (!$u) {
            return false;
        }
        if ((int) $u['is_admin'] === 1) {
            return true;
        }
        $role = (int) $u['user_role_id'];
        $stmt = mysqli_prepare($this->db, 'SELECT p.user_role_permission_level FROM modules m JOIN user_role_permissions p ON p.module_id = m.module_id WHERE m.module_name = ? AND p.user_role_id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $module, $role);
        mysqli_stmt_execute($stmt);
        $lvl = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$lvl || (int) $lvl[0] < $level) {
            return false;
        }

        return $clientId <= 0 || $this->deptOk($userId, $clientId);
    }

    private function deptOk(int $userId, int $clientId): bool
    {
        $any = mysqli_fetch_row(mysqli_query($this->db, "SELECT 1 FROM user_client_permissions WHERE user_id = $userId LIMIT 1"));
        if (!$any) {
            return true;
        }

        return (bool) mysqli_fetch_row(mysqli_query($this->db, "SELECT 1 FROM user_client_permissions WHERE user_id = $userId AND client_id = $clientId LIMIT 1"));
    }

    /** Departments (clients) this agent may raise tickets in, for a picker. @return list<array{id:int,name:string}> */
    public function clientsFor(array $actor, int $limit = 100): array
    {
        if ($actor['kind'] !== 'agent') {
            return [];
        }
        $out = [];
        $res = mysqli_query($this->db, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name ASC LIMIT 500');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            if ($this->mayWork($actor, (int) $r['client_id'])) {
                $out[] = ['id' => (int) $r['client_id'], 'name' => (string) $r['client_name']];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    // ----- reading -----------------------------------------------------------------------------------------------------

    /** A ticket by "TCK-1001", "1001" or an internal id given as "id:57"; null when it does not exist OR the actor may not see it. @return array<string,mixed>|null */
    public function findTicket(array $actor, string $ref): ?array
    {
        $ref = trim($ref, " \t#");
        if ($ref === '' || strlen($ref) > 40) {
            return null;
        }
        if (preg_match('/^id:([1-9][0-9]{0,9})$/', $ref, $m)) {
            $stmt = mysqli_prepare($this->db, 'SELECT * FROM tickets WHERE ticket_id = ? AND ticket_archived_at IS NULL LIMIT 1');
            $n = (int) $m[1];
            mysqli_stmt_bind_param($stmt, 'i', $n);
        } elseif (ctype_digit($ref)) {
            $stmt = mysqli_prepare($this->db, 'SELECT * FROM tickets WHERE ticket_number = ? AND ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 1');
            $n = (int) $ref;
            mysqli_stmt_bind_param($stmt, 'i', $n);
        } else {
            $stmt = mysqli_prepare($this->db, 'SELECT * FROM tickets WHERE LOWER(CONCAT(COALESCE(ticket_prefix, \'\'), ticket_number)) = LOWER(?) AND ticket_archived_at IS NULL ORDER BY ticket_id DESC LIMIT 1');
            mysqli_stmt_bind_param($stmt, 's', $ref);
        }
        mysqli_stmt_execute($stmt);
        $t = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: null;
        mysqli_stmt_close($stmt);

        return $t !== null && $this->canSee($actor, $t) ? $t : null;
    }

    public function canSee(array $actor, array $ticket): bool
    {
        if ($actor['kind'] === 'agent') {
            return $this->mayRead($actor, (int) $ticket['ticket_client_id']);
        }

        return (int) $ticket['ticket_contact_id'] === (int) $actor['contact_id'] && (int) $ticket['ticket_client_id'] === (int) $actor['client_id'];
    }

    public function statusName(int $statusId): string
    {
        $r = mysqli_fetch_row(mysqli_query($this->db, 'SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = ' . $statusId));

        return (string) ($r[0] ?? '');
    }

    public function label(array $t): string
    {
        return (string) ($t['ticket_prefix'] ?? '') . $t['ticket_number'];
    }

    /** One line per fact; plain text. */
    public function describe(array $t): string
    {
        $assignee = (int) $t['ticket_assigned_to'] > 0 ? (string) (mysqli_fetch_row(mysqli_query($this->db, 'SELECT user_name FROM users WHERE user_id = ' . (int) $t['ticket_assigned_to']))[0] ?? '') : '';
        $client = (string) (mysqli_fetch_row(mysqli_query($this->db, 'SELECT client_name FROM clients WHERE client_id = ' . (int) $t['ticket_client_id']))[0] ?? '');

        return $this->label($t) . ' ' . $t['ticket_subject'] . "\n"
            . 'Status: ' . ($t['ticket_closed_at'] !== null ? 'Closed' : $this->statusName((int) $t['ticket_status']))
            . ' | Priority: ' . ($t['ticket_priority'] ?: 'none')
            . ' | Assigned to: ' . ($assignee !== '' ? $assignee : 'nobody')
            . ($client !== '' ? ' | Client: ' . $client : '');
    }

    /** @return array{ok:bool,text:string,ticket_id?:int} */
    public function status(array $actor, string $ref): array
    {
        $t = $this->findTicket($actor, $ref);
        if ($t === null) {
            return ['ok' => false, 'text' => 'I could not find a ticket "' . mb_substr(trim($ref), 0, 40) . '" that you can see.'];
        }

        return ['ok' => true, 'text' => $this->describe($t), 'ticket_id' => (int) $t['ticket_id']];
    }

    // ----- creating ----------------------------------------------------------------------------------------------------

    /**
     * Create a ticket from chat. An agent names the department ($clientId) or the install's chat default is used; a contact always gets their own.
     *
     * @return array{ok:bool,text:string,ticket_id?:int}
     */
    public function createTicket(array $actor, string $subject, string $details = '', string $priority = '', int $clientId = 0, string $via = 'Chat'): array
    {
        $subject = trim(preg_replace('/\s+/', ' ', strip_tags($subject)) ?? '');
        if ($subject === '') {
            return ['ok' => false, 'text' => 'A ticket needs a subject.'];
        }
        $subject = mb_substr($subject, 0, 200);
        $priority = in_array($priority, self::PRIORITIES, true) ? $priority : 'Low';
        $contactId = 0;
        $createdBy = 0;
        if ($actor['kind'] === 'contact') {
            $clientId = (int) $actor['client_id'];
            $contactId = (int) $actor['contact_id'];
            $priority = 'Low'; // a requester does not set priority; the team triages
        } else {
            if ($clientId <= 0) {
                $clientId = $this->settings->int('config_chat_default_client_id');
            }
            if ($clientId <= 0) {
                return ['ok' => false, 'text' => 'Say which client: "/ticket new client=<id> <subject>", or ask an administrator to set a default client for chat tickets.'];
            }
            if (!$this->mayWork($actor, $clientId)) {
                return ['ok' => false, 'text' => 'You are not allowed to create tickets for that client.'];
            }
            if (!mysqli_fetch_row(mysqli_query($this->db, "SELECT 1 FROM clients WHERE client_id = $clientId AND client_archived_at IS NULL"))) {
                return ['ok' => false, 'text' => 'That client does not exist.'];
            }
            $createdBy = (int) $actor['user_id'];
        }

        $ticketId = $this->insertTicket($subject, $this->htmlBody($details), $priority, $clientId, $contactId, $createdBy, $via);
        if ($ticketId <= 0) {
            return ['ok' => false, 'text' => 'The ticket could not be created.'];
        }
        $t = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT * FROM tickets WHERE ticket_id = $ticketId"));
        $this->event('ticket.created', $ticketId);
        ($this->audit)('chat.ticket_created', $createdBy ?: null, 'ticket', $ticketId, 'created', 'Ticket ' . $this->label($t) . ' created from ' . $via . ' by ' . $actor['name'], ['ticket_id' => $ticketId, 'via' => strtolower($via), 'actor_kind' => $actor['kind']]);

        return ['ok' => true, 'text' => 'Created ' . $this->label($t) . ': ' . $subject, 'ticket_id' => $ticketId];
    }

    private function insertTicket(string $subject, string $detailsHtml, string $priority, int $clientId, int $contactId, int $createdBy, string $via): int
    {
        $this->db->begin_transaction();
        try {
            $row = mysqli_fetch_assoc(mysqli_query($this->db, 'SELECT config_ticket_prefix, config_ticket_default_technician_id FROM settings WHERE company_id = 1')) ?: [];
            $prefix = (string) ($row['config_ticket_prefix'] ?? '');
            $assigned = (int) ($row['config_ticket_default_technician_id'] ?? 0);
            mysqli_query($this->db, 'UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
            $number = (int) mysqli_insert_id($this->db);
            $status = $assigned > 0 ? 2 : 1;
            $source = mb_substr($via, 0, 100);
            $key = bin2hex(random_bytes(16));
            $stmt = mysqli_prepare($this->db, 'INSERT INTO tickets SET ticket_prefix = ?, ticket_number = ?, ticket_subject = ?, ticket_details = ?, ticket_status = ?, ticket_priority = ?, ticket_source = ?, ticket_client_id = ?, ticket_contact_id = ?, ticket_created_by = ?, ticket_assigned_to = ?, ticket_url_key = ?, ticket_created_at = NOW()');
            mysqli_stmt_bind_param($stmt, 'sississiiiis', $prefix, $number, $subject, $detailsHtml, $status, $priority, $source, $clientId, $contactId, $createdBy, $assigned, $key);
            mysqli_stmt_execute($stmt);
            $id = (int) mysqli_stmt_insert_id($stmt);
            mysqli_stmt_close($stmt);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return 0;
        }
        if ($id > 0 && function_exists('slaSyncPause')) {
            try { \slaSyncPause($this->db, $id); } catch (\Throwable $e) { /* no SLA tables */ }
        }

        return $id;
    }

    /** Plain text -> the HTML the ticket stores: escaped, paragraphs and line breaks only. */
    public function htmlBody(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return '<p>Created from chat.</p>';
        }
        $paras = array_map(static fn (string $p) => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES, 'UTF-8'), false) . '</p>', preg_split('/\n{2,}/', mb_substr($text, 0, 8000)) ?: []);

        return implode('', $paras);
    }

    /**
     * Raise a service-catalog request from chat. Only items with no required form fields (a request with questions to answer goes to the portal,
     * and the answer says so with a link). Held for approval exactly as the portal does it when the item needs approval.
     *
     * @return array{ok:bool,text:string,ticket_id?:int}
     */
    public function requestCatalogItem(array $actor, int $itemId, string $via = 'Chat'): array
    {
        $item = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT * FROM service_catalog_items WHERE catalog_item_id = $itemId AND is_active = 1 LIMIT 1"));
        if (!$item) {
            return ['ok' => false, 'text' => 'That catalog item does not exist or is not available.'];
        }
        $svc = new ServiceCatalogService($this->db);
        $check = ServiceCatalogService::validateInput($svc->getFields($itemId), []);
        if ($check['errors']) {
            $base = ChatSettings::baseUrl();

            return ['ok' => false, 'text' => '"' . $item['name'] . '" needs a few answers, so please request it in the portal' . ($base !== '' ? ': ' . $base . '/client/ticket_add.php?catalog_item_id=' . $itemId : '.')];
        }
        $priority = in_array((string) $item['default_priority'], self::PRIORITIES, true) ? (string) $item['default_priority'] : 'Low';
        $r = $this->createTicket($actor, (string) $item['name'], 'Requested from ' . $via . ': ' . $item['name'] . "\n" . (string) ($item['description'] ?? ''), $priority, 0, $via);
        if (!$r['ok']) {
            return $r;
        }
        $ticketId = (int) $r['ticket_id'];
        mysqli_query($this->db, "UPDATE tickets SET ticket_catalog_item_id = $itemId WHERE ticket_id = $ticketId");
        $t = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT * FROM tickets WHERE ticket_id = $ticketId"));
        $note = '';
        if ($svc->hasRequestFlow($item)) {
            $res = $svc->submit($item, $ticketId, (int) $t['ticket_client_id'], (int) $t['ticket_contact_id'], $actor['kind'] === 'agent' ? (int) $actor['user_id'] : 0, $check['values']);
            if ($res['status'] === 'pending_approval') {
                $note = ' It is waiting for approval.';
                ChatNotifier::approvalRequested($this->db, 'catalog_request', (int) $res['request_id']);
            }
        }

        return ['ok' => true, 'text' => 'Requested "' . $item['name'] . '" as ' . $this->label($t) . '.' . $note, 'ticket_id' => $ticketId];
    }

    // ----- changing ----------------------------------------------------------------------------------------------------

    /** @return array{ok:bool,text:string,ticket_id?:int} */
    public function changeStatus(array $actor, int $ticketId, string $statusName, string $via = 'Chat'): array
    {
        $t = $this->workable($actor, $ticketId);
        if (is_string($t)) {
            return ['ok' => false, 'text' => $t];
        }
        $statusName = trim($statusName);
        if (in_array(strtolower($statusName), ['closed', 'resolved'], true)) {
            return ['ok' => false, 'text' => 'Closing or resolving a ticket is done in ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' itself (it also handles time, billing and the customer notice).'];
        }
        $stmt = mysqli_prepare($this->db, 'SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE LOWER(ticket_status_name) = LOWER(?) AND ticket_status_active = 1 ORDER BY ticket_status_id ASC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $statusName);
        mysqli_stmt_execute($stmt);
        $s = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$s) {
            return ['ok' => false, 'text' => 'There is no active status "' . mb_substr($statusName, 0, 40) . '".'];
        }
        $new = (int) $s['ticket_status_id'];
        if ($new === (int) $t['ticket_status']) {
            return ['ok' => true, 'text' => $this->label($t) . ' is already ' . $s['ticket_status_name'] . '.', 'ticket_id' => $ticketId];
        }
        $stmt = mysqli_prepare($this->db, 'UPDATE tickets SET ticket_status = ? WHERE ticket_id = ? AND ticket_closed_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'ii', $new, $ticketId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $this->note($ticketId, (int) $actor['user_id'], 'Status changed to ' . $s['ticket_status_name'] . ' from ' . $via . ' by ' . $actor['name'] . '.');
        if (function_exists('slaSyncPause')) {
            try { \slaSyncPause($this->db, $ticketId); } catch (\Throwable $e) { /* no SLA tables */ }
        }
        $this->event('ticket.status_changed', $ticketId);
        ($this->audit)('chat.status_changed', (int) $actor['user_id'], 'ticket', $ticketId, 'status_changed', $this->label($t) . ' set to ' . $s['ticket_status_name'] . ' from ' . $via . ' by ' . $actor['name'], ['ticket_id' => $ticketId, 'via' => strtolower($via), 'status' => $s['ticket_status_name']]);

        return ['ok' => true, 'text' => $this->label($t) . ' is now ' . $s['ticket_status_name'] . '.', 'ticket_id' => $ticketId];
    }

    /** @return array{ok:bool,text:string,ticket_id?:int} */
    public function changePriority(array $actor, int $ticketId, string $priority, string $via = 'Chat'): array
    {
        $t = $this->workable($actor, $ticketId);
        if (is_string($t)) {
            return ['ok' => false, 'text' => $t];
        }
        $match = null;
        foreach (self::PRIORITIES as $p) {
            if (strcasecmp($p, trim($priority)) === 0) {
                $match = $p;
            }
        }
        if ($match === null) {
            return ['ok' => false, 'text' => 'Priority must be one of: ' . implode(', ', self::PRIORITIES) . '.'];
        }
        if ((string) $t['ticket_priority'] === $match) {
            return ['ok' => true, 'text' => $this->label($t) . ' is already ' . $match . ' priority.', 'ticket_id' => $ticketId];
        }
        $stmt = mysqli_prepare($this->db, 'UPDATE tickets SET ticket_priority = ? WHERE ticket_id = ? AND ticket_closed_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'si', $match, $ticketId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $this->note($ticketId, (int) $actor['user_id'], 'Priority changed to ' . $match . ' from ' . $via . ' by ' . $actor['name'] . '.');
        if (function_exists('recalculateTicketSla')) {
            try { \recalculateTicketSla($this->db, $ticketId); } catch (\Throwable $e) { /* no SLA tables */ }
        }
        $this->event('ticket.priority_changed', $ticketId);
        ($this->audit)('chat.priority_changed', (int) $actor['user_id'], 'ticket', $ticketId, 'priority_changed', $this->label($t) . ' set to ' . $match . ' from ' . $via . ' by ' . $actor['name'], ['ticket_id' => $ticketId, 'via' => strtolower($via), 'priority' => $match]);

        return ['ok' => true, 'text' => $this->label($t) . ' is now ' . $match . ' priority.', 'ticket_id' => $ticketId];
    }

    /**
     * Add a reply. An agent's reply becomes what the install configured for chat replies (an internal note by default) unless $type is given;
     * a contact's reply is a client reply on their own ticket. A Public agent reply is stored and shown in the portal but NOT emailed (the web
     * app's mail path is not reused from chat).
     *
     * @return array{ok:bool,text:string,ticket_id?:int,reply_id?:int}
     */
    public function addReply(array $actor, int $ticketId, string $text, ?string $type = null, string $via = 'Chat'): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'text' => 'The reply is empty.'];
        }
        if ($actor['kind'] === 'agent') {
            $t = $this->workable($actor, $ticketId);
            if (is_string($t)) {
                return ['ok' => false, 'text' => $t];
            }
            $replyType = $type !== null ? ($type === 'Public' ? 'Public' : 'Internal') : $this->settings->threadReplyType();
            $by = (int) $actor['user_id'];
        } else {
            $t = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT * FROM tickets WHERE ticket_id = $ticketId AND ticket_archived_at IS NULL"));
            if (!$t || !$this->canSee($actor, $t)) {
                return ['ok' => false, 'text' => 'I could not find that ticket.'];
            }
            if ($t['ticket_closed_at'] !== null) {
                return ['ok' => false, 'text' => 'That ticket is closed.'];
            }
            $replyType = 'Client';
            $by = (int) $actor['contact_id'];
        }
        $html = $this->htmlBody($text);
        $stmt = mysqli_prepare($this->db, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = ?, ticket_reply_time_worked = '00:00:00', ticket_reply_by = ?, ticket_reply_ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'ssii', $html, $replyType, $by, $ticketId);
        mysqli_stmt_execute($stmt);
        $replyId = (int) mysqli_stmt_insert_id($stmt);
        mysqli_stmt_close($stmt);
        if ($replyType === 'Public' && empty($t['ticket_first_response_at'])) {
            mysqli_query($this->db, "UPDATE tickets SET ticket_first_response_at = NOW() WHERE ticket_id = $ticketId");
        }
        // This reply came FROM chat: the thread must not echo it back.
        ChatNotifier::markOrigin($this->db, $replyId);
        $this->event('ticket.replied', $ticketId, ['via' => 'chat']);
        ($this->audit)('chat.reply_added', $by ?: null, 'ticket', $ticketId, 'replied', 'Reply (' . $replyType . ') added to ' . $this->label($t) . ' from ' . $via . ' by ' . $actor['name'], ['ticket_id' => $ticketId, 'reply_id' => $replyId, 'via' => strtolower($via), 'reply_type' => $replyType]);

        return ['ok' => true, 'text' => 'Added your reply to ' . $this->label($t) . ($replyType === 'Internal' ? ' as an internal note.' : '.'), 'ticket_id' => $ticketId, 'reply_id' => $replyId];
    }

    /** Ticket row if the agent may change it and it is open; else the refusal text. @return array<string,mixed>|string */
    private function workable(array $actor, int $ticketId)
    {
        if ($actor['kind'] !== 'agent') {
            return 'Only agents can do that.';
        }
        $t = mysqli_fetch_assoc(mysqli_query($this->db, "SELECT * FROM tickets WHERE ticket_id = $ticketId AND ticket_archived_at IS NULL"));
        if (!$t) {
            return 'That ticket no longer exists.';
        }
        if (!$this->mayWork($actor, (int) $t['ticket_client_id'])) {
            return 'Your ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' role does not allow you to work on that ticket.';
        }
        if ($t['ticket_closed_at'] !== null) {
            return 'That ticket is closed.';
        }

        return $t;
    }

    private function note(int $ticketId, int $userId, string $text): void
    {
        $stmt = mysqli_prepare($this->db, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = ?, ticket_reply_ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'sii', $text, $userId, $ticketId);
        mysqli_stmt_execute($stmt);
        $id = (int) mysqli_stmt_insert_id($stmt);
        mysqli_stmt_close($stmt);
        ChatNotifier::markOrigin($this->db, $id); // our own audit note is not conversation
    }

    private function event(string $event, int $ticketId, array $extra = []): void
    {
        if (function_exists('rivetEmitEvent') && function_exists('getWebhookTicketPayload')) {
            try {
                \rivetEmitEvent($event, \getWebhookTicketPayload($ticketId) + $extra);
            } catch (\Throwable $e) {
                error_log('chat event skipped: ' . get_class($e));
            }
        }
    }

    // ----- approvals ---------------------------------------------------------------------------------------------------

    /**
     * Approvals waiting for this actor. Catalog requests addressed to the agent (or contact), and workflow approval tasks the agent can decide.
     *
     * @return list<array{kind:string,id:int,title:string,detail:string}>
     */
    public function pendingApprovals(array $actor, int $limit = 10): array
    {
        $out = [];
        $svc = new ServiceCatalogService($this->db);
        if ($actor['kind'] === 'agent') {
            $rows = $this->agentAllowed((int) $actor['user_id'], 0, 1) ? $svc->pendingApprovals((int) $actor['user_id']) : [];
        } else {
            $rows = $svc->pendingApprovals(null, (int) $actor['contact_id'], (int) $actor['client_id']);
        }
        foreach ($rows as $r) {
            $out[] = ['kind' => 'catalog_request', 'id' => (int) $r['request_id'], 'title' => $r['item_name'] . ' for ' . ($r['requester_name'] ?: 'a requester'), 'detail' => $r['ticket_prefix'] . $r['ticket_number'] . ' ' . $r['ticket_subject']];
        }
        if ($actor['kind'] === 'agent' && $this->agentAllowed((int) $actor['user_id'], 0, 1, 'module_client')) {
            $wf = new WorkflowService($this->db, $this->workflowGateway);
            $res = mysqli_query($this->db, "SELECT t.run_task_id, t.title, c.contact_name FROM workflow_run_tasks t JOIN workflow_runs r ON r.run_id = t.run_id LEFT JOIN contacts c ON c.contact_id = r.contact_id WHERE t.task_type = 'approval' AND t.status = 'pending' AND t.approval_status = 'pending' AND r.status NOT IN ('cancelled','paused') ORDER BY t.run_task_id ASC LIMIT 100");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                if ($wf->canDecide((int) $r['run_task_id'], (int) $actor['user_id'])) {
                    $out[] = ['kind' => 'workflow_task', 'id' => (int) $r['run_task_id'], 'title' => (string) $r['title'], 'detail' => 'for ' . ($r['contact_name'] ?: 'an employee')];
                }
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** @return array{ok:bool,text:string} */
    public function decideCatalog(array $actor, int $requestId, bool $approve, string $comment = '', string $via = 'Chat'): array
    {
        if ($actor['kind'] === 'agent') {
            if (!$this->agentAllowed((int) $actor['user_id'], 0, 1)) {
                return ['ok' => false, 'text' => 'Your role does not allow you to decide approvals.'];
            }
            $r = (new ServiceCatalogService($this->db))->decide($requestId, (int) $actor['user_id'], null, $approve, $comment, false);
        } else {
            $r = (new ServiceCatalogService($this->db))->decide($requestId, null, (int) $actor['contact_id'], $approve, $comment, false, (int) $actor['client_id']);
        }
        if (!$r['ok']) {
            return ['ok' => false, 'text' => (string) $r['error']];
        }
        $who = $actor['kind'] === 'agent' ? (int) $actor['user_id'] : null;
        ($this->audit)('chat.approval_decided', $who, 'catalog_request', $requestId, $approve ? 'approved' : 'rejected', 'Catalog request ' . $requestId . ($approve ? ' approved' : ' rejected') . ' from ' . $via . ' by ' . $actor['name'], ['request_id' => $requestId, 'via' => strtolower($via), 'status' => $r['status']]);

        return ['ok' => true, 'text' => $approve ? ($r['status'] === 'approved' ? 'Approved. The request is released.' : 'Approved. It now waits for the next approver.') : 'Rejected. The requester is told and the ticket is closed.'];
    }

    /** @return array{ok:bool,text:string} */
    public function decideWorkflow(array $actor, int $taskId, bool $approve, string $comment = '', string $via = 'Chat'): array
    {
        if ($actor['kind'] !== 'agent' || !$this->agentAllowed((int) $actor['user_id'], 0, 1, 'module_client')) {
            return ['ok' => false, 'text' => 'Your role does not allow you to decide workflow approvals.'];
        }
        $wf = new WorkflowService($this->db, $this->workflowGateway);
        try {
            $approve ? $wf->approveTask($taskId, (int) $actor['user_id'], $comment) : $wf->rejectTask($taskId, (int) $actor['user_id'], $comment);
        } catch (\DomainException $e) {
            return ['ok' => false, 'text' => $e->getMessage()];
        }
        ($this->audit)('chat.approval_decided', (int) $actor['user_id'], 'workflow_task', $taskId, $approve ? 'approved' : 'rejected', 'Workflow approval ' . $taskId . ($approve ? ' approved' : ' rejected') . ' from ' . $via . ' by ' . $actor['name'], ['task_id' => $taskId, 'via' => strtolower($via)]);

        return ['ok' => true, 'text' => $approve ? 'Approved.' : 'Rejected. The workflow is paused and its owner is told.'];
    }
}
