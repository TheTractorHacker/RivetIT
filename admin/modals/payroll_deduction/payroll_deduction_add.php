<?php
require_once '../../../includes/modal_header.php';
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-minus-circle me-2"></i>New Deduction Category</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="name" placeholder="e.g. Health Insurance" maxlength="100" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <input type="text" class="form-control" name="description" maxlength="255" placeholder="Optional note">
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_payroll_deduction_category" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
