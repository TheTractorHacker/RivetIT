<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\ChatFormatter;
use ITFlow\Webhooks\SlackInteractive;

/**
 * What RivetIT does with the requests Slack sends (already signature-verified and replay-checked by the endpoints):
 *   events()       Events API: URL verification, thread replies -> ticket replies (two-way), direct messages / mentions -> virtual agent
 *   command()      the /ticket slash command
 *   interaction()  block actions (existing Acknowledge / Assign buttons, plus status / priority / reply / approve / deny), modal submissions,
 *                  the message shortcut and the global shortcut that open the ticket-creation modal
 *
 * Every entry point is gated by its own switch (Administration > Chat, all OFF by default) and by the workspace pin (config_slack_team_id) when set.
 * Identity is never read from a payload: only the signed Slack user id is used, through ChatIdentity (explicit link first, then the opt-in
 * confirmed-email fallback). Every change goes through ChatActions, which re-checks the same rights the web app enforces.
 */
final class SlackApp
{
    private ChatSettings $settings;
    private ChatActions $actions;
    private ChatIdentity $identity;
    /** @var callable */
    private $poster;
    /** @var callable */
    private $opener;

    /**
     * @param callable|null $slackLookup users.info lookup (tests); default asks Slack
     * @param callable|null $poster      (channel, text, blocks, threadTs) => void, how a bot message is posted (tests record it); default SlackApi
     * @param callable|null $opener      (triggerId, view) => bool, how a modal is opened (tests record it); default SlackApi views.open
     */
    public function __construct(private \mysqli $db, ?callable $audit = null, ?callable $slackLookup = null, ?callable $poster = null, ?callable $opener = null, ?\ITFlow\Workflow\ActionGateway $workflowGateway = null)
    {
        $this->settings = new ChatSettings($db);
        $this->actions = new ChatActions($db, $audit, $this->settings, $workflowGateway);
        $this->identity = new ChatIdentity($db, $this->settings, $slackLookup);
        $token = $this->settings->secret('config_slack_bot_token');
        $this->poster = $poster ?? static function (string $channel, string $text, array $blocks, ?string $threadTs) use ($token): void {
            SlackApi::postMessage($token, $channel, $text, $blocks, $threadTs);
        };
        $this->opener = $opener ?? static function (string $trigger, array $view) use ($token): bool {
            return SlackApi::openView($token, $trigger, $view)['ok'];
        };
    }

    private function app(): string
    {
        return defined('APP_NAME') ? (string) APP_NAME : 'RivetIT';
    }

    private function teamAllowed(string $teamId): bool
    {
        $pin = $this->settings->text('config_slack_team_id');

        return $pin === '' || ($teamId !== '' && hash_equals($pin, $teamId));
    }

    private function notLinked(): string
    {
        return "Your Slack account isn't linked to a " . $this->app() . ' user, so nothing was done. Ask an administrator to link you (Administration > Chat).';
    }

    private function say(string $channel, string $text, array $blocks = [], ?string $thread = null): void
    {
        ($this->poster)($channel, ChatFormatter::slackEscape($text), $blocks, $thread);
    }

