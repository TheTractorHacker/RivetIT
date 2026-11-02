<?php

namespace ITFlow\Chat;

use ITFlow\Automation\EventPayloads;
use ITFlow\Webhooks\ChatFormatter;

/**
 * The outbound side of chat, as a service other code can call (the counterpart of ChatActions, which is the inbound side).
 *
 *   ChatNotifier::sendToUser($db, $userId, $text, $slackBlocks, $teamsCard)   DM an agent on every chat platform they are linked on
 *   ChatNotifier::approvalRequested($db, 'catalog_request'|'workflow_task', $id)   ask the approvers to Approve / Deny from chat
 *   ChatNotifier::handleEvent($db, $event, $data)   ticket thread mirroring (called from the event bus job 'chat.sync')
 *   ChatNotifier::mirrorTicket / syncTicket        post a ticket into the configured channel as a thread, and its replies into the thread
 *
 * Everything is a no-op unless the matching switch is on (Administration > Chat) and the platform credentials exist. It never throws into
 * the caller: a chat outage must not break a ticket or an approval. Slack goes through SlackApi (bot token, pinned/SSRF-safe), Teams through
 * TeamsConnector (UNVERIFIED LIVE).
 */
final class ChatNotifier
{
    /** Remember that a ticket reply was created FROM chat, so the thread sync does not echo it back. */
    public static function markOrigin(\mysqli $db, int $replyId): void
    {
        try {
            $key = 'o:' . $replyId;
            $stmt = mysqli_prepare($db, "INSERT IGNORE INTO chat_kv (kv_key, kv_value) VALUES (?, '')");
            mysqli_stmt_bind_param($stmt, 's', $key);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        } catch (\Throwable $e) {
            // the table may not exist before the migration; then there is no thread to echo into either
        }
    }

    // ----- direct messages -----------------------------------------------------------------------------------------------

