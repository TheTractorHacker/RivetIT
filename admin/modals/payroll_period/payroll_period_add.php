<?php
require_once '../../../includes/modal_header.php';

$suggest_start = sanitizeInput($_GET['suggest_start'] ?? date('Y-m-d'));
$suggest_end = sanitizeInput($_GET['suggest_end'] ?? date('Y-m-d'));

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-calendar-alt me-2"></i>New Pay Period</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Start Date <strong class="text-danger">*</strong></label>
            <input type="date" class="form-control" name="start_date" value="<?= $suggest_start ?>" required>
        </div>

        <div class="form-group">
            <label>End Date <strong class="text-danger">*</strong></label>
            <input type="date" class="form-control" name="end_date" value="<?= $suggest_end ?>" required>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="add_payroll_period" class="btn btn-primary"><i class="fas fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
