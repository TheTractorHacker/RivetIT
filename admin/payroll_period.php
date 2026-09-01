<?php

require_once "includes/inc_all_admin.php";
require_once "../includes/payroll_functions.php";

$period_id = intval($_GET['id'] ?? 0);
$period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id AND payroll_period_archived_at IS NULL LIMIT 1"));
if (!$period) {
    flash_alert("Pay period not found.", 'error');
    redirect('/admin/payroll_periods.php');
}

$period_locked = $period['payroll_period_status'] === 'locked';
$period_start = $period['payroll_period_start_date'];
$period_end = $period['payroll_period_end_date'];

// Week buckets: 7-day chunks anchored at the period's own start date (matches
// the calculation engine in includes/payroll_functions.php exactly).
$week_buckets = payrollGetWeekBuckets($period_start, $period_end);

// Enrolled employees.
$employees_sql = mysqli_query(
    $mysqli,
    "SELECT u.user_id, u.user_name, r.payroll_pay_rate_type, r.payroll_pay_rate_amount
     FROM users u
     JOIN payroll_pay_rates r ON r.payroll_pay_rate_user_id = u.user_id AND r.payroll_pay_rate_archived_at IS NULL
     WHERE u.user_type = 1 AND u.user_archived_at IS NULL
     ORDER BY u.user_name ASC"
);
$employees = [];
while ($row = mysqli_fetch_assoc($employees_sql)) {
    $employees[] = $row;
}

// Existing hours entries for this period's weeks, keyed by user_id|week_start.
$hours_by_key = [];
if (!empty($week_buckets)) {
    $week_starts_esc = array_map(fn($w) => "'" . mysqli_real_escape_string($mysqli, $w['start']) . "'", $week_buckets);
    $hres = mysqli_query($mysqli, "SELECT * FROM payroll_hours_entries WHERE payroll_hours_entry_week_start_date IN (" . implode(',', $week_starts_esc) . ")");
    while ($row = mysqli_fetch_assoc($hres)) {
        $hours_by_key[$row['payroll_hours_entry_user_id'] . '|' . $row['payroll_hours_entry_week_start_date']] = $row['payroll_hours_entry_worked_time'];
    }
}

$existing_run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_period_id = $period_id AND payroll_run_archived_at IS NULL ORDER BY payroll_run_id DESC LIMIT 1"));

