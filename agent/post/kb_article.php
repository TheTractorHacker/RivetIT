<?php

// Knowledge Base Articles

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_kb_article'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Creating an article is a write - module_kb level 1 is "Viewing Only" in the role
    // editor, so a read-only role must not be able to reach this handler
    enforceUserPermission('module_kb', 2);

    $title = sanitizeInput($_POST['title']);
    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    /* THE SERVER BACKSTOP for interactive blocks. \ITFlow\KB\InteractiveBlocks::normalise()
       mints a missing block or part key, re-mints the duplicates a copy-paste
       leaves behind, restores a step title the editor deleted, drops the
       <p>&nbsp;</p> a stray Enter leaves inside a step, and STRIPS
       contenteditable - which is the one thing the stock purifier keeps
       (measured: contenteditable="false" survives it untouched), so this is the
       only place that can guarantee the editor's own marker never reaches
       storage.

       It early-returns on content with no data-ikb in it, so an ordinary
       article save is byte-identical to before this call existed. Every path in
       this file that writes kb_article_content runs it; a path that does not
       would silently store a block whose keys collide with another's, and a
       reader's ticks would land on the wrong steps. */
    $ikb_add = \ITFlow\KB\InteractiveBlocks::normalise((string) $_POST['content']);

    $content = mysqli_real_escape_string($mysqli, $ikb_add['html']);
    $content_raw = sanitizeInput($_POST['title'] . " " . str_replace("<", " <", $ikb_add['html']));

    mysqli_query(
        $mysqli,
        "INSERT INTO kb_articles SET
            kb_article_title = '$title',
            kb_article_content = '$content',
            kb_article_content_raw = '$content_raw',
            kb_article_client_id = $kb_article_client_id,
            kb_article_category_id = $kb_article_category_id,
            kb_article_client_visible = $client_visible,
            kb_article_created_by = $session_user_id,
            kb_article_updated_by = $session_user_id"
    );

    $kb_article_id = mysqli_insert_id($mysqli);

    logAction("Knowledge Base", "Create", "$session_name created KB article: $title", $kb_article_client_id, $kb_article_id);

    $add_flash = "Knowledge Base article <strong>$title</strong> created";
    if (!empty($ikb_add['warnings'])) {
        $add_flash .= " &mdash; " . nullable_htmlentities(implode(' ', $ikb_add['warnings']));
    }

    flash_alert($add_flash, empty($ikb_add['warnings']) ? 'success' : 'warning');

    redirect();

}

