<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

require_once __DIR__ . '/../../includes/cron_jobs.php';

if (isset($_POST['save_cron_schedule'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $app_root = realpath(__DIR__ . '/../..');
    $instance = rivetit_cron_manager_instance($app_root);
    $file = (string) ($_POST['cron_file'] ?? '');
    $line = (string) ($_POST['cron_line'] ?? '');
    $hash = (string) ($_POST['cron_hash'] ?? '');
    $schedule = trim((string) ($_POST['cron_schedule'] ?? ''));
    $jobs = rivetit_cron_jobs_for_app($app_root);
    $matching = array_values(array_filter($jobs, static fn($job) =>
        basename($job['file']) === $file &&
        (string) $job['line'] === $line &&
        $job['command_hash'] === $hash &&
        $job['user'] === 'www-data'
    ));
    if (!$instance || count($matching) !== 1 || !is_executable('/usr/local/sbin/rivetit-cron-schedule')) {
        flash_alert('This cron job is not available for editing. Reload the page or ask the server administrator to install the Cron Manager helper.', 'danger');
        redirect('/admin/cron.php');
    }

    // The helper independently checks the root-owned registration, cron file,
    // exact command hash, and timing fields before touching a schedule.
    $args = ['--set', $instance, $file, $line, $hash, $schedule];
    $command = '/usr/bin/sudo -n /usr/local/sbin/rivetit-cron-schedule';
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    exec($command . ' 2>&1', $output, $status);
    if ($status === 0) {
        logAction('Cron', 'Edit', "$session_name set the schedule for $file:$line to $schedule");
        flash_alert('Cron schedule saved for ' . basename($matching[0]['script']) . '.');
    } else {
        error_log('Cron Manager schedule update failed: ' . implode(' ', $output));
        flash_alert('Could not save the cron schedule. Check the five timing fields or ask the server administrator to check the Cron Manager helper.', 'danger');
    }
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
    if (!$config_enable_cron) {
        flash_alert('Enable Cron Job in Settings before running the main cron job.', 'danger');
        redirect('/admin/cron.php');
    }

    exec('/usr/bin/php ' . escapeshellarg($cron_script) . ' > /dev/null 2>&1 &');
    flash_alert('Cron started in the background. Check App Logs for results.');
    redirect('/admin/cron.php');
}
