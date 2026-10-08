<?php

namespace ITFlow\Webhooks;

/**
 * Slack interactive buttons ("Acknowledge", "Assign to me") on ticket messages. The public endpoint is /slack_interactive.php;
 * this class holds everything that decides anything, so it is testable without a web server.
 *
 * Trust chain, in order, and each link fails closed:
 *   1. The request is signed by Slack: HMAC-SHA256 over "v0:<timestamp>:<raw body>" with the signing secret of a Slack destination
 *      (webhooks.webhook_secret, encrypted), compared in constant time, timestamp within 5 minutes.
 *   2. It is not a replay: each accepted signature is recorded (slack_interactive_seen) and a second use is refused.
 *   3. The Slack user must map to exactly one active agent. That is OFF unless the install opted in (config_slack_link_by_email), and
 *      then only by an email address that Slack itself says is confirmed (users.info with the bot token, is_email_confirmed), for a
 *      non-bot, non-deleted, non-guest member of the pinned workspace (config_slack_team_id, when set).
 *   4. The agent must be allowed to work tickets of that department (role: module_support write or administrator; department access).
 * Nothing in a payload is ever trusted for identity (the Slack-supplied name and email-looking fields are ignored); only the signed
 * user id is used, and the email comes from Slack's API. Payloads and tokens are never logged.
 */
final class SlackInteractive
{
    public const MAX_SKEW_SECONDS = 300;
    public const MAX_BODY_BYTES = 100000;
    private const SEEN_RETENTION_MINUTES = 10;

    /** @var callable(string $token, string $slackUserId): array */
    private $userLookup;
    /** @var callable(string $event, ?int $actor, string $entityType, $entityId, string $action, string $summary, array $meta): void */
    private $audit;

    /**
     * @param callable|null $userLookup (botToken, slackUserId) => users.info "user" array, or ['error' => code]; default asks Slack
     * @param callable|null $audit      defaults to rivetAudit()
     */
    public function __construct(private \mysqli $mysqli, ?callable $userLookup = null, ?callable $audit = null)
    {
        $this->userLookup = $userLookup ?? [self::class, 'slackUsersInfo'];
        $this->audit = $audit ?? static function (string $e, ?int $a, string $et, $id, string $act, string $sum, array $meta = []): void {
            if (function_exists('rivetAudit')) {
                \rivetAudit($e, $a, $et, $id, $act, $sum, $meta);
            }
        };
    }

