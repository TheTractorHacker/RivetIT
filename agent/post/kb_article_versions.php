<?php

// Knowledge Base article version history (master plan Phase 5, Section 14)

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['restore_kb_article_version'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

    $kb_article_version_id = intval($_POST['restore_kb_article_version']);
    $kb_article_id = intval($_POST['kb_article_id']);

    $sql_version = mysqli_query(
        $mysqli,
        "SELECT * FROM kb_article_versions WHERE kb_article_version_id = $kb_article_version_id AND kb_article_version_kb_article_id = $kb_article_id LIMIT 1"
    );

    if (mysqli_num_rows($sql_version) == 0) {
        flash_alert("Version not found", 'error');
        redirect();
    }

    $version = mysqli_fetch_assoc($sql_version);

    $existing_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($existing_client_id) {
        enforceClientAccess($existing_client_id);
    }

    // Snapshot what is currently live before overwriting it, exactly like a
    // normal edit does - so a restore is itself just another version, never
    // a silent, unrecoverable overwrite.
    $sql_current = mysqli_query($mysqli, "SELECT kb_article_content, kb_article_content_raw FROM kb_articles WHERE kb_article_id = $kb_article_id LIMIT 1");
    $current = mysqli_fetch_assoc($sql_current);

    $sql_max_version = mysqli_query($mysqli, "SELECT MAX(kb_article_version_number) AS max_version FROM kb_article_versions WHERE kb_article_version_kb_article_id = $kb_article_id");
    $next_version_number = intval(mysqli_fetch_assoc($sql_max_version)['max_version']) + 1;

    $prev_content = mysqli_real_escape_string($mysqli, $current['kb_article_content'] ?? '');
    $prev_content_raw = mysqli_real_escape_string($mysqli, $current['kb_article_content_raw'] ?? '');

    mysqli_query(
        $mysqli,
        "INSERT INTO kb_article_versions SET
            kb_article_version_kb_article_id = $kb_article_id,
            kb_article_version_content = '$prev_content',
            kb_article_version_content_raw = '$prev_content_raw',
            kb_article_version_edited_by = $session_user_id,
            kb_article_version_edited_at = NOW(),
            kb_article_version_number = $next_version_number"
    );

    $restored_content = mysqli_real_escape_string($mysqli, $version['kb_article_version_content'] ?? '');
    $restored_content_raw = mysqli_real_escape_string($mysqli, $version['kb_article_version_content_raw'] ?? '');
    $restored_version_number = intval($version['kb_article_version_number']);

    mysqli_query(
        $mysqli,
        "UPDATE kb_articles SET
            kb_article_content = '$restored_content',
            kb_article_content_raw = '$restored_content_raw',
            kb_article_updated_by = $session_user_id
         WHERE kb_article_id = $kb_article_id"
    );

    $kb_article_title = sanitizeInput(getFieldById('kb_articles', $kb_article_id, 'kb_article_title'));

    logAction("Knowledge Base", "Restore", "$session_name restored KB article \"$kb_article_title\" to version #$restored_version_number", $existing_client_id, $kb_article_id);

    \ITFlow\Audit\AuditService::record(
        'kb_article.restored',
        $session_user_id,
        'kb_article',
        $kb_article_id,
        'restore',
        "$session_name restored KB article \"$kb_article_title\" to version #$restored_version_number"
    );

    flash_alert("Knowledge Base article <strong>$kb_article_title</strong> restored to version #$restored_version_number");

    redirect("kb_article_versions.php?kb_article_id=$kb_article_id" . ($existing_client_id ? "&client_id=$existing_client_id" : ""));

}
