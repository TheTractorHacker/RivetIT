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
