<?php

require_once '../../../includes/modal_header.php';

$canned_response_id = intval($_GET['id']);

$sql = mysqli_query($mysqli, "SELECT * FROM canned_responses WHERE canned_response_id = $canned_response_id LIMIT 1");
$row = mysqli_fetch_assoc($sql);

$canned_response_name = nullable_htmlentities($row['canned_response_name']);
$canned_response_message = $row['canned_response_message'];

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-comment-dots me-2"></i>Canned Response: <strong><?= $canned_response_name ?></strong></h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="canned_response_id" value="<?= $canned_response_id ?>">

    <div class="modal-body">

        <div class="form-group">
            <label>Name <strong class="text-danger">*</strong></label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-comment-dots"></i></span>
                </div>
                <input type="text" class="form-control" name="name" maxlength="255" value="<?= $canned_response_name ?>" required>
            </div>
        </div>

        <div class="form-group">
            <label>Message <strong class="text-danger">*</strong></label>
            <textarea class="form-control tinymceTicket" name="message"><?= $canned_response_message ?></textarea>
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="edit_canned_response" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i>Save changes</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
