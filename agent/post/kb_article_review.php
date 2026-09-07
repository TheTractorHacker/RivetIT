<?php

// Knowledge Base article review scheduling (master plan Phase 5, Section 14.3)
// Deliberately its own small handler, separate from add_kb_article/edit_kb_article
// in post/kb_article.php, so the review-due-date/reviewer fields don't need to
// ride along with the main content-edit form.

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['set_kb_article_review'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Setting the review schedule updates the article - that is a write
    enforceUserPermission('module_kb', 2);

    $kb_article_id = intval($_POST['kb_article_id']);

    $existing_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($existing_client_id) {
        enforceClientAccess($existing_client_id);
    }

    $review_due_at_raw = sanitizeInput($_POST['review_due_at'] ?? '');
    $review_due_at = $review_due_at_raw === '' ? "NULL" : "'" . $review_due_at_raw . "'";

    $reviewer_user_id_raw = intval($_POST['reviewer_user_id'] ?? 0);
    $reviewer_user_id = $reviewer_user_id_raw > 0 ? $reviewer_user_id_raw : "NULL";

    mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_review_due_at = $review_due_at,
            kb_article_reviewer_user_id = $reviewer_user_id
         WHERE kb_article_id = $kb_article_id"
    );

    $kb_article_title = sanitizeInput(getFieldById('kb_articles', $kb_article_id, 'kb_article_title'));

    logAction("Knowledge Base", "Edit", "$session_name set the review schedule for KB article: $kb_article_title", $existing_client_id, $kb_article_id);

    flash_alert("Review schedule for <strong>$kb_article_title</strong> updated");

    redirect();

}
