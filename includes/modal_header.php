<?php

// Every modal response must be pure JSON (see modal_footer.php). Buffer the
// bootstrap chain and discard whatever it prints (a stray PHP notice/warning
// would otherwise land ahead of the JSON and break every modal at once - this
// happened for real with a NULL company_locale, see git history) so only a
// genuine fatal error here can still break a modal response.
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
ob_end_clean();

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

// Every agent pop-up (agent/modals/*, /modals/*) also needs its module: see the map in
// includes/modal_permissions.php (roles audit 2026-09-26, P1d). Same SCRIPT_NAME rule as above - the
// requested pop-up, not this include. Denied: HTTP 403 + {"ok":false,"error":...}. The pop-up's own checks
// still run after this one.
require_once __DIR__ . '/modal_permissions.php';
itflow_modal_check($_SERVER['SCRIPT_NAME'] ?? '');

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// IT-WEB-1: per-response script token. js/ajax_modal.js re-runs a pop-up's <script> elements (innerHTML-inserted scripts are inert)
// with the page CSP nonce, and ONLY those stamped with this response's token, which it reads from the X-Rivet-Modal-Token header.
// A static file (an upload) or any other same-origin response cannot carry the header, so it can never get its scripts executed.
// The stamping happens in an output callback so every pop-up that ends in json_encode(['content' => ...]) is covered, whether it
// uses modal_footer.php or not; any data-modal-script the content already carried is removed first.
$GLOBALS['rivet_modal_script_token'] = bin2hex(random_bytes(16));
header('X-Rivet-Modal-Token: ' . $GLOBALS['rivet_modal_script_token']);
require_once __DIR__ . '/modal_script_token.php';
ob_start(static fn (string $out): string => rivet_modal_stamp_scripts($out, $GLOBALS['rivet_modal_script_token']));
