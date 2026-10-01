<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

require_once __DIR__ . '/../../includes/cron_jobs.php';

if (isset($_POST['save_cron_schedule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);
    // Cron files are root-owned. Do not let a web request rewrite one (the
    // previous handler could change another installation's cron file).
    flash_alert('Edit this installation’s cron schedule in its root-owned file under /etc/cron.d/.', 'danger');
    redirect('/admin/cron.php');
}

if (isset($_POST['run_cron_now'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $app_root = realpath(__DIR__ . '/../..');
    $cron_script = $app_root . '/cron/cron.php';
    $jobs = rivetit_cron_jobs_for_app($app_root);
    $main_jobs = array_values(array_filter($jobs, fn($job) => $job['script'] === $cron_script));
    if (count($main_jobs) !== 1) {
        flash_alert('Run Now is unavailable until exactly one main cron job is installed for this instance.', 'danger');
        redirect('/admin/cron.php');
    }

    exec('/usr/bin/php ' . escapeshellarg($cron_script) . ' > /dev/null 2>&1 &');
    flash_alert('Cron started in the background. Check App Logs for results.');
    redirect('/admin/cron.php');
}
