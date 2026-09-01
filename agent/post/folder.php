<?php

/*
 * ITFlow - GET/POST request handler for folders
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['create_folder'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['client_id']);
    $folder_location = intval($_POST['folder_location'] ?? 0);
    $folder_name = sanitizeInput($_POST['folder_name']);
    $parent_folder = intval($_POST['parent_folder']);

    enforceClientAccess();

    // Parent folder (if any) must belong to this client and share this folder's
    // type, or a crafted request could nest a folder under another client's tree,
    // or mix a credential folder into a file/document tree (and vice versa).
    if ($parent_folder !== 0) {
        $sql_parent = mysqli_query($mysqli, "SELECT folder_id FROM folders WHERE folder_id = $parent_folder AND folder_client_id = $client_id AND folder_location = $folder_location");
        if (mysqli_num_rows($sql_parent) !== 1) {
            flash_alert("Invalid parent folder", 'error');
            redirect();
        }
    }

    // Document folder add query
    $add_folder = mysqli_query($mysqli,"INSERT INTO folders SET folder_name = '$folder_name', parent_folder = $parent_folder, folder_location = $folder_location, folder_client_id = $client_id");
    $folder_id = mysqli_insert_id($mysqli);

    logAction("Folder", "Create", "$session_name created folder $folder_name", $client_id, $folder_id);

    flash_alert("Folder <strong>$folder_name</strong> created");

    redirect();

}

if (isset($_POST['rename_folder'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $folder_id = intval($_POST['folder_id']);
    $folder_name = sanitizeInput($_POST['folder_name']);

    // Get old Folder Name Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT folder_name, folder_client_id FROM folders WHERE folder_id = $folder_id");
    $row = mysqli_fetch_assoc($sql);
    $old_folder_name = sanitizeInput($row['folder_name']);
    $client_id = intval($row['folder_client_id']);

    enforceClientAccess();

    // Folder edit query
    mysqli_query($mysqli,"UPDATE folders SET folder_name = '$folder_name' WHERE folder_id = $folder_id");

    logAction("Folder", "Rename", "$session_name renamed folder $old_folder_name to $folder_name", $client_id, $folder_id);

    flash_alert("Folder <strong>$old_folder_name</strong> renamed to <strong>$folder_name</strong>");

    redirect();

}

if (isset($_GET['delete_folder'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_support', 3);

    $folder_id = intval($_GET['delete_folder']);

    // Get Folder Name Client ID for Logging
    $sql = mysqli_query($mysqli,"SELECT folder_name, folder_client_id FROM folders WHERE folder_id = $folder_id");
    $row = mysqli_fetch_assoc($sql);
    $folder_name = sanitizeInput($row['folder_name']);
    $client_id = intval($row['folder_client_id']);

    enforceClientAccess();

    // The UI only ever offers this action for empty, childless folders - enforce
    // that here too, or a direct request could delete a non-empty folder and
    // (since subfolders aren't reparented) orphan any subfolders it contains.
    $has_documents  = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM documents WHERE document_folder_id = $folder_id LIMIT 1"));
    $has_files      = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM files WHERE file_folder_id = $folder_id LIMIT 1"));
    $has_credentials = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM credentials WHERE credential_folder_id = $folder_id LIMIT 1"));
    $has_subfolders = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM folders WHERE parent_folder = $folder_id LIMIT 1"));

    if ($has_documents || $has_files || $has_credentials || $has_subfolders) {
        flash_alert("Folder <strong>$folder_name</strong> is not empty and cannot be deleted", 'error');
        redirect();
    }

    mysqli_query($mysqli,"DELETE FROM folders WHERE folder_id = $folder_id");

    logAction("Folder", "Delete", "$session_name deleted folder $folder_name", $client_id);

    flash_alert("Folder <strong>$folder_name</strong> deleted", 'error');

    redirect();

}
