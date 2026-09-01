<?php
require_once '../../../includes/modal_header.php';
$field_id = intval($_GET['field_id']);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM worksheet_template_fields WHERE field_id = $field_id LIMIT 1"));
$fname = nullable_htmlentities($row['field_name']);
$ftype = nullable_htmlentities($row['field_type']);
$fopts = nullable_htmlentities($row['field_options']);
$freq = intval($row['field_required']);
$ftmpl = intval($row['field_template_id']);
ob_start();
?>
<div class="modal-header">
    <h5 class="modal-title"><i class="fa fa-fw fa-edit me-2"></i>Edit Field</h5>
    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="field_id" value="<?= $field_id ?>">
    <input type="hidden" name="field_template_id" value="<?= $ftmpl ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Field Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="field_name" value="<?= $fname ?>" required>
        </div>
        <div class="form-group">
            <label>Field Type <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="field_type" id="fieldTypeSelectEdit" required>
                <?php foreach (['text','textarea','checkbox','select','signature','heading'] as $t) { ?>
                    <option value="<?= $t ?>" <?= $ftype === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group" id="fieldOptionsGroupEdit" <?= $ftype !== 'select' ? 'style="display:none;"' : '' ?>>
            <label>Options <small class="text-secondary">(one per line)</small></label>
            <textarea class="form-control" name="field_options" rows="4"><?= $fopts ?></textarea>
        </div>
        <div class="form-group">
            <div class="form-check form-check form-switch">
                <input type="checkbox" class="form-check-input" id="fieldRequiredEdit" name="field_required" value="1" <?= $freq ? 'checked' : '' ?>>
                <label class="form-check-label" for="fieldRequiredEdit">Required field</label>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_worksheet_field" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.getElementById('fieldTypeSelectEdit').addEventListener('change', function() {
    document.getElementById('fieldOptionsGroupEdit').style.display = this.value === 'select' ? 'block' : 'none';
});
</script>
<?php require_once '../../../includes/modal_footer.php'; ?>
