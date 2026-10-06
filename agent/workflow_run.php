<?php
require_once "includes/inc_all.php";

// Onboarding/offboarding runs are Departments data (agent/post/workflow_run.php needs it too) - roles audit P1b/F9.
enforceUserPermission('module_client');

$run_id = intval($_GET['run_id'] ?? 0);

$run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT wr.*, c.contact_name, c.contact_client_id
    FROM workflow_runs wr
    LEFT JOIN contacts c ON c.contact_id = wr.contact_id
    WHERE wr.run_id = $run_id"));

if (!$run) {
    echo "<center><h1 class='text-secondary mt-5'>Nothing to see here</h1><a class='btn btn-lg btn-secondary mt-3' href='javascript:history.back()'><i class='fa fa-fw fa-arrow-left'></i> Go Back</a></center>";
    require_once "../includes/footer.php";
    exit;
}

$client_id = intval($run['contact_client_id']);
enforceClientAccess($client_id);

$sql_tasks = mysqli_query($mysqli, "SELECT t.*, u.user_name AS assignee_name FROM workflow_run_tasks t LEFT JOIN users u ON u.user_id = t.assignee_user_id WHERE t.run_id = $run_id ORDER BY t.sort_order ASC, t.run_task_id ASC");
$tasks = mysqli_fetch_all($sql_tasks, MYSQLI_ASSOC);
$task_titles = [];
foreach ($tasks as $t) {
    $task_titles[intval($t['run_task_id'])] = $t['title'];
}
$workflow_service = new \ITFlow\Workflow\WorkflowService($mysqli);
$contact_for_labels = \ITFlow\Workflow\TaskActionRunner::loadContact($mysqli, intval($run['contact_id'])) ?? [];
$action_labels = \ITFlow\Workflow\TaskActionRunner::labels();
$sql_log = mysqli_query($mysqli, "SELECT l.*, t.title AS task_title, u.user_name AS actor_name FROM workflow_task_log l LEFT JOIN workflow_run_tasks t ON t.run_task_id = l.run_task_id LEFT JOIN users u ON u.user_id = l.actor_user_id WHERE l.run_id = $run_id ORDER BY l.log_id DESC LIMIT 50");
$now_ts = time();

$status_badge = [
    'in_progress' => 'text-bg-primary',
    'completed' => 'text-bg-success',
    'completed_with_exceptions' => 'text-bg-warning',
    'cancelled' => 'text-bg-secondary',
    'paused' => 'text-bg-warning',
][$run['status']] ?? 'text-bg-secondary';

?>

<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title me-auto">
            <i class="fas fa-fw fa-tasks me-2"></i><?= nullable_htmlentities($run['contact_name']) ?>
            <span class="badge <?= $run['type'] === 'onboarding' ? 'text-bg-success' : 'text-bg-danger' ?> ms-2"><?= ucfirst($run['type']) ?></span>
            <span class="badge <?= $status_badge ?>"><?= ucwords(str_replace('_', ' ', $run['status'])) ?></span>
        </h3>
        <a href="contact_details.php?contact_id=<?= intval($run['contact_id']) ?>&client_id=<?= $client_id ?>" class="btn btn-sm btn-default"><i class="fas fa-arrow-left me-1"></i>Back to Contact</a>
    </div>
    <div class="card-body py-2 text-muted small">
        Started <?= nullable_htmlentities($run['started_at']) ?><?= $run['completed_at'] ? ' &middot; Completed ' . nullable_htmlentities($run['completed_at']) : '' ?>
    </div>
</div>

