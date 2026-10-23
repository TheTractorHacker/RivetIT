<?php

// Project Kanban - task status lanes (To Do / In Progress / Blocked / Done)
// Mirrors project_details.php's include + permission pattern and the ticket
// kanban's SortableJS approach (rewired to vanilla fetch()).

if (isset($_GET['client_id'])) {
    require_once "includes/inc_all_client.php";
    $client_url = "client_id=" . intval($_GET['client_id']) . "&";
} else {
    require_once "includes/inc_all.php";
    $client_url = '';
}

enforceUserPermission('module_support');

$project_permission_snippet = '';
if (!empty($client_access_string)) {
    $project_permission_snippet = "AND (project_client_id IN ($client_access_string) OR project_client_id = 0)";
}

$project_id = intval($_GET['project_id'] ?? 0);

$sql_project = mysqli_query(
    $mysqli,
    "SELECT * FROM projects
     WHERE project_id = $project_id
     $project_permission_snippet
     LIMIT 1"
);

// Fallback title vars so footer.php always has something to render
$tab_title = "Project";
$page_title = "Kanban";

if (mysqli_num_rows($sql_project) == 0) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='projects.php'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}

$row = mysqli_fetch_assoc($sql_project);

$project_id = intval($row['project_id']);
$project_prefix = nullable_htmlentities($row['project_prefix']);
$project_number = intval($row['project_number']);
$project_name = nullable_htmlentities($row['project_name']);
$project_completed_at = nullable_htmlentities($row['project_completed_at']);
$project_client_id = intval($row['project_client_id']);

$tab_title = "{$row['project_prefix']}{$row['project_number']}";
$page_title = $row['project_name'];

// Fixed lanes
$lanes = ['To Do', 'In Progress', 'Blocked', 'Done'];
$lane_meta = [
    'To Do'       => ['icon' => 'fa-clipboard-list', 'badge' => 'text-bg-secondary'],
    'In Progress' => ['icon' => 'fa-hourglass-half',        'badge' => 'text-bg-info'],
    'Blocked'     => ['icon' => 'fa-ban',            'badge' => 'text-bg-danger'],
    'Done'        => ['icon' => 'fa-check',          'badge' => 'text-bg-success'],
];
$board = [];
foreach ($lanes as $l) {
    $board[$l] = [];
}

// Fetch project tasks (directly on the project, or via a project ticket)
$sql_tasks = mysqli_query(
    $mysqli,
    "SELECT tasks.*, tickets.ticket_prefix, tickets.ticket_number, users.user_name AS assigned_name,
            created_ticket.ticket_prefix AS created_ticket_prefix,
            created_ticket.ticket_number AS created_ticket_number
     FROM tasks
     LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
     LEFT JOIN tickets AS created_ticket ON created_ticket.ticket_id = tasks.task_created_ticket_id
     LEFT JOIN users ON users.user_id = tasks.task_assigned_to
     WHERE (tasks.task_project_id = $project_id OR tickets.ticket_project_id = $project_id)
     ORDER BY tasks.task_order ASC, tasks.task_created_at ASC"
);

while ($t = mysqli_fetch_assoc($sql_tasks)) {
    $lane = 'To Do';
    if (!empty($t['task_completed_at'])) {
        $lane = 'Done';
    } elseif (in_array(trim((string)$t['task_status']), $lanes, true)) {
        $lane = trim((string)$t['task_status']);
    } elseif (intval($t['task_progress']) >= 100) {
        $lane = 'Done';
    } elseif (intval($t['task_progress']) > 0) {
        $lane = 'In Progress';
    }
    $board[$lane][] = $t;
}

?>

