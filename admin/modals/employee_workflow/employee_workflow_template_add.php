<?php

require_once '../../../includes/modal_header.php';

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-tasks me-2"></i>New Employee Workflow Template</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Template Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" placeholder="e.g. Standard Office Employee" maxlength="200" required autofocus>
        </div>

        <div class="form-group">
            <label>Type <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="type" required>
                <option value="onboarding">Onboarding</option>
                <option value="offboarding">Offboarding</option>
            </select>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" rows="3" placeholder="What this template is for / when to use it"></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_employee_workflow_template" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
