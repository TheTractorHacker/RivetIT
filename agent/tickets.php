<?php

// Default Column Sortby Filter
$sort = "ticket_number";
$order = "DESC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND ticket_client_id = $client_id";
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_url = '';
}

// Perms
enforceUserPermission('module_support');

// Ticket status from GET
$status_filter_id = '';
if (isset($_GET['status']) && is_array($_GET['status']) && !empty($_GET['status'])) {
    // Sanitize each element of the status array
    $sanitizedStatuses = array();
    foreach ($_GET['status'] as $status) {
        // Escape each status to prevent SQL injection
        $sanitizedStatuses[] = "'" . intval($status) . "'";
    }

    // Convert the sanitized statuses into a comma-separated string
    $sanitizedStatusesString = implode(",", $sanitizedStatuses);
    $ticket_status_snippet = "ticket_status IN ($sanitizedStatusesString)";
    $status = '';
    $status_filter_id = intval($_GET['status'][0]);

} elseif (isset($_GET['status']) && ctype_digit((string) $_GET['status'])) {
    // Single status selected from the Status filter pill
    $status_filter_id = intval($_GET['status']);
    $status = '';
    $ticket_status_snippet = "ticket_status = $status_filter_id";
} else {

    // TODO: Convert this to use the status IDs
    if (isset($_GET['status']) && ($_GET['status']) == 'Closed') {
        $status = 'Closed';
        $ticket_status_snippet = "ticket_resolved_at IS NOT NULL";
    } else {
        // Default - Show open tickets
        $status = 'Open';
        $ticket_status_snippet = "ticket_resolved_at IS NULL";
    }
}

if (isset($_GET['billable']) && ($_GET['billable']) == '1') {
    if (isset($_GET['unbilled'])) {
        $billable = 1;
        $ticket_billable_snippet = "AND ticket_billable = 1 AND ticket_invoice_id = 0";
        $ticket_status_snippet = '1 = 1';
    }
} else {
    $billable = 0;
    $ticket_billable_snippet = '';
}

// Category Filter
if (isset($_GET['category']) & !empty($_GET['category'])) {
    $category_query = 'AND (ticket_category = ' . intval($_GET['category']) . ')';
    $category_filter = intval($_GET['category']);
} else {
    // Default - any
    $category_query = '';
    $category_filter = '';
}

// Board Filter (top-level ticket category group)
if (isset($_GET['board']) && !empty($_GET['board'])) {
    $board_filter = intval($_GET['board']);
    $board_query = "AND ticket_category IN (SELECT category_id FROM categories WHERE category_id = $board_filter OR category_parent = $board_filter)";
} else {
    $board_filter = '';
    $board_query = '';
}

// Priority Filter
if (isset($_GET['priority']) && in_array($_GET['priority'], ['Low', 'Medium', 'High'], true)) {
    $priority_filter = $_GET['priority'];
    $priority_query = "AND ticket_priority = '$priority_filter'";
} else {
    $priority_filter = '';
    $priority_query = '';
}

// On-site / Remote filter (used by the On-Site / Remote saved views)
if (isset($_GET['onsite']) && in_array($_GET['onsite'], ['0', '1'], true)) {
    $onsite_filter = intval($_GET['onsite']);
    $onsite_query = "AND ticket_onsite = $onsite_filter";
} else {
    $onsite_filter = '';
    $onsite_query = '';
}

// Stat-box filters (Overdue / Due Today / On-Site Open) - clicking a stat box applies one of these
$stat_query = '';
if (isset($_GET['overdue'])) {
    $stat_query = "AND ticket_due_at IS NOT NULL AND ticket_due_at < NOW() AND ticket_resolved_at IS NULL";
} elseif (isset($_GET['due_today'])) {
    $stat_query = "AND ticket_due_at IS NOT NULL AND DATE(ticket_due_at) = CURDATE() AND ticket_resolved_at IS NULL";
}

// Ticket assignment status filter
// Default - any
$ticket_assigned_query = '';
$ticket_assigned_filter_id = '';
if (isset($_GET['assigned']) & !empty($_GET['assigned'])) {
    if ($_GET['assigned'] == 'unassigned') {
        $ticket_assigned_query = 'AND ticket_assigned_to = 0';
        $ticket_assigned_filter_id = 0;
    } else {
        $ticket_assigned_query = 'AND ticket_assigned_to = ' . intval($_GET['assigned']);
        $ticket_assigned_filter_id = intval($_GET['assigned']);
    }
}

