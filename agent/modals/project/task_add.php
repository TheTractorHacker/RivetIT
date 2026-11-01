<?php

require_once '../../../includes/modal_header.php';
require_once '../../../includes/project_tasks.php';
require_once '../../../includes/project_task_form.php';

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
        <?php projectTaskRenderFields($mysqli, ['project_id' => $project_id, 'task' => null, 'milestone_preset' => $milestone_id_preset]); ?>
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
