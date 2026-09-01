<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_onboarding_template'])) {

    validateCSRFToken($_POST['csrf_token']);

    $project_template_id = intval($_POST['project_template_id']);
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description']);
    $default_contract_template_id = !empty($_POST['default_contract_template_id']) ? intval($_POST['default_contract_template_id']) : 'NULL';

    mysqli_query($mysqli, "UPDATE project_templates SET
        project_template_name = '$name',
        project_template_description = '$description',
        project_template_default_contract_template_id = $default_contract_template_id
        WHERE project_template_id = $project_template_id");

    logAction("Onboarding Template", "Edit", "$session_name edited onboarding template $name", 0, $project_template_id);

    flash_alert("Onboarding Template <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['delete_onboarding_template'])) {

    validateCSRFToken($_GET['csrf_token']);

    $project_template_id = intval($_GET['delete_onboarding_template']);

    $project_template_name = sanitizeInput(getFieldById('project_templates', $project_template_id, 'project_template_name'));

    $sql_ticket_templates = mysqli_query($mysqli, "SELECT ticket_template_id FROM project_template_ticket_templates WHERE project_template_id = $project_template_id");
    while ($row = mysqli_fetch_assoc($sql_ticket_templates)) {
        $ticket_template_id = intval($row['ticket_template_id']);
        mysqli_query($mysqli, "DELETE FROM task_templates WHERE task_template_ticket_template_id = $ticket_template_id");
        mysqli_query($mysqli, "DELETE FROM ticket_templates WHERE ticket_template_id = $ticket_template_id");
    }

    mysqli_query($mysqli, "DELETE FROM project_template_ticket_templates WHERE project_template_id = $project_template_id");
    mysqli_query($mysqli, "DELETE FROM project_templates WHERE project_template_id = $project_template_id");

    logAction("Onboarding Template", "Delete", "$session_name deleted onboarding template $project_template_name and its checklist");

    flash_alert("Onboarding Template <strong>$project_template_name</strong> deleted", 'error');

    redirect("onboarding_templates.php");

}

if (isset($_POST['add_checklist_task'])) {

    validateCSRFToken($_POST['csrf_token']);

    $ticket_template_id = intval($_POST['ticket_template_id']);
    $task_name = sanitizeInput($_POST['task_name']);

    mysqli_query($mysqli, "INSERT INTO task_templates SET task_template_name = '$task_name', task_template_ticket_template_id = $ticket_template_id");

    logAction("Onboarding Template", "Edit", "$session_name added checklist item $task_name to onboarding template", 0, $ticket_template_id);

    flash_alert("Added checklist item <strong>$task_name</strong>");

    redirect();

}

if (isset($_POST['edit_ticket_template_task'])) {

    validateCSRFToken($_POST['csrf_token']);

    $task_template_id = intval($_POST['task_template_id']);
    $task_name = sanitizeInput($_POST['name']);
    $completion_estimate = intval($_POST['completion_estimate']);

    mysqli_query($mysqli, "UPDATE task_templates SET task_template_name = '$task_name', task_template_completion_estimate = $completion_estimate WHERE task_template_id = $task_template_id");

    logAction("Onboarding Template", "Edit", "$session_name edited checklist item $task_name");

    flash_alert("Checklist item <strong>$task_name</strong> updated");

    redirect();

}

if (isset($_GET['delete_task_template'])) {

    validateCSRFToken($_GET['csrf_token']);

    $task_template_id = intval($_GET['delete_task_template']);

    $task_template_name = sanitizeInput(getFieldById('task_templates', $task_template_id, 'task_template_name'));

    mysqli_query($mysqli, "DELETE FROM task_templates WHERE task_template_id = $task_template_id");

    logAction("Onboarding Template", "Edit", "$session_name deleted checklist item $task_template_name");

    flash_alert("Checklist item <strong>$task_template_name</strong> deleted", 'error');

    redirect();

}
