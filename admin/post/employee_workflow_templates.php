<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_employee_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');

    mysqli_query($mysqli, "INSERT INTO workflow_templates SET name = '$name', type = '$type', description = '$description', created_by = $session_user_id");
    $workflow_template_id = mysqli_insert_id($mysqli);

    logAction("Settings", "Create", "$session_name created employee workflow template $name");

    flash_alert("Template <strong>$name</strong> created");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_POST['edit_employee_workflow_template'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_POST['workflow_template_id']);
    $name = sanitizeInput($_POST['name']);
    $type = in_array($_POST['type'] ?? '', ['onboarding', 'offboarding'], true) ? $_POST['type'] : 'onboarding';
    $description = sanitizeInput($_POST['description'] ?? '');

    mysqli_query($mysqli, "UPDATE workflow_templates SET name = '$name', type = '$type', description = '$description' WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Edit", "$session_name edited employee workflow template $name");

    flash_alert("Template <strong>$name</strong> updated");
    redirect("employee_workflow_template_details.php?id=$workflow_template_id");
}

if (isset($_GET['archive_employee_workflow_template'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $workflow_template_id = intval($_GET['archive_employee_workflow_template']);
    $name = sanitizeInput(getFieldById('workflow_templates', $workflow_template_id, 'name'));

    mysqli_query($mysqli, "UPDATE workflow_templates SET archived_at = NOW() WHERE workflow_template_id = $workflow_template_id");

    logAction("Settings", "Archive", "$session_name archived employee workflow template $name");

    flash_alert("Template <strong>$name</strong> archived", 'error');
    redirect("employee_workflow_templates.php");
}
