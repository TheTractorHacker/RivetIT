<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);
$current_folder_id = intval($_GET['current_folder_id'] ?? 0);
$folder_location = intval($_GET['folder_location'] ?? 0); // 0/1 = files & documents, 2 = credentials
$folder_name = nullable_htmlentities(getFieldByID('folders', $current_folder_id, 'folder_name') ?? '/');

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-folder-plus me-2"></i>Creating folder in <strong><?= $folder_name ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <input type="hidden" name="parent_folder" value="<?= $current_folder_id ?>">
    <input type="hidden" name="folder_location" value="<?= $folder_location ?>">
    <div class="modal-body">

        <div class="form-group">
            <label>Folder Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-folder"></i></span>
                </div>
                <input type="text" class="form-control" name="folder_name" placeholder="Folder Name" maxlength="200" required>
            </div>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="create_folder" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Create</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
