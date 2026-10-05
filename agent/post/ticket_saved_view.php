<?php

// Ticket Saved Views (left sidebar on the ticket dashboard)

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../includes/ticket_view_filters.php';

if (isset($_POST['add_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $name = sanitizeInput($_POST['name']);
    $icon = sanitizeInput($_POST['icon'] ?: 'fa-filter');
    // The filter form (validated, whitelisted) when present; otherwise the raw current-filters query as before.
    $query = isset($_POST['filters_present'])
        ? mysqli_real_escape_string($mysqli, ticketViewQueryFromPost($mysqli, $_POST))
        : mysqli_real_escape_string($mysqli, trim($_POST['query'] ?? '', '&'));

    $shared = (intval($_POST['shared'] ?? 0) === 1 && lookupUserPermission("module_support") === 3) ? 0 : $session_user_id;

    $sql_order = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(MAX(ticket_saved_view_order), -1) + 1 AS next_order FROM ticket_saved_views WHERE ticket_saved_view_user_id = $shared"));
    $order = intval($sql_order['next_order']);

    mysqli_query(
        $mysqli,
        "INSERT INTO ticket_saved_views SET
            ticket_saved_view_name = '$name',
            ticket_saved_view_icon = '$icon',
            ticket_saved_view_query = '$query',
            ticket_saved_view_user_id = $shared,
            ticket_saved_view_order = $order"
    );

    flash_alert("Saved view <strong>$name</strong> created");

    redirect("tickets.php");

}

if (isset($_POST['edit_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_saved_view_id = intval($_POST['ticket_saved_view_id']);
    $name = sanitizeInput($_POST['name']);
    $icon = sanitizeInput($_POST['icon'] ?: 'fa-filter');

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    // Only a submitted filter form changes the view's filters; a plain rename leaves them alone.
    $query_sql = isset($_POST['filters_present'])
        ? ", ticket_saved_view_query = '" . mysqli_real_escape_string($mysqli, ticketViewQueryFromPost($mysqli, $_POST)) . "'"
        : '';

    mysqli_query(
        $mysqli,
        "UPDATE ticket_saved_views SET
            ticket_saved_view_name = '$name',
            ticket_saved_view_icon = '$icon'$query_sql
         WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query"
    );

    flash_alert("Saved view <strong>$name</strong> updated");

    redirect("tickets.php");

}

if (isset($_GET['delete_ticket_saved_view'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_saved_view_id = intval($_GET['delete_ticket_saved_view']);

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_archived_at = NOW() WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query");

    flash_alert("Saved view deleted", 'error');

    redirect("tickets.php");

}

if (isset($_GET['move_ticket_saved_view_up']) || isset($_GET['move_ticket_saved_view_down'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 2);

    $direction = isset($_GET['move_ticket_saved_view_up']) ? 'up' : 'down';
    $ticket_saved_view_id = intval($_GET[$direction == 'up' ? 'move_ticket_saved_view_up' : 'move_ticket_saved_view_down']);

    $owner_query = (lookupUserPermission("module_support") === 3)
        ? "(ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)"
        : "ticket_saved_view_user_id = $session_user_id";

    $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query AND ticket_saved_view_archived_at IS NULL"));

    if ($current) {
        $current_order = intval($current['ticket_saved_view_order']);
        $current_owner = intval($current['ticket_saved_view_user_id']);
        $comparator = $direction == 'up' ? '<' : '>';
        $sort_dir = $direction == 'up' ? 'DESC' : 'ASC';

        $neighbor = mysqli_fetch_assoc(mysqli_query(
            $mysqli,
            "SELECT * FROM ticket_saved_views
             WHERE ticket_saved_view_user_id = $current_owner
             AND ticket_saved_view_archived_at IS NULL
             AND ticket_saved_view_order $comparator $current_order
             ORDER BY ticket_saved_view_order $sort_dir
             LIMIT 1"
        ));

        if ($neighbor) {
            $neighbor_id = intval($neighbor['ticket_saved_view_id']);
            $neighbor_order = intval($neighbor['ticket_saved_view_order']);

            mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_order = $neighbor_order WHERE ticket_saved_view_id = $ticket_saved_view_id");
            mysqli_query($mysqli, "UPDATE ticket_saved_views SET ticket_saved_view_order = $current_order WHERE ticket_saved_view_id = $neighbor_id");
        }
    }

    redirect("tickets.php");

}
