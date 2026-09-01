<?php

/*
 * ITFlow - SSE wrapper for live ticket updates (agent portal)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/redis_functions.php';

enforceUserPermission('module_support');

$ticket_id = intval($_GET['ticket_id'] ?? 0);

// Ticket client access overide - mirrors agent/ticket.php
$access_permission_query_overide = '';
if ($client_access_string) {
    $access_permission_query_overide = "AND ticket_client_id IN (0,$client_access_string)";
}

$sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id $access_permission_query_overide LIMIT 1");

if (!$ticket_id || mysqli_num_rows($sql) == 0) {
    http_response_code(404);
    exit;
}

// Release the session file lock before entering the long-lived SSE loop below -
// otherwise every other request from this browser (other tabs, navigation) blocks
// on session_start() for up to $max_runtime seconds while this connection is open.
session_write_close();

require $_SERVER['DOCUMENT_ROOT'] . '/includes/sse_ticket_stream.php';
