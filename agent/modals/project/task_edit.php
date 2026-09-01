<?php

require_once '../../../includes/modal_header.php';

$task_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM tasks WHERE task_id = $task_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$task_name = nullable_htmlentities($row['task_name']);
$task_project_id = intval($row['task_project_id']);
$task_ticket_id = intval($row['task_ticket_id']);
$task_milestone_id = intval($row['task_milestone_id']);
$task_assigned_to = intval($row['task_assigned_to']);
$task_start = nullable_htmlentities($row['task_start']);
$task_due = nullable_htmlentities($row['task_due']);
$task_completion_estimate = intval($row['task_completion_estimate']);
$task_progress = intval($row['task_progress']);

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
        <div class="form-group">
            <label>Task Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tasks"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Task Name" maxlength="255" value="<?php echo $task_name; ?>" required autofocus>
            </div>
        </div>
        <div class="form-group">
            <label>Milestone</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-flag-checkered"></i></span>
                </div>
                <select class="form-control select2" name="milestone_id">
                    <option value="0">- No Milestone -</option>
                    <?php
                    $sql_milestones = mysqli_query($mysqli, "SELECT milestone_id, milestone_name FROM project_milestones WHERE milestone_project_id = $task_project_id ORDER BY milestone_order ASC, milestone_id ASC");
                    while ($m = mysqli_fetch_assoc($sql_milestones)) {
                        $m_id = intval($m['milestone_id']);
                        $m_name = nullable_htmlentities($m['milestone_name']); ?>
                        <option value="<?php echo $m_id; ?>" <?php if ($task_milestone_id == $m_id) { echo "selected"; } ?>><?php echo $m_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Assigned To</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user"></i></span>
                </div>
                <select class="form-control select2" name="assigned_to">
                    <option value="0">- Unassigned -</option>
                    <?php
                    $sql_users = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
                    while ($u = mysqli_fetch_assoc($sql_users)) {
                        $u_id = intval($u['user_id']);
                        $u_name = nullable_htmlentities($u['user_name']); ?>
                        <option value="<?php echo $u_id; ?>" <?php if ($task_assigned_to == $u_id) { echo "selected"; } ?>><?php echo $u_name; ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Start Date</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar"></i></span>
                    </div>
                    <input type="date" class="form-control" name="start" value="<?php echo $task_start; ?>">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Due Date</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                    </div>
                    <input type="date" class="form-control" name="due" value="<?php echo $task_due; ?>">
                </div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Time Estimate <small class="text-secondary">(minutes)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-clock"></i></span>
                    </div>
                    <input type="number" class="form-control" name="completion_estimate" value="<?php echo $task_completion_estimate; ?>" min="0">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Progress <small class="text-secondary">(%)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-percent"></i></span>
                    </div>
                    <input type="number" class="form-control" name="progress" value="<?php echo $task_progress; ?>" min="0" max="100">
                </div>
            </div>
        </div>
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