    /**
     * @param list<array<string,mixed>> $slackBlocks
     * @param array<string,mixed>|null  $teamsCard   an Adaptive Card body (TeamsCards)
     * @return array{slack:int,teams:int} how many messages were sent
     */
    public static function sendToUser(\mysqli $db, int $userId, string $text, array $slackBlocks = [], ?array $teamsCard = null): array
    {
        $sent = ['slack' => 0, 'teams' => 0];
        try {
            $settings = new ChatSettings($db);
            $res = mysqli_query($db, 'SELECT identity_platform, identity_external_id FROM chat_identities WHERE identity_user_id = ' . $userId);
            while ($res && ($i = mysqli_fetch_assoc($res))) {
                if ($i['identity_platform'] === ChatIdentity::SLACK) {
                    $token = $settings->secret('config_slack_bot_token');
                    if ($token !== '' && SlackApi::postMessage($token, $i['identity_external_id'], ChatFormatter::slackEscape($text), $slackBlocks)['ok']) {
                        $sent['slack']++;
                    }
                } elseif ($i['identity_platform'] === ChatIdentity::TEAMS && $settings->teamsBot() && $teamsCard !== null) {
                    $dm = mysqli_fetch_assoc(mysqli_query($db, "SELECT thread_channel_id, thread_service_url FROM chat_threads WHERE thread_platform = 'teams' AND thread_ticket_id = 0 AND thread_ref = 'dm:" . mysqli_real_escape_string($db, strtolower($i['identity_external_id'])) . "' LIMIT 1"));
                    if ($dm && (new TeamsConnector($db, $settings))->send($dm['thread_service_url'], $dm['thread_channel_id'], TeamsCards::activity($text, $teamsCard))['ok']) {
                        $sent['teams']++;
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('chat sendToUser skipped: ' . get_class($e));
        }

        return $sent;
    }

    /** Ask the approvers of a catalog request / workflow task to decide from chat. Only when "actions from chat" is on. @return int messages sent */
    public static function approvalRequested(\mysqli $db, string $kind, int $id): int
    {
        try {
            $settings = new ChatSettings($db);
            if (!$settings->actions()) {
                return 0;
            }
            $title = '';
            $detail = '';
            $userIds = [];
            if ($kind === 'catalog_request') {
                $res = mysqli_query($db, "SELECT a.approver_user_id, i.name, t.ticket_prefix, t.ticket_number, t.ticket_subject FROM service_catalog_request_approvals a JOIN service_catalog_requests r ON r.request_id = a.request_id AND r.current_step = a.step_order AND r.status = 'pending_approval' JOIN service_catalog_items i ON i.catalog_item_id = r.catalog_item_id JOIN tickets t ON t.ticket_id = r.ticket_id WHERE a.request_id = $id AND a.status = 'pending' AND a.approver_user_id IS NOT NULL");
                while ($res && ($r = mysqli_fetch_assoc($res))) {
                    $userIds[] = (int) $r['approver_user_id'];
                    $title = $r['name'];
                    $detail = $r['ticket_prefix'] . $r['ticket_number'] . ' ' . $r['ticket_subject'];
                }
                $url = ChatSettings::baseUrl() !== '' ? ChatSettings::baseUrl() . '/agent/service_catalog_approvals.php?request_id=' . $id : null;
            } elseif ($kind === 'workflow_task') {
                $t = mysqli_fetch_assoc(mysqli_query($db, "SELECT t.*, r.contact_id FROM workflow_run_tasks t JOIN workflow_runs r ON r.run_id = t.run_id WHERE t.run_task_id = $id AND t.task_type = 'approval' AND t.status = 'pending' AND t.approval_status = 'pending'"));
                if ($t) {
                    $contact = \ITFlow\Workflow\TaskActionRunner::loadContact($db, (int) $t['contact_id']) ?? [];
                    $userIds = (new \ITFlow\Workflow\WorkflowService($db))->approverUserIds($t, $contact);
                    $title = (string) $t['title'];
                    $detail = 'for ' . ($contact['contact_name'] ?? 'an employee');
                }
                $url = null;
            } else {
                return 0;
            }
            if (!$userIds || $title === '') {
                return 0;
            }
            $slack = ChatBlocks::approvalPrompt($kind, $id, $title, $detail, $url);
            $teams = TeamsCards::approval($kind, $id, $title, $detail);
            $n = 0;
            foreach (array_unique($userIds) as $uid) {
                $s = self::sendToUser($db, $uid, $slack['text'], $slack['blocks'], $teams);
                $n += $s['slack'] + $s['teams'];
            }

            return $n;
        } catch (\Throwable $e) {
            error_log('chat approvalRequested skipped: ' . get_class($e) . (getenv('RIVETIT_CHAT_DEBUG') ? ' ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() : ''));

            return 0;
        }
    }

    // ----- ticket threads (two-way) ------------------------------------------------------------------------------------------

    /** The event bus hands over ticket.created / ticket.replied; everything else is ignored. */
    public static function handleEvent(\mysqli $db, string $event, array $data): void
    {
        try {
            $settings = new ChatSettings($db);
            $ticketId = (int) ($data['ticket_id'] ?? 0);
            if (!$settings->twoWay() || $ticketId <= 0) {
                return;
            }
            if ($event === 'ticket.created') {
                self::mirrorTicket($db, $ticketId);
            } elseif ($event === 'ticket.replied') {
                self::syncTicket($db, $ticketId);
            }
        } catch (\Throwable $e) {
            error_log('chat handleEvent skipped: ' . get_class($e));
        }
    }

    /** Post the ticket into the configured Slack channel as a new thread (once). @return array<string,mixed>|null the thread row */
    public static function mirrorTicket(\mysqli $db, int $ticketId): ?array
    {
        $settings = new ChatSettings($db);
        $token = $settings->secret('config_slack_bot_token');
        $channel = $settings->text('config_chat_slack_channel_id');
        if (!$settings->twoWay() || $token === '' || !preg_match('/^[CG][A-Z0-9]{4,20}$/', $channel)) {
            return null;
        }
        $existing = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM chat_threads WHERE thread_platform = 'slack' AND thread_ticket_id = $ticketId LIMIT 1"));
        if ($existing) {
            return $existing;
        }
        $data = EventPayloads::ticket($db, $ticketId);
        if (empty($data['ticket_subject'])) {
            return null;
        }
        $opts = \ITFlow\Webhooks\ChatDelivery::formatterOptions($data);
        $msg = ChatFormatter::slack('ticket.created', $data, $opts);
        $r = SlackApi::postMessage($token, $channel, $msg['text'], $msg['blocks']);
        $ts = (string) ($r['data']['ts'] ?? '');
        if (!$r['ok'] || !preg_match('/^[0-9]+\.[0-9]+$/', $ts)) {
            return null;
        }
        $team = $settings->text('config_slack_team_id');
        $last = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COALESCE(MAX(ticket_reply_id), 0) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticketId"))[0] ?? 0);
        $stmt = mysqli_prepare($db, "INSERT IGNORE INTO chat_threads (thread_platform, thread_team_id, thread_channel_id, thread_ref, thread_ticket_id, thread_last_reply_id) VALUES ('slack', ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'sssii', $team, $channel, $ts, $ticketId, $last);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM chat_threads WHERE thread_platform = 'slack' AND thread_ticket_id = $ticketId LIMIT 1")) ?: null;
    }

    /** Post replies the thread has not seen yet. Public / client replies always; internal notes only when the install mirrors them. @return int posted */
    public static function syncTicket(\mysqli $db, int $ticketId): int
    {
        $settings = new ChatSettings($db);
        if (!$settings->twoWay()) {
            return 0;
        }
        mysqli_query($db, "DELETE FROM chat_kv WHERE kv_key LIKE 'o:%' AND kv_updated_at < NOW() - INTERVAL 2 DAY");
        $types = $settings->flag('config_chat_mirror_internal') ? "'Public','Client','Internal'" : "'Public','Client'";
        $posted = 0;
        $threads = mysqli_query($db, "SELECT * FROM chat_threads WHERE thread_ticket_id = $ticketId AND thread_platform IN ('slack','teams')");
        while ($threads && ($th = mysqli_fetch_assoc($threads))) {
            $last = (int) $th['thread_last_reply_id'];
            $res = mysqli_query($db, "SELECT r.ticket_reply_id, r.ticket_reply, r.ticket_reply_type, r.ticket_reply_by, u.user_name, c.contact_name FROM ticket_replies r
                LEFT JOIN users u ON u.user_id = r.ticket_reply_by AND r.ticket_reply_type <> 'Client' LEFT JOIN contacts c ON c.contact_id = r.ticket_reply_by AND r.ticket_reply_type = 'Client'
                WHERE r.ticket_reply_ticket_id = $ticketId AND r.ticket_reply_id > $last AND r.ticket_reply_archived_at IS NULL AND r.ticket_reply_type IN ($types)
                AND NOT EXISTS (SELECT 1 FROM chat_kv k WHERE k.kv_key = CONCAT('o:', r.ticket_reply_id)) ORDER BY r.ticket_reply_id ASC LIMIT 20");
            $maxSeen = $last;
            $stopped = false;
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $who = (string) ($r['ticket_reply_type'] === 'Client' ? ($r['contact_name'] ?: 'Customer') : ($r['user_name'] ?: 'Agent'));
                $label = $r['ticket_reply_type'] === 'Internal' ? 'internal note' : ($r['ticket_reply_type'] === 'Client' ? 'customer' : 'public reply');
                $body = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string) $r['ticket_reply'])), ENT_QUOTES, 'UTF-8');
                $text = $who . ' (' . $label . '): ' . ChatBlocks::clip(trim($body), 2800);
                if (self::postToThread($db, $settings, $th, $text)) {
                    $maxSeen = (int) $r['ticket_reply_id'];
                    $posted++;
                } else {
                    $stopped = true; // keep the cursor where it is: the next event retries from here
                    break;
                }
            }
            if (!$stopped) {
                // Replies of types we do not mirror (and chat-origin ones) are skipped over too, so the cursor catches up.
                $max = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COALESCE(MAX(ticket_reply_id), 0) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticketId"))[0] ?? 0);
                $maxSeen = max($maxSeen, $max);
            }
            if ($maxSeen > $last) {
                mysqli_query($db, "UPDATE chat_threads SET thread_last_reply_id = $maxSeen WHERE thread_id = " . (int) $th['thread_id'] . " AND thread_last_reply_id < $maxSeen");
            }
        }

        return $posted;
    }

    /** @param array<string,mixed> $th a chat_threads row */
    private static function postToThread(\mysqli $db, ChatSettings $settings, array $th, string $text): bool
    {
        if ($th['thread_platform'] === 'slack') {
            return SlackApi::postMessage($settings->secret('config_slack_bot_token'), $th['thread_channel_id'], ChatFormatter::slackEscape($text), [], $th['thread_ref'])['ok'];
        }
        if ($th['thread_platform'] === 'teams' && $settings->teamsBot()) {
            return (new TeamsConnector($db, $settings))->send($th['thread_service_url'], $th['thread_channel_id'], TeamsCards::textActivity($text))['ok'];
        }

        return false;
    }
}
