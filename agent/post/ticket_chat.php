<?php

/*
 * ITFlow - Live chat messages on tickets
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/redis_functions.php';

if (isset($_POST['add_ticket_chat_message'])) {

    validateCSRFToken($_POST['csrf_token']);

    enforceUserPermission('module_support', 2);

    $ticket_id = intval($_POST['ticket_id']);
    $message = trim($_POST['message'] ?? '');

    // Ticket client access overide - mirrors agent/ticket.php / agent/sse_ticket_stream.php
    $access_permission_query_overide = '';
    if ($client_access_string) {
        $access_permission_query_overide = "AND ticket_client_id IN (0,$client_access_string)";
    }

    $sql_ticket_check = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id $access_permission_query_overide LIMIT 1");

    if (!$config_module_enable_live_chat || $message === '' || !$ticket_id || mysqli_num_rows($sql_ticket_check) == 0) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $message_escaped = mysqli_real_escape_string($mysqli, $message);

    mysqli_query($mysqli, "INSERT INTO ticket_chat_messages SET ticket_id = $ticket_id, sender_type = 'agent', sender_id = $session_user_id, message = '$message_escaped'");

    $chat_id = mysqli_insert_id($mysqli);

    publishTicketEvent($ticket_id, 'chat', [
        'chat_id' => $chat_id,
        'message' => $message,
        'sender_type' => 'agent',
        'sender_id' => $session_user_id,
        'sender_name' => $session_name,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    echo json_encode(['ok' => true, 'id' => $chat_id]);
    exit;
}
