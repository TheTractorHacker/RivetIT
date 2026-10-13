<?php

// Ticket Saved Views (left sidebar on the ticket dashboard)

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../includes/ticket_view_filters.php';

/**
 * Set or clear a default view and leave the log and audit trail behind. $scope is 'personal' or 'shared'. Returns a message for the user
 * ('' when nothing changed).
 */
function ticketViewDefaultChange($mysqli, int $user_id, string $session_name, int $view_id, string $scope, bool $set, bool $can_share): string
{
    $shared = $scope === 'shared';
    if ($set) {
        $res = ticketViewSetDefault($mysqli, $user_id, $view_id, $scope, $can_share);
        if (!$res['ok']) {
            return 'ERROR:' . $res['error'];
        }
        $view = ticketViewVisible($mysqli, $view_id, $user_id);
        $name = $view['ticket_saved_view_name'] ?? '';
        $what = $shared ? "set the shared default ticket view to $name" : "set their default ticket view to $name";
        $event = 'ticket_view.default_set';
    } else {
        if (!ticketViewClearDefault($mysqli, $user_id, $scope, $can_share)) {
            return '';
        }
        $what = $shared ? 'cleared the shared default ticket view' : 'cleared their default ticket view';
        $event = 'ticket_view.default_cleared';
    }
    logAction('Ticket', 'Edit', "$session_name $what");
    if (!function_exists('rivetAudit') && is_file(dirname(__DIR__, 2) . '/includes/event_bus.php')) {
        require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
    }
    if (function_exists('rivetAudit')) {
        rivetAudit($event, $user_id, 'ticket_saved_view', $view_id, $set ? 'set' : 'clear', "$session_name $what", ['view_id' => $view_id, 'scope' => $scope]);
    }

    return $what;
}

/** Apply the two default checkboxes of the add/edit forms to a view. */
function ticketViewDefaultFromForm($mysqli, int $user_id, string $session_name, int $view_id, bool $view_is_shared, bool $can_share): void
{
    // Personal default: ticked = make it, unticked (on the edit form, which says it carries the box) = clear it if it was.
    $has_box = isset($_POST['default_present']);
    if (intval($_POST['make_default'] ?? 0) === 1) {
        $msg = ticketViewDefaultChange($mysqli, $user_id, $session_name, $view_id, 'personal', true, $can_share);
        if (strpos($msg, 'ERROR:') === 0) {
            flash_alert(substr($msg, 6), 'error');
        }
    } elseif ($has_box && ticketViewDefaultIds($mysqli, $user_id)['personal'] === $view_id) {
        ticketViewDefaultChange($mysqli, $user_id, $session_name, $view_id, 'personal', false, $can_share);
    }

    // Shared default: administrators only, and only for a view shared with everyone.
    if ($can_share && $view_is_shared) {
        if (intval($_POST['shared_default'] ?? 0) === 1) {
            $msg = ticketViewDefaultChange($mysqli, $user_id, $session_name, $view_id, 'shared', true, $can_share);
            if (strpos($msg, 'ERROR:') === 0) {
                flash_alert(substr($msg, 6), 'error');
            }
        } elseif ($has_box && ticketViewDefaultIds($mysqli, $user_id)['shared'] === $view_id) {
            ticketViewDefaultChange($mysqli, $user_id, $session_name, $view_id, 'shared', false, $can_share);
        }
    } elseif (intval($_POST['shared_default'] ?? 0) === 1) {
        flash_alert('A default for everyone needs a view shared with all agents.', 'error');
    }
}

if (isset($_POST['add_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $name = sanitizeInput($_POST['name']);
    $icon = sanitizeInput(\RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', 'fa-filter'));
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

    $new_view_id = intval(mysqli_insert_id($mysqli));
    ticketViewDefaultFromForm($mysqli, (int) $session_user_id, $session_name, $new_view_id, $shared === 0, lookupUserPermission("module_support") === 3);

    flash_alert("Saved view <strong>$name</strong> created");

    redirect("tickets.php");

}

if (isset($_POST['edit_ticket_saved_view'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_saved_view_id = intval($_POST['ticket_saved_view_id']);
    $name = sanitizeInput($_POST['name']);
    $icon = sanitizeInput(\RivetCore\Ui\IconCatalog::normalize($_POST['icon'] ?? '', 'fa-filter'));

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

    // Only a view this person may edit can have its default boxes applied
    $edited = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_saved_view_user_id FROM ticket_saved_views WHERE ticket_saved_view_id = $ticket_saved_view_id AND $owner_query AND ticket_saved_view_archived_at IS NULL"));
    if ($edited) {
        ticketViewDefaultFromForm($mysqli, (int) $session_user_id, $session_name, $ticket_saved_view_id, intval($edited['ticket_saved_view_user_id']) === 0, lookupUserPermission("module_support") === 3);
    }

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
    if (mysqli_affected_rows($mysqli) > 0) {
        // A deleted view cannot stay anyone's default: they fall back to the shared default, then the built-in open list.
        ticketViewDropDefaultsFor($mysqli, $ticket_saved_view_id);
    }

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

if (isset($_GET['set_ticket_view_default']) || isset($_GET['clear_ticket_view_default'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support');

    $set = isset($_GET['set_ticket_view_default']);
    $view_id = intval($_GET[$set ? 'set_ticket_view_default' : 'clear_ticket_view_default']);
    $scope = ($_GET['scope'] ?? '') === 'shared' ? 'shared' : 'personal';

    $msg = ticketViewDefaultChange($mysqli, (int) $session_user_id, $session_name, $set ? $view_id : ($scope === 'shared' ? ticketViewDefaultIds($mysqli, (int) $session_user_id)['shared'] ?? 0 : ticketViewDefaultIds($mysqli, (int) $session_user_id)['personal'] ?? 0), $scope, $set, lookupUserPermission("module_support") === 3);

    if (strpos($msg, 'ERROR:') === 0) {
        flash_alert(substr($msg, 6), 'error');
    } elseif ($msg !== '') {
        flash_alert($set ? ($scope === 'shared' ? 'This view is now the default for everyone without their own' : 'This view is now your default') : ($scope === 'shared' ? 'The default for everyone was cleared' : 'Your default view was cleared'));
    }

    redirect(); // back to the page the click came from (Referer), filters intact

}
