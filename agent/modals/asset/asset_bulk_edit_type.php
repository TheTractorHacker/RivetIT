<?php

require_once '../../../includes/modal_header.php';

$asset_ids = array_map('intval', $_GET['asset_ids'] ?? []);

$count = count($asset_ids);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-layer-group me-2"></i>Set Type for <strong><?= $count ?></strong> Assets</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>

<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <?php foreach ($asset_ids as $asset_id) { ?><input type="hidden" name="asset_ids[]" value="<?= $asset_id ?>"><?php } ?>
    <div class="modal-body">

        <div class="form-group">
            <label>Type</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-layer-group"></i></span>
                </div>
                <select class="form-control select2" name="bulk_type" required>
                    <option value="">Select Type</option>
                    <?php foreach ($asset_types_array as $asset_type => $asset_icon) { ?>
                        <option><?= $asset_type ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit" name="bulk_edit_asset_type" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Set Type</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
