<?php

defined('FROM_POST_HANDLER') || die('Direct file access is not allowed');

require_once __DIR__ . '/../../includes/cron_jobs.php';

use ITFlow\Audit\AuditService;
use ITFlow\Cron\JobCatalog;
use ITFlow\Cron\JobRunner;
use ITFlow\Redis\RateLimit;

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

if (isset($_POST['run_cron_job'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('user_type', 1);

    $app_root = realpath(__DIR__ . '/../..');
    $runner = new JobRunner($app_root);
    $fail = static function (string $message) {
        flash_alert($message, 'danger');
        redirect('/admin/cron.php');
    };

    if (isset($_POST['cron_script'])) {
        // A catalog job with no schedule on this server: runs with no arguments.
        $file = basename((string) $_POST['cron_script']);
        $info = JobCatalog::describe($file);
        if (!$info['run_now'] || JobCatalog::needsArguments($file) || !is_file($app_root . '/' . JobCatalog::dir($file) . '/' . $file)) {
            $fail('That job cannot be started from here.');
        }
        $key = 'u-' . basename($file, '.php');
        $script = $app_root . '/' . JobCatalog::dir($file) . '/' . $file;
        $args = [];
        $php = '/usr/bin/php';
    } else {
        // A scheduled job: must match one exact cron.d line, the same way schedule editing does.
        $jobs = rivetit_cron_jobs_for_app($app_root);
        $matching = array_values(array_filter($jobs, static fn($job) =>
            basename($job['file']) === (string) ($_POST['cron_file'] ?? '') &&
            (string) $job['line'] === (string) ($_POST['cron_line'] ?? '') &&
            $job['command_hash'] === (string) ($_POST['cron_hash'] ?? '') &&
            $job['user'] === 'www-data'
        ));
        if (count($matching) !== 1) $fail('That job is no longer available. Reload the page.');
        $job = $matching[0];
        $file = basename($job['script']);
        $info = JobCatalog::describe($file);
        $args = JobRunner::argumentsFromCommand($job['command'], $job['script']);
        if (!$info['run_now'] || $args === null) $fail('That job cannot be started from here.');
        $key = substr($job['command_hash'], 0, 16);
        $script = $job['script'];
        $php = JobRunner::phpBinaryFromCommand($job['command']);
    }

    if (!RateLimit::hit("cron-run:$key", 3, 60)['allowed']) $fail('Slow down: that job was started several times in the last minute.');

    $result = $runner->start($key, $script, $args, $php);
    if ($result['ok']) {
        logAction('Cron', 'Run', "$session_name started $file from the Cron Manager");
        AuditService::record('cron.job_started', (int) $session_user_id, 'cron_job', $file, 'run', "Started $file manually", ['args' => $args]);
        flash_alert($info['label'] . ' started in the background. Its output appears in the table.');
    } else {
        flash_alert($result['message'], 'danger');
    }
    redirect('/admin/cron.php');
}
