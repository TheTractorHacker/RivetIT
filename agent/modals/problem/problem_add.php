<?php
require_once '../../../includes/modal_header.php';

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exclamation-circle me-2"></i>New Problem</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" placeholder="e.g. Client X file server keeps crashing" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" rows="5" placeholder="Root cause analysis, related incidents, anything known so far..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_problem" class="btn btn-primary"><i class="fa fa-check me-2"></i>Create Problem</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
