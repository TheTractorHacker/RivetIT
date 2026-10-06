<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Webhooks;

use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * One subscription handed straight to RivetCore's dispatcher, for "Send test" on a webhook that is not saved yet (or whose form
 * has unsaved edits). The dispatcher, its URL policy, signing and logging are the real ones; only the lookup is replaced.
 */
final class FixedSubscription implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface
{
    public function __construct(private WebhookSubscription $subscription)
    {
    }

    public function find(int $webhookId): ?WebhookSubscription
    {
        return $this->subscription;
    }

    public function forEvent(string $eventType): array
    {
        return [];
    }
}
