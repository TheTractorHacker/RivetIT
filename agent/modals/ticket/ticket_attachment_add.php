<?php
require_once '../../../includes/modal_header.php';
$ticket_id = intval($_GET['ticket_id']);
ob_start();
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-paperclip me-2"></i>Upload Attachment(s)</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal"><span>&times;</span></button>
</div>
<form action="post.php" method="post" autocomplete="off" enctype="multipart/form-data" id="ticketAttachmentUploadForm">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
    <div class="modal-body">
        <div class="form-group mb-0">
            <label>File(s) <strong class="text-danger">*</strong></label>
            <label for="ticketAttachmentFileInput" id="ticketAttachmentDropZone" class="d-flex flex-column align-items-center justify-content-center text-center p-4 mb-2" style="border:2px dashed var(--bs-border-color, #ccc);border-radius:var(--input-radius, 6px);cursor:pointer;transition:background-color .15s, border-color .15s;">
                <i class="fas fa-fw fa-cloud-upload-alt fa-2x text-secondary mb-2"></i>
                <span class="text-secondary">Drag &amp; drop files here, or click to browse</span>
                <small class="text-secondary mt-1">Up to 20 files, 500MB total</small>
            </label>
            <input type="file" class="d-none" name="attachment_file[]" multiple id="ticketAttachmentFileInput"
                accept=".jpg, .jpeg, .gif, .png, .webp, .pdf, .txt, .md, .doc, .docx, .odt, .csv, .xls, .xlsx, .ods, .pptx, .odp, .zip, .tar, .gz, .xml, .msg, .json, .wav, .mp3, .ogg, .mov, .mp4, .av1, .ovpn">
            <div id="ticketAttachmentFileList" class="list-group"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="upload_ticket_attachment" class="btn btn-primary"><i class="fa fa-check me-2"></i>Upload</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var maxFiles = 20;
    var maxTotalSize = 500 * 1024 * 1024; // 500MB

    var dropZone = document.getElementById('ticketAttachmentDropZone');
    var fileInput = document.getElementById('ticketAttachmentFileInput');
    var fileList = document.getElementById('ticketAttachmentFileList');
    var form = document.getElementById('ticketAttachmentUploadForm');

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / (1024 * 1024)).toFixed(1) + ' MB'; }
        if (bytes >= 1024) { return (bytes / 1024).toFixed(1) + ' KB'; }
        return bytes + ' B';
    }

    function totalSize(files) {
        var total = 0;
        for (var i = 0; i < files.length; i++) { total += files[i].size; }
        return total;
    }

    function renderFileList() {
        fileList.innerHTML = '';
        var files = fileInput.files;
        for (var i = 0; i < files.length; i++) {
            var item = document.createElement('div');
            item.className = 'list-group-item d-flex justify-content-between align-items-center py-1 px-2';
            var nameSpan = document.createElement('span');
            nameSpan.className = 'text-truncate me-2';
            nameSpan.textContent = files[i].name;
            var sizeSpan = document.createElement('small');
            sizeSpan.className = 'text-secondary flex-shrink-0';
            sizeSpan.textContent = formatSize(files[i].size);
            item.appendChild(nameSpan);
            item.appendChild(sizeSpan);
            fileList.appendChild(item);
        }
    }

    function validateSelection() {
        var files = fileInput.files;
        if (files.length > maxFiles || totalSize(files) > maxTotalSize) {
            alert('You can only upload up to ' + maxFiles + ' files at a time and the total file size must not exceed 500MB.');
            fileInput.value = '';
            renderFileList();
            return false;
        }
        return true;
    }

    fileInput.addEventListener('change', function () {
        validateSelection();
        renderFileList();
    });

    form.addEventListener('submit', function (e) {
        if (!fileInput.files.length) {
            e.preventDefault();
            alert('Please choose at least one file to upload.');
            return;
        }
        if (!validateSelection()) {
            e.preventDefault();
        }
    });

    ['dragenter', 'dragover'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.add('border-primary');
        });
    });

    ['dragleave', 'dragend', 'drop'].forEach(function (evt) {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            dropZone.classList.remove('border-primary');
        });
    });

    dropZone.addEventListener('drop', function (e) {
        var dropped = e.dataTransfer && e.dataTransfer.files;
        if (dropped && dropped.length) {
            fileInput.files = dropped;
            validateSelection();
            renderFileList();
        }
    });
})();
</script>

<?php require_once '../../../includes/modal_footer.php'; ?>
