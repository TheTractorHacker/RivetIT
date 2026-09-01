<?php
require_once '../../../includes/modal_header.php';
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-clock me-2"></i>New Labor Type</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span></div>
                <input type="text" class="form-control" name="name" placeholder="e.g. Remote Support" maxlength="100" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Default Rate (per hour)</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="number" class="form-control" name="rate" value="0.00" min="0" step="0.01">
            </div>
            <small class="form-text text-muted">Auto-fills unit price when this type is selected on a charge.</small>
        </div>

        <div class="form-group">
            <label>Color</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-paint-brush"></i></span></div>
                <input type="color" class="form-control col-3" name="color" value="#6c757d">
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_labor_type" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
