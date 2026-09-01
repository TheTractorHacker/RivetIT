<?php

/*
 * ITFlow - GET/POST request handler for CRM Engagement Segments
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../modals/crm/crm_helpers.php';

if (isset($_POST['add_segment'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $segment_name = sanitizeInput($_POST['name'] ?? '');
    if ($segment_name === '') {
        $segment_name = 'Untitled Segment';
    }

    $criteria = crmSanitizeCriteria($_POST);
    $criteria_json = mysqli_real_escape_string($mysqli, json_encode($criteria));

    mysqli_query($mysqli, "INSERT INTO crm_segments SET
        segment_name = '$segment_name',
        segment_criteria_json = '$criteria_json'");

    $segment_id = mysqli_insert_id($mysqli);

    logAction("CRM Segment", "Create", "$session_name created segment $segment_name", 0, $segment_id);

    flash_alert("Segment <strong>$segment_name</strong> created");

    redirect();
}

if (isset($_POST['edit_segment'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $segment_id = intval($_POST['segment_id']);
    $segment_name = sanitizeInput($_POST['name'] ?? '');
    if ($segment_name === '') {
        $segment_name = 'Untitled Segment';
    }

    $criteria = crmSanitizeCriteria($_POST);
    $criteria_json = mysqli_real_escape_string($mysqli, json_encode($criteria));

    mysqli_query($mysqli, "UPDATE crm_segments SET
        segment_name = '$segment_name',
        segment_criteria_json = '$criteria_json'
        WHERE segment_id = $segment_id");

    logAction("CRM Segment", "Edit", "$session_name edited segment $segment_name", 0, $segment_id);

    flash_alert("Segment <strong>$segment_name</strong> updated");

    redirect();
}

if (isset($_GET['delete_segment'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_sales', 3);

    $segment_id = intval($_GET['delete_segment']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT segment_name FROM crm_segments WHERE segment_id = $segment_id"));
    $segment_name = sanitizeInput($row['segment_name'] ?? '');

    mysqli_query($mysqli, "DELETE FROM crm_segments WHERE segment_id = $segment_id");

    logAction("CRM Segment", "Delete", "$session_name deleted segment $segment_name", 0, $segment_id);

    flash_alert("Segment <strong>$segment_name</strong> deleted");

    redirect();
}
