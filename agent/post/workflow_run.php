<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

/**
 * Loads the run + its contact's client_id and enforces access, common to
 * every action below. Returns the run row (client_id already checked).
 */
function loadWorkflowRunOrDie($mysqli, int $run_id): array {
    $run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT wr.*, c.contact_client_id, c.contact_name
        FROM workflow_runs wr
        LEFT JOIN contacts c ON c.contact_id = wr.contact_id
        WHERE wr.run_id = $run_id"));
    if (!$run) {
        flash_alert('Workflow run not found', 'error');
        redirect('workflow_run.php?run_id=' . $run_id);
    }
    enforceClientAccess(intval($run['contact_client_id']));
    return $run;
}

if (isset($_POST['complete_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);

    (new \ITFlow\Workflow\WorkflowService($mysqli))->completeTask(intval($_POST['run_task_id']), $session_user_id);

    logAction("Contact", "Edit", "$session_name completed a workflow task for {$run['contact_name']}", intval($run['contact_client_id']), intval($run['contact_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['skip_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $reason = sanitizeInput($_POST['skip_reason'] ?? '');

    (new \ITFlow\Workflow\WorkflowService($mysqli))->skipTask(intval($_POST['run_task_id']), $reason, $session_user_id);

    logAction("Contact", "Edit", "$session_name skipped a workflow task for {$run['contact_name']}: $reason", intval($run['contact_client_id']), intval($run['contact_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['reopen_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    loadWorkflowRunOrDie($mysqli, $run_id);

    (new \ITFlow\Workflow\WorkflowService($mysqli))->reopenTask(intval($_POST['run_task_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['cancel_workflow_run'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);

    (new \ITFlow\Workflow\WorkflowService($mysqli))->cancelRun($run_id);

    \ITFlow\Audit\AuditService::record('workflow.cancelled', $session_user_id, 'contact', intval($run['contact_id']), 'cancelled', "$session_name cancelled a workflow for {$run['contact_name']}");
    logAction("Contact", "Edit", "$session_name cancelled a workflow for {$run['contact_name']}", intval($run['contact_client_id']), intval($run['contact_id']));

    flash_alert('Workflow cancelled');
    redirect("workflow_run.php?run_id=$run_id");
}