<link rel="stylesheet" href="css/ticket_kanban.css?v=<?= file_exists(__DIR__ . '/css/ticket_kanban.css') ? filemtime(__DIR__ . '/css/ticket_kanban.css') : time() ?>">
<style>
    /* css/ticket_kanban.css hardcodes flat AdminLTE-3-era greys (#f4f4f4 column,
       #f9f9f9 status well, #fff/#ddd task cards) that clash with the light/dark
       design tokens and go flat white-on-white in dark mode. Re-skin this page's
       board with --if-* tokens instead of touching the shared stylesheet (other
       kanban boards also use it). */
    #kanban-board .kanban-column {
        background: var(--if-bg);
        border: 1px solid var(--if-border);
    }
    #kanban-board .kanban-status {
        background-color: var(--if-surface);
    }
    #kanban-board .task {
        background: var(--if-surface);
        border: 1px solid var(--if-border);
        box-shadow: var(--if-shadow);
        color: var(--if-ink);
    }
    /* Board layout: equal-width lanes that scroll sideways as a group instead of clipping the last one, a header that stays put, and no giant empty band. */
    #kanban-board { height: auto; gap: 12px; padding: 0 0 .75rem; align-items: flex-start; scroll-snap-type: x proximity; }
    #kanban-board .kanban-column { flex: 1 0 280px; min-width: 280px; margin: 0; padding: 0; min-height: 0; max-height: calc(100vh - 250px); overflow: hidden; scroll-snap-align: start; }
    #kanban-board .panel-title { display: flex; align-items: center; gap: .5rem; margin: 0; padding: .7rem .85rem; font-size: .9rem; font-weight: 600; border-bottom: 1px solid var(--if-border); background: var(--if-surface); position: sticky; top: 0; z-index: 1; }
    #kanban-board .panel-title .badge { float: none !important; margin-left: auto; }
    #kanban-board .kanban-status { padding: .6rem; min-height: 96px; }
    #kanban-board .task { position: relative; margin: 0 0 .55rem; padding: .7rem .8rem; display: flex; flex-direction: column; gap: .35rem; }
    #kanban-board .task:last-child { margin-bottom: 0; }
    #kanban-board .task > .d-flex strong { min-width: 0; overflow-wrap: anywhere; line-height: 1.3; }
    #kanban-board .task > .d-flex > .text-nowrap { display: flex; gap: .1rem; }
    #kanban-board .task > .d-flex > .text-nowrap a { padding: .1rem .2rem; border-radius: 4px; }
    #kanban-board .task > .mt-1 { margin-top: 0 !important; display: flex; flex-wrap: wrap; gap: .3rem; align-items: center; }
    /* touch drag handle sits inside the card, bottom-right, instead of floating outside it */
    #kanban-board .task .drag-handle-class { position: absolute; right: .5rem; bottom: .45rem; float: none; padding: .1rem .45rem; font-size: .75rem; }
    .sortable-ghost { opacity: .55; outline: 2px dashed var(--bs-primary, #206bc4); outline-offset: -2px; background: rgba(var(--bs-primary-rgb, 32, 107, 196), .08) !important; }
    .project-new-task { min-height: 2.5rem; padding-inline: 1.1rem; font-weight: 600; }
</style>

<!-- Breadcrumbs-->
<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="projects.php">Projects</a></li>
    <li class="breadcrumb-item"><a href="project_details.php?<?= $client_url ?>project_id=<?= $project_id ?>"><?= "$project_prefix$project_number" ?></a></li>
    <li class="breadcrumb-item active">Kanban</li>
</ol>

<!-- Project view tabs -->
<div class="d-flex justify-content-between align-items-center flex-wrap mb-3 d-print-none">
    <ul class="nav nav-pills mb-0">
        <li class="nav-item">
            <a class="nav-link" href="project_details.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-info-circle me-2"></i>Details</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="project_gantt.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-stream me-2"></i>Gantt</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="project_kanban.php?<?= $client_url ?>project_id=<?= $project_id ?>"><i class="fas fa-fw fa-columns me-2"></i>Kanban</a>
        </li>
    </ul>
    <?php if (empty($project_completed_at)) { ?>
        <a class="btn btn-primary ajax-modal project-new-task" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>">
            <i class="fas fa-fw fa-plus me-2"></i>New Task
        </a>
    <?php } ?>
</div>

<div class="container-fluid" id="kanban-board">
    <?php foreach ($lanes as $lane) {
        $meta = $lane_meta[$lane];
        $lane_tasks = $board[$lane];
        ?>
        <div class="kanban-column card card-dark" data-status="<?= $lane ?>">
            <h6 class="panel-title">
                <i class="fas fa-fw <?= $meta['icon'] ?> me-1"></i><?= $lane ?>
                <span class="badge <?= $meta['badge'] ?> float-end"><?= count($lane_tasks) ?></span>
            </h6>

            <div class="kanban-status" data-status="<?= $lane ?>">
                <?php foreach ($lane_tasks as $item) {
                    $t_id = intval($item['task_id']);
                    $t_name = nullable_htmlentities($item['task_name']);
                    $t_due = nullable_htmlentities($item['task_due']);
                    $t_progress = intval($item['task_progress']);
                    $t_ticket_id = intval($item['task_ticket_id']);
                    $t_prefix = nullable_htmlentities($item['ticket_prefix'] ?? '');
                    $t_number = nullable_htmlentities($item['ticket_number'] ?? '');
                    $t_assigned = nullable_htmlentities($item['assigned_name'] ?? '');
                    // See project_details.php's render_task_row for why this is a
                    // separate column/relationship from task_ticket_id above.
                    $t_created_ticket_id = intval($item['task_created_ticket_id'] ?? 0);
                    $t_created_ticket_prefix = nullable_htmlentities($item['created_ticket_prefix'] ?? '');
                    $t_created_ticket_number = nullable_htmlentities($item['created_ticket_number'] ?? '');
                    $create_ticket_url = "modals/ticket/ticket_add_v2.php?client_id=$project_client_id&project_id=$project_id&source_task_id=$t_id&subject=" . urlencode($item['task_name'] ?? '');
                    ?>
                    <div class="task grab-cursor" data-task-id="<?= $t_id ?>" data-task-status="<?= $lane ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <strong><?= $t_name ?></strong>
                            <div class="text-nowrap ms-2">
                                <?php if (!$t_created_ticket_id && empty($project_completed_at)) { ?>
                                    <a href="#" class="ajax-modal text-secondary" data-modal-url="<?= $create_ticket_url ?>" data-modal-size="lg" title="Create a ticket from this task">
                                        <i class="fas fa-fw fa-life-ring"></i>
                                    </a>
                                <?php } ?>
                                <a href="#" class="ajax-modal text-secondary" data-modal-url="modals/project/task_edit.php?id=<?= $t_id ?>" title="Edit task">
                                    <i class="fas fa-fw fa-pen"></i>
                                </a>
                            </div>
                        </div>

                        <div class="btn btn-light drag-handle-class" style="display:none;">
                            <i class="drag-handle-class fas fa-bars"></i>
                        </div>

                        <div class="mt-1">
                            <?php if ($t_ticket_id) { ?>
                                <a href="ticket.php?ticket_id=<?= $t_ticket_id ?>" class="badge text-bg-light border"><?= "$t_prefix$t_number" ?></a>
                            <?php } ?>
                            <?php if ($t_created_ticket_id) { ?>
                                <a href="ticket.php?ticket_id=<?= $t_created_ticket_id ?>" class="badge text-bg-primary" title="Ticket created from this task"><i class="fas fa-fw fa-life-ring me-1"></i><?= "$t_created_ticket_prefix$t_created_ticket_number" ?></a>
                            <?php } ?>
                            <?php if ($t_due) { ?>
                                <span class="badge text-bg-light border"><i class="far fa-calendar me-1"></i><?= $t_due ?></span>
                            <?php } ?>
                            <?php if ($t_progress > 0) { ?>
                                <span class="badge text-bg-info"><?= $t_progress ?>%</span>
                            <?php } ?>
                        </div>

                        <?php if ($t_assigned) { ?>
                            <div class="mt-1"><i class="fas fa-fw fa-user me-1 text-secondary"></i><?= $t_assigned ?></div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>

<?php require_once "../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    const PROJECT_KANBAN_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>
<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script src="js/project_kanban.js?v=<?= file_exists(__DIR__ . '/js/project_kanban.js') ? filemtime(__DIR__ . '/js/project_kanban.js') : time() ?>"></script>
