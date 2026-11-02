<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\SlackInteractive;

/**
 * The Microsoft Teams bot: what RivetIT does with an activity the Bot Framework Service posts to /teams_bot.php. UNVERIFIED LIVE: everything here is
 * proven with locally signed activities and mocked token / connector endpoints (tests/chat_teams.php); it has not run against a real Azure Bot
 * registration or a Teams tenant. See docs/CHAT_LIVE_VERIFICATION.md for exactly what to check.
 *
 * Order of trust for every request: the JWT (TeamsJwt) -> the bot is switched on -> the tenant matches the pinned tenant (when set) -> the activity
 * is handled once (id) -> the Teams user maps to an agent / contact (ChatIdentity: explicit link first, opt-in directory-email fallback) -> the rights
 * ChatActions enforces. The activity body is never trusted for identity: the signed-in Entra object id comes from `from.aadObjectId` of an activity
 * whose JWT already proved it came from the Bot Framework Service for THIS bot and THIS serviceUrl.
 */
final class TeamsBot
{
    private ChatSettings $settings;
    private ChatActions $actions;
    private ChatIdentity $identity;
    /** @var callable(string,string,array): array */
    private $sender;

    /**
     * @param callable|null $sender       (serviceUrl, conversationId, activity) => ['ok'=>bool,...]; default TeamsConnector::send
     * @param callable|null $emailLookup  (aadObjectId, conversationId, serviceUrl) => ?string; default TeamsConnector::memberEmail
     */
    public function __construct(private \mysqli $db, ?callable $audit = null, ?callable $sender = null, ?callable $emailLookup = null)
    {
        $this->settings = new ChatSettings($db);
        $this->actions = new ChatActions($db, $audit, $this->settings);
        $connector = new TeamsConnector($db, $this->settings);
        $this->sender = $sender ?? static fn (string $url, string $conv, array $act): array => $connector->send($url, $conv, $act);
        $this->identity = new ChatIdentity($db, $this->settings, null, $emailLookup ?? static fn (string $aad, string $conv, string $url): ?string => $connector->memberEmail($url, $conv, $aad));
    }

    /**
     * Validate the token of an incoming request. @return array{ok:bool,error:?string}
     *
     * @param callable(bool): (array|null)|null $keys JWKS provider (tests); default TeamsKeys
     */
    public function authenticate(string $authorization, array $activity, ?callable $keys = null, ?int $now = null): array
    {
        if (!$this->settings->teamsBot() || $this->settings->text('config_teams_app_id') === '') {
            return ['ok' => false, 'error' => 'disabled'];
        }
        $jwt = new TeamsJwt($keys ?? TeamsKeys::provider($this->db));

        return $jwt->validate($authorization, $this->settings->text('config_teams_app_id'), (string) ($activity['serviceUrl'] ?? ''), (string) ($activity['channelId'] ?? ''), $now);
    }

