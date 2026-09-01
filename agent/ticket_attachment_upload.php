<?php

// TinyMCE image upload endpoint for the ticket reply/note editor - lets a tech
// paste a screenshot directly into a note and have it embedded inline. Expects
// a multipart POST with "file", "ticket_id" and "csrf_token" fields, and returns
// {"location": "..."} on success or {"error": {"message": "..."}} on failure.
// Deliberately mirrors agent/ajax.php's lean require chain (not includes/inc_all.php,
// which renders the full page shell/nav and would corrupt this JSON response).

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');

enforceUserPermission('module_support', 2);
validateCSRFToken($_POST['csrf_token'] ?? null);

$ticket_id = intval($_POST['ticket_id'] ?? 0);

$ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));

if (!$ticket_row) {
    http_response_code(404);
    echo json_encode(['error' => ['message' => 'Ticket not found']]);
    exit;
}

$client_id = intval($ticket_row['ticket_client_id'] ?? 0);
if ($client_id) {
    enforceClientAccess($client_id);
}

$allowed = ['jpg', 'jpeg', 'gif', 'png', 'webp'];

if (empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'No file uploaded']]);
    exit;
}

$ref_name = checkFileUpload($_FILES['file'], $allowed);

if (!is_string($ref_name) || !preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $ref_name)) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'Invalid or disallowed file']]);
    exit;
}

$upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/tickets/$ticket_id/";
mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/tickets/");
mkdirMissing($upload_dir);

move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $ref_name);

echo json_encode(['location' => "/uploads/tickets/$ticket_id/$ref_name"]);
