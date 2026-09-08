<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

$sql_client_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$sql_category_select = mysqli_query($mysqli, "SELECT kb_category_id, kb_category_name FROM kb_categories WHERE kb_category_archived_at IS NULL ORDER BY kb_category_name ASC");

// The help text has to state the ceiling this host actually enforces rather than a
// hopeful number, and it has to keep stating the truth after the limit is raised -
// so it is read from PHP at render time instead of being hard-coded.
//
// A single-file upload is capped by BOTH upload_max_filesize and post_max_size, so
// the real ceiling is the smaller of the two. A value of 0 means "no limit".
$docx_ini_bytes = static function ($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $number = (float) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            $number *= 1024;
            // no break - g is k * 1024 * 1024
        case 'm':
            $number *= 1024;
            // no break - m is k * 1024
        case 'k':
            $number *= 1024;
    }
    return (int) $number;
};

/* The PHP ini ceiling is 500 MB on this host, but that is not the real limit for a
   Word import and advertising it invites a failure. src/KB/DocxConverter.php refuses
   an archive whose contents exceed 48 MiB uncompressed, or whose word/document.xml
   alone exceeds 8 MiB - so a .docx anywhere near the ini ceiling cannot convert. Cap
   at 32 MB, which is comfortably larger than any real Word document and still well
   inside the converter's budgets, and take whichever of the two is smaller so a host
   configured lower than this still wins. */
$docx_converter_max_bytes = 32 * 1024 * 1024;
$docx_max_bytes = min($docx_ini_bytes(ini_get('upload_max_filesize')), $docx_converter_max_bytes);
$docx_post_bytes = $docx_ini_bytes(ini_get('post_max_size'));

if ($docx_post_bytes > 0 && ($docx_max_bytes <= 0 || $docx_post_bytes < $docx_max_bytes)) {
    $docx_max_bytes = $docx_post_bytes;
}

// Everything else in the multipart body (title, three selects, the CSRF token, the
// part boundaries) also counts against post_max_size, so hold back a little headroom.
// A POST that overflows post_max_size is discarded by PHP before the handler runs -
// $_POST and $_FILES both arrive empty, which means the CSRF token is gone too and the
// request cannot be told apart from a stray one. Keeping the browser from sending it is
// the only place that failure can still be reported clearly.
$docx_guard_bytes = $docx_max_bytes > 65536 ? $docx_max_bytes - 65536 : $docx_max_bytes;

// One decimal, and no thousands separator - "2 MB", "1.5 MB", "500 MB", "1 GB".
if ($docx_max_bytes >= 1073741824) {
    $docx_max_label = rtrim(rtrim(sprintf('%.1f', $docx_max_bytes / 1073741824), '0'), '.') . ' GB';
} elseif ($docx_max_bytes >= 1048576) {
    $docx_max_label = rtrim(rtrim(sprintf('%.1f', $docx_max_bytes / 1048576), '0'), '.') . ' MB';
} elseif ($docx_max_bytes > 0) {
    $docx_max_label = max(1, (int) round($docx_max_bytes / 1024)) . ' KB';
} else {
    $docx_max_label = 'the server limit';
}

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-file-word me-2"></i>Import Word Document</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off" id="kb_import_docx_form">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $docx_guard_bytes; ?>">

    <div class="modal-body">

        <p class="text-secondary">Importing reads the document and turns its text into ordinary article content you can keep editing here, rather than attaching the file to an article.</p>

        <div class="form-group">
            <label>Word Document <strong class="text-danger">*</strong></label>
            <input type="file" class="form-control-file" name="docx_file" id="kb_import_docx_file" accept=".docx" required>
            <small class="form-text text-muted d-block">One .docx file (Word 2007 and newer), up to <?php echo nullable_htmlentities($docx_max_label); ?>. Older .doc files need to be saved as .docx in Word first.</small>
            <div class="text-danger small mt-2 d-none" id="kb_import_docx_size_error"></div>
        </div>

        <div class="form-group">
            <label>Title</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" maxlength="255" placeholder="Leave blank to use the document's file name">
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label>Department</label>
                    <select class="form-control select2" name="client_id">
                        <option value="0" <?php if ($client_id == 0) { echo "selected"; } ?>>Central (Company-wide)</option>
                        <?php
                        while ($row = mysqli_fetch_assoc($sql_client_select)) {
                            $select_client_id = intval($row['client_id']);
                            $select_client_name = nullable_htmlentities($row['client_name']);
                        ?>
                            <option value="<?php echo $select_client_id; ?>" <?php if ($client_id == $select_client_id) { echo "selected"; } ?>><?php echo $select_client_name; ?></option>
                        <?php } ?>
                    </select>
                    <small class="form-text text-muted">Central articles appear in every department's knowledge base. Department-specific articles are only visible to that department.</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label>Category</label>
                    <select class="form-control select2" name="category_id">
                        <option value="0" selected>Uncategorized</option>
                        <?php while ($row = mysqli_fetch_assoc($sql_category_select)) { ?>
                            <option value="<?= intval($row['kb_category_id']) ?>"><?= nullable_htmlentities($row['kb_category_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label>Visible to Department Portal</label>
                    <select class="form-control select2" name="client_visible">
                        <option value="1" selected>Yes</option>
                        <option value="0">No</option>
                    </select>
                    <small class="form-text text-muted">Internal-only articles are still visible to agents, but hidden from departments.</small>
                </div>
            </div>
        </div>

        <div class="alert alert-warning mb-0" role="alert">
            <i class="fas fa-fw fa-exclamation-triangle me-2"></i>Headings, lists, tables, links and images come across. Any formatting beyond those - fonts, colours, sizes, columns, text boxes, footnotes, page headers and footers - is not carried over, so check the article after importing.
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="import_kb_article_docx" class="btn btn-primary text-bold" id="kb_import_docx_submit"><i class="fa fa-check me-2"></i>Import Document</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var form = document.getElementById('kb_import_docx_form');
    var fileInput = document.getElementById('kb_import_docx_file');
    var sizeError = document.getElementById('kb_import_docx_size_error');
    var submitButton = document.getElementById('kb_import_docx_submit');

    if (!form || !fileInput || !sizeError || !submitButton) {
        return;
    }

    // PHP silently discards a POST larger than post_max_size: the handler sees an empty
    // $_POST (no CSRF token) and an empty $_FILES, so it has nothing to report on. Stop
    // the oversized upload here, where the file's real size is still known.
    var maxBytes = <?php echo (int) $docx_guard_bytes; ?>;
    var maxLabel = <?php echo json_encode($docx_max_label); ?>;

    function selectedFileIsTooBig() {
        var file = fileInput.files && fileInput.files[0];
        return !!(file && maxBytes > 0 && file.size > maxBytes);
    }

    function checkSelectedFile() {
        if (selectedFileIsTooBig()) {
            var megabytes = (fileInput.files[0].size / 1048576).toFixed(1);
            sizeError.textContent = 'That document is ' + megabytes + ' MB. This server accepts uploads up to ' + maxLabel + ', and anything larger is dropped before it arrives - please choose a smaller file.';
            sizeError.classList.remove('d-none');
            submitButton.disabled = true;
        } else {
            sizeError.textContent = '';
            sizeError.classList.add('d-none');
            submitButton.disabled = false;
        }
    }

    fileInput.addEventListener('change', checkSelectedFile);

    form.addEventListener('submit', function (e) {
        if (selectedFileIsTooBig()) {
            e.preventDefault();
            checkSelectedFile();
        }
    });
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
