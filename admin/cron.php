<?php
require_once "includes/inc_all_admin.php";

$cron_file = '/etc/cron.d/itflow';

// Parse current cron.d file
$cron_lines = file_exists($cron_file) ? file($cron_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

$jobs = [];
foreach ($cron_lines as $line) {
    if (str_starts_with(trim($line), '#')) continue;
    if (preg_match('/^(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(\S+)\s+(.+)$/', trim($line), $m)) {
        $jobs[] = ['schedule' => $m[1], 'user' => $m[2], 'command' => $m[3]];
    }
}

// Find the main cron.php schedule
$main_schedule = '0 2 * * *';
foreach ($jobs as $job) {
    if (str_contains($job['command'], 'cron/cron.php')) {
        $main_schedule = $job['schedule'];
        break;
    }
}

// Last successful run
$last_run = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT app_log_created_at FROM app_logs
     WHERE app_log_category = 'Cron' AND app_log_details = 'Cron executed successfully'
     ORDER BY app_log_id DESC LIMIT 1"
));

$schedule_presets = [
    '*/5 * * * *'  => 'Every 5 minutes',
    '*/15 * * * *' => 'Every 15 minutes',
    '*/30 * * * *' => 'Every 30 minutes',
    '0 * * * *'    => 'Every hour',
    '0 */2 * * *'  => 'Every 2 hours',
    '0 */6 * * *'  => 'Every 6 hours',
    '0 2 * * *'    => 'Daily at 2 AM',
];

$script_base = '/usr/bin/php /var/www/itflow.foleyit.com/cron/';
?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-clock me-2"></i>Cron Manager</h3>
        <div class="card-tools">
            <form action="/admin/post.php" method="POST" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="run_cron_now" value="1">
                <button type="submit" class="btn btn-success btn-sm confirm-link">
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

        <!-- Main cron schedule editor -->
        <form action="/admin/post.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="save_cron_schedule" value="1">
            <div class="form-group">
                <label><strong>Main Cron Schedule</strong>
                    <small class="text-muted ms-1">(cron/cron.php — runs ticket automation, invoices, reminders, backups, etc.)</small>
                </label>
                <div class="input-group">
                    <select name="cron_preset" class="form-control" id="cronPreset">
                        <?php foreach ($schedule_presets as $expr => $label): ?>
                            <option value="<?= htmlspecialchars($expr) ?>"
                                    <?= $expr === $main_schedule ? 'selected' : '' ?>>
                                <?= htmlspecialchars($label) ?> — <?= htmlspecialchars($expr) ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="custom" <?= !array_key_exists($main_schedule, $schedule_presets) ? 'selected' : '' ?>>
                            Custom expression…
                        </option>
                    </select>
                    <div class="input-group-append">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Schedule
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-group" id="customScheduleGroup"
                 style="display:<?= array_key_exists($main_schedule, $schedule_presets) ? 'none' : 'block' ?>">
                <label>Custom Expression</label>
                <input type="text" name="cron_custom" class="form-control"
                       value="<?= htmlspecialchars($main_schedule) ?>"
                       placeholder="*/15 * * * *">
                <small class="text-muted">Standard 5-field cron expression: <code>minute hour day-of-month month day-of-week</code></small>
            </div>
        </form>

        <!-- All scheduled jobs table -->
        <h6 class="mt-4 mb-2 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.05em">
            <i class="fas fa-list me-1"></i>All Scheduled Jobs (<?= htmlspecialchars($cron_file) ?>)
        </h6>
        <table class="table table-sm table-bordered mb-0">
            <thead class="thead-light">
                <tr>
                    <th style="width:200px">Schedule</th>
                    <th>Script</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr <?= str_contains($job['command'], 'cron/cron.php') ? 'class="table-primary"' : '' ?>>
                    <td><code><?= htmlspecialchars($job['schedule']) ?></code></td>
                    <td><small class="text-monospace"><?= htmlspecialchars(str_replace($script_base, '', $job['command'])) ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="2" class="text-center text-muted py-3">No jobs found in <?= htmlspecialchars($cron_file) ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.getElementById('cronPreset').addEventListener('change', function () {
    document.getElementById('customScheduleGroup').style.display =
        this.value === 'custom' ? 'block' : 'none';
});
</script>

<?php require_once "../includes/footer.php"; ?>
