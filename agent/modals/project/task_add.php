<?php

require_once '../../../includes/modal_header.php';

$project_id = intval($_GET['project_id']);
$milestone_id_preset = intval($_GET['milestone_id'] ?? 0);

$sql = mysqli_query($mysqli, "SELECT project_name FROM projects WHERE project_id = $project_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);
$project_name = nullable_htmlentities($row['project_name']);

// Generate the HTML form content using output buffering.
ob_start();
?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fas fa-fw fa-tasks me-2"></i>New Task: <strong><?php echo $project_name; ?></strong>
    </h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Task Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tasks"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="Task Name" maxlength="255" required autofocus>
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
                    $sql_milestones = mysqli_query($mysqli, "SELECT milestone_id, milestone_name FROM project_milestones WHERE milestone_project_id = $project_id ORDER BY milestone_order ASC, milestone_id ASC");
                    while ($m = mysqli_fetch_assoc($sql_milestones)) {
                        $m_id = intval($m['milestone_id']);
                        $m_name = nullable_htmlentities($m['milestone_name']); ?>
                        <option value="<?php echo $m_id; ?>" <?php if ($milestone_id_preset == $m_id) { echo "selected"; } ?>><?php echo $m_name; ?></option>
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
                        <option value="<?php echo $u_id; ?>"><?php echo $u_name; ?></option>
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
                    <input type="date" class="form-control" name="start">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Due Date</label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-calendar-check"></i></span>
                    </div>
                    <input type="date" class="form-control" name="due">
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
                    <input type="number" class="form-control" name="completion_estimate" value="0" min="0">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Progress <small class="text-secondary">(%)</small></label>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text"><i class="fa fa-fw fa-percent"></i></span>
                    </div>
                    <input type="number" class="form-control" name="progress" value="0" min="0" max="100">
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_project_task" class="btn btn-primary text-bold">
            <i class="fas fa-check me-2"></i>Create
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
