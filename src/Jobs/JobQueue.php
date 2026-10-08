<?php

namespace ITFlow\Jobs;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Compatibility shim: the queue now lives in RivetCore (RivetCore\Jobs\JobQueue) on the same integration_jobs
 * table. Same constructor and methods. One behavior change, a fix: claim() now returns the claimed state (status
 * 'running', attempts already incremented) and is safe for concurrent workers, so the worker's
 * markFailed(.., $job['attempts'], ..) gets the correct attempt number and backoff step.
 *
 * @deprecated since 26.10.26 use \RivetCore\Jobs\JobQueue (construct it with a MysqliDatabaseAdapter). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class JobQueue
{
    private \RivetCore\Jobs\JobQueue $core;

    public function __construct(\mysqli $mysqli)
    {
        $this->core = new \RivetCore\Jobs\JobQueue(new MysqliDatabaseAdapter($mysqli));
    }

    public function enqueue(string $jobType, array $payload = [], ?int $integrationId = null, ?string $resourceType = null, int $priority = 0, int $maxAttempts = 5): int
    {
        return $this->core->enqueue($jobType, $payload, $integrationId, $resourceType, $priority, $maxAttempts);
    }

    public function claim(int $limit = 10): array
    {
        return $this->core->claim($limit);
    }

    public function markCompleted(int $jobId, array $result = []): void
    {
        $this->core->markCompleted($jobId, $result);
    }

    public function markFailed(int $jobId, string $error, int $attempts, int $maxAttempts): void
    {
        $this->core->markFailed($jobId, $error, $attempts, $maxAttempts);
    }
}