// Project filter
// Default - any (including tickets without a project)
$ticket_project_snippet = '';
$ticket_project_filter_id = '';
if (isset($_GET['project']) & !empty($_GET['project']) && $_GET['project'] > '0') {
    $ticket_project_snippet = 'AND ticket_project_id = ' . intval($_GET['project']);
    $ticket_project_filter_id = intval($_GET['project']);
}

// Tags Filter
if (isset($_GET['tags']) && is_array($_GET['tags']) && !empty($_GET['tags'])) {
    $ticket_tag_filter = array_map('intval', $_GET['tags']);
    $ticket_tag_filter_string = implode(",", $ticket_tag_filter);
    $ticket_tag_query = "AND ticket_id IN (SELECT ticket_tag_ticket_id FROM ticket_tags WHERE ticket_tag_tag_id IN ($ticket_tag_filter_string))";
} else {
    $ticket_tag_filter = array();
    $ticket_tag_query = '';
}

// Ticket client access overide - This is the only way to show tickets without a client to agents with restricted client access
$access_permission_query_overide = '';
if ($client_access_string) {
    $access_permission_query_overide = "AND ticket_client_id IN (0,$client_access_string)";
}

// Main ticket query:
$query =
    "SELECT SQL_CALC_FOUND_ROWS * FROM tickets
    LEFT JOIN clients ON ticket_client_id = client_id
    LEFT JOIN contacts ON ticket_contact_id = contact_id
    LEFT JOIN users ON ticket_assigned_to = user_id
    LEFT JOIN assets ON ticket_asset_id = asset_id
    LEFT JOIN locations ON ticket_location_id = location_id
    LEFT JOIN vendors ON ticket_vendor_id = vendor_id
    LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
    LEFT JOIN categories ON ticket_category = category_id
    WHERE $ticket_status_snippet " . $ticket_assigned_query . "
    $category_query
    $board_query
    $priority_query
    $onsite_query
    $stat_query
    AND DATE(ticket_created_at) BETWEEN '$dtf' AND '$dtt'
    AND (CONCAT(ticket_prefix,ticket_number) LIKE '%$q%' OR client_name LIKE '%$q%' OR ticket_subject LIKE '%$q%' OR ticket_status_name LIKE '%$q%' OR ticket_priority LIKE '%$q%' OR user_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR asset_name LIKE '%$q%' OR vendor_name LIKE '%$q%' OR ticket_vendor_ticket_number LIKE '%q%')
    $ticket_billable_snippet
    $ticket_project_snippet
    $ticket_tag_query
    $access_permission_query_overide
    $client_query
    ORDER BY
        COALESCE(category_name, '') ASC,
        CASE
            WHEN '$sort' = 'ticket_priority' THEN
                CASE ticket_priority
                    WHEN 'High' THEN 1
                    WHEN 'Medium' THEN 2
                    WHEN 'Low' THEN 3
                    ELSE 4
                END
            ELSE NULL
        END $order,
        $sort $order
    LIMIT $record_from, $record_to";

$sql = mysqli_query($mysqli,$query);

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

//Get Total tickets open
$sql_total_tickets_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_open FROM tickets WHERE ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_open);
$total_tickets_open = intval($row['total_tickets_open']);

//Get Total tickets closed
$sql_total_tickets_closed = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_closed FROM tickets WHERE ticket_resolved_at IS NOT NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_closed);
$total_tickets_closed = intval($row['total_tickets_closed']);

//Get Unassigned tickets
$sql_total_tickets_unassigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_unassigned FROM tickets WHERE ticket_assigned_to = '0' AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_unassigned);
$total_tickets_unassigned = intval($row['total_tickets_unassigned']);

//Get Total tickets assigned to me
$sql_total_tickets_assigned = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_assigned FROM tickets WHERE ticket_assigned_to = $session_user_id AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_assigned);
$user_active_assigned_tickets = intval($row['total_tickets_assigned']);

//Get Overdue tickets (past due, still open)
$sql_total_tickets_overdue = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_overdue FROM tickets WHERE ticket_due_at IS NOT NULL AND ticket_due_at < NOW() AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_overdue);
$total_tickets_overdue = intval($row['total_tickets_overdue']);

