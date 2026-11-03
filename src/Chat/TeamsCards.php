<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\ChatFormatter;

/**
 * Teams (Bot Framework) activities and Adaptive Cards for the chat features. Pure: no I/O. All user text goes through ChatFormatter::teamsEscape
 * (Markdown characters and <at> mentions are neutralised); button data is a small fixed shape that TeamsBot re-validates:
 *   {"rivet":"approve"|"reject","kind":"catalog_request"|"workflow_task","id":N}
 *   {"rivet":"status"|"priority","ticket":N}   with the value picked in the card's choice set (field "choice")
 *   {"rivet":"request","item":N}            raise a catalog request (virtual agent search results)
 *   {"rivet":"reply","ticket":N}               with the text typed in the card's input (field "text")
 */
final class TeamsCards
{
    public static function textActivity(string $text): array
    {
        return ['type' => 'message', 'text' => ChatFormatter::teamsEscape(ChatBlocks::clip($text, 3000)), 'textFormat' => 'plain'];
    }

    /** @param array<string,mixed> $card AdaptiveCard content */
    public static function activity(string $text, array $card): array
    {
        return [
            'type' => 'message', 'text' => ChatFormatter::teamsEscape(ChatBlocks::clip($text, 500)), 'textFormat' => 'plain',
            'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => $card]],
        ];
    }

    private static function card(array $body, array $actions = []): array
    {
        $c = ['$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'type' => 'AdaptiveCard', 'version' => '1.4', 'body' => $body, 'msteams' => ['width' => 'Full']];
        if ($actions) {
            $c['actions'] = $actions;
        }

        return $c;
    }

    private static function tb(string $t, array $extra = []): array
    {
        return ['type' => 'TextBlock', 'text' => ChatFormatter::teamsEscape(ChatBlocks::clip($t, 1500)), 'wrap' => true] + $extra;
    }

    public static function approval(string $kind, int $id, string $title, string $detail): array
    {
        return self::card(
            [self::tb('Approval needed', ['weight' => 'Bolder', 'size' => 'Medium']), self::tb($title), self::tb($detail, ['isSubtle' => true])],
            [
                ['type' => 'Action.Submit', 'title' => 'Approve', 'style' => 'positive', 'data' => ['rivet' => 'approve', 'kind' => $kind, 'id' => $id]],
                ['type' => 'Action.Submit', 'title' => 'Deny', 'style' => 'destructive', 'data' => ['rivet' => 'reject', 'kind' => $kind, 'id' => $id]],
            ]
        );
    }

    /** @param list<string> $statusNames */
    public static function ticket(int $ticketId, string $description, ?string $url, array $statusNames, string $status, string $priority, bool $controls): array
    {
        $body = [self::tb($description)];
        $actions = [];
        if ($controls) {
            $body[] = ['type' => 'Input.ChoiceSet', 'id' => 'choice_status', 'style' => 'compact', 'value' => $status, 'choices' => array_map(static fn ($n) => ['title' => ChatBlocks::clip($n, 60), 'value' => $n], array_slice($statusNames, 0, 20))];
            $actions[] = ['type' => 'Action.Submit', 'title' => 'Set status', 'data' => ['rivet' => 'status', 'ticket' => $ticketId]];
            $body[] = ['type' => 'Input.ChoiceSet', 'id' => 'choice_priority', 'style' => 'compact', 'value' => $priority, 'choices' => array_map(static fn ($n) => ['title' => $n, 'value' => $n], ChatActions::PRIORITIES)];
            $actions[] = ['type' => 'Action.Submit', 'title' => 'Set priority', 'data' => ['rivet' => 'priority', 'ticket' => $ticketId]];
            $body[] = ['type' => 'Input.Text', 'id' => 'text', 'isMultiline' => true, 'placeholder' => 'Write a reply', 'maxLength' => 3000];
            $actions[] = ['type' => 'Action.Submit', 'title' => 'Reply', 'data' => ['rivet' => 'reply', 'ticket' => $ticketId]];
        }
        if ($url !== null && preg_match('#^https?://[^\s<>"\'|]+$#', $url)) {
            $actions[] = ['type' => 'Action.OpenUrl', 'title' => 'Open ticket', 'url' => $url];
        }

        return self::card($body, $actions);
    }

    /** @param list<array{title:string,url:?string,snippet:string}> $rows */
    public static function results(string $heading, array $rows, bool $withRequest = false): array
    {
        $body = [self::tb($heading, ['weight' => 'Bolder'])];
        $actions = [];
        foreach (array_slice($rows, 0, 5) as $r) {
            $body[] = self::tb($r['title'] . ($r['snippet'] !== '' ? "\n" . $r['snippet'] : ''));
            if (!empty($r['url']) && preg_match('#^https?://[^\s<>"\'|]+$#', $r['url']) && count($actions) < 5) {
                $actions[] = ['type' => 'Action.OpenUrl', 'title' => ChatBlocks::clip($r['title'], 30), 'url' => $r['url']];
            }
            if ($withRequest && !empty($r['request_item']) && (int) $r['request_item'] > 0 && count($actions) < 5) {
                $actions[] = ['type' => 'Action.Submit', 'title' => 'Request ' . ChatBlocks::clip(preg_replace('/ \(say .*$/', '', $r['title']) ?? $r['title'], 22), 'data' => ['rivet' => 'request', 'item' => (int) $r['request_item']]];
            }
        }

        return self::card($body, $actions);
    }
}
