<?php

/*
 * RivetIT - GET/POST request handler for Price Quotes
 *
 * The light, standalone "give management a price" tool (agent/price_quotes.php, agent/price_quote.php).
 * Permission: module_client (department-scoped) - level 2 for every write below.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_price_quote'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $client_id = intval($_POST['client_id']);
    enforceClientAccess($client_id);

    $title = sanitizeInput(trim($_POST['title']));
    $description = sanitizeInput(trim($_POST['description'] ?? ''));

    if ($client_id <= 0 || $title === '') {
        flash_alert("A department and a title are required", "danger");
        redirect("price_quotes.php");
    }

    mysqli_query($mysqli, "INSERT INTO price_quotes SET
        price_quote_title = '$title',
        price_quote_description = '$description',
        price_quote_status = 'Draft',
        price_quote_client_id = $client_id,
        price_quote_created_by = $session_user_id,
        price_quote_updated_by = $session_user_id");

    $price_quote_id = mysqli_insert_id($mysqli);

    logAction("Price Quote", "Create", "$session_name created price quote \"$title\"", $client_id, $price_quote_id);

    flash_alert("Price quote created");
    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}

if (isset($_POST['edit_price_quote'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_id = intval($_POST['price_quote_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT price_quote_client_id FROM price_quotes WHERE price_quote_id = $price_quote_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    enforceClientAccess($client_id);

    $title = sanitizeInput(trim($_POST['title']));
    $description = sanitizeInput(trim($_POST['description'] ?? ''));

    if ($title === '') {
        flash_alert("A title is required", "danger");
        redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
    }

    mysqli_query($mysqli, "UPDATE price_quotes SET
        price_quote_title = '$title',
        price_quote_description = '$description',
        price_quote_updated_by = $session_user_id
        WHERE price_quote_id = $price_quote_id");

    logAction("Price Quote", "Edit", "$session_name edited price quote \"$title\"", $client_id, $price_quote_id);

    flash_alert("Price quote updated");
    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}

if (isset($_POST['edit_price_quote_status'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_id = intval($_POST['price_quote_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT price_quote_client_id FROM price_quotes WHERE price_quote_id = $price_quote_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    enforceClientAccess($client_id);

    $status = sanitizeInput($_POST['status']);
    if (!in_array($status, ['Draft', 'Sent', 'Accepted', 'Declined'], true)) {
        redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
    }

    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_status = '$status', price_quote_updated_by = $session_user_id WHERE price_quote_id = $price_quote_id");

    logAction("Price Quote", "Edit", "$session_name set price quote #$price_quote_id to $status", $client_id, $price_quote_id);

    flash_alert("Status updated");
    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}

if (isset($_GET['archive_price_quote'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_id = intval($_GET['archive_price_quote']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT price_quote_client_id, price_quote_title FROM price_quotes WHERE price_quote_id = $price_quote_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    enforceClientAccess($client_id);
    $title = sanitizeInput($row['price_quote_title']);

    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_archived_at = NOW() WHERE price_quote_id = $price_quote_id");

    logAction("Price Quote", "Archive", "$session_name archived price quote \"$title\"", $client_id, $price_quote_id);

    flash_alert("Price quote archived");
    redirect();
}

if (isset($_GET['restore_price_quote'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_id = intval($_GET['restore_price_quote']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT price_quote_client_id, price_quote_title FROM price_quotes WHERE price_quote_id = $price_quote_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    enforceClientAccess($client_id);
    $title = sanitizeInput($row['price_quote_title']);

    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_archived_at = NULL WHERE price_quote_id = $price_quote_id");

    logAction("Price Quote", "Restore", "$session_name restored price quote \"$title\"", $client_id, $price_quote_id);

    flash_alert("Price quote restored");
    redirect();
}

if (isset($_POST['add_price_quote_item'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_id = intval($_POST['price_quote_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT price_quote_client_id FROM price_quotes WHERE price_quote_id = $price_quote_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    enforceClientAccess($client_id);

    $description = sanitizeInput(trim($_POST['description']));
    $quantity = is_numeric($_POST['quantity'] ?? null) ? (float) $_POST['quantity'] : 0;
    $unit_price = is_numeric($_POST['unit_price'] ?? null) ? (float) $_POST['unit_price'] : 0;

    if ($description === '' || $quantity <= 0 || $unit_price < 0) {
        flash_alert("A description, a positive quantity and a non-negative unit price are required", "danger");
        redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
    }

    $next_order = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(price_quote_item_order), 0) + 1 AS n FROM price_quote_items WHERE price_quote_item_price_quote_id = $price_quote_id"));

    mysqli_query($mysqli, "INSERT INTO price_quote_items SET
        price_quote_item_price_quote_id = $price_quote_id,
        price_quote_item_description = '$description',
        price_quote_item_quantity = $quantity,
        price_quote_item_unit_price = $unit_price,
        price_quote_item_order = " . intval($next_order['n']));

    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_updated_by = $session_user_id WHERE price_quote_id = $price_quote_id");

    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}

if (isset($_POST['edit_price_quote_item'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_item_id = intval($_POST['price_quote_item_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT pqi.price_quote_item_price_quote_id, pq.price_quote_client_id FROM price_quote_items pqi
        LEFT JOIN price_quotes pq ON pq.price_quote_id = pqi.price_quote_item_price_quote_id
        WHERE pqi.price_quote_item_id = $price_quote_item_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    $price_quote_id = intval($row['price_quote_item_price_quote_id']);
    enforceClientAccess($client_id);

    $description = sanitizeInput(trim($_POST['description']));
    $quantity = is_numeric($_POST['quantity'] ?? null) ? (float) $_POST['quantity'] : 0;
    $unit_price = is_numeric($_POST['unit_price'] ?? null) ? (float) $_POST['unit_price'] : 0;

    if ($description === '' || $quantity <= 0 || $unit_price < 0) {
        flash_alert("A description, a positive quantity and a non-negative unit price are required", "danger");
        redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
    }

    mysqli_query($mysqli, "UPDATE price_quote_items SET
        price_quote_item_description = '$description',
        price_quote_item_quantity = $quantity,
        price_quote_item_unit_price = $unit_price
        WHERE price_quote_item_id = $price_quote_item_id");

    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_updated_by = $session_user_id WHERE price_quote_id = $price_quote_id");

    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}

if (isset($_GET['delete_price_quote_item'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_client', 2);

    $price_quote_item_id = intval($_GET['delete_price_quote_item']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT pqi.price_quote_item_price_quote_id, pq.price_quote_client_id FROM price_quote_items pqi
        LEFT JOIN price_quotes pq ON pq.price_quote_id = pqi.price_quote_item_price_quote_id
        WHERE pqi.price_quote_item_id = $price_quote_item_id"));
    if (!$row) {
        redirect("price_quotes.php");
    }
    $client_id = intval($row['price_quote_client_id']);
    $price_quote_id = intval($row['price_quote_item_price_quote_id']);
    enforceClientAccess($client_id);

    mysqli_query($mysqli, "DELETE FROM price_quote_items WHERE price_quote_item_id = $price_quote_item_id");
    mysqli_query($mysqli, "UPDATE price_quotes SET price_quote_updated_by = $session_user_id WHERE price_quote_id = $price_quote_id");

    redirect("price_quote.php?client_id=$client_id&price_quote_id=$price_quote_id");
}
