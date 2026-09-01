<?php
require_once '../../../includes/modal_header.php';
$template_id = intval($_GET['template_id']);
ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fa fa-fw fa-plus me-2"></i>Add Field</h5>
    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="field_template_id" value="<?= $template_id ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Field Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="field_name" placeholder="e.g. Customer Signature, Device Condition" required autofocus>
        </div>
        <div class="form-group">
            <label>Field Type <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="field_type" id="fieldTypeSelect" required>
                <option value="text">Text (single line)</option>
                <option value="textarea">Text Area (multi-line)</option>
                <option value="checkbox">Checkbox</option>
                <option value="select">Dropdown (select)</option>
                <option value="signature">Signature</option>
                <option value="heading">Section Heading</option>
            </select>
        </div>
        <div class="form-group" id="fieldOptionsGroup" style="display:none;">
            <label>Options <small class="text-secondary">(one per line, for dropdown fields)</small></label>
            <textarea class="form-control" name="field_options" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
        </div>
        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" id="fieldRequired" name="field_required" value="1">
                <label class="form-check-label" for="fieldRequired">Required field</label>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_worksheet_field" class="btn btn-primary"><i class="fa fa-check me-2"></i>Add Field</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.getElementById('fieldTypeSelect').addEventListener('change', function() {
    document.getElementById('fieldOptionsGroup').style.display = this.value === 'select' ? 'block' : 'none';
});
</script>
<?php require_once '../../../includes/modal_footer.php'; ?>
