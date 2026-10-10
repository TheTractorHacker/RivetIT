<?php

declare(strict_types=1);

namespace ITFlow\Mail;

/**
 * The checks that run on every inbound message before the ticket/contact matching: duplicate Message-ID, machine
 * generated mail, the per-sender rate cap and Message-ID threading. Returns what to do; the caller (cron/ticket_email_parser.php)
 * performs it. Nothing here writes to tickets.
 *
 * action:
 *   duplicate    this Message-ID was already imported - skip, mark handled
 *   dsn          bounce - notify an admin, never a ticket or reply
 *   suppressed   auto-reply / out-of-office / list mail / our own mail looping back - log only
 *   rate_limited sender exceeded the hourly cap - quarantine for review
 *   thread       reply to ticket_id found through In-Reply-To/References
 *   continue     fall through to subject-token / contact / domain matching
 */
final class InboundRouter
{
    /**
     * @param array $n normalised message
     * @param array{rate_cap?: int} $opts
     * @return array{action: string, detail?: string, rule?: string, ticket_id?: int, via?: string, message_id?: string, count?: int}
     */
    public static function preRoute(IntakeStore $store, array $n, array $opts = []): array
    {
        $messageId = (string) ($n['message_id'] ?? '');
        if ($messageId !== '' && $store->isImported($messageId)) {
            return ['action' => 'duplicate', 'detail' => 'Message-ID already imported: ' . $messageId];
        }

        $partTypes = array_map(static fn ($p) => (string) ($p['content_type'] ?? $p['mime'] ?? ''), $n['attachments'] ?? []);
        $auto = AutoReplyDetector::classify($n['headers'] ?? [], (string) ($n['subject'] ?? ''), $partTypes);
        if ($auto !== null) {
            return ['action' => $auto['kind'] === 'dsn' ? 'dsn' : 'suppressed', 'rule' => $auto['rule'], 'detail' => $auto['detail']];
        }

        $cap = (int) ($opts['rate_cap'] ?? 0);
        if ($cap > 0) {
            $count = $store->senderMessageCount((string) $n['from_email'], 3600);
            if ($count >= $cap) {
                return ['action' => 'rate_limited', 'count' => $count, 'detail' => "Sender sent $count messages in the last hour (cap $cap)"];
            }
        }

        $candidates = MessageId::threadCandidates($n['in_reply_to'] ?? null, $n['references'] ?? null);
        $hit = $store->resolveThread($candidates);
        if ($hit !== null) {
            return ['action' => 'thread', 'ticket_id' => $hit['ticket_id'], 'via' => $hit['via'], 'message_id' => $hit['message_id'], 'detail' => 'Threaded by ' . $hit['via'] . ' Message-ID ' . $hit['message_id']];
        }

        return ['action' => 'continue'];
    }
}
