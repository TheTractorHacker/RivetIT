<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../includes/workflow_task_fields.php';

if (isset($_POST['add_employee_workflow_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);

    try {
        (new \ITFlow\Workflow\TemplateTaskService($mysqli))->add($workflow_template_id, workflowTaskInputFromPost($_POST));
        flash_alert("Task added");
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
    }
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['edit_employee_workflow_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $template_task_id = intval($_POST['template_task_id']);

    try {
        (new \ITFlow\Workflow\TemplateTaskService($mysqli))->update($workflow_template_id, $template_task_id, workflowTaskInputFromPost($_POST));
        logAction("Settings", "Edit", "$session_name edited a task of employee workflow template $workflow_template_id");
        flash_alert("Task updated");
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
    }
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['delete_employee_workflow_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $template_task_id = intval($_POST['template_task_id']);

    (new \ITFlow\Workflow\TemplateTaskService($mysqli))->delete($workflow_template_id, $template_task_id);

    flash_alert("Task removed");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['move_employee_workflow_template_task_up']) || isset($_POST['move_employee_workflow_template_task_down'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $template_task_id = intval($_POST['template_task_id']);
    $direction = isset($_POST['move_employee_workflow_template_task_up']) ? 'up' : 'down';

    $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_template_tasks WHERE template_task_id = $template_task_id"));

    if ($current) {
        $comparison = $direction === 'up' ? '<' : '>';
        $order = $direction === 'up' ? 'DESC' : 'ASC';
        $neighbor = mysqli_fetch_assoc(mysqli_query($mysqli, "
            SELECT * FROM workflow_template_tasks
            WHERE workflow_template_id = $workflow_template_id AND sort_order $comparison {$current['sort_order']}
            ORDER BY sort_order $order LIMIT 1
        "));

        if ($neighbor) {
            $current_id = intval($current['template_task_id']);
            $current_order = intval($current['sort_order']);
            $neighbor_id = intval($neighbor['template_task_id']);
            $neighbor_order = intval($neighbor['sort_order']);

            mysqli_query($mysqli, "UPDATE workflow_template_tasks SET sort_order = $neighbor_order WHERE template_task_id = $current_id");
            mysqli_query($mysqli, "UPDATE workflow_template_tasks SET sort_order = $current_order WHERE template_task_id = $neighbor_id");
        }
    }

    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}
