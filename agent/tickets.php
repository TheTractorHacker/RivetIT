<?php

// Marks ticket_list.php as reached through this bootstrapped page, not
// requested directly (it has no session/$mysqli setup of its own).
define('FROM_TICKETS_PAGE', true);

// Default Column Sortby Filter
$sort = "ticket_number";
$order = "DESC";

// The user's default saved view: opening Tickets with no filter of its own loads it (an explicit filter in the URL always wins).
// This has to happen before the page shell reads the request, so the bootstrap files it needs are loaded first; they are
// require_once, so the shell below does not load them twice.
$ticket_default_view = null;
if (!isset($_GET['client_id'])) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
    require_once __DIR__ . '/includes/ticket_view_filters.php';
    if (lookupUserPermission('module_support') >= 1) {
        $ticket_default_view = ticketViewApplyDefault($mysqli, (int) $session_user_id, $_GET);
        if ($ticket_default_view) {
            $_REQUEST = $_GET + $_REQUEST;
        }
    }
}

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

// Date range + which ticket date it applies to (Created by default; Updated / Resolved / Closed / Due / SLA due).
// $date_range comes from includes/filter_header.php (RivetCore DateRange); the column is whitelisted, the bounds are validated dates.
$ticket_date_field = ticketDateFieldFromRequest($_GET);
$ticket_date_query = dateRangeSqlBetween(ticketDateFields()[$ticket_date_field]['column'], $date_range);

