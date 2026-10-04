<?php
// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

// claim() is not safe for concurrent workers, so never run two at once.
\ITFlow\Redis\CronGuard::acquireOrExit('integration_worker', 300);

use ITFlow\Jobs\JobQueue;

// No integration currently enqueues jobs (Microsoft/Odoo are scaffolding-only,
// not connected - see PROGRESS.md). This worker exists as infrastructure so
// those phases have a queue to enqueue into later; it's safe to run on a
// schedule now since claim() on an empty table is a cheap no-op.
$queue = new JobQueue($mysqli);
$jobs = $queue->claim(10);

foreach ($jobs as $job) {
    // No job types are registered yet - anything claimed here is unexpected.
    // Fail it loudly rather than silently dropping it.
    $queue->markFailed(
        (int) $job['job_id'],
        "No handler registered for job_type '{$job['job_type']}'",
        (int) $job['attempts'],
        (int) $job['max_attempts']
    );
}

echo count($jobs) . " job(s) claimed.\n";
