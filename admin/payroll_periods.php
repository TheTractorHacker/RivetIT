<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT p.*, r.payroll_run_id, r.payroll_run_status
     FROM payroll_periods p
     LEFT JOIN payroll_runs r ON r.payroll_run_period_id = p.payroll_period_id AND r.payroll_run_archived_at IS NULL
     WHERE p.payroll_period_archived_at IS NULL
     ORDER BY p.payroll_period_start_date DESC"
);

// Suggest the next period's start/end based on the default pay frequency.
$suggest_start = date('Y-m-d');
$last_period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_period_end_date FROM payroll_periods WHERE payroll_period_archived_at IS NULL ORDER BY payroll_period_end_date DESC LIMIT 1"));
if ($last_period) {
    $suggest_start = date('Y-m-d', strtotime($last_period['payroll_period_end_date'] . ' +1 day'));
}
$frequency_days = ['weekly' => 6, 'biweekly' => 13, 'semimonthly' => 14, 'monthly' => 29];
$suggest_days = $frequency_days[$config_payroll_default_pay_frequency] ?? 13;
$suggest_end = date('Y-m-d', strtotime($suggest_start . " +$suggest_days days"));

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-calendar-alt me-2"></i>Pay Periods</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/payroll_period/payroll_period_add.php?suggest_start=<?= urlencode($suggest_start) ?>&suggest_end=<?= urlencode($suggest_end) ?>">
                <i class="fas fa-plus me-2"></i>New Period
            </button>
        </div>
    </div>

    <div class="card-body">
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Payroll Run</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No pay periods yet.</td></tr>
                <?php } ?>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $p_id = intval($row['payroll_period_id']);
                    $p_start = date('M j, Y', strtotime($row['payroll_period_start_date']));
                    $p_end = date('M j, Y', strtotime($row['payroll_period_end_date']));
                    $p_status = $row['payroll_period_status'];
                    $run_id = $row['payroll_run_id'] ? intval($row['payroll_run_id']) : 0;
                    $run_status = $row['payroll_run_status'];
                ?>
                <tr>
                    <td><?= $p_start ?> &ndash; <?= $p_end ?></td>
                    <td>
                        <?php if ($p_status === 'locked') { ?>
                            <span class="badge text-bg-secondary"><i class="fas fa-lock me-1"></i>Locked</span>
                        <?php } else { ?>
                            <span class="badge text-bg-success">Open</span>
                        <?php } ?>
                    </td>
                    <td>
                        <?php if ($run_id) { ?>
                            <span class="badge <?= $run_status === 'finalized' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= ucfirst($run_status) ?></span>
                        <?php } else { ?>
                            <span class="text-muted">—</span>
                        <?php } ?>
                    </td>
                    <td class="text-center">
                        <a href="payroll_period.php?id=<?= $p_id ?>" class="btn btn-xs btn-secondary">
                            <i class="fas fa-arrow-right"></i> Open
                        </a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
