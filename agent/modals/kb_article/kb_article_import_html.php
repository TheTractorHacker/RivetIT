<?php

require_once '../../../includes/modal_header.php';

$client_id = intval($_GET['client_id'] ?? 0);

$sql_client_select = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL $access_permission_query ORDER BY client_name ASC");
$sql_category_select = mysqli_query($mysqli, "SELECT kb_category_id, kb_category_name FROM kb_categories WHERE kb_category_archived_at IS NULL ORDER BY kb_category_name ASC");

// Identical ini-ceiling machinery to kb_article_import_pdf.php beside it, and for
// the identical reason: the help text has to state the ceiling this host actually
// enforces rather than a hopeful number, and it has to keep stating the truth
// after the limit is raised - so it is read from PHP at render time.
//
// A single-file upload is capped by BOTH upload_max_filesize and post_max_size, so
// the real ceiling is the smaller of the two. A value of 0 means "no limit".
$html_ini_bytes = static function ($value) {
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

/* The PHP ini ceiling is far above the real limit for an HTML import, and
   advertising it invites a failure. src/KB/HtmlImporter.php refuses anything over
   MAX_INPUT_BYTES (2 MiB) before it parses a byte - measured there: 2 MiB of
   markup is 203 ms and 21 MB over baseline, and a DOM lives in libxml's heap where
   php.ini memory_limit cannot reach it. Cap at the same 2 MB the importer enforces
   and take whichever of that and the ini values is smaller, so a host configured
   lower than this still wins. */
$html_importer_max_bytes = 2 * 1024 * 1024;
$html_max_bytes = min($html_ini_bytes(ini_get('upload_max_filesize')), $html_importer_max_bytes);
$html_post_bytes = $html_ini_bytes(ini_get('post_max_size'));

if ($html_post_bytes > 0 && ($html_max_bytes <= 0 || $html_post_bytes < $html_max_bytes)) {
    $html_max_bytes = $html_post_bytes;
}

// Everything else in the multipart body (title, the name and description, three
// selects, the CSRF token, the part boundaries) also counts against post_max_size,
// so hold back a little headroom. A POST that overflows post_max_size is discarded
// by PHP before the handler runs - $_POST and $_FILES both arrive empty, which
// means the CSRF token is gone too and the request cannot be told apart from a
// stray one. Keeping the browser from sending it is the only place that failure
// can still be reported clearly.
$html_guard_bytes = $html_max_bytes > 65536 ? $html_max_bytes - 65536 : $html_max_bytes;

// One decimal, and no thousands separator - "2 MB", "1.5 MB", "500 MB", "1 GB".
if ($html_max_bytes >= 1073741824) {
    $html_max_label = rtrim(rtrim(sprintf('%.1f', $html_max_bytes / 1073741824), '0'), '.') . ' GB';
} elseif ($html_max_bytes >= 1048576) {
    $html_max_label = rtrim(rtrim(sprintf('%.1f', $html_max_bytes / 1048576), '0'), '.') . ' MB';
} elseif ($html_max_bytes > 0) {
    $html_max_label = max(1, (int) round($html_max_bytes / 1024)) . ' KB';
} else {
    $html_max_label = 'the server limit';
}

/* Is the escape hatch usable on this host? It stores the page in
   kb_article_embeds, which the 2.6.79 database update creates. On an install that
   has the code but has not run the update, that table is missing - so the choice
   is disabled here, at render time, with a message that says what to do, rather
   than accepted and then failed after the upload. Same shape as the PDF modal's
   poppler check beside it. Wrapped because PHP 8.1+ throws on a failed query and
   this codebase never calls mysqli_report(). */
$html_embed_ready = false;
try {
    $html_embed_probe = mysqli_query(
        $mysqli,
        "SELECT COUNT(*) AS cnt FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kb_article_embeds'"
    );
    $html_embed_ready = $html_embed_probe && intval(mysqli_fetch_assoc($html_embed_probe)['cnt'] ?? 0) === 1;
} catch (\Throwable $html_embed_probe_error) {
    $html_embed_ready = false;
}

// 512 KB, the cap src/KB/HtmlImporter.php enforces on an embedded page and the one
// agent/includes/kb_embed_serve.php:150 restates on the way back out.
$html_embed_max_label = '512 KB';

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-code me-2"></i>Import HTML Page</h5>
    <button type="button" class="close text-white" data-bs-dismiss="modal">
        <span>&times;</span>
    </button>
</div>
<form action="post.php" method="post" enctype="multipart/form-data" autocomplete="off" id="kb_import_html_form">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo $html_guard_bytes; ?>">

    <div class="modal-body">

        <p class="text-secondary">Importing reads the page and turns it into ordinary article content you can keep editing here. Unlike the Word and PDF importers, this one recognises structure: collapsible sections, tab strips, task lists and code blocks become <strong>interactive blocks</strong> rather than flat text.</p>

        <div class="form-group">
            <label>HTML File <strong class="text-danger">*</strong></label>
            <input type="file" class="form-control-file" name="html_file" id="kb_import_html_file" accept="text/html,.html,.htm" required>
            <small class="form-text text-muted d-block">One .html or .htm file, up to <?php echo nullable_htmlentities($html_max_label); ?>. Only the page itself is uploaded &mdash; pictures saved beside it in a folder cannot come across, but pictures embedded in the page itself can.</small>
            <div class="text-danger small mt-2 d-none" id="kb_import_html_size_error"></div>
        </div>

        <div class="form-group">
            <label>Title</label>
            <div class="input-group">
                <div class="input-group-prepend">
                    <span class="input-group-text"><i class="fa fa-fw fa-heading"></i></span>
                </div>
                <input type="text" class="form-control" name="title" maxlength="255" placeholder="Leave blank to use the page's own title">
            </div>
        </div>

        <div class="form-group">
            <label class="d-block">What should happen to it?</label>

            <div class="form-check mb-2">
                <input type="radio" class="form-check-input" id="kb_import_html_mode_blocks" name="import_mode" value="blocks" checked>
                <label class="form-check-label" for="kb_import_html_mode_blocks">
                    <strong>Convert it into article content</strong> &mdash; recommended.
                    The page becomes editable article text, its structure becomes interactive blocks, and every word of it is searchable.
                </label>
            </div>

            <div id="kb_import_html_blocks_options" class="ms-4 mb-3">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="kb_import_html_leave_lists" name="leave_lists" value="1">
                    <label class="form-check-label" for="kb_import_html_leave_lists">Leave lists, headings and collapsible sections exactly as they are</label>
                </div>
                <small class="form-text text-muted">Tick this if the page comes back with the wrong bits turned into checklists or steps. Code blocks still get a Copy button either way.</small>
            </div>

            <div class="form-check">
                <input type="radio" class="form-check-input" id="kb_import_html_mode_embed" name="import_mode" value="embed"<?php if (!$html_embed_ready) { echo ' disabled'; } ?>>
                <label class="form-check-label" for="kb_import_html_mode_embed">
                    <strong>Keep the whole page, running, in a sandboxed frame</strong> &mdash; for a page that <em>is</em> a tool (a calculator, a form that works something out) and would be destroyed by converting it.
                    <?php if (!$html_embed_ready) { ?>
                        <?php /* Maintenance > Update, the "Update Database" button at admin/update.php:55 - the
                                 2.6.79 update is what creates kb_article_embeds. Named on screen because an agent
                                 who cannot find it cannot use this half of the importer. */ ?>
                        <span class="text-danger d-block">Not available until the database update that creates the embed storage has been run &mdash; an administrator does that from <strong>Maintenance &gt; Update</strong>, with the <em>Update Database</em> button.</span>
                    <?php } ?>
                </label>
            </div>

            <div id="kb_import_html_embed_options" class="ms-4 mt-2 d-none">
                <div class="row">
                    <div class="col-md-5">
                        <div class="form-group">
                            <label>Name of the tool <strong class="text-danger">*</strong></label>
                            <input type="text" class="form-control" name="embed_name" maxlength="200" placeholder="VLAN subnet calculator">
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="form-group">
                            <label>One line about it <strong class="text-danger">*</strong></label>
                            <input type="text" class="form-control" name="embed_description" maxlength="255" placeholder="Works out how many usable addresses a prefix gives you">
                        </div>
                    </div>
                </div>
                <div class="alert alert-warning" role="alert">
                    <i class="fas fa-fw fa-triangle-exclamation me-2"></i><strong>An embedded page is not searchable.</strong>
                    Its contents never reach the knowledge base search index, so the name and the line above are the only words anyone can find it by &mdash; write them for the person who will go looking.
                    The page runs with no access to this application, no cookies, no storage and no network, so anything it loads from another website, and anything it tries to save, will not work. Maximum <?php echo $html_embed_max_label; ?>.
                </div>
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

        <div class="alert alert-secondary mb-0" role="alert">
            <strong>What comes across, and what does not.</strong>
            <div class="row mt-2">
                <div class="col-md-6">
                    <div class="text-success"><i class="fas fa-fw fa-check me-1"></i>Becomes an interactive block</div>
                    <ul class="mb-2 ps-4">
                        <li>Collapsible <code>&lt;details&gt;</code> sections &rarr; an accordion</li>
                        <li>Tab strips (Bootstrap or ARIA) &rarr; tabs</li>
                        <li>Tick-box task lists &rarr; a checklist that remembers each reader's ticks</li>
                        <li>A short list under a "checklist / steps / procedure / runbook / how to" heading &rarr; a checklist, or guided steps</li>
                        <li>"Step 1&hellip; Step 2&hellip;" headings, and runs of <code>&lt;section&gt;</code> &rarr; guided steps</li>
                        <li>Code blocks &rarr; a block with a Copy button</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <div class="text-danger"><i class="fas fa-fw fa-xmark me-1"></i>Removed, and you are told how many</div>
                    <ul class="mb-2 ps-4">
                        <li>Scripts, stylesheets and every <code>style=</code> &mdash; they cannot run in an article</li>
                        <li>Forms, buttons and input fields</li>
                        <li>Frames, embedded objects, SVG, canvas, video and audio</li>
                        <li>Pictures kept in a folder beside the page (embedded ones are imported)</li>
                        <li>Layout &mdash; columns and panels are flattened into reading order</li>
                    </ul>
                    <div class="text-muted small"><strong>Decision trees are never guessed at.</strong> No page structure reliably means "branching troubleshooter", so a wrong guess would be worse than none &mdash; build those in the editor.</div>
                </div>
            </div>
            Read the article through after importing; the import tells you exactly what it changed.
        </div>

    </div>
    <div class="modal-footer">
        <button type="submit" name="import_kb_article_html" class="btn btn-primary text-bold" id="kb_import_html_submit"><i class="fa fa-check me-2"></i>Import HTML</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
(function () {
    var form = document.getElementById('kb_import_html_form');
    var fileInput = document.getElementById('kb_import_html_file');
    var sizeError = document.getElementById('kb_import_html_size_error');
    var submitButton = document.getElementById('kb_import_html_submit');
    var modeEmbed = document.getElementById('kb_import_html_mode_embed');
    var modeBlocks = document.getElementById('kb_import_html_mode_blocks');
    var embedOptions = document.getElementById('kb_import_html_embed_options');
    var blocksOptions = document.getElementById('kb_import_html_blocks_options');

    if (!form || !fileInput || !sizeError || !submitButton) {
        return;
    }

    // PHP silently discards a POST larger than post_max_size: the handler sees an empty
    // $_POST (no CSRF token) and an empty $_FILES, so it has nothing to report on. Stop
    // the oversized upload here, where the file's real size is still known.
    var maxBytes = <?php echo (int) $html_guard_bytes; ?>;
    var maxLabel = <?php echo json_encode($html_max_label); ?>;
    // The embed path has a second, much lower ceiling - the stored page has to
    // fit the 512 KB cap the serve endpoint restates. Same reasoning: say so
    // before the upload, not after it.
    var embedMaxBytes = 524288;

    function chosenMode() {
        return (modeEmbed && modeEmbed.checked) ? 'embed' : 'blocks';
    }

    function limitForMode() {
        var limit = maxBytes;
        if (chosenMode() === 'embed' && (limit <= 0 || embedMaxBytes < limit)) {
            limit = embedMaxBytes;
        }
        return limit;
    }

    function selectedFileIsTooBig() {
        var file = fileInput.files && fileInput.files[0];
        var limit = limitForMode();
        return !!(file && limit > 0 && file.size > limit);
    }

    function checkSelectedFile() {
        if (selectedFileIsTooBig()) {
            var kilobytes = (fileInput.files[0].size / 1024).toFixed(0);
            sizeError.textContent = chosenMode() === 'embed'
                ? 'That page is ' + kilobytes + ' KB. A page kept whole in a frame may be at most 512 KB - import it as article content instead, or trim the page.'
                : 'That page is ' + (fileInput.files[0].size / 1048576).toFixed(1) + ' MB. This server accepts uploads up to ' + maxLabel + ', and anything larger is dropped before it arrives - please choose a smaller file.';
            sizeError.classList.remove('d-none');
            submitButton.disabled = true;
        } else {
            sizeError.textContent = '';
            sizeError.classList.add('d-none');
            submitButton.disabled = false;
        }
    }

    function syncMode() {
        var embed = chosenMode() === 'embed';
        if (embedOptions) { embedOptions.classList.toggle('d-none', !embed); }
        if (blocksOptions) { blocksOptions.classList.toggle('d-none', embed); }
        checkSelectedFile();
    }

    fileInput.addEventListener('change', checkSelectedFile);
    if (modeEmbed) { modeEmbed.addEventListener('change', syncMode); }
    if (modeBlocks) { modeBlocks.addEventListener('change', syncMode); }

    form.addEventListener('submit', function (e) {
        if (selectedFileIsTooBig()) {
            e.preventDefault();
            checkSelectedFile();
        }
    });

    syncMode();
})();
</script>

<?php

require_once '../../../includes/modal_footer.php';
