<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

use PHPUnit\Framework\TestCase;

if (!KitSupport::has(\RivetCore\Testing\WebhookSubscriptionsConformanceTestCase::class)) {
    /** Placeholder until the pinned RivetCore carries the conformance kit. */
    final class WebhookSubscriptionsConformanceTest extends TestCase
    {
        public function testKitIsAvailable(): void
        {
            $this->markTestSkipped(KitSupport::MISSING);
        }
    }

    return;
}

use RivetCore\Testing\WebhookSubscriptionsConformanceTestCase;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** The `webhooks` table adapter: ids, patterns ("*", "ticket.*"), decrypted secrets, disabled/deleted rows. */
final class WebhookSubscriptionsConformanceTest extends WebhookSubscriptionsConformanceTestCase
{
    protected function subscriptions(): WebhookSubscriptionsInterface
    {
        return new \ITFlow\Core\Adapter\Webhooks\WebhooksTableSubscriptions(KitSupport::db());
    }

    protected function storeSubscription(string $url, string $secret, array $events, bool $enabled): int
    {
        // Seeded the way Administration > Webhooks stores it: URL and secret wrapped by encryptSetting() (a stand-in here), events as a comma list.
        return (int) KitSupport::db()->execute(
            'INSERT INTO webhooks (webhook_name, webhook_url, webhook_secret, webhook_events, webhook_enabled) VALUES (?, ?, ?, ?, ?)',
            ['conformance', encryptSetting($url), encryptSetting($secret), implode(', ', $events), $enabled ? 1 : 0]
        )->insertId;
    }

    protected function deleteSubscription(int $webhookId): void
    {
        KitSupport::db()->execute('DELETE FROM webhooks WHERE webhook_id = ?', [$webhookId]);
    }

    protected function supportsEventPatterns(): bool
    {
        return true;
    }
}
