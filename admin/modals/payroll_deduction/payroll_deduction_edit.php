<?php
require_once '../../../includes/modal_header.php';

$payroll_deduction_category_id = intval($_GET['id'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_deduction_categories WHERE payroll_deduction_category_id = $payroll_deduction_category_id LIMIT 1"));
if (!$row) { echo '<div class="p-3 text-danger">Deduction category not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$dc_name  = nullable_htmlentities($row['payroll_deduction_category_name']);
$dc_desc  = nullable_htmlentities($row['payroll_deduction_category_description']);
$dc_order = intval($row['payroll_deduction_category_order']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-edit me-2"></i>Edit Deduction Category</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="payroll_deduction_category_id" value="<?= $payroll_deduction_category_id ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="name" value="<?= $dc_name ?>" maxlength="100" required>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <input type="text" class="form-control" name="description" value="<?= $dc_desc ?>" maxlength="255">
        </div>

        <div class="form-group">
            <label>Order</label>
            <input type="number" class="form-control" name="order" value="<?= $dc_order ?>" min="0">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_payroll_deduction_category" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
