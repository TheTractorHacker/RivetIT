<?php

// Canned Responses

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_canned_response'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $message = mysqli_real_escape_string($mysqli, $_POST['message']);

    mysqli_query($mysqli, "INSERT INTO canned_responses SET canned_response_name = '$name', canned_response_message = '$message'");

    $canned_response_id = mysqli_insert_id($mysqli);

    logAction("Canned Response", "Create", "$session_name created canned response $name", 0, $canned_response_id);

    flash_alert("Canned Response <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_canned_response'])) {

    validateCSRFToken($_POST['csrf_token']);

    $canned_response_id = intval($_POST['canned_response_id']);
    $name = sanitizeInput($_POST['name']);
    $message = mysqli_real_escape_string($mysqli, $_POST['message']);

    mysqli_query($mysqli, "UPDATE canned_responses SET canned_response_name = '$name', canned_response_message = '$message' WHERE canned_response_id = $canned_response_id");

    logAction("Canned Response", "Edit", "$session_name edited canned response $name", 0, $canned_response_id);

    flash_alert("Canned Response <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['delete_canned_response'])) {

    validateCSRFToken($_GET['csrf_token']);

    $canned_response_id = intval($_GET['delete_canned_response']);

    $canned_response_name = sanitizeInput(getFieldById('canned_responses', $canned_response_id, 'canned_response_name'));

    mysqli_query($mysqli, "DELETE FROM canned_responses WHERE canned_response_id = $canned_response_id");

    logAction("Canned Response", "Delete", "$session_name deleted canned response $canned_response_name");

    flash_alert("Canned Response <strong>$canned_response_name</strong> deleted", 'error');

    redirect();

}
