<?php

/*
 * ITFlow - GET/POST request handler for network drives
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_network_drive'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once 'network_drive_model.php';

    $client_id = intval($_POST['client_id']);

    enforceClientAccess();

    mysqli_query($mysqli, "INSERT INTO network_drives SET network_drive_name = '$name', network_drive_letter = '$letter', network_drive_path = '$path', network_drive_purpose = '$purpose', network_drive_notes = '$notes', network_drive_client_id = $client_id, network_drive_created_by = $session_user_id, network_drive_updated_by = $session_user_id");

    $network_drive_id = mysqli_insert_id($mysqli);

    logAction("Network Drive", "Create", "$session_name created network drive $name", $client_id, $network_drive_id);

    flash_alert("Network Drive <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_network_drive'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'network_drive_model.php';

    $network_drive_id = intval($_POST['network_drive_id']);

    // Get Client ID
    $client_id = intval(getFieldById('network_drives', $network_drive_id, 'network_drive_client_id'));

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE network_drives SET network_drive_name = '$name', network_drive_letter = '$letter', network_drive_path = '$path', network_drive_purpose = '$purpose', network_drive_notes = '$notes', network_drive_updated_by = $session_user_id WHERE network_drive_id = $network_drive_id");

    logAction("Network Drive", "Edit", "$session_name edited network drive $name", $client_id, $network_drive_id);

    flash_alert("Network Drive <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_network_drive'])) {

    validateCSRFToken($_GET['csrf_token']);

    $network_drive_id = intval($_GET['archive_network_drive']);

    $sql = mysqli_query($mysqli, "SELECT * FROM network_drives WHERE network_drive_id = $network_drive_id");
    $row = mysqli_fetch_assoc($sql);
    $network_drive_name = sanitizeInput($row['network_drive_name']);
    $client_id = intval($row['network_drive_client_id']);

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE network_drives SET network_drive_archived_at = NOW(), network_drive_updated_by = $session_user_id WHERE network_drive_id = $network_drive_id");

    logAction("Network Drive", "Archive", "$session_name archived network drive $network_drive_name", $client_id, $network_drive_id);

    flash_alert("Network Drive <strong>$network_drive_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_network_drive'])) {

    validateCSRFToken($_GET['csrf_token']);

    $network_drive_id = intval($_GET['restore_network_drive']);

    $sql = mysqli_query($mysqli, "SELECT network_drive_name, network_drive_client_id FROM network_drives WHERE network_drive_id = $network_drive_id");
    $row = mysqli_fetch_assoc($sql);
    $network_drive_name = sanitizeInput($row['network_drive_name']);
    $client_id = intval($row['network_drive_client_id']);

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE network_drives SET network_drive_archived_at = NULL, network_drive_updated_by = $session_user_id WHERE network_drive_id = $network_drive_id");

    logAction("Network Drive", "Restore", "$session_name restored network drive $network_drive_name", $client_id, $network_drive_id);

    flash_alert("Network Drive <strong>$network_drive_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_network_drive'])) {

    validateCSRFToken($_GET['csrf_token']);

    $network_drive_id = intval($_GET['delete_network_drive']);

    $sql = mysqli_query($mysqli, "SELECT * FROM network_drives WHERE network_drive_id = $network_drive_id");
    $row = mysqli_fetch_assoc($sql);
    $network_drive_name = sanitizeInput($row['network_drive_name']);
    $client_id = intval($row['network_drive_client_id']);

    enforceUserPermission('module_support', 3);
    enforceClientAccess();

    mysqli_query($mysqli, "DELETE FROM network_drives WHERE network_drive_id = $network_drive_id");

    logAction("Network Drive", "Delete", "$session_name deleted network drive $network_drive_name", $client_id);

    flash_alert("Network Drive <strong>$network_drive_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['bulk_archive_network_drives'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['network_drive_ids'])) {

        $count = count($_POST['network_drive_ids']);

        foreach ($_POST['network_drive_ids'] as $network_drive_id) {

            $network_drive_id = intval($network_drive_id);

            $sql = mysqli_query($mysqli, "SELECT network_drive_name, network_drive_client_id FROM network_drives WHERE network_drive_id = $network_drive_id");
            $row = mysqli_fetch_assoc($sql);
            $network_drive_name = sanitizeInput($row['network_drive_name']);
            $client_id = intval($row['network_drive_client_id']);

            enforceUserPermission('module_support', 2);
            enforceClientAccess();

            mysqli_query($mysqli, "UPDATE network_drives SET network_drive_archived_at = NOW(), network_drive_updated_by = $session_user_id WHERE network_drive_id = $network_drive_id");

            logAction("Network Drive", "Archive", "$session_name archived network drive $network_drive_name", $client_id, $network_drive_id);
        }

        logAction("Network Drive", "Bulk Archive", "$session_name archived $count network drive(s)");

        flash_alert("Archived <strong>$count</strong> network drive(s)", 'error');

    }

    redirect();

}

if (isset($_POST['bulk_restore_network_drives'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['network_drive_ids'])) {

        $count = count($_POST['network_drive_ids']);

        foreach ($_POST['network_drive_ids'] as $network_drive_id) {

            $network_drive_id = intval($network_drive_id);

            $sql = mysqli_query($mysqli, "SELECT network_drive_name, network_drive_client_id FROM network_drives WHERE network_drive_id = $network_drive_id");
            $row = mysqli_fetch_assoc($sql);
            $network_drive_name = sanitizeInput($row['network_drive_name']);
            $client_id = intval($row['network_drive_client_id']);

            enforceUserPermission('module_support', 2);
            enforceClientAccess();

            mysqli_query($mysqli, "UPDATE network_drives SET network_drive_archived_at = NULL, network_drive_updated_by = $session_user_id WHERE network_drive_id = $network_drive_id");

            logAction("Network Drive", "Restore", "$session_name restored network drive $network_drive_name", $client_id, $network_drive_id);
        }

        logAction("Network Drive", "Bulk Restore", "$session_name restored $count network drive(s)");

        flash_alert("Restored <strong>$count</strong> network drive(s)");

    }

    redirect();

}

if (isset($_POST['bulk_delete_network_drives'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['network_drive_ids'])) {

        $count = count($_POST['network_drive_ids']);

        foreach ($_POST['network_drive_ids'] as $network_drive_id) {

            $network_drive_id = intval($network_drive_id);

            $sql = mysqli_query($mysqli, "SELECT network_drive_name, network_drive_client_id FROM network_drives WHERE network_drive_id = $network_drive_id");
            $row = mysqli_fetch_assoc($sql);
            $network_drive_name = sanitizeInput($row['network_drive_name']);
            $client_id = intval($row['network_drive_client_id']);

            enforceUserPermission('module_support', 3);
            enforceClientAccess();

            mysqli_query($mysqli, "DELETE FROM network_drives WHERE network_drive_id = $network_drive_id");

            logAction("Network Drive", "Delete", "$session_name deleted network drive $network_drive_name", $client_id);
        }

        logAction("Network Drive", "Bulk Delete", "$session_name deleted $count network drive(s)");

        flash_alert("Deleted <strong>$count</strong> network drive(s)", 'error');

    }

    redirect();

}
