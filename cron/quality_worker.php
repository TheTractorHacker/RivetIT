<?php

/*
 * RivetIT - Quality module worker (issue #52).
 *
 *   php cron/quality_worker.php [--force] [--full] [--max-pages=N] [--help]
 *
 * One pass: ask the QMS how it is (GET /integrations/health), queue signed events for what changed in the LMS and the directory since the last pass (assignments,
 * completions, people, departments - only for the departments enabled for Quality), reconcile a trailing window once a day, deliver due events (retry with backoff,
 * dead letter), and prune old rows. Its OWN script, deliberately NOT cron/cron.php: no mail code runs here, and nothing here writes to Odoo, to the QMS database or to
 * any LMS record. It only reads the LMS/directory and writes its own quality_* tables.
 *
 * Gates, in order (each exits 0 silently, so the schedule can stay installed): CLI only; the Quality tables are not there yet; the module switch is off (settings
 * and history are kept, nothing runs); the connection is not set up; the minimum gap between passes ("Sync every N minutes") has not passed. One runner at a time (flock).
 * Events are queued and sent only while "Send events to the QMS" is on; the health check runs whenever the module is on and connected.
 *
 * Exit codes: 0 ok or silent; 1 bootstrap or lock error; 2 the pass reported an error.
 *
 * Schedule (deploy/install.sh writes it into /etc/cron.d, running as www-data):
 *   *\/5 * * * * www-data /usr/bin/php /var/www/<site>/cron/quality_worker.php >> /var/log/rivetit-training-<site>.log 2>&1
 */

chdir(__DIR__);

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

$qw_opts = getopt('', ['force', 'full', 'max-pages:', 'help']);
if (isset($qw_opts['help'])) {
    echo "usage: php quality_worker.php [--force] [--full] [--max-pages=N]\n";
    exit(0);
}
$qw_pages = 50;
if (isset($qw_opts['max-pages'])) {
    if (!is_string($qw_opts['max-pages']) || preg_match('/^[1-9]\d{0,3}$/D', $qw_opts['max-pages']) !== 1) {
        fwrite(STDERR, "--max-pages must be 1 to 9999\n");
        exit(1);
    }
    $qw_pages = (int) $qw_opts['max-pages'];
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../vendor/autoload.php";

use ITFlow\Quality\QualityConfig;
use ITFlow\Quality\SyncWorker;

function qw_say(string $line): void
{
    echo gmdate('Y-m-d\TH:i:s\Z') . ' quality_worker: ' . str_replace(["\r", "\n"], ' ', $line) . "\n";
}

// Business timestamps and "now" use the company time zone, like the API the same code serves.
mysqli_query($mysqli, "SET time_zone = '" . (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P') . "'");

if (!QualityConfig::ready($mysqli) || !QualityConfig::moduleOn($mysqli)) {
    exit(0);   // not migrated, or switched off: nothing runs and nothing is said
}

$qw_lock_path = sys_get_temp_dir() . '/itflow_quality_worker_' . md5(__DIR__) . '.lock';
$qw_lock = @fopen($qw_lock_path, 'c');
if ($qw_lock === false) {
    qw_say("ERROR cannot open $qw_lock_path (created by another OS user? run as www-data)");
    exit(1);
}
if (!flock($qw_lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // another pass is running
}

try {
    $qw = SyncWorker::run($mysqli, ['force' => isset($qw_opts['force']), 'full' => isset($qw_opts['full']), 'max_pages' => $qw_pages]);
} catch (\Throwable $e) {
    qw_say('ERROR ' . get_class($e) . ': ' . $e->getMessage());
    logApp('Quality', 'error', 'Quality worker failed: ' . get_class($e) . ': ' . $e->getMessage());
    exit(2);
}
if (in_array($qw['status'], ['module_off', 'not_configured', 'not_due'], true)) {
    exit(0);
}
qw_say(sprintf('%s queued=%d sent=%d retry=%d dead=%d health=%s%s', $qw['status'], $qw['produced'], $qw['sent'], $qw['retry'], $qw['dead'],
    is_array($qw['health']) ? ($qw['health']['ok'] ? 'ok' : 'FAILED') : 'n/a', $qw['reconciled'] ? ' reconciled' : ''));
exit($qw['status'] === 'error' ? 2 : 0);
