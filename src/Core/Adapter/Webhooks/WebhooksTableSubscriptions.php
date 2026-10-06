<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Webhooks;

use ITFlow\Webhooks\DestinationConfig;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * RivetIT's webhook endpoints: rows of the `webhooks` table (events stored as a comma list of ids and patterns, secrets encrypted
 * with encryptSetting). The same table also feeds the async queueWebhookEvent()/cron path; RivetCore never
 * touches it.
 */
final class WebhooksTableSubscriptions implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        $r = $this->database->fetchOne('SELECT * FROM webhooks WHERE webhook_id = ? AND webhook_enabled = 1', [$webhookId]);

        // Slack / Teams destinations are delivered by ITFlow\Webhooks\ChatDelivery, never by the generic signed POST.
        // Preset rows (webhook_destination set) carry their format, method and auth headers as options; legacy rows have none.
        return $r === null || ($r['webhook_type'] ?? 'generic') !== 'generic' ? null : DestinationConfig::subscription($r);
    }

    public function forEvent(string $eventType): array
    {
        // Stored events are ids and/or patterns (ticket.*, *): the SQL narrows by exact id or any pattern, PHP does the matching.
        $rows = $this->database->fetchAll(
            "SELECT *
             FROM webhooks
             WHERE webhook_enabled = 1
               AND (FIND_IN_SET(?, REPLACE(webhook_events, ', ', ',')) OR webhook_events LIKE '%*%')",
            [$eventType]
        );

        $rows = array_values(array_filter($rows, static fn (array $r) => ($r['webhook_type'] ?? 'generic') === 'generic' && DestinationConfig::eventMatches((string) ($r['webhook_events'] ?? ''), $eventType)));

        return array_map(static fn (array $r) => DestinationConfig::subscription($r), $rows);
    }
}
