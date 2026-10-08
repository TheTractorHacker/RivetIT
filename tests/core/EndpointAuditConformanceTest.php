<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Testing\RmmAuditConformanceTestCase;

/** EndpointAudit through logAction('Endpoint Agent', ...) into `logs` (a stand-in of logAction writes the same row). */
final class EndpointAuditConformanceTest extends RmmAuditConformanceTestCase
{
    protected function audit(): RmmAuditInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointAudit();
    }

    protected function recorded(int $entityId): ?array
    {
        return array_map(static fn (array $r): array => ['action' => (string) $r['log_action'], 'description' => (string) $r['log_description'], 'client_id' => (int) $r['log_client_id']],
            EndpointKit::db()->fetchAll("SELECT * FROM logs WHERE log_type = 'Endpoint Agent' AND log_entity_id = ? ORDER BY log_id", [$entityId]));
    }
}
