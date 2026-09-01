<?php
// Pre-load all techs for inline assignment dropdowns
$_techs_list = [];
$_sql_techs = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
while ($_t = mysqli_fetch_assoc($_sql_techs)) $_techs_list[] = $_t;

// Pre-load all ticket statuses for inline status dropdowns
$_statuses_list = [];
$_sql_statuses = mysqli_query($mysqli, "SELECT ticket_status_id, ticket_status_name, ticket_status_color FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order ASC");
while ($_st = mysqli_fetch_assoc($_sql_statuses)) $_statuses_list[] = $_st;

// Pre-load all ticket categories for inline category dropdowns
$_cats_list = [];
$_sql_cats_g = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND category_parent = 0 AND category_archived_at IS NULL ORDER BY category_name");
$_cat_groups = [];
while ($_g = mysqli_fetch_assoc($_sql_cats_g)) $_cat_groups[] = $_g;
$_sql_cats_s = mysqli_query($mysqli, "SELECT category_id, category_name, category_parent FROM categories WHERE category_type = 'Ticket' AND category_parent > 0 AND category_archived_at IS NULL ORDER BY category_name");
$_cat_subs = [];
while ($_s = mysqli_fetch_assoc($_sql_cats_s)) $_cat_subs[intval($_s['category_parent'])][] = $_s;