//Get tickets due today (still open)
$sql_total_tickets_due_today = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_due_today FROM tickets WHERE ticket_due_at IS NOT NULL AND DATE(ticket_due_at) = CURDATE() AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_due_today);
$total_tickets_due_today = intval($row['total_tickets_due_today']);

//Get open on-site tickets
$sql_total_tickets_onsite_open = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_onsite_open FROM tickets WHERE ticket_onsite = 1 AND ticket_resolved_at IS NULL $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_onsite_open);
$total_tickets_onsite_open = intval($row['total_tickets_onsite_open']);

//Get All tickets (open + closed)
$sql_total_tickets_all = mysqli_query($mysqli, "SELECT COUNT(ticket_id) AS total_tickets_all FROM tickets WHERE 1=1 $client_query $access_permission_query_overide");
$row = mysqli_fetch_assoc($sql_total_tickets_all);
$total_tickets_all = intval($row['total_tickets_all']);

// Saved Views sidebar (hidden in client-scoped view)
$_saved_views = [];
if (!$client_url) {
    $sql_saved_views = mysqli_query(
        $mysqli,
        "SELECT * FROM ticket_saved_views
         WHERE (ticket_saved_view_user_id = 0 OR ticket_saved_view_user_id = $session_user_id)
         AND ticket_saved_view_archived_at IS NULL
         ORDER BY ticket_saved_view_user_id ASC, ticket_saved_view_order ASC"
    );
    while ($row = mysqli_fetch_assoc($sql_saved_views)) $_saved_views[] = $row;
}

// Resolve "assigned=me" to the current user for active-view comparison/links
function ticketSavedViewQueryForUser($query, $session_user_id) {
    return str_replace('assigned=me', 'assigned=' . $session_user_id, $query);
}

// Is the given saved-view query the one currently active?
function ticketSavedViewIsActive($query, $session_user_id) {
    $resolved = ticketSavedViewQueryForUser($query, $session_user_id);
    parse_str($resolved, $view_params);

    // Compare every relevant filter key, not just the ones present in the
    // view's query - otherwise a view like "All Unresolved" (status=Open)
    // would also light up whenever any other view that also sets
    // status=Open (e.g. "Assigned to me") is active.
    foreach (['status', 'assigned', 'onsite', 'board', 'category', 'priority'] as $key) {
        $current = isset($_GET[$key]) && $_GET[$key] !== '' ? (string)$_GET[$key] : null;
        $expected = isset($view_params[$key]) && $view_params[$key] !== '' ? (string)$view_params[$key] : null;
        if ($current !== $expected) return false;
    }
    return true;
}

$sql_categories_filter = mysqli_query(
    $mysqli,
    "SELECT * FROM categories
    WHERE category_type = 'Ticket'
    AND category_archived_at IS NULL
    ORDER BY category_name"
);

$sql_boards_filter = mysqli_query(
    $mysqli,
    "SELECT * FROM categories
    WHERE category_type = 'Ticket'
    AND category_parent = 0
    AND category_archived_at IS NULL
    ORDER BY category_name"
);

$sql_ticket_status_pill = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order");

$sql_ticket_tags_filter = mysqli_query($mysqli, "SELECT * FROM tags WHERE tag_type = 6 ORDER BY tag_name ASC");

