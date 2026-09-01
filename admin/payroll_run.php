<?php

require_once "includes/inc_all_admin.php";

$run_id = intval($_GET['id'] ?? 0);
$run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_runs WHERE payroll_run_id = $run_id AND payroll_run_archived_at IS NULL LIMIT 1"));
if (!$run) {
    flash_alert("Payroll run not found.", 'error');
    redirect('/admin/payroll_runs.php');
}

$period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = " . intval($run['payroll_run_period_id']) . " LIMIT 1"));
$is_finalized = $run['payroll_run_status'] === 'finalized';

$line_items_sql = mysqli_query($mysqli, "SELECT * FROM payroll_run_line_items WHERE payroll_run_line_item_run_id = $run_id ORDER BY payroll_run_line_item_employee_name_snapshot ASC");
$line_items = [];
while ($row = mysqli_fetch_assoc($line_items_sql)) {
    $row['deductions'] = [];
    $row['jobs'] = [];
    $line_items[intval($row['payroll_run_line_item_id'])] = $row;
}
if (!empty($line_items)) {
    $ids = implode(',', array_keys($line_items));
    $ded_sql = mysqli_query($mysqli, "SELECT * FROM payroll_run_line_item_deductions WHERE payroll_run_line_item_deduction_line_item_id IN ($ids)");
    while ($drow = mysqli_fetch_assoc($ded_sql)) {
        $line_items[intval($drow['payroll_run_line_item_deduction_line_item_id'])]['deductions'][] = $drow;
    }
    $job_sql = mysqli_query($mysqli, "SELECT * FROM payroll_run_line_item_jobs WHERE payroll_run_line_item_job_line_item_id IN ($ids)");
    while ($jrow = mysqli_fetch_assoc($job_sql)) {
        $line_items[intval($jrow['payroll_run_line_item_job_line_item_id'])]['jobs'][] = $jrow;
    }
}

