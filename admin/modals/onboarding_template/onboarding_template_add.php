<?php

require_once '../../../includes/modal_header.php';

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-user-plus me-2"></i>New Onboarding Template</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Template Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-plus"></i></span>
                </div>
                <input type="text" class="form-control" name="name" placeholder="e.g. Client Onboarding" maxlength="255" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-angle-right"></i></span>
                </div>
                <input type="text" class="form-control" name="description" placeholder="Description">
            </div>
        </div>

        <div class="form-group">
            <label>Default Contract Template <small class="text-secondary">(optional)</small></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-file-contract"></i></span>
                </div>
                <select class="form-control select2" name="default_contract_template_id">
                    <option value="">- None -</option>
                    <?php
                    $sql_contract_templates = mysqli_query($mysqli, "SELECT contract_template_id, contract_template_name FROM contract_templates WHERE contract_template_archived_at IS NULL ORDER BY contract_template_name ASC");
                    while ($contract_template_row = mysqli_fetch_assoc($sql_contract_templates)) {
                    ?>
                    <option value="<?= intval($contract_template_row['contract_template_id']) ?>"><?= nullable_htmlentities($contract_template_row['contract_template_name']) ?></option>
                    <?php } ?>
                </select>
            </div>
            <small class="form-text text-secondary">Pre-selected when creating a project from this template, so the client gets this contract automatically.</small>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_onboarding_template" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