if (isset($_POST['import_kb_article_docx'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Importing a Word document CREATES an article, so this is a write, exactly
    // like add_kb_article above - module_kb level 1 is "Viewing Only".
    enforceUserPermission('module_kb', 2);

    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $docx_file = $_FILES['docx_file'] ?? ($_FILES['import_file'] ?? []);

    if (empty($docx_file) || ($docx_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_alert("No Word document was uploaded", 'error');
        redirect();
    }

    // The upload must be a genuine PHP upload, and it must pass the codebase's
    // canonical validator with a one-entry allow-list. checkFileUpload() is used
    // purely as that validator here - the source .docx is deliberately never
    // moved into /uploads/, only read from its tmp_name, so an import leaves
    // nothing under the unauthenticated /uploads/ tree except real images.
    if (!is_uploaded_file($docx_file['tmp_name'])) {
        flash_alert("That upload could not be read", 'error');
        redirect();
    }

    $docx_ref_name = checkFileUpload($docx_file, ['docx']);

    if (!is_string($docx_ref_name) || !preg_match('/^[a-zA-Z0-9_-]+\.docx$/', $docx_ref_name)) {
        flash_alert("Only .docx files can be imported", 'error');
        redirect();
    }

    // Convert BEFORE anything is created. DocxConverter never writes to disk -
    // it hands back the HTML and the image bytes in memory - so a document that
    // is malformed, oversized or hostile leaves no article and no files behind.
    try {
        $docx_result = \ITFlow\KB\DocxConverter::convert($docx_file['tmp_name']);
    } catch (\ITFlow\KB\DocxConversionException $e) {
        flash_alert("Import failed: " . nullable_htmlentities($e->getMessage()), 'error');
        redirect();
    } catch (\Throwable $e) {
        // Never leak an internal message; the detail goes to the error log.
        error_log('KB DOCX import failed: ' . $e->getMessage());
        flash_alert("Import failed: that Word document could not be read", 'error');
        redirect();
    }

    // Title: an explicitly posted one wins, otherwise it comes from the filename.
    $title = trim($_POST['title'] ?? '');

    if ($title === '') {
        // basename() first so a crafted "name" can never contribute a path.
        $title = basename(str_replace('\\', '/', $docx_file['name']));
        $title = preg_replace('/\.docx$/i', '', $title);
        $title = str_replace(['_', '+'], ' ', $title);
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // "vpn onboarding runbook" -> "Vpn Onboarding Runbook", but a name that
        // already carries capitals (e.g. "VPN Onboarding") is left alone.
        if ($title !== '' && $title === mb_strtolower($title)) {
            $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        }
    }

    if ($title === '') {
        $title = 'Imported Document';
    }

    // cleanInput() strips tags and trims but does NOT escape, so $title stays
    // usable for the flash and the audit log; sanitizeInput() below is what
    // escapes it for SQL, exactly as add_kb_article does.
    $title = cleanInput(mb_substr($title, 0, 255));
    $title_escaped = sanitizeInput($title);

    // Create the row first so the images have an article id to live under, then
    // fill the content in once the src attributes point at their real URLs. The
    // content is written exactly once - the INSERT deliberately leaves it empty
    // rather than storing HTML that still carries substitution tokens.
    mysqli_query(
        $mysqli,
        "INSERT INTO kb_articles SET
            kb_article_title = '$title_escaped',
            kb_article_content = '',
            kb_article_content_raw = '',
            kb_article_client_id = $kb_article_client_id,
            kb_article_category_id = $kb_article_category_id,
            kb_article_client_visible = $client_visible,
            kb_article_created_by = $session_user_id,
            kb_article_updated_by = $session_user_id"
    );

    $kb_article_id = intval(mysqli_insert_id($mysqli));

    if (!$kb_article_id) {
        flash_alert("Import failed: the article could not be created", 'error');
        redirect();
    }

    // Write the extracted images. Every filename here is generated by us from
    // random bytes plus the extension the converter derived from the image's
    // own sniffed type - nothing in the path comes from the uploaded file.
    $docx_written_files = [];
    $docx_upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/";

    if (!empty($docx_result['media'])) {
        mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/");
        mkdirMissing($docx_upload_dir);
    }

    $docx_html = $docx_result['html'];

    foreach (array_keys($docx_result['media']) as $docx_image_key) {

        $docx_image = $docx_result['media'][$docx_image_key];

        $docx_image_name = bin2hex(random_bytes(16)) . '.' . $docx_image['extension'];

        if (is_dir($docx_upload_dir) && file_put_contents($docx_upload_dir . $docx_image_name, $docx_image['bytes']) !== false) {
            $docx_written_files[] = $docx_upload_dir . $docx_image_name;

            /* The bytes stay exactly where they were - /uploads/kb/<article_id>/<name>
               on disk. Only the URL that goes into kb_article_content changes, from
               that raw filesystem path to the canonical serve endpoint.
               /uploads/ is handed out by nginx with no authentication whatsoever,
               and the filename is not a secret substitute for one: these names are
               bin2hex(random_bytes(16)) here, but checkFileUpload() names its files
               md5(contents) plus two random characters, so the pattern of "the path
               is the credential" is not defensible for KB media generally.
               agent/kb_media.php re-derives module_kb and the article's department
               scope from the database on every single fetch instead.

               SIGNATURE-FREE AND ROOT-RELATIVE ON PURPOSE. This exact string has to
               survive an edit: kb_article_edit.php:13 htmlentities it into the
               TinyMCE textarea (line 91), convert_urls:false (js/app.js:316) makes
               TinyMCE hand back what it was given rather than rewriting it, and
               edit_kb_article below stores that back verbatim. A signed URL would
               expire while the editor sat open and then be baked permanently into
               the content on save; an absolute URL would pin the article to
               whichever hostname happened to import it. The signature is added at
               RESPONSE time by api/v1/kb.php, for the API/Android clients that have
               no cookie to send.

               &amp; RATHER THAN &, because this token sits inside an <img src="...">
               attribute (DocxConverter.php:1182 emits <img src="TOKEN" alt="...">),
               where an ampersand is an entity reference. Measured on the bundled
               HTMLPurifier 4.15.0 with the config from agent/kb_article.php: both
               forms purify to the &amp; form, so storing the entity means the stored
               bytes and the rendered bytes already agree and a purify/save round
               trip changes nothing. DocxConverter also emits a real alt, so the
               Attr.DefaultImageAlt='' guard in the four purifier configs never has
               to fire for a DOCX image. */
            $docx_html = str_replace(
                $docx_image['token'],
                "/agent/kb_media.php?a=$kb_article_id&amp;f=$docx_image_name",
                $docx_html
            );
        } else {
            // Could not store it - drop the <img> rather than leave a dead link.
            $docx_html = preg_replace('/<img src="' . preg_quote($docx_image['token'], '/') . '"[^>]*>/', '', $docx_html);
        }

        // Release the image bytes as we go - a picture-heavy document would
        // otherwise hold every image in memory at once alongside the HTML.
        unset($docx_image, $docx_result['media'][$docx_image_key]);
    }

    /* The same interactive-block backstop every other write path in this file
       runs. DocxConverter emits no data-ikb today, so normalise() early-returns
       and this is exactly a no-op - it is here so that "every path that writes
       kb_article_content normalises first" is a property of the file rather
       than of four separate memories. */
    $docx_html = \ITFlow\KB\InteractiveBlocks::normalise($docx_html)['html'];

    // Same shape as add_kb_article: the HTML is escaped for SQL as-is, and the
    // raw copy is the tag-stripped text the FULLTEXT index searches on.
    // sanitizeInput() already escapes, so neither is escaped a second time.
    $content = mysqli_real_escape_string($mysqli, $docx_html);
    $content_raw = sanitizeInput($title . " " . str_replace("<", " <", $docx_html));

    $docx_stored = mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_content = '$content',
            kb_article_content_raw = '$content_raw'
         WHERE kb_article_id = $kb_article_id"
    );

    if (!$docx_stored) {
        // Roll the whole import back - no orphan article, no orphan files.
        foreach ($docx_written_files as $docx_written_file) {
            if (is_file($docx_written_file)) {
                unlink($docx_written_file);
            }
        }
        if (is_dir($docx_upload_dir)) {
            @rmdir($docx_upload_dir);
        }
        mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $kb_article_id");

        flash_alert("Import failed: the converted article could not be saved", 'error');
        redirect();
    }

    logAction("Knowledge Base", "Create", "$session_name imported KB article from a Word document: $title", $kb_article_client_id, $kb_article_id);

    $docx_flash = "Knowledge Base article <strong>" . nullable_htmlentities($title) . "</strong> imported from Word";
    if (!empty($docx_result['warnings'])) {
        $docx_flash .= " &mdash; " . nullable_htmlentities(implode(' ', $docx_result['warnings']));
    }

    flash_alert($docx_flash, empty($docx_result['warnings']) ? 'success' : 'warning');

    redirect();

}

if (isset($_POST['import_kb_article_pdf'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Importing a Word document CREATES an article, so this is a write, exactly
    // like add_kb_article above - module_kb level 1 is "Viewing Only".
    enforceUserPermission('module_kb', 2);

    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $pdf_file = $_FILES['pdf_file'] ?? ($_FILES['import_file'] ?? []);

    if (empty($pdf_file) || ($pdf_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_alert("No PDF was uploaded", 'error');
        redirect();
    }

    // The upload must be a genuine PHP upload, and it must pass the codebase's
    // canonical validator with a one-entry allow-list. checkFileUpload() is used
    // purely as that validator here - the source .pdf is deliberately never
    // moved into /uploads/, only read from its tmp_name, so an import leaves
    // nothing under the unauthenticated /uploads/ tree except real images.
    if (!is_uploaded_file($pdf_file['tmp_name'])) {
        flash_alert("That upload could not be read", 'error');
        redirect();
    }

    $pdf_ref_name = checkFileUpload($pdf_file, ['pdf']);

    if (!is_string($pdf_ref_name) || !preg_match('/^[a-zA-Z0-9_-]+\.pdf$/', $pdf_ref_name)) {
        flash_alert("Only PDF files can be imported", 'error');
        redirect();
    }

    // Convert BEFORE anything is created. PdfConverter never writes to disk -
    // it hands back the HTML and the image bytes in memory - so a document that
    // is malformed, oversized or hostile leaves no article and no files behind.
    try {
        $pdf_result = \ITFlow\KB\PdfConverter::convert($pdf_file['tmp_name']);
    } catch (\ITFlow\KB\PdfConversionException $e) {
        flash_alert("Import failed: " . nullable_htmlentities($e->getMessage()), 'error');
        redirect();
    } catch (\Throwable $e) {
        // Never leak an internal message; the detail goes to the error log.
        error_log('KB PDF import failed: ' . $e->getMessage());
        flash_alert("Import failed: that PDF could not be read", 'error');
        redirect();
    }

    // Title: an explicitly posted one wins, otherwise it comes from the filename.
    $title = trim($_POST['title'] ?? '');

    if ($title === '') {
        // basename() first so a crafted "name" can never contribute a path.
        $title = basename(str_replace('\\', '/', $pdf_file['name']));
        $title = preg_replace('/\.pdf$/i', '', $title);
        $title = str_replace(['_', '+'], ' ', $title);
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // "vpn onboarding runbook" -> "Vpn Onboarding Runbook", but a name that
        // already carries capitals (e.g. "VPN Onboarding") is left alone.
        if ($title !== '' && $title === mb_strtolower($title)) {
            $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        }
    }

    if ($title === '') {
        $title = 'Imported Document';
    }

    // cleanInput() strips tags and trims but does NOT escape, so $title stays
    // usable for the flash and the audit log; sanitizeInput() below is what
    // escapes it for SQL, exactly as add_kb_article does.
    $title = cleanInput(mb_substr($title, 0, 255));
    $title_escaped = sanitizeInput($title);

    // Create the row first so the images have an article id to live under, then
    // fill the content in once the src attributes point at their real URLs. The
    // content is written exactly once - the INSERT deliberately leaves it empty
    // rather than storing HTML that still carries substitution tokens.
    mysqli_query(
        $mysqli,
        "INSERT INTO kb_articles SET
            kb_article_title = '$title_escaped',
            kb_article_content = '',
            kb_article_content_raw = '',
            kb_article_client_id = $kb_article_client_id,
            kb_article_category_id = $kb_article_category_id,
            kb_article_client_visible = $client_visible,
            kb_article_created_by = $session_user_id,
            kb_article_updated_by = $session_user_id"
    );

    $kb_article_id = intval(mysqli_insert_id($mysqli));

    if (!$kb_article_id) {
        flash_alert("Import failed: the article could not be created", 'error');
        redirect();
    }

    // Write the extracted images. Every filename here is generated by us from
    // random bytes plus the extension the converter derived from the image's
    // own sniffed type - nothing in the path comes from the uploaded file.
    $pdf_written_files = [];
    $pdf_upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/";

    if (!empty($pdf_result['media'])) {
        mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/");
        mkdirMissing($pdf_upload_dir);
    }

    $pdf_html = $pdf_result['html'];

    foreach (array_keys($pdf_result['media']) as $pdf_image_key) {

        $pdf_image = $pdf_result['media'][$pdf_image_key];

        $pdf_image_name = bin2hex(random_bytes(16)) . '.' . $pdf_image['extension'];

        if (is_dir($pdf_upload_dir) && file_put_contents($pdf_upload_dir . $pdf_image_name, $pdf_image['bytes']) !== false) {
            $pdf_written_files[] = $pdf_upload_dir . $pdf_image_name;

            /* THE CANONICAL FORM, byte-identical to what the DOCX importer above
               stores, and for every one of the same reasons - signature-free and
               root-relative so it survives a TinyMCE edit, &amp; rather than & so
               the stored bytes and the purified bytes already agree.

               This importer was written while the media-authentication work was
               parked on a branch, so it mirrored the DOCX handler as it stood THEN
               and wrote a raw /uploads/kb/ path. Once the branch landed the two
               importers disagreed: a DOCX article's images went through the
               authenticated endpoint and a PDF article's did not. That is not
               cosmetic - the 2.6.78 storage migration rewrites the legacy rows that
               exist at migration time, and would silently not cover any PDF
               imported afterwards, so once nginx denies /uploads/kb/ every image in
               every future PDF import would 404 with nothing left to repair it. */
            $pdf_html = str_replace(
                $pdf_image['token'],
                "/agent/kb_media.php?a=$kb_article_id&amp;f=$pdf_image_name",
                $pdf_html
            );
        } else {
            // Could not store it - drop the <img> rather than leave a dead link.
            $pdf_html = preg_replace('/<img src="' . preg_quote($pdf_image['token'], '/') . '"[^>]*>/', '', $pdf_html);
        }

        // Release the image bytes as we go - a picture-heavy document would
        // otherwise hold every image in memory at once alongside the HTML.
        unset($pdf_image, $pdf_result['media'][$pdf_image_key]);
    }

    // The same interactive-block backstop, and the same no-op reasoning as the
    // DOCX path above: PdfConverter emits no data-ikb either.
    $pdf_html = \ITFlow\KB\InteractiveBlocks::normalise($pdf_html)['html'];

    // Same shape as add_kb_article: the HTML is escaped for SQL as-is, and the
    // raw copy is the tag-stripped text the FULLTEXT index searches on.
    // sanitizeInput() already escapes, so neither is escaped a second time.
    $content = mysqli_real_escape_string($mysqli, $pdf_html);
    /* The FULLTEXT feed comes from poppler's own plain-text extraction rather than
       from tag-stripping the HTML. It is the same document, but read out by a tool
       that knows the page layout - so hyphenation, column order and the spaces that
       the HTML reconstruction has to infer are all already right. The DOCX path has
       no equivalent and stays as it is. */
    $content_raw = sanitizeInput($title . " " . ($pdf_result['text'] ?? str_replace("<", " <", $pdf_html)));

    $pdf_stored = mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_content = '$content',
            kb_article_content_raw = '$content_raw'
         WHERE kb_article_id = $kb_article_id"
    );

    if (!$pdf_stored) {
        // Roll the whole import back - no orphan article, no orphan files.
        foreach ($pdf_written_files as $pdf_written_file) {
            if (is_file($pdf_written_file)) {
                unlink($pdf_written_file);
            }
        }
        if (is_dir($pdf_upload_dir)) {
            @rmdir($pdf_upload_dir);
        }
        mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $kb_article_id");

        flash_alert("Import failed: the converted article could not be saved", 'error');
        redirect();
    }

    logAction("Knowledge Base", "Create", "$session_name imported KB article from a PDF: $title", $kb_article_client_id, $kb_article_id);

    $pdf_flash = "Knowledge Base article <strong>" . nullable_htmlentities($title) . "</strong> imported from a PDF";
    if (!empty($pdf_result['warnings'])) {
        $pdf_flash .= " &mdash; " . nullable_htmlentities(implode(' ', $pdf_result['warnings']));
    }

    flash_alert($pdf_flash, empty($pdf_result['warnings']) ? 'success' : 'warning');

    redirect();

}

if (isset($_POST['import_kb_article_html'])) {

    /* `?? ''` rather than the bare $_POST['csrf_token'] its two sibling
       importers use, deliberately: a POST with no token at all is exactly what a
       cross-site attempt looks like, and the bare form makes PHP 8 emit
       "Undefined array key" into the error log on every one of them - measured
       here on 8.4.25 against a token-less upload. validateCSRFToken() already
       treats a non-string as invalid (functions.php:868-880, where the same
       reasoning is written up for the null case), so '' takes the identical
       rejection path with no log noise an outsider can generate at will. */
    validateCSRFToken($_POST['csrf_token'] ?? '');

    // Importing an HTML page CREATES an article, so this is a write, exactly
    // like add_kb_article above - module_kb level 1 is "Viewing Only".
    enforceUserPermission('module_kb', 2);

    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $html_file = $_FILES['html_file'] ?? ($_FILES['import_file'] ?? []);

    if (empty($html_file) || ($html_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        flash_alert("No HTML page was uploaded", 'error');
        redirect();
    }

    /* The upload must be a genuine PHP upload, and it must pass the codebase's
       canonical validator with a two-entry allow-list.

       THE SOURCE FILE IS NEVER MOVED INTO /uploads/. That rule is a nicety for
       the Word and PDF importers and it is the whole ballgame here: /uploads/ is
       served straight off nginx with no authentication, so a .html file landing
       there would be stored XSS on this application's own origin, with the
       session cookie in reach - which would defeat every control in the
       sandboxed embed below. checkFileUpload() is a pure validator and namer
       (functions.php:2461-2505; it moves nothing), so it is used exactly as the
       DOCX and PDF paths use it: to check the extension and produce a name that
       is then deliberately never used. */
    if (!is_uploaded_file($html_file['tmp_name'])) {
        flash_alert("That upload could not be read", 'error');
        redirect();
    }

    $html_ref_name = checkFileUpload($html_file, ['html', 'htm']);

    if (!is_string($html_ref_name) || !preg_match('/^[a-zA-Z0-9_-]+\.html?$/', $html_ref_name)) {
        flash_alert("Only .html and .htm files can be imported", 'error');
        redirect();
    }

    // Two honest outcomes, and the agent chose between them in the modal with
    // the policy in front of them: convert the page into article content, or
    // keep it whole and running inside a sandboxed frame.
    $html_mode = ($_POST['import_mode'] ?? 'blocks') === 'embed'
        ? \ITFlow\KB\HtmlImporter::MODE_EMBED
        : \ITFlow\KB\HtmlImporter::MODE_BLOCKS;

    // "Leave lists, headings and collapsible sections alone" - the escape valve
    // for the one mapping in the importer that guesses.
    $html_options = ['map_blocks' => empty($_POST['leave_lists'])];

    $html_embed_name = cleanInput(mb_substr(trim($_POST['embed_name'] ?? ''), 0, 200));
    $html_embed_description = cleanInput(mb_substr(trim($_POST['embed_description'] ?? ''), 0, 255));

    if ($html_mode === \ITFlow\KB\HtmlImporter::MODE_EMBED && ($html_embed_name === '' || $html_embed_description === '')) {
        /* Not optional, and not merely a form nicety: an embed's HTML never
           reaches kb_article_content_raw, so these two strings are the ONLY
           words the knowledge base search will ever be able to find it by. */
        flash_alert("An embedded tool needs a name and a one-line description - they are the only words it can be searched for by", 'error');
        redirect();
    }

    // Convert BEFORE anything is created. HtmlImporter never writes to disk and
    // never fetches a remote resource - it hands back the HTML and the image
    // bytes in memory - so a page that is malformed, oversized or hostile leaves
    // no article and no files behind.
    try {
        $html_result = \ITFlow\KB\HtmlImporter::convert($html_file['tmp_name'], $html_mode, $html_options);
    } catch (\ITFlow\KB\HtmlImportException $e) {
        flash_alert("Import failed: " . nullable_htmlentities($e->getMessage()), 'error');
        redirect();
    } catch (\Throwable $e) {
        // Never leak an internal message; the detail goes to the error log.
        error_log('KB HTML import failed: ' . $e->getMessage());
        flash_alert("Import failed: that page could not be read", 'error');
        redirect();
    }

    /* Title: an explicitly posted one wins, then the page's own <title>, then
       the file name. The <title> is the extra step the other two importers do
       not have - a .docx carries no equivalent and a PDF's is usually the
       producing application - and it is nearly always the right answer for a
       page exported from a wiki. */
    $title = trim($_POST['title'] ?? '');

    if ($title === '' && !empty($html_result['title'])) {
        $title = (string) $html_result['title'];
    }

    if ($title === '') {
        // basename() first so a crafted "name" can never contribute a path.
        $title = basename(str_replace('\\', '/', $html_file['name']));
        $title = preg_replace('/\.html?$/i', '', $title);
        $title = str_replace(['_', '+', '-'], ' ', $title);
        $title = trim(preg_replace('/\s+/', ' ', $title));

        // "vpn onboarding runbook" -> "Vpn Onboarding Runbook", but a name that
        // already carries capitals (e.g. "VPN Onboarding") is left alone.
        if ($title !== '' && $title === mb_strtolower($title)) {
            $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        }
    }

    if ($title === '') {
        $title = 'Imported Page';
    }

    // cleanInput() strips tags and trims but does NOT escape, so $title stays
    // usable for the flash and the audit log; sanitizeInput() below is what
    // escapes it for SQL, exactly as add_kb_article does.
    $title = cleanInput(mb_substr($title, 0, 255));
    $title_escaped = sanitizeInput($title);

    // Create the row first so the images have an article id to live under, and
    // so the embed row has an article to be bound to. The content is written
    // exactly once, at the end - the INSERT deliberately leaves it empty rather
    // than storing HTML that still carries substitution tokens.
    mysqli_query(
        $mysqli,
        "INSERT INTO kb_articles SET
            kb_article_title = '$title_escaped',
            kb_article_content = '',
            kb_article_content_raw = '',
            kb_article_client_id = $kb_article_client_id,
            kb_article_category_id = $kb_article_category_id,
            kb_article_client_visible = $client_visible,
            kb_article_created_by = $session_user_id,
            kb_article_updated_by = $session_user_id"
    );

    $kb_article_id = intval(mysqli_insert_id($mysqli));

    if (!$kb_article_id) {
        flash_alert("Import failed: the article could not be created", 'error');
        redirect();
    }

    $html_written_files = [];
    $html_upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/";
    $html_embed_id = 0;
    $html_body = '';
    $html_search_text = '';

    if ($html_mode === \ITFlow\KB\HtmlImporter::MODE_EMBED) {

        /* THE ESCAPE HATCH. kb_article_embed_untrusted_html is the only column
           in this database that holds HTML no filter ever touched, and that is
           deliberate - purifying it would defeat the point of the feature. The
           containment is entirely in agent/kb_embed.php and client/kb_embed.php,
           which serve it into an opaque-origin sandbox under their own
           Content-Security-Policy. Nothing here echoes these bytes, and nothing
           here is allowed to put them anywhere else.

           The row is bound to ONE article, and that binding is the entire
           authorization model for both serve endpoints. */
        $html_embed_bytes = (string) ($html_result['embed'] ?? '');

        $html_embed_html_escaped = mysqli_real_escape_string($mysqli, $html_embed_bytes);
        $html_embed_name_escaped = sanitizeInput($html_embed_name);
        $html_embed_text_escaped = sanitizeInput(mb_substr((string) ($html_result['text'] ?? ''), 0, 8192));
        $html_embed_sha256 = hash('sha256', $html_embed_bytes);
        $html_embed_height = intval(\ITFlow\KB\InteractiveBlocks::EMBED_HEIGHT_DEFAULT);

        /* PHP 8.1+ throws on a failed query and this codebase never calls
           mysqli_report(), so an install that has this code but has not run the
           2.6.79 database update would take an uncaught fatal here rather than
           reporting a missing table. The modal disables the choice in that
           state; this is the second half of the same guard, for a POST that did
           not come through the modal. */
        $html_embed_stored = false;
        try {
            $html_embed_stored = (bool) mysqli_query(
                $mysqli,
                "INSERT INTO kb_article_embeds SET
                    kb_article_embed_kb_article_id = $kb_article_id,
                    kb_article_embed_name = '$html_embed_name_escaped',
                    kb_article_embed_untrusted_html = '$html_embed_html_escaped',
                    kb_article_embed_text = '$html_embed_text_escaped',
                    kb_article_embed_sha256 = '$html_embed_sha256',
                    kb_article_embed_height = $html_embed_height,
                    kb_article_embed_created_by = $session_user_id"
            );
            $html_embed_id = intval(mysqli_insert_id($mysqli));
        } catch (\Throwable $e) {
            error_log('KB HTML embed import failed: ' . $e->getMessage());
        }

        if (!$html_embed_stored || !$html_embed_id) {
            mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $kb_article_id");
            flash_alert("Import failed: the embedded page could not be stored. If this install was upgraded recently, an admin needs to run the database update first (Maintenance &gt; Update).", 'error');
            redirect();
        }

        /* The block, and the <p> inside it, are the whole of what the article
           holds: the fallback for a reader with no JavaScript, what the Android
           app shows (a frame cannot work there), and the only text about the
           tool that FULLTEXT will ever see. */
        $html_body = \ITFlow\KB\HtmlImporter::embedBlock(
            $html_embed_id,
            $html_embed_name,
            $html_embed_description,
            $html_embed_height
        );

        // The tool's own words, folded into the index the article is found by.
        $html_search_text = $html_embed_name . ' ' . $html_embed_description . ' ' . (string) ($html_result['text'] ?? '');

    } else {

        // Write the extracted images. Every filename here is generated by us
        // from random bytes plus the extension the importer derived from the
        // image's own sniffed type - nothing in the path comes from the
        // uploaded file.
        if (!empty($html_result['media'])) {
            mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/");
            mkdirMissing($html_upload_dir);
        }

        $html_body = $html_result['html'];

        foreach (array_keys($html_result['media']) as $html_image_key) {

            $html_image = $html_result['media'][$html_image_key];

            $html_image_name = bin2hex(random_bytes(16)) . '.' . $html_image['extension'];

            if (is_dir($html_upload_dir) && file_put_contents($html_upload_dir . $html_image_name, $html_image['bytes']) !== false) {
                $html_written_files[] = $html_upload_dir . $html_image_name;

                /* THE CANONICAL FORM, byte-identical to what the DOCX and PDF
                   importers above store, and for every one of the same reasons:
                   /uploads/ is handed out by nginx with no authentication, so
                   agent/kb_media.php re-derives module_kb and the article's
                   department scope from the database on every fetch instead;
                   signature-free and root-relative so it survives a TinyMCE
                   edit; and &amp; rather than & because the token sits inside an
                   <img src="..."> attribute, where the stored bytes and the
                   purified bytes then already agree. */
                $html_body = str_replace(
                    $html_image['token'],
                    "/agent/kb_media.php?a=$kb_article_id&amp;f=$html_image_name",
                    $html_body
                );
            } else {
                // Could not store it - drop the <img> rather than leave a dead link.
                $html_body = preg_replace('/<img src="' . preg_quote($html_image['token'], '/') . '"[^>]*>/', '', $html_body);
            }

            // Release the image bytes as we go - a picture-heavy page would
            // otherwise hold every image in memory at once alongside the HTML.
            unset($html_image, $html_result['media'][$html_image_key]);
        }

    }

    /* THE SERVER BACKSTOP, then the second lock.

       normalise() mints any missing block or part key, re-mints duplicates,
       drops the editor-only contenteditable marker and reports what it repaired.
       It is a no-op on content with no data-ikb in it.

       Then HTMLPurifier, with agent/kb_article.php's exact config plus the
       interactive vocabulary. The importer is already a closed emitter that can
       only produce a fixed tag set with escaped text - this is the SECOND lock,
       not the first, and it is here rather than only at render time because this
       is the one import path whose input format is itself an attack language.
       Measured on the fixtures: the emitted blocks survive it byte-for-byte and
       a second purify of the result is identical, so nothing is lost by storing
       the purified form. */
    $html_normalised = \ITFlow\KB\InteractiveBlocks::normalise($html_body);
    $html_body = $html_normalised['html'];

    require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/htmlpurifier/HTMLPurifier.standalone.php';

    $html_purifier_config = HTMLPurifier_Config::createDefault();
    $html_purifier_config->set('Cache.DefinitionImpl', null);
    $html_purifier_config->set('URI.AllowedSchemes', ['data' => true, 'src' => true, 'http' => true, 'https' => true]);
    $html_purifier_config->set('Attr.DefaultImageAlt', '');
    \ITFlow\KB\InteractiveBlocks::apply($html_purifier_config);
    $html_purifier = new HTMLPurifier($html_purifier_config);

    $html_body = $html_purifier->purify($html_body);

    /* The FULLTEXT feed is derived AFTER the purify, so the index describes what
       was actually stored rather than what was about to be. For an embed the two
       are different things - the article holds only a link, so the search text is
       the tool's name, its description and its own words, assembled above. */
    if (!$html_embed_id) {
        $html_search_text = str_replace("<", " <", $html_body);
    }

    // Same shape as add_kb_article: the HTML is escaped for SQL as-is, and the
    // raw copy is the tag-stripped text the FULLTEXT index searches on.
    // sanitizeInput() already escapes, so neither is escaped a second time.
    $content = mysqli_real_escape_string($mysqli, $html_body);
    $content_raw = sanitizeInput($title . " " . $html_search_text);

    $html_stored = mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_content = '$content',
            kb_article_content_raw = '$content_raw'
         WHERE kb_article_id = $kb_article_id"
    );

    if (!$html_stored) {
        // Roll the whole import back - no orphan article, no orphan files, no
        // orphan embed row.
        foreach ($html_written_files as $html_written_file) {
            if (is_file($html_written_file)) {
                unlink($html_written_file);
            }
        }
        if (is_dir($html_upload_dir)) {
            @rmdir($html_upload_dir);
        }
        if ($html_embed_id) {
            mysqli_query($mysqli, "DELETE FROM kb_article_embeds WHERE kb_article_embed_id = $html_embed_id");
        }
        mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $kb_article_id");

        flash_alert("Import failed: the converted article could not be saved", 'error');
        redirect();
    }

    if ($html_embed_id) {
        /* Audit-logged with the bytes' digest, because this is the one authoring
           action in the application that ships arbitrary JavaScript to every
           department contact who can read the article - caged, but arbitrary. */
        logAction(
            "Knowledge Base",
            "Create",
            "$session_name imported KB article as a sandboxed embed: $title (embed #$html_embed_id, sha256 " . substr($html_embed_sha256, 0, 16) . ")",
            $kb_article_client_id,
            $kb_article_id
        );
    } else {
        logAction("Knowledge Base", "Create", "$session_name imported KB article from an HTML page: $title", $kb_article_client_id, $kb_article_id);
    }

    $html_warnings = array_merge((array) $html_result['warnings'], $html_normalised['warnings']);

    $html_flash = "Knowledge Base article <strong>" . nullable_htmlentities($title) . "</strong> imported from an HTML page";
    if (!empty($html_warnings)) {
        $html_flash .= " &mdash; " . nullable_htmlentities(implode(' ', $html_warnings));
    }

    flash_alert($html_flash, empty($html_warnings) ? 'success' : 'warning');

    redirect();

}

if (isset($_POST['edit_kb_article'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Editing an article is a write, not a read
    enforceUserPermission('module_kb', 2);

    $kb_article_id = intval($_POST['kb_article_id']);

    // Confirm access to the article's current client before allowing any edit
    // - the client_id must be looked up from the DB, not trusted from POST.
    $existing_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($existing_client_id) {
        enforceClientAccess($existing_client_id);
    }

    $title = sanitizeInput($_POST['title']);
    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    /* THE SERVER BACKSTOP for interactive blocks. \ITFlow\KB\InteractiveBlocks::normalise()
       mints a missing block or part key, re-mints the duplicates a copy-paste
       leaves behind, restores a step title the editor deleted, drops the
       <p>&nbsp;</p> a stray Enter leaves inside a step, and STRIPS
       contenteditable - which is the one thing the stock purifier keeps
       (measured: contenteditable="false" survives it untouched), so this is the
       only place that can guarantee the editor's own marker never reaches
       storage.

       It early-returns on content with no data-ikb in it, so an ordinary
       article save is byte-identical to before this call existed. Every path in
       this file that writes kb_article_content runs it; a path that does not
       would silently store a block whose keys collide with another's, and a
       reader's ticks would land on the wrong steps. */
    $ikb_edit = \ITFlow\KB\InteractiveBlocks::normalise((string) $_POST['content']);

    $content = mysqli_real_escape_string($mysqli, $ikb_edit['html']);
    $content_raw = sanitizeInput($_POST['title'] . " " . str_replace("<", " <", $ikb_edit['html']));

    // Snapshot the pre-overwrite content into kb_article_versions before
    // applying the edit (master plan Phase 5, Section 14 - KB versioning).
    $sql_kb_version_current = mysqli_query($mysqli, "SELECT kb_article_content, kb_article_content_raw FROM kb_articles WHERE kb_article_id = $kb_article_id LIMIT 1");
    $kb_version_current_row = mysqli_fetch_assoc($sql_kb_version_current);

    $sql_kb_version_max = mysqli_query($mysqli, "SELECT MAX(kb_article_version_number) AS max_version FROM kb_article_versions WHERE kb_article_version_kb_article_id = $kb_article_id");
    $kb_version_next_number = intval(mysqli_fetch_assoc($sql_kb_version_max)['max_version']) + 1;

    $kb_version_prev_content = mysqli_real_escape_string($mysqli, $kb_version_current_row['kb_article_content'] ?? '');
    $kb_version_prev_content_raw = mysqli_real_escape_string($mysqli, $kb_version_current_row['kb_article_content_raw'] ?? '');

    mysqli_query(
        $mysqli,
        "INSERT INTO kb_article_versions SET
            kb_article_version_kb_article_id = $kb_article_id,
            kb_article_version_content = '$kb_version_prev_content',
            kb_article_version_content_raw = '$kb_version_prev_content_raw',
            kb_article_version_edited_by = $session_user_id,
            kb_article_version_edited_at = NOW(),
            kb_article_version_number = $kb_version_next_number"
    );

    mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_title = '$title',
            kb_article_content = '$content',
            kb_article_content_raw = '$content_raw',
            kb_article_client_id = $kb_article_client_id,
            kb_article_category_id = $kb_article_category_id,
            kb_article_client_visible = $client_visible,
            kb_article_updated_by = $session_user_id
         WHERE kb_article_id = $kb_article_id"
    );

    logAction("Knowledge Base", "Edit", "$session_name edited KB article: $title", $kb_article_client_id, $kb_article_id);

    $edit_flash = "Knowledge Base article <strong>$title</strong> updated";
    if (!empty($ikb_edit['warnings'])) {
        $edit_flash .= " &mdash; " . nullable_htmlentities(implode(' ', $ikb_edit['warnings']));
    }

    flash_alert($edit_flash, empty($ikb_edit['warnings']) ? 'success' : 'warning');

    redirect();

}

if (isset($_POST['upload_kb_article_attachment'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Attaching a file to an article is a write, not a read
    enforceUserPermission('module_kb', 2);

    $kb_article_id = intval($_POST['kb_article_id']);

    $kb_article_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $allowed = ['jpg','jpeg','gif','png','webp','pdf','txt','md','doc','docx','odt','csv','xls','xlsx','ods','pptx','odp','zip','tar','gz','xml','msg','json','wav','mp3','ogg','mov','mp4','av1','ovpn'];

    if (!empty($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {

        $ref_name = checkFileUpload($_FILES['attachment_file'], $allowed);

        /* One definition, shared with every reader of
           kb_article_attachment_reference_name (kb_media.php,
           kb_article_attachment.php, MediaUrlRewriter::descriptor()), so the
           writer cannot disagree with them. See functions.php. */
        if (isUploadReferenceName($ref_name)) {

            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/";
            mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/");
            mkdirMissing($upload_dir);

            move_uploaded_file($_FILES['attachment_file']['tmp_name'], $upload_dir . $ref_name);

            $name = sanitizeInput($_FILES['attachment_file']['name']);
            $ref  = mysqli_real_escape_string($mysqli, $ref_name);

            mysqli_query($mysqli,
                "INSERT INTO kb_article_attachments SET kb_article_attachment_name='$name', kb_article_attachment_reference_name='$ref', kb_article_attachment_kb_article_id=$kb_article_id"
            );

            logAction("Knowledge Base", "Edit", "$session_name uploaded attachment $name to KB article", 0, $kb_article_id);
            flash_alert("Attachment uploaded", 'success');

        } else {
            flash_alert("Invalid or unsupported file type", 'error');
        }

    } else {
        flash_alert("No file uploaded", 'error');
    }

    redirect();
}

if (isset($_GET['delete_kb_article_attachment'])) {

    validateCSRFToken($_GET['csrf_token']);

    // Destroying an attachment (row + file on disk) is a delete - full access
    enforceUserPermission('module_kb', 3);

    $attachment_id = intval($_GET['delete_kb_article_attachment']);
    $kb_article_id = intval($_GET['kb_article_id']);

    $kb_article_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $att = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM kb_article_attachments WHERE kb_article_attachment_id = $attachment_id AND kb_article_attachment_kb_article_id = $kb_article_id LIMIT 1"));

    if ($att) {
        /* A row is not a trust boundary - the same rule the read side applies at
           kb_media.php:309 and kb_article_attachment.php:120, and it matters more
           here because the string is being interpolated into a path that is then
           unlink()ed. The column is only ever written by the upload handler above,
           so there is no known way to get a traversal sequence into it today; this
           is the guard that means a future writer, or a restored/edited row, cannot
           turn "delete this attachment" into "delete any file www-data can reach".

           A name that fails still has its ROW removed - the attachment disappears
           from the article either way. Only the unlink is skipped, because a name
           we cannot parse is a path we have no business constructing. */
        $ref_name = (string) $att['kb_article_attachment_reference_name'];
        if (\ITFlow\KB\MediaToken::isValidReferenceName($ref_name)) {
            $file_path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/$ref_name";
            if (is_file($file_path)) {
                unlink($file_path);
            }
        }

        mysqli_query($mysqli, "DELETE FROM kb_article_attachments WHERE kb_article_attachment_id = $attachment_id");

        $name = sanitizeInput($att['kb_article_attachment_name']);
        logAction("Knowledge Base", "Edit", "$session_name deleted attachment $name from KB article", 0, $kb_article_id);
        flash_alert("Attachment deleted", 'error');
    }

    redirect();
}

if (isset($_GET['delete_kb_article'])) {

    validateCSRFToken($_GET['csrf_token']);

    // Deleting an article is a delete - full access, matching every other module
    enforceUserPermission('module_kb', 3);

    $kb_article_id = intval($_GET['delete_kb_article']);

    $kb_article_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $kb_article_title = sanitizeInput(getFieldById('kb_articles', $kb_article_id, 'kb_article_title'));

    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_archived_at = NOW() WHERE kb_article_id = $kb_article_id");

    logAction("Knowledge Base", "Delete", "$session_name deleted KB article: $kb_article_title");

    flash_alert("Knowledge Base article <strong>$kb_article_title</strong> deleted", 'error');

    redirect();

}
