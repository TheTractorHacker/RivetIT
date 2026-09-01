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

$tab_title = "{$row['project_prefix']}{$row['project_number']}";
$page_title = $row['project_name'];

// Fixed lanes
$lanes = ['To Do', 'In Progress', 'Blocked', 'Done'];
$lane_meta = [
    'To Do'       => ['icon' => 'fa-clipboard-list', 'badge' => 'text-bg-secondary'],
    'In Progress' => ['icon' => 'fa-spinner',        'badge' => 'text-bg-info'],
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
    "SELECT tasks.*, tickets.ticket_prefix, tickets.ticket_number, users.user_name AS assigned_name
     FROM tasks
     LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
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

<link rel="stylesheet" href="css/ticket_kanban.css">
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
        <a class="btn btn-sm btn-primary ajax-modal" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>">
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
                    ?>
                    <div class="task grab-cursor" data-task-id="<?= $t_id ?>" data-task-status="<?= $lane ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <strong><?= $t_name ?></strong>
                            <a href="#" class="ajax-modal text-secondary ms-2" data-modal-url="modals/project/task_edit.php?id=<?= $t_id ?>" title="Edit task">
                                <i class="fas fa-fw fa-pen"></i>
                            </a>
                        </div>

                        <div class="btn btn-light drag-handle-class" style="display:none;">
                            <i class="drag-handle-class fas fa-bars"></i>
                        </div>

                        <div class="mt-1">
                            <?php if ($t_ticket_id) { ?>
                                <a href="ticket.php?ticket_id=<?= $t_ticket_id ?>" class="badge text-bg-light border"><?= "$t_prefix$t_number" ?></a>
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
