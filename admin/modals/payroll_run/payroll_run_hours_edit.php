<?php
require_once '../../../includes/modal_header.php';
require_once '../../../includes/payroll_functions.php';

$run_id = intval($_GET['run_id'] ?? 0);
$user_id = intval($_GET['user_id'] ?? 0);
$period_id = intval($_GET['period_id'] ?? 0);

$run = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_run_status FROM payroll_runs WHERE payroll_run_id = $run_id LIMIT 1"));
$period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id LIMIT 1"));
$user_name = nullable_htmlentities(getFieldById('users', $user_id, 'user_name'));

if (!$run || !$period || $run['payroll_run_status'] === 'finalized') {
    echo '<div class="p-3 text-danger">This payroll run can no longer be edited.</div>';
    require_once '../../../includes/modal_footer.php';
    exit;
}

$buckets = payrollGetWeekBuckets($period['payroll_period_start_date'], $period['payroll_period_end_date']);

$existing_by_week = [];
foreach ($buckets as $bucket) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_hours_entry_worked_time FROM payroll_hours_entries WHERE payroll_hours_entry_user_id = $user_id AND payroll_hours_entry_week_start_date = '{$bucket['start']}' LIMIT 1"));
    $existing_by_week[$bucket['start']] = $row['payroll_hours_entry_worked_time'] ?? '00:00:00';
}

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-edit me-2"></i>Edit Hours - <?= $user_name ?></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="run_id" value="<?= $run_id ?>">
    <input type="hidden" name="user_id" value="<?= $user_id ?>">
    <input type="hidden" name="period_id" value="<?= $period_id ?>">
    <div class="modal-body">

        <?php foreach ($buckets as $bucket) {
            $existing_time = $existing_by_week[$bucket['start']];
            $parts = explode(':', $existing_time);
            $eh = intval($parts[0] ?? 0);
            $em = intval($parts[1] ?? 0);
        ?>
        <div class="form-group">
            <label>Week of <?= date('M j, Y', strtotime($bucket['start'])) ?></label>
            <div>
                <input type="text" inputmode="numeric" maxlength="3" name="weeks[<?= $bucket['start'] ?>][h]"
                    value="<?= $eh ?>" class="form-control form-control-sm text-center d-inline-block" style="max-width:4rem;">
                <span class="mx-1">h</span>
                <input type="text" inputmode="numeric" maxlength="2" name="weeks[<?= $bucket['start'] ?>][m]"
                    value="<?= $em ?>" class="form-control form-control-sm text-center d-inline-block" style="max-width:4rem;">
                <span class="ms-1">m</span>
            </div>
        </div>
        <?php } ?>

        <small class="form-text text-muted">Saving here updates the hours entry - it does not recalculate this run's totals automatically. Click <strong>Recalculate</strong> on the run page afterward.</small>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_payroll_run_hours" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php
require_once '../../../includes/modal_footer.php';
