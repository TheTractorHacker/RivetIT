<?php
require_once "includes/inc_all_admin.php";
require_once __DIR__ . '/../includes/cron_jobs.php';

use ITFlow\Cron\JobCatalog;
use ITFlow\Cron\JobRunner;

$app_root = realpath(__DIR__ . '/..');
$jobs = rivetit_cron_jobs_for_app($app_root);
$cron_manager_instance = rivetit_cron_manager_instance($app_root);
$can_edit_schedules = $cron_manager_instance !== null && is_executable('/usr/local/sbin/rivetit-cron-schedule');
$runner = new JobRunner($app_root);
$any_running = false;
$cron_ago = static function (?int $ts): string {
    if (!$ts) return 'never';
    $d = max(0, time() - $ts);
    return $d < 90 ? 'just now' : ($d < 5400 ? round($d / 60) . ' min ago' : ($d < 172800 ? round($d / 3600) . ' h ago' : round($d / 86400) . ' days ago'));
};
$scheduled_scripts = array_unique(array_map(fn($j) => basename($j['script']), $jobs));
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
        <?php if ($can_edit_schedules): ?>
            <p class="text-muted mb-2">Choose <strong>Edit schedule</strong> to set a repeat time, or <strong>Run now</strong> to start a job once in the background. Changes affect only the selected job.</p>
        <?php endif; ?>
        <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="thead-light">
                <tr>
                    <th>Job</th>
                    <th style="width:190px">Schedule</th>
                    <th style="width:260px">Last activity</th>
                    <th style="width:210px">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job):
                $script_file = basename($job['script']);
                $info = JobCatalog::describe($script_file);
                $job_key = substr($job['command_hash'], 0, 16);
                $log_path = JobRunner::logPathFromCommand($job['command']);
                $log = JobRunner::tail($log_path, 8);
                $run = $runner->state($job_key, $job['script']);
                $any_running = $any_running || $run['running'];
                $job_args = JobRunner::argumentsFromCommand($job['command'], $job['script']);
                $can_run = $info['run_now'] && $job['user'] === 'www-data' && $job_args !== null;
                $last_ts = max((int) $log['mtime'], (int) $run['finished_at']) ?: null;
            ?>
                <tr <?= $job['script'] === $app_root . '/cron/cron.php' ? 'class="table-primary"' : '' ?>>
                    <td>
                        <strong><?= nullable_htmlentities($info['label']) ?></strong>
                        <?php if ($job_args): ?><span class="badge bg-secondary ms-1"><?= nullable_htmlentities(implode(' ', $job_args)) ?></span><?php endif; ?>
                        <div class="small text-muted"><?= nullable_htmlentities($info['description']) ?></div>
                        <details><summary class="small text-muted"><?= nullable_htmlentities($script_file) ?> &middot; command</summary><code class="small text-break"><?= nullable_htmlentities($job['command']) ?></code>
                            <div class="small text-muted"><?= nullable_htmlentities($job['file']) ?>:<?= (int) $job['line'] ?></div></details>
                    </td>
                    <td>
                        <code data-cron-schedule="<?= htmlspecialchars($job['schedule']) ?>"><?= htmlspecialchars($job['schedule']) ?></code>
                        <span class="d-block small text-muted" data-cron-description></span>
                    </td>
                    <td class="small">
                        <?php if ($run['running']): ?>
                            <span class="badge bg-info text-dark"><span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span>Running</span>
                            <span class="text-muted">since <?= nullable_htmlentities($cron_ago($run['started_at'])) ?></span>
                        <?php else: ?>
                            <?php if ($run['exit'] !== null): ?>
                                <span class="badge bg-<?= $run['exit'] === 0 ? 'success' : 'danger' ?>"><?= $run['exit'] === 0 ? 'Manual run OK' : 'Manual run failed (' . (int) $run['exit'] . ')' ?></span>
                                <span class="text-muted"><?= nullable_htmlentities($cron_ago($run['finished_at'])) ?></span><br>
                            <?php endif; ?>
                            <?php if ($log_path && $log['mtime']): ?>
                                Schedule log: <?= nullable_htmlentities($cron_ago($log['mtime'])) ?>
                            <?php elseif ($log_path): ?>
                                <span class="text-muted">No log output yet</span>
                            <?php elseif ($run['exit'] === null): ?>
                                <span class="text-muted">No log file configured</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php $shown = $run['lines'] ?: $log['lines']; if ($shown): ?>
                            <details class="mt-1"><summary class="text-muted">Latest output</summary>
                                <pre class="small mb-0 text-break" style="white-space:pre-wrap;max-height:11rem;overflow:auto"><?= nullable_htmlentities(implode("\n", $shown)) ?></pre></details>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                        <?php if ($can_run): ?>
                            <form action="/admin/post.php" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="run_cron_job" value="1">
                                <input type="hidden" name="cron_file" value="<?= htmlspecialchars(basename($job['file'])) ?>">
                                <input type="hidden" name="cron_line" value="<?= (int) $job['line'] ?>">
                                <input type="hidden" name="cron_hash" value="<?= htmlspecialchars($job['command_hash']) ?>">
                                <button type="submit" class="btn btn-success btn-sm confirm-link" <?= $run['running'] ? 'disabled' : '' ?>><i class="fas fa-play me-1" aria-hidden="true"></i>Run now</button>
                            </form>
                        <?php elseif ($script_file !== 'cron.php'): ?>
                            <span class="small text-muted" style="max-width:11rem" title="<?= nullable_htmlentities($info['note']) ?>"><i class="fas fa-ban me-1" aria-hidden="true"></i><?= nullable_htmlentities($info['note'] ?: 'Not startable from here.') ?></span>
                        <?php endif; ?>
                        <?php if ($can_edit_schedules && $job['user'] === 'www-data'): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#cronScheduleModal"
                                data-cron-file="<?= htmlspecialchars(basename($job['file'])) ?>"
                                data-cron-line="<?= (int) $job['line'] ?>"
                                data-cron-hash="<?= htmlspecialchars($job['command_hash']) ?>"
                                data-cron-script="<?= htmlspecialchars($script_file) ?>"
                                data-cron-current="<?= htmlspecialchars($job['schedule']) ?>">
                                <i class="fas fa-calendar-alt me-1" aria-hidden="true"></i>Edit schedule
                            </button>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($jobs)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No scheduled jobs found for this installation.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php
        $unscheduled = [];
        foreach (JobCatalog::all() as $file => $meta) {
            if (!in_array($file, $scheduled_scripts, true) && is_file($app_root . '/' . JobCatalog::dir($file) . '/' . $file)) $unscheduled[$file] = $meta;
        }
        if ($unscheduled): ?>
        <h6 class="mt-4 mb-2 text-muted text-uppercase" style="font-size:.75rem;letter-spacing:.05em">
            <i class="fas fa-pause-circle me-1"></i>Available but not scheduled here
        </h6>
        <p class="text-muted small mb-2">These jobs exist in the app but have no schedule on this server. Some belong to other installs that share the code. Scheduling a new one still needs a server administrator.</p>
        <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
            <tbody>
            <?php foreach ($unscheduled as $file => $meta):
                $ukey = 'u-' . basename($file, '.php');
                $urun = $runner->state($ukey, $app_root . '/' . JobCatalog::dir($file) . '/' . $file);
                $any_running = $any_running || $urun['running'];
                $can_run_u = $meta['run_now'] && !JobCatalog::needsArguments($file);
            ?>
                <tr>
                    <td><strong><?= nullable_htmlentities($meta['label']) ?></strong> <span class="small text-muted"><?= nullable_htmlentities($file) ?></span>
                        <div class="small text-muted"><?= nullable_htmlentities($meta['description']) ?></div>
                        <?php if ($urun['lines']): ?><details><summary class="small text-muted">Latest output<?= $urun['exit'] !== null ? ' (exit ' . (int) $urun['exit'] . ')' : '' ?></summary>
                            <pre class="small mb-0 text-break" style="white-space:pre-wrap;max-height:11rem;overflow:auto"><?= nullable_htmlentities(implode("\n", $urun['lines'])) ?></pre></details><?php endif; ?></td>
                    <td style="width:230px">
                        <?php if ($urun['running']): ?>
                            <span class="badge bg-info text-dark">Running</span>
                        <?php elseif ($can_run_u): ?>
                            <form action="/admin/post.php" method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="run_cron_job" value="1">
                                <input type="hidden" name="cron_script" value="<?= htmlspecialchars($file) ?>">
                                <button type="submit" class="btn btn-success btn-sm confirm-link"><i class="fas fa-play me-1" aria-hidden="true"></i>Run now</button>
                            </form>
                        <?php else: ?>
                            <span class="small text-muted"><i class="fas fa-ban me-1" aria-hidden="true"></i><?= nullable_htmlentities($meta['note'] ?: 'Not startable from here.') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
        <?php if ($any_running): ?><script>setTimeout(function () { location.reload(); }, 4000);</script><?php endif; ?>

    </div>
