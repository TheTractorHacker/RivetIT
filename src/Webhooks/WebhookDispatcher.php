<?php

namespace ITFlow\Webhooks;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;
use ITFlow\Core\Adapter\Webhooks\WebhooksTableSubscriptions;
use RivetCore\Support\SystemClock;

/**
 * Synchronous, in-request webhook delivery (master plan Section 35). Distinct from the existing
 * queueWebhookEvent()/webhook_queue/cron.php path (async, with retry backoff): this fires immediately and logs
 * one row per attempt to webhook_deliveries. Both read the same `webhooks` table and never throw.
 *
 * Compatibility shim over RivetCore\Webhooks\WebhookDispatcher. X-ITFlow-* are the header names existing
 * receivers verify: keep them. X-RivetIT-* carry the same values so new receivers can use the product's name.
 *
 * @deprecated since 26.10.26 use \RivetCore\Webhooks\WebhookDispatcher (the shim wires the mysqli adapter, WebhooksTableSubscriptions, SystemClock and the X-ITFlow/X-RivetIT header prefixes). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class WebhookDispatcher
{
    private \RivetCore\Webhooks\WebhookDispatcher $core;

    public function __construct(\mysqli $mysqli)
    {
        $database = new MysqliDatabaseAdapter($mysqli);
        $this->core = new \RivetCore\Webhooks\WebhookDispatcher(
            $database,
            new WebhooksTableSubscriptions($database),
            new SystemClock(),
            ['X-ITFlow', 'X-RivetIT']
        );
    }

    /**
     * @return array<int,array{webhook_id:int,http_status:?int,duration_ms:int,error:?string}>
     */
    public function deliver(string $eventType, array $payload): array
    {
        return $this->core->deliver($eventType, $payload);
    }
}
