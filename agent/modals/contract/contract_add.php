<?php
require_once '../../../includes/modal_header.php';
$client_id_pre = intval($_GET['client_id'] ?? 0);
$contract_types = ['Managed Services', 'Fully Managed', 'Partially Managed', 'Break/Fix', 'Block Hours', 'Project', 'SLA', 'Other'];
$contract_statuses = ['Active', 'Pending', 'Expired', 'Cancelled'];
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-file-contract me-2"></i>New Contract</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="modal-body">
        <?php if (!$client_id_pre) { ?>
        <div class="form-group">
            <label>Client <strong class="text-danger">*</strong></label>
            <select class="form-control select2" name="contract_client_id" required>
                <option value="">- Select Client -</option>
                <?php $sql_c = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");
                while ($c = mysqli_fetch_assoc($sql_c)) echo "<option value=\"{$c['client_id']}\">{$c['client_name']}</option>"; ?>
            </select>
        </div>
        <?php } else { ?>
        <input type="hidden" name="contract_client_id" value="<?= $client_id_pre ?>">
        <?php } ?>
        <div class="form-group">
            <label>Contract Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-file-contract"></i></span></div>
                <input type="text" class="form-control" name="contract_name" placeholder="e.g. Monthly MSP Agreement" required maxlength="200">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Type</label>
                <select class="form-control select2" name="contract_type">
                    <option value="">- Select Type -</option>
                    <?php foreach ($contract_types as $t) echo "<option>$t</option>"; ?>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Status <strong class="text-danger">*</strong></label>
                <select class="form-control select2" name="contract_status" required>
                    <?php foreach ($contract_statuses as $s) echo "<option>$s</option>"; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Billing Value ($)</label>
                <div class="input-group">
                    <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span></div>
                    <input type="number" step="0.01" class="form-control" name="contract_value" placeholder="0.00">
                </div>
            </div>
            <div class="form-group col-md-6">
                <label>Billing Frequency</label>
                <select class="form-control select2" name="contract_renewal_frequency">
                    <option value="">- Select -</option>
                    <option>Monthly</option>
                    <option>Quarterly</option>
                    <option>Annual</option>
                    <option>Other</option>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group col-md-4">
                <label>Start Date</label>
                <input type="date" class="form-control" name="contract_start_date">
            </div>
            <div class="form-group col-md-4">
                <label>End Date</label>
                <input type="date" class="form-control" name="contract_end_date">
            </div>
            <div class="form-group col-md-4">
                <label>Renewal Date</label>
                <input type="date" class="form-control" name="contract_renewal_date">
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
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_low_response" placeholder="e.g. 8"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_low_resolution" placeholder="e.g. 48"></td>
                    </tr>
                    <tr>
                        <td><span class="badge text-bg-warning text-dark">Medium</span></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_medium_response" placeholder="e.g. 4"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_medium_resolution" placeholder="e.g. 24"></td>
                    </tr>
                    <tr>
                        <td><span class="badge text-bg-danger">High</span></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_high_response" placeholder="e.g. 1"></td>
                        <td><input type="number" min="0" step="1" class="form-control form-control-sm" name="sla_high_resolution" placeholder="e.g. 4"></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea class="form-control" name="contract_details" rows="2" placeholder="Any additional notes..."></textarea>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="add_contract" class="btn btn-primary"><i class="fa fa-check me-2"></i>Create Contract</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
