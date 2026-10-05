<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** ITFlow\Jobs\JobQueue keeps its constructor and methods and now runs on RivetCore. Needs a scratch DB with db.sql. */
final class JobQueueShimTest extends TestCase
{
    private mysqli $m;

    protected function setUp(): void
    {
        $name = getenv('RIVETCORE_TEST_DB_NAME');
        if (!$name) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->m = new mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', getenv('RIVETCORE_TEST_DB_USER') ?: 'root', getenv('RIVETCORE_TEST_DB_PASS') ?: '', $name);
        $this->m->query('DELETE FROM integration_jobs');
    }

    public function testWorkerFailurePathUsesTheCorrectAttemptNumber(): void
    {
        $q = new \ITFlow\Jobs\JobQueue($this->m);
        $id = $q->enqueue('unknown.type', ['x' => 1]);
        $jobs = $q->claim(10);
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        // exactly what cron/integration_worker.php does
        $q->markFailed((int) $job['job_id'], "No handler registered for job_type '{$job['job_type']}'", (int) $job['attempts'], (int) $job['max_attempts']);
        $row = $this->m->query("SELECT status, attempts, TIMESTAMPDIFF(MINUTE, NOW(), available_at) m FROM integration_jobs WHERE job_id = $id")->fetch_assoc();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertEqualsWithDelta(1, (int) $row['m'], 1); // first failure backs off ~1 minute (was the 120-minute fallback)
    }

    public function testCompleteAndEnqueueSignature(): void
    {
        $q = new \ITFlow\Jobs\JobQueue($this->m);
        $id = $q->enqueue('t', [], 3, 'asset', 2, 9);
        $q->claim();
        $q->markCompleted($id, ['done' => 1]);
        $this->assertSame('completed', $this->m->query("SELECT status FROM integration_jobs WHERE job_id = $id")->fetch_row()[0]);
    }
}
