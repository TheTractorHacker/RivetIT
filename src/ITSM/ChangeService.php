<?php

namespace ITFlow\ITSM;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Change management (master plan Section 29), kept deliberately lightweight: no separate approver/CAB model - a
 * change just carries its own status, risk and plans, and a problem can point at the change meant to fix it.
 *
 * Compatibility shim over RivetCore\ITSM\ChangeService; the status machine is public there (ChangeService::TRANSITIONS).
 *
 * @deprecated since 26.10.26 use \RivetCore\ITSM\ChangeService (construct it with a \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class ChangeService
{
    private \RivetCore\ITSM\ChangeService $core;

    public function __construct(\mysqli $mysqli)
    {
        $this->core = new \RivetCore\ITSM\ChangeService(new MysqliDatabaseAdapter($mysqli));
    }

    public function create(
        string $title,
        ?string $reason,
        ?string $impact,
        string $risk,
        ?string $implementationPlan,
        ?string $rollbackPlan,
        ?string $scheduledAt,
        ?int $createdBy
    ): int {
        return $this->core->create($title, $reason, $impact, $risk, $implementationPlan, $rollbackPlan, $scheduledAt, $createdBy);
    }

    public function setStatus(int $changeId, string $status, ?string $scheduledAt = null): void
    {
        $this->core->setStatus($changeId, $status, $scheduledAt);
    }

    public function reschedule(int $changeId, string $scheduledAt): void
    {
        $this->core->reschedule($changeId, $scheduledAt);
    }
}