?>
    <style>
        .popover {
            max-width: 600px;
        }
        .ticket-stat-box {
            display: flex;
            align-items: center;
            gap: .75rem;
            text-align: left;
            border-radius: var(--input-radius);
            padding: .6rem .85rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-left: 4px solid var(--stat-color, var(--color-accent));
            color: var(--color-text);
            box-shadow: var(--card-shadow);
            transition: transform .1s ease-out, box-shadow .1s ease-out;
        }
        .ticket-stat-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 14px -4px rgba(16,24,40,.18);
            text-decoration: none;
            color: var(--color-text);
        }
        .ticket-stat-box.active {
            background: var(--stat-color, var(--color-accent));
            border-color: var(--stat-color, var(--color-accent));
            color: #fff;
        }
        .ticket-stat-box .ticket-stat-icon {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 2.1rem;
            height: 2.1rem;
            border-radius: 50%;
            font-size: 1rem;
            background: rgba(var(--stat-color-rgb, var(--color-accent-rgb)), .12);
            color: var(--stat-color, var(--color-accent));
        }
        .ticket-stat-box.active .ticket-stat-icon {
            background: rgba(255,255,255,.2);
            color: #fff;
        }
        .ticket-stat-box .ticket-stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            display: block;
            line-height: 1.15;
        }
        .ticket-stat-box .ticket-stat-label {
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--color-text-muted);
        }
        .ticket-stat-box.active .ticket-stat-label {
            color: rgba(255,255,255,.85);
        }
        .ticket-saved-views .nav-link {
            color: inherit;
            border-radius: .25rem;
            padding: .4rem .6rem;
        }
        .ticket-saved-views .nav-link.active {
            background: var(--accent, #2563eb);
            color: #fff;
        }
        .ticket-saved-views .nav-link:hover {
            background: rgba(0,0,0,.15);
        }
        .ticket-saved-views .saved-view-actions {
            opacity: 0;
        }
        .ticket-saved-views .nav-item:hover .saved-view-actions {
            opacity: 1;
        }
        .table-tight td, .table-tight th {
            padding: .35rem .5rem;
        }
    </style>
    <div class="row">
        <?php if (!$client_url) { ?>
        <div class="col-lg-2 mb-3">
            <div class="card card-dark h-100">
                <div class="card-header py-2 d-flex align-items-center justify-content-between">
                    <h3 class="card-title mt-0 mb-0"><i class="fa fa-fw fa-thumbtack me-2"></i>Ticket Views</h3>
                    <button type="button" class="btn btn-sm btn-outline-secondary ajax-modal" title="Save current view"
                        data-modal-url="modals/ticket/ticket_saved_view_add.php?<?= htmlspecialchars(http_build_query($_GET)) ?>">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
                <div class="card-body ticket-saved-views py-2">
                    <ul class="nav nav-pills flex-column">
                        <?php foreach ($_saved_views as $_view) {
                            $_view_id = intval($_view['ticket_saved_view_id']);
                            $_view_name = nullable_htmlentities($_view['ticket_saved_view_name']);
                            $_view_icon = nullable_htmlentities($_view['ticket_saved_view_icon']) ?: 'fa-filter';
                            $_view_query = ticketSavedViewQueryForUser($_view['ticket_saved_view_query'], $session_user_id);
                            $_view_active = ticketSavedViewIsActive($_view['ticket_saved_view_query'], $session_user_id);
                            $_view_owner = intval($_view['ticket_saved_view_user_id']);
                            $_can_edit = ($_view_owner == $session_user_id) || ($_view_owner == 0 && lookupUserPermission("module_support") === 3);
                        ?>
                        <li class="nav-item d-flex align-items-center">
                            <a class="nav-link flex-grow-1 <?= $_view_active ? 'active' : '' ?>" href="?<?= htmlspecialchars($_view_query, ENT_QUOTES) ?>">
                                <i class="fas fa-fw <?= $_view_icon ?> me-2"></i><?= $_view_name ?>
                            </a>
                            <?php if ($_can_edit) { ?>
                            <div class="dropdown saved-view-actions">
                                <button class="btn btn-sm btn-link text-secondary p-0 px-1" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_up=<?= $_view_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-arrow-up me-2"></i>Move Up</a>
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_down=<?= $_view_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-arrow-down me-2"></i>Move Down</a>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_saved_view_edit.php?id=<?= $_view_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Rename</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ticket_saved_view=<?= $_view_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                                </div>
                            </div>
                            <?php } ?>
                        </li>
                        <?php } ?>
                    </ul>

                    <?php if (mysqli_num_rows($sql_boards_filter)) {
                        mysqli_data_seek($sql_boards_filter, 0);
                    ?>
                    <hr>
                    <h6 class="text-secondary text-uppercase" style="font-size:.7rem;">Boards</h6>
                    <ul class="nav nav-pills flex-column">
                        <?php while ($_board = mysqli_fetch_assoc($sql_boards_filter)) {
                            $_board_id = intval($_board['category_id']);
                            $_board_name = nullable_htmlentities($_board['category_name']);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($board_filter == $_board_id) ? 'active' : '' ?>" href="?<?= $client_url ?>board=<?= $_board_id ?>&status=Open">
                                <i class="fas fa-fw fa-layer-group me-2"></i><?= $_board_name ?>
                            </a>
                        </li>
                        <?php } ?>
                    </ul>
                    <?php mysqli_data_seek($sql_boards_filter, 0); } ?>
                </div>
            </div>
        </div>
        <?php } ?>
        <div class="<?= $client_url ? 'col-12' : 'col-lg-10' ?>">

    <div class="row mb-3">
        <?php
        $_stats = [
            ['label' => 'All Tickets',  'value' => $total_tickets_all,           'href' => '?' . $client_url,                                            'icon' => 'fa-list', 'color' => '#64748B', 'rgb' => '100,116,139'],
            ['label' => 'Unassigned',   'value' => $total_tickets_unassigned,    'href' => '?' . $client_url . 'assigned=unassigned',                    'icon' => 'fa-user-slash', 'color' => '#F59E0B', 'rgb' => '245,158,11'],
            ['label' => 'Unresolved',   'value' => $total_tickets_open,          'href' => '?' . $client_url . 'status=Open',                            'icon' => 'fa-exclamation-circle', 'color' => '#3B82F6', 'rgb' => '59,130,246'],
            ['label' => 'Due Today',    'value' => $total_tickets_due_today,     'href' => '?' . $client_url . 'status=Open&due_today=1', 'active' => isset($_GET['due_today']), 'icon' => 'fa-clock', 'color' => '#D97706', 'rgb' => '217,119,6'],
            ['label' => 'Overdue',      'value' => $total_tickets_overdue,       'href' => '?' . $client_url . 'status=Open&overdue=1', 'active' => isset($_GET['overdue']), 'icon' => 'fa-fire', 'color' => '#EF4444', 'rgb' => '239,68,68'],
            ['label' => 'On-Site Open', 'value' => $total_tickets_onsite_open,   'href' => '?' . $client_url . 'status=Open&onsite=1', 'active' => $onsite_filter === 1, 'icon' => 'fa-map-marker-alt', 'color' => '#8B5CF6', 'rgb' => '139,92,246'],
            ['label' => 'My Active',    'value' => $user_active_assigned_tickets,'href' => '?' . $client_url . 'status=Open&assigned=' . $session_user_id, 'icon' => 'fa-user-check', 'color' => 'var(--color-accent)', 'rgb' => 'var(--color-accent-rgb)'],
        ];
        foreach ($_stats as $_stat) {
        ?>
        <div class="col">
            <a class="ticket-stat-box <?= !empty($_stat['active']) ? 'active' : '' ?>" href="<?= $_stat['href'] ?>" style="--stat-color: <?= $_stat['color'] ?>; --stat-color-rgb: <?= $_stat['rgb'] ?>;">
                <span class="ticket-stat-icon"><i class="fas fa-fw <?= $_stat['icon'] ?>"></i></span>
                <span>
                    <span class="ticket-stat-value"><?= $_stat['value'] ?></span>
                    <span class="ticket-stat-label"><?= $_stat['label'] ?></span>
                </span>
            </a>
        </div>
        <?php } ?>
    </div>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fa fa-fw fa-life-ring me-2"></i>Tickets
                <small class="ms-3">
                    <a href="?<?= $client_url ?>status=Open" class="badge rounded-pill p-1 <?= $status == 'Open' ? 'text-bg-light text-dark' : 'text-bg-secondary text-light' ?>"><strong><?= $total_tickets_open ?></strong> Open</a> |
                    <a href="?<?= $client_url ?>status=Closed" class="badge rounded-pill p-1 <?= $status == 'Closed' ? 'text-bg-light text-dark' : 'text-bg-secondary text-light' ?>"><strong><?= $total_tickets_closed ?></strong> Closed</a>
                </small>
            </h3>
            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                <div class="card-tools">
                    <div class="btn-group">
                        <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/ticket/ticket_add_v2.php?<?= $client_url ?>" data-modal-size="lg">
                            <i class="fas fa-plus"></i><span class="d-none d-lg-inline ms-2">New Ticket</span>
                        </button>
                        <?php if ($num_rows[0] > 0) { ?>
                        <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item text-dark ajax-modal" href="#"
                                data-modal-url="modals/ticket/ticket_export.php?<?= $client_url ?>">
                                <i class="fa fa-fw fa-download me-2"></i>Export
                            </a>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <form autocomplete="off" class="ticket-filter-bar">
                <?php if ($client_url) { ?>
                    <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <?php } ?>
                <input type="hidden" name="view" value="<?= nullable_htmlentities($_GET['view'] ?? 'list') ?>">
                <?php if ($onsite_filter !== '') { ?>
                <input type="hidden" name="onsite" value="<?= intval($onsite_filter) ?>">
                <?php } ?>
                <?php if (isset($_GET['overdue'])) { ?>
                <input type="hidden" name="overdue" value="1">
                <?php } ?>
                <?php if (isset($_GET['due_today'])) { ?>
                <input type="hidden" name="due_today" value="1">
                <?php } ?>

                <div class="filter-toolbar">
                <div class="row align-items-center filter-row-nowrap">
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="board" data-placeholder="Board" style="width:150px;">
                            <option value="">- All Boards -</option>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_boards_filter)) {
                                $board_id = intval($row['category_id']);
                                $board_name = nullable_htmlentities($row['category_name']);
                            ?>
                                <option value="<?= $board_id ?>" <?= $board_filter == $board_id ? 'selected' : '' ?>><?= $board_name ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="category" data-placeholder="Category" style="width:170px;">
                            <option value="">- All Categories -</option>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_categories_filter)) {
                                $category_id = intval($row['category_id']);
                                $category_name = nullable_htmlentities($row['category_name']);
                            ?>
                                <option <?php if ($category_filter == $category_id) { echo "selected"; } ?> value="<?php echo $category_id; ?>"><?php echo $category_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="status" data-placeholder="Status" style="width:140px;">
                            <option value="Open" <?= $status === 'Open' ? 'selected' : '' ?>>All Open</option>
                            <option value="Closed" <?= $status === 'Closed' ? 'selected' : '' ?>>All Closed</option>
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_ticket_status_pill)) {
                                $ticket_status_id = intval($row['ticket_status_id']);
                                $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                            ?>
                                <option value="<?= $ticket_status_id ?>" <?= $status_filter_id == $ticket_status_id ? 'selected' : '' ?>><?= $ticket_status_name ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="priority" id="priorityFilterSelect" data-placeholder="Priority" style="width:150px;">
                            <option value="">All Priorities</option>
                            <option value="High" data-dot="high" <?= $priority_filter === 'High' ? 'selected' : '' ?>>High</option>
                            <option value="Medium" data-dot="medium" <?= $priority_filter === 'Medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="Low" data-dot="low" <?= $priority_filter === 'Low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="col-auto mb-2" style="width:220px;">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search tickets...">
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="tags[]" data-placeholder="Tags Filter" multiple style="width:160px;">
                            <?php
                            while ($row = mysqli_fetch_assoc($sql_ticket_tags_filter)) {
                                $tag_id = intval($row['tag_id']);
                                $tag_name = nullable_htmlentities($row['tag_name']);
                            ?>
                                <option value="<?php echo $tag_id; ?>" <?php if (in_array($tag_id, $ticket_tag_filter)) { echo "selected"; } ?>><?php echo $tag_name; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto mb-2">
                        <a href="?<?= $client_url ?>" class="btn btn-outline-secondary"><i class="fas fa-undo me-1"></i>Reset Filters</a>
                    </div>
                    <div class="col-auto mb-2">
                        <button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilter"><i class="fas fa-sliders-h me-1"></i>More Filters</button>
                    </div>
                </div>
                </div>

                <div class="row mt-1">
                    <div class="col-12">
                        <div class="btn-group">
                            <?php if (lookupUserPermission("module_support") >= 2) { ?>
                                <div class="dropdown ms-2" id="bulkActionButton" hidden>
                                    <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                        <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_assign.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-user-check me-2"></i>Assign Agent
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_edit_category.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-layer-group me-2"></i>Set Category
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_edit_priority.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-thermometer-half me-2"></i>Set Priority
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_reply.php"
                                            data-modal-size="lg"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-paper-plane me-2"></i>Update/Reply
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_add_project.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-project-diagram me-2"></i>Set Project
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_merge.php"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-clone me-2"></i>Merge
                                        </a>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item ajax-modal" href="#"
                                            data-modal-url="modals/ticket/ticket_bulk_resolve.php"
                                            data-modal-size="lg"
                                            data-bulk="true">
                                            <i class="fas fa-fw fa-check me-2"></i>Resolve
                                        </a>
                                        <?php if (lookupUserPermission("module_support") === 3) { ?>
                                        <div class="dropdown-divider"></div>
                                        <button class="dropdown-item text-danger text-bold confirm-link" type="submit" form="bulkActions" name="bulk_delete_tickets">
                                            <i class="fas fa-fw fa-trash me-2"></i>Delete
                                        </button>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>

                <div
                    class="collapse mt-3
                        <?php
                        if (isset($_GET['dtf']) && $_GET['dtf'] !== '1970-01-01'
                            || (isset($_GET['assigned']) && $_GET['assigned']
                        ))
                            { echo "show"; }
                        ?>"
                    id="advancedFilter"
                >
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Date range</label>
                                <input type="text" id="dateFilter" class="form-control" autocomplete="off">
                                <input type="hidden" name="canned_date" id="canned_date" value="<?php echo nullable_htmlentities($_GET['canned_date']) ?? ''; ?>">
                                <input type="hidden" name="dtf" id="dtf" value="<?php echo nullable_htmlentities($dtf ?? ''); ?>">
                                <input type="hidden" name="dtt" id="dtt" value="<?php echo nullable_htmlentities($dtt ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Assigned to</label>
                                <select class="form-control select2 auto-submit-select" name="assigned">
                                    <option value="" <?php if ($ticket_assigned_filter_id == "") { echo "selected"; } ?>>Any</option>
                                    <option value="unassigned" <?php if ($ticket_assigned_filter_id == "0") { echo "selected"; } ?>>Unassigned</option>

                                    <?php
                                    $sql_assign_to = mysqli_query($mysqli, "SELECT * FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_assign_to)) {
                                        $user_id = intval($row['user_id']);
                                        $user_name = nullable_htmlentities($row['user_name']);
                                        ?>
                                        <option <?php if ($ticket_assigned_filter_id == $user_id) { echo "selected"; } ?> value="<?php echo $user_id; ?>"><?php echo $user_name; ?></option>
                                        <?php
                                    }
                                    ?>

                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Project</label>
                                <select class="form-control select2 auto-submit-select" name="project">
                                    <option value="" <?php if ($ticket_project_filter_id == "") { echo "selected"; } ?>>Any</option>

                                    <?php
                                    $sql_projects = mysqli_query($mysqli, "SELECT * FROM projects WHERE project_completed_at IS NULL and project_archived_at IS NULL ORDER BY project_name ASC");
                                    while ($row = mysqli_fetch_assoc($sql_projects)) {
                                        $project_id = intval($row['project_id']);
                                        $project_prefix = nullable_htmlentities($row['project_prefix']);
                                        $project_number = intval($row['project_number']);
                                        $project_name = nullable_htmlentities($row['project_name']);
                                        ?>
                                        <option <?php if ($ticket_project_filter_id == $project_id) { echo "selected"; } ?> value="<?php echo $project_id; ?>"><?php echo $project_prefix . $project_number . " - " . $project_name; ?></option>
                                        <?php
                                    }
                                    ?>

                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php

if (isset($_GET["view"])) {
    if ($_GET["view"] == "list") {
        require_once "ticket_list.php";
    } elseif ($_GET["view"] == "kanban") {
        require_once "ticket_kanban.php";
    }
} else {
    // here we have to get default view setting
    if ($config_ticket_default_view === 0) {
        require_once "ticket_list.php";
    } elseif ($config_ticket_default_view === 2) {
        require_once "ticket_kanban.php";
    } else {
        require_once "ticket_list.php";
    }
}

?>
        </div>
    </div>

<script src="../js/bulk_actions.js"></script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function () {
    if ($.fn.select2) {
        $('#priorityFilterSelect').select2({
            templateResult: formatPriorityOption,
            templateSelection: formatPriorityOption
        });
    }

    function formatPriorityOption(option) {
        var dot = $(option.element).data('dot');
        if (!dot) {
            return option.text;
        }
        return $('<span><span class="priority-dot priority-dot-' + dot + '"></span>' + option.text + '</span>');
    }
});
</script>

<?php
require_once "../includes/footer.php";