    // ----- Events API --------------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body the decoded JSON event envelope
     * @return array<string,mixed>|null the JSON answer, or null for "200 with an empty body"
     */
    public function events(array $body, ?callable $claim = null): ?array
    {
        $type = (string) ($body['type'] ?? '');
        if ($type === 'url_verification') {
            // Slack's one-time check when the Request URL is saved. The request itself was signature-verified; the challenge is echoed as text-safe.
            $c = (string) ($body['challenge'] ?? '');

            return ['challenge' => preg_match('/^[A-Za-z0-9._-]{1,200}$/', $c) ? $c : ''];
        }
        if ($type !== 'event_callback' || !is_array($body['event'] ?? null)) {
            return null;
        }
        if (!$this->teamAllowed((string) ($body['team_id'] ?? ''))) {
            return null;
        }
        $eventId = (string) ($body['event_id'] ?? '');
        if ($claim !== null && ($eventId === '' || !$claim('slack-event:' . $eventId))) {
            return null; // Slack retries unanswered events; each event is handled once
        }
        $ev = $body['event'];
        if (!empty($ev['bot_id']) || !empty($ev['subtype'])) {
            return null; // our own posts, edits, joins ... never a person typing
        }
        $teamId = (string) ($body['team_id'] ?? '');
        $user = (string) ($ev['user'] ?? '');
        $channel = (string) ($ev['channel'] ?? '');
        $ts = (string) ($ev['ts'] ?? '');
        $threadTs = (string) ($ev['thread_ts'] ?? '');
        $text = self::cleanText((string) ($ev['text'] ?? ''));
        if ($user === '' || $channel === '' || $text === '') {
            return null;
        }
        $evType = (string) ($ev['type'] ?? '');

        // 1. A reply inside a ticket thread -> a reply on the ticket (two-way).
        if ($evType === 'message' && $threadTs !== '' && $threadTs !== $ts && $this->settings->twoWay()) {
            $stmt = mysqli_prepare($this->db, "SELECT thread_ticket_id FROM chat_threads WHERE thread_platform = 'slack' AND thread_channel_id = ? AND thread_ref = ? AND thread_ticket_id > 0 LIMIT 1");
            mysqli_stmt_bind_param($stmt, 'ss', $channel, $threadTs);
            mysqli_stmt_execute($stmt);
            $th = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            mysqli_stmt_close($stmt);
            if ($th) {
                $actor = $this->identity->resolve(ChatIdentity::SLACK, $teamId, $user);
                if ($actor === null || $actor['kind'] !== 'agent') {
                    $this->say($channel, $this->notLinked(), [], $threadTs);

                    return null;
                }
                $r = $this->actions->addReply($actor, (int) $th['thread_ticket_id'], $text, null, 'Slack');
                if (!$r['ok']) {
                    $this->say($channel, $r['text'], [], $threadTs);
                }

                return null;
            }
        }

        // 2. A direct message to the bot, or an @mention -> the virtual agent.
        $isDm = $evType === 'message' && (string) ($ev['channel_type'] ?? '') === 'im';
        if (($isDm || $evType === 'app_mention') && $this->settings->virtualAgent()) {
            $actor = $this->identity->resolve(ChatIdentity::SLACK, $teamId, $user);
            if ($actor === null) {
                $this->say($channel, $this->notLinked(), [], $isDm ? null : ($threadTs ?: $ts));

                return null;
            }
            $text = trim(preg_replace('/^@\S+\s*/', '', $text) ?? $text); // drop the leading bot mention
            $a = (new VirtualAgent($this->db, $this->actions))->answer($actor, $text);
            $blocks = $a['results'] ? ChatBlocks::results($a['heading'], $a['results'], $this->settings->actions())['blocks'] : [];
            $this->say($channel, $a['text'], $blocks, $isDm ? null : ($threadTs ?: $ts));
        }

        return null;
    }

