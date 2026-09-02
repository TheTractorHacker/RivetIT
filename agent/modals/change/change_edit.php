<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support');

$change_id = intval($_GET['id'] ?? 0);
$change = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM changes WHERE change_id = $change_id"));
if (!$change) {
    http_response_code(404);
    echo json_encode(['error' => 'Change not found.']);
    exit;
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exchange-alt me-2"></i>Edit Change</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="change_id" value="<?= $change_id ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" value="<?= nullable_htmlentities($change['title']) ?>" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Risk <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="risk" required>
                <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $label) { ?>
                    <option value="<?= $val ?>" <?= $change['risk'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-group">
            <label>Reason</label>
            <textarea class="form-control" name="reason" rows="2"><?= nullable_htmlentities($change['reason']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Impact</label>
            <textarea class="form-control" name="impact" rows="2"><?= nullable_htmlentities($change['impact']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Implementation Plan</label>
            <textarea class="form-control" name="implementation_plan" rows="3"><?= nullable_htmlentities($change['implementation_plan']) ?></textarea>
        </div>
        <div class="form-group">
            <label>Rollback Plan</label>
            <textarea class="form-control" name="rollback_plan" rows="3"><?= nullable_htmlentities($change['rollback_plan']) ?></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_change" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
