<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_labor_type'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name  = sanitizeInput($_POST['name']);
    $rate  = round(floatval($_POST['rate']), 2);
    $color = sanitizeInput($_POST['color']);

    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(labor_type_order),0)+1 FROM labor_types"))[0]);

    mysqli_query($mysqli, "INSERT INTO labor_types SET labor_type_name='$name', labor_type_rate=$rate, labor_type_color='$color', labor_type_order=$order");

    logAction("Labor Type", "Create", "$session_name created labor type $name");

    flash_alert("Labor type <strong>$name</strong> created");
    redirect();
}

if (isset($_POST['edit_labor_type'])) {

    validateCSRFToken($_POST['csrf_token']);

    $labor_type_id = intval($_POST['labor_type_id']);
    $name  = sanitizeInput($_POST['name']);
    $rate  = round(floatval($_POST['rate']), 2);
    $color = sanitizeInput($_POST['color']);
    $order = intval($_POST['order']);

    mysqli_query($mysqli, "UPDATE labor_types SET labor_type_name='$name', labor_type_rate=$rate, labor_type_color='$color', labor_type_order=$order WHERE labor_type_id=$labor_type_id");

    logAction("Labor Type", "Edit", "$session_name edited labor type $name");

    flash_alert("Labor type <strong>$name</strong> updated");
    redirect();
}

if (isset($_GET['delete_labor_type'])) {

    validateCSRFToken($_GET['csrf_token']);

    $labor_type_id = intval($_GET['delete_labor_type']);
    $name = sanitizeInput(getFieldById('labor_types', $labor_type_id, 'labor_type_name'));

    mysqli_query($mysqli, "UPDATE labor_types SET labor_type_archived_at=NOW() WHERE labor_type_id=$labor_type_id");

    logAction("Labor Type", "Delete", "$session_name deleted labor type $name");

    flash_alert("Labor type <strong>$name</strong> deleted", 'error');
    redirect();
}