    /** Slack message text -> plain text: <@U1> -> @user, <#C1|name> -> #name, <url|label> -> label (url), entities decoded. */
    public static function cleanText(string $t): string
    {
        $t = preg_replace('/<@([UW][A-Z0-9]+)(?:\|[^>]*)?>/', '@user', $t) ?? $t;
        $t = preg_replace('/<#[A-Z0-9]+\|([^>]*)>/', '#$1', $t) ?? $t;
        $t = preg_replace('/<!(?:here|channel|everyone)[^>]*>/', '', $t) ?? $t;
        $t = preg_replace('/<(https?:[^>|]+)\|([^>]*)>/', '$2 ($1)', $t) ?? $t;
        $t = preg_replace('/<(https?:[^>|]+)>/', '$1', $t) ?? $t;
        $t = str_replace(['&lt;', '&gt;', '&amp;'], ['<', '>', '&'], $t);

        return trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $t) ?? '', 0, 4000));
    }

    // ----- slash command -------------------------------------------------------------------------------------------------------

    /**
     * @param array<string,string> $form Slack's form-encoded command payload
     * @return array<string,mixed> the JSON answer (always ephemeral)
     */
    public function command(array $form): array
    {
        $reply = fn (string $t, array $blocks = []) => ['response_type' => 'ephemeral', 'text' => ChatFormatter::slackEscape($t)] + ($blocks ? ['blocks' => $blocks] : []);
        if (!$this->settings->commands()) {
            return $reply('Slash commands are turned off. An administrator can turn them on in Administration > Chat.');
        }
        if (!$this->teamAllowed((string) ($form['team_id'] ?? ''))) {
            return $reply('This Slack workspace is not allowed to use ' . $this->app() . '.');
        }
        $actor = $this->identity->resolve(ChatIdentity::SLACK, (string) ($form['team_id'] ?? ''), (string) ($form['user_id'] ?? ''));
        if ($actor === null) {
            return $reply($this->notLinked());
        }
        $arg = trim((string) ($form['text'] ?? ''));
        [$verb, $rest] = array_pad(preg_split('/\s+/', $arg, 2) ?: [''], 2, '');
        if (strtolower($verb) === 'new' && trim($rest) === '') {
            // No subject: open the ticket form (agents), or say what to type.
            if ($actor['kind'] !== 'agent') {
                return $reply('Say what you need: /ticket new <what is wrong>');
            }
            if (!$this->openNewModal((string) ($form['trigger_id'] ?? ''), $actor, '', '', (string) ($form['channel_id'] ?? ''))) {
                return $reply('I could not open the form. Check the app has a bot token and try again.');
            }

            return []; // the form is the answer
        }
        $r = (new ChatCommands($this->db, $this->actions, $this->settings))->run($actor, $arg, 'Slack', '/ticket ');

        return $reply($r['text'], $r['results'] ? ChatBlocks::results($r['heading'], $r['results'], $this->settings->actions())['blocks'] : []);
    }

    private function openNewModal(string $triggerId, array $actor, string $subject, string $details, string $channel): bool
    {
        if ($triggerId === '' || $actor['kind'] !== 'agent') {
            return false;
        }
        $view = ChatBlocks::newTicketModal($subject, $details, $this->actions->clientsFor($actor), $channel, $this->settings->int('config_chat_default_client_id'));

        return ($this->opener)($triggerId, $view);
    }

    // ----- interactivity ---------------------------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $webhook the destination row whose secret verified the request, or null (app-level secret)
     * @param array<string,mixed> $payload
     * @return array{text:?string,acted:bool,body:?array<string,mixed>,replace:bool}
     */
    public function interaction(?array $webhook, array $payload): array
    {
        $none = ['text' => null, 'acted' => false, 'body' => null, 'replace' => false];
        $type = (string) ($payload['type'] ?? '');
        $team = (string) (($payload['team']['id'] ?? '') ?: ($payload['user']['team_id'] ?? ''));
        $userId = (string) ($payload['user']['id'] ?? '');

        if ($type === 'block_actions') {
            $actionId = (string) ($payload['actions'][0]['action_id'] ?? '');
            if (in_array($actionId, [ChatFormatter::ACTION_ACK, ChatFormatter::ACTION_ASSIGN], true)) {
                return (new SlackInteractive($this->db))->handle($webhook ?? [], $payload) + ['body' => null, 'replace' => false];
            }
            if (!in_array($actionId, [ChatBlocks::ACT_STATUS, ChatBlocks::ACT_PRIORITY, ChatBlocks::ACT_REPLY, ChatBlocks::ACT_APPROVE, ChatBlocks::ACT_REJECT, ChatBlocks::ACT_REQUEST], true)) {
                return $none;
            }
            if (!$this->settings->actions()) {
                return ['text' => 'Changing tickets from chat is turned off. An administrator can turn it on in Administration > Chat.'] + $none;
            }
            if (!$this->teamAllowed($team)) {
                return ['text' => 'This Slack workspace is not allowed to use ' . $this->app() . '.'] + $none;
            }
            $actor = $this->identity->resolve(ChatIdentity::SLACK, $team, $userId);
            if ($actor === null) {
                return ['text' => $this->notLinked()] + $none;
            }

            return $this->blockAction($actor, $actionId, $payload) + ['body' => null];
        }

        if ($type === 'view_submission') {
            return $this->viewSubmission($payload, $team, $userId);
        }

        if ($type === 'message_action' || $type === 'shortcut') {
            $callback = (string) ($payload['callback_id'] ?? '');
            if (!in_array($callback, [ChatBlocks::SHORTCUT_NEW, ChatBlocks::SHORTCUT_GLOBAL], true)) {
                return $none;
            }
            if (!$this->settings->commands()) {
                return ['text' => 'The ticket form is turned off. An administrator can turn it on in Administration > Chat.'] + $none;
            }
            if (!$this->teamAllowed($team)) {
                return ['text' => 'This Slack workspace is not allowed to use ' . $this->app() . '.'] + $none;
            }
            $actor = $this->identity->resolve(ChatIdentity::SLACK, $team, $userId);
            if ($actor === null) {
                return ['text' => $this->notLinked()] + $none;
            }
            if ($actor['kind'] !== 'agent') {
                return ['text' => 'Only agents can use this form. Use /ticket new <what is wrong>.'] + $none;
            }
            $msg = $type === 'message_action' ? self::cleanText((string) ($payload['message']['text'] ?? '')) : '';
            $subject = $msg !== '' ? mb_substr(strtok($msg, "\n") ?: $msg, 0, 120) : '';
            $ok = $this->openNewModal((string) ($payload['trigger_id'] ?? ''), $actor, $subject, $msg, (string) ($payload['channel']['id'] ?? ''));

            return $ok ? $none : ['text' => 'I could not open the form.'] + $none;
        }

        return $none;
    }

    /** @return array{text:?string,acted:bool,replace:bool} */
    private function blockAction(array $actor, string $actionId, array $payload): array
    {
        $action = $payload['actions'][0];
        $value = (string) ($action['selected_option']['value'] ?? $action['value'] ?? '');
        if ($actionId === ChatBlocks::ACT_STATUS || $actionId === ChatBlocks::ACT_PRIORITY) {
            if (!preg_match('/^ticket:([1-9][0-9]{0,9}):(.{1,60})$/', $value, $m)) {
                return ['text' => 'That choice is not valid.', 'acted' => false, 'replace' => false];
            }
            $r = $actionId === ChatBlocks::ACT_STATUS ? $this->actions->changeStatus($actor, (int) $m[1], $m[2], 'Slack') : $this->actions->changePriority($actor, (int) $m[1], $m[2], 'Slack');

            return ['text' => $r['text'], 'acted' => $r['ok'], 'replace' => false];
        }
        if ($actionId === ChatBlocks::ACT_REPLY) {
            if (!preg_match('/^ticket:([1-9][0-9]{0,9})$/', $value, $m)) {
                return ['text' => 'That button is not valid.', 'acted' => false, 'replace' => false];
            }
            $t = $this->actions->findTicket($actor, 'id:' . $m[1]);
            if ($t === null || $actor['kind'] !== 'agent') {
                return ['text' => 'I could not find that ticket.', 'acted' => false, 'replace' => false];
            }
            $ok = ($this->opener)((string) ($payload['trigger_id'] ?? ''), ChatBlocks::replyModal((int) $t['ticket_id'], $this->actions->label($t)));

            return ['text' => $ok ? null : 'I could not open the reply form.', 'acted' => false, 'replace' => false];
        }
        if ($actionId === ChatBlocks::ACT_REQUEST) {
            if (!preg_match('/^catalog_item:([1-9][0-9]{0,9})$/', $value, $m)) {
                return ['text' => 'That button is not valid.', 'acted' => false, 'replace' => false];
            }
            $r = $this->actions->requestCatalogItem($actor, (int) $m[1], 'Slack');

            return ['text' => $r['text'], 'acted' => $r['ok'], 'replace' => false];
        }
        // approve / reject
        if (!preg_match('/^(catalog_request|workflow_task):([1-9][0-9]{0,9})$/', $value, $m)) {
            return ['text' => 'That button is not valid.', 'acted' => false, 'replace' => false];
        }
        $approve = $actionId === ChatBlocks::ACT_APPROVE;
        $comment = ($approve ? 'Approved' : 'Denied') . ' in Slack by ' . $actor['name'];
        $r = $m[1] === 'catalog_request' ? $this->actions->decideCatalog($actor, (int) $m[2], $approve, $comment, 'Slack') : $this->actions->decideWorkflow($actor, (int) $m[2], $approve, $comment, 'Slack');

        return ['text' => $r['ok'] ? ($approve ? 'Approved: ' : 'Denied: ') . $r['text'] : $r['text'], 'acted' => $r['ok'], 'replace' => $r['ok']];
    }

    /** @return array{text:?string,acted:bool,body:?array<string,mixed>,replace:bool} */
    private function viewSubmission(array $payload, string $team, string $userId): array
    {
        $out = ['text' => null, 'acted' => false, 'body' => null, 'replace' => false];
        $view = (array) ($payload['view'] ?? []);
        $callback = (string) ($view['callback_id'] ?? '');
        if (!in_array($callback, [ChatBlocks::CB_NEW_MODAL, ChatBlocks::CB_REPLY_MODAL], true)) {
            return $out;
        }
        $err = fn (string $block, string $msg) => ['body' => ['response_action' => 'errors', 'errors' => [$block => $msg]]] + $out;
        $gate = $callback === ChatBlocks::CB_NEW_MODAL ? $this->settings->commands() : $this->settings->actions();
        $actor = $gate && $this->teamAllowed($team) ? $this->identity->resolve(ChatIdentity::SLACK, $team, $userId) : null;
        if ($actor === null || $actor['kind'] !== 'agent') {
            return $err($callback === ChatBlocks::CB_NEW_MODAL ? 'subject' : 'text', $gate ? $this->notLinked() : 'This is turned off. An administrator can turn it on in Administration > Chat.');
        }
        $v = static fn (string $block): string => (string) ($view['state']['values'][$block]['v']['value'] ?? ($view['state']['values'][$block]['v']['selected_option']['value'] ?? ''));

        if ($callback === ChatBlocks::CB_REPLY_MODAL) {
            if (!ctype_digit((string) ($view['private_metadata'] ?? ''))) {
                return $out;
            }
            $r = $this->actions->addReply($actor, (int) $view['private_metadata'], $v('text'), null, 'Slack');
            if (!$r['ok']) {
                return $err('text', $r['text']);
            }
            $this->say($userId, $r['text']);

            return ['body' => ['response_action' => 'clear'], 'acted' => true] + $out;
        }

        $subject = trim($v('subject'));
        if ($subject === '') {
            return $err('subject', 'A ticket needs a subject.');
        }
        $client = ctype_digit($v('client')) ? (int) $v('client') : 0;
        $r = $this->actions->createTicket($actor, $subject, $v('details') !== '' ? $v('details') : $subject, $v('priority'), $client, 'Slack');
        if (!$r['ok']) {
            return $err('subject', $r['text']);
        }
        $this->say($userId, $r['text']);

        return ['body' => ['response_action' => 'clear'], 'acted' => true] + $out;
    }
}
