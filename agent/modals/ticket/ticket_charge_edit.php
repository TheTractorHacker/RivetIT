<?php

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$charge_id = intval($_GET['charge_id'] ?? 0);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM ticket_charges LEFT JOIN tickets ON ticket_id = charge_ticket_id WHERE charge_id = $charge_id LIMIT 1"));
if (!$row) { echo '<div class="p-3 text-danger">Charge not found.</div>'; require_once '../../../includes/modal_footer.php'; exit; }

$client_id   = intval($row['ticket_client_id']);
enforceClientAccess($client_id);

$ticket_id   = intval($row['charge_ticket_id']);
$charge_name = nullable_htmlentities($row['charge_name']);
$charge_desc = nullable_htmlentities($row['charge_description']);
$charge_qty  = floatval($row['charge_quantity']);
$charge_price = floatval($row['charge_unit_price']);
$charge_total = floatval($row['charge_total']);
$charge_tax_id = intval($row['charge_tax_id']);
$charge_product_id = intval($row['charge_product_id']);
$charge_labor_type_id = intval($row['charge_labor_type_id']);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-edit me-2"></i>Edit Charge</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="charge_id" value="<?= $charge_id ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <input type="hidden" name="charge_product_id" value="<?= $charge_product_id ?>">
    <input type="hidden" name="charge_labor_type_id" id="edit_labor_type_id" value="<?= $charge_labor_type_id ?>">

    <div class="modal-body">

        <!-- Labor Type quick-select -->
        <?php
        $sql_lt = mysqli_query($mysqli, "SELECT * FROM labor_types WHERE labor_type_archived_at IS NULL ORDER BY labor_type_order ASC, labor_type_name ASC");
        $lt_rows = [];
        while ($lt = mysqli_fetch_assoc($sql_lt)) $lt_rows[] = $lt;
        if ($lt_rows) { ?>
        <div class="form-group">
            <label>Labor Type</label>
            <div class="d-flex flex-wrap" id="edit_labor_type_btns">
                <button type="button" class="btn btn-sm lt-btn me-1 mb-1 <?= $charge_labor_type_id == 0 ? 'active' : '' ?> btn-outline-secondary" data-id="0" data-rate="0" data-name="">
                    Custom
                </button>
                <?php foreach ($lt_rows as $lt) {
                    $lt_id    = intval($lt['labor_type_id']);
                    $lt_name  = nullable_htmlentities($lt['labor_type_name']);
                    $lt_rate  = floatval($lt['labor_type_rate']);
                    $lt_color = nullable_htmlentities($lt['labor_type_color']);
                    $is_active = $lt_id === $charge_labor_type_id;
                ?>
                <button type="button" class="btn btn-sm lt-btn me-1 mb-1 <?= $is_active ? 'active' : '' ?>"
                        data-id="<?= $lt_id ?>"
                        data-rate="<?= $lt_rate ?>"
                        data-name="<?= $lt_name ?>"
                        style="background:<?= $lt_color ?>;color:#fff;border-color:<?= $lt_color ?>;<?= $is_active ? '' : 'opacity:0.65;' ?>">
                    <?= $lt_name ?>
                </button>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <div class="form-group">
            <label>Item Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-tag"></i></span>
                </div>
                <input type="text" class="form-control" name="charge_name" id="charge_name" value="<?= $charge_name ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" name="charge_description" id="charge_description" rows="3"><?= $charge_desc ?></textarea>
        </div>

        <div class="form-row">
            <div class="col-sm-4">
                <div class="form-group">
                    <label>Qty <strong class="text-danger">*</strong></label>
                    <input type="number" class="form-control" name="charge_quantity" id="charge_quantity" value="<?= $charge_qty ?>" min="0.01" step="0.01" required>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="form-group">
                    <label>Unit Price <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                        <input type="number" class="form-control" name="charge_unit_price" id="charge_unit_price" value="<?= number_format($charge_price, 2, '.', '') ?>" min="0" step="0.01" required>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="form-group">
                    <label>Total</label>
                    <div class="input-group">
                        <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                        <input type="text" class="form-control" id="charge_total_display" readonly value="<?= number_format($charge_total, 2, '.', '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Tax</label>
            <div class="input-group">
                <div class="input-group-prepend"><span class="input-group-text"><i class="fa fa-fw fa-piggy-bank"></i></span></div>
                <select class="form-control select2" name="charge_tax_id">
                    <option value="0">None</option>
                    <?php
                    $sql_taxes = mysqli_query($mysqli, "SELECT * FROM taxes WHERE tax_archived_at IS NULL ORDER BY tax_name ASC");
                    while ($t = mysqli_fetch_assoc($sql_taxes)) {
                        $sel = intval($t['tax_id']) === $charge_tax_id ? ' selected' : '';
                        echo '<option value="' . intval($t['tax_id']) . '"' . $sel . '>' . nullable_htmlentities($t['tax_name']) . ' ' . floatval($t['tax_percent']) . '%</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_ticket_charge" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
$(function() {
    function recalcTotal() {
        var qty   = parseFloat($('#charge_quantity').val()) || 0;
        var price = parseFloat($('#charge_unit_price').val()) || 0;
        $('#charge_total_display').val((qty * price).toFixed(2));
    }
    $('#charge_quantity, #charge_unit_price').on('input', recalcTotal);

    $(document).on('click', '.lt-btn', function() {
        $('.lt-btn').removeClass('active').css('opacity','0.65');
        $(this).addClass('active').css('opacity','1');
        var id   = parseInt($(this).data('id')) || 0;
        var rate = parseFloat($(this).data('rate')) || 0;
        var name = $(this).data('name') || '';
        $('#edit_labor_type_id').val(id);
        if (id > 0 && rate > 0) {
            $('#charge_unit_price').val(rate.toFixed(2));
            recalcTotal();
        }
    });
});
</script>

<?php
require_once '../../../includes/modal_footer.php';
