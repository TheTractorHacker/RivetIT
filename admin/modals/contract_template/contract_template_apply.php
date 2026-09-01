<?php
require_once '../../../includes/modal_header.php';

$contract_template_id = intval($_GET['id']);

$template = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contract_template_name FROM contract_templates WHERE contract_template_id = $contract_template_id LIMIT 1"));
$name = nullable_htmlentities($template['contract_template_name'] ?? '');

$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");

ob_start();
?>

<div class="modal-header">
    <h5 class="modal-title"><i class="fa fa-fw fa-copy me-2"></i>Apply Template: <?= $name ?></h5>
    <button type="button" class="close" data-bs-dismiss="modal"><span>&times;</span></button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="contract_template_id" value="<?= $contract_template_id ?>">

    <div class="modal-body">
        <p class="text-secondary">Create a new contract from this template for each selected client.</p>

        <div class="form-row">
            <div class="form-group col-md-6">
                <label>Contract Status</label>
                <select class="form-control select2" name="contract_status">
                    <option>Active</option>
                    <option>Pending</option>
                </select>
            </div>
            <div class="form-group col-md-6">
                <label>Start Date</label>
                <input type="date" class="form-control" name="contract_start_date" value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="d-block">
                <input type="checkbox" id="select_all_clients"> <strong>Select All Clients</strong>
            </label>
            <hr class="mt-1 mb-2">
            <div style="max-height: 300px; overflow-y: auto;">
                <?php while ($c = mysqli_fetch_assoc($sql_clients)) { ?>
                <div class="form-check">
                    <input type="checkbox" class="form-check-input client-checkbox" name="client_ids[]" value="<?= intval($c['client_id']) ?>" id="client_<?= intval($c['client_id']) ?>">
                    <label class="form-check-label" for="client_<?= intval($c['client_id']) ?>"><?= nullable_htmlentities($c['client_name']) ?></label>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>

    <div class="modal-footer">
        <button type="submit" name="apply_contract_template" class="btn btn-primary text-bold">
            <i class="fa fa-check me-2"></i>Apply to Selected Clients
        </button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">
            <i class="fa fa-times me-2"></i>Cancel
        </button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.getElementById('select_all_clients').addEventListener('change', function() {
    var checked = this.checked;
    document.querySelectorAll('.client-checkbox').forEach(function(cb) { cb.checked = checked; });
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
?>
