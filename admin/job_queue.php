<?php
require_once "includes/inc_all_admin.php";
require_once "../includes/event_bus.php";

$csrf = $_SESSION['csrf_token'];
$ready = rivetJobsAvailable($mysqli);
$queue = $ready ? new \RivetCore\Jobs\JobQueue(rivetCoreDb($mysqli)) : null;
$stats = $ready ? $queue->stats() : [];
$filter = in_array($_GET['status'] ?? '', ['pending', 'running', 'completed', 'failed', 'dead_letter'], true) ? $_GET['status'] : null;
$jobs = $ready ? $queue->recent(100, $filter) : [];
$types = [];
if ($ready) {
    $w = new \RivetCore\Jobs\JobWorker($queue);
    rivetRegisterJobHandlers($w, $mysqli);
    $types = $w->types();
}
$tone = ['pending' => 'warning text-dark', 'running' => 'info', 'completed' => 'success', 'failed' => 'danger', 'dead_letter' => 'danger'];
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="card mb-3">
    <div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-tasks me-2"></i>Job queue</h3></div>
    <div class="card-body">
        <p class="text-muted mb-1">Background work that should not slow a page: webhook deliveries and event-rule actions. A job that fails is retried after 1, 5, 30 and 120 minutes, then set aside as failed so you can look at it and retry it by hand.</p>
        <p class="text-muted small mb-0">The scheduled job worker (Maintenance &rarr; Cron) and the main cron both process the queue; jobs queued by a page are also sent right after the page responds. Job types: <?= $types ? implode(', ', array_map(static fn ($t) => '<code>' . htmlspecialchars($t) . '</code>', $types)) : 'none' ?>.</p>
    </div>
</div>

<?php if (!$ready) { ?>
    <div class="alert alert-warning">The job queue is not available yet. Run the database update (Administration &rarr; Update).</div>
<?php } else { ?>
<div class="row g-3 mb-3">
    <?php foreach (['pending' => 'Waiting / retrying', 'running' => 'Running', 'completed' => 'Completed', 'dead_letter' => 'Failed for good'] as $k => $label) { ?>
        <div class="col-6 col-md-3"><a class="text-decoration-none" href="?status=<?= $k ?>"><div class="card h-100 <?= $filter === $k ? 'border-primary' : '' ?>"><div class="card-body py-3"><div class="small text-muted"><?= $h($label) ?></div><div class="fs-3 fw-semibold"><?= (int) ($stats[$k] ?? 0) ?></div></div></div></a></div>
    <?php } ?>
</div>

<div class="card mb-3">
    <div class="card-header py-3 d-flex justify-content-between align-items-center">
        <h4 class="card-title mb-0">Jobs <?= $filter ? '<small class="text-muted">(' . $h($filter) . ')</small> <a class="small" href="job_queue.php">show all</a>' : '' ?></h4>
        <form action="post.php" method="post" class="d-flex gap-2">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <button class="btn btn-primary btn-sm" name="run_job_worker"><i class="fas fa-play me-1"></i>Process jobs now</button>
            <button class="btn btn-outline-secondary btn-sm" name="purge_completed_jobs" title="Remove completed jobs older than 30 days">Clear old completed</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Type</th><th>Status</th><th>Tries</th><th>Next try</th><th>Error</th><th></th></tr></thead>
            <tbody>
            <?php if (!$jobs) { ?><tr><td colspan="7" class="text-center text-muted py-3">No jobs.</td></tr><?php } ?>
            <?php foreach ($jobs as $j) { ?>
                <tr>
                    <td><?= (int) $j['job_id'] ?></td>
                    <td><code><?= $h($j['job_type']) ?></code></td>
                    <td><span class="badge text-bg-<?= $tone[$j['status']] ?? 'secondary' ?>"><?= $h(str_replace('_', ' ', $j['status'])) ?></span></td>
                    <td><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
                    <td class="small text-secondary"><?= $j['status'] === 'pending' ? $h($j['available_at']) : '&mdash;' ?></td>
                    <td class="small text-truncate" style="max-width:340px" title="<?= $h($j['error']) ?>"><?= $h($j['error']) ?></td>
                    <td class="text-end"><?php if (in_array($j['status'], ['dead_letter', 'failed'], true)) { ?><form action="post.php" method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="job_id" value="<?= (int) $j['job_id'] ?>"><button class="btn btn-sm btn-outline-primary" name="retry_job">Retry</button></form><?php } ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>
<?php } ?>

<?php require_once "../includes/footer.php";
