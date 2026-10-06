<?php

namespace ITFlow\Webhooks;

/**
 * Pure formatting of an event into a Slack (Block Kit) or Microsoft Teams (Adaptive Card) chat message. No I/O, no
 * database, no globals: everything it needs is passed in, so it is unit-testable.
 *
 * User text (ticket subjects, client names, audit summaries) is untrusted. It is never interpreted as markup or as a
 * mention:
 *   - Slack: user text only ever goes into `plain_text` elements, and the `text` fallback is HTML-entity escaped
 *     (&, <, > -> &amp; &lt; &gt;, which is what turns <!channel>, <@U123> and <http://x|y> into inert text) with
 *     @channel/@here/@everyone defanged, plus "mrkdwn": false.
 *   - Teams: Adaptive Card TextBlock/FactSet text supports a Markdown subset, so Markdown control characters are
 *     backslash-escaped and angle brackets are replaced, which stops [links](x), emphasis and <at> mentions.
 * The result is always a PHP array; callers JSON-encode it, so quoting is never done by hand.
 */
final class ChatFormatter
{
    public const TYPE_GENERIC = 'generic';
    public const TYPE_SLACK = 'slack';
    public const TYPE_TEAMS = 'teams';
    public const TYPES = [self::TYPE_GENERIC, self::TYPE_SLACK, self::TYPE_TEAMS];

    /** Slack button action ids handled by slack_interactive.php. */
    public const ACTION_ACK = 'rivet_ack';
    public const ACTION_ASSIGN = 'rivet_assign';

