<?php

/*
 * ITFlow - GET/POST request handler for printers
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_printer'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    require_once 'printer_model.php';

    $client_id = intval($_POST['client_id']);

    enforceClientAccess();

    mysqli_query($mysqli, "INSERT INTO printers SET printer_name = '$name', printer_ip_address = '$ip_address', printer_location_id = $location_id, printer_physical_location = '$physical_location', printer_model = '$model', printer_serial_number = '$serial_number', printer_mac_address = '$mac_address', printer_notes = '$notes', printer_client_id = $client_id, printer_created_by = $session_user_id, printer_updated_by = $session_user_id");

    $printer_id = mysqli_insert_id($mysqli);

    logAction("Printer", "Create", "$session_name created printer $name", $client_id, $printer_id);

    flash_alert("Printer <strong>$name</strong> created");

    redirect();

}

if (isset($_POST['edit_printer'])) {

    validateCSRFToken($_POST['csrf_token']);

    require_once 'printer_model.php';

    $printer_id = intval($_POST['printer_id']);

    // Get Client ID
    $client_id = intval(getFieldById('printers', $printer_id, 'printer_client_id'));

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE printers SET printer_name = '$name', printer_ip_address = '$ip_address', printer_location_id = $location_id, printer_physical_location = '$physical_location', printer_model = '$model', printer_serial_number = '$serial_number', printer_mac_address = '$mac_address', printer_notes = '$notes', printer_updated_by = $session_user_id WHERE printer_id = $printer_id");

    logAction("Printer", "Edit", "$session_name edited printer $name", $client_id, $printer_id);

    flash_alert("Printer <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['archive_printer'])) {

    validateCSRFToken($_GET['csrf_token']);

    $printer_id = intval($_GET['archive_printer']);

    $sql = mysqli_query($mysqli, "SELECT * FROM printers WHERE printer_id = $printer_id");
    $row = mysqli_fetch_assoc($sql);
    $printer_name = sanitizeInput($row['printer_name']);
    $client_id = intval($row['printer_client_id']);

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE printers SET printer_archived_at = NOW(), printer_updated_by = $session_user_id WHERE printer_id = $printer_id");

    logAction("Printer", "Archive", "$session_name archived printer $printer_name", $client_id, $printer_id);

    flash_alert("Printer <strong>$printer_name</strong> archived", 'error');

    redirect();

}

if (isset($_GET['restore_printer'])) {

    validateCSRFToken($_GET['csrf_token']);

    $printer_id = intval($_GET['restore_printer']);

    $sql = mysqli_query($mysqli, "SELECT printer_name, printer_client_id FROM printers WHERE printer_id = $printer_id");
    $row = mysqli_fetch_assoc($sql);
    $printer_name = sanitizeInput($row['printer_name']);
    $client_id = intval($row['printer_client_id']);

    enforceUserPermission('module_support', 2);
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE printers SET printer_archived_at = NULL, printer_updated_by = $session_user_id WHERE printer_id = $printer_id");

    logAction("Printer", "Restore", "$session_name restored printer $printer_name", $client_id, $printer_id);

    flash_alert("Printer <strong>$printer_name</strong> restored");

    redirect();

}

if (isset($_GET['delete_printer'])) {

    validateCSRFToken($_GET['csrf_token']);

    $printer_id = intval($_GET['delete_printer']);

    $sql = mysqli_query($mysqli, "SELECT * FROM printers WHERE printer_id = $printer_id");
    $row = mysqli_fetch_assoc($sql);
    $printer_name = sanitizeInput($row['printer_name']);
    $client_id = intval($row['printer_client_id']);

    enforceUserPermission('module_support', 3);
    enforceClientAccess();

    mysqli_query($mysqli, "DELETE FROM printers WHERE printer_id = $printer_id");

    logAction("Printer", "Delete", "$session_name deleted printer $printer_name", $client_id);

    flash_alert("Printer <strong>$printer_name</strong> deleted", 'error');

    redirect();

}

if (isset($_POST['bulk_archive_printers'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['printer_ids'])) {

        $count = count($_POST['printer_ids']);

        foreach ($_POST['printer_ids'] as $printer_id) {

            $printer_id = intval($printer_id);

            $sql = mysqli_query($mysqli, "SELECT printer_name, printer_client_id FROM printers WHERE printer_id = $printer_id");
            $row = mysqli_fetch_assoc($sql);
            $printer_name = sanitizeInput($row['printer_name']);
            $client_id = intval($row['printer_client_id']);

            enforceUserPermission('module_support', 2);
            enforceClientAccess();

            mysqli_query($mysqli, "UPDATE printers SET printer_archived_at = NOW(), printer_updated_by = $session_user_id WHERE printer_id = $printer_id");

            logAction("Printer", "Archive", "$session_name archived printer $printer_name", $client_id, $printer_id);
        }

        logAction("Printer", "Bulk Archive", "$session_name archived $count printer(s)");

        flash_alert("Archived <strong>$count</strong> printer(s)", 'error');

    }

    redirect();

}

if (isset($_POST['bulk_restore_printers'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['printer_ids'])) {

        $count = count($_POST['printer_ids']);

        foreach ($_POST['printer_ids'] as $printer_id) {

            $printer_id = intval($printer_id);

            $sql = mysqli_query($mysqli, "SELECT printer_name, printer_client_id FROM printers WHERE printer_id = $printer_id");
            $row = mysqli_fetch_assoc($sql);
            $printer_name = sanitizeInput($row['printer_name']);
            $client_id = intval($row['printer_client_id']);

            enforceUserPermission('module_support', 2);
            enforceClientAccess();

            mysqli_query($mysqli, "UPDATE printers SET printer_archived_at = NULL, printer_updated_by = $session_user_id WHERE printer_id = $printer_id");

            logAction("Printer", "Restore", "$session_name restored printer $printer_name", $client_id, $printer_id);
        }

        logAction("Printer", "Bulk Restore", "$session_name restored $count printer(s)");

        flash_alert("Restored <strong>$count</strong> printer(s)");

    }

    redirect();

}

if (isset($_POST['bulk_delete_printers'])) {

    validateCSRFToken($_POST['csrf_token']);

    if (isset($_POST['printer_ids'])) {

        $count = count($_POST['printer_ids']);

        foreach ($_POST['printer_ids'] as $printer_id) {

            $printer_id = intval($printer_id);

            $sql = mysqli_query($mysqli, "SELECT printer_name, printer_client_id FROM printers WHERE printer_id = $printer_id");
            $row = mysqli_fetch_assoc($sql);
            $printer_name = sanitizeInput($row['printer_name']);
            $client_id = intval($row['printer_client_id']);

            enforceUserPermission('module_support', 3);
            enforceClientAccess();

            mysqli_query($mysqli, "DELETE FROM printers WHERE printer_id = $printer_id");

            logAction("Printer", "Delete", "$session_name deleted printer $printer_name", $client_id);
        }

        logAction("Printer", "Bulk Delete", "$session_name deleted $count printer(s)");

        flash_alert("Deleted <strong>$count</strong> printer(s)", 'error');

    }

    redirect();

}
