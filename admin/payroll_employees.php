<?php

require_once "includes/inc_all_admin.php";

$sql = mysqli_query(
    $mysqli,
    "SELECT u.user_id, u.user_name, u.user_email, u.user_avatar,
            r.payroll_pay_rate_type, r.payroll_pay_rate_amount
     FROM users u
     LEFT JOIN payroll_pay_rates r
            ON r.payroll_pay_rate_user_id = u.user_id
           AND r.payroll_pay_rate_archived_at IS NULL
     WHERE u.user_type = 1 AND u.user_archived_at IS NULL
     ORDER BY u.user_name ASC"
);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-tag me-2"></i>Payroll Employees</h3>
    </div>

    <div class="card-body">
        <p class="text-muted">Set a pay rate to enroll a staff member in payroll. Employees with no rate configured are never included in a payroll run.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Employee</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($sql) === 0) { ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No active staff users found.</td></tr>
                <?php } ?>
                <?php while ($row = mysqli_fetch_assoc($sql)) {
                    $u_id = intval($row['user_id']);
                    $u_name = nullable_htmlentities($row['user_name']);
                    $u_email = nullable_htmlentities($row['user_email']);
                    $pay_type = $row['payroll_pay_rate_type'];
                    $pay_amount = $row['payroll_pay_rate_amount'];
                ?>
                <tr>
                    <td>
                        <strong><?= $u_name ?></strong><br>
                        <span class="text-muted small"><?= $u_email ?></span>
                    </td>
                    <td>
                        <?php if ($pay_type === 'hourly') { ?>
                            <span class="badge text-bg-success">Hourly - $<?= number_format((float) $pay_amount, 2) ?>/hr</span>
                        <?php } elseif ($pay_type === 'salary') { ?>
                            <span class="badge text-bg-success">Salary - $<?= number_format((float) $pay_amount, 2) ?>/period</span>
                        <?php } else { ?>
                            <span class="badge text-bg-secondary">Not enrolled</span>
                        <?php } ?>
                    </td>
                    <td class="text-center">
                        <a href="payroll_employee.php?id=<?= $u_id ?>" class="btn btn-xs btn-secondary">
                            <i class="fas fa-edit"></i> <?= $pay_type ? 'Edit' : 'Enroll' ?>
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
