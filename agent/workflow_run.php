<?php
require_once "includes/inc_all.php";

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

$sql_tasks = mysqli_query($mysqli, "SELECT * FROM workflow_run_tasks WHERE run_id = $run_id ORDER BY sort_order ASC, run_task_id ASC");

$status_badge = [
    'in_progress' => 'text-bg-primary',
    'completed' => 'text-bg-success',
    'completed_with_exceptions' => 'text-bg-warning',
    'cancelled' => 'text-bg-secondary',
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

<div class="card card-dark">
    <div class="card-header py-2">
        <h5 class="card-title">Checklist</h5>
    </div>
    <div class="card-body">
        <?php while ($task = mysqli_fetch_assoc($sql_tasks)) { ?>
            <div class="d-flex align-items-start border-bottom py-2">
                <div class="me-3 mt-1">
                    <?php if ($task['status'] === 'completed') { ?>
                        <i class="fas fa-check-circle text-success fa-lg"></i>
                    <?php } elseif ($task['status'] === 'skipped') { ?>
                        <i class="fas fa-minus-circle text-warning fa-lg"></i>
                    <?php } else { ?>
                        <i class="far fa-circle text-secondary fa-lg"></i>
                    <?php } ?>
                </div>
                <div class="flex-fill">
                    <div class="<?= $task['status'] !== 'pending' ? 'text-decoration-line-through text-muted' : '' ?>">
                        <strong><?= nullable_htmlentities($task['title']) ?></strong>
                        <?php if (!$task['required']) { ?><span class="badge text-bg-light text-secondary">optional</span><?php } ?>
                        <?php if ($task['category']) { ?><span class="badge text-bg-light text-secondary"><?= nullable_htmlentities($task['category']) ?></span><?php } ?>
                        <?php if ($task['default_owner']) { ?><span class="text-secondary small"> &mdash; <?= nullable_htmlentities($task['default_owner']) ?></span><?php } ?>
                    </div>
                    <?php if ($task['instructions']) { ?><div class="text-muted small"><?= nullable_htmlentities($task['instructions']) ?></div><?php } ?>
                    <?php if ($task['status'] === 'skipped' && $task['skip_reason']) { ?><div class="text-warning small">Skipped: <?= nullable_htmlentities($task['skip_reason']) ?></div><?php } ?>
                </div>
                <div class="ms-2 text-nowrap">
                    <?php if ($task['status'] === 'pending') { ?>
                        <form action="post.php" method="post" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="complete_workflow_run_task" class="btn btn-sm btn-success"><i class="fas fa-check"></i></button>
                        </form>
                        <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#skipTaskModal<?= intval($task['run_task_id']) ?>"><i class="fas fa-forward"></i></button>

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
                    <?php } else { ?>
                        <form action="post.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="run_task_id" value="<?= intval($task['run_task_id']) ?>">
                            <input type="hidden" name="run_id" value="<?= $run_id ?>">
                            <button type="submit" name="reopen_workflow_run_task" class="btn btn-sm btn-light" title="Reopen"><i class="fas fa-undo"></i></button>
                        </form>
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

<?php require_once "../includes/footer.php"; ?>
