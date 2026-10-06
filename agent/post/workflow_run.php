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

/**
 * The run_task_id comes from the form, so it must belong to the run whose department access was just checked (otherwise a
 * task id from another department's run could be acted on). Returns the service.
 */
function workflowServiceForTask($mysqli, int $run_id, int $run_task_id): \ITFlow\Workflow\WorkflowService {
    $service = new \ITFlow\Workflow\WorkflowService($mysqli);
    if (!$service->taskInRun($run_task_id, $run_id)) {
        flash_alert('That task is not part of this workflow', 'error');
        redirect('workflow_run.php?run_id=' . $run_id);
    }
    return $service;
}

if (isset($_POST['complete_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);

    $service = workflowServiceForTask($mysqli, $run_id, intval($_POST['run_task_id']));
    try {
        $service->completeTask(intval($_POST['run_task_id']), $session_user_id);
    } catch (\DomainException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect("workflow_run.php?run_id=$run_id");
    }

    logAction("Contact", "Edit", "$session_name completed a workflow task for {$run['contact_name']}", intval($run['contact_client_id']), intval($run['contact_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['skip_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $reason = sanitizeInput($_POST['skip_reason'] ?? '');

    $service = workflowServiceForTask($mysqli, $run_id, intval($_POST['run_task_id']));
    try {
        $service->skipTask(intval($_POST['run_task_id']), $reason, $session_user_id, (bool) $session_is_admin);
    } catch (\DomainException $e) {
        flash_alert($e->getMessage(), 'error');
        redirect("workflow_run.php?run_id=$run_id");
    }

    logAction("Contact", "Edit", "$session_name skipped a workflow task for {$run['contact_name']}: $reason", intval($run['contact_client_id']), intval($run['contact_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['reopen_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    loadWorkflowRunOrDie($mysqli, $run_id);

    workflowServiceForTask($mysqli, $run_id, intval($_POST['run_task_id']))->reopenTask(intval($_POST['run_task_id']));

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['approve_workflow_run_task']) || isset($_POST['reject_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    // Deciding is not "editing Departments data": the task's approver (or an administrator) may decide, which the service checks.
    // Reading the run still needs Departments access to the person's department.
    enforceUserPermission('module_client');

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $run_task_id = intval($_POST['run_task_id']);
    $service = workflowServiceForTask($mysqli, $run_id, $run_task_id);
    $comment = trim((string) ($_POST['approval_comment'] ?? ''));

    try {
        if (isset($_POST['approve_workflow_run_task'])) {
            $service->approveTask($run_task_id, $session_user_id, $comment);
            flash_alert('Approved');
        } else {
            $service->rejectTask($run_task_id, $session_user_id, $comment);
            flash_alert('Rejected. The workflow is paused.', 'error');
        }
        logAction("Contact", "Edit", "$session_name " . (isset($_POST['approve_workflow_run_task']) ? 'approved' : 'rejected') . " a workflow approval for {$run['contact_name']}", intval($run['contact_client_id']), intval($run['contact_id']));
    } catch (\DomainException $e) {
        flash_alert($e->getMessage(), 'error');
    }

    redirect("workflow_run.php?run_id=$run_id");
}

if (isset($_POST['retry_workflow_run_task'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $run_id = intval($_POST['run_id']);
    $run = loadWorkflowRunOrDie($mysqli, $run_id);
    $run_task_id = intval($_POST['run_task_id']);
    $service = workflowServiceForTask($mysqli, $run_id, $run_task_id);

    try {
        $ok = $service->retryAction($run_task_id, $session_user_id);
        logAction("Contact", "Edit", "$session_name re-ran a workflow action for {$run['contact_name']}", intval($run['contact_client_id']), intval($run['contact_id']));
        flash_alert($ok ? 'The action ran' : 'The action failed again; see the log below', $ok ? 'success' : 'error');
    } catch (\DomainException $e) {
        flash_alert($e->getMessage(), 'error');
    }

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
