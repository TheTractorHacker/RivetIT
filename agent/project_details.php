<?php

// Default Column Sortby Filter
$sort = "ticket_number";
$order = "DESC";

// If client_id is in URI then show client Side Bar and client header
if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_query = "AND ticket_client_id = $client_id";
    $client_ticket_select_query = "AND ticket_client_id = $client_id"; // Used when linking a ticket to the project
    $client_url = "client_id=$client_id&";
} else {
    require_once "includes/inc_all.php";
    $client_query = '';
    $client_ticket_select_query = '';
    $client_url = '';
}

// Perms & Project client access snippet
enforceUserPermission('module_support');
$project_permission_snippet = '';

if (!empty($client_access_string)) {
    $project_permission_snippet = "AND (project_client_id IN ($client_access_string) OR project_client_id = 0)";
}

if (isset($_GET['project_id'])) {
    $project_id = intval($_GET['project_id']);

    $sql_project = mysqli_query(
        $mysqli,
        "SELECT * FROM projects
        LEFT JOIN clients ON project_client_id = client_id
        LEFT JOIN users ON project_manager = user_id
        WHERE project_id = $project_id
        $project_permission_snippet
        LIMIT 1"
    );

    if (mysqli_num_rows($sql_project) == 0) {
        echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='projects.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";

        include_once "../includes/footer.php";
        exit;
    }

    $row = mysqli_fetch_assoc($sql_project);

    $project_id = intval($row['project_id']);
    $project_prefix = nullable_htmlentities($row['project_prefix']);
    $project_number = intval($row['project_number']);
    $project_name = nullable_htmlentities($row['project_name']);
    $project_description = nullable_htmlentities($row['project_description']);
    $project_due = nullable_htmlentities($row['project_due']);
    $project_created_at = date("Y-m-d", strtotime($row['project_created_at']));
    $project_updated_at = nullable_htmlentities($row['project_updated_at']);
    $project_completed_at = nullable_htmlentities($row['project_completed_at']);
    $project_archived_at = nullable_htmlentities($row['project_archived_at']);
    $client_id = intval($row['client_id']);
    $client_name = nullable_htmlentities($row['client_name']);
    if ($client_name) {
        $client_name_display = "<div class='text-secondary'><i class='fas fa-fw fa-users me-2'></i>$client_name</div>";
    } else {
        $client_name_display = "";
    }

    $project_manager = intval($row['user_id']);
    $project_manager_name = nullable_htmlentities($row['user_name']);

    // Planning / budget fields (captured now before $row is reused by later queries)
    $project_start = nullable_htmlentities($row['project_start']);
    $project_estimated_hours = $row['project_estimated_hours'] !== null ? floatval($row['project_estimated_hours']) : 0;
    $project_budget_amount = $row['project_budget_amount'] !== null ? floatval($row['project_budget_amount']) : 0;
    $project_hourly_rate = $row['project_hourly_rate'] !== null ? floatval($row['project_hourly_rate']) : 0;

    if ($project_manager) {
        $project_manager_display = "<div class='text-secondary'><i class='fas fa-fw fa-user-tie me-2'></i>$project_manager_name</div>";
    } else {
        $project_manager_display = "-";
    }

    if ($project_completed_at) {
        $project_status_display = "<span class='badge rounded-pill text-bg-dark ms-2'>Closed</span>";
        $project_completed_date_display = "<div class='text-primary text-bold'><small><i class='fa fa-fw fa-door-closed me-2'></i>" . date('Y-m-d', strtotime($project_completed_at)) . "</small></div>";
    } else {
        $project_status_display = "<span class='badge rounded-pill text-bg-primary ms-2'>Open</span>";
        $project_completed_date_display = "";
    }

    // Override Tab Title // No Sanitizing needed as this var will only be used in the tab title
    $tab_title = "{$row['project_prefix']}{$row['project_number']}";
    $page_title = $row['project_name'];

    // Get Tickets
    $sql_tickets = mysqli_query($mysqli, "SELECT * FROM tickets
        LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE ticket_project_id = $project_id
        ORDER BY $sort $order"
    );
    $ticket_count = mysqli_num_rows($sql_tickets);

    // Get Closed Ticket Count
    $sql_closed_tickets = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_project_id = $project_id AND ticket_closed_at IS NOT NULL");
    $closed_ticket_count = mysqli_num_rows($sql_closed_tickets);

    // Get Resolved Ticket Count
    $sql_resolved_tickets = mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_project_id = $project_id AND ticket_resolved_at IS NOT NULL");

    $resolved_ticket_count = mysqli_num_rows($sql_resolved_tickets);

    $tickets_closed_percent = 100; //Default
    if ($ticket_count) {
        $tickets_closed_percent = round(($closed_ticket_count / $ticket_count) * 100);
    }

    $tickets_resolved_percent = 100; //Default
    if ($ticket_count) {
        $tickets_resolved_percent = round(($resolved_ticket_count / $ticket_count) * 100);
    }

    // Get All Tasks
    // LEFT JOIN (plus OR task_project_id) so that project-only tasks (task_project_id set,
    // no task_ticket_id) are included alongside tasks belonging to the project's tickets.
    $sql_tasks = mysqli_query($mysqli,
        "SELECT tasks.*, tickets.ticket_prefix, tickets.ticket_number, tickets.ticket_project_id
        FROM tasks
        LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
        WHERE tickets.ticket_project_id = $project_id
        OR tasks.task_project_id = $project_id
        ORDER BY task_order ASC, task_created_at ASC"
    );
    $project_tasks = [];
    while ($task_row = mysqli_fetch_assoc($sql_tasks)) {
        $project_tasks[] = $task_row;
    }
    $task_count = count($project_tasks);

    // Completed Task Count
    $completed_task_count = 0;
    foreach ($project_tasks as $task_row) {
        if (!empty($task_row['task_completed_at'])) {
            $completed_task_count++;
        }
    }

    // Tasks Completed Percent
    $tasks_completed_percent = 0;
    if ($task_count) {
        $tasks_completed_percent = round(($completed_task_count / $task_count) * 100);
    }

    // Get Milestones for this project
    $sql_milestones = mysqli_query($mysqli, "SELECT * FROM project_milestones WHERE milestone_project_id = $project_id ORDER BY milestone_order ASC, milestone_id ASC");
    $project_milestones = [];
    while ($milestone_row = mysqli_fetch_assoc($sql_milestones)) {
        $project_milestones[] = $milestone_row;
    }
    $milestone_count = count($project_milestones);

    //Get Total Ticket Time
    $sql_ticket_total_reply_time = mysqli_query($mysqli, "SELECT SEC_TO_TIME(SUM(TIME_TO_SEC(ticket_reply_time_worked))) AS ticket_total_reply_time FROM ticket_replies
        LEFT JOIN tickets ON ticket_id = ticket_reply_ticket_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_project_id = $project_id");
    $row = mysqli_fetch_assoc($sql_ticket_total_reply_time);
    $ticket_total_reply_time = nullable_htmlentities($row['ticket_total_reply_time']);

    // Get all Assigned ticket Users as a comma-separated string
    $sql_project_collaborators = mysqli_query($mysqli, "
        SELECT GROUP_CONCAT(DISTINCT user_name SEPARATOR ', ') AS user_names
        FROM users
        LEFT JOIN ticket_replies ON user_id = ticket_reply_by
        LEFT JOIN tickets ON ticket_id = ticket_reply_ticket_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_project_id = $project_id
    ");

    // Fetch the result
    $row = mysqli_fetch_assoc($sql_project_collaborators);

    // The user names in a comma-separated string
    $ticket_collaborators = nullable_htmlentities($row['user_names']);

    // --- Budget / Effort rollup ---
    // Actual worked seconds from ticket replies on this project's tickets (same rollup as the time card)
    $bud_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(SUM(TIME_TO_SEC(ticket_reply_time_worked)), 0) AS s
        FROM ticket_replies
        LEFT JOIN tickets ON ticket_id = ticket_reply_ticket_id
        WHERE ticket_reply_archived_at IS NULL AND ticket_project_id = $project_id"));
    $actual_seconds = intval($bud_row['s']);

    // Add completed project-only task estimates - these never create ticket_replies (guarded),
    // so they are not represented in the reply-time rollup above.
    $ptask_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COALESCE(SUM(task_completion_estimate), 0) * 60 AS s
        FROM tasks
        WHERE task_project_id = $project_id
        AND (task_ticket_id IS NULL OR task_ticket_id = 0)
        AND task_completed_at IS NOT NULL"));
    $actual_seconds += intval($ptask_row['s']);

    $actual_hours = $actual_seconds / 3600;

    // Estimated hours: use the project field, else fall back to the sum of task estimates
    $estimated_hours = $project_estimated_hours;
    if ($estimated_hours <= 0) {
        $est_minutes = 0;
        foreach ($project_tasks as $task_row) {
            $est_minutes += intval($task_row['task_completion_estimate']);
        }
        $estimated_hours = $est_minutes / 60;
    }
    $hours_percent = $estimated_hours > 0 ? round(($actual_hours / $estimated_hours) * 100) : 0;

    // Budget $ vs burned $ (actual hours x hourly rate)
    $burned_amount = $actual_hours * $project_hourly_rate;
    $budget_percent = $project_budget_amount > 0 ? round(($burned_amount / $project_budget_amount) * 100) : 0;

    // Only show the budget card when there is something meaningful to display
    $show_budget_card = ($estimated_hours > 0 || $project_budget_amount > 0 || $project_hourly_rate > 0 || $actual_hours > 0);

    // Renderer for a single task row (shared by the milestones section and the tasks card)
    $render_task_row = function ($t) {
        $t_id = intval($t['task_id']);
        $t_name = nullable_htmlentities($t['task_name']);
        $t_completed = !empty($t['task_completed_at']);
        $t_progress = intval($t['task_progress']);
        $t_due = nullable_htmlentities($t['task_due']);
        $t_ticket_id = intval($t['task_ticket_id']);
        $t_prefix = nullable_htmlentities($t['ticket_prefix'] ?? '');
        $t_number = nullable_htmlentities($t['ticket_number'] ?? '');
        $csrf = $_SESSION['csrf_token'];
        ?>
        <tr>
            <td style="width: 28px;">
                <?php if ($t_completed) { ?>
                    <a href="post.php?undo_complete_task=<?= $t_id ?>&csrf_token=<?= $csrf ?>" title="Mark incomplete">
                        <i class="far fa-check-square text-success"></i>
                    </a>
                <?php } else { ?>
                    <a href="post.php?complete_task=<?= $t_id ?>&csrf_token=<?= $csrf ?>" title="Mark complete">
                        <i class="far fa-square text-secondary"></i>
                    </a>
                <?php } ?>
            </td>
            <td>
                <span class="<?= $t_completed ? 'text-secondary' : '' ?>" style="<?= $t_completed ? 'text-decoration: line-through;' : '' ?>"><?= $t_name ?></span>
                <?php if ($t_ticket_id) { ?>
                    <a href="ticket.php?ticket_id=<?= $t_ticket_id ?>" class="badge text-bg-light border ms-1"><?= "$t_prefix$t_number" ?></a>
                <?php } ?>
                <?php if ($t_due) { ?>
                    <span class="badge text-bg-light border ms-1"><i class="far fa-calendar me-1"></i><?= $t_due ?></span>
                <?php } ?>
                <?php if (!$t_completed && $t_progress > 0) { ?>
                    <span class="badge text-bg-info ms-1"><?= $t_progress ?>%</span>
                <?php } ?>
            </td>
            <td class="text-end" style="width: 34px;">
                <div class="dropdown">
                    <a href="#" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v text-secondary"></i></a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/task_edit.php?id=<?= $t_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                        <a class="dropdown-item text-danger confirm-link" href="post.php?delete_task=<?= $t_id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                    </div>
                </div>
            </td>
        </tr>
        <?php
    };

    ?>

<!-- Breadcrumbs-->
<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item">
        <a href="projects.php">Projects</a>
    </li>
    <li class="breadcrumb-item active">Project Details</li>
</ol>

<!-- Project view tabs -->
<ul class="nav nav-pills mb-3 d-print-none">
    <li class="nav-item">
        <a class="nav-link active" href="project_details.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-info-circle me-2"></i>Details</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="project_gantt.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-stream me-2"></i>Gantt</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="project_kanban.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-columns me-2"></i>Kanban</a>
    </li>
</ul>

<!-- Project Header -->
<div class="card">
    <div class="card-header">
        <h5 class="card-title">
            <i class="fas fa-project-diagram text-secondary me-2"></i>
            <span class="h4"><?= "$project_prefix$project_number$project_status_display" ?></span>
        </h5>
        <div class="card-tools d-print-none">
            <div class="btn-group">
                <?php if (empty($project_completed_at)) { ?>
                    <div class="dropdown me-2">
                        <button class="btn btn-primary btn-sm" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-fw fa-plus me-2"></i>New
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/ticket/ticket_add.php?<?= $client_url ?>&project_id=<?= $project_id ?>" data-modal-size="lg">
                                <i class="fa fa-fw fa-life-ring me-2"></i>Ticket
                            </a>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/project/milestone_add.php?project_id=<?= $project_id ?>">
                                <i class="fa fa-fw fa-flag-checkered me-2"></i>Milestone
                            </a>
                            <a class="dropdown-item text-dark ajax-modal" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>">
                                <i class="fa fa-fw fa-tasks me-2"></i>Task
                            </a>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm me-3" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                            <i class="fas fa-fw fa-link me-2"></i>Link
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/project_link_ticket.php?<?= $client_url ?>project_id=<?= $project_id ?>">
                                <i class="fas fa-fw fa-life-ring me-2"></i>Open Ticket
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/project_link_closed_ticket.php?<?= $client_url ?>project_id=<?= $project_id ?>">
                                <i class="fas fa-fw fa-life-ring me-2"></i>Closed Ticket
                            </a>
                        </div>
                    </div>
                <?php } ?>
                <?php if (($tickets_closed_percent == 100 || $tickets_resolved_percent == 100) && empty($project_completed_at)) { ?>
                    <a class="btn btn-dark btn-sm confirm-link" href="post.php?close_project=<?= $project_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                        <i class="fas fa-fw fa-check me-2"></i>Close
                    </a>
                <?php } ?>
                <div class="dropdown dropleft text-center ms-3">
                    <button class="btn btn-secondary btn-sm" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" data-boundary="window">
                        <i class="fas fa-fw fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu">
                        <?php if (empty($project_completed_at)) { ?>
                            <a class="dropdown-item ajax-modal" href="#"
                                data-modal-url = "modals/project/project_edit.php?id=<?= $project_id ?>">
                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                            </a>
                        <?php } ?>
                        <?php if (!empty($project_completed_at) && empty($project_archived_at) && lookupUserPermission("module_support") >= 2) { ?>
                            <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?archive_project=<?= $project_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                <i class="fas fa-fw fa-archive me-2"></i>Archive
                            </a>
                        <?php } ?>
                        <?php if (!empty($project_archived_at) && lookupUserPermission("module_support" >= 3)) { ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger confirm-link" href="post.php?delete_project=<?php echo $project_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>">
                                <i class="fas fa-fw fa-trash me-2"></i>Delete
                            </a>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card-group mb-3">
    <div class="card card-body">
        <h5 class="mb-0"><?= $project_name ?></h5>
        <div><small class="text-secondary"><?php echo $project_description; ?></small></div>
    </div>
    <div class="card card-body">
        <div><?php echo $client_name_display; ?></div>
        <div><?php echo $project_manager_display; ?></div>
        <div class='text-secondary'><i class='fa fa-fw fa-clock me-2'></i><?php echo $project_due; ?></div>
        <div><?php echo $project_completed_date_display; ?></div>
        <!-- Time tracking -->
        <?php if ($ticket_total_reply_time) { ?>
            <div>
                <i class="far fa-fw fa-clock text-secondary me-2"></i>Total time worked: <?php echo $ticket_total_reply_time; ?>
            </div>
        <?php } ?>
    </div>

    <div class="card card-body">
        <?php if ($ticket_count) { ?>
            <div class="progress" style="height: 20px;">
                <i class="fa fas fa-fw fa-life-ring me-2"></i>
                <div class="progress-bar bg-primary" style="width: <?php echo $tickets_closed_percent; ?>%;"><?php echo $closed_ticket_count; ?> / <?php echo $ticket_count; ?></div>
            </div>
        <?php } ?>
        <?php if ($task_count) { ?>
            <div class="progress mt-2" style="height: 20px;">
                <i class="fa fas fa-fw fa-tasks me-2"></i>
                <div class="progress-bar bg-secondary" style="width: <?php echo $tasks_completed_percent; ?>%;"><?php echo $completed_task_count; ?> / <?php echo $task_count; ?></div>
            </div>
        <?php } ?>
        <?php if ($ticket_collaborators) { ?>
            <div class=mt-1>
                <i class="fas fa-fw fa-users me-2 text-secondary"></i><?php echo $ticket_collaborators; ?>
            </div>
        <?php } ?>
    </div>
</div>

<!-- Budget & Effort card -->
<?php if ($show_budget_card) { ?>
    <div class="card mb-3">
        <div class="card-header py-2">
            <h5 class="card-title mt-2 mb-2"><i class="fas fa-fw fa-coins me-2"></i>Budget &amp; Effort</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-2 mb-md-0">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary"><i class="far fa-fw fa-clock me-2"></i>Hours</span>
                        <span><strong><?php echo number_format($actual_hours, 1); ?></strong> / <?php echo number_format($estimated_hours, 1); ?> hrs (<?php echo $hours_percent; ?>%)</span>
                    </div>
                    <div class="progress mt-1" style="height: 20px;">
                        <div class="progress-bar <?php echo $hours_percent > 100 ? 'bg-danger' : 'bg-info'; ?>" style="width: <?php echo min($hours_percent, 100); ?>%;"><?php echo $hours_percent; ?>%</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="d-flex justify-content-between">
                        <span class="text-secondary"><i class="fas fa-fw fa-dollar-sign me-2"></i>Budget</span>
                        <span><strong><?php echo numfmt_format_currency($currency_format, $burned_amount, $session_company_currency); ?></strong> / <?php echo numfmt_format_currency($currency_format, $project_budget_amount, $session_company_currency); ?> (<?php echo $budget_percent; ?>%)</span>
                    </div>
                    <div class="progress mt-1" style="height: 20px;">
                        <div class="progress-bar <?php echo $budget_percent > 100 ? 'bg-danger' : 'bg-success'; ?>" style="width: <?php echo min($budget_percent, 100); ?>%;"><?php echo $budget_percent; ?>%</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<div class="row">
    <div class="col-md-9">

        <!-- Milestones card -->
        <?php if ($milestone_count > 0) { ?>
            <div class="card mb-3">
                <div class="card-header py-2">
                    <h5 class="card-title mt-2 mb-2"><i class="fas fa-fw fa-flag-checkered me-2"></i>Milestones</h5>
                    <div class="card-tools">
                        <?php if (empty($project_completed_at)) { ?>
                            <a class="btn btn-sm btn-secondary ajax-modal" href="#" data-modal-url="modals/project/milestone_add.php?project_id=<?= $project_id ?>" title="New Milestone"><i class="fas fa-fw fa-plus"></i></a>
                        <?php } ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php foreach ($project_milestones as $ms) {
                        $ms_id = intval($ms['milestone_id']);
                        $ms_name = nullable_htmlentities($ms['milestone_name']);
                        $ms_description = nullable_htmlentities($ms['milestone_description']);
                        $ms_due = nullable_htmlentities($ms['milestone_due']);
                        $ms_status = nullable_htmlentities($ms['milestone_status']);
                        $ms_is_complete = ($ms_status == 'completed');

                        // Tasks belonging to this milestone
                        $ms_tasks = array_filter($project_tasks, function ($t) use ($ms_id) {
                            return intval($t['task_milestone_id']) === $ms_id;
                        });
                        $ms_total = count($ms_tasks);
                        $ms_done = 0;
                        foreach ($ms_tasks as $t) {
                            if (!empty($t['task_completed_at'])) { $ms_done++; }
                        }
                        if ($ms_total) {
                            $ms_percent = round(($ms_done / $ms_total) * 100);
                        } else {
                            $ms_percent = $ms_is_complete ? 100 : 0;
                        }
                        ?>
                        <div class="border-bottom p-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <?php if ($ms_is_complete) { ?>
                                        <span class="badge text-bg-success"><i class="fas fa-check"></i></span>
                                    <?php } ?>
                                    <strong><?php echo $ms_name; ?></strong>
                                    <?php if ($ms_due) { ?>
                                        <span class="badge text-bg-light border ms-1"><i class="far fa-calendar me-1"></i><?php echo $ms_due; ?></span>
                                    <?php } ?>
                                    <?php if ($ms_description) { ?>
                                        <div><small class="text-secondary"><?php echo $ms_description; ?></small></div>
                                    <?php } ?>
                                </div>
                                <div class="dropdown">
                                    <a href="#" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v text-secondary"></i></a>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <?php if (empty($project_completed_at)) { ?>
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>&milestone_id=<?= $ms_id ?>"><i class="fas fa-fw fa-plus me-2"></i>Add Task</a>
                                            <a class="dropdown-item confirm-link" href="post.php?toggle_milestone=<?= $ms_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-check me-2"></i><?= $ms_is_complete ? 'Reopen' : 'Mark Complete' ?></a>
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/milestone_edit.php?id=<?= $ms_id ?>"><i class="fas fa-fw fa-edit me-2"></i>Edit</a>
                                            <div class="dropdown-divider"></div>
                                        <?php } ?>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?delete_milestone=<?= $ms_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</a>
                                    </div>
                                </div>
                            </div>
                            <div class="progress mt-2" style="height: 16px;">
                                <div class="progress-bar <?php echo $ms_percent >= 100 ? 'bg-success' : 'bg-secondary'; ?>" style="width: <?php echo $ms_percent; ?>%;"><?php echo "$ms_done / $ms_total"; ?></div>
                            </div>
                            <?php if ($ms_total > 0) { ?>
                                <table class="table table-sm mt-2 mb-0">
                                    <?php foreach ($ms_tasks as $t) { $render_task_row($t); } ?>
                                </table>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <!-- End Milestones card -->

        <!-- Tickets card -->
        <?php if (mysqli_num_rows($sql_tickets) > 0) { ?>
            <div class="card mb-3">
                <div class="card-header py-2">

                    <h5 class="card-title mt-2 mb-2"><i class="fa fa-fw fa-life-ring me-2"></i>Project Tickets</h5>

                    <div class="card-tools">
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
                                </div>
                            </div>
                        <?php } ?>
                    </div>

                </div>

                <div class="card-body p-0">
                    <form id="bulkActions" action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">
                        <div class="table-responsive">
                            <table class="table table-border table-hover">
                                <thead class="thead-light">
                                <tr>
                                    <td class="bg-light checkbox-column">
                                        <div class="form-check">
                                            <input class="form-check-input" id="selectAllCheckbox" type="checkbox">
                                        </div>
                                    </td>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_number&order=<?php echo $disp; ?>">
                                            Ticket <?php if ($sort == 'ticket_number') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_priority&order=<?php echo $disp; ?>">
                                            Priority <?php if ($sort == 'ticket_priority') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_status&order=<?php echo $disp; ?>">
                                            Status <?php if ($sort == 'ticket_status') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=user_name&order=<?php echo $disp; ?>">
                                            Assigned <?php if ($sort == 'user_name') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=ticket_updated_at&order=<?php echo $disp; ?>">
                                            Last Response <?php if ($sort == 'ticket_updated_at') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                    <th>
                                        <a class="text-secondary" href="?<?php echo $url_query_strings_sort; ?>&sort=client_name&order=<?php echo $disp; ?>">
                                            Client <?php if ($sort == 'client_name') { echo $order_icon; } ?>
                                        </a>
                                    </th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php

                                while ($row = mysqli_fetch_assoc($sql_tickets)) {
                                    $ticket_id = intval($row['ticket_id']);
                                    $ticket_prefix = nullable_htmlentities($row['ticket_prefix']);
                                    $ticket_number = nullable_htmlentities($row['ticket_number']);
                                    $ticket_subject = nullable_htmlentities($row['ticket_subject']);
                                    $ticket_priority = nullable_htmlentities($row['ticket_priority']);
                                    $ticket_status = intval($row['ticket_status']);
                                    $ticket_status_name = nullable_htmlentities($row['ticket_status_name']);
                                    $ticket_status_color = nullable_htmlentities($row['ticket_status_color']);
                                    $ticket_billable = intval($row['ticket_billable']);
                                    $ticket_created_at = nullable_htmlentities($row['ticket_created_at']);
                                    $ticket_created_at_time_ago = timeAgo($row['ticket_created_at']);
                                    $ticket_updated_at = nullable_htmlentities($row['ticket_updated_at']);
                                    $ticket_updated_at_time_ago = timeAgo($row['ticket_updated_at']);
                                    if (empty($ticket_updated_at)) {
                                        if ($ticket_status == 5) {
                                            $ticket_updated_at_display = "<p>Never</p>";
                                        } else {
                                            $ticket_updated_at_display = "<p class='text-danger'>Never</p>";
                                        }
                                    } else {
                                        $ticket_updated_at_display = "$ticket_updated_at_time_ago<br><small class='text-secondary'>$ticket_updated_at</small>";
                                    }
                                    $ticket_closed_at = nullable_htmlentities($row['ticket_closed_at']);

                                    if ($ticket_priority == "High") {
                                        $ticket_priority_display = "<span class='p-2 badge text-bg-danger'>$ticket_priority</span>";
                                    } elseif ($ticket_priority == "Medium") {
                                        $ticket_priority_display = "<span class='p-2 badge text-bg-warning'>$ticket_priority</span>";
                                    } elseif ($ticket_priority == "Low") {
                                        $ticket_priority_display = "<span class='p-2 badge text-bg-info'>$ticket_priority</span>";
                                    } else{
                                        $ticket_priority_display = "-";
                                    }

                                    $ticket_assigned_to = intval($row['ticket_assigned_to']);
                                    if (empty($ticket_assigned_to)) {
                                        if ($ticket_status == 5) {
                                            $ticket_assigned_to_display = "<p>Not Assigned</p>";
                                        } else {
                                            $ticket_assigned_to_display = "<p class='text-danger'>Not Assigned</p>";
                                        }
                                    } else {
                                        $ticket_assigned_to_display = nullable_htmlentities($row['user_name']);
                                    }

                                    $project_id = intval($row['ticket_project_id']);

                                    $client_id = intval($row['client_id']);
                                    $client_name = nullable_htmlentities($row['client_name']);

                                    // Get who last updated the ticket - to be shown in the last Response column
                                    $ticket_reply_type = "Client"; // Default to client for unreplied tickets
                                    $ticket_reply_by_display = ""; // Default none
                                    $sql_ticket_reply = mysqli_query($mysqli, "SELECT ticket_reply_type, contact_name, user_name FROM ticket_replies
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
                                    }

                                    ?>

                                    <tr>
                                        <td class="bg-light checkbox-column">
                                            <!-- Ticket Bulk Select (for open tickets) -->
                                            <?php if (empty($ticket_closed_at)) { ?>
                                            <div class="form-check">
                                                <input class="form-check-input bulk-select" type="checkbox" name="ticket_ids[]" value="<?php echo $ticket_id ?>">
                                            </div>
                                            <?php } ?>
                                        </td>
                                        <!-- Ticket Number / Subject -->
                                        <td>
                                            <a href="ticket.php?ticket_id=<?php echo $ticket_id; ?>">
                                                <span class="badge rounded-pill text-bg-secondary p-3 me-2"><?php echo "$ticket_prefix$ticket_number"; ?></span>
                                                <?php echo $ticket_subject; ?>
                                            </a>
                                        </td>
                                        <!-- Ticket Priority -->
                                        <td><?php echo $ticket_priority_display; ?></a></td>

                                        <!-- Ticket Status -->
                                        <td>
                                            <span class='badge rounded-pill <?php echo tagTextClass($ticket_status_color); ?> p-2' style="background-color: <?php echo $ticket_status_color; ?>"><?php echo $ticket_status_name; ?></span>
                                        </td>

                                        <!-- Ticket Assigned agent -->
                                        <td><?php echo $ticket_assigned_to_display; ?></td>

                                        <!-- Ticket Last Response -->
                                        <td>
                                            <div><?php echo $ticket_updated_at_display; ?></div>
                                            <div><?php echo $ticket_reply_by_display; ?></div>
                                        </td>
                                        <td><?php echo $client_name; ?></td>
                                    </tr>

                                <?php } ?>

                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="col-md-3">

        <!-- Tasks Card (tasks not attached to a milestone) -->
        <?php $unassigned_tasks = array_filter($project_tasks, function ($t) { return empty($t['task_milestone_id']); }); ?>
        <div class="card">
            <div class="card-header py-2">
                <h5 class="card-title mt-2 mb-2"><i class="fas fa-fw fa-tasks me-2"></i><?= $milestone_count > 0 ? 'Other Tasks' : 'Tasks' ?></h5>
                <div class="card-tools">
                    <?php if (empty($project_completed_at)) { ?>
                        <a class="btn btn-sm btn-secondary ajax-modal" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>" title="New Task"><i class="fas fa-fw fa-plus"></i></a>
                    <?php } ?>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (count($unassigned_tasks) > 0) { ?>
                    <table class="table table-sm mb-0">
                        <?php foreach ($unassigned_tasks as $t) { $render_task_row($t); } ?>
                    </table>
                <?php } else { ?>
                    <div class="text-center text-secondary py-3"><small>No tasks</small></div>
                <?php } ?>
            </div>
        </div>
        <!-- End Tasks Card -->

    </div> <!-- End col-3 -->

</div> <!-- End row -->

<?php

}

require_once "../includes/footer.php";

?>

<script src="../js/bulk_actions.js"></script>
<script src="../js/pretty_content.js"></script>
