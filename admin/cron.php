<?php
require_once "includes/inc_all_admin.php";
require_once __DIR__ . '/../includes/cron_jobs.php';

$app_root = realpath(__DIR__ . '/..');
$jobs = rivetit_cron_jobs_for_app($app_root);
$cron_manager_instance = rivetit_cron_manager_instance($app_root);
$can_edit_schedules = $cron_manager_instance !== null && is_executable('/usr/local/sbin/rivetit-cron-schedule');
$main_jobs = array_values(array_filter($jobs, fn($job) => $job['script'] === $app_root . '/cron/cron.php'));
$main_job = count($main_jobs) === 1 ? $main_jobs[0] : null;

// Last successful run
$last_run = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT app_log_created_at FROM app_logs
     WHERE app_log_category = 'Cron' AND app_log_details = 'Cron executed successfully'
     ORDER BY app_log_id DESC LIMIT 1"
));

?>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clock me-2"></i>Cron Manager</h3>
        <div class="card-tools">
            <form action="/admin/post.php" method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="run_cron_now" value="1">
                <button type="submit" class="btn btn-success btn-sm confirm-link" <?= $main_job && $config_enable_cron ? '' : 'disabled title="Install a main cron job and enable Cron in Settings first"' ?>>
                    <i class="fas fa-play me-1"></i>Run Now
                </button>
            </form>
        </div>
    </div>
    <div class="card-body">

        <?php if ($last_run): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i>
            Last successful run: <strong><?= nullable_htmlentities($last_run['app_log_created_at']) ?></strong>
        </div>
        <?php endif; ?>

        <?php if (!$main_jobs): ?>
            <div class="alert alert-warning mb-3">
                No main <code>cron/cron.php</code> job is installed for this instance. The installer creates
                <code>/etc/cron.d/rivetit-&lt;domain&gt;</code>. Review mail and integration effects
                before adding the full job to a shared server.
            </div>
        <?php elseif (count($main_jobs) > 1): ?>
            <div class="alert alert-danger mb-3">
                Multiple main cron jobs target this instance. Remove the duplicate entries on the server
                before running the job again.
            </div>
        <?php else: ?>
            <p class="mb-3">Main cron is scheduled at <code><?= htmlspecialchars($main_job['schedule']) ?></code>
                from <code><?= htmlspecialchars($main_job['file']) ?></code>.</p>
            <?php if (!$config_enable_cron): ?>
                <div class="alert alert-info mb-3">
                    The system job is installed, but <strong>Enable Cron Job</strong> is off in
                    <a href="/admin/settings_notification.php">Settings → Notifications</a>.
                    The script exits without running tasks. Review mail and invoice effects before enabling it.
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$can_edit_schedules && $jobs): ?>
            <div class="alert alert-info mb-3">Schedules are visible here. A server administrator must install the
                Cron Manager helper to enable editing.</div>
        <?php endif; ?>

        <!-- All scheduled jobs table -->
        <h6 class="mt-4 mb-2 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.05em">
            <i class="fas fa-list me-1"></i>Scheduled Jobs for This Installation
        </h6>
        <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width:310px">Schedule</th>
                    <th>Script</th>
                    <th>File</th>
                    <?php if ($can_edit_schedules): ?><th style="width:85px">Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr <?= $job['script'] === $app_root . '/cron/cron.php' ? 'class="table-primary"' : '' ?>>
                    <?php $form_id = 'cron-schedule-' . basename($job['file']) . '-' . $job['line'] . '-' . substr($job['command_hash'], 0, 8); ?>
                    <td>
                        <?php if ($can_edit_schedules && $job['user'] === 'www-data'): ?>
                            <input form="<?= htmlspecialchars($form_id) ?>" name="cron_schedule" class="form-control form-control-sm font-monospace"
                                value="<?= htmlspecialchars($job['schedule']) ?>" aria-label="Schedule for <?= htmlspecialchars(basename($job['script'])) ?>"
                                required maxlength="100" spellcheck="false">
                        <?php else: ?><code><?= htmlspecialchars($job['schedule']) ?></code><?php endif; ?>
                    </td>
                    <td>
                        <small class="text-monospace"><?= htmlspecialchars(basename($job['script'])) ?></small>
                        <details><summary class="small text-muted">Command</summary><code class="small text-break"><?= htmlspecialchars($job['command']) ?></code></details>
                    </td>
                    <td><small class="text-monospace"><?= htmlspecialchars($job['file']) ?>:<?= (int) $job['line'] ?></small></td>
                    <?php if ($can_edit_schedules): ?>
                        <td>
                            <?php if ($job['user'] === 'www-data'): ?>
                            <form id="<?= htmlspecialchars($form_id) ?>" action="/admin/post.php" method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="save_cron_schedule" value="1">
                                <input type="hidden" name="cron_file" value="<?= htmlspecialchars(basename($job['file'])) ?>">
                                <input type="hidden" name="cron_line" value="<?= (int) $job['line'] ?>">
                                <input type="hidden" name="cron_hash" value="<?= htmlspecialchars($job['command_hash']) ?>">
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="<?= $can_edit_schedules ? 4 : 3 ?>" class="text-center text-muted py-3">No scheduled jobs found for this installation.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
