<?php

require_once '../../../includes/modal_header.php';

$price_quote_item_id = intval($_GET['price_quote_item_id'] ?? 0);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT pqi.*, pq.price_quote_client_id FROM price_quote_items pqi
    LEFT JOIN price_quotes pq ON pq.price_quote_id = pqi.price_quote_item_price_quote_id
    WHERE pqi.price_quote_item_id = $price_quote_item_id"));
if (!$row) {
    http_response_code(404);
    exit;
}

enforceUserPermission('module_client', 2);
enforceClientAccess(intval($row['price_quote_client_id']));

$price_quote_item_description = nullable_htmlentities($row['price_quote_item_description']);
$price_quote_item_quantity = nullable_htmlentities(rtrim(rtrim(number_format((float) $row['price_quote_item_quantity'], 2), '0'), '.'));
$price_quote_item_unit_price = nullable_htmlentities(number_format((float) $row['price_quote_item_unit_price'], 2, '.', ''));

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-list me-2"></i>Edit Line Item</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="price_quote_item_id" value="<?= $price_quote_item_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Description <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="description" value="<?= $price_quote_item_description ?>" maxlength="255" required autofocus>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Quantity <strong class="text-danger">*</strong></label>
                    <input type="text" class="form-control" inputmode="decimal" name="quantity" value="<?= $price_quote_item_quantity ?>" required>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label>Unit Price <strong class="text-danger">*</strong></label>
                    <input type="text" class="form-control" inputmode="decimal" name="unit_price" value="<?= $price_quote_item_unit_price ?>" required>
                </div>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_price_quote_item" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
