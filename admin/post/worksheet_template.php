<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_worksheet_template'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 2);

    $name = sanitizeInput($_POST['worksheet_template_name']);
    $desc = sanitizeInput($_POST['worksheet_template_description']);

    mysqli_query($mysqli, "INSERT INTO worksheet_templates SET worksheet_template_name = '$name', worksheet_template_description = '$desc', worksheet_template_created_by = $session_user_id");

    $new_id = mysqli_insert_id($mysqli);
    logAction("Worksheet Template", "Add", "Created worksheet template: $name");
    flash_alert("Worksheet template <strong>$name</strong> created");
    header("Location: worksheet_template_details.php?id=$new_id");
    exit;
}

if (isset($_POST['edit_worksheet_template'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 2);

    $template_id = intval($_POST['worksheet_template_id']);
    $name = sanitizeInput($_POST['worksheet_template_name']);
    $desc = sanitizeInput($_POST['worksheet_template_description']);

    mysqli_query($mysqli, "UPDATE worksheet_templates SET worksheet_template_name = '$name', worksheet_template_description = '$desc' WHERE worksheet_template_id = $template_id");
    logAction("Worksheet Template", "Edit", "Updated worksheet template: $name");
    flash_alert("Template updated");
    redirect();
}

if (isset($_POST['add_worksheet_field'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 2);

    $template_id = intval($_POST['field_template_id']);
    $name = sanitizeInput($_POST['field_name']);
    $type = sanitizeInput($_POST['field_type']);
    $options = sanitizeInput($_POST['field_options'] ?? '');
    $required = isset($_POST['field_required']) ? 1 : 0;

    $max_order = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(field_order),0) FROM worksheet_template_fields WHERE field_template_id = $template_id"))[0];
    $order = intval($max_order) + 1;

    mysqli_query($mysqli, "INSERT INTO worksheet_template_fields SET field_template_id = $template_id, field_name = '$name', field_type = '$type', field_options = '$options', field_order = $order, field_required = $required");
    flash_alert("Field <strong>$name</strong> added");
    redirect();
}

if (isset($_POST['edit_worksheet_field'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 2);

    $field_id = intval($_POST['field_id']);
    $name = sanitizeInput($_POST['field_name']);
    $type = sanitizeInput($_POST['field_type']);
    $options = sanitizeInput($_POST['field_options'] ?? '');
    $required = isset($_POST['field_required']) ? 1 : 0;

    mysqli_query($mysqli, "UPDATE worksheet_template_fields SET field_name = '$name', field_type = '$type', field_options = '$options', field_required = $required WHERE field_id = $field_id");
    flash_alert("Field updated");
    redirect();
}

if (isset($_GET['delete_worksheet_template'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('admin', 3);

    $template_id = intval($_GET['delete_worksheet_template']);
    mysqli_query($mysqli, "UPDATE worksheet_templates SET worksheet_template_archived_at = NOW() WHERE worksheet_template_id = $template_id");
    flash_alert("Template deleted");
    header("Location: worksheet_template.php");
    exit;
}

if (isset($_POST['reorder_worksheet_fields'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('admin', 2);

    $order = json_decode($_POST['order'] ?? '[]', true);
    if (is_array($order)) {
        foreach ($order as $pos => $field_id) {
            $field_id = intval($field_id);
            $new_order = intval($pos) + 1;
            mysqli_query($mysqli, "UPDATE worksheet_template_fields SET field_order = $new_order WHERE field_id = $field_id");
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['delete_worksheet_field'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('admin', 2);

    $field_id = intval($_GET['delete_worksheet_field']);
    $template_id = intval($_GET['template_id']);
    mysqli_query($mysqli, "DELETE FROM worksheet_template_fields WHERE field_id = $field_id");
    flash_alert("Field deleted");
    header("Location: worksheet_template_details.php?id=$template_id");
    exit;
}