<?php if ($run['status'] === 'paused') { ?>
<div class="alert alert-warning">An approval was rejected, so this workflow is paused. Reopen the rejected task to ask for approval again, or an administrator can skip it to carry on.</div>
<?php } ?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h5 class="card-title">Checklist</h5>
    </div>
    <div class="card-body">
        <?php foreach ($tasks as $task) {
            $is_open = in_array($task['status'], ['pending', 'blocked', 'running', 'action_failed', 'rejected'], true);
            $due_state = $is_open ? \ITFlow\Workflow\DueDateResolver::state($task['due_at'], $now_ts) : null;
            $dep_titles = [];
            foreach (\ITFlow\Workflow\DependencyGraph::parse($task['depends_on']) as $dep_id) {
                if (isset($task_titles[$dep_id])) { $dep_titles[] = $task_titles[$dep_id]; }
            }
        ?>
            <div class="d-flex align-items-start border-bottom py-2">
                <div class="me-3 mt-1">
                    <?php if ($task['status'] === 'completed') { ?>
                        <i class="fas fa-check-circle text-success fa-lg"></i>
                    <?php } elseif ($task['status'] === 'skipped') { ?>
                        <i class="fas fa-minus-circle text-warning fa-lg"></i>
                    <?php } elseif ($task['status'] === 'blocked') { ?>
                        <i class="fas fa-lock text-secondary fa-lg" title="Waiting on other tasks"></i>
                    <?php } elseif ($task['status'] === 'running') { ?>
                        <i class="fas fa-cog fa-spin text-primary fa-lg" title="Running"></i>
                    <?php } elseif ($task['status'] === 'action_failed' || $task['status'] === 'rejected') { ?>
                        <i class="fas fa-exclamation-circle text-danger fa-lg"></i>
                    <?php } else { ?>
                        <i class="far fa-circle text-secondary fa-lg"></i>
                    <?php } ?>
                </div>
                <div class="flex-fill">
                    <div class="<?= in_array($task['status'], ['completed', 'skipped'], true) ? 'text-decoration-line-through text-muted' : '' ?>">
                        <strong><?= nullable_htmlentities($task['title']) ?></strong>
                        <?php if (!$task['required']) { ?><span class="badge text-bg-light text-secondary">optional</span><?php } ?>
                        <?php if ($task['category']) { ?><span class="badge text-bg-light text-secondary"><?= nullable_htmlentities($task['category']) ?></span><?php } ?>
                        <?php if ($task['default_owner']) { ?><span class="text-secondary small"> &mdash; <?= nullable_htmlentities($task['default_owner']) ?></span><?php } ?>
                    </div>
                    <?php if ($task['instructions']) { ?><div class="text-muted small"><?= nullable_htmlentities($task['instructions']) ?></div><?php } ?>
                    <div class="small mt-1">
                        <?php if ($task['task_type'] === 'approval') { ?><span class="badge text-bg-info">approval</span><?php } ?>
                        <?php if ($task['task_type'] === 'action') { ?><span class="badge text-bg-info">automated: <?= nullable_htmlentities($action_labels[$task['action_type']] ?? $task['action_type']) ?></span><?php } ?>
                        <?php if ($task['assignee_name']) { ?><span class="badge text-bg-light text-secondary"><i class="fas fa-user me-1"></i><?= nullable_htmlentities($task['assignee_name']) ?></span><?php } ?>
                        <?php if ($task['due_at']) { ?>
                            <span class="badge <?= $due_state === 'overdue' ? 'text-bg-danger' : ($due_state === 'due_soon' ? 'text-bg-warning' : 'text-bg-light text-secondary') ?>"><i class="far fa-clock me-1"></i><?= $due_state === 'overdue' ? 'Overdue &middot; ' : ($due_state === 'due_soon' ? 'Due soon &middot; ' : 'Due ') ?><?= nullable_htmlentities($task['due_at']) ?></span>
                        <?php } ?>
                        <?php if ($task['status'] === 'blocked') { ?><span class="text-muted">Waiting for: <?= nullable_htmlentities(implode(', ', $dep_titles)) ?></span><?php } ?>
                    </div>
                    <?php if ($task['task_type'] === 'approval' && $task['status'] !== 'blocked') { ?>
                        <div class="small mt-1">
                            Approver: <?= nullable_htmlentities($workflow_service->approverLabel($task, $contact_for_labels)) ?>
                            <?php if ($task['approval_status'] === 'approved' || $task['approval_status'] === 'rejected') { ?>
                                &middot; <strong class="<?= $task['approval_status'] === 'approved' ? 'text-success' : 'text-danger' ?>"><?= ucfirst(nullable_htmlentities($task['approval_status'])) ?></strong> <?= nullable_htmlentities($task['approved_at']) ?>
                                <?php if ($task['approval_comment']) { ?>: &ldquo;<?= nullable_htmlentities($task['approval_comment']) ?>&rdquo;<?php } ?>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php if ($task['task_type'] === 'action' && $task['last_error'] && $task['status'] !== 'completed') { ?>
                        <div class="text-danger small mt-1">Failed after <?= intval($task['attempts']) ?> attempt(s): <?= nullable_htmlentities($task['last_error']) ?>. Do it by hand and tick it off, or run it again.</div>
                    <?php } ?>
                    <?php if ($task['status'] === 'skipped' && $task['skip_reason']) { ?><div class="text-warning small">Skipped: <?= nullable_htmlentities($task['skip_reason']) ?></div><?php } ?>
                </div>
                <div class="ms-2 text-nowrap">
                    <?php if ($task['task_type'] === 'approval' && $task['status'] === 'pending' && $task['approval_status'] === 'pending') { ?>
                        <?php if ($workflow_service->canDecide(intval($task['run_task_id']), intval($session_user_id)) && !in_array($run['status'], ['cancelled', 'paused'], true)) { ?>
                        <form action="post.php" method="post" class="d-inline-flex gap-1 align-items-center">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <input type="text" class="form-control form-control-sm" name="approval_comment" maxlength="500" placeholder="Comment" style="width:150px">
                            <button type="submit" name="approve_workflow_run_task" class="btn btn-sm btn-success" title="Approve"><i class="fas fa-thumbs-up"></i></button>
                            <button type="submit" name="reject_workflow_run_task" class="btn btn-sm btn-danger" title="Reject (pauses the workflow)"><i class="fas fa-thumbs-down"></i></button>
                        </form>
                        <?php } else { ?>
                            <span class="text-muted small">Waiting for approval</span>
                        <?php } ?>
                        <?php if ($session_is_admin) { ?>
                        <button type="button" class="btn btn-sm btn-outline-warning" title="Skip the approval (administrators only)" data-bs-toggle="modal" data-bs-target="#skipTaskModal<?= intval($task['run_task_id']) ?>"><i class="fas fa-forward"></i></button>
                        <?php } ?>
                    <?php } elseif ($task['status'] === 'blocked' || $task['status'] === 'running') { ?>
                        <span class="text-muted small"><?= $task['status'] === 'blocked' ? 'Blocked' : 'Running' ?></span>
                    <?php } elseif ($task['status'] === 'rejected') { ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="reopen_workflow_run_task" class="btn btn-sm btn-light" title="Reopen and ask for approval again"><i class="fas fa-undo"></i></button>
                        </form>
                        <?php if ($session_is_admin) { ?>
                        <button type="button" class="btn btn-sm btn-outline-warning" title="Skip the approval (administrators only)" data-bs-toggle="modal" data-bs-target="#skipTaskModal<?= intval($task['run_task_id']) ?>"><i class="fas fa-forward"></i></button>
                        <?php } ?>
                    <?php } elseif ($task['status'] === 'pending' || $task['status'] === 'action_failed') { ?>
                        <?php if ($task['task_type'] === 'action') { ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="retry_workflow_run_task" class="btn btn-sm btn-outline-primary" title="Run the action again"><i class="fas fa-redo"></i></button>
                        </form>
                        <?php } ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="complete_workflow_run_task" class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#skipTaskModal<?= intval($task['run_task_id']) ?>"><i class="fas fa-forward"></i></button>
                    <?php } else { ?>
                        <form action="post.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="reopen_workflow_run_task" class="btn btn-sm btn-light" title="Reopen"><i class="fas fa-undo"></i></button>
                        </form>
                    <?php } ?>
                    <?php if (in_array($task['status'], ['pending', 'action_failed', 'rejected'], true) && ($task['task_type'] !== 'approval' || $session_is_admin)) { ?>
                        <div class="modal fade" id="skipTaskModal<?= intval($task['run_task_id']) ?>">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form action="post.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                                        <input type="hidden" name="run_id" value="<?= $run_id ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Skip: <?= nullable_htmlentities($task['title']) ?></h5>
                                            <button type="button" class="close" data-bs-dismiss="modal">&times;</button>
                                        </div>
                                        <div class="modal-body">
                                            <label>Reason</label>
                                            <input type="text" class="form-control" name="skip_reason" required>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="submit" name="skip_workflow_run_task" class="btn btn-warning">Skip Task</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <?php if ($run['status'] !== 'cancelled') { ?>
        <div class="mt-3">
            <form action="post.php" method="post" onsubmit="return confirm('Cancel this workflow? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="run_id" value="<?= $run_id ?>">
                <button type="submit" name="cancel_workflow_run" class="btn btn-sm btn-outline-danger">Cancel Workflow</button>
            </form>
        </div>
        <?php } ?>
    </div>
</div>

<?php if (mysqli_num_rows($sql_log) > 0) { ?>
<div class="card card-dark mt-3">
    <div class="card-header py-2">
        <h5 class="card-title">Activity log</h5>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Task</th><th>Event</th><th>Result</th><th>Detail</th></tr></thead>
            <tbody>
            <?php while ($log = mysqli_fetch_assoc($sql_log)) { ?>
                <tr>
                    <td class="text-nowrap text-secondary small"><?= nullable_htmlentities($log['created_at']) ?></td>
                    <td class="small"><?= nullable_htmlentities($log['task_title']) ?></td>
                    <td class="small"><?= nullable_htmlentities(str_replace('_', ' ', $log['event'])) ?><?= $log['attempt'] > 0 ? ' (attempt ' . intval($log['attempt']) . ')' : '' ?></td>
                    <td><?= $log['ok'] ? '<span class="badge text-bg-success">ok</span>' : '<span class="badge text-bg-danger">failed</span>' ?></td>
                    <td class="small"><?= nullable_htmlentities($log['detail']) ?><?= $log['actor_name'] ? ' <span class="text-muted">by ' . nullable_htmlentities($log['actor_name']) . '</span>' : '' ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php"; ?>
