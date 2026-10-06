<?php
require_once "includes/inc_all_admin.php";

enforceUserPermission('module_client', 2);

$workflow_template_id = intval($_GET['id']);

$template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM workflow_templates WHERE workflow_template_id = $workflow_template_id"));
if (!$template) {
    flash_alert('Template not found', 'error');
    redirect('employee_workflow_templates.php');
}

require_once "includes/workflow_task_fields.php";

$sql_tasks = mysqli_query($mysqli, "SELECT t.*, u.user_name AS assignee_name FROM workflow_template_tasks t LEFT JOIN users u ON u.user_id = t.assignee_user_id WHERE t.workflow_template_id = $workflow_template_id ORDER BY t.sort_order ASC, t.template_task_id ASC");
$task_rows = mysqli_fetch_all($sql_tasks, MYSQLI_ASSOC);
$task_titles = [];
foreach ($task_rows as $tr) {
    $task_titles[intval($tr['template_task_id'])] = $tr['title'];
}
$action_labels = \ITFlow\Workflow\TaskActionRunner::labels();
$anchor_labels = ['run' => 'workflow start', 'start' => 'start date', 'end' => 'end date'];

?>

<div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto"><i class="fas fa-fw fa-tasks me-2"></i><?= nullable_htmlentities($template['name']) ?>
            <span class="badge <?= $template['type'] === 'onboarding' ? 'text-bg-success' : 'text-bg-danger' ?> ms-2"><?= ucfirst($template['type']) ?></span>
        </h3>
        <div class="dropdown dropleft text-center me-2">
            <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-fw fa-ellipsis-v"></i>
            </button>
            <div class="dropdown-menu">
                <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/employee_workflow/employee_workflow_template_edit.php?id=<?= $workflow_template_id ?>">
                    <i class="fas fa-fw fa-edit me-2"></i>Edit Template
                </a>
                <a class="dropdown-item text-danger confirm-link" href="post.php?archive_employee_workflow_template=<?php echo $workflow_template_id; ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                </a>
            </div>
        </div>
        <a href="employee_workflow_templates.php" class="btn btn-sm btn-default"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>
    <?php if ($template['description']) { ?>
    <div class="card-body py-2">
        <p class="text-muted mb-0"><?= nullable_htmlentities($template['description']) ?></p>
    </div>
    <?php } ?>
</div>

<div class="card mb-3">
    <div class="card-header py-2">
        <h5 class="card-title">Tasks</h5>
    </div>
    <div class="card-body">
        <?php if (!$task_rows) { ?>
            <p class="text-muted">No tasks yet - add the first one below.</p>
        <?php } else { ?>
        <table class="table table-sm table-hover">
            <thead>
                <tr>
                    <th style="width:60px"></th>
                    <th>Title</th>
                    <th>Category</th>
                    <th>Owner</th>
                    <th>Waits for</th>
                    <th>Due</th>
                    <th>Required</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($task_rows as $task) { ?>
                <tr>
                    <td class="text-nowrap">
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="template_task_id" value="<?= intval($task['template_task_id']) ?>">
                            <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">
                            <button type="submit" name="move_employee_workflow_template_task_up" class="btn btn-sm btn-light" title="Move up"><i class="fas fa-arrow-up"></i></button>
                        </form>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="template_task_id" value="<?= intval($task['template_task_id']) ?>">
                            <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">
                            <button type="submit" name="move_employee_workflow_template_task_down" class="btn btn-sm btn-light" title="Move down"><i class="fas fa-arrow-down"></i></button>
                        </form>
                    </td>
                    <td>
                        <strong><?= nullable_htmlentities($task['title']) ?></strong>
                        <?php if ($task['task_type'] === 'approval') { ?><span class="badge text-bg-info">approval</span><?php } ?>
                        <?php if ($task['task_type'] === 'action') { ?><span class="badge text-bg-info">automated: <?= nullable_htmlentities($action_labels[$task['action_type']] ?? $task['action_type']) ?></span><?php } ?>
                        <?php if ($task['instructions']) { ?><div class="text-muted small"><?= nullable_htmlentities($task['instructions']) ?></div><?php } ?>
                    </td>
                    <td><?= nullable_htmlentities($task['category']) ?></td>
                    <td><?= nullable_htmlentities($task['assignee_name'] ?: $task['default_owner']) ?></td>
                    <td class="small"><?php foreach (\ITFlow\Workflow\DependencyGraph::parse($task['depends_on']) as $dep_id) { echo nullable_htmlentities($task_titles[$dep_id] ?? '') . '<br>'; } ?></td>
                    <td class="small text-nowrap"><?= $task['due_offset_days'] !== null ? nullable_htmlentities(($task['due_offset_days'] >= 0 ? '+' : '') . $task['due_offset_days'] . ' days from ' . ($anchor_labels[$task['due_anchor']] ?? '')) : '' ?></td>
                    <td><?= $task['required'] ? '<i class="fas fa-check text-success"></i>' : '' ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-light ajax-modal" data-modal-url="modals/employee_workflow/employee_workflow_template_task_edit.php?id=<?= intval($task['template_task_id']) ?>" title="Edit task"><i class="fas fa-edit"></i></button>
                        <form action="post.php" method="post" class="d-inline" onsubmit="return confirm('Remove this task from the template?');">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="template_task_id" value="<?= intval($task['template_task_id']) ?>">
                            <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">
                            <button type="submit" name="delete_employee_workflow_template_task" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <hr>
        <h6>Add Task</h6>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="workflow_template_id" value="<?= $workflow_template_id ?>">
            <?php workflowTaskFields($mysqli, $workflow_template_id, $template['type']); ?>
            <button type="submit" name="add_employee_workflow_template_task" class="btn btn-primary"><i class="fas fa-plus me-2"></i>Add Task</button>
        </form>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
