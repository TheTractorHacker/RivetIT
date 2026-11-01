<?php

// Task detail pop-up: every field of a project task, editable, plus the basic actions (complete, duplicate, move, delete).
// Opened from the Kanban card title / expand icon and from the project's task list.

require_once '../../../includes/modal_header.php';
require_once '../../../includes/project_tasks.php';
require_once '../../../includes/project_task_form.php';
require_once '../../../includes/project_task_panels.php';

$task_id = intval($_GET['id'] ?? 0);

$ctx = projectTaskContext($mysqli, $task_id);
if (!$ctx) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'That task no longer exists.']);
    exit;
}
$row = $ctx['row'];
$task_project_id = $ctx['project_id'];
$client_id = $ctx['client_id'];
if ($client_id) {
    enforceClientAccess($client_id);
}

$task_name = nullable_htmlentities($row['task_name']);
$task_priority = projectTaskNormalizePriority($row['task_priority'] ?? 0);
$priority_meta = projectTaskPriorityMeta($task_priority);
$task_color = projectTaskNormalizeColor($row['task_color'] ?? '');
$t_completed = !empty($row['task_completed_at']);
$t_ticket_id = intval($row['task_ticket_id']);
$t_created_ticket_id = intval($row['task_created_ticket_id'] ?? 0);
$is_project_task = !$t_ticket_id && $task_project_id;
$can_delete = lookupUserPermission('module_support') >= 3;
$can_write = lookupUserPermission('module_support') >= 2;
$ready = projectTaskPhase1Ready($mysqli);
$labels = $ready ? (projectTaskLabelsByTask($mysqli, [$task_id])[$task_id] ?? []) : [];

// Other (open) projects the person may move this task to
$move_targets = [];
if ($is_project_task && $can_write) {
    $project_scope = (!empty($client_access_string) && empty($session_is_admin)) ? "AND (project_client_id IN ($client_access_string) OR project_client_id = 0)" : '';
    $sql_projects = mysqli_query($mysqli, "SELECT project_id, project_prefix, project_number, project_name FROM projects WHERE project_completed_at IS NULL AND project_id <> $task_project_id $project_scope ORDER BY project_name ASC LIMIT 300");
    while ($p = mysqli_fetch_assoc($sql_projects)) {
        $move_targets[] = $p;
    }
}

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fas fa-fw fa-tasks me-2"></i><strong><?= $task_name ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="task_id" value="<?= $task_id ?>">
    <?php /* Enter in a field must Save, not hit whichever action button comes first */ ?>
    <button type="submit" name="edit_project_task" value="1" class="d-none" tabindex="-1" aria-hidden="true"></button>
    <div class="modal-body">

        <div class="d-flex flex-wrap align-items-center gap-2 mb-3 task-detail-summary" <?= $task_color ? 'style="border-left: 4px solid ' . $task_color . '; padding-left: .6rem;"' : '' ?>>
            <?php if ($ready) { ?>
                <span class="badge text-white" style="background: <?= $priority_meta['color'] ?>;"><i class="fas fa-fw <?= $priority_meta['icon'] ?> me-1"></i><?= nullable_htmlentities($priority_meta['name']) ?> priority</span>
            <?php } ?>
            <?php if ($t_completed) { ?>
                <span class="badge text-bg-success"><i class="fas fa-fw fa-check me-1"></i>Completed <?= nullable_htmlentities($row['task_completed_at']) ?></span>
            <?php } ?>
            <?php foreach ($labels as $l) { echo projectTaskLabelChip($l); } ?>
            <?php if ($t_ticket_id) { ?>
                <a href="ticket.php?ticket_id=<?= $t_ticket_id ?>" class="badge text-bg-light border">Checklist item of a ticket</a>
            <?php } ?>
            <?php if ($t_created_ticket_id) { ?>
                <a href="ticket.php?ticket_id=<?= $t_created_ticket_id ?>" class="badge text-bg-primary"><i class="fas fa-fw fa-life-ring me-1"></i>Ticket created from this task</a>
            <?php } ?>
            <span class="text-secondary small ms-auto">Created <?= nullable_htmlentities($row['task_created_at']) ?></span>
        </div>

        <?php projectTaskRenderFields($mysqli, ['project_id' => $task_project_id, 'task' => $row]); ?>

        <?php /* Checklist, subtasks, comments, files (DB 2.6.208 / 2.6.209): their own buttons, saved as you go, separate from the form's Save */ ?>
        <?= projectTaskRenderPanels($mysqli, $ctx, ['write' => $can_write, 'user_id' => intval($session_user_id), 'is_admin' => !empty($session_is_admin), 'can_delete_file' => $can_delete || !empty($session_is_admin)]) ?>

        <?php if ($move_targets) { ?>
            <hr>
            <div class="form-group mb-0">
                <label>Move to another project</label>
                <div class="input-group">
                    <select class="form-control" name="target_project_id">
                        <option value="0">- Choose a project -</option>
                        <?php foreach ($move_targets as $p) { ?>
                            <option value="<?= intval($p['project_id']) ?>"><?= nullable_htmlentities($p['project_prefix'] . $p['project_number'] . ' ' . $p['project_name']) ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" name="move_project_task" value="1" class="btn btn-outline-secondary" formnovalidate><i class="fas fa-fw fa-arrow-right me-1"></i>Move</button>
                </div>
                <small class="text-secondary">Labels that belong to this project do not move with the task.</small>
            </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($can_delete) { ?>
            <button type="submit" name="delete_project_task" value="1" class="btn btn-outline-danger me-auto js-confirm-native" data-confirm-message="Delete this task? This cannot be undone." formnovalidate>
                <i class="fas fa-trash me-2"></i>Delete
            </button>
        <?php } ?>
        <?php if ($is_project_task && $can_write) { ?>
            <button type="submit" name="duplicate_project_task" value="1" class="btn btn-outline-secondary" formnovalidate>
                <i class="fas fa-copy me-2"></i>Duplicate
            </button>
        <?php } ?>
        <button type="submit" name="edit_project_task" value="1" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Save
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Close
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
