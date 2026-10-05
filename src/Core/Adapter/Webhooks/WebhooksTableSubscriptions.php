<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Webhooks;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * RivetIT's webhook endpoints: rows of the `webhooks` table (events stored as a comma list, secrets encrypted
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
        $r = $this->database->fetchOne('SELECT webhook_id, webhook_url, webhook_secret FROM webhooks WHERE webhook_id = ? AND webhook_enabled = 1', [$webhookId]);

        return $r === null ? null : new WebhookSubscription((int) $r['webhook_id'], (string) $r['webhook_url'], decryptSetting((string) $r['webhook_secret']));
    }

    public function forEvent(string $eventType): array
    {
        $rows = $this->database->fetchAll(
            "SELECT webhook_id, webhook_url, webhook_secret
             FROM webhooks
             WHERE webhook_enabled = 1
               AND FIND_IN_SET(?, REPLACE(webhook_events, ', ', ','))",
            [$eventType]
        );

        return array_map(
            static fn (array $r) => new WebhookSubscription((int) $r['webhook_id'], (string) $r['webhook_url'], decryptSetting((string) $r['webhook_secret'])),
            $rows
        );
    }
}
