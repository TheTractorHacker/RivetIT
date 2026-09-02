<?php
require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support');

$problem_id = intval($_GET['id'] ?? 0);
$problem = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM problems WHERE problem_id = $problem_id"));
if (!$problem) {
    http_response_code(404);
    echo json_encode(['error' => 'Problem not found.']);
    exit;
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exclamation-circle me-2"></i>Edit Problem</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="problem_id" value="<?= $problem_id ?>">

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" value="<?= nullable_htmlentities($problem['title']) ?>" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="description" rows="5"><?= nullable_htmlentities($problem['description']) ?></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_problem" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
