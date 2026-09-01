<?php

require_once "includes/inc_all_admin.php";

$user_id = intval($_GET['id'] ?? 0);
$user_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM users WHERE user_id = $user_id AND user_type = 1 AND user_archived_at IS NULL LIMIT 1"));
if (!$user_row) {
    flash_alert("Employee not found.", 'error');
    redirect('/admin/payroll_employees.php');
}

$user_name = nullable_htmlentities($user_row['user_name']);

$rate_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_pay_rates WHERE payroll_pay_rate_user_id = $user_id AND payroll_pay_rate_archived_at IS NULL LIMIT 1"));
$pay_type = $rate_row['payroll_pay_rate_type'] ?? 'hourly';
$pay_amount = $rate_row ? floatval($rate_row['payroll_pay_rate_amount']) : 0.00;

$deductions_sql = mysqli_query(
    $mysqli,
    "SELECT ed.payroll_employee_deduction_id, ed.payroll_employee_deduction_amount,
            c.payroll_deduction_category_id, c.payroll_deduction_category_name
     FROM payroll_employee_deductions ed
     JOIN payroll_deduction_categories c ON c.payroll_deduction_category_id = ed.payroll_employee_deduction_category_id
     WHERE ed.payroll_employee_deduction_user_id = $user_id AND ed.payroll_employee_deduction_archived_at IS NULL
     ORDER BY c.payroll_deduction_category_name ASC"
);

?>

<div class="card card-dark">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-user-tag me-2"></i><?= $user_name ?> - Pay Rate</h3>
        <div class="card-tools">
            <a href="payroll_employees.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Employees</a>
        </div>
    </div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="user_id" value="<?= $user_id ?>">

            <div class="form-group">
                <label>Pay Type</label>
                <select class="form-control" name="pay_type" id="payrollPayType">
                    <option value="hourly" <?= $pay_type === 'hourly' ? 'selected' : '' ?>>Hourly</option>
                    <option value="salary" <?= $pay_type === 'salary' ? 'selected' : '' ?>>Salary (flat amount per pay period)</option>
                </select>
            </div>

            <div class="form-group">
                <label id="payrollAmountLabel"><?= $pay_type === 'salary' ? 'Amount per pay period' : 'Hourly rate' ?></label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                    <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}"
                        name="pay_amount" value="<?= number_format($pay_amount, 2, '.', '') ?>" placeholder="0.00" required>
                </div>
            </div>

            <button type="submit" name="save_payroll_pay_rate" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            <?php if ($rate_row) { ?>
            <a href="post.php?unenroll_payroll_employee=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
               class="btn btn-outline-danger confirm-link"><i class="fas fa-user-slash me-2"></i>Remove from Payroll</a>
            <?php } ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-minus-circle me-2"></i>Standing Deductions</h3>
        <div class="card-tools">
            <button type="button" class="btn btn-primary ajax-modal" data-modal-url="modals/payroll_employee_deduction/payroll_employee_deduction_add.php?user_id=<?= $user_id ?>">
                <i class="fas fa-plus me-2"></i>Add Deduction
            </button>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted">Applied automatically to every future payroll run for this employee until removed.</p>
        <hr>
        <div class="table-responsive-sm">
            <table class="table table-striped table-borderless table-hover">
                <thead class="text-dark">
                    <tr>
                        <th>Category</th>
                        <th>Amount</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($deductions_sql) === 0) { ?>
                <tr><td colspan="3" class="text-center text-muted py-4">No standing deductions.</td></tr>
                <?php } ?>
                <?php while ($row = mysqli_fetch_assoc($deductions_sql)) {
                    $ed_id = intval($row['payroll_employee_deduction_id']);
                    $cat_name = nullable_htmlentities($row['payroll_deduction_category_name']);
                    $amount = floatval($row['payroll_employee_deduction_amount']);
                ?>
                <tr>
                    <td><?= $cat_name ?></td>
                    <td>$<?= number_format($amount, 2) ?></td>
                    <td class="text-center">
                        <a href="post.php?remove_payroll_employee_deduction=<?= $ed_id ?>&user_id=<?= $user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                           class="btn btn-xs btn-danger confirm-link">
                            <i class="fas fa-trash"></i> Remove
                        </a>
                    </td>
                </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect = document.getElementById('payrollPayType');
    var label = document.getElementById('payrollAmountLabel');
    if (typeSelect && label) {
        typeSelect.addEventListener('change', function () {
            label.textContent = typeSelect.value === 'salary' ? 'Amount per pay period' : 'Hourly rate';
        });
    }
});
</script>

<?php require_once "../includes/footer.php"; ?>
