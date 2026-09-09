<?php

/*
 * Handler for admin/kb_articles_archive.php.
 *
 * The filename is load-bearing: admin/post.php derives the module from the basename of
 * the HTTP referer, so this file must stay named after the page that posts to it - and
 * that includes the Restore button inside modals/kb_article/kb_article_archive_view.php,
 * which is loaded INTO that page by the ajax-modal fetch, so the browser's referer for a
 * submit from inside it is still admin/kb_articles_archive.php, not the modal's own path.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['restore_kb_article'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_admin');

    $kb_article_id = intval($_POST['kb_article_id']);

    $kb_article_title = sanitizeInput(getFieldById('kb_articles', $kb_article_id, 'kb_article_title'));

    // WHERE ... archived_at IS NOT NULL: a no-op on an id that is not actually
    // archived, rather than something that could ever touch a live article.
    mysqli_query($mysqli, "UPDATE kb_articles SET kb_article_archived_at = NULL WHERE kb_article_id = $kb_article_id AND kb_article_archived_at IS NOT NULL");

    if (mysqli_affected_rows($mysqli) > 0) {
        logAction("Knowledge Base", "Restore", "$session_name restored archived KB article: $kb_article_title", 0, $kb_article_id);
        flash_alert("Knowledge Base article <strong>$kb_article_title</strong> restored");
    } else {
        flash_alert("That article was not in the archive - it may already have been restored.", 'error');
    }

    redirect();

}

if (isset($_POST['delete_kb_article_permanently'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_admin');

    $kb_article_id = intval($_POST['kb_article_id']);

    /* Only an ARCHIVED article can be hard-deleted from here - the query below
       requires kb_article_archived_at IS NOT NULL, so a crafted id belonging to a
       live article does nothing rather than destroying it. Soft-delete
       (kb_article_archived_at = NOW()) is still the only thing agent/kb_article.php
       offers, and it stays that way; this page is what turns that into permanent
       only when someone with admin access chooses it here. */
    $article = mysqli_fetch_assoc(mysqli_query(
        $mysqli,
        "SELECT kb_article_id, kb_article_title FROM kb_articles WHERE kb_article_id = $kb_article_id AND kb_article_archived_at IS NOT NULL LIMIT 1"
    ));

    if (!$article) {
        flash_alert("That article is not in the archive - it may already have been restored or deleted.", 'error');
        redirect();
    }

    $kb_article_title = sanitizeInput($article['kb_article_title']);
    $kb_article_title_html = nullable_htmlentities($article['kb_article_title']);

    // Attachments: unlink each file BEFORE the row that names it is gone, using
    // the same reference-name validation the attachment-delete handler above
    // this one applies (isValidReferenceName()) - a row is not a trust boundary,
    // and a name that fails to validate still has its ROW removed; only the
    // unlink is skipped, because a name we cannot parse is a path we have no
    // business constructing.
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/";
    $sql_attachments = mysqli_query(
        $mysqli,
        "SELECT kb_article_attachment_reference_name FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $kb_article_id"
    );
    $attachment_count = 0;
    while ($att = mysqli_fetch_assoc($sql_attachments)) {
        $attachment_count++;
        $ref_name = (string) $att['kb_article_attachment_reference_name'];
        if (\ITFlow\KB\MediaToken::isValidReferenceName($ref_name)) {
            $file_path = $upload_dir . $ref_name;
            if (is_file($file_path)) {
                unlink($file_path);
            }
        }
    }

    mysqli_query($mysqli, "DELETE FROM kb_article_attachments WHERE kb_article_attachment_kb_article_id = $kb_article_id");
    mysqli_query($mysqli, "DELETE FROM kb_article_versions WHERE kb_article_version_kb_article_id = $kb_article_id");
    mysqli_query($mysqli, "DELETE FROM kb_articles WHERE kb_article_id = $kb_article_id");

    // Best-effort: the article's own upload directory should be empty now that
    // every tracked attachment and DOCX/PDF-imported image has been unlinked
    // (agent/post/kb_article.php writes only through those two paths), but this
    // is not itself the source of truth for what belonged to the article, so a
    // directory that turns out non-empty is simply left rather than force-removed.
    if (is_dir($upload_dir)) {
        @rmdir($upload_dir);
    }

    // Logged AFTER the delete, unlike every other KB log line in this app - a
    // logAction() call taking kb_article_id as its record id would leave a log
    // entry pointing at a row that, by the time anyone reads the log, no longer
    // exists. Passed as 0 instead; the title is in the message text either way.
    logAction("Knowledge Base", "Delete", "$session_name permanently deleted archived KB article: $kb_article_title" . ($attachment_count > 0 ? " (with $attachment_count attachment" . ($attachment_count === 1 ? '' : 's') . ")" : ''), 0, 0);

    flash_alert("Knowledge Base article <strong>$kb_article_title_html</strong> permanently deleted");

    redirect();

}