    /** Lowest to highest. Anything not listed (blank, custom) ranks 0 and is never filtered out by a minimum. */
    public const PRIORITIES = ['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4];

    public static function isChatType(string $type): bool
    {
        return $type === self::TYPE_SLACK || $type === self::TYPE_TEAMS;
    }

    public static function normalizeType(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return in_array($type, self::TYPES, true) ? $type : self::TYPE_GENERIC;
    }

    // ----- routing ---------------------------------------------------------------------------------------------

    /**
     * Should this destination receive this event? $webhook keys: webhook_events (comma list), webhook_min_priority
     * ('' = any), webhook_client_ids (comma list of client ids, '' = all clients).
     *
     * A priority or client filter only applies to events that carry a ticket priority / client id: a platform event
     * (login, setting change) has neither, so it is not dropped by them.
     */
    public static function shouldDeliver(array $webhook, string $event, array $data): bool
    {
        $events = array_filter(array_map('trim', explode(',', (string) ($webhook['webhook_events'] ?? ''))));
        if (!in_array($event, $events, true)) {
            return false;
        }

        $min = (string) ($webhook['webhook_min_priority'] ?? '');
        if ($min !== '' && isset(self::PRIORITIES[$min]) && isset($data['ticket_priority'])) {
            $rank = self::PRIORITIES[(string) $data['ticket_priority']] ?? 0;
            if ($rank !== 0 && $rank < self::PRIORITIES[$min]) {
                return false;
            }
        }

        $clients = array_filter(array_map('intval', array_filter(array_map('trim', explode(',', (string) ($webhook['webhook_client_ids'] ?? ''))), 'ctype_digit')));
        if ($clients && isset($data['client_id']) && !in_array((int) $data['client_id'], $clients, true)) {
            return false;
        }

        return true;
    }

    // ----- common model ----------------------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $data  the event's data array
     * @param array{ticket_url?:?string, test?:bool, app_name?:string, interactive?:bool} $opts
     * @return array{headline:string,title:string,facts:list<array{0:string,1:string}>,url:?string,priority:string,test:bool,ticket_id:int}
     */
    public static function describe(string $event, array $data, array $opts = []): array
    {
        $test = !empty($opts['test']);
        $app = self::clip((string) ($opts['app_name'] ?? 'RivetIT'), 40);
        $facts = [];
        $priority = '';
        $url = null;
        $ticketId = 0;

        if (isset($data['ticket_subject']) || isset($data['ticket_number'])) {
            $headline = [
                'ticket.created' => 'New ticket',
                'ticket.replied' => 'New reply on ticket',
                'ticket.assigned' => 'Ticket assigned',
                'ticket.status_changed' => 'Ticket status changed',
                'ticket.resolved' => 'Ticket resolved',
            ][$event] ?? 'Ticket event: ' . $event;
            $number = self::scalar($data['ticket_number'] ?? '');
            $subject = self::scalar($data['ticket_subject'] ?? '');
            $title = trim(($number !== '' ? $number . ' ' : '') . $subject);
            $priority = self::scalar($data['ticket_priority'] ?? '');
            if ($priority !== '') {
                $facts[] = ['Priority', $priority];
            }
            if (self::scalar($data['client_name'] ?? '') !== '') {
                $facts[] = ['Client', self::scalar($data['client_name'])];
            }
            if (self::scalar($data['ticket_status'] ?? '') !== '') {
                $facts[] = ['Status', self::scalar($data['ticket_status'])];
            }
            if (self::scalar($data['assigned_to_user_name'] ?? '') !== '') {
                $facts[] = ['Assigned to', self::scalar($data['assigned_to_user_name'])];
            }
            $ticketId = isset($data['ticket_id']) ? max(0, (int) $data['ticket_id']) : 0;
            $url = isset($opts['ticket_url']) && is_string($opts['ticket_url']) && preg_match('#^https?://[^\s<>"\'|]+$#', $opts['ticket_url']) ? $opts['ticket_url'] : null;
        } else {
            // Platform / audit events: only the summary, action and entity type are shown, never the metadata blob.
            $headline = 'Event: ' . $event;
            $title = self::scalar($data['summary'] ?? '') !== '' ? self::scalar($data['summary']) : $event;
            if (self::scalar($data['action'] ?? '') !== '') {
                $facts[] = ['Action', self::scalar($data['action'])];
            }
            if (self::scalar($data['entity_type'] ?? '') !== '') {
                $facts[] = ['Entity', self::scalar($data['entity_type'])];
            }
        }

        if ($test) {
            $headline = "TEST MESSAGE from $app";
            $title = $title !== '' ? $title : 'This is a test message. Nothing is wrong.';
        }

        return [
            'headline' => self::clip($headline, 140),
            'title' => self::clip($title, 250),
            'facts' => array_map(static fn (array $f) => [$f[0], self::clip($f[1], 150)], $facts),
            'url' => $url,
            'priority' => $priority,
            'test' => $test,
            'ticket_id' => $ticketId,
        ];
    }

    // ----- Slack -----------------------------------------------------------------------------------------------

    /** @return array<string,mixed> {text, blocks, mrkdwn} ready for json_encode */
    public static function slack(string $event, array $data, array $opts = []): array
    {
        $d = self::describe($event, $data, $opts);

        $fields = [];
        foreach (array_slice($d['facts'], 0, 10) as [$k, $v]) {
            $fields[] = ['type' => 'plain_text', 'text' => self::clip($k . ': ' . $v, 150), 'emoji' => false];
        }

        $blocks = [
            ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => self::clip($d['headline'], 150), 'emoji' => false]],
            ['type' => 'section', 'text' => ['type' => 'plain_text', 'text' => self::clip($d['title'], 3000), 'emoji' => false]],
        ];
        if ($fields) {
            $blocks[1]['fields'] = $fields;
        }
        $buttons = [];
        if ($d['url'] !== null) {
            $buttons[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => 'Open ticket', 'emoji' => false],
                'url' => $d['url'],
            ];
        }
        // Interactive buttons only when the destination has a Slack signing secret (opts['interactive']), so the request that
        // comes back can be verified; never on a test message. The value carries only the ticket id.
        if (!empty($opts['interactive']) && $d['ticket_id'] > 0 && !$d['test']) {
            $buttons[] = ['type' => 'button', 'action_id' => self::ACTION_ACK, 'value' => 'ticket:' . $d['ticket_id'], 'text' => ['type' => 'plain_text', 'text' => 'Acknowledge', 'emoji' => false]];
            $buttons[] = ['type' => 'button', 'action_id' => self::ACTION_ASSIGN, 'value' => 'ticket:' . $d['ticket_id'], 'text' => ['type' => 'plain_text', 'text' => 'Assign to me', 'emoji' => false]];
        }
        if ($buttons) {
            $blocks[] = ['type' => 'actions', 'elements' => $buttons];
        }

        $fallback = $d['headline'] . ': ' . $d['title'];
        foreach ($d['facts'] as [$k, $v]) {
            if ($k === 'Priority' || $k === 'Client') {
                $fallback .= ' | ' . $k . ': ' . $v;
            }
        }

        return ['text' => self::slackEscape($fallback), 'blocks' => $blocks, 'mrkdwn' => false];
    }

    /** Make text inert for Slack: entity-escape, then break the plain-text broadcast keywords. */
    public static function slackEscape(string $text): string
    {
        $text = self::stripControl($text);
        $text = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);

        return preg_replace('/@(channel|here|everyone)\b/i', "@\u{200B}$1", $text);
    }

    // ----- Teams -----------------------------------------------------------------------------------------------

    /**
     * Workflows / Power Automate "Post to a channel when a webhook request is received" format: a message with one
     * Adaptive Card attachment. (The classic Office 365 connector MessageCard format is retired.)
     *
     * @return array<string,mixed>
     */
    public static function teams(string $event, array $data, array $opts = []): array
    {
        $d = self::describe($event, $data, $opts);
        $attention = in_array($d['priority'], ['High', 'Critical'], true);

        $body = [
            ['type' => 'TextBlock', 'text' => self::teamsEscape($d['headline']), 'weight' => 'Bolder', 'size' => 'Medium', 'wrap' => true,
                'color' => $d['test'] ? 'Warning' : ($attention ? 'Attention' : 'Default')],
            ['type' => 'TextBlock', 'text' => self::teamsEscape($d['title']), 'wrap' => true, 'spacing' => 'Small'],
        ];
        if ($d['facts']) {
            $facts = [];
            foreach (array_slice($d['facts'], 0, 10) as [$k, $v]) {
                $facts[] = ['title' => self::teamsEscape($k), 'value' => self::teamsEscape($v)];
            }
            $body[] = ['type' => 'FactSet', 'facts' => $facts];
        }

        $card = [
            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
            'type' => 'AdaptiveCard',
            'version' => '1.4',
            'body' => $body,
            'msteams' => ['width' => 'Full'],
        ];
        if ($d['url'] !== null) {
            $card['actions'] = [['type' => 'Action.OpenUrl', 'title' => 'Open ticket', 'url' => $d['url']]];
        }

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => $card,
            ]],
        ];
    }

    /** Backslash-escape Markdown control characters and neutralise angle brackets (<at> mentions, HTML). */
    public static function teamsEscape(string $text): string
    {
        $text = self::stripControl($text);
        $text = preg_replace('/([\\\\`*_{}\[\]()#+!|~>-])/', '\\\\$1', $text);

        return str_replace(['<', '&'], ["\u{FF1C}", "\u{FF06}"], $text);
    }

    // ----- helpers ---------------------------------------------------------------------------------------------

    public static function format(string $type, string $event, array $data, array $opts = []): ?array
    {
        return match ($type) {
            self::TYPE_SLACK => self::slack($event, $data, $opts),
            self::TYPE_TEAMS => self::teams($event, $data, $opts),
            default => null,
        };
    }

    private static function scalar($v): string
    {
        return is_scalar($v) ? trim(self::stripControl((string) $v)) : '';
    }

    private static function stripControl(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    }

    private static function clip(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . "\u{2026}" : $s;
    }
}
