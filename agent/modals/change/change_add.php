<?php
require_once '../../../includes/modal_header.php';

$linked_problem_id = intval($_GET['problem_id'] ?? 0);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-exchange-alt me-2"></i>New Change</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php if ($linked_problem_id) { ?>
        <input type="hidden" name="linked_problem_id" value="<?= $linked_problem_id ?>">
    <?php } ?>

    <div class="modal-body">
        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="title" placeholder="e.g. Migrate file server to new hardware" maxlength="255" required autofocus>
        </div>
        <div class="form-group">
            <label>Risk <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="risk" required>
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
            </select>
        </div>
        <div class="form-group">
            <label>Reason</label>
            <textarea class="form-control" name="reason" rows="2" placeholder="Why is this change needed?"></textarea>
        </div>
        <div class="form-group">
            <label>Impact</label>
            <textarea class="form-control" name="impact" rows="2" placeholder="What/who is affected while this is carried out?"></textarea>
        </div>
        <div class="form-group">
            <label>Implementation Plan</label>
            <textarea class="form-control" name="implementation_plan" rows="3" placeholder="Steps to carry out the change"></textarea>
        </div>
        <div class="form-group">
            <label>Rollback Plan</label>
            <textarea class="form-control" name="rollback_plan" rows="3" placeholder="How to undo this if it goes wrong"></textarea>
        </div>
        <div class="form-group">
            <label>Scheduled For</label>
            <input type="datetime-local" class="form-control" name="scheduled_at">
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_change" class="btn btn-primary"><i class="fa fa-check me-2"></i>Create Change</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
