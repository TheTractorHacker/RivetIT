<?php
require_once __DIR__ . '/../includes/cron_jobs.php';

$root = realpath(__DIR__ . '/..');
$dir = sys_get_temp_dir() . '/rivetit-cron-test-' . bin2hex(random_bytes(5));
mkdir($dir);
try {
    file_put_contents($dir . '/rivetit-example',
        "# this app\n*/5 * * * * www-data /usr/bin/php $root/cron/cron.php >> /var/log/rivetit.log 2>&1\n" .
        "* * * * * www-data /usr/bin/php $root/cron/mail_queue.php\n");
    file_put_contents($dir . '/itflow-other',
        "* * * * * www-data /usr/bin/php /var/www/other/cron/cron.php\n");
    file_put_contents($dir . '/rivetit-example.bak-1',
        "* * * * * www-data /usr/bin/php $root/cron/cron.php\n");
    file_put_contents($dir . '/itflow-example',
        "0 * * * * www-data /usr/bin/php $root/cron/report_scheduler.php\n");

    $jobs = rivetit_cron_jobs_for_app($root, $dir);
    if (count($jobs) !== 3 ||
        $jobs[0]['script'] !== $root . '/cron/report_scheduler.php' ||
        $jobs[1]['script'] !== $root . '/cron/cron.php' ||
        $jobs[2]['script'] !== $root . '/cron/mail_queue.php') {
        throw new RuntimeException('Cron jobs were not isolated to this installation.');
    }
    echo "Cron job discovery checks passed\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
