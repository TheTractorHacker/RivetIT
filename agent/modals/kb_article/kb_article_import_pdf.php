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
$pdf_ini_bytes = static function ($value) {
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
   PDF import and advertising it invites a failure. src/KB/PdfConverter.php refuses
   anything over MAX_UPLOAD_BYTES (32 MiB) before it spawns a single process, so a
   file anywhere near the ini ceiling cannot convert. Cap at the same 32 MB the
   converter enforces, and take whichever of that and the ini values is smaller so a
   host configured lower than this still wins. Same shape as the Word modal beside
   it, deliberately - the two limits are the same limit. */
$pdf_converter_max_bytes = 32 * 1024 * 1024;
$pdf_max_bytes = min($pdf_ini_bytes(ini_get('upload_max_filesize')), $pdf_converter_max_bytes);
$pdf_post_bytes = $pdf_ini_bytes(ini_get('post_max_size'));

if ($pdf_post_bytes > 0 && ($pdf_max_bytes <= 0 || $pdf_post_bytes < $pdf_max_bytes)) {
    $pdf_max_bytes = $pdf_post_bytes;
}

// Everything else in the multipart body (title, three selects, the CSRF token, the
// part boundaries) also counts against post_max_size, so hold back a little headroom.
// A POST that overflows post_max_size is discarded by PHP before the handler runs -
// $_POST and $_FILES both arrive empty, which means the CSRF token is gone too and the
// request cannot be told apart from a stray one. Keeping the browser from sending it is
// the only place that failure can still be reported clearly.
$pdf_guard_bytes = $pdf_max_bytes > 65536 ? $pdf_max_bytes - 65536 : $pdf_max_bytes;

// One decimal, and no thousands separator - "2 MB", "1.5 MB", "500 MB", "1 GB".
if ($pdf_max_bytes >= 1073741824) {
    $pdf_max_label = rtrim(rtrim(sprintf('%.1f', $pdf_max_bytes / 1073741824), '0'), '.') . ' GB';
} elseif ($pdf_max_bytes >= 1048576) {
    $pdf_max_label = rtrim(rtrim(sprintf('%.1f', $pdf_max_bytes / 1048576), '0'), '.') . ' MB';
} elseif ($pdf_max_bytes > 0) {
    $pdf_max_label = max(1, (int) round($pdf_max_bytes / 1024)) . ' KB';
} else {
    $pdf_max_label = 'the server limit';
}

/* Is the converter actually usable on this host? src/KB/PdfConverter.php shells out
   to poppler, and poppler is an OS package this application does not install. Checked
   here, at render time, so a host without it says so on the form instead of accepting
   a 20 MB upload and then failing. The same three paths the converter uses, checked
   the same way it checks them. */
$pdf_tools_ready = is_executable('/usr/bin/pdfinfo')
    && is_executable('/usr/bin/pdftotext')
    && is_executable('/usr/bin/pdftohtml');

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-file-pdf me-2"></i>Import PDF</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off" id="kb_import_pdf_form">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $pdf_guard_bytes; ?>">

    <div class="modal-body">

        <?php if (!$pdf_tools_ready) { ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-fw fa-circle-exclamation me-2"></i><strong>PDF import is not available on this server.</strong>
                The PDF tools it needs are not installed. An administrator can enable it by installing the
                <code>poppler-utils</code> package. Until then, attach the PDF to an article instead - that works today.
            </div>
        <?php } ?>

        <p class="text-secondary">Importing reads the PDF and turns its text and pictures into ordinary article content you can keep editing here, rather than attaching the file to an article.</p>

        <div class="form-group">
            <label>PDF File <strong class="text-danger">*</strong></label>
            <input type="file" class="form-control-file" name="pdf_file" id="kb_import_pdf_file" accept="application/pdf,.pdf" required>
            <small class="form-text text-muted d-block">One PDF, up to <?php echo nullable_htmlentities($pdf_max_label); ?> and 200 pages (anything longer is imported truncated). A scanned PDF cannot be imported - there is no OCR on this server - so it needs attaching to an article instead.</small>
            <div class="text-danger small mt-2 d-none" id="kb_import_pdf_size_error"></div>
        </div>

        <div class="form-group">
            <label>Title</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" maxlength="255" placeholder="Leave blank to use the PDF's file name">
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
            <i class="fas fa-fw fa-exclamation-triangle me-2"></i><strong>A PDF has no structure in it</strong> - it is positioned text on a page - so headings, paragraphs, lists and bold are worked out from font size, weight and layout. Text, bold, italic, headings, numbered and bulleted lists and embedded pictures come across. <strong>Tables, columns and page headers and footers do not</strong>, and become ordinary paragraphs. Always read the article through after importing.
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="import_kb_article_pdf" class="btn btn-primary text-bold" id="kb_import_pdf_submit"<?php if (!$pdf_tools_ready) { echo ' disabled'; } ?>><i class="fa fa-check me-2"></i>Import PDF</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var form = document.getElementById('kb_import_pdf_form');
    var fileInput = document.getElementById('kb_import_pdf_file');
    var sizeError = document.getElementById('kb_import_pdf_size_error');
    var submitButton = document.getElementById('kb_import_pdf_submit');

    if (!form || !fileInput || !sizeError || !submitButton) {
        return;
    }

    // PHP silently discards a POST larger than post_max_size: the handler sees an empty
    // $_POST (no CSRF token) and an empty $_FILES, so it has nothing to report on. Stop
    // the oversized upload here, where the file's real size is still known.
    var maxBytes = <?php echo (int) $pdf_guard_bytes; ?>;
    var maxLabel = <?php echo json_encode($pdf_max_label); ?>;

    function selectedFileIsTooBig() {
        var file = fileInput.files && fileInput.files[0];
        return !!(file && maxBytes > 0 && file.size > maxBytes);
    }

    function checkSelectedFile() {
        if (selectedFileIsTooBig()) {
            var megabytes = (fileInput.files[0].size / 1048576).toFixed(1);
            sizeError.textContent = 'That PDF is ' + megabytes + ' MB. This server accepts uploads up to ' + maxLabel + ', and anything larger is dropped before it arrives - please choose a smaller file.';
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
