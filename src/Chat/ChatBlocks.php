<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\ChatFormatter;

/**
 * Slack Block Kit pieces for the chat features: the action row on a ticket message (status, priority, reply), approval prompts, search results.
 * Pure: no I/O. User text only ever goes into plain_text elements; the `text` fallback of a message goes through ChatFormatter::slackEscape.
 *
 * action_id / value contract (parsed again in SlackApp, which re-validates everything; a value is only a hint, never trusted for identity):
 *   rivet_set_status    static_select, option value "ticket:<id>:<status name>"
 *   rivet_set_priority  static_select, option value "ticket:<id>:<priority>"
 *   rivet_reply         button, value "ticket:<id>"  -> opens a reply modal
 *   rivet_approve / rivet_reject   button, value "catalog_request:<id>" or "workflow_task:<id>"
 */
final class ChatBlocks
{
    public const ACT_STATUS = 'rivet_set_status';
    public const ACT_PRIORITY = 'rivet_set_priority';
    public const ACT_REPLY = 'rivet_reply';
    public const ACT_APPROVE = 'rivet_approve';
    public const ACT_REJECT = 'rivet_reject';
    public const ACT_REQUEST = 'rivet_request';
    public const CB_REPLY_MODAL = 'rivet_reply_modal';
    public const CB_NEW_MODAL = 'rivet_new_ticket_modal';
    public const SHORTCUT_NEW = 'rivet_ticket_from_message';
    public const SHORTCUT_GLOBAL = 'rivet_new_ticket';

    private static function plain(string $t, int $max = 150): array
    {
        return ['type' => 'plain_text', 'text' => self::clip($t, $max), 'emoji' => false];
    }

    public static function clip(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . "\u{2026}" : $s;
    }

    /**
     * Extra elements for a ticket message: status select, priority select, Reply button. Statuses never include Closed / Resolved.
     *
     * @param list<string> $statusNames active status names the actor may pick
     * @return list<array<string,mixed>> the elements of one `actions` block
     */
    public static function ticketControls(int $ticketId, string $currentStatus, string $currentPriority, array $statusNames): array
    {
        $out = [];
        $opts = [];
        foreach (array_slice($statusNames, 0, 20) as $n) {
            $opts[] = ['text' => self::plain($n, 75), 'value' => self::clip('ticket:' . $ticketId . ':' . $n, 150)];
        }
        if ($opts) {
            $sel = ['type' => 'static_select', 'action_id' => self::ACT_STATUS, 'placeholder' => self::plain('Set status'), 'options' => $opts];
            foreach ($opts as $o) {
                if (strcasecmp($o['text']['text'], $currentStatus) === 0) {
                    $sel['initial_option'] = $o;
                }
            }
            $out[] = $sel;
        }
        $popts = [];
        foreach (ChatActions::PRIORITIES as $p) {
            $popts[] = ['text' => self::plain($p, 75), 'value' => 'ticket:' . $ticketId . ':' . $p];
        }
        $psel = ['type' => 'static_select', 'action_id' => self::ACT_PRIORITY, 'placeholder' => self::plain('Set priority'), 'options' => $popts];
        foreach ($popts as $o) {
            if (strcasecmp($o['text']['text'], $currentPriority) === 0) {
                $psel['initial_option'] = $o;
            }
        }
        $out[] = $psel;
        $out[] = ['type' => 'button', 'action_id' => self::ACT_REPLY, 'value' => 'ticket:' . $ticketId, 'text' => self::plain('Reply')];

        return $out;
    }

    /** A direct message asking an approver to decide. @return array{text:string,blocks:list<array<string,mixed>>} */
    public static function approvalPrompt(string $kind, int $id, string $title, string $detail, ?string $url): array
    {
        $value = $kind . ':' . $id;
        $buttons = [
            ['type' => 'button', 'action_id' => self::ACT_APPROVE, 'style' => 'primary', 'value' => $value, 'text' => self::plain('Approve')],
            ['type' => 'button', 'action_id' => self::ACT_REJECT, 'style' => 'danger', 'value' => $value, 'text' => self::plain('Deny')],
        ];
        if ($url !== null && preg_match('#^https?://[^\s<>"\'|]+$#', $url)) {
            $buttons[] = ['type' => 'button', 'text' => self::plain('Open'), 'url' => $url];
        }

        return [
            'text' => ChatFormatter::slackEscape('Approval needed: ' . $title),
            'blocks' => [
                ['type' => 'header', 'text' => self::plain('Approval needed')],
                ['type' => 'section', 'text' => self::plain($title . ($detail !== '' ? "\n" . $detail : ''), 3000)],
                ['type' => 'actions', 'elements' => $buttons],
            ],
        ];
    }

