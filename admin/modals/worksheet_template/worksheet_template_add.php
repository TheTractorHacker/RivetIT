<?php
require_once '../../../includes/modal_header.php';
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-clipboard-list me-2"></i>New Worksheet Template</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Template Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-clipboard-list"></i></span></div>
                <input type="text" class="form-control" name="worksheet_template_name" placeholder="e.g. Laptop Intake Form" maxlength="200" required autofocus>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-align-left"></i></span></div>
                <input type="text" class="form-control" name="worksheet_template_description" placeholder="Optional description" maxlength="200">
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_worksheet_template" class="btn btn-primary"><i class="fa fa-check me-2"></i>Create Template</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
