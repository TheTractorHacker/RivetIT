<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Directory\PersonImportService;

if (isset($_POST['preview_people_import'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        flash_alert('No file uploaded or upload failed.', 'error');
        redirect('people_import.php');
    }

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        flash_alert('Only .csv files are supported.', 'error');
        redirect('people_import.php');
    }

    $service = new PersonImportService($mysqli);
    $result = $service->preview($_FILES['file']['tmp_name']);

    if ($result['headerError']) {
        flash_alert($result['headerError'], 'error');
        redirect('people_import.php');
    }

    $_SESSION['people_import_preview'] = [
        'filename' => $_FILES['file']['name'],
        'rows' => $result['rows'],
    ];

    logAction("Settings", "Edit", "$session_name previewed a people import (" . count($result['rows']) . " row(s)) from {$_FILES['file']['name']}");

    redirect('people_import.php');
}

if (isset($_POST['approve_people_import'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $preview = $_SESSION['people_import_preview'] ?? null;
    if (!$preview) {
        flash_alert('No pending import to approve.', 'error');
        redirect('people_import.php');
    }

    $approvedRows = array_filter($preview['rows'], fn($r) => $r['action'] !== 'error');
    $skippedCount = count($preview['rows']) - count($approvedRows);

    $service = new PersonImportService($mysqli);
    $summary = $service->commit($approvedRows, $session_user_id);

    $resultsJson = mysqli_real_escape_string($mysqli, json_encode($summary));
    $filenameSql = mysqli_real_escape_string($mysqli, $preview['filename']);
    mysqli_query($mysqli, "INSERT INTO people_import_runs SET
        imported_by_user_id = $session_user_id,
        original_filename = '$filenameSql',
        row_count = " . count($preview['rows']) . ",
        created_count = {$summary['created']},
        updated_count = {$summary['updated']},
        skipped_count = $skippedCount,
        status = 'approved',
        results_json = '$resultsJson'
    ");

    \ITFlow\Audit\AuditService::record(
        'people.import_approved',
        $session_user_id,
        'people_import_run',
        mysqli_insert_id($mysqli),
        'approved',
        "$session_name imported people: {$summary['created']} created, {$summary['updated']} updated, $skippedCount skipped",
        $summary
    );

    logAction("Settings", "Edit", "$session_name approved a people import: {$summary['created']} created, {$summary['updated']} updated, $skippedCount skipped");

    unset($_SESSION['people_import_preview']);

    flash_alert("Import complete: {$summary['created']} created, {$summary['updated']} updated, $skippedCount skipped");

    redirect('people_import.php');
}

if (isset($_POST['cancel_people_import'])) {
    validateCSRFToken($_POST['csrf_token']);
    unset($_SESSION['people_import_preview']);
    redirect('people_import.php');
}

if (isset($_GET['download_people_import_template'])) {
    validateCSRFToken($_GET['csrf_token']);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="people_import_template.csv"');
    echo "employee_id,name,email,job_title,department,site,manager_email,phone,mobile,start_date,employee_type,employment_status,work_arrangement\n";
    echo "1001,John Smith,john@example.com,Engineer,Engineering,Fort Smith,jane@example.com,4795551111,4795552222,2026-09-14,employee,active,onsite\n";
    exit;
}
