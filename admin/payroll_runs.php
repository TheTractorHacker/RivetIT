<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT r.*, p.payroll_period_start_date, p.payroll_period_end_date, u.user_name AS created_by_name
     FROM payroll_runs r
     JOIN payroll_periods p ON p.payroll_period_id = r.payroll_run_period_id
     LEFT JOIN users u ON u.user_id = r.payroll_run_created_by
     WHERE r.payroll_run_archived_at IS NULL
     ORDER BY p.payroll_period_start_date DESC"
);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-play-circle me-2"></i>Payroll Runs</h3>
    </div>

    <div class="card-body">
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Period</th>
                        <th>Status</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Deductions</th>
                        <th class="text-end">Net</th>
                        <th>Created By</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No payroll runs yet.</td></tr>
                <?php } ?>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $run_id = intval($row['payroll_run_id']);
                    $status = $row['payroll_run_status'];
                    $p_start = date('M j, Y', strtotime($row['payroll_period_start_date']));
                    $p_end = date('M j, Y', strtotime($row['payroll_period_end_date']));
                    $created_by = nullable_htmlentities($row['created_by_name'] ?? '—');
                ?>
                <tr>
                    <td><?= $p_start ?> &ndash; <?= $p_end ?></td>
                    <td>
                        <?php if ($status === 'finalized') { ?>
                            <span class="badge text-bg-success"><i class="fas fa-lock me-1"></i>Finalized</span>
                        <?php } else { ?>
                            <span class="badge text-bg-warning">Draft</span>
                        <?php } ?>
                    </td>
                    <td class="text-end">$<?= number_format((float) $row['payroll_run_total_gross'], 2) ?></td>
                    <td class="text-end">$<?= number_format((float) $row['payroll_run_total_deductions'], 2) ?></td>
                    <td class="text-end">$<?= number_format((float) $row['payroll_run_total_net'], 2) ?></td>
                    <td><?= $created_by ?></td>
                    <td class="text-center">
                        <a href="payroll_run.php?id=<?= $run_id ?>" class="btn btn-xs btn-secondary">
                            <i class="fas fa-eye"></i> View
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