// Map every category id (top-level or sub) to its top-level "Board" name
$_cat_board_name = [];
foreach ($_cat_groups as $_g) {
    $_cat_board_name[intval($_g['category_id'])] = $_g['category_name'];
}
foreach ($_cat_subs as $_pid => $_subs) {
    foreach ($_subs as $_s) {
        $_cat_board_name[intval($_s['category_id'])] = $_cat_board_name[$_pid] ?? '';
    }
}
?>
<div class="card card-dark">
    <div class="card-body">
        <form id="bulkActions" action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                <div class="table-responsive">
                    <table class="table table-striped table-borderless table-hover table-sm table-tight">
                        <thead class="text-dark <?php if (!$num_rows[0]) { echo "d-none"; } ?> text-nowrap">
                        <tr>
                            <td class="checkbox-column">
                                <?php if ($status !== 'Closed') { ?>
                                <div class="form-check">
                                    <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                                </div>
                                <?php } ?>
                            </td>

                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_number&order=<?php echo $disp; ?>">
                                    Ticket <?php if ($sort == 'ticket_number') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_subject&order=<?php echo $disp; ?>">
                                    Subject <?php if ($sort == 'ticket_subject') { echo $order_icon; } ?>
                                </a>
                            </th>

                            <th>Board</th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=category_name&order=<?php echo $disp; ?>">
                                    Category <?php if ($sort == 'category_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <?php if (!$client_url) { ?>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=client_name&order=<?php echo $disp; ?>">
                                    Client <?php if ($sort == 'client_name') { echo $order_icon; } ?> /
                                </a>
                                <?php } ?>
                                <a class="text-secondary <?php if ($client_url) { echo "text-dark"; } ?>" href="?<?php echo $url_query_strings_sort; ?>&sort=contact_name&order=<?php echo $disp; ?>">
                                    Contact <?php if ($sort == 'contact_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <?php if ($config_module_enable_accounting && lookupUserPermission("module_sales") >= 2) { ?>
                            <th class="text-center">
                                <a class="text-secondary" href="?<?= $url_query_strings_sort ?>&sort=ticket_billable&order=<?= $disp ?>">
                                    Billable <?php if ($sort == 'ticket_billable') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <?php } ?>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_priority&order=<?php echo $disp; ?>">
                                    Priority <?php if ($sort == 'ticket_priority') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>CSAT</th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_status&order=<?php echo $disp; ?>">
                                    Status <?php if ($sort == 'ticket_status') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=user_name&order=<?php echo $disp; ?>">
                                    Assigned <?php if ($sort == 'user_name') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>Tags</th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_updated_at&order=<?php echo $disp; ?>">
                                    Last Response <?php if ($sort == 'ticket_updated_at') { echo $order_icon; } ?>
                                </a>
                            </th>
                            <th>
                                <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_created_at&order=<?php echo $disp; ?>">
                                    Created <?php if ($sort == 'ticket_created_at') { echo $order_icon; } ?>
                                </a>
                            </th>
                        </tr>
                        </thead>
                        <?php
                        // Buffer all rows so we can count per group before rendering
                        $_all_rows = [];
                        while ($_r = mysqli_fetch_assoc($sql)) $_all_rows[] = $_r;

                        // Count tickets per group and collect color
                        $_group_meta = []; // [label => ['count'=>n,'color'=>'#hex','id'=>category_id]]
                        foreach ($_all_rows as $_r) {
                            $_lbl = ($_r['category_name'] ?? '') ?: 'Uncategorized';
                            if (!isset($_group_meta[$_lbl])) {
                                $_group_meta[$_lbl] = [
                                    'count' => 0,
                                    'color' => $_r['category_color'] ?? '#6c757d',
                                    'cat_id' => intval($_r['ticket_category'] ?? 0),
                                ];
                            }
                            $_group_meta[$_lbl]['count']++;
                        }

                        $current_group_cat = null;
                        $group_index = 0;

                        foreach ($_all_rows as $row) {
                            $ticket_id = intval($row['ticket_id']);
                            $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                            $ticket_number = intval($row['ticket_number']);
                            $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                            $ticket_priority = nullable_htmlentities($row['ticket_priority']);
                            $ticket_csat_rating = intval($row['ticket_csat_rating'] ?? 0);
                            $ticket_status_id = intval($row['ticket_status_id']);
                            $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                            $ticket_status_color = nullable_htmlentities($row['ticket_status_color']);
                            $ticket_billable = intval($row['ticket_billable']);
                            $ticket_scheduled_for = nullable_htmlentities($row['ticket_schedule']);
                            $ticket_created_at = nullable_htmlentities($row['ticket_created_at']);
                            $ticket_created_at_time_ago = timeAgo($row['ticket_created_at']);
                            $ticket_updated_at = nullable_htmlentities($row['ticket_updated_at']);
                            $ticket_updated_at_time_ago = timeAgo($row['ticket_updated_at']);
                            $ticket_closed_at = nullable_htmlentities($row['ticket_closed_at']);
                            if (empty($ticket_updated_at)) {
                                if (!empty($ticket_closed_at)) {
                                    $ticket_updated_at_display = "<p>Never</p>";
                                } else {
                                    $ticket_updated_at_display = "<p class='text-danger'>Never</p>";
                                }
                            } else {
                                $ticket_updated_at_display = "$ticket_updated_at_time_ago<br><small class='text-secondary'>$ticket_updated_at</small>";
                            }

                            $project_id = intval($row['ticket_project_id']);
                            $client_id = intval($row['ticket_client_id']);
                            $client_name = nullable_htmlentities($row['client_name']);
                            $contact_id = intval($row['contact_id']);
                            $contact_name = nullable_htmlentities($row['contact_name']);
                            $contact_email = nullable_htmlentities($row['contact_email']);
                            $has_client = $client_id ? "&client_id=$client_id" : "";

                            if ($ticket_priority == "High") {
                                $ticket_priority_color = "danger";
                            } elseif ($ticket_priority == "Medium") {
                                $ticket_priority_color = "warning";
                            } else {
                                $ticket_priority_color = "info";
                            }

                            $ticket_assigned_to = intval($row['ticket_assigned_to']);
                            $ticket_assigned_user_name = nullable_htmlentities($row['user_name'] ?? '');
                            if (empty($ticket_assigned_to)) {
                                $ticket_assigned_to_display = empty($ticket_closed_at) ? "<span class='text-muted'>Unassigned</span>" : "Unassigned";
                            } else {
                                $ticket_assigned_to_display = $ticket_assigned_user_name;
                            }

                            $contact_display = empty($contact_name) ? "-" : "<div><a href='contact_details.php?client_id=$client_id&contact_id=$contact_id'>$contact_name</a></div>";

                            $ticket_invoice_id = intval($row['ticket_invoice_id']);
                            $ticket_quote_id = intval($row['ticket_quote_id']);
                            $ticket_category_id = intval($row['ticket_category'] ?? 0);
                            $ticket_category_name = nullable_htmlentities($row['category_name'] ?? '');
                            $ticket_board_name = $_cat_board_name[$ticket_category_id] ?? '';
                            $group_label = $ticket_category_name ?: 'Uncategorized';

                            // Emit group header when category changes
                            if ($group_label !== $current_group_cat) {
                                if ($current_group_cat !== null) echo '</tbody>';
                                $group_index++;
                                $group_id = 'cat_group_' . $group_index;
                                $current_group_cat = $group_label;
                                $gcolor = $_group_meta[$group_label]['color'] ?? '#6c757d';
                                $gcount = $_group_meta[$group_label]['count'] ?? 0;
                                // Darken color slightly for text contrast check
                                echo '<tbody>'
                                   . '<tr class="ticket-group-header" data-group="' . $group_id . '" style="cursor:pointer;box-shadow:inset 3px 0 0 ' . htmlspecialchars($gcolor) . ';">'
                                   . '<td colspan="99" class="py-2 px-3" style="border:none;">'
                                   . '<i class="fas fa-chevron-down group-chevron me-2" style="font-size:11px;transition:transform .2s;color:var(--if-muted,#5d6f76);"></i>'
                                   . '<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:' . htmlspecialchars($gcolor) . ';vertical-align:middle;margin-right:8px;"></span>'
                                   . '<strong style="font-size:.72rem;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--if-muted,#5d6f76);">' . htmlspecialchars($group_label) . '</strong>'
                                   . '<span class="badge rounded-pill ms-2" style="background:' . htmlspecialchars($gcolor) . ';color:#fff;">' . $gcount . '</span>'
                                   . '</td></tr>'
                                   . '</tbody>';
                                echo '<tbody class="ticket-group-body" id="' . $group_id . '">';
                            }

                            // Get who last updated the ticket - to be shown in the last Response column

                            // Defaults to prevent undefined errors
                            $ticket_reply_created_at = "";
                            $ticket_reply_created_at_time_ago = "Never";
                            $ticket_reply_by_display = "";
                            $ticket_reply_type = "Client"; // Default to client for un-replied tickets

                            $sql_ticket_reply = mysqli_query($mysqli,
                                "SELECT ticket_reply_type, ticket_reply_created_at, contact_name, user_name FROM ticket_replies
                                LEFT JOIN users ON ticket_reply_by = user_id
                                LEFT JOIN contacts ON ticket_reply_by = contact_id
                                WHERE ticket_reply_ticket_id = $ticket_id
                                AND ticket_reply_archived_at IS NULL
                                ORDER BY ticket_reply_id DESC LIMIT 1"
                            );
                            $row = mysqli_fetch_assoc($sql_ticket_reply);

                            if ($row) {
                                $ticket_reply_type = nullable_htmlentities($row['ticket_reply_type']);
                                if ($ticket_reply_type == "Client") {
                                    $ticket_reply_by_display = nullable_htmlentities($row['contact_name']);
                                } else {
                                    $ticket_reply_by_display = nullable_htmlentities($row['user_name']);
                                }
                                $ticket_reply_created_at = nullable_htmlentities($row['ticket_reply_created_at']);
                                $ticket_reply_created_at_time_ago = timeAgo($ticket_reply_created_at);
                            }


                            // Get Tasks
                            // Get Tasks
                            $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT('task_id') AS count FROM tickets, tasks WHERE ticket_id = task_ticket_id AND ticket_project_id = $project_id"));
                            $task_count = $row['count'];
                            $sql_tasks = mysqli_query( $mysqli, "SELECT * FROM tasks WHERE task_ticket_id = $ticket_id ORDER BY task_created_at ASC");
                            $task_count = mysqli_num_rows($sql_tasks);
                                    // Get Completed Task Count
                            $sql_tasks_completed = mysqli_query($mysqli,
                                "SELECT * FROM tasks
                                WHERE task_ticket_id = $ticket_id
                                AND task_completed_at IS NOT NULL"
                            );
                            $completed_task_count = mysqli_num_rows($sql_tasks_completed);

                            // Tasks Completed Percent
                            if($task_count) {
                                $tasks_completed_percent = round(($completed_task_count / $task_count) * 100);
                            }

                            // Get Tags
                            $ticket_tags_display = '';
                            $sql_ticket_tags_row = mysqli_query($mysqli, "SELECT tag_name, tag_color, tag_icon FROM ticket_tags LEFT JOIN tags ON ticket_tag_tag_id = tag_id WHERE ticket_tag_ticket_id = $ticket_id ORDER BY tag_name ASC");
                            while ($tag_row = mysqli_fetch_assoc($sql_ticket_tags_row)) {
                                $ticket_tag_name = nullable_htmlentities($tag_row['tag_name']);
                                $ticket_tag_color = nullable_htmlentities($tag_row['tag_color']) ?: 'dark';
                                $ticket_tag_icon = nullable_htmlentities($tag_row['tag_icon']) ?: 'tag';
                                $ticket_tags_display .= "<span class='badge " . tagTextClass($ticket_tag_color) . " p-1 me-1' style='background-color: $ticket_tag_color;'><i class='fa fa-fw fa-$ticket_tag_icon me-1'></i>$ticket_tag_name</span>";
                            }

                            ?>

                            <tr class="<?php if(empty($ticket_closed_at) && empty($ticket_updated_at)) { echo "text-bold"; }?> <?php if (empty($ticket_closed_at) && $ticket_reply_type == "Client") { echo "table-warning"; } ?>">

                                <td class="checkbox-column">
                                    <!-- Ticket Bulk Select (for open tickets) -->
                                    <?php if (empty($ticket_closed_at)) { ?>
                                    <div class="form-check">
                                        <input class="form-check-input bulk-select" type="checkbox" name="ticket_ids[]" value="<?php echo $ticket_id ?>">
                                    </div>
                                    <?php } ?>
                                </td>

                                <!-- Ticket Number -->
                                <td>
                                    <a href="ticket.php?ticket_id=<?= "$ticket_id$has_client" ?>">
                                        <span class="badge rounded-pill text-bg-dark p-2"><?php echo "$ticket_prefix$ticket_number"; ?></span>
                                    </a>
                                </td>

                                <!-- Ticket Subject -->
                                <td>
                                    <a href="ticket.php?ticket_id=<?= "$ticket_id$has_client" ?>"><?= $ticket_subject ?></a>
                                    <?php if (!empty($row['ticket_merged_into_id'])) { ?>
                                    <small class="text-muted ms-1"><i class="fas fa-code-branch fa-sm"></i> Merged</small>
                                    <?php } ?>

                                    <?php if ($task_count) { ?>
                                    <div class="d-flex align-items-center mt-1" style="gap:5px;">
                                        <div class="progress flex-grow-1" style="height:4px;min-width:40px;background-color:var(--color-border-soft);">
                                            <div class="progress-bar <?= $tasks_completed_percent == 100 ? 'bg-success' : '' ?>" style="width:<?= $tasks_completed_percent ?>%;"></div>
                                        </div>
                                        <span style="font-size:10px;color:var(--color-text-muted);white-space:nowrap;flex-shrink:0;"><?= $completed_task_count ?>/<?= $task_count ?></span>
                                    </div>
                                    <?php } ?>
                                </td>

                                <!-- Board -->
                                <td>
                                    <?php if ($ticket_board_name) { ?>
                                    <span class="badge rounded-pill tkt-pill-badge text-bg-light border"><?= htmlspecialchars($ticket_board_name) ?></span>
                                    <?php } else { ?>
                                    <span class="text-muted">-</span>
                                    <?php } ?>
                                </td>

                                <!-- Category pill -->
                                <td>
                                    <?php
                                    $cat_color = ($row['category_color'] ?? '') ?: '#6c757d';
                                    $cat_label = $ticket_category_name ?: 'Uncategorized';
                                    if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                    <div class="dropdown">
                                        <span class="badge rounded-pill tkt-pill-badge dropdown-toggle" data-bs-toggle="dropdown" data-boundary="window"
                                             style="background:<?= htmlspecialchars($cat_color) ?>;color:#fff;cursor:pointer;">
                                            <?= htmlspecialchars($cat_label) ?>
                                        </span>
                                        <div class="dropdown-menu shadow-sm" style="min-width:180px;max-height:280px;overflow-y:auto;">
                                            <h6 class="dropdown-header">Set Category</h6>
                                            <a class="dropdown-item quick-cat-item <?= !$ticket_category_id ? 'active' : '' ?>"
                                               href="#" data-ticket-id="<?= $ticket_id ?>" data-cat-id="0" data-cat-name="Uncategorized" data-cat-color="#6c757d">
                                               <span class="badge rounded-pill me-2" style="background:#6c757d;color:#fff;">None</span>Uncategorized
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <?php foreach ($_cat_groups as $_g) {
                                                $_gid = intval($_g['category_id']);
                                                $_gname = nullable_htmlentities($_g['category_name']);
                                                $_gcolor = $_g['category_color'] ?? '#6c757d';
                                                if (isset($_cat_subs[$_gid])) {
                                                    echo '<h6 class="dropdown-header">' . $_gname . '</h6>';
                                                    foreach ($_cat_subs[$_gid] as $_s) {
                                                        $active = intval($_s['category_id']) === $ticket_category_id ? ' active' : '';
                                                        echo '<a class="dropdown-item quick-cat-item' . $active . '" href="#" data-ticket-id="' . $ticket_id . '" data-cat-id="' . intval($_s['category_id']) . '" data-cat-name="' . nullable_htmlentities($_s['category_name']) . '" data-cat-color="' . htmlspecialchars($_gcolor) . '">'
                                                           . '<span class="badge rounded-pill me-2" style="background:' . htmlspecialchars($_gcolor) . ';color:#fff;">&nbsp;</span>' . nullable_htmlentities($_s['category_name'])
                                                           . '</a>';
                                                    }
                                                } else {
                                                    $active = $_gid === $ticket_category_id ? ' active' : '';
                                                    echo '<a class="dropdown-item quick-cat-item' . $active . '" href="#" data-ticket-id="' . $ticket_id . '" data-cat-id="' . $_gid . '" data-cat-name="' . $_gname . '" data-cat-color="' . htmlspecialchars($_gcolor) . '">'
                                                       . '<span class="badge rounded-pill me-2" style="background:' . htmlspecialchars($_gcolor) . ';color:#fff;">&nbsp;</span>' . $_gname
                                                       . '</a>';
                                                }
                                            } ?>
                                        </div>
                                    </div>
                                    <?php } else { ?>
                                    <span class="badge rounded-pill tkt-pill-badge" style="background:<?= htmlspecialchars($cat_color) ?>;color:#fff;">
                                        <?= htmlspecialchars($cat_label) ?>
                                    </span>
                                    <?php } ?>
                                </td>

                                <!-- Ticket Contact -->
                                <td>
                                    <?php if (!$client_url) { ?>
                                    <a href="tickets.php?client_id=<?php echo $client_id; ?>"><strong><?php echo $client_name; ?></strong></a>
                                    <?php } ?>
                                    <div><?php echo $contact_display; ?></div>
                                </td>

                                <!-- Ticket Billable (if accounting enabled -->
                                <?php if ($config_module_enable_accounting && lookupUserPermission("module_sales") >= 2) { ?>
                                    <td class="text-center">
                                        <?php if ($ticket_invoice_id) { ?>
                                        <a href="invoice.php?client_id=<?php echo $client_id; ?>&invoice_id=<?php echo $ticket_invoice_id; ?>"><span class='badge rounded-pill text-bg-success p-2'>Invoiced</span></a>
                                        <?php } else if ($ticket_quote_id) { ?>
                                            <a href="quote.php?client_id=<?php echo $client_id; ?>&quote_id=<?php echo $ticket_quote_id; ?>"><span class='badge rounded-pill text-bg-primary p-2'>Quoted</span></a>
                                        <?php } else { ?>
                                        <a href="#"
                                            class="ajax-modal"
                                            data-modal-url="modals/ticket/ticket_billable.php?id=<?= $ticket_id ?>">
                                            <?php
                                            if ($ticket_billable == 1) {
                                                echo "<span class='badge rounded-pill text-bg-success p-2'><i class='fas fa-fw fa-check'></i></span>";
                                            } else {
                                                echo "<span class='badge rounded-pill text-bg-secondary p-2'><i class='fas fa-fw fa-minus'></i></span>";
                                            }
                                            ?>
                                        </a>
                                        <?php } ?>
                                    </td>
                                <?php } ?>


                                <!-- Priority -->
                                <td>
                                    <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                    <div class="dropdown">
                                        <span class="badge rounded-pill tkt-pill-badge badge-<?= $ticket_priority_color ?> dropdown-toggle"
                                              data-bs-toggle="dropdown" data-boundary="window" style="cursor:pointer;">
                                            <?= $ticket_priority ?>
                                        </span>
                                        <div class="dropdown-menu shadow-sm" style="min-width:120px;">
                                            <h6 class="dropdown-header">Set Priority</h6>
                                            <?php foreach (['Low'=>'info','Medium'=>'warning','High'=>'danger'] as $_p=>$_pc) {
                                                $active = $ticket_priority === $_p ? ' active' : '';
                                                echo '<a class="dropdown-item quick-priority-item' . $active . '" href="#" data-ticket-id="' . $ticket_id . '" data-priority="' . $_p . '" data-color="' . $_pc . '">'
                                                   . '<span class="badge rounded-pill badge-' . $_pc . ' me-2">&nbsp;</span>' . $_p
                                                   . '</a>';
                                            } ?>
                                        </div>
                                    </div>
                                    <?php } else { ?>
                                    <span class="badge rounded-pill tkt-pill-badge badge-<?= $ticket_priority_color ?>">
                                        <?= $ticket_priority ?>
                                    </span>
                                    <?php } ?>
                                    <?php
                                    // SLA badge - worst-case of response (if not yet first-responded) / resolution
                                    $_sla_due = null;
                                    if (empty($row['ticket_first_response_at']) && !empty($row['ticket_sla_response_due'])) {
                                        $_sla_due = $row['ticket_sla_response_due'];
                                    } elseif (!empty($row['ticket_sla_resolution_due'])) {
                                        $_sla_due = $row['ticket_sla_resolution_due'];
                                    }
                                    if ($_sla_due && empty($ticket_closed_at)) {
                                        $_sla_breached = $_sla_due < date('Y-m-d H:i:s');
                                        $_sla_color = $_sla_breached ? 'danger' : (strtotime($_sla_due) - time() < 7200 ? 'warning' : 'success');
                                        $_sla_label = $_sla_breached ? 'SLA breached' : 'SLA ok';
                                    ?>
                                    <br>
                                    <span class="badge badge-<?= $_sla_color ?> mt-1" title="SLA due <?= date('M j, Y g:i A', strtotime($_sla_due)) ?>">
                                        <i class="fas fa-stopwatch me-1"></i><?= $_sla_label ?>
                                    </span>
                                    <?php } ?>
                                </td>

                                <!-- CSAT -->
                                <td>
                                    <?php if ($ticket_csat_rating > 0) { ?>
                                        <span class="csat-face-display" title="<?= csatFaceLabel($ticket_csat_rating) ?>"><?= csatFaceEmoji($ticket_csat_rating) ?></span>
                                    <?php } ?>
                                </td>

                                <!-- Ticket Status -->
                                <td>
                                    <?php if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                    <div class="dropdown">
                                        <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_status_color) ?> dropdown-toggle"
                                              style="background-color:<?= $ticket_status_color ?>;cursor:pointer;"
                                              data-bs-toggle="dropdown" data-boundary="window">
                                            <?= $ticket_status_name ?>
                                        </span>
                                        <div class="dropdown-menu shadow-sm" style="min-width:160px;max-height:260px;overflow-y:auto;">
                                            <h6 class="dropdown-header">Set Status</h6>
                                            <?php foreach ($_statuses_list as $_st) {
                                                $active = intval($_st['ticket_status_id']) === $ticket_status_id ? ' active' : '';
                                                echo '<a class="dropdown-item quick-status-item' . $active . '" href="#" data-ticket-id="' . $ticket_id . '" data-status-id="' . intval($_st['ticket_status_id']) . '" data-status-name="' . nullable_htmlentities($_st['ticket_status_name']) . '" data-status-color="' . htmlspecialchars($_st['ticket_status_color']) . '">'
                                                   . '<span class="badge rounded-pill me-2" style="background:' . htmlspecialchars($_st['ticket_status_color']) . ';color:#fff;">&nbsp;</span>' . nullable_htmlentities($_st['ticket_status_name'])
                                                   . '</a>';
                                            } ?>
                                        </div>
                                    </div>
                                    <?php } else { ?>
                                    <span class="badge rounded-pill tkt-pill-badge <?= tagTextClass($ticket_status_color) ?>" style="background-color:<?= $ticket_status_color ?>">
                                        <?= $ticket_status_name ?>
                                    </span>
                                    <?php } ?>
                                    <?php if (isset($ticket_scheduled_for)) { echo "<div class='mt-1'><small class='text-secondary'>$ticket_scheduled_for</small></div>"; } ?>
                                </td>

                                <!-- Assigned agent pill -->
                                <td>
                                    <?php
                                    $agent_name_raw = $ticket_assigned_to ? $ticket_assigned_user_name : '';
                                    $agent_label = $agent_name_raw ?: 'Unassigned';
                                    $agent_colors = ['#e74c3c','#3498db','#2ecc71','#9b59b6','#f39c12','#1abc9c','#e67e22','#34495e','#c0392b','#16a085'];
                                    $agent_color = $ticket_assigned_to ? $agent_colors[$ticket_assigned_to % count($agent_colors)] : '#adb5bd';
                                    if (lookupUserPermission("module_support") >= 2 && empty($ticket_closed_at)) { ?>
                                    <div class="dropdown">
                                        <span class="badge rounded-pill tkt-pill-badge dropdown-toggle" data-bs-toggle="dropdown" data-boundary="window"
                                             style="background:<?= $agent_color ?>;color:#fff;cursor:pointer;">
                                            <?= htmlspecialchars($agent_label) ?>
                                        </span>
                                        <div class="dropdown-menu shadow-sm" style="min-width:160px;">
                                            <h6 class="dropdown-header">Assign To</h6>
                                            <a class="dropdown-item quick-assign-item <?= !$ticket_assigned_to ? 'active' : '' ?>"
                                               href="#" data-ticket-id="<?= $ticket_id ?>" data-user-id="0" data-user-name="Unassigned" data-user-color="#adb5bd">
                                               <span class="badge rounded-pill me-2" style="background:#adb5bd;color:#fff;">&nbsp;</span>Unassigned
                                            </a>
                                            <div class="dropdown-divider"></div>
                                            <?php foreach ($_techs_list as $_t) {
                                                $_uid = intval($_t['user_id']);
                                                $_uname = nullable_htmlentities($_t['user_name']);
                                                $_ucolor = $agent_colors[$_uid % count($agent_colors)];
                                                $active = $_uid === $ticket_assigned_to ? ' active' : '';
                                                echo '<a class="dropdown-item quick-assign-item' . $active . '" href="#" data-ticket-id="' . $ticket_id . '" data-user-id="' . $_uid . '" data-user-name="' . $_uname . '" data-user-color="' . $_ucolor . '">'
                                                   . '<span class="badge rounded-pill me-2" style="background:' . $_ucolor . ';color:#fff;">&nbsp;</span>' . $_uname
                                                   . '</a>';
                                            } ?>
                                        </div>
                                    </div>
                                    <?php } else { ?>
                                    <span class="badge rounded-pill tkt-pill-badge" style="background:<?= $agent_color ?>;color:#fff;">
                                        <?= htmlspecialchars($agent_label) ?>
                                    </span>
                                    <?php } ?>
                                </td>

                                <!-- Tags -->
                                <td>
                                    <?php echo $ticket_tags_display ?: "<span class='text-muted'>-</span>"; ?>
                                </td>

                                <!-- Ticket Last Response -->
                                <td>
                                    <div title="<?php echo $ticket_reply_created_at; ?>">
                                        <?php echo $ticket_reply_created_at_time_ago; ?>
                                    </div>
                                    <div class="text-secondary"><?php echo $ticket_reply_by_display; ?></div>
                                </td>

                                <!-- Ticket Created At -->
                                <td>
                                    <?php echo $ticket_created_at_time_ago; ?>
                                    <br>
                                    <small class="text-secondary"><?php echo date("$config_date_format $config_time_format", strtotime($ticket_created_at)); ?></small>
                                </td>

                            </tr>

                            <?php
                        }
                        // Close the last group tbody
                        if ($current_group_cat !== null) echo '</tbody>';
                        else echo '<tbody></tbody>';
                        ?>

                    </table>
                </div>
            </form>
            <?php require_once "../includes/filter_footer.php"; ?>
        </div>
    </div>

