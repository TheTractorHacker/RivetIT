<?php

// Knowledge Base Categories

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_kb_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

    $name = sanitizeInput($_POST['name']);
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $kb_category_client_id = intval($_POST['client_id'] ?? 0);

    $sql_order = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(kb_category_order), -1) + 1 AS next_order FROM kb_categories WHERE kb_category_parent_id = $parent_id"));
    $order = intval($sql_order['next_order']);

    mysqli_query(
        $mysqli,
        "INSERT INTO kb_categories SET
            kb_category_name = '$name',
            kb_category_parent_id = $parent_id,
            kb_category_client_id = $kb_category_client_id,
            kb_category_order = $order"
    );

    $kb_category_id = mysqli_insert_id($mysqli);

    logAction("Knowledge Base", "Create", "$session_name created KB category: $name", $kb_category_client_id, $kb_category_id);

    flash_alert("Category <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_kb_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

    $kb_category_id = intval($_POST['kb_category_id']);
    $name = sanitizeInput($_POST['name']);
    $parent_id = intval($_POST['parent_id'] ?? 0);
    $kb_category_client_id = intval($_POST['client_id'] ?? 0);

    // A category can't be its own parent.
    if ($parent_id == $kb_category_id) {
        $parent_id = 0;
    }

    mysqli_query(
        $mysqli,
        "UPDATE kb_categories SET
            kb_category_name = '$name',
            kb_category_parent_id = $parent_id,
            kb_category_client_id = $kb_category_client_id
         WHERE kb_category_id = $kb_category_id"
    );

    logAction("Knowledge Base", "Edit", "$session_name edited KB category: $name", $kb_category_client_id, $kb_category_id);

    flash_alert("Category <strong>$name</strong> updated");

    redirect();

}

if (isset($_GET['delete_kb_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_kb');

    $kb_category_id = intval($_GET['delete_kb_category']);

    $kb_category_name = sanitizeInput(getFieldById('kb_categories', $kb_category_id, 'kb_category_name'));

    // Articles and sub-categories fall back to uncategorized / top-level.
    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_category_id = 0 WHERE kb_article_category_id = $kb_category_id");
    mysqli_query($mysqli, "UPDATE kb_categories SET kb_category_parent_id = 0 WHERE kb_category_parent_id = $kb_category_id");

    mysqli_query($mysqli, "UPDATE kb_categories SET kb_category_archived_at = NOW() WHERE kb_category_id = $kb_category_id");

    logAction("Knowledge Base", "Delete", "$session_name deleted KB category: $kb_category_name");

    flash_alert("Category <strong>$kb_category_name</strong> deleted", 'error');

    redirect();

}