    /**
     * Handle one authenticated activity. Replies go out through the connector; nothing is returned to the Bot Framework but an empty 200.
     *
     * @param callable|null $claim (key) => bool, true the first time (replay protection)
     */
    public function handle(array $activity, ?callable $claim = null): void
    {
        $type = (string) ($activity['type'] ?? '');
        $serviceUrl = (string) ($activity['serviceUrl'] ?? '');
        $conv = (string) ($activity['conversation']['id'] ?? '');
        $actId = (string) ($activity['id'] ?? '');
        if (!in_array($type, ['message'], true) || $serviceUrl === '' || $conv === '') {
            return;
        }
        $tenant = strtolower((string) ($activity['channelData']['tenant']['id'] ?? $activity['conversation']['tenantId'] ?? ''));
        $pin = strtolower($this->settings->text('config_teams_tenant_id'));
        if ($pin !== '' && !hash_equals($pin, $tenant)) {
            return; // another tenant's users never reach the bot's logic
        }
        if ($claim !== null && ($actId === '' || !$claim('teams-activity:' . $actId))) {
            return;
        }
        $reply = fn (array $activity2) => ($this->sender)($serviceUrl, $conv, $activity2);
        $aad = strtolower((string) ($activity['from']['aadObjectId'] ?? ''));
        $actor = $aad !== '' ? $this->identity->resolve(ChatIdentity::TEAMS, $tenant, $aad, ['tenant_id' => $tenant, 'conversation_id' => $conv, 'service_url' => $serviceUrl]) : null;
        if ($actor === null) {
            $reply(TeamsCards::textActivity("Your Teams account isn't linked to a " . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' user, so nothing was done. Ask an administrator to link you (Administration > Chat).'));

            return;
        }
        if ((string) ($activity['conversation']['conversationType'] ?? '') === 'personal') {
            $this->rememberDm($aad, $conv, $serviceUrl);
        }

        // A card button: Action.Submit puts its data and the inputs in `value`.
        if (is_array($activity['value'] ?? null) && isset($activity['value']['rivet'])) {
            $reply(TeamsCards::textActivity($this->cardAction($actor, $activity['value'])));

            return;
        }
        $text = trim(preg_replace('/<at>.*?<\/at>/is', '', (string) ($activity['text'] ?? '')) ?? '');
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
        $run = (new ChatCommands($this->db, $this->actions, $this->settings))->run($actor, $text, 'Teams');
        $a = $run['results']
            ? TeamsCards::activity($run['text'], TeamsCards::results($run['heading'], $run['results']))
            : TeamsCards::textActivity($run['text']);
        $reply($a);
        // A ticket made here keeps talking in this conversation (replies on it are mirrored back), when two-way is on.
        if ($run['ticket_id'] !== null && $this->settings->twoWay() && $actor['kind'] === 'agent') {
            $this->rememberTicketThread((int) $run['ticket_id'], $conv, $serviceUrl, $tenant);
        }
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $v @return string the answer text */
    private function cardAction(array $actor, array $v): string
    {
        if (!$this->settings->actions()) {
            return 'Changing tickets from chat is turned off. An administrator can turn it on in Administration > Chat.';
        }
        $what = (string) $v['rivet'];
        if ($what === 'approve' || $what === 'reject') {
            $kind = (string) ($v['kind'] ?? '');
            $id = (int) ($v['id'] ?? 0);
            if (!in_array($kind, ['catalog_request', 'workflow_task'], true) || $id <= 0) {
                return 'That button is not valid.';
            }
            $ap = $what === 'approve';
            $comment = ($ap ? 'Approved' : 'Denied') . ' in Teams by ' . $actor['name'];
            $r = $kind === 'catalog_request' ? $this->actions->decideCatalog($actor, $id, $ap, $comment, 'Teams') : $this->actions->decideWorkflow($actor, $id, $ap, $comment, 'Teams');

            return $r['ok'] ? ($ap ? 'Approved: ' : 'Denied: ') . $r['text'] : $r['text'];
        }
        $ticket = (int) ($v['ticket'] ?? 0);
        if ($ticket <= 0) {
            return 'That button is not valid.';
        }
        $found = $this->actions->findTicket($actor, 'id:' . $ticket);
        if ($found === null) {
            return 'I could not find that ticket.';
        }
        return match ($what) {
            'status' => $this->actions->changeStatus($actor, $ticket, (string) ($v['choice_status'] ?? ''), 'Teams')['text'],
            'priority' => $this->actions->changePriority($actor, $ticket, (string) ($v['choice_priority'] ?? ''), 'Teams')['text'],
            'reply' => $this->actions->addReply($actor, $ticket, (string) ($v['text'] ?? ''), null, 'Teams')['text'],
            default => 'That button is not valid.',
        };
    }

    private function rememberDm(string $aad, string $conv, string $serviceUrl): void
    {
        if (!TeamsConnector::serviceUrlAllowed($serviceUrl)) {
            return;
        }
        $ref = 'dm:' . $aad;
        $stmt = mysqli_prepare($this->db, "INSERT INTO chat_threads (thread_platform, thread_channel_id, thread_ref, thread_service_url, thread_ticket_id) VALUES ('teams', ?, ?, ?, 0) ON DUPLICATE KEY UPDATE thread_service_url = VALUES(thread_service_url)");
        mysqli_stmt_bind_param($stmt, 'sss', $conv, $ref, $serviceUrl);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    private function rememberTicketThread(int $ticketId, string $conv, string $serviceUrl, string $tenant): void
    {
        if (!TeamsConnector::serviceUrlAllowed($serviceUrl)) {
            return;
        }
        $ref = 'ticket:' . $ticketId;
        $last = (int) (mysqli_fetch_row(mysqli_query($this->db, "SELECT COALESCE(MAX(ticket_reply_id), 0) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticketId"))[0] ?? 0);
        $stmt = mysqli_prepare($this->db, "INSERT IGNORE INTO chat_threads (thread_platform, thread_team_id, thread_channel_id, thread_ref, thread_service_url, thread_ticket_id, thread_last_reply_id) VALUES ('teams', ?, ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssssii', $tenant, $conv, $ref, $serviceUrl, $ticketId, $last);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}
