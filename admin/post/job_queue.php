<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../../includes/event_bus.php';

if (isset($_POST['run_job_worker']) || isset($_POST['retry_job']) || isset($_POST['purge_completed_jobs'])) {
    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    if (!rivetJobsAvailable($mysqli)) {
        flash_alert('The job queue is not available yet. Run the database update first.', 'error');
        redirect();
    }
    $queue = new \RivetCore\Jobs\JobQueue(rivetCoreDb($mysqli));

    if (isset($_POST['retry_job'])) {
        $id = intval($_POST['job_id'] ?? 0);
        $ok = $queue->retry($id);
        if ($ok) {
            logAction('Jobs', 'Retry', "$session_name retried job $id");
            rivetAudit('job.retried', (int) $session_user_id, 'job', $id, 'update', 'Failed job put back in the queue');
        }
        flash_alert($ok ? "Job $id will be tried again." : 'That job cannot be retried.', $ok ? 'success' : 'error');
        redirect();
    }
    if (isset($_POST['purge_completed_jobs'])) {
        $n = $queue->purgeCompleted(30);
        logAction('Jobs', 'Purge', "$session_name cleared $n completed jobs");
        flash_alert("Removed $n completed job" . ($n === 1 ? '' : 's') . ' older than 30 days.');
        redirect();
    }
    $r = rivetRunJobWorker($mysqli, 50, 20);
    flash_alert("Processed {$r['claimed']} job(s): {$r['completed']} completed, {$r['retrying']} to retry, {$r['dead']} failed for good.");
    redirect();
}
