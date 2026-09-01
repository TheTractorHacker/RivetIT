<?php

require_once '../../../includes/modal_header.php';

$kb_category_id = intval($_GET['id']);

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM kb_categories WHERE kb_category_id = $kb_category_id LIMIT 1"));

$kb_category_name = nullable_htmlentities($row['kb_category_name']);
$kb_category_parent_id = intval($row['kb_category_parent_id']);
$kb_category_client_id = intval($row['kb_category_client_id']);

$sql_client_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$sql_parent_select = mysqli_query($mysqli, "SELECT kb_category_id, kb_category_name FROM kb_categories WHERE kb_category_archived_at IS NULL AND kb_category_id != $kb_category_id ORDER BY kb_category_name ASC");

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-folder me-2"></i>Edit Category: <strong><?= $kb_category_name ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="kb_category_id" value="<?= $kb_category_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <input type="text" class="form-control" name="name" maxlength="100" value="<?= $kb_category_name ?>" required autofocus>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label>Parent Category</label>
                    <select class="form-control select2" name="parent_id">
                        <option value="0" <?php if ($kb_category_parent_id == 0) { echo "selected"; } ?>>- Top Level -</option>
                        <?php while ($parent = mysqli_fetch_assoc($sql_parent_select)) { ?>
                            <option value="<?= intval($parent['kb_category_id']) ?>" <?php if ($kb_category_parent_id == $parent['kb_category_id']) { echo "selected"; } ?>><?= nullable_htmlentities($parent['kb_category_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label>Client</label>
                    <select class="form-control select2" name="client_id">
                        <option value="0" <?php if ($kb_category_client_id == 0) { echo "selected"; } ?>>Central (Company-wide)</option>
                        <?php
                        while ($client_row = mysqli_fetch_assoc($sql_client_select)) {
                            $select_client_id = intval($client_row['client_id']);
                            $select_client_name = nullable_htmlentities($client_row['client_name']);
                        ?>
                            <option value="<?= $select_client_id ?>" <?php if ($kb_category_client_id == $select_client_id) { echo "selected"; } ?>><?= $select_client_name ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_kb_category" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save Changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
