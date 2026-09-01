<?php
require_once '../../../includes/modal_header.php';
$kb_article_id = intval($_GET['kb_article_id']);
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-paperclip me-2"></i>Upload Attachment</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="kb_article_id" value="<?= $kb_article_id ?>">
    <div class="modal-body">
        <div class="form-group">
            <label>File <strong class="text-danger">*</strong></label>
            <input type="file" class="form-control-file" name="attachment_file" required>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="upload_kb_article_attachment" class="btn btn-primary"><i class="fa fa-check me-2"></i>Upload</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>
<?php require_once '../../../includes/modal_footer.php'; ?>
