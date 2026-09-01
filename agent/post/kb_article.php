<?php

// Knowledge Base Articles

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_kb_article'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

    $title = sanitizeInput($_POST['title']);
    $kb_article_client_id = intval($_POST['client_id'] ?? 0);
    $kb_article_category_id = intval($_POST['category_id'] ?? 0);
    $client_visible = intval($_POST['client_visible'] ?? 1);

    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $content = mysqli_real_escape_string($mysqli, $_POST['content']);
    $content_raw = sanitizeInput($_POST['title'] . " " . str_replace("<", " <", $_POST['content']));

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

    flash_alert("Knowledge Base article <strong>$title</strong> created");

    redirect();

}

if (isset($_POST['edit_kb_article'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

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

    $content = mysqli_real_escape_string($mysqli, $_POST['content']);
    $content_raw = sanitizeInput($_POST['title'] . " " . str_replace("<", " <", $_POST['content']));

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

    flash_alert("Knowledge Base article <strong>$title</strong> updated");

    redirect();

}

if (isset($_POST['upload_kb_article_attachment'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_kb');

    $kb_article_id = intval($_POST['kb_article_id']);

    $kb_article_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $allowed = ['jpg','jpeg','gif','png','webp','pdf','txt','md','doc','docx','odt','csv','xls','xlsx','ods','pptx','odp','zip','tar','gz','xml','msg','json','wav','mp3','ogg','mov','mp4','av1','ovpn'];

    if (!empty($_FILES['attachment_file']) && $_FILES['attachment_file']['error'] === UPLOAD_ERR_OK) {

        $ref_name = checkFileUpload($_FILES['attachment_file'], $allowed);

        if (is_string($ref_name) && preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $ref_name)) {

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

    enforceUserPermission('module_kb');

    $attachment_id = intval($_GET['delete_kb_article_attachment']);
    $kb_article_id = intval($_GET['kb_article_id']);

    $kb_article_client_id = intval(getFieldById('kb_articles', $kb_article_id, 'kb_article_client_id'));
    if ($kb_article_client_id) {
        enforceClientAccess($kb_article_client_id);
    }

    $att = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM kb_article_attachments WHERE kb_article_attachment_id = $attachment_id AND kb_article_attachment_kb_article_id = $kb_article_id LIMIT 1"));

    if ($att) {
        $ref_name = $att['kb_article_attachment_reference_name'];
        $file_path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/$kb_article_id/$ref_name";
        if (is_file($file_path)) {
            unlink($file_path);
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

    enforceUserPermission('module_kb');

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
