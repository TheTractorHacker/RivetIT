<?php

if (isset($_POST['add_contract'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $client_id = intval($_POST['contract_client_id']);
    $name = sanitizeInput($_POST['contract_name']);
    $type = sanitizeInput($_POST['contract_type']);
    $status = sanitizeInput($_POST['contract_status']);
    $value = is_numeric($_POST['contract_value'] ?? '') ? floatval($_POST['contract_value']) : 'NULL';
    $freq = sanitizeInput($_POST['contract_renewal_frequency'] ?? '');
    $start = sanitizeInput($_POST['contract_start_date']);
    $end = sanitizeInput($_POST['contract_end_date']);
    $renewal = sanitizeInput($_POST['contract_renewal_date']);
    $notes = sanitizeInput($_POST['contract_details']);
    $sla_lr = is_numeric($_POST['sla_low_response'] ?? '') ? intval($_POST['sla_low_response']) : 'NULL';
    $sla_lres = is_numeric($_POST['sla_low_resolution'] ?? '') ? intval($_POST['sla_low_resolution']) : 'NULL';
    $sla_mr = is_numeric($_POST['sla_medium_response'] ?? '') ? intval($_POST['sla_medium_response']) : 'NULL';
    $sla_mres = is_numeric($_POST['sla_medium_resolution'] ?? '') ? intval($_POST['sla_medium_resolution']) : 'NULL';
    $sla_hr = is_numeric($_POST['sla_high_response'] ?? '') ? intval($_POST['sla_high_response']) : 'NULL';
    $sla_hres = is_numeric($_POST['sla_high_resolution'] ?? '') ? intval($_POST['sla_high_resolution']) : 'NULL';

    enforceClientAccess();

    $start_sql = $start ? "'$start'" : 'NULL';
    $end_sql = $end ? "'$end'" : 'NULL';
    $renewal_sql = $renewal ? "'$renewal'" : 'NULL';
    $value_sql = is_numeric($value) ? $value : 'NULL';
    $freq_sql = $freq ? "'$freq'" : 'NULL';

    mysqli_query($mysqli, "INSERT INTO contracts SET contract_client_id = $client_id, contract_name = '$name', contract_type = '$type', contract_status = '$status', contract_value = $value_sql, contract_renewal_frequency = $freq_sql, contract_start_date = $start_sql, contract_end_date = $end_sql, contract_renewal_date = $renewal_sql, contract_details = '$notes', contract_sla_low_response_time = $sla_lr, contract_sla_low_resolution_time = $sla_lres, contract_sla_medium_response_time = $sla_mr, contract_sla_medium_resolution_time = $sla_mres, contract_sla_high_response_time = $sla_hr, contract_sla_high_resolution_time = $sla_hres, contract_created_by = $session_user_id");

    logAction("Contract", "Add", "Added contract $name", $client_id);
    flash_alert("Contract <strong>$name</strong> created.");
    redirect();
}

if (isset($_POST['edit_contract'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_POST['contract_id']);

    // Confirm access to the contract's current client before allowing any edit
    enforceClientAccess(intval(getFieldById('contracts', $contract_id, 'contract_client_id')));

    $client_id = intval($_POST['contract_client_id']);
    $name = sanitizeInput($_POST['contract_name']);
    $type = sanitizeInput($_POST['contract_type']);
    $status = sanitizeInput($_POST['contract_status']);
    $freq = sanitizeInput($_POST['contract_renewal_frequency'] ?? '');
    $start = sanitizeInput($_POST['contract_start_date']);
    $end = sanitizeInput($_POST['contract_end_date']);
    $renewal = sanitizeInput($_POST['contract_renewal_date']);
    $notes = sanitizeInput($_POST['contract_details']);
    $sla_lr = is_numeric($_POST['sla_low_response'] ?? '') ? intval($_POST['sla_low_response']) : 'NULL';
    $sla_lres = is_numeric($_POST['sla_low_resolution'] ?? '') ? intval($_POST['sla_low_resolution']) : 'NULL';
    $sla_mr = is_numeric($_POST['sla_medium_response'] ?? '') ? intval($_POST['sla_medium_response']) : 'NULL';
    $sla_mres = is_numeric($_POST['sla_medium_resolution'] ?? '') ? intval($_POST['sla_medium_resolution']) : 'NULL';
    $sla_hr = is_numeric($_POST['sla_high_response'] ?? '') ? intval($_POST['sla_high_response']) : 'NULL';
    $sla_hres = is_numeric($_POST['sla_high_resolution'] ?? '') ? intval($_POST['sla_high_resolution']) : 'NULL';

    // The form may also reassign the contract to a different client - confirm
    // access to that destination client too.
    enforceClientAccess();

    $start_sql = $start ? "'$start'" : 'NULL';
    $end_sql = $end ? "'$end'" : 'NULL';
    $renewal_sql = $renewal ? "'$renewal'" : 'NULL';
    $value_sql = is_numeric($_POST['contract_value'] ?? '') ? floatval($_POST['contract_value']) : 'NULL';
    $freq_sql = $freq ? "'$freq'" : 'NULL';

    mysqli_query($mysqli, "UPDATE contracts SET contract_name = '$name', contract_type = '$type', contract_status = '$status', contract_value = $value_sql, contract_renewal_frequency = $freq_sql, contract_start_date = $start_sql, contract_end_date = $end_sql, contract_renewal_date = $renewal_sql, contract_details = '$notes', contract_sla_low_response_time = $sla_lr, contract_sla_low_resolution_time = $sla_lres, contract_sla_medium_response_time = $sla_mr, contract_sla_medium_resolution_time = $sla_mres, contract_sla_high_response_time = $sla_hr, contract_sla_high_resolution_time = $sla_hres WHERE contract_id = $contract_id");

    logAction("Contract", "Edit", "Updated contract $name", $client_id);
    flash_alert("Contract updated.");
    redirect();
}

if (isset($_GET['delete_contract'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_GET['delete_contract']);

    // Derive the client from the contract itself, not the (attacker-controlled) URL
    $client_id = intval(getFieldById('contracts', $contract_id, 'contract_client_id'));
    enforceClientAccess();

    mysqli_query($mysqli, "UPDATE contracts SET contract_archived_at = NOW() WHERE contract_id = $contract_id");
    logAction("Contract", "Delete", "Deleted contract #$contract_id", $client_id);
    flash_alert("Contract deleted.");
    redirect();
}

// ── Upload contract document ──────────────────────────────────────────────────
if (isset($_POST['upload_contract_document'])) {
    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_support', 2);

    $contract_id = intval($_POST['contract_id']);
    $client_id   = intval(getFieldById('contracts', $contract_id, 'contract_client_id'));
    if ($client_id) enforceClientAccess();

    if (empty($_FILES['contract_document']['tmp_name'])) {
        flash_alert('No file selected.', 'error'); redirect();
    }

    $file      = $_FILES['contract_document'];
    $max_bytes = 20 * 1048576; // 20 MB

    if ($file['size'] > $max_bytes) {
        flash_alert('File too large (max 20 MB).', 'error'); redirect();
    }

    // Allowed MIME types
    $allowed_mime = [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/png', 'image/jpeg', 'text/plain',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed_mime)) {
        flash_alert('File type not allowed.', 'error'); redirect();
    }

    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/$contract_id";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0750, true);

    // Safe stored filename: doc_id will come from insert, use timestamp for now
    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $stored   = time() . '_' . bin2hex(random_bytes(8)) . '.' . preg_replace('/[^a-z0-9]/i', '', $ext);
    $dest     = "$upload_dir/$stored";

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        flash_alert('File upload failed.', 'error'); redirect();
    }

    $orig_safe = sanitizeInput($file['name']);
    $mime_safe = sanitizeInput($mime);
    $size      = intval($file['size']);

    mysqli_query($mysqli, "INSERT INTO contract_documents SET
        doc_contract_id  = $contract_id,
        doc_filename     = '$stored',
        doc_original_name = '$orig_safe',
        doc_mime_type    = '$mime_safe',
        doc_size         = $size,
        doc_uploaded_by  = $session_user_id
    ");

    logAction('Contract', 'Upload', "$session_name uploaded $orig_safe to contract #$contract_id", $client_id);
    flash_alert("Document <strong>$orig_safe</strong> uploaded.");
    redirect();
}

// ── Serve (download) contract document ───────────────────────────────────────
if (isset($_GET['serve_contract_document'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support');

    $doc_id = intval($_GET['serve_contract_document']);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT d.*, c.contract_client_id FROM contract_documents d
         JOIN contracts c ON d.doc_contract_id = c.contract_id
         WHERE d.doc_id = $doc_id LIMIT 1"
    ));
    if (!$doc) { flash_alert('Document not found.', 'error'); redirect(); }

    $client_id = intval($doc['contract_client_id']);
    if ($client_id) enforceClientAccess();

    $path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/{$doc['doc_contract_id']}/{$doc['doc_filename']}";
    if (!is_file($path)) { flash_alert('File not found on server.', 'error'); redirect(); }

    $safe_name = preg_replace('/[^\w.\-]/', '_', $doc['doc_original_name']);
    header('Content-Type: ' . $doc['doc_mime_type']);
    header('Content-Disposition: inline; filename="' . $safe_name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private');
    readfile($path);
    exit;
}

// ── Delete contract document ──────────────────────────────────────────────────
if (isset($_GET['delete_contract_document'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $doc_id = intval($_GET['delete_contract_document']);
    $doc = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT d.*, c.contract_client_id FROM contract_documents d
         JOIN contracts c ON d.doc_contract_id = c.contract_id
         WHERE d.doc_id = $doc_id LIMIT 1"
    ));
    if (!$doc) { redirect(); }

    $client_id = intval($doc['contract_client_id']);
    if ($client_id) enforceClientAccess();

    $path = $_SERVER['DOCUMENT_ROOT'] . "/uploads/contracts/{$doc['doc_contract_id']}/{$doc['doc_filename']}";
    if (is_file($path)) @unlink($path);

    mysqli_query($mysqli, "DELETE FROM contract_documents WHERE doc_id = $doc_id");
    $orig_safe = sanitizeInput($doc['doc_original_name']);
    logAction('Contract', 'Delete', "$session_name deleted document $orig_safe from contract #{$doc['doc_contract_id']}", $client_id);
    flash_alert("Document <strong>$orig_safe</strong> deleted.", 'error');
    redirect();
}