// Ticket status from GET
$status_filter_id = '';
$status_list = isset($_GET['status']) && is_array($_GET['status']) ? ticketFilterIds($_GET['status']) : [];
if ($status_list) {
    // Several specific statuses (validated positive integers, capped)
    $ticket_status_snippet = 'ticket_status IN (' . implode(',', $status_list) . ')';
    $status = '';
    $status_filter_id = $status_list[0];

} elseif (isset($_GET['status']) && is_string($_GET['status']) && ctype_digit($_GET['status'])) {
    // Single status selected from the Status filter pill
    $status_filter_id = intval($_GET['status']);
    $status = '';
    $ticket_status_snippet = "ticket_status = $status_filter_id";
} else {

    // TODO: Convert this to use the status IDs
    if (isset($_GET['status']) && $_GET['status'] === 'Closed') {
        $status = 'Closed';
        $ticket_status_snippet = "ticket_resolved_at IS NOT NULL";
    } elseif (isset($_GET['status']) && $_GET['status'] === 'All') {
        // "All Tickets" stat box - genuinely no status filter, open or closed
        $status = 'All';
        $ticket_status_snippet = '1 = 1';
    } elseif (!isset($_GET['status']) && !$date_range->isAllTime() && in_array($ticket_date_field, ['resolved', 'closed'], true)) {
        // Filtering by when a ticket was resolved/closed only makes sense across resolved tickets: don't hide them behind the open-only default.
        $status = 'All';
        $ticket_status_snippet = '1 = 1';
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

// Multi-value filters (assigned to, priority, board, category, on-site/remote, due): any mix of single values (old links and
// saved views) and lists (assigned[]=me&assigned[]=7). OR inside a group, AND across groups; see includes/ticket_filter_sets.php.
$filter_sets = ticketFilterSets($_GET, (int) $session_user_id);
$filter_sql = ticketFilterSql($filter_sets);
$category_query = $filter_sql['category'];
$board_query = $filter_sql['board'];
$priority_query = $filter_sql['priority'];
$onsite_query = $filter_sql['onsite'];
$stat_query = $filter_sql['due'];
$ticket_assigned_query = $filter_sql['assigned'];
$category_filter = $filter_sets['category'];
$board_filter = $filter_sets['board'];
$priority_filter = $filter_sets['priority'];
$onsite_filter = $filter_sets['onsite'];
// "Only one choice" values the stat tiles compare against ('' = not filtering by that)
$onsite_single = count($onsite_filter) === 1 ? $onsite_filter[0] : '';
$ticket_assigned_filter_ids = $filter_sets['assigned'];
$ticket_assigned_filter_id = count($ticket_assigned_filter_ids) === 1 ? $ticket_assigned_filter_ids[0] : ($ticket_assigned_filter_ids ? 'many' : '');

// Project filter
// Default - any (including tickets without a project)
$ticket_project_snippet = '';
$ticket_project_filter_id = '';
if (isset($_GET['project']) && is_scalar($_GET['project']) && intval($_GET['project']) > 0) {
    $ticket_project_snippet = 'AND ticket_project_id = ' . intval($_GET['project']);
    $ticket_project_filter_id = intval($_GET['project']);
}

// Tags Filter
if (isset($_GET['tags']) && is_array($_GET['tags']) && !empty($_GET['tags'])) {
    $ticket_tag_filter = ticketFilterIds($_GET['tags']);
    $ticket_tag_filter_string = implode(",", $ticket_tag_filter ?: [0]);
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
    AND $ticket_date_query
    AND (CONCAT(ticket_prefix,ticket_number) LIKE '%$q%' OR client_name LIKE '%$q%' OR ticket_subject LIKE '%$q%' OR ticket_status_name LIKE '%$q%' OR ticket_priority LIKE '%$q%' OR user_name LIKE '%$q%' OR contact_name LIKE '%$q%' OR asset_name LIKE '%$q%' OR vendor_name LIKE '%$q%' OR ticket_vendor_ticket_number LIKE '%$q%')
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

// Default view: which saved views are this user's own default / the shared default, and whether the user may set the shared one.
$_default_available = ticketViewDefaultsAvailable($mysqli);
$_default_ids = ticketViewDefaultIds($mysqli, (int) $session_user_id);
$_can_share_default = lookupUserPermission("module_support") === 3;
$_effective_default = $_default_available && !$client_url ? ticketViewDefaultFor($mysqli, (int) $session_user_id) : null;

// Is the given saved-view query the one currently active?
function ticketSavedViewIsActive($query, $session_user_id) {
    $view_params = ticketViewParseQuery((string) $query);

    // Compare every relevant filter, not just the ones present in the view's query - otherwise a view like "All Unresolved"
    // (status=Open) would also light up whenever any other view that also sets status=Open (e.g. "Assigned to me") is active.
    // Lists (several statuses, priorities, agents...) compare by content, not order, and "me" equals the viewer's own id.
    if (ticketFilterSignature($_GET, (int) $session_user_id) !== ticketFilterSignature($view_params, (int) $session_user_id)) return false;
    // Date range (resolved, so a rolling "last7" view and the page showing last7 match) and the date field it applies to
    global $date_range;
    if (dateRangeFromRequest($view_params)->toQuery() !== $date_range->toQuery()) return false;
    if (ticketDateFieldFromRequest($view_params) !== ticketDateFieldFromRequest($_GET)) return false;
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
        /* Stat tiles.
           Grid (not .row/.col) so every tile in a row shares one row height - the
           old flex row let each tile size itself and the bottom edge came out
           saw-toothed whenever a label wrapped. Column count is stepped by
           breakpoint rather than auto-fit so the seven tiles never leave a hole. */
        .ticket-stat-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .75rem;
        }
        @media (min-width: 576px) {
            .ticket-stat-row { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        @media (min-width: 1200px) {
            .ticket-stat-row { grid-template-columns: repeat(7, minmax(0, 1fr)); }
        }
        .ticket-stat-box {
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: .3rem;
            min-width: 0;
            text-align: left;
            text-decoration: none;
            border-radius: var(--input-radius);
            padding: .6rem .7rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-left: 3px solid var(--stat-color, var(--color-accent));
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
        /* Selected tile: a tint of its own hue plus a full-colour ring. A solid
           fill could not carry legible text - white on the lighter stat hues
           measured 3.1:1 - so the label stays ink on a light ground. */
        .ticket-stat-box.active {
            background: rgba(var(--stat-color-rgb, var(--color-accent-rgb)), .10);
            border-color: var(--stat-color, var(--color-accent));
            box-shadow: inset 0 0 0 1px var(--stat-color, var(--color-accent));
            color: var(--color-text);
        }
        .ticket-stat-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .4rem;
            min-width: 0;
        }
        .ticket-stat-box .ticket-stat-icon {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 50%;
            font-size: .8rem;
            background: rgba(var(--stat-color-rgb, var(--color-accent-rgb)), .12);
            color: var(--stat-color, var(--color-accent));
        }
        .ticket-stat-box.active .ticket-stat-icon {
            background: rgba(var(--stat-color-rgb, var(--color-accent-rgb)), .22);
            color: var(--stat-color, var(--color-accent));
        }
        .ticket-stat-box .ticket-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
            text-align: right;
            min-width: 0;
        }
        .ticket-stat-box .ticket-stat-label {
            display: block;
            min-width: 0;
            font-size: .6875rem;
            line-height: 1.25;
            text-transform: uppercase;
            letter-spacing: .04em;
            overflow-wrap: break-word;
            color: var(--color-text-muted);
        }
        .ticket-stat-box.active .ticket-stat-label {
            color: var(--color-text);
            font-weight: 600;
        }
        /* Open/Closed counts in the card header. Selected is the filled chip;
           the old markup had that backwards (and its --tblr-light fill vanished
           against the card in dark mode). */
        .ticket-count-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            font-size: .75rem;
            font-weight: 500;
            line-height: 1.2;
            padding: .22rem .6rem;
            border: 1px solid var(--color-border);
            border-radius: 999px;
            background: var(--color-surface);
            color: var(--color-text-muted);
            text-decoration: none;
        }
        .ticket-count-pill:hover {
            color: var(--color-text);
            border-color: var(--color-text-muted);
            text-decoration: none;
        }
        .ticket-count-pill.active {
            background: var(--color-text);
            border-color: var(--color-text);
            color: var(--color-surface);
        }
        .ticket-views-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            padding: .5rem .75rem;
        }
        .ticket-views-header .card-title {
            min-width: 0;
            font-size: .8rem;
            font-weight: 600;
        }
        .ticket-views-header .btn-add-view {
            flex: 0 0 auto;
            width: 2rem;
            height: 2rem;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        /* Saved views: a flex row per item so a wrapped label keeps its own
           column and the icon stays a fixed-width cell on the first line
           instead of being flex-shrunk into the gutter. */
        .ticket-saved-views .nav-link {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            color: inherit;
            border-radius: var(--input-radius);
            padding: .35rem .5rem;
            line-height: 1.35;
        }
        .ticket-saved-views .nav-link > i {
            flex: 0 0 1.05rem;
            width: 1.05rem;
            text-align: center;
            line-height: 1.35;
            opacity: .75;
        }
        .ticket-saved-views .nav-link > span {
            min-width: 0;
            overflow-wrap: break-word;
        }
        .ticket-saved-views .nav-link.active {
            background: var(--color-accent-soft);
            color: var(--color-text);
            font-weight: 600;
            box-shadow: inset 3px 0 0 var(--color-accent);
        }
        .ticket-saved-views .nav-link.active > i {
            color: var(--color-accent);
            opacity: 1;
        }
        .ticket-saved-views .nav-link:hover:not(.active) {
            background: var(--color-accent-soft);
            color: var(--color-text);
        }
        .ticket-saved-views .saved-view-actions {
            opacity: 0;
        }
        .ticket-saved-views .nav-item:hover .saved-view-actions {
            opacity: 1;
        }
        .ticket-views-group-label {
            margin: .85rem .5rem .35rem;
            font-size: .6875rem;
            font-weight: 600;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--color-text-muted);
        }
        .table-tight td, .table-tight th {
            padding: .35rem .5rem;
        }
    </style>
    <div class="row">
        <?php if (!$client_url) { ?>
        <div class="col-lg-2 mb-3 mb-lg-0">
            <div class="card card-dark h-100">
                <div class="card-header ticket-views-header">
                    <h3 class="card-title mt-0 mb-0">Ticket Views</h3>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-add-view ajax-modal" title="Save current view"
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
                            $_view_query = (string) $_view['ticket_saved_view_query'];
                            $_view_active = ticketSavedViewIsActive($_view['ticket_saved_view_query'], $session_user_id);
                            $_view_owner = intval($_view['ticket_saved_view_user_id']);
                            $_can_edit = ($_view_owner == $session_user_id) || ($_view_owner == 0 && lookupUserPermission("module_support") === 3);
                            $_is_personal_default = $_default_ids['personal'] === $_view_id;
                            $_is_shared_default = $_default_ids['shared'] === $_view_id;
                            $_csrf = $_SESSION['csrf_token'];
                        ?>
                        <li class="nav-item d-flex align-items-center">
                            <a class="nav-link flex-grow-1 <?= $_view_active ? 'active' : '' ?>" href="?<?= htmlspecialchars($_view_query, ENT_QUOTES) ?>">
                                <i class="fas fa-fw <?= $_view_icon ?>"></i><span><?= $_view_name ?><?php if ($_is_shared_default) { ?> <span class="badge bg-secondary-subtle text-secondary-emphasis ms-1" title="The default for everyone who has not chosen their own">Default for all</span><?php } ?></span>
                            </a>
                            <?php if ($_default_available) { ?>
                            <a class="saved-view-star <?= $_is_personal_default ? 'is-default' : '' ?>"
                               href="post.php?<?= $_is_personal_default ? 'clear' : 'set' ?>_ticket_view_default=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"
                               title="<?= $_is_personal_default ? 'Your default view: click to clear' : 'Make this my default view' ?>"
                               aria-label="<?= $_is_personal_default ? 'Clear my default view (currently ' . $_view_name . ')' : 'Make ' . $_view_name . ' my default view' ?>">
                                <i class="<?= $_is_personal_default ? 'fas' : 'far' ?> fa-fw fa-star"></i>
                            </a>
                            <?php } ?>
                            <?php if ($_can_edit || ($_default_available && $_can_share_default && $_view_owner == 0)) { ?>
                            <div class="dropdown saved-view-actions">
                                <button class="btn btn-sm btn-link text-secondary p-0 px-1" type="button" data-bs-toggle="dropdown" aria-label="Actions for <?= $_view_name ?>">
                                    <i class="fas fa-fw fa-ellipsis-v"></i>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right">
                                    <?php if ($_default_available && $_can_share_default && $_view_owner == 0) { ?>
                                    <?php if ($_is_shared_default) { ?>
                                    <a class="dropdown-item" href="post.php?clear_ticket_view_default=<?= $_view_id ?>&scope=shared&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-star-half-alt me-2"></i>Remove default for everyone</a>
                                    <?php } else { ?>
                                    <a class="dropdown-item" href="post.php?set_ticket_view_default=<?= $_view_id ?>&scope=shared&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-star-half-alt me-2"></i>Make default for everyone</a>
                                    <?php } ?>
                                    <?php } ?>
                                    <?php if ($_can_edit) { ?>
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_up=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-arrow-up me-2"></i>Move Up</a>
                                    <a class="dropdown-item" href="post.php?move_ticket_saved_view_down=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-arrow-down me-2"></i>Move Down</a>
                                    <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/ticket/ticket_saved_view_edit.php?id=<?= $_view_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_ticket_saved_view=<?= $_view_id ?>&csrf_token=<?= $_csrf ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                                    <?php } ?>
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
                    <div class="ticket-views-group-label">Boards</div>
                    <ul class="nav nav-pills flex-column">
                        <?php while ($_board = mysqli_fetch_assoc($sql_boards_filter)) {
                            $_board_id = intval($_board['category_id']);
                            $_board_name = nullable_htmlentities($_board['category_name']);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link <?= ($board_filter === [$_board_id]) ? 'active' : '' ?>" href="?<?= $client_url ?>board=<?= $_board_id ?>&status=Open">
                                <i class="fas fa-fw fa-layer-group"></i><span><?= $_board_name ?></span>
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

    <div class="ticket-stat-row mb-3">
        <?php
        $_stats = [
            ['label' => 'All Tickets',  'value' => $total_tickets_all,           'href' => '?' . $client_url . 'status=All', 'active' => $status === 'All', 'icon' => 'fa-list', 'color' => '#64748B', 'rgb' => '100,116,139'],
            ['label' => 'Unassigned',   'value' => $total_tickets_unassigned,    'href' => '?' . $client_url . 'assigned=unassigned', 'active' => $ticket_assigned_filter_id === 0, 'icon' => 'fa-user-slash', 'color' => '#F59E0B', 'rgb' => '245,158,11'],
            ['label' => 'Unresolved',   'value' => $total_tickets_open,          'href' => '?' . $client_url . 'status=Open', 'active' => $status === 'Open' && !isset($_GET['due_today']) && !isset($_GET['overdue']) && $onsite_single !== 1 && $ticket_assigned_filter_id === '', 'icon' => 'fa-exclamation-circle', 'color' => '#3B82F6', 'rgb' => '59,130,246'],
            ['label' => 'Due Today',    'value' => $total_tickets_due_today,     'href' => '?' . $client_url . 'status=Open&due_today=1', 'active' => isset($_GET['due_today']), 'icon' => 'fa-clock', 'color' => '#D97706', 'rgb' => '217,119,6'],
            ['label' => 'Overdue',      'value' => $total_tickets_overdue,       'href' => '?' . $client_url . 'status=Open&overdue=1', 'active' => isset($_GET['overdue']), 'icon' => 'fa-fire', 'color' => '#EF4444', 'rgb' => '239,68,68'],
            ['label' => 'On-Site Open', 'value' => $total_tickets_onsite_open,   'href' => '?' . $client_url . 'status=Open&onsite=1', 'active' => $onsite_single === 1, 'icon' => 'fa-map-marker-alt', 'color' => '#8B5CF6', 'rgb' => '139,92,246'],
            ['label' => 'My Active',    'value' => $user_active_assigned_tickets,'href' => '?' . $client_url . 'status=Open&assigned=' . $session_user_id, 'active' => $ticket_assigned_filter_id === (int) $session_user_id, 'icon' => 'fa-user-check', 'color' => 'var(--color-accent)', 'rgb' => 'var(--color-accent-rgb)'],
        ];
        foreach ($_stats as $_stat) {
        ?>
        <a class="ticket-stat-box <?= !empty($_stat['active']) ? 'active' : '' ?>" href="<?= $_stat['href'] ?>" style="--stat-color: <?= $_stat['color'] ?>; --stat-color-rgb: <?= $_stat['rgb'] ?>;">
            <span class="ticket-stat-head">
                <span class="ticket-stat-icon"><i class="fas fa-fw <?= $_stat['icon'] ?>"></i></span>
                <span class="ticket-stat-value"><?= $_stat['value'] ?></span>
            </span>
            <span class="ticket-stat-label"><?= $_stat['label'] ?></span>
        </a>
        <?php } ?>
    </div>

    <?php if ($ticket_default_view) { ?>
    <div class="alert alert-info py-2 d-flex flex-wrap align-items-center gap-2 mb-3 tv-default-banner" role="status">
        <i class="fas fa-star" aria-hidden="true"></i>
        <span>Showing <?= $ticket_default_view['default_source'] === 'shared' ? 'the default view' : 'your default view' ?> <strong><?= nullable_htmlentities($ticket_default_view['ticket_saved_view_name']) ?></strong>
            <span class="text-muted">(<?= nullable_htmlentities(ticketViewDescribe($mysqli, (string) $ticket_default_view['ticket_saved_view_query'])) ?>)</span></span>
        <a class="ms-auto" href="?status=Open">Show all open tickets</a>
    </div>
    <?php } elseif ($_effective_default && !$client_url) { ?>
    <div class="small text-muted mb-2 tv-default-hint">
        <i class="far fa-star me-1" aria-hidden="true"></i>Your default view is <strong><?= nullable_htmlentities($_effective_default['ticket_saved_view_name']) ?></strong>.
        <a href="?">Reset to my default</a>
    </div>
    <?php } ?>

    <div class="card card-dark">
        <div class="card-header py-2">
            <h3 class="card-title mt-2"><i class="fa fa-fw fa-life-ring me-2"></i>Tickets
                <span class="ms-3 d-inline-flex align-items-center" style="gap:.4rem;">
                    <a href="?<?= $client_url ?>status=Open" class="ticket-count-pill <?= $status == 'Open' ? 'active' : '' ?>"><strong><?= $total_tickets_open ?></strong> Open</a>
                    <a href="?<?= $client_url ?>status=Closed" class="ticket-count-pill <?= $status == 'Closed' ? 'active' : '' ?>"><strong><?= $total_tickets_closed ?></strong> Closed</a>
                </span>
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

                <div class="filter-toolbar">
                <div class="row align-items-center filter-row-nowrap">
                    <?php
                    // Multi-choice filters: each is a button that opens a checkbox list and applies when it closes.
                    $_opts_boards = [];
                    while ($row = mysqli_fetch_assoc($sql_boards_filter)) { $_opts_boards[(string) intval($row['category_id'])] = $row['category_name']; }
                    $_opts_categories = [];
                    while ($row = mysqli_fetch_assoc($sql_categories_filter)) { $_opts_categories[(string) intval($row['category_id'])] = $row['category_name']; }
                    $_opts_assigned = ['me' => 'Me', 'unassigned' => 'Unassigned'];
                    $sql_assign_to = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                    while ($row = mysqli_fetch_assoc($sql_assign_to)) {
                        // Yourself is the "Me" choice, not a second entry
                        if (intval($row['user_id']) !== (int) $session_user_id) { $_opts_assigned[(string) intval($row['user_id'])] = $row['user_name']; }
                    }
                    $_sel_assigned = [];
                    foreach ($ticket_assigned_filter_ids as $_aid) {
                        $_sel_assigned[] = $_aid === 0 ? 'unassigned' : ($_aid === (int) $session_user_id ? 'me' : (string) $_aid);
                    }
                    ?>
                    <div class="col-auto mb-2">
                        <?php ticketFilterCheckGroup('tvp_board', 'Board', 'board[]', $_opts_boards, array_map('strval', $board_filter), ['mode' => 'dropdown', 'autosubmit' => true, 'empty' => 'No boards yet']); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterCheckGroup('tvp_category', 'Category', 'category[]', $_opts_categories, array_map('strval', $category_filter), ['mode' => 'dropdown', 'autosubmit' => true, 'empty' => 'No categories yet']); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php
                        // Status: All open / All closed / Both, or any set of specific statuses (picking one switches off the other kind).
                        $_status_is_all = $status === 'Open' || $status === 'Closed' || $status === 'All';
                        $_sel_status_ids = $status_list ?: ($status_filter_id !== '' ? [(int) $status_filter_id] : []);
                        $_status_summary = $_sel_status_ids ? count($_sel_status_ids) : 0;
                        ?>
                        <div class="dropdown tv-ms" data-tv-group data-tv-autosubmit data-tv-exclusive>
                            <button class="btn btn-outline-secondary dropdown-toggle tv-ms-btn<?= $_status_summary ? ' tv-active' : '' ?>" type="button" id="tvp_status_btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                                <span data-tv-label><?= $_status_summary ? 'Status' : ($status === 'Closed' ? 'Closed' : ($status === 'All' ? 'Open + Closed' : 'Open')) ?></span> <span class="badge bg-primary rounded-pill tv-count" data-tv-count<?= $_status_summary ? '' : ' hidden' ?>><?= $_status_summary ?></span>
                            </button>
                            <div class="dropdown-menu tv-ms-menu p-2" aria-labelledby="tvp_status_btn">
                                <fieldset class="tv-fieldset mb-2">
                                    <legend class="tv-legend-sm">Show</legend>
                                    <?php foreach (['Open' => ['All open', 'Open'], 'Closed' => ['All closed', 'Closed'], 'All' => ['All (open + closed)', 'Open + Closed']] as $_sv => $_sl_pair) { $_sl = $_sl_pair[0]; ?>
                                        <label class="form-check tv-option"><input class="form-check-input" type="radio" name="status" value="<?= $_sv ?>" data-tv-label="<?= $_sl_pair[1] ?>" <?= $status === $_sv ? 'checked' : '' ?>> <span class="form-check-label"><?= $_sl ?></span></label>
                                    <?php } ?>
                                </fieldset>
                                <fieldset class="tv-fieldset">
                                    <legend class="tv-legend-sm">Or specific statuses</legend>
                                    <span class="tv-tools"><button type="button" class="btn btn-link btn-sm p-0" data-tv-all>Select all</button><span class="text-muted" aria-hidden="true"> · </span><button type="button" class="btn btn-link btn-sm p-0" data-tv-clear>Clear</button></span>
                                    <div class="tv-options">
                                    <?php
                                    $_si = 0;
                                    while ($row = mysqli_fetch_assoc($sql_ticket_status_pill)) {
                                        $ticket_status_id = intval($row['ticket_status_id']);
                                        $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                                        $_si++;
                                    ?>
                                        <label class="form-check tv-option" for="tvp_status_<?= $_si ?>"><input class="form-check-input" type="checkbox" id="tvp_status_<?= $_si ?>" name="status[]" value="<?= $ticket_status_id ?>" <?= in_array($ticket_status_id, $_sel_status_ids, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= $ticket_status_name ?></span></label>
                                    <?php } ?>
                                    </div>
                                </fieldset>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterCheckGroup('tvp_priority', 'Priority', 'priority[]', ['High' => 'High', 'Medium' => 'Medium', 'Low' => 'Low'], $priority_filter, ['mode' => 'dropdown', 'autosubmit' => true, 'dots' => ['High' => 'high', 'Medium' => 'medium', 'Low' => 'low']]); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <?php ticketFilterCheckGroup('tvp_assigned', 'Assigned to', 'assigned[]', $_opts_assigned, $_sel_assigned, ['mode' => 'dropdown', 'autosubmit' => true]); ?>
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
                        <?php echo dateRangePickerField($date_range, 'canned_date', ['id' => 'ticketDateRange', 'label' => 'Ticket date range']); ?>
                    </div>
                    <div class="col-auto mb-2">
                        <select class="form-control select2 auto-submit-select" name="datefield" data-placeholder="Date field" data-searchable="0" style="width:150px;" title="Which ticket date the range applies to" aria-label="Date field">
                            <?php foreach (ticketDateFields() as $df_key => $df) { ?>
                                <option value="<?= $df_key ?>" <?= $ticket_date_field === $df_key ? 'selected' : '' ?>><?= nullable_htmlentities($df['label']) ?></option>
                            <?php } ?>
                        </select>
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
                        <a href="?<?= $client_url ?>" class="btn btn-outline-secondary" <?= $_effective_default ? 'title="Back to your default view: ' . nullable_htmlentities($_effective_default['ticket_saved_view_name']) . '"' : '' ?>><i class="fas fa-undo me-1"></i><?= $_effective_default ? 'Reset to my default' : 'Reset Filters' ?></a>
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

                <?php $_more_open = $filter_sets['onsite'] || $filter_sets['overdue'] || $filter_sets['due_today'] || $ticket_project_filter_id !== ''; ?>
                <div class="collapse mt-3 <?= $_more_open ? 'show' : '' ?>" id="advancedFilter">
                    <div class="row align-items-start">
                        <div class="col-auto mb-2">
                            <?php ticketFilterCheckGroup('tvp_onsite', 'Where', 'onsite[]', ['1' => 'On-site', '0' => 'Remote'], array_map('strval', $onsite_filter), ['mode' => 'dropdown', 'autosubmit' => true]); ?>
                        </div>
                        <div class="col-auto mb-2">
                            <?php ticketFilterCheckGroup('tvp_due', 'Due', 'due[]', ['overdue' => 'Overdue', 'due_today' => 'Due today'], array_values(array_filter([$filter_sets['overdue'] ? 'overdue' : '', $filter_sets['due_today'] ? 'due_today' : ''])), ['mode' => 'dropdown', 'autosubmit' => true]); ?>
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
<script src="../js/ticket_view_filters.js?v=<?= (int) @filemtime(__DIR__ . '/../js/ticket_view_filters.js') ?>"></script>

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