    // ----- signature -----------------------------------------------------------------------------------------------

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'v0=' . hash_hmac('sha256', 'v0:' . $timestamp . ':' . $body, $secret);
    }

    /** Signature valid AND timestamp within MAX_SKEW_SECONDS of $now (either direction). Constant-time compare. */
    public static function verify(string $secret, string $timestamp, string $body, string $signatureHeader, int $now): bool
    {
        if ($secret === '' || $timestamp === '' || !ctype_digit($timestamp) || strlen($timestamp) > 12) {
            return false;
        }
        if (abs($now - (int) $timestamp) > self::MAX_SKEW_SECONDS) {
            return false;
        }
        if (!preg_match('/^v0=[0-9a-f]{64}$/', $signatureHeader)) {
            return false;
        }

        return hash_equals(self::signature($secret, $timestamp, $body), $signatureHeader);
    }

    /**
     * The enabled Slack destination whose signing secret signed this request, or null. A Slack app has one signing secret that may
     * be entered on several destinations; the first that verifies is used.
     *
     * @return array<string,mixed>|null a webhooks row
     */
    public function destinationFor(string $timestamp, string $body, string $signatureHeader, int $now): ?array
    {
        $res = mysqli_query($this->mysqli, "SELECT * FROM webhooks WHERE webhook_type = 'slack' AND webhook_enabled = 1 AND webhook_secret <> ''");
        while ($res && ($row = mysqli_fetch_assoc($res))) {
            $secret = function_exists('decryptSetting') ? decryptSetting((string) $row['webhook_secret']) : (string) $row['webhook_secret'];
            if (self::verify($secret, $timestamp, $body, $signatureHeader, $now)) {
                return $row;
            }
        }

        return null;
    }

    /** Record an accepted signature; false when it was already used (a replay). Old records are purged. */
    public function claimOnce(string $signatureHeader): bool
    {
        mysqli_query($this->mysqli, 'DELETE FROM slack_interactive_seen WHERE seen_at < NOW() - INTERVAL ' . self::SEEN_RETENTION_MINUTES . ' MINUTE');
        $hash = hash('sha256', $signatureHeader);
        $stmt = mysqli_prepare($this->mysqli, 'INSERT IGNORE INTO slack_interactive_seen (sig_hash) VALUES (?)');
        mysqli_stmt_bind_param($stmt, 's', $hash);
        mysqli_stmt_execute($stmt);
        $new = mysqli_stmt_affected_rows($stmt) === 1;
        mysqli_stmt_close($stmt);

        return $new;
    }

    // ----- handling ------------------------------------------------------------------------------------------------

    /**
     * Handle one verified, unreplayed payload.
     *
     * @param array<string,mixed> $webhook the destination row that verified the request
     * @param array<string,mixed> $payload Slack's decoded interaction payload
     * @return array{text:?string,acted:bool} text is the ephemeral answer for the clicker (null = say nothing)
     */
    public function handle(array $webhook, array $payload): array
    {
        if (($payload['type'] ?? '') !== 'block_actions' || !is_array($payload['actions'] ?? null) || !$payload['actions']) {
            return ['text' => null, 'acted' => false];
        }
        $action = $payload['actions'][0];
        $actionId = is_array($action) ? (string) ($action['action_id'] ?? '') : '';
        if (!in_array($actionId, [ChatFormatter::ACTION_ACK, ChatFormatter::ACTION_ASSIGN], true)) {
            return ['text' => null, 'acted' => false]; // a link button, or something this version does not know
        }
        if (!preg_match('/^ticket:([1-9][0-9]{0,9})$/', (string) ($action['value'] ?? ''), $m)) {
            return ['text' => 'That button is not valid.', 'acted' => false];
        }
        $ticketId = (int) $m[1];

        $cfg = mysqli_fetch_assoc(mysqli_query($this->mysqli, 'SELECT config_slack_link_by_email, config_slack_bot_token, config_slack_team_id FROM settings WHERE company_id = 1')) ?: [];
        $team = (string) ($cfg['config_slack_team_id'] ?? '');
        $payloadTeam = (string) (($payload['team']['id'] ?? '') ?: ($payload['user']['team_id'] ?? ''));
        if ($team !== '' && !hash_equals($team, $payloadTeam)) {
            return ['text' => 'This Slack workspace is not allowed to use these buttons.', 'acted' => false];
        }

        $ticket = $this->loadTicket($ticketId);
        if ($ticket === null) {
            return ['text' => 'That ticket no longer exists.', 'acted' => false];
        }
        // The destination's own client filter still applies: it never acts on a department it was not set up for.
        $allowedClients = array_filter(array_map('intval', array_filter(array_map('trim', explode(',', (string) ($webhook['webhook_client_ids'] ?? ''))), 'ctype_digit')));
        if ($allowedClients && !in_array((int) $ticket['ticket_client_id'], $allowedClients, true)) {
            return ['text' => 'This channel is not set up for that ticket.', 'acted' => false];
        }

        $notLinked = "Your Slack account isn't linked to a RivetIT agent, so nothing was changed. Use the Open ticket button and sign in.";
        if ((int) ($cfg['config_slack_link_by_email'] ?? 0) !== 1) {
            return ['text' => $notLinked, 'acted' => false];
        }
        $agentId = $this->agentForSlackUser((string) ($payload['user']['id'] ?? ''), (string) ($cfg['config_slack_bot_token'] ?? ''), $payloadTeam);
        if ($agentId === null) {
            return ['text' => $notLinked, 'acted' => false];
        }
        if (!$this->agentMayWork($agentId, (int) $ticket['ticket_client_id'])) {
            return ['text' => 'Your RivetIT role does not allow you to work on that ticket.', 'acted' => false];
        }
        if ($ticket['ticket_closed_at'] !== null) {
            return ['text' => 'That ticket is closed.', 'acted' => false];
        }

        $agentName = (string) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT user_name FROM users WHERE user_id = $agentId"))[0];
        $label = $ticket['ticket_prefix'] . $ticket['ticket_number'];

        if ($actionId === ChatFormatter::ACTION_ACK) {
            $this->note($ticketId, $agentId, "Acknowledged in Slack by $agentName.");
            ($this->audit)('ticket.slack_acknowledged', $agentId, 'ticket', $ticketId, 'acknowledged', "Ticket $label acknowledged from Slack by $agentName", ['ticket_id' => $ticketId, 'via' => 'slack']);

            return ['text' => "Acknowledged $label.", 'acted' => true];
        }

        // Assign to me.
        if ((int) $ticket['ticket_assigned_to'] === $agentId) {
            return ['text' => "$label is already assigned to you.", 'acted' => false];
        }
        $status = (int) $ticket['ticket_status'];
        if ($status === $this->statusId('New') && $this->statusId('Open') > 0) {
            $status = $this->statusId('Open');
        }
        $stmt = mysqli_prepare($this->mysqli, 'UPDATE tickets SET ticket_assigned_to = ?, ticket_status = ? WHERE ticket_id = ? AND ticket_closed_at IS NULL');
        mysqli_stmt_bind_param($stmt, 'iii', $agentId, $status, $ticketId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $this->note($ticketId, $agentId, "Ticket assigned to $agentName from Slack.");
        ($this->audit)('ticket.slack_assigned', $agentId, 'ticket', $ticketId, 'assigned', "Ticket $label assigned to $agentName from Slack", ['ticket_id' => $ticketId, 'via' => 'slack']);
        if (function_exists('rivetEmitEvent') && function_exists('getWebhookTicketPayload')) {
            \rivetEmitEvent('ticket.assigned', \getWebhookTicketPayload($ticketId));
        }

        return ['text' => "Assigned $label to you.", 'acted' => true];
    }

    /**
     * Slack user id -> agent id, or null. Only through a confirmed email address Slack reports for that exact user, and only when
     * exactly one active agent has it. A name, a display name or anything in the payload itself is never used.
     */
    public function agentForSlackUser(string $slackUserId, string $encryptedBotToken, string $payloadTeamId): ?int
    {
        if (!preg_match('/^[UW][A-Z0-9]{4,20}$/', $slackUserId)) {
            return null;
        }
        $token = function_exists('decryptSetting') ? decryptSetting($encryptedBotToken) : $encryptedBotToken;
        if ($token === '') {
            return null;
        }
        $user = ($this->userLookup)($token, $slackUserId);
        if (!is_array($user) || isset($user['error']) || (string) ($user['id'] ?? '') !== $slackUserId) {
            return null;
        }
        if (!empty($user['deleted']) || !empty($user['is_bot']) || !empty($user['is_restricted']) || !empty($user['is_ultra_restricted']) || !empty($user['is_stranger'])) {
            return null;
        }
        if ($payloadTeamId !== '' && isset($user['team_id']) && (string) $user['team_id'] !== $payloadTeamId) {
            return null; // a member of some other workspace (shared channel)
        }
        $email = strtolower(trim((string) ($user['profile']['email'] ?? '')));
        if (($user['is_email_confirmed'] ?? false) !== true || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $stmt = mysqli_prepare($this->mysqli, 'SELECT user_id FROM users WHERE LOWER(user_email) = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL');
        mysqli_stmt_bind_param($stmt, 's', $email);
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);

        return count($rows) === 1 ? (int) $rows[0]['user_id'] : null;
    }

    /** Same rule as the web UI: administrator, or a role with write access to the support module; department restrictions honoured. */
    public function agentMayWork(int $userId, int $clientId): bool
    {
        $u = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT u.user_role_id, COALESCE(r.role_is_admin, 0) AS is_admin FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = $userId AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL"));
        if (!$u) {
            return false;
        }
        if ((int) $u['is_admin'] === 1) {
            return true;
        }
        $role = (int) $u['user_role_id'];
        $lvl = mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT p.user_role_permission_level FROM modules m JOIN user_role_permissions p ON p.module_id = m.module_id WHERE m.module_name = 'module_support' AND p.user_role_id = $role"));
        if (!$lvl || (int) $lvl[0] < 2) {
            return false;
        }
        if ($clientId <= 0) {
            return true;
        }
        $any = mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM user_client_permissions WHERE user_id = $userId LIMIT 1"));
        if (!$any) {
            return true;
        }

        return (bool) mysqli_fetch_row(mysqli_query($this->mysqli, "SELECT 1 FROM user_client_permissions WHERE user_id = $userId AND client_id = $clientId LIMIT 1"));
    }

    private function loadTicket(int $id): ?array
    {
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_client_id, ticket_assigned_to, ticket_status, ticket_closed_at FROM tickets WHERE ticket_id = $id"));

        return $row ?: null;
    }

    private function statusId(string $name): int
    {
        $stmt = mysqli_prepare($this->mysqli, 'SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = ? AND ticket_status_active = 1 ORDER BY ticket_status_id ASC LIMIT 1');
        mysqli_stmt_bind_param($stmt, 's', $name);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        return (int) ($row[0] ?? 0);
    }

    private function note(int $ticketId, int $agentId, string $text): void
    {
        $stmt = mysqli_prepare($this->mysqli, "INSERT INTO ticket_replies SET ticket_reply = ?, ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = ?, ticket_reply_ticket_id = ?");
        mysqli_stmt_bind_param($stmt, 'sii', $text, $agentId, $ticketId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    // ----- Slack API (users.info) and the ephemeral answer ------------------------------------------------------------

    /** The Slack Web API base; tests point it at a local mock via RIVETIT_SLACK_API_BASE (config.php only, never the UI). */
    public static function apiBase(): string
    {
        return rtrim(defined('RIVETIT_SLACK_API_BASE') ? (string) RIVETIT_SLACK_API_BASE : 'https://slack.com/api', '/');
    }

    /** users.info for one user. @return array the Slack "user" object, or ['error' => code] */
    public static function slackUsersInfo(string $botToken, string $slackUserId): array
    {
        $url = self::apiBase() . '/users.info';
        $vet = ChatDelivery::vetUrl($url);
        if (!$vet['ok']) {
            return ['error' => 'url_rejected'];
        }
        $ch = curl_init($url);
        $resolve = [];
        foreach ($vet['ips'] as $ip) {
            $resolve[] = $vet['host'] . ':' . $vet['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['user' => $slackUserId]),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $botToken, 'Content-Type: application/x-www-form-urlencoded', 'User-Agent: RivetIT-Slack-Interactive'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($body) ? json_decode(substr($body, 0, 200000), true) : null;
        if ($status !== 200 || !is_array($data) || empty($data['ok']) || !is_array($data['user'] ?? null)) {
            return ['error' => is_array($data) ? (string) ($data['error'] ?? 'bad_response') : 'unreachable'];
        }

        return $data['user'];
    }

    /**
     * Post the ephemeral answer to the interaction's response_url. Only a Slack host is accepted (and the usual public-address vetting
     * applies); a failure is ignored, the click itself has already been handled.
     */
    public static function respond(string $responseUrl, string $text): bool
    {
        $host = strtolower((string) parse_url($responseUrl, PHP_URL_HOST));
        $isSlack = $host === 'slack.com' || str_ends_with($host, '.slack.com');
        $testLocal = defined('RIVETIT_CHAT_ALLOW_LOCAL_HTTP') && RIVETIT_CHAT_ALLOW_LOCAL_HTTP === true && in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (!$isSlack && !$testLocal) {
            return false;
        }
        $json = json_encode(['response_type' => 'ephemeral', 'replace_original' => false, 'text' => ChatFormatter::slackEscape($text)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r = ChatDelivery::post($responseUrl, (string) $json);

        return $r['error'] === null && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 300;
    }
}
