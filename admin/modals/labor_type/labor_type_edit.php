<?php
require_once '../../../includes/modal_header.php';

$labor_type_id = intval($_GET['id'] ?? 0);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM labor_types WHERE labor_type_id = $labor_type_id LIMIT 1"));
if (!$row) { echo '<div class="p-3 text-danger">Labor type not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$lt_name  = nullable_htmlentities($row['labor_type_name']);
$lt_rate  = floatval($row['labor_type_rate']);
$lt_color = nullable_htmlentities($row['labor_type_color']);
$lt_order = intval($row['labor_type_order']);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-edit me-2"></i>Edit Labor Type</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="labor_type_id" value="<?= $labor_type_id ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="name" value="<?= $lt_name ?>" maxlength="100" required>
            </div>
        </div>

        <div class="form-group">
            <label>Default Rate (per hour)</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="number" class="form-control" name="rate" value="<?= number_format($lt_rate, 2, '.', '') ?>" min="0" step="0.01">
            </div>
        </div>

        <div class="form-group">
            <label>Color</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span></div>
                <input type="color" class="form-control col-3" name="color" value="<?= $lt_color ?>">
            </div>
        </div>

        <div class="form-group">
            <label>Order</label>
            <input type="number" class="form-control" name="order" value="<?= $lt_order ?>" min="0">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_labor_type" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
