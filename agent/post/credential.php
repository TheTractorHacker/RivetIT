<?php

/*
 * RivetIT - GET/POST request handler for client credentials
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_credential'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    require_once 'credential_model.php';

    $client_id = intval($_POST['client_id']);
    $folder_id = intval($_POST['folder_id'] ?? 0);

    enforceClientAccess();

    mysqli_query($mysqli,"INSERT INTO credentials SET credential_type = '$type', credential_name = '$name', credential_description = '$description', credential_uri = '$uri', credential_uri_2 = '$uri_2', credential_username = '$username', credential_password = '$password', credential_otp_secret = '$otp_secret', credential_note = '$note', credential_favorite = $favorite, credential_folder_id = $folder_id, credential_contact_id = $contact_id, credential_asset_id = $asset_id, credential_client_id = $client_id, credential_rotation_due_at = $rotation_due_at");

    $credential_id = mysqli_insert_id($mysqli);

     // Add Tags
    if (isset($_POST['tags'])) {
        foreach($_POST['tags'] as $tag) {
            $tag = intval($tag);
            mysqli_query($mysqli, "INSERT INTO credential_tags SET credential_id = $credential_id, tag_id = $tag");
        }
    }

    logAction("Credential", "Create", "$session_name created credential $name", $client_id, $credential_id);

    flash_alert("Credential <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_credential'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    require_once 'credential_model.php';

    $credential_id = intval($_POST['credential_id']);

    $client_id = intval(getFieldById('credentials', $credential_id, 'credential_client_id'));

    enforceClientAccess();

    // Snapshot old values before update (for history tracking)
    $old_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT credential_type, credential_name, credential_description, credential_uri, credential_username, credential_password, credential_note FROM credentials WHERE credential_id = $credential_id"));
    $old_type_h         = $old_row['credential_type'];
    $old_name_h        = $old_row['credential_name'];
    $old_description_h = $old_row['credential_description'];
    $old_uri_h         = $old_row['credential_uri'];
    $old_username_h    = decryptCredentialEntry($old_row['credential_username']);
    $old_password_h    = decryptCredentialEntry($old_row['credential_password']);
    $old_note_h        = $old_row['credential_note'];

    // Determine if the password has actually changed (salt is rotated on all updates, so have to dencrypt both and compare)
    $current_password = $old_password_h;
    $new_password = decryptCredentialEntry($password); // Get the new password being set (already encrypted by the credential model)
    $new_username_plain = decryptCredentialEntry($username);
    $password_changed = ($current_password !== $new_password);
    $username_changed = ($old_username_h !== $new_username_plain);
    if ($password_changed) {
        // The password has been changed - update the DB to track
        mysqli_query($mysqli, "UPDATE credentials SET credential_password_changed_at = NOW(), credential_last_rotated_at = NOW() WHERE credential_id = $credential_id");
    }

    // Version snapshot: keep the OLD encrypted username/password (never plaintext) so a
    // wrongly-changed credential can be recovered, distinct from credential_history below
    // which is a redacted-for-password change log, not a recoverable copy.
    if ($password_changed || $username_changed) {
        $stmt_version = mysqli_prepare($mysqli, "INSERT INTO credential_versions SET version_credential_id = ?, version_changed_by = ?, version_changed_by_name = ?, version_previous_username_enc = ?, version_previous_password_enc = ?");
        $old_username_enc = $old_row['credential_username'];
        $old_password_enc = $old_row['credential_password'];
        mysqli_stmt_bind_param($stmt_version, "iisss", $credential_id, $session_user_id, $session_name, $old_username_enc, $old_password_enc);
        mysqli_stmt_execute($stmt_version);
        mysqli_stmt_close($stmt_version);
    }

    // Update the credential entry with the new details
    mysqli_query($mysqli,"UPDATE credentials SET credential_type = '$type', credential_name = '$name', credential_description = '$description', credential_uri = '$uri', credential_uri_2 = '$uri_2', credential_username = '$username', credential_password = '$password', credential_otp_secret = '$otp_secret', credential_note = '$note', credential_favorite = $favorite, credential_contact_id = $contact_id, credential_asset_id = $asset_id, credential_rotation_due_at = $rotation_due_at WHERE credential_id = $credential_id");

    // Record history for each changed field
    $history_changes = [
        ['Type',        $old_type_h,        $type],
        ['Name',        $old_name_h,        $name],
        ['Description', $old_description_h,  $description],
        ['URL',         $old_uri_h,          $uri],
        ['Username',    $old_username_h,     $new_username_plain],
        ['Note',        $old_note_h,         $note],
    ];
    foreach ($history_changes as [$field, $old_val, $new_val]) {
        if ($old_val !== $new_val) {
            $h_field   = sanitizeInput($field);
            $h_old     = sanitizeInput((string)$old_val);
            $h_new     = sanitizeInput((string)$new_val);
            $h_user    = sanitizeInput($session_name);
            mysqli_query($mysqli, "INSERT INTO credential_history SET history_credential_id = $credential_id, history_user_id = $session_user_id, history_user_name = '$h_user', history_field = '$h_field', history_old_value = '$h_old', history_new_value = '$h_new'");
        }
    }
    if ($current_password !== $new_password) {
        $h_user = sanitizeInput($session_name);
        mysqli_query($mysqli, "INSERT INTO credential_history SET history_credential_id = $credential_id, history_user_id = $session_user_id, history_user_name = '$h_user', history_field = 'Password', history_old_value = NULL, history_new_value = NULL");
    }

    // Tags
    // Delete existing tags
    mysqli_query($mysqli, "DELETE FROM credential_tags WHERE credential_id = $credential_id");

    // Add new tags
    if(isset($_POST['tags'])) {
        foreach($_POST['tags'] as $tag) {
            $tag = intval($tag);
            mysqli_query($mysqli, "INSERT INTO credential_tags SET credential_id = $credential_id, tag_id = $tag");
        }
    }

    logAction("Credential", "Edit", "$session_name edited credential $name", $client_id, $credential_id);

    flash_alert("Credential <strong>$name</strong> edited");

    redirect();

}

if (isset($_POST['move_credential'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    $credential_id = intval($_POST['credential_id']);
    $folder_id = intval($_POST['folder_id']);

    // Get Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
    $row = mysqli_fetch_assoc($sql);
    $credential_name = sanitizeInput($row['credential_name']);
    $client_id = intval($row['credential_client_id']);

    enforceClientAccess();

    // Target folder (if not root) must belong to this credential's client and be a
    // credential folder, or a crafted request could move a credential into another
    // client's folder, or into a file/document folder.
    if ($folder_id > 0) {
        $sql_target_folder = mysqli_query($mysqli, "SELECT folder_id FROM folders WHERE folder_id = $folder_id AND folder_client_id = $client_id AND folder_location = 2");
        if (mysqli_num_rows($sql_target_folder) !== 1) {
            flash_alert("Invalid target folder", 'error');
            redirect();
        }
    }

    // Get folder name for logging
    $folder_name = "/";
    if ($folder_id > 0) {
        $folder_name = sanitizeInput(getFieldById('folders', $folder_id, 'folder_name'));
    }

    mysqli_query($mysqli,"UPDATE credentials SET credential_folder_id = $folder_id, credential_updated_at = credential_updated_at WHERE credential_id = $credential_id");

    logAction("Credential", "Move", "$session_name moved credential $credential_name to folder $folder_name", $client_id, $credential_id);

    flash_alert("Credential <strong>$credential_name</strong> moved to <strong>$folder_name</strong>");

    redirect();

}

if (isset($_POST['bulk_move_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    $folder_id = intval($_POST['bulk_folder_id']);

    // Get folder name for logging
    $folder_name = "/";
    if ($folder_id > 0) {
        $folder_name = sanitizeInput(getFieldById('folders', $folder_id, 'folder_name'));
    }

    if (isset($_POST['credential_ids'])) {

        $count = count($_POST['credential_ids']);

        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Name and Client ID for logging
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            // Same ownership/type check as the single-move handler, per credential
            // since a bulk selection could (in theory) span clients.
            if ($folder_id > 0) {
                $sql_target_folder = mysqli_query($mysqli, "SELECT folder_id FROM folders WHERE folder_id = $folder_id AND folder_client_id = $client_id AND folder_location = 2");
                if (mysqli_num_rows($sql_target_folder) !== 1) {
                    continue;
                }
            }

            mysqli_query($mysqli,"UPDATE credentials SET credential_folder_id = $folder_id, credential_updated_at = credential_updated_at WHERE credential_id = $credential_id");

            logAction("Credential", "Move", "$session_name moved credential $credential_name to folder $folder_name", $client_id, $credential_id);

        }

        logAction("Credential", "Bulk Move", "$session_name moved $count credential(s) to folder $folder_name", $client_id);

        flash_alert("Moved <strong>$count</strong> credential(s) to <strong>$folder_name</strong>");

    }

    redirect();

}

if(isset($_GET['archive_credential'])){

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_credential', 2);

    $credential_id = intval($_GET['archive_credential']);

    // Get Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
    $row = mysqli_fetch_assoc($sql);
    $credential_name = sanitizeInput($row['credential_name']);
    $client_id = intval($row['credential_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE credentials SET credential_archived_at = NOW() WHERE credential_id = $credential_id");

    logAction("Credential", "Archive", "$session_name archived credential $credential_name", $client_id, $credential_id);

    flash_alert("Credential <strong>$credential_name</strong> archived", 'error');

    redirect();

}

if(isset($_GET['restore_credential'])){

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_credential', 2);

    $credential_id = intval($_GET['restore_credential']);

    // Get Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
    $row = mysqli_fetch_assoc($sql);
    $credential_name = sanitizeInput($row['credential_name']);
    $client_id = intval($row['credential_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"UPDATE credentials SET credential_archived_at = NULL WHERE credential_id = $credential_id");

    logAction("Credential", "Restore", "$session_name restored credential $credential_name", $client_id, $credential_id);

    flash_alert("Credential <strong>$credential_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_credential'])) {

    validateCSRFToken($_GET['csrf_token']);

    enforceUserPermission('module_credential', 3);

    $credential_id = intval($_GET['delete_credential']);

    // Get Credential Name and Client ID for logging and alert message
    $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
    $row = mysqli_fetch_assoc($sql);
    $credential_name = sanitizeInput($row['credential_name']);
    $client_id = intval($row['credential_client_id']);

    enforceClientAccess();

    mysqli_query($mysqli,"DELETE FROM credentials WHERE credential_id = $credential_id");

    logAction("Credential", "Delete", "$session_name deleted credential $credential_name", $client_id);

    flash_alert("Credential <strong>$credential_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['bulk_assign_credential_tags'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    // Assign tags to Selected Credentials
    if (isset($_POST['credential_ids'])) {

        // Get Selected Credential Count
        $count = count($_POST['credential_ids']);

        foreach($_POST['credential_ids'] as $credential_id) {
            $credential_id = intval($credential_id);

            // Get Contact Details for Logging
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            if($_POST['bulk_remove_tags']) {
                // Delete tags if chosed to do so
                mysqli_query($mysqli, "DELETE FROM credential_tags WHERE credential_id = $credential_id");
            }

            // Add new tags
            if (isset($_POST['bulk_tags'])) {
                foreach($_POST['bulk_tags'] as $tag) {
                    $tag = intval($tag);

                    $sql = mysqli_query($mysqli,"SELECT * FROM credential_tags WHERE credential_id = $credential_id AND tag_id = $tag");
                    if (mysqli_num_rows($sql) == 0) {
                        mysqli_query($mysqli, "INSERT INTO credential_tags SET credential_id = $credential_id, tag_id = $tag");
                    }
                }
            }

            logAction("Credential", "Edit", "$session_name added tags to $credential_name", $client_id, $credential_id);

            flash_alert("Assigned tags for <strong>$count</strong> credentials");

        } // End Assign Loop

        logAction("Credential", "Bulk Edit", "$session_name added tags to $count credentials", $client_id);

    }

    redirect();

}

if (isset($_POST['bulk_favorite_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    if (isset($_POST['credential_ids'])) {

        $count = count($_POST['credential_ids']);

        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Asset Name and Client ID for logging and alert message
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE credentials SET credential_favorite = 1 WHERE credential_id = $credential_id");

            logAction("Credential", "Edit", "$session_name marked credential $credential_name a favorite", $client_id, $credential_id);

        }

        logAction("Credential", "Bulk Edit", "$session_name favorited $count credentials", $client_id);

        flash_alert("Favorited <strong>$count</strong> credential(s)");

    }

    redirect();

}

if (isset($_POST['bulk_unfavorite_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    if (isset($_POST['credential_ids'])) {

        $count = count($_POST['credential_ids']);

        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Asset Name and Client ID for logging and alert message
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE credentials SET credential_favorite = 0 WHERE credential_id = $credential_id");

            logAction("Credential", "Edit", "$session_name unfavorited credential $credential_name", $client_id, $credential_id);

        }

        logAction("Crednetial", "Bulk Edit", "$session_name unfavorited $count credentials", $client_id);

        flash_alert("Unfavorited <strong>$count</strong> credential(s)");

    }

    redirect();

}

if (isset($_POST['bulk_archive_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    if (isset($_POST['credential_ids'])) {

        // Get Selected Credential Count
        $count = count($_POST['credential_ids']);

        // Cycle through array and archive each record
        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Name and Client ID for logging and alert message
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE credentials SET credential_archived_at = NOW() WHERE credential_id = $credential_id");

            logAction("Credential", "Archive", "$session_name archived credential $credential_name", $client_id, $credential_id);
        }

        logAction("Credential", "Bulk Archive", "$session_name archived $count credentials", $client_id);

        flash_alert("Archived <strong>$count</strong> credential(s)", 'error');

    }

    redirect();

}

if (isset($_POST['bulk_restore_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    if (isset($_POST['credential_ids'])) {

        // Get Selected Credential Count
        $count = count($_POST['credential_ids']);

        // Cycle through array and restore
        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Name and Client ID for logging and alert message
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli,"UPDATE credentials SET credential_archived_at = NULL WHERE credential_id = $credential_id");

            logAction("Credential", "Restore", "$session_name restored credential $credential_name", $client_id, $credential_id);

        }

        logAction("Credential", "Bulk Restore", "$session_name restored $count credential(s)", $client_id);

        flash_alert("Restored <strong>$count</strong> credential(s)");

    }

    redirect();

}

if (isset($_POST['bulk_delete_credentials'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 3);

    if (isset($_POST['credential_ids'])) {

        // Get Selected Credential Count
        $count = count($_POST['credential_ids']);

        // Cycle through array and delete each record
        foreach ($_POST['credential_ids'] as $credential_id) {

            $credential_id = intval($credential_id);

            // Get Name and Client ID for logging and alert message
            $sql = mysqli_query($mysqli,"SELECT credential_name, credential_client_id FROM credentials WHERE credential_id = $credential_id");
            $row = mysqli_fetch_assoc($sql);
            $credential_name = sanitizeInput($row['credential_name']);
            $client_id = intval($row['credential_client_id']);

            enforceClientAccess();

            mysqli_query($mysqli, "DELETE FROM credentials WHERE credential_id = $credential_id AND credential_client_id = $client_id");

            logAction("Credential", "Delete", "$session_name deleted credential $credential_name", $client_id);

        }

        logAction("Credential", "Bulk Delete", "$session_name deleted $count credential(s)", $client_id);

        flash_alert("Deleted <strong>$count</strong> credential(s)", 'error');

    }

    redirect();

}

if (isset($_POST['export_credentials_csv'])) {

    validateCSRFToken($_POST['csrf_token']);

    // The export writes every decrypted password and OTP secret in one file, so it needs Full access, not Read.
    enforceUserPermission('module_credential', 3);

    if ($_POST['client_id']) {
        $client_id = intval($_POST['client_id']);
        $client_query = "AND credential_client_id = $client_id";
        $client_name = getFieldById('clients', $client_id, 'client_name');
        $file_name_prepend = "$client_name-";
        enforceClientAccess();
    } else {
        $client_query = '';
        $client_id = 0;
        $file_name_prepend = "$session_company_name-";
    }

    //get records from database
    $sql = mysqli_query($mysqli,"SELECT * FROM credentials LEFT JOIN clients ON client_id = credential_client_id WHERE credential_archived_at IS NULL $client_query $access_permission_query ORDER BY credential_name ASC");
    $num_rows = mysqli_num_rows($sql);

    if ($num_rows > 0) {
        $delimiter = ",";
        $enclosure = '"';
        $escape    = '\\';   // backslash
        $filename = sanitize_filename($file_name_prepend . "Credentials-" . date('Y-m-d_H-i-s') . ".csv");

        //create a file pointer
        $f = fopen('php://memory', 'w');

        //set column headers
        $fields = array('Name', 'Description', 'Username', 'Password', 'TOTP', 'URI');
        fputcsv($f, $fields, $delimiter, $enclosure, $escape);

        //output each row of the data, format line as csv and write to file pointer
        while($row = mysqli_fetch_assoc($sql)){
            $credential_username = decryptCredentialEntry($row['credential_username']);
            $credential_password = decryptCredentialEntry($row['credential_password']);
            $lineData = array($row['credential_name'], $row['credential_description'], $credential_username, $credential_password, $row['credential_otp_secret'], $row['credential_uri']);
            fputcsv($f, $lineData, $delimiter, $enclosure, $escape);
        }

        //move back to beginning of file
        fseek($f, 0);

        //set headers to download file rather than displayed
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '";');

        //output all remaining data on a file pointer
        fpassthru($f);
    }

    logAction("Credential", "Export", "$session_name exported $num_rows credential(s) to a CSV file", $client_id);

    exit;

}

if (isset($_POST["import_credentials_csv"])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_credential', 2);

    $client_id = intval($_POST['client_id']);

    enforceClientAccess();

    $error = false;

    if (!empty($_FILES["file"]["tmp_name"])) {
        $file_name = $_FILES["file"]["tmp_name"];
    } else {
        flash_alert("Please select a file to upload.", 'error');
        redirect();
    }

    //Check file is CSV
    $file_extension = strtolower(end(explode('.',$_FILES['file']['name'])));
    $allowed_file_extensions = array('csv');
    if (in_array($file_extension,$allowed_file_extensions) === false){
        $error = true;
        flash_alert("Bad file extension", 'error');
    }

    //Check file isn't empty
    elseif ($_FILES["file"]["size"] < 1){
        $error = true;
        flash_alert("Bad file size (empty?)", 'error');
    }

    //(Else)Check column count
    $f = fopen($file_name, "r");
    $f_columns = fgetcsv($f, 1000, ",");
    if (!$error & count($f_columns) != 6) {
        $error = true;
        flash_alert("Bad column count.", 'error');
    }

    //Else, parse the file
    if (!$error){
        $file = fopen($file_name, "r");
        fgetcsv($file, 1000, ","); // Skip first line
        $row_count = 0;
        $duplicate_count = 0;
        while(($column = fgetcsv($file, 1000, ",")) !== false){
            $duplicate_detect = 0;
            // Name
            if (isset($column[0])) {
                $name = sanitizeInput($column[0]);
                if (mysqli_num_rows(mysqli_query($mysqli,"SELECT * FROM credentials WHERE credential_name = '$name' AND credential_client_id = $client_id")) > 0){
                    $duplicate_detect = 1;
                }
            }
            // Desc
            if (isset($column[1])) {
                $description = sanitizeInput($column[1]);
            }
            // User
            if (isset($column[2])) {
                $username = sanitizeInput(encryptCredentialEntry($column[2]));
            }
            // Pass
            if (isset($column[3])) {
                $password = sanitizeInput(encryptCredentialEntry($column[3]));
            }
            // OTP
            if (isset($column[4])) {
                $totp = sanitizeInput($column[4]);
            }
            // URL
            if (isset($column[4])) {
                $uri = sanitizeInput($column[5]);
            }

            // Check if duplicate was detected
            if ($duplicate_detect == 0){
                //Add
                mysqli_query($mysqli,"INSERT INTO credentials SET credential_name = '$name', credential_description = '$description', credential_uri = '$uri', credential_username = '$username', credential_password = '$password', credential_otp_secret = '$totp', credential_client_id = $client_id");
                $row_count = $row_count + 1;
            } else {
                $duplicate_count = $duplicate_count + 1;
            }
        }
        fclose($file);

        logAction("Credential", "Import", "$session_name imported $row_count credential(s) via CSV file. $duplicate_count duplicate(s) found and not imported", $client_id);

        flash_alert("<strong>$row_count</strong> credential(s) imported, <strong>$duplicate_count</strong> duplicate(s) detected and not imported", 'warning');

        redirect();
    }
    //Check for any errors, if there are notify user and redirect
    if ($error) {
        redirect();
    }

}

if (isset($_GET['download_credentials_csv_template'])) {

    $delimiter = ",";
    $enclosure = '"';
    $escape    = '\\';
    $filename = "Credentials-Template.csv";

    //create a file pointer
    $f = fopen('php://memory', 'w');

    //set column headers
    $fields = array('Name', 'Description', 'Username', 'Password', 'TOTP', 'URI');
    fputcsv($f, $fields, $delimiter, $enclosure, $escape);

    //move back to beginning of file
    fseek($f, 0);

    //set headers to download file rather than displayed
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '";');

    //output all remaining data on a file pointer
    fpassthru($f);
    exit;

}
