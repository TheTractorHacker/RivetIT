<?php

// TinyMCE image upload endpoint for Knowledge Base article content.
// Expects a multipart POST with a single "file" field, returns
// {"location": "..."} on success or {"error": {"message": "..."}} on failure.

require_once "includes/inc_all.php";

enforceUserPermission('module_kb');

header('Content-Type: application/json');

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

$upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/uploads/kb/";
mkdirMissing($_SERVER['DOCUMENT_ROOT'] . "/uploads/");
mkdirMissing($upload_dir);

move_uploaded_file($_FILES['file']['tmp_name'], $upload_dir . $ref_name);

echo json_encode(['location' => "/uploads/kb/$ref_name"]);
