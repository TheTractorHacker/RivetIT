<?php
require_once '../../../includes/modal_header.php';
enforceUserPermission('module_contracts');

$contract_id = intval($_GET['contract_id']);
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM contracts WHERE contract_id = $contract_id LIMIT 1"));
if (!$row) { echo '<div class="p-3 text-danger">Contract not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$client_id = intval($row['contract_client_id']);
if ($client_id) enforceClientAccess();

$contract_types = ['Managed Services', 'Fully Managed', 'Partially Managed', 'Break/Fix', 'Block Hours', 'Project', 'SLA', 'Other'];
$contract_statuses = ['Active', 'Pending', 'Expired', 'Cancelled'];
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-edit me-2"></i>Edit Contract</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="contract_id" value="<?= $contract_id ?>">
    <input type="hidden" name="contract_client_id" value="<?= intval($row['contract_client_id']) ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>Contract Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-file-contract"></i></span></div>
                <input type="text" class="form-control" name="contract_name" value="<?= nullable_htmlentities($row['contract_name']) ?>" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Type</label>
                <select class="form-control select2" name="contract_type">
                    <option value="">- Select Type -</option>
                    <?php foreach ($contract_types as $t) echo "<option" . ($row['contract_type'] === $t ? ' selected' : '') . ">$t</option>"; ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Status <strong class="text-danger">*</strong></label>
                <select class="form-control select2" name="contract_status" required>
                    <?php foreach ($contract_statuses as $s) echo "<option" . ($row['contract_status'] === $s ? ' selected' : '') . ">$s</option>"; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Billing Value ($)</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span></div>
                    <input type="number" step="0.01" class="form-control" name="contract_value" value="<?= $row['contract_value'] ?>">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Billing Frequency</label>
                <?php $freq = $row['contract_renewal_frequency'] ?? ''; ?>
                <select class="form-control select2" name="contract_renewal_frequency">
                    <option value="">- Select -</option>
                    <?php foreach (['Monthly','Quarterly','Annual','Other'] as $f) echo "<option" . ($freq === $f ? ' selected' : '') . ">$f</option>"; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Start Date</label>
                <input type="date" class="form-control" name="contract_start_date" value="<?= $row['contract_start_date'] ?>">
            </div>
            <div class="form-group col-md-4">
                <label>End Date</label>
                <input type="date" class="form-control" name="contract_end_date" value="<?= $row['contract_end_date'] ?>">
            </div>
            <div class="form-group col-md-4">
                <label>Renewal Date</label>
                <input type="date" class="form-control" name="contract_renewal_date" value="<?= $row['contract_renewal_date'] ?>">
            </div>
        </div>

        <hr>
        <h6 class="text-uppercase text-muted mb-3" style="font-size:11px;letter-spacing:.6px;"><i class="fas fa-stopwatch me-1"></i>SLA Response &amp; Resolution Times <small>(hours, 0 = no SLA)</small></h6>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-2">
                <thead class="thead-light">
                    <tr><th>Priority</th><th>Response Time (hrs)</th><th>Resolution Time (hrs)</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="badge text-bg-success">Low</span></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_low_response" value="<?= intval($row['contract_sla_low_response_time']) ?: '' ?>"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_low_resolution" value="<?= intval($row['contract_sla_low_resolution_time']) ?: '' ?>"></td>
                    </tr>
                    <tr>
                        <td><span class="badge text-bg-warning text-dark">Medium</span></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_medium_response" value="<?= intval($row['contract_sla_medium_response_time']) ?: '' ?>"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_medium_resolution" value="<?= intval($row['contract_sla_medium_resolution_time']) ?: '' ?>"></td>
                    </tr>
                    <tr>
                        <td><span class="badge text-bg-danger">High</span></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_high_response" value="<?= intval($row['contract_sla_high_response_time']) ?: '' ?>"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_high_resolution" value="<?= intval($row['contract_sla_high_resolution_time']) ?: '' ?>"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea class="form-control" name="contract_details" rows="2"><?= nullable_htmlentities($row['contract_details']) ?></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_contract" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
