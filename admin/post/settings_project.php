<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_project_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_project_prefix = sanitizeInput($_POST['config_project_prefix']);
    $config_project_next_number = intval($_POST['config_project_next_number']);

    if (trim($config_project_prefix) === '' || $config_project_next_number < 1) {
        flash_alert("Enter a project prefix and a next number of 1 or more. Nothing was saved.", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE settings SET config_project_prefix = '$config_project_prefix', config_project_next_number = $config_project_next_number WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited project settings");

    flash_alert("Project Settings updated");

    redirect();

}
