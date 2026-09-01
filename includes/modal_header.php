<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

// check_login.php only verifies the session is logged in (any role) - admin/modals/*.php
// files are meant to be admin-only (mirroring admin/includes/inc_all_admin.php's own gate),
// but unlike full admin pages they don't go through that include, so a logged-in non-admin
// agent could otherwise load them directly and read admin-only config/secrets. Gate on the
// actually-requested script's path (not this included file's own path) so agent/modals/*.php
// is unaffected.
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/modals/') !== false && (!isset($session_is_admin) || !$session_is_admin)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Your role does not have admin access.']);
    exit;
}

header('Content-Type: application/json');

// Check for the 'id' parameter
//if (!isset($_GET['id'])) {
//    echo json_encode(['error' => 'ID missing.']);
//    exit;
//}
