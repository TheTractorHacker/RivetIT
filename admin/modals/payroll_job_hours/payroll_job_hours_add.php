<?php
require_once '../../../includes/modal_header.php';

$period_id = intval($_GET['period_id'] ?? 0);
$period = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payroll_periods WHERE payroll_period_id = $period_id LIMIT 1"));
if (!$period || $period['payroll_period_status'] === 'locked') {
    echo '<div class="p-3 text-danger">This pay period cannot be edited.</div>';
    require_once '../../../includes/modal_footer.php';
    exit;
}

$employees_sql = mysqli_query(
    $mysqli,
    "SELECT u.user_id, u.user_name
     FROM users u
     JOIN payroll_pay_rates r ON r.payroll_pay_rate_user_id = u.user_id AND r.payroll_pay_rate_archived_at IS NULL
     WHERE u.user_type = 1 AND u.user_archived_at IS NULL
     ORDER BY u.user_name ASC"
);

$tickets_sql = mysqli_query(
    $mysqli,
    "SELECT tickets.ticket_id, tickets.ticket_prefix, tickets.ticket_number, tickets.ticket_subject,
            clients.client_name, clients.client_abbreviation
     FROM tickets
     LEFT JOIN clients ON clients.client_id = tickets.ticket_client_id
     WHERE tickets.ticket_archived_at IS NULL
     ORDER BY tickets.ticket_id DESC
     LIMIT 500"
);

ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-briefcase me-2"></i>Add Job-Specific Hours</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="period_id" value="<?= $period_id ?>">
    <div class="modal-body">

        <?php if (mysqli_num_rows($employees_sql) === 0) { ?>
        <div class="alert alert-warning">No employees are enrolled in payroll yet. <a href="/admin/payroll_employees.php">Enroll one first</a>.</div>
        <?php } else { ?>

        <div class="form-group">
            <label>Employee <strong class="text-danger">*</strong></label>
            <select class="form-control" name="user_id" required>
                <option value="">- Select Employee -</option>
                <?php while ($row = mysqli_fetch_assoc($employees_sql)) { ?>
                <option value="<?= intval($row['user_id']) ?>"><?= nullable_htmlentities($row['user_name']) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Ticket <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="ticket_id" id="payrollJobTicketSelect" data-placeholder="- Select a Ticket -" required>
                <option value=""></option>
                <?php while ($row = mysqli_fetch_assoc($tickets_sql)) {
                    $client_label = $row['client_abbreviation'] ?: $row['client_name'];
                    $label = trim((string) $row['ticket_prefix'] . $row['ticket_number']) . ' - ' . $row['ticket_subject'] . ($client_label ? " ($client_label)" : '');
                ?>
                <option value="<?= intval($row['ticket_id']) ?>"><?= nullable_htmlentities($label) ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label>Hours Worked <strong class="text-danger">*</strong></label>
            <div>
                <input type="text" inputmode="numeric" maxlength="3" name="hours_h" value="0" class="form-control form-control-sm text-center d-inline-block" style="max-width:4rem;">
                <span class="mx-1">h</span>
                <input type="text" inputmode="numeric" maxlength="2" name="hours_m" value="0" class="form-control form-control-sm text-center d-inline-block" style="max-width:4rem;">
                <span class="ms-1">m</span>
            </div>
        </div>

        <div class="form-group">
            <label>Rate for this job (per hour) <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                <input type="text" class="form-control" inputmode="decimal" pattern="[0-9]*\.?[0-9]{0,2}" name="rate" placeholder="0.00" required>
            </div>
            <small class="form-text text-muted">A one-off rate for this job only - the employee's standard rate is untouched, and these hours don't count toward the weekly overtime threshold.</small>
        </div>

        <div class="form-group">
            <label>Note</label>
            <input type="text" class="form-control" name="note" maxlength="255" placeholder="Optional - e.g. reason for the different rate">
        </div>

        <?php } ?>

    </div>
    <div class="modal-footer">
        <?php if (mysqli_num_rows($employees_sql) > 0) { ?>
        <button type="submit" name="add_payroll_job_hours" class="btn btn-primary"><i class="fas fa-check me-2"></i>Add</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var el = document.getElementById('payrollJobTicketSelect');
    if (el && window.TomSelect) {
        if (el.tomselect) { el.tomselect.destroy(); }
        new TomSelect(el, { create: false, persist: false, allowEmptyOption: true, placeholder: el.getAttribute('data-placeholder') || '' });
    }
})();
</script>
<?php
require_once '../../../includes/modal_footer.php';
