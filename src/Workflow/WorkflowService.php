<?php

namespace ITFlow\Workflow;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Manual-first employee lifecycle workflow engine (master plan Section 16, scoped to Section 53's first release):
 * a checklist tied to a person. Template tasks are snapshotted onto the run when it starts.
 *
 * Compatibility shim over RivetCore\Workflow\WorkflowService. One improvement: startRun() is now atomic.
 */
class WorkflowService
{
    private \RivetCore\Workflow\WorkflowService $core;

    public function __construct(\mysqli $mysqli)
    {
        $this->core = new \RivetCore\Workflow\WorkflowService(new MysqliDatabaseAdapter($mysqli));
    }

    public function startRun(int $templateId, int $contactId, ?int $startedByUserId): int
    {
        return $this->core->startRun($templateId, $contactId, $startedByUserId);
    }

    public function completeTask(int $runTaskId, ?int $userId): void
    {
        $this->core->completeTask($runTaskId, $userId);
    }

    public function reopenTask(int $runTaskId): void
    {
        $this->core->reopenTask($runTaskId);
    }

    public function skipTask(int $runTaskId, string $reason, ?int $userId): void
    {
        $this->core->skipTask($runTaskId, $reason, $userId);
    }

    public function cancelRun(int $runId): void
    {
        $this->core->cancelRun($runId);
    }
}
