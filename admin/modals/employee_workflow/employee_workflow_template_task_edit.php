<?php

require_once '../../../includes/modal_header.php';
require_once '../../includes/workflow_task_fields.php';

$template_task_id = intval($_GET['id']);

$task = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_template_tasks WHERE template_task_id = $template_task_id LIMIT 1"));
if (!$task) {
    exit;
}
$workflow_template_id = intval($task['workflow_template_id']);
$template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT type FROM workflow_templates WHERE workflow_template_id = $workflow_template_id"));

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-tasks me-2"></i>Edit Task</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">
    <input type="hidden" name="template_task_id" value="<?= $template_task_id ?>">

    <div class="modal-body">
        <?php workflowTaskFields($mysqli, $workflow_template_id, (string) ($template['type'] ?? 'onboarding'), $task); ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_employee_workflow_template_task" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save task</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
