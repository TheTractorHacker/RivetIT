<?php

/*
 * ITFlow - GET/POST request handler for the Service Catalog (admin/service_catalog.php)
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

function service_catalog_priority_or_null($raw) {
    $priority = sanitizeInput($raw);
    return in_array($priority, ['Low', 'Medium', 'High'], true) ? $priority : null;
}

if (isset($_POST['add_service_catalog_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description'] ?? '');
    $icon = preg_replace("/[^0-9a-zA-Z-]/", "", sanitizeInput($_POST['icon'] ?? ''));
    $ticket_subject_template = sanitizeInput($_POST['ticket_subject_template']);
    $ticket_category_id = intval($_POST['ticket_category_id'] ?? 0);
    $default_priority = service_catalog_priority_or_null($_POST['default_priority'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $sort_order = intval($_POST['sort_order'] ?? 0);

    $ticket_category_sql = $ticket_category_id > 0 ? $ticket_category_id : 'NULL';
    $default_priority_sql = $default_priority !== null ? "'$default_priority'" : 'NULL';

    mysqli_query(
        $mysqli,
        "INSERT INTO service_catalog_items
            SET name = '$name',
                description = " . ($description !== '' ? "'$description'" : 'NULL') . ",
                icon = " . ($icon !== '' ? "'$icon'" : 'NULL') . ",
                ticket_subject_template = '$ticket_subject_template',
                ticket_category_id = $ticket_category_sql,
                default_priority = $default_priority_sql,
                is_active = $is_active,
                sort_order = $sort_order"
    );

    $catalog_item_id = mysqli_insert_id($mysqli);

    logAction("Service Catalog", "Create", "$session_name created catalog item $name", 0, $catalog_item_id);

    flash_alert("Catalog item <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_service_catalog_item'])) {

    validateCSRFToken($_POST['csrf_token']);

    $catalog_item_id = intval($_POST['catalog_item_id']);

    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description'] ?? '');
    $icon = preg_replace("/[^0-9a-zA-Z-]/", "", sanitizeInput($_POST['icon'] ?? ''));
    $ticket_subject_template = sanitizeInput($_POST['ticket_subject_template']);
    $ticket_category_id = intval($_POST['ticket_category_id'] ?? 0);
    $default_priority = service_catalog_priority_or_null($_POST['default_priority'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $sort_order = intval($_POST['sort_order'] ?? 0);

    $ticket_category_sql = $ticket_category_id > 0 ? $ticket_category_id : 'NULL';
    $default_priority_sql = $default_priority !== null ? "'$default_priority'" : 'NULL';

    mysqli_query(
        $mysqli,
        "UPDATE service_catalog_items
            SET name = '$name',
                description = " . ($description !== '' ? "'$description'" : 'NULL') . ",
                icon = " . ($icon !== '' ? "'$icon'" : 'NULL') . ",
                ticket_subject_template = '$ticket_subject_template',
                ticket_category_id = $ticket_category_sql,
                default_priority = $default_priority_sql,
                is_active = $is_active,
                sort_order = $sort_order
            WHERE catalog_item_id = $catalog_item_id"
    );

    logAction("Service Catalog", "Edit", "$session_name edited catalog item $name", 0, $catalog_item_id);

    flash_alert("Catalog item <strong>$name</strong> updated");

    redirect();

}

if (isset($_GET['toggle_service_catalog_item'])) {

    validateCSRFToken($_GET['csrf_token']);

    $catalog_item_id = intval($_GET['toggle_service_catalog_item']);

    $name = sanitizeInput(getFieldById('service_catalog_items', $catalog_item_id, 'name'));

    mysqli_query($mysqli, "UPDATE service_catalog_items SET is_active = 1 - is_active WHERE catalog_item_id = $catalog_item_id");

    logAction("Service Catalog", "Edit", "$session_name toggled active state of catalog item $name", 0, $catalog_item_id);

    flash_alert("Catalog item <strong>$name</strong> updated");

    redirect();

}

if (isset($_GET['delete_service_catalog_item'])) {

    validateCSRFToken($_GET['csrf_token']);

    $catalog_item_id = intval($_GET['delete_service_catalog_item']);

    $name = sanitizeInput(getFieldById('service_catalog_items', $catalog_item_id, 'name'));

    mysqli_query($mysqli, "DELETE FROM service_catalog_items WHERE catalog_item_id = $catalog_item_id");

    logAction("Service Catalog", "Delete", "$session_name deleted catalog item $name");

    flash_alert("Catalog item <strong>$name</strong> deleted", 'error');

    redirect();

}
