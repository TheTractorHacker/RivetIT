<?php

/*
 * ITFlow - GET/POST request handler for AI Models ('ai_model')
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_ai_model'])) {

    validateCSRFToken($_POST['csrf_token']);

    $provider_id = intval($_POST['provider']);
    $model = trim(sanitizeInput($_POST['model']));
    $prompt = sanitizeInput($_POST['prompt']);
    $use_case = sanitizeInput($_POST['use_case']);

    if ($model === '' || $provider_id <= 0) {
        flash_alert("Model Name and Provider are required", 'error');
        redirect();
    }

    $dupe = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ai_model_name FROM ai_models WHERE ai_model_use_case = '$use_case' LIMIT 1"));
    if ($dupe) {
        flash_alert("Use Case <strong>$use_case</strong> is already assigned to model <strong>{$dupe['ai_model_name']}</strong> - each use case can only have one model", 'error');
        redirect();
    }

    mysqli_query($mysqli,"INSERT INTO ai_models SET ai_model_name = '$model', ai_model_prompt = '$prompt', ai_model_use_case = '$use_case', ai_model_ai_provider_id = $provider_id");

    $ai_model_id = mysqli_insert_id($mysqli);

    logAction("AI Model", "Create", "$session_name created AI Model $model");

    flash_alert("AI Model <strong>$model</strong> created");

    redirect();

}

if (isset($_POST['edit_ai_model'])) {

    validateCSRFToken($_POST['csrf_token']);

    $model_id = intval($_POST['model_id']);
    $provider_id = intval($_POST['provider']);
    $model = trim(sanitizeInput($_POST['model']));
    $prompt = sanitizeInput($_POST['prompt']);
    $use_case = sanitizeInput($_POST['use_case']);

    if ($model === '' || $provider_id <= 0) {
        flash_alert("Model Name and Provider are required", 'error');
        redirect();
    }

    $dupe = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ai_model_name FROM ai_models WHERE ai_model_use_case = '$use_case' AND ai_model_id != $model_id LIMIT 1"));
    if ($dupe) {
        flash_alert("Use Case <strong>$use_case</strong> is already assigned to model <strong>{$dupe['ai_model_name']}</strong> - each use case can only have one model", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE ai_models SET ai_model_name = '$model', ai_model_prompt = '$prompt', ai_model_use_case = '$use_case', ai_model_ai_provider_id = $provider_id WHERE ai_model_id = $model_id");

    logAction("AI Model", "Edit", "$session_name edited AI Model $model");

    flash_alert("AI Model <strong>$model</strong> edited");

    redirect();

}

if (isset($_GET['delete_ai_model'])) {

    validateCSRFToken($_GET['csrf_token']);

    $model_id = intval($_GET['delete_ai_model']);

    $model_name = sanitizeInput(getFieldById('ai_models', $model_id, 'ai_model_name'));

    mysqli_query($mysqli,"DELETE FROM ai_models WHERE ai_model_id = $model_id");

    logAction("AI Model", "Delete", "$session_name deleted AI Model $model_name");

    flash_alert("AI Model <strong>$model_name</strong> deleted", 'error');

    redirect();

}
