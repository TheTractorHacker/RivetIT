<?php
require_once '../../../includes/modal_header.php';

$user_id = intval($_GET['user_id'] ?? 0);
$user_name = nullable_htmlentities(getFieldById('users', $user_id, 'user_name'));

$categories_sql = mysqli_query($mysqli, "SELECT * FROM payroll_deduction_categories WHERE payroll_deduction_category_archived_at IS NULL ORDER BY payroll_deduction_category_order ASC, payroll_deduction_category_name ASC");

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-minus-circle me-2"></i>Add Deduction - <?= $user_name ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">
    <div class="modal-body">

        <?php if (mysqli_num_rows($categories_sql) === 0) { ?>
        <div class="alert alert-warning">
            No deduction categories exist yet. <a href="/admin/payroll_deductions.php">Create one first</a>.
        </div>
        <?php } else { ?>

        <div class="form-group">
            <label>Category <strong class="text-danger">*</strong></label>
            <select class="form-control" name="category_id" required>
                <?php while ($row = mysqli_fetch_assoc($categories_sql)) { ?>
                <option value="<?= intval($row['payroll_deduction_category_id']) ?>"><?= nullable_htmlentities($row['payroll_deduction_category_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Amount (per pay period) <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}" name="amount" value="0.00" required>
            </div>
        </div>

        <?php } ?>

    </div>
    <div class="modal-footer">
        <?php if (mysqli_num_rows($categories_sql) > 0) { ?>
        <button type="submit" name="add_payroll_employee_deduction" class="btn btn-primary"><i class="fas fa-check me-2"></i>Add</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
