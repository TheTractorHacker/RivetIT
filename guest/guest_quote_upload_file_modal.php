<div class="modal" id="uploadFileModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-fw fa-cloud-upload-alt me-2"></i>Upload File</h5>
                <button type="button" class="close" data-bs-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form action="guest_post.php" method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="quote_id" value="<?php echo $quote_id; ?>">
                <input type="hidden" name="url_key" value="<?php echo $url_key; ?>">
                <div class="modal-body bg-white">

                    <div class="form-group">
                        <input type="file" class="form-control-file" name="file[]" id="fileInput" accept=".pdf">
                    </div>

                </div>
                <div class="modal-footer bg-white">
                    <button type="submit" name="guest_quote_upload_file" class="btn btn-primary text-bold"><i class="fa fa-upload me-2"></i>Upload</button>
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>
