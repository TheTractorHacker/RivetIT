<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Rmm\Contracts\RmmAuditInterface;

/**
 * Keeps the activity-log rows existing installs already see: logAction('Endpoint Agent', ...) writes `logs` and mirrors the entry into
 * the structured audit trail (the types are on logAction's audited list).
 */
final class EndpointAudit implements RmmAuditInterface
{
    public function record(string $action, string $description, int $clientId, int $entityId): void
    {
        if (!function_exists('logAction')) {
            return;
        }
        // The module records settings changes as "Settings"; RivetIT has always logged them as type Settings, action Edit.
        if ($action === 'Settings') {
            logAction('Settings', 'Edit', $description, $clientId, $entityId);

            return;
        }
        logAction('Endpoint Agent', $action, $description, $clientId, $entityId);
    }
}
