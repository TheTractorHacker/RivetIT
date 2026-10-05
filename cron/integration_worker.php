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

// Runs every queued job: webhook deliveries (signed, retried with backoff), event-rule actions, and anything else registered in
// includes/event_bus.php. claim() is safe for concurrent workers, but the guard above keeps a slow run from stacking up.
require_once "../includes/event_bus.php";
$result = rivetRunJobWorker($mysqli, 50, 50);
echo $result['claimed'] . " job(s) claimed: " . $result['completed'] . " completed, " . $result['retrying'] . " to retry, " . $result['dead'] . " failed for good.\n";
