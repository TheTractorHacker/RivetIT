<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Keeps the activity-log rows existing installs already see: logAction('Endpoint Agent', ...) writes `logs` and mirrors the entry into
 * the structured audit trail (the type is on logAction's audited list).
 */
final class EndpointAudit implements RmmAuditInterface
{
    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        if (function_exists('logAction')) {
            logAction('Endpoint Agent', $action, $description, $clientId, $entityId);
        }
    }
}