$finalized_by_name = '';
if ($run['payroll_run_finalized_by']) {
    $finalized_by_name = nullable_htmlentities(getFieldById('users', intval($run['payroll_run_finalized_by']), 'user_name'));
}

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2">
            <i class="fas fa-fw fa-play-circle me-2"></i>
            Payroll Run: <?= date('M j, Y', strtotime($period['payroll_period_start_date'])) ?> &ndash; <?= date('M j, Y', strtotime($period['payroll_period_end_date'])) ?>
            <?php if ($is_finalized) { ?>
                <span class="badge text-bg-success ms-2"><i class="fas fa-lock me-1"></i>Finalized</span>
            <?php } else { ?>
                <span class="badge text-bg-warning ms-2">Draft</span>
            <?php } ?>
        </h3>
        <div class="card-tools">
            <a href="payroll_period.php?id=<?= intval($period['payroll_period_id']) ?>" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Period</a>
        </div>
    </div>
    <div class="card-body">

        <?php if ($is_finalized) { ?>
        <div class="alert alert-success">
            <i class="fas fa-lock me-1"></i>
            This run was finalized by <strong><?= $finalized_by_name !== '' ? $finalized_by_name : 'a user' ?></strong> on
            <strong><?= date('M j, Y g:i A', strtotime($run['payroll_run_finalized_at'])) ?></strong>. Every figure below is a
            permanent snapshot - later changes to pay rates, deductions, or the overtime rule can never alter it.
        </div>
        <?php } ?>

        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover align-middle">
                <thead class="text-dark">
                    <tr>
                        <th>Employee</th>
                        <th class="text-end">Reg. Hours</th>
                        <th class="text-end">OT Hours</th>
                        <th class="text-end">Reg. Pay</th>
                        <th class="text-end">OT Pay</th>
                        <th>Job Pay</th>
                        <th class="text-end">Gross</th>
                        <th>Deductions</th>
                        <th class="text-end">Net</th>
                        <?php if (!$is_finalized) { ?><th class="text-center">Action</th><?php } ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($line_items)) { ?>
                <tr><td colspan="10" class="text-center text-muted py-4">No enrolled employees to pay for this period.</td></tr>
                <?php } ?>
                <?php foreach ($line_items as $item_id => $item) {
                    $name = nullable_htmlentities($item['payroll_run_line_item_employee_name_snapshot']);
                    $pay_type = $item['payroll_run_line_item_pay_type_snapshot'];
                ?>
                <tr>
                    <td><?= $name ?><br><span class="badge text-bg-<?= $pay_type === 'salary' ? 'info' : 'primary' ?>"><?= ucfirst($pay_type) ?></span></td>
                    <td class="text-end"><?= number_format((float) $item['payroll_run_line_item_regular_hours'], 2) ?></td>
                    <td class="text-end"><?= number_format((float) $item['payroll_run_line_item_overtime_hours'], 2) ?></td>
                    <td class="text-end">$<?= number_format((float) $item['payroll_run_line_item_regular_pay'], 2) ?></td>
                    <td class="text-end">$<?= number_format((float) $item['payroll_run_line_item_overtime_pay'], 2) ?></td>
                    <td>
                        <?php if (empty($item['jobs'])) { ?>
                            <span class="text-muted">—</span>
                        <?php } else { foreach ($item['jobs'] as $job) { ?>
                            <div class="small"><?= nullable_htmlentities($job['payroll_run_line_item_job_ticket_label_snapshot']) ?>: <?= number_format((float) $job['payroll_run_line_item_job_hours'], 2) ?>h @ $<?= number_format((float) $job['payroll_run_line_item_job_rate'], 2) ?> = $<?= number_format((float) $job['payroll_run_line_item_job_pay'], 2) ?></div>
                        <?php } } ?>
                    </td>
                    <td class="text-end fw-bold">$<?= number_format((float) $item['payroll_run_line_item_gross_pay'], 2) ?></td>
                    <td>
                        <?php if (empty($item['deductions'])) { ?>
                            <span class="text-muted">—</span>
                        <?php } else { foreach ($item['deductions'] as $ded) { ?>
                            <div class="small"><?= nullable_htmlentities($ded['payroll_run_line_item_deduction_category_name_snapshot']) ?>: $<?= number_format((float) $ded['payroll_run_line_item_deduction_amount'], 2) ?></div>
                        <?php } } ?>
                    </td>
                    <td class="text-end fw-bold">$<?= number_format((float) $item['payroll_run_line_item_net_pay'], 2) ?></td>
                    <?php if (!$is_finalized) { ?>
                    <td class="text-center">
                        <?php if ($pay_type === 'hourly') { ?>
                        <a href="#" class="btn btn-xs btn-secondary ajax-modal" data-modal-url="modals/payroll_run/payroll_run_hours_edit.php?run_id=<?= $run_id ?>&user_id=<?= intval($item['payroll_run_line_item_user_id']) ?>&period_id=<?= intval($period['payroll_period_id']) ?>">
                            <i class="fas fa-edit"></i> Edit Hours
                        </a>
                        <?php } ?>
                    </td>
                    <?php } ?>
                </tr>
                <?php } ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold">
                        <td colspan="6" class="text-end">Totals</td>
                        <td class="text-end">$<?= number_format((float) $run['payroll_run_total_gross'], 2) ?></td>
                        <td class="text-end">$<?= number_format((float) $run['payroll_run_total_deductions'], 2) ?></td>
                        <td class="text-end">$<?= number_format((float) $run['payroll_run_total_net'], 2) ?></td>
                        <?php if (!$is_finalized) { ?><td></td><?php } ?>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if (!$is_finalized) { ?>
        <hr>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="run_id" value="<?= $run_id ?>">
            <button type="submit" name="recalculate_payroll_run" class="btn btn-outline-primary">
                <i class="fas fa-sync me-2"></i>Recalculate
            </button>
        </form>
        <form action="post.php" method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="run_id" value="<?= $run_id ?>">
            <button type="submit" name="finalize_payroll_run" class="btn btn-success confirm-link">
                <i class="fas fa-lock me-2"></i>Finalize
            </button>
        </form>
        <small class="d-block text-muted mt-2">Finalizing locks this run and its pay period permanently - the numbers above can never be changed again.</small>
        <?php } ?>

    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
