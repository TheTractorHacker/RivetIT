<?php

require_once '../../../includes/modal_header.php';

$price_quote_id = intval($_GET['price_quote_id'] ?? 0);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM price_quotes WHERE price_quote_id = $price_quote_id"));
if (!$row) {
    http_response_code(404);
    exit;
}

enforceUserPermission('module_client', 2);
enforceClientAccess(intval($row['price_quote_client_id']));

$price_quote_title = nullable_htmlentities($row['price_quote_title']);
$price_quote_description = nullable_htmlentities($row['price_quote_description']);

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>Edit Price Quote</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="price_quote_id" value="<?= $price_quote_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Title <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" value="<?= $price_quote_title ?>" maxlength="255" required autofocus>
            </div>
        </div>

        <div class="form-group">
            <label>Description</label>
            <textarea class="form-control" rows="4" name="description"><?= $price_quote_description ?></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_price_quote" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
