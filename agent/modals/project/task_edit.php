<?php

require_once '../../../includes/modal_header.php';
require_once '../../../includes/project_tasks.php';
require_once '../../../includes/project_task_form.php';

$task_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tasks WHERE task_id = $task_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$task_name = nullable_htmlentities($row['task_name']);
$task_project_id = intval($row['task_project_id']);
$task_ticket_id = intval($row['task_ticket_id']);

// Resolve client for access control (mirrors agent/post/task.php's edit_project_task/edit_ticket_task handlers)
if ($task_project_id) {
    $client_id = intval(getFieldById('projects', $task_project_id, 'project_client_id'));
} else {
    $client_id = intval(getFieldById('tickets', $task_ticket_id, 'ticket_client_id'));
}
if ($client_id) {
    enforceClientAccess($client_id);
}

// Resolve the owning project (project-only tasks use task_project_id, ticket tasks inherit via their ticket)
if (!$task_project_id) {
    $task_project_id = intval(getFieldById('tickets', $task_ticket_id, 'ticket_project_id'));
}

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fas fa-fw fa-tasks me-2"></i>Editing Task: <strong><?php echo $task_name; ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="task_id" value="<?php echo $task_id; ?>">
    <div class="modal-body">
        <?php projectTaskRenderFields($mysqli, ['project_id' => $task_project_id, 'task' => $row]); ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_project_task" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Save
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