</div>

<?php if ($can_edit_schedules): ?>
<div class="modal fade" id="cronScheduleModal" tabindex="-1" aria-labelledby="cronScheduleTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="/admin/post.php" method="POST" id="cronScheduleForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="save_cron_schedule" value="1">
                <input type="hidden" name="cron_file" id="cronScheduleFile">
                <input type="hidden" name="cron_line" id="cronScheduleLine">
                <input type="hidden" name="cron_hash" id="cronScheduleHash">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="cronScheduleTitle">Edit schedule</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Job: <code id="cronScheduleJob"></code><br>Current: <code id="cronScheduleCurrent"></code></p>

                    <div class="mb-3">
                        <label for="cronScheduleType" class="form-label">Repeat</label>
                        <select id="cronScheduleType" class="form-select">
                            <option value="minutes">Every few minutes</option>
                            <option value="hourly">Hourly</option>
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                            <option value="custom">Custom cron expression</option>
                        </select>
                    </div>

                    <div class="mb-3" data-cron-control="minutes">
                        <label for="cronEveryMinutes" class="form-label">Run every</label>
                        <div class="input-group" style="max-width:180px">
                            <input type="number" id="cronEveryMinutes" class="form-control" min="1" max="59" step="1" value="5">
                            <span class="input-group-text">minutes</span>
                        </div>
                        <div class="form-text">Intervals start again at the top of each hour.</div>
                    </div>
                    <div class="mb-3 d-none" data-cron-control="hourly">
                        <label for="cronMinute" class="form-label">Minute of each hour</label>
                        <input type="number" id="cronMinute" class="form-control" min="0" max="59" step="1" value="0" style="max-width:120px">
                    </div>
                    <div class="mb-3 d-none" data-cron-control="time">
                        <label for="cronTime" class="form-label">Time of day</label>
                        <input type="time" id="cronTime" class="form-control" value="09:00" style="max-width:180px">
                        <div class="form-text">Uses the server's cron time zone.</div>
                    </div>
                    <fieldset class="mb-3 d-none" data-cron-control="weekly">
                        <legend class="form-label fs-6 mb-2">Days of the week</legend>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun'] as $day_number => $day_name): ?>
                                <label class="form-check form-check-inline mb-0 me-2"><input class="form-check-input" type="checkbox" name="cron_weekday" value="<?= $day_number ?>" <?= $day_number >= 1 && $day_number <= 5 ? 'checked' : '' ?>><span class="form-check-label"><?= $day_name ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <div class="mb-3 d-none" data-cron-control="monthly">
                        <label for="cronMonthDay" class="form-label">Day of the month</label>
                        <input type="number" id="cronMonthDay" class="form-control" min="1" max="31" step="1" value="1" style="max-width:120px">
                        <div class="form-text">Days 29–31 are skipped in shorter months.</div>
                    </div>

                    <div class="mb-2">
                        <label for="cronScheduleExpression" class="form-label">Cron expression</label>
                        <input type="text" id="cronScheduleExpression" name="cron_schedule" class="form-control font-monospace" required maxlength="100" spellcheck="false" readonly>
                        <div class="form-text">For custom schedules: minute, hour, day of month, month, day of week. Use <code>*</code> for every value.</div>
                    </div>
                    <div id="cronScheduleSummary" class="text-muted" aria-live="polite"></div>
                    <div id="cronScheduleError" class="text-danger d-none" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="cronScheduleSave" disabled>Save schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="/js/cron_schedule_builder.js?v=<?= filemtime(__DIR__ . '/../js/cron_schedule_builder.js') ?>" defer></script>
<?php require_once "../includes/footer.php"; ?>
