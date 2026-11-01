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

require_once __DIR__ . '/../includes/project_tasks.php';

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
$all_task_ids = [];
foreach ($lanes as $l) {
    $board[$l] = [];
}

// Subtasks (DB 2.6.208) live in their parent's pop-up and show as a count on the parent's card, not as cards of their own
$subtask_filter = projectTaskPhase2Ready($mysqli) ? 'AND tasks.task_parent_id IS NULL' : '';

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
     $subtask_filter
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
    $all_task_ids[] = intval($t['task_id']);
}

// Priority / description / colour / labels exist from DB 2.6.207; before Update Database everything below degrades to the old card.
$phase1_ready = projectTaskPhase1Ready($mysqli);
$task_labels = $phase1_ready ? projectTaskLabelsByTask($mysqli, $all_task_ids) : [];
$board_labels = [];      // labels in use on this board, for the filter
$board_assignees = [];   // user_id => name of everyone assigned to a card on this board, for the filter
// Checklist / subtask / comment / file counts (DB 2.6.208 / 2.6.209) and every assignee of every card, each in one query
$phase2_ready = projectTaskPhase2Ready($mysqli);
$phase3_ready = projectTaskPhase3Ready($mysqli);
$task_counts = projectTaskCounts($mysqli, $all_task_ids);
$task_people = projectTaskAssigneesByTask($mysqli, $all_task_ids);
foreach ($task_people as $people) {
    foreach ($people as $p) { $board_assignees[$p['user_id']] = $p['user_name']; }
}
asort($board_assignees, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($task_labels as $lbls) {
    foreach ($lbls as $l) { $board_labels[$l['label_id']] = $l['label_name']; }
}
asort($board_labels, SORT_NATURAL | SORT_FLAG_CASE);
$today = date('Y-m-d');
$can_delete_task = lookupUserPermission('module_support') >= 3;
$can_write_task = lookupUserPermission('module_support') >= 2;

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
        border-left: 4px solid var(--task-prio, var(--if-border));
        box-shadow: var(--if-shadow);
        color: var(--if-ink);
    }
    /* Board layout: equal-width lanes that scroll sideways as a group instead of clipping the last one, a header that stays put, and no giant empty band. */
    #kanban-board { min-width: 0; max-width: 100%; height: auto; gap: 12px; padding: 0 0 .75rem; align-items: flex-start; scroll-snap-type: x proximity; }
    #kanban-board .kanban-column { flex: 1 0 280px; min-width: 280px; margin: 0; padding: 0; min-height: 0; max-height: calc(100vh - 290px); overflow: hidden; scroll-snap-align: start; display: flex; flex-direction: column; }
    #kanban-board .panel-title { display: flex; align-items: center; gap: .5rem; margin: 0; padding: .7rem .85rem; font-size: .9rem; font-weight: 600; border-bottom: 1px solid var(--if-border); background: var(--if-surface); position: sticky; top: 0; z-index: 1; flex: 0 0 auto; }
    #kanban-board .panel-title .badge { float: none !important; margin-left: auto; }
    #kanban-board .kanban-status { padding: .5rem; min-height: 96px; overflow-y: auto; flex: 1 1 auto; }
    /* Whole card is the drag surface (no handle). Touch: press and hold ~250 ms to lift; a quick swipe still scrolls the lane/page. */
    #kanban-board .task { position: relative; margin: 0 0 .45rem; padding: .5rem .6rem; display: flex; flex-direction: column; gap: .3rem; font-size: .85rem; }
    #kanban-board .task:last-child { margin-bottom: 0; }
    #kanban-board .task.task-accent { box-shadow: inset 0 3px 0 var(--task-accent), var(--if-shadow); }
    #kanban-board .task-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .35rem; }
    #kanban-board .task-title { min-width: 0; overflow-wrap: anywhere; line-height: 1.3; font-weight: 600; cursor: pointer; }
    #kanban-board .task-title:hover, #kanban-board .task-title:focus-visible { text-decoration: underline; }
    #kanban-board .task-tools { display: flex; gap: .1rem; flex: 0 0 auto; margin: -.15rem -.25rem 0 0; }
    #kanban-board .task-tools .btn { padding: .1rem .35rem; line-height: 1.2; min-width: 1.9rem; min-height: 1.9rem; color: var(--bs-secondary-color, #6c757d); }
    #kanban-board .task-meta { display: flex; flex-wrap: wrap; gap: .25rem; align-items: center; }
    #kanban-board .task-meta .badge { font-weight: 500; }
    #kanban-board .task-meta a, #kanban-board .task-tools a { -webkit-user-drag: none; }
    #kanban-board .task-prio-badge { color: #fff; }
    #kanban-board .task-label-chip { font-weight: 500; }
    #kanban-board .task-progress { height: 4px; border-radius: 2px; background: var(--if-border); overflow: hidden; }
    #kanban-board .task-progress > span { display: block; height: 100%; background: var(--bs-info, #4299e1); }
    #kanban-board .task-foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; flex-wrap: wrap; }
    #kanban-board .task-stats { display: flex; flex-wrap: wrap; gap: .5rem; font-size: .75rem; color: var(--bs-secondary-color, #6c757d); }
    #kanban-board .task-stat { white-space: nowrap; }
    #kanban-board .task-stat i { margin-right: .15rem; }
    #kanban-board .task-stat.is-complete { color: var(--bs-success, #2fb344); }
    #kanban-board .task-avatars { display: inline-flex; align-items: center; margin-left: auto; min-width: 0; }
    #kanban-board .task-avatars .task-avatar { margin-left: -.35rem; border: 2px solid var(--if-surface); }
    #kanban-board .task-avatars .task-avatar:first-child { margin-left: 0; }
    #kanban-board .task-avatar.is-primary { background: var(--bs-primary, #206bc4); color: #fff; }
    #kanban-board .task-avatar-more { font-size: .6rem; }
    #kanban-board .task-assignee-name { margin-left: .35rem; font-size: .78rem; color: var(--bs-secondary-color, #6c757d); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 9rem; }
    #kanban-board .task-assignee { display: flex; align-items: center; gap: .35rem; font-size: .78rem; color: var(--bs-secondary-color, #6c757d); }
    #kanban-board .task-avatar { width: 1.35rem; height: 1.35rem; border-radius: 50%; background: var(--if-border); color: var(--if-ink); display: inline-flex; align-items: center; justify-content: center; font-size: .65rem; font-weight: 600; flex: 0 0 auto; }
    #kanban-board .task.is-filtered-out { display: none; }
    #kanban-board .task .dropdown-menu { font-size: .85rem; }
    /* Keep the page from selecting text / opening the iOS callout while a card is being held to drag */
    @media (pointer: coarse) {
        #kanban-board .task { -webkit-touch-callout: none; -webkit-user-select: none; user-select: none; }
    }
    .sortable-ghost { opacity: .55; outline: 2px dashed var(--bs-primary, #206bc4); outline-offset: -2px; background: rgba(var(--bs-primary-rgb, 32, 107, 196), .08) !important; }
    .sortable-chosen { box-shadow: 0 6px 18px rgba(0, 0, 0, .25) !important; }
    .project-new-task { min-height: 2.5rem; padding-inline: 1.1rem; font-weight: 600; }
    #kanban-toolbar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; margin-bottom: .75rem; }
    #kanban-toolbar .form-select { width: auto; min-width: 10.5rem; max-width: 100%; }
    @media (max-width: 575.98px) {
        #kanban-toolbar .form-select { flex: 1 1 8.5rem; }
        #kanban-board .kanban-column { flex-basis: 85vw; min-width: 85vw; max-height: none; }
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
        <a class="btn btn-primary ajax-modal project-new-task" href="#" data-modal-url="modals/project/task_add.php?project_id=<?= $project_id ?>">
            <i class="fas fa-fw fa-plus me-2"></i>New Task
        </a>
    <?php } ?>
</div>

<!-- Sort and filter (view only: nothing here changes saved order) -->
<div id="kanban-toolbar" class="d-print-none" data-project-id="<?= $project_id ?>">
    <?php if ($phase1_ready) { ?>
        <select class="form-select form-select-sm" id="kanban-sort" aria-label="Sort cards in each lane">
            <option value="manual">Sort: manual order</option>
            <option value="priority">Sort: priority</option>
        </select>
        <select class="form-select form-select-sm" id="kanban-filter-priority" aria-label="Filter by priority">
            <option value="">Any priority</option>
            <?php foreach (projectTaskPriorities() as $pv => $pm) { ?>
                <option value="<?= $pv ?>"><?= nullable_htmlentities($pm['name']) ?><?= $pv === 0 ? ' (unset)' : '' ?></option>
            <?php } ?>
        </select>
        <?php if ($board_labels) { ?>
            <select class="form-select form-select-sm" id="kanban-filter-label" aria-label="Filter by label">
                <option value="">Any label</option>
                <?php foreach ($board_labels as $lid => $lname) { ?>
                    <option value="<?= intval($lid) ?>"><?= nullable_htmlentities($lname) ?></option>
                <?php } ?>
            </select>
        <?php } ?>
    <?php } ?>
    <select class="form-select form-select-sm" id="kanban-filter-assignee" aria-label="Filter by assignee">
        <option value="">Anyone</option>
        <option value="0">Unassigned</option>
        <?php foreach ($board_assignees as $uid => $uname) { ?>
            <option value="<?= intval($uid) ?>"><?= nullable_htmlentities($uname) ?></option>
        <?php } ?>
    </select>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="kanban-filter-clear" hidden><i class="fas fa-times me-1"></i>Clear filters</button>
    <span class="text-secondary small ms-auto" id="kanban-showing" aria-live="polite"></span>
</div>

<div class="container-fluid" id="kanban-board" data-priority-ready="<?= $phase1_ready ? '1' : '0' ?>">
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
                <?php foreach ($lane_tasks as $order_index => $item) {
                    $t_id = intval($item['task_id']);
                    $t_name = nullable_htmlentities($item['task_name']);
                    $t_due = nullable_htmlentities($item['task_due']);
                    $t_overdue = !empty($item['task_due']) && $item['task_due'] < $today && $lane !== 'Done';
                    $t_progress = intval($item['task_progress']);
                    $t_ticket_id = intval($item['task_ticket_id']);
                    $t_prefix = nullable_htmlentities($item['ticket_prefix'] ?? '');
                    $t_number = nullable_htmlentities($item['ticket_number'] ?? '');
                    $t_assigned_id = intval($item['task_assigned_to'] ?? 0);
                    $t_people = $task_people[$t_id] ?? [];
                    $t_people_ids = implode(',', array_column($t_people, 'user_id'));
                    $t_counts = $task_counts[$t_id] ?? ['chk_done' => 0, 'chk_total' => 0, 'sub_done' => 0, 'sub_total' => 0, 'comments' => 0, 'files' => 0];
                    $t_repeats = $phase3_ready && intval($item['task_repeat_interval'] ?? 0) > 0;
                    $t_reminder = $phase3_ready && !empty($item['task_reminder_at']) && empty($item['task_reminder_sent_at']);
                    $t_priority = $phase1_ready ? projectTaskNormalizePriority($item['task_priority'] ?? 0) : 0;
                    $t_prio_meta = projectTaskPriorityMeta($t_priority);
                    $t_color = $phase1_ready ? projectTaskNormalizeColor($item['task_color'] ?? '') : null;
                    $t_has_desc = $phase1_ready && trim((string) ($item['task_description'] ?? '')) !== '';
                    $t_labels = $task_labels[$t_id] ?? [];
                    $t_label_ids = implode(',', array_column($t_labels, 'label_id'));
                    // See project_details.php's render_task_row for why this is a
                    // separate column/relationship from task_ticket_id above.
                    $t_created_ticket_id = intval($item['task_created_ticket_id'] ?? 0);
                    $t_created_ticket_prefix = nullable_htmlentities($item['created_ticket_prefix'] ?? '');
                    $t_created_ticket_number = nullable_htmlentities($item['created_ticket_number'] ?? '');
                    $create_ticket_url = "modals/ticket/ticket_add_v2.php?client_id=$project_client_id&project_id=$project_id&source_task_id=$t_id&subject=" . urlencode($item['task_name'] ?? '');
                    $detail_url = "modals/project/task_detail.php?id=$t_id";
                    $is_done = $lane === 'Done';
                    ?>
                    <div class="task grab-cursor<?= $t_color ? ' task-accent' : '' ?>" data-task-id="<?= $t_id ?>" data-task-status="<?= $lane ?>"
                         data-order="<?= $order_index ?>" data-priority="<?= $t_priority ?>" data-labels="<?= $t_label_ids ?>" data-assignee="<?= $t_assigned_id ?>" data-assignees="<?= $t_people_ids ?>"
                         style="<?= $t_priority > 0 ? '--task-prio:' . $t_prio_meta['color'] . ';' : '' ?><?= $t_color ? '--task-accent:' . $t_color . ';' : '' ?>">
                        <div class="task-head">
                            <span class="task-title ajax-modal" role="button" tabindex="0" data-modal-url="<?= $detail_url ?>" data-modal-size="lg" title="Open task details"><?= $t_name ?></span>
                            <div class="task-tools">
                                <button type="button" class="btn btn-sm btn-link ajax-modal" data-modal-url="<?= $detail_url ?>" data-modal-size="lg" title="Open task details" aria-label="Open task details: <?= $t_name ?>">
                                    <i class="fas fa-fw fa-expand-alt"></i>
                                </button>
                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-link task-menu-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Task actions: <?= $t_name ?>" title="Task actions">
                                        <i class="fas fa-fw fa-ellipsis-v"></i>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/project/task_edit.php?id=<?= $t_id ?>"><i class="fas fa-fw fa-pen me-2"></i>Edit</a>
                                        <?php if (!$t_created_ticket_id && empty($project_completed_at)) { ?>
                                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="<?= $create_ticket_url ?>" data-modal-size="lg"><i class="fas fa-fw fa-life-ring me-2"></i>Create ticket</a>
                                        <?php } ?>
                                        <?php if ($can_write_task) { ?>
                                            <?php if ($is_done) { ?>
                                                <a class="dropdown-item" href="post.php?undo_complete_task=<?= $t_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="far fa-fw fa-square me-2"></i>Mark incomplete</a>
                                            <?php } else { ?>
                                                <a class="dropdown-item" href="post.php?complete_task=<?= $t_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"><i class="far fa-fw fa-check-square me-2"></i>Mark complete</a>
                                            <?php } ?>
                                            <?php if (!$t_ticket_id) { ?>
                                                <button type="button" class="dropdown-item" data-task-action="duplicate_project_task" data-task-id="<?= $t_id ?>"><i class="far fa-fw fa-copy me-2"></i>Duplicate</button>
                                            <?php } ?>
                                        <?php } ?>
                                        <?php if ($can_delete_task) { ?>
                                            <div class="dropdown-divider"></div>
                                            <button type="button" class="dropdown-item text-danger" data-task-action="delete_project_task" data-task-id="<?= $t_id ?>" data-task-name="<?= $t_name ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete</button>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="task-meta">
                            <?php if ($t_priority > 0) { ?>
                                <span class="badge task-prio-badge" style="background: <?= $t_prio_meta['color'] ?>;" title="<?= nullable_htmlentities($t_prio_meta['name']) ?> priority"><i class="fas fa-fw <?= $t_prio_meta['icon'] ?> me-1"></i><?= nullable_htmlentities($t_prio_meta['name']) ?></span>
                            <?php } ?>
                            <?php foreach ($t_labels as $lbl) { echo projectTaskLabelChip($lbl); } ?>
                            <?php if ($t_has_desc) { ?>
                                <span class="text-secondary" title="Has a description"><i class="fas fa-fw fa-align-left"></i><span class="visually-hidden">Has a description</span></span>
                            <?php } ?>
                            <?php if ($t_ticket_id) { ?>
                                <a href="ticket.php?ticket_id=<?= $t_ticket_id ?>" draggable="false" class="badge text-bg-light border"><?= "$t_prefix$t_number" ?></a>
                            <?php } ?>
                            <?php if ($t_created_ticket_id) { ?>
                                <a href="ticket.php?ticket_id=<?= $t_created_ticket_id ?>" draggable="false" class="badge text-bg-primary" title="Ticket created from this task"><i class="fas fa-fw fa-life-ring me-1"></i><?= "$t_created_ticket_prefix$t_created_ticket_number" ?></a>
                            <?php } ?>
                            <?php if ($t_due) { ?>
                                <span class="badge <?= $t_overdue ? 'text-bg-danger' : 'text-bg-light border' ?>" title="<?= $t_overdue ? 'Overdue' : 'Due date' ?>"><i class="<?= $t_overdue ? 'fas fa-exclamation-circle' : 'far fa-calendar' ?> me-1"></i><?= $t_overdue ? 'Overdue ' : '' ?><?= $t_due ?></span>
                            <?php } ?>
                            <?php if ($t_progress > 0 && $t_progress < 100) { ?>
                                <span class="badge text-bg-info"><?= $t_progress ?>%</span>
                            <?php } ?>
                        </div>

                        <?php if ($t_progress > 0) { ?>
                            <div class="task-progress" role="progressbar" aria-valuenow="<?= $t_progress ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Progress <?= $t_progress ?>%"><span style="width: <?= $t_progress ?>%;"></span></div>
                        <?php } ?>

                        <?php
                        $has_stats = $t_counts['chk_total'] || $t_counts['sub_total'] || $t_counts['comments'] || $t_counts['files'] || $t_repeats || $t_reminder;
                        if ($has_stats || $t_people) { ?>
                            <div class="task-foot">
                                <div class="task-stats">
                                    <?php if ($t_counts['chk_total']) { ?>
                                        <span class="task-stat<?= $t_counts['chk_done'] === $t_counts['chk_total'] ? ' is-complete' : '' ?>" title="Checklist: <?= $t_counts['chk_done'] ?> of <?= $t_counts['chk_total'] ?> done"><i class="far fa-fw fa-check-square"></i><?= $t_counts['chk_done'] ?>/<?= $t_counts['chk_total'] ?></span>
                                    <?php } ?>
                                    <?php if ($t_counts['sub_total']) { ?>
                                        <span class="task-stat<?= $t_counts['sub_done'] === $t_counts['sub_total'] ? ' is-complete' : '' ?>" title="Subtasks: <?= $t_counts['sub_done'] ?> of <?= $t_counts['sub_total'] ?> done"><i class="fas fa-fw fa-sitemap"></i><?= $t_counts['sub_done'] ?>/<?= $t_counts['sub_total'] ?></span>
                                    <?php } ?>
                                    <?php if ($t_counts['comments']) { ?>
                                        <span class="task-stat" title="<?= $t_counts['comments'] ?> comment(s)"><i class="far fa-fw fa-comment"></i><?= $t_counts['comments'] ?></span>
                                    <?php } ?>
                                    <?php if ($t_counts['files']) { ?>
                                        <span class="task-stat" title="<?= $t_counts['files'] ?> file(s)"><i class="fas fa-fw fa-paperclip"></i><?= $t_counts['files'] ?></span>
                                    <?php } ?>
                                    <?php if ($t_repeats) { ?>
                                        <span class="task-stat" title="Repeats every <?= intval($item['task_repeat_interval']) ?> <?= nullable_htmlentities($item['task_repeat_unit']) ?>(s)"><i class="fas fa-fw fa-redo"></i><span class="visually-hidden">Repeating task</span></span>
                                    <?php } ?>
                                    <?php if ($t_reminder) { ?>
                                        <span class="task-stat" title="Reminder: <?= nullable_htmlentities($item['task_reminder_at']) ?>"><i class="far fa-fw fa-bell"></i><span class="visually-hidden">Has a reminder</span></span>
                                    <?php } ?>
                                </div>
                                <?php if ($t_people) { ?>
                                    <div class="task-avatars" title="<?= nullable_htmlentities(implode(', ', array_column($t_people, 'user_name'))) ?>">
                                        <?php foreach (array_slice($t_people, 0, 3) as $person) {
                                            $initials = '';
                                            foreach (preg_split('/\s+/', trim($person['user_name'])) as $part) { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
                                            ?>
                                            <span class="task-avatar<?= $person['primary'] ? ' is-primary' : '' ?>" title="<?= nullable_htmlentities($person['user_name']) ?>"><?= nullable_htmlentities(mb_substr($initials, 0, 2)) ?></span>
                                        <?php } ?>
                                        <?php if (count($t_people) > 3) { ?><span class="task-avatar task-avatar-more">+<?= count($t_people) - 3 ?></span><?php } ?>
                                        <?php if (count($t_people) === 1) { ?><span class="task-assignee-name"><?= nullable_htmlentities($t_people[0]['user_name']) ?></span><?php } ?>
                                    </div>
                                <?php } ?>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            </div>
        </div>
    <?php } ?>
</div>

<!-- Per-card menu actions (duplicate / delete) post through this one form -->
<form id="task-action-form" action="post.php" method="post" hidden>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="task_id" value="">
</form>

<?php require_once "../includes/footer.php"; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
    const PROJECT_KANBAN_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;
</script>
<script src="../plugins/SortableJS/Sortable.min.js"></script>
<script src="js/project_kanban.js?v=<?= file_exists(__DIR__ . '/js/project_kanban.js') ? filemtime(__DIR__ . '/js/project_kanban.js') : time() ?>"></script>