<style>
.tkt-pill-badge {
    font-size: 13px; font-weight: 600; padding: 6px 14px;
    white-space: nowrap; cursor: pointer; user-select: none;
}
.tkt-pill-badge.dropdown-toggle::after { display: none; }
.ticket-group-header td { border: none !important; }
.ticket-group-header { background: var(--if-bg, #eef2f2); }
.ticket-group-header:hover { background: var(--if-border, #e3e9ea); }
:root[data-bs-theme="dark"] .ticket-group-header { background: rgba(255,255,255,.03); }
:root[data-bs-theme="dark"] .ticket-group-header:hover { background: rgba(255,255,255,.06); }
</style>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Category group collapse/expand
$(document).on('click', '.ticket-group-header', function(e) {
    if ($(e.target).closest('.dropdown').length) return;
    var groupId = $(this).data('group');
    var $body = $('#' + groupId);
    var $chevron = $(this).find('.group-chevron');
    $body.toggle();
    $chevron.css('transform', $body.is(':visible') ? 'rotate(0deg)' : 'rotate(-90deg)');
});

// While a dropdown-menu is open it has been reparented to <body> (see app.js's
// initTableResponsiveDropdowns(), which escapes the table's clipping/scroll
// container - every dropdown on this page lives inside .table-responsive, so
// this fires every time) - so $item.closest('.dropdown') can no longer find
// its way back to the original toggle/pill; the menu item now hangs directly
// off <body> with no .dropdown ancestor at all, and every .css()/.text() call
// below silently no-ops on an empty jQuery set. Use the placeholder comment
// initTableResponsiveDropdowns() leaves in the menu's original spot (inside
// the real .dropdown container) to find our way back - that's the only
// pointer it actually sets (as a plain element property, not jQuery .data()).
function resolveDropdown($item) {
    var menu = $item.closest('.dropdown-menu')[0];
    var placeholder = menu && menu._trfPlaceholder;
    if (placeholder && placeholder.parentNode) {
        return $(placeholder.parentNode).closest('.dropdown');
    }
    return $item.closest('.dropdown');
}

// Category dropdown item click
$(document).on('click', '.quick-cat-item', function(e) {
    e.preventDefault();
    var $item = $(this);
    var ticketId = $item.data('ticket-id');
    var catId = $item.data('cat-id');
    var catName = $item.data('cat-name');
    var catColor = $item.data('cat-color');
    var $pill = resolveDropdown($item).find('.tkt-pill-badge');
    var csrf = $('input[name="csrf_token"]').first().val();

    $pill.css('opacity', '0.5');
    $.post('post.php', { quick_categorize_ticket: 1, ticket_id: ticketId, category_id: catId, csrf_token: csrf }, function(res) {
        if (res.ok) {
            $pill.css({'opacity':'1', 'background': catColor}).text(catName);
            $item.closest('.dropdown-menu').find('.active').removeClass('active');
            $item.addClass('active');
            setTimeout(function() { location.reload(); }, 600);
        }
    }, 'json').fail(function(){ $pill.css('opacity','1'); });
});

// Priority dropdown item click
$(document).on('click', '.quick-priority-item', function(e) {
    e.preventDefault();
    var $item = $(this);
    var ticketId = $item.data('ticket-id');
    var priority = $item.data('priority');
    var color = $item.data('color');
    var $pill = resolveDropdown($item).find('.tkt-pill-badge');
    var csrf = $('input[name="csrf_token"]').first().val();

    $pill.css('opacity', '0.5');
    $.post('post.php', { quick_priority_ticket: 1, ticket_id: ticketId, priority: priority, csrf_token: csrf }, function(res) {
        if (res.ok) {
            $pill.css('opacity','1').removeClass('text-bg-info text-bg-warning text-bg-danger').addClass('badge-' + color).text(priority);
            $item.closest('.dropdown-menu').find('.active').removeClass('active');
            $item.addClass('active');
        }
    }, 'json').fail(function(){ $pill.css('opacity','1'); });
});

// Status dropdown item click
$(document).on('click', '.quick-status-item', function(e) {
    e.preventDefault();
    var $item = $(this);
    var ticketId = $item.data('ticket-id');
    var statusId = $item.data('status-id');
    var statusName = $item.data('status-name');
    var statusColor = $item.data('status-color');
    var $pill = resolveDropdown($item).find('.tkt-pill-badge');
    var csrf = $('input[name="csrf_token"]').first().val();

    $pill.css('opacity', '0.5');
    $.post('post.php', { quick_status_ticket: 1, ticket_id: ticketId, ticket_status_id: statusId, csrf_token: csrf }, function(res) {
        if (res.ok) {
            $pill.css({'opacity':'1', 'background-color': statusColor}).text(statusName);
            $item.closest('.dropdown-menu').find('.active').removeClass('active');
            $item.addClass('active');
        }
    }, 'json').fail(function(){ $pill.css('opacity','1'); });
});

// Assign dropdown item click
$(document).on('click', '.quick-assign-item', function(e) {
    e.preventDefault();
    var $item = $(this);
    var ticketId = $item.data('ticket-id');
    var userId = $item.data('user-id');
    var userName = $item.data('user-name');
    var userColor = $item.data('user-color');
    var $pill = resolveDropdown($item).find('.tkt-pill-badge');
    var csrf = $('input[name="csrf_token"]').first().val();

    $pill.css('opacity', '0.5');
    $.post('post.php', { quick_assign_ticket: 1, ticket_id: ticketId, assigned_to: userId, csrf_token: csrf }, function(res) {
        if (res.ok) {
            $pill.css({'opacity':'1', 'background': userColor}).text(userName);
            $item.closest('.dropdown-menu').find('.active').removeClass('active');
            $item.addClass('active');
        }
    }, 'json').fail(function(){ $pill.css('opacity','1'); });
});
</script>