    /** The modal for a new ticket. @param list<array{id:int,name:string}> $clients */
    public static function newTicketModal(string $prefillSubject, string $prefillDetails, array $clients, string $channelId = '', int $defaultClient = 0): array
    {
        $blocks = [
            ['type' => 'input', 'block_id' => 'subject', 'label' => self::plain('Subject'), 'element' => ['type' => 'plain_text_input', 'action_id' => 'v', 'initial_value' => self::clip($prefillSubject, 200), 'max_length' => 200]],
            ['type' => 'input', 'block_id' => 'details', 'optional' => true, 'label' => self::plain('Details'), 'element' => ['type' => 'plain_text_input', 'action_id' => 'v', 'multiline' => true, 'initial_value' => self::clip($prefillDetails, 3000), 'max_length' => 3000]],
        ];
        if ($clients) {
            $opts = [];
            $init = null;
            foreach (array_slice($clients, 0, 100) as $c) {
                $o = ['text' => self::plain($c['name'], 75), 'value' => (string) $c['id']];
                $opts[] = $o;
                if ($c['id'] === $defaultClient) {
                    $init = $o;
                }
            }
            $el = ['type' => 'static_select', 'action_id' => 'v', 'options' => $opts];
            if ($init) {
                $el['initial_option'] = $init;
            }
            $blocks[] = ['type' => 'input', 'block_id' => 'client', 'label' => self::plain('Client'), 'element' => $el];
        }
        $popts = array_map(static fn (string $p) => ['text' => self::plain($p, 75), 'value' => $p], ChatActions::PRIORITIES);
        $blocks[] = ['type' => 'input', 'block_id' => 'priority', 'optional' => true, 'label' => self::plain('Priority'), 'element' => ['type' => 'static_select', 'action_id' => 'v', 'options' => $popts, 'initial_option' => $popts[0]]];

        return [
            'type' => 'modal', 'callback_id' => self::CB_NEW_MODAL, 'private_metadata' => self::clip($channelId, 100),
            'title' => self::plain('New ticket', 24), 'submit' => self::plain('Create', 24), 'close' => self::plain('Cancel', 24),
            'blocks' => $blocks,
        ];
    }

    public static function replyModal(int $ticketId, string $label): array
    {
        return [
            'type' => 'modal', 'callback_id' => self::CB_REPLY_MODAL, 'private_metadata' => (string) $ticketId,
            'title' => self::plain('Reply', 24), 'submit' => self::plain('Send', 24), 'close' => self::plain('Cancel', 24),
            'blocks' => [
                ['type' => 'section', 'text' => self::plain('Reply to ' . $label, 200)],
                ['type' => 'input', 'block_id' => 'text', 'label' => self::plain('Reply'), 'element' => ['type' => 'plain_text_input', 'action_id' => 'v', 'multiline' => true, 'max_length' => 3000]],
            ],
        ];
    }

    /**
     * Search results as a message. A catalog row (it has request_item) gets a one-click Request button when $withRequest is true
     * (the answer is re-checked server side; the button only carries the item id).
     * @param list<array{title:string,url:?string,snippet:string,request_item?:int}> $rows
     */
    public static function results(string $heading, array $rows, bool $withRequest = false): array
    {
        $blocks = [['type' => 'header', 'text' => self::plain($heading)]];
        foreach (array_slice($rows, 0, 5) as $r) {
            $b = ['type' => 'section', 'text' => self::plain($r['title'] . ($r['snippet'] !== '' ? "\n" . $r['snippet'] : ''), 600)];
            if (!empty($r['url']) && preg_match('#^https?://[^\s<>"\'|]+$#', $r['url'])) {
                $b['accessory'] = ['type' => 'button', 'text' => self::plain('Open'), 'url' => $r['url']];
            }
            $blocks[] = $b;
            if ($withRequest && !empty($r['request_item']) && (int) $r['request_item'] > 0) {
                $blocks[] = ['type' => 'actions', 'elements' => [['type' => 'button', 'action_id' => self::ACT_REQUEST, 'value' => 'catalog_item:' . (int) $r['request_item'], 'text' => self::plain('Request this'), 'style' => 'primary']]];
            }
        }

        return ['text' => ChatFormatter::slackEscape($heading), 'blocks' => $blocks];
    }
}
