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

// One worker by default (the guard keeps a slow run from stacking up). RivetCore 1.0.0-rc.11 made claim() safe for any number of concurrent workers (SKIP LOCKED on MariaDB 10.6+ and
// MySQL 8.0.1+, a conditional-UPDATE lease elsewhere; docs/ENDPOINT_AGENT.md "Several queue workers"), so a busy install may run N copies, each with its own number:
//   * * * * * php cron/integration_worker.php --worker=2      (worker 1 is the one without the option)
// The recommended N is shown in the Performance and capacity panel of Endpoints > Agent Fleet.
$worker_no = 1;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--worker=([0-9]{1,2})$/', (string) $arg, $wm) === 1 && (int) $wm[1] >= 1 && (int) $wm[1] <= 32) {
        $worker_no = (int) $wm[1];
    }
}
\ITFlow\Redis\CronGuard::acquireOrExit($worker_no === 1 ? 'integration_worker' : 'integration_worker_' . $worker_no, 300);

// Runs every queued job: webhook deliveries (signed, retried with backoff), event-rule actions, and anything else registered in
// includes/event_bus.php.
require_once "../includes/event_bus.php";
$result = rivetRunJobWorker($mysqli, 50, 50);
echo $result['claimed'] . " job(s) claimed: " . $result['completed'] . " completed, " . $result['retrying'] . " to retry, " . $result['dead'] . " failed for good.\n";