$job_hours_sql = mysqli_query(
    $mysqli,
    "SELECT j.*, u.user_name, tickets.ticket_prefix, tickets.ticket_number, tickets.ticket_subject
     FROM payroll_job_hours_entries j
     LEFT JOIN users u ON u.user_id = j.payroll_job_hours_entry_user_id
     LEFT JOIN tickets ON tickets.ticket_id = j.payroll_job_hours_entry_ticket_id
     WHERE j.payroll_job_hours_entry_period_id = $period_id
     ORDER BY j.payroll_job_hours_entry_id DESC"
);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-calendar-alt me-2"></i>
            Pay Period: <?= date('M j, Y', strtotime($period_start)) ?> &ndash; <?= date('M j, Y', strtotime($period_end)) ?>
            <?php if ($period_locked) { ?>
                <span class="badge text-bg-secondary ms-2"><i class="fas fa-lock me-1"></i>Locked</span>
            <?php } ?>
        </h3>
        <div class="card-tools">
            <a href="payroll_periods.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Periods</a>
        </div>
    </div>
    <div class="card-body">

        <?php if (empty($employees)) { ?>
            <div class="alert alert-warning">
                No employees are enrolled in payroll yet. <a href="/admin/payroll_employees.php">Enroll one first</a>.
            </div>
        <?php } else { ?>

        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="period_id" value="<?= $period_id ?>">

            <div class="table-responsive-sm">
                <table class="table table-striped table-borderless table-hover align-middle">
                    <thead class="text-dark">
                        <tr>
                            <th>Employee</th>
                            <?php foreach ($week_buckets as $wb) { ?>
                                <th class="text-center">
                                    Week of<br><?= date('M j', strtotime($wb['start'])) ?>
                                </th>
                            <?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($employees as $emp) {
                        $u_id = intval($emp['user_id']);
                        $u_name = nullable_htmlentities($emp['user_name']);
                        $is_salaried = $emp['payroll_pay_rate_type'] === 'salary';
                    ?>
                        <tr>
                            <td><?= $u_name ?><br><span class="badge text-bg-<?= $is_salaried ? 'info' : 'primary' ?> mt-1"><?= ucfirst($emp['payroll_pay_rate_type']) ?></span></td>
                            <?php if ($is_salaried) { ?>
                                <td class="text-center text-muted" colspan="<?= max(count($week_buckets), 1) ?>">Salaried - no hours needed</td>
                            <?php } else {
                                foreach ($week_buckets as $wb) {
                                    $key = $u_id . '|' . $wb['start'];
                                    $existing_time = $hours_by_key[$key] ?? '00:00:00';
                                    $parts = explode(':', $existing_time);
                                    $eh = intval($parts[0] ?? 0);
                                    $em = intval($parts[1] ?? 0);
                            ?>
                                <td class="text-center">
                                    <?php if ($period_locked) { ?>
                                        <?= $eh ?>h <?= $em ?>m
                                    <?php } else { ?>
                                        <input type="text" inputmode="numeric" maxlength="3" name="hours[<?= $u_id ?>][<?= $wb['start'] ?>][h]"
                                            value="<?= $eh ?>" placeholder="0" class="form-control form-control-sm text-center d-inline-block" style="max-width:3.5rem;">
                                        <span class="mx-1">h</span>
                                        <input type="text" inputmode="numeric" maxlength="2" name="hours[<?= $u_id ?>][<?= $wb['start'] ?>][m]"
                                            value="<?= $em ?>" placeholder="0" class="form-control form-control-sm text-center d-inline-block" style="max-width:3.5rem;">
                                        <span class="ms-1">m</span>
                                    <?php } ?>
                                </td>
                            <?php } } ?>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$period_locked) { ?>
            <button type="submit" name="save_payroll_hours" class="btn btn-primary text-bold"><i class="fas fa-save me-2"></i>Save Hours</button>
            <?php } ?>

        </form>

        <hr>

        <h5><i class="fas fa-fw fa-briefcase me-2"></i>Job-Specific Hours</h5>
        <p class="text-muted small">Occasionally pay more (or less) than an employee's standard rate for a specific ticket - these hours are paid flat at the one-off rate entered below and don't count toward the weekly overtime threshold.</p>

        <?php if (!$period_locked) { ?>
        <button type="button" class="btn btn-sm btn-primary mb-2 ajax-modal" data-modal-url="modals/payroll_job_hours/payroll_job_hours_add.php?period_id=<?= $period_id ?>">
            <i class="fas fa-plus me-2"></i>Add Job Hours
        </button>
        <?php } ?>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover table-sm">
                <thead class="text-dark">
                    <tr>
                        <th>Employee</th>
                        <th>Ticket</th>
                        <th class="text-end">Hours</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Pay</th>
                        <th>Note</th>
                        <?php if (!$period_locked) { ?><th class="text-center">Action</th><?php } ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($job_hours_sql) === 0) { ?>
                <tr><td colspan="<?= $period_locked ? 6 : 7 ?>" class="text-center text-muted py-3">No job-specific hours for this period.</td></tr>
                <?php } ?>
                <?php while ($jrow = mysqli_fetch_assoc($job_hours_sql)) {
                    $j_id = intval($jrow['payroll_job_hours_entry_id']);
                    $j_user_name = nullable_htmlentities($jrow['user_name'] ?? '—');
                    $j_ticket_label = $jrow['ticket_subject'] !== null
                        ? nullable_htmlentities(trim((string) $jrow['ticket_prefix'] . $jrow['ticket_number']) . ' - ' . $jrow['ticket_subject'])
                        : '<span class="text-muted">Ticket deleted</span>';
                    $j_parts = explode(':', $jrow['payroll_job_hours_entry_worked_time']);
                    $j_h = intval($j_parts[0] ?? 0);
                    $j_m = intval($j_parts[1] ?? 0);
                    $j_hours_decimal = $j_h + ($j_m / 60);
                    $j_rate = floatval($jrow['payroll_job_hours_entry_rate']);
                    $j_pay = round($j_hours_decimal * $j_rate, 2);
                    $j_note = nullable_htmlentities($jrow['payroll_job_hours_entry_note'] ?? '');
                ?>
                <tr>
                    <td><?= $j_user_name ?></td>
                    <td><?= $j_ticket_label ?></td>
                    <td class="text-end"><?= $j_h ?>h <?= $j_m ?>m</td>
                    <td class="text-end">$<?= number_format($j_rate, 2) ?>/hr</td>
                    <td class="text-end">$<?= number_format($j_pay, 2) ?></td>
                    <td class="text-muted small"><?= $j_note !== '' ? $j_note : '—' ?></td>
                    <?php if (!$period_locked) { ?>
                    <td class="text-center">
                        <a href="post.php?remove_payroll_job_hours=<?= $j_id ?>&period_id=<?= $period_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link">
                            <i class="fas fa-trash"></i>
                        </a>
                    </td>
                    <?php } ?>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <hr>

        <?php if ($existing_run) { ?>
            <a href="payroll_run.php?id=<?= intval($existing_run['payroll_run_id']) ?>" class="btn btn-success">
                <i class="fas fa-eye me-2"></i>View Payroll Run (<?= ucfirst($existing_run['payroll_run_status']) ?>)
            </a>
        <?php } elseif (!$period_locked) { ?>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="period_id" value="<?= $period_id ?>">
                <button type="submit" name="run_payroll" class="btn btn-success confirm-link">
                    <i class="fas fa-play me-2"></i>Run Payroll
                </button>
            </form>
        <?php } ?>

        <?php } ?>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
