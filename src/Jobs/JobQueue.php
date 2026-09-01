<?php

namespace ITFlow\Jobs;

/**
 * DB-backed job queue (master plan Section 33). Foundation for the async
 * Microsoft/Odoo/RMM sync work in later phases - nothing enqueues real jobs
 * yet, since those integrations aren't connected to anything live. This
 * exists now so later phases have somewhere to enqueue into instead of
 * inventing queueing ad hoc per integration.
 *
 * Redis is available in this app (see includes/redis_functions.php) but the
 * plan is explicit: start with a DB-backed queue, move to Redis/a long-running
 * worker only if scale actually requires it.
 */
class JobQueue
{
    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function enqueue(string $jobType, array $payload = [], ?int $integrationId = null, ?string $resourceType = null, int $priority = 0, int $maxAttempts = 5): int
    {
        $payloadJson = json_encode($payload);
        $stmt = $this->mysqli->prepare(
            "INSERT INTO integration_jobs (integration_id, job_type, resource_type, priority, max_attempts, payload)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ississ', $integrationId, $jobType, $resourceType, $priority, $maxAttempts, $payloadJson);
        $stmt->execute();
        $jobId = $stmt->insert_id;
        $stmt->close();

        return $jobId;
    }

    /**
     * Claims up to $limit pending, due jobs and marks them 'running'.
     * Safe for a single worker process; not yet safe for multiple
     * concurrent workers (no SKIP LOCKED / row locking) - fine at today's
     * scale (0 real jobs), revisit before any integration actually enqueues
     * high volume.
     */
    public function claim(int $limit = 10): array
    {
        $result = $this->mysqli->query(
            "SELECT * FROM integration_jobs
             WHERE status = 'pending' AND available_at <= NOW()
             ORDER BY priority DESC, job_id ASC
             LIMIT " . (int) $limit
        );

        $jobs = [];
        while ($row = $result->fetch_assoc()) {
            $jobs[] = $row;
        }

        foreach ($jobs as $job) {
            $stmt = $this->mysqli->prepare("UPDATE integration_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1 WHERE job_id = ?");
            $stmt->bind_param('i', $job['job_id']);
            $stmt->execute();
            $stmt->close();
        }

        return $jobs;
    }

    public function markCompleted(int $jobId, array $result = []): void
    {
        $resultJson = json_encode($result);
        $stmt = $this->mysqli->prepare("UPDATE integration_jobs SET status = 'completed', completed_at = NOW(), result = ? WHERE job_id = ?");
        $stmt->bind_param('si', $resultJson, $jobId);
        $stmt->execute();
        $stmt->close();
    }

    public function markFailed(int $jobId, string $error, int $attempts, int $maxAttempts): void
    {
        $status = $attempts >= $maxAttempts ? 'dead_letter' : 'pending';
        // Simple backoff: 1min, 5min, 30min, 2h, then dead-letter (matches
        // the webhook-retry schedule in Section 35 - same shape, same reasoning).
        $backoffMinutes = [1, 5, 30, 120][$attempts - 1] ?? 120;
        $stmt = $this->mysqli->prepare(
            "UPDATE integration_jobs
             SET status = ?, error = ?, available_at = DATE_ADD(NOW(), INTERVAL ? MINUTE)
             WHERE job_id = ?"
        );
        $stmt->bind_param('ssii', $status, $error, $backoffMinutes, $jobId);
        $stmt->execute();
        $stmt->close();
    }
}
