<?php
// GET    /api/v1/tickets              list
// GET    /api/v1/tickets/{id}         detail
// POST   /api/v1/tickets/{id}/reply   add reply
// POST   /api/v1/tickets/{id}/time    log time
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

$uid = $api_user_id;

// Gate on the support module permission, mirroring every sibling endpoint
// (assets.php, contacts.php, clients.php, credentials.php, invoices.php, etc.) -
// without this, any authenticated API caller could read/write all ticket data
// regardless of their role's module_support permission level.
api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

// For per-ticket operations, enforce client-scope restrictions.
// Agents with no user_client_permissions rows have unrestricted access.
// Agents with rows may only access tickets whose client is in their permitted set.
if ($id !== null) {
    $ticket_access_check = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.ticket_id FROM tickets t
         WHERE t.ticket_id = $id
           AND " . api_client_scope_sql('t.ticket_client_id') . "
         LIMIT 1"
    ));
    if (!$ticket_access_check) api_error(403, 'Access denied');
}

// Saves uploaded files (multipart field "files[]" for multiple, or "file" for a single
// upload) into uploads/tickets/{ticket_id}/ and records them in ticket_attachments --
// the same table/storage the agent UI and client portal use.
function api_save_ticket_attachments($mysqli, int $ticket_id, ?int $reply_id, string $document_root): array {
    $allowed = ['jpg','jpeg','gif','png','webp','pdf','txt','md','doc','docx','odt','csv','xls','xlsx','ods','pptx','odp','zip','tar','gz','xml','msg','json','wav','mp3','ogg','mov','mp4','av1','ovpn'];

    $files = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'] ?? null)) {
        for ($i = 0; $i < count($_FILES['files']['name']); $i++) {
            $files[] = [
                'name'     => $_FILES['files']['name'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error'    => $_FILES['files']['error'][$i],
                'size'     => $_FILES['files']['size'][$i],
            ];
        }
    } elseif (!empty($_FILES['file'])) {
        $files[] = $_FILES['file'];
    }

    $saved = [];
    if (empty($files)) return $saved;

    $upload_dir = "$document_root/uploads/tickets/$ticket_id/";
    mkdirMissing("$document_root/uploads/tickets/");
    mkdirMissing($upload_dir);

    $reply_sql = $reply_id !== null ? intval($reply_id) : 'NULL';

    foreach ($files as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;

        $ref_name = checkFileUpload($file, $allowed);
        if (!is_string($ref_name) || !preg_match('/^[a-zA-Z0-9]+\.[a-zA-Z0-9]+$/', $ref_name)) continue;

        move_uploaded_file($file['tmp_name'], $upload_dir . $ref_name);

        $name = sanitizeInput($file['name']);
        $ref  = mysqli_real_escape_string($mysqli, $ref_name);
        mysqli_query($mysqli,
            "INSERT INTO ticket_attachments SET ticket_attachment_name='$name', ticket_attachment_reference_name='$ref', ticket_attachment_reply_id=$reply_sql, ticket_attachment_ticket_id=$ticket_id"
        );
        $saved[] = ['name' => $file['name'], 'filename' => $ref_name, 'url' => "/uploads/tickets/$ticket_id/$ref_name"];
    }
    return $saved;
}

// LIST
if ($method === 'GET' && $id === null && $resource === 'tickets') {
    $page    = max(1, intval($_GET['page'] ?? 1));
    $limit   = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset  = ($page - 1) * $limit;
    $search   = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
    $status   = $_GET['status'] ?? 'open'; // open|closed|all
    $mine     = isset($_GET['mine']) ? intval($_GET['mine']) : 0;
    $priority = mysqli_real_escape_string($mysqli, strtolower($_GET['priority'] ?? ''));
    $onsite   = isset($_GET['onsite']) && $_GET['onsite'] !== '' ? intval($_GET['onsite']) : -1;
    $contact_id = isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0;
    $client_id  = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

    // Same client-scope restriction as the per-ticket path above: agents with
    // user_client_permissions rows may only list tickets for permitted clients.
    $where = [
        't.ticket_archived_at IS NULL',
        api_client_scope_sql('t.ticket_client_id'),
    ];
    if ($status === 'open')   $where[] = 't.ticket_resolved_at IS NULL';
    if ($status === 'closed') $where[] = 't.ticket_resolved_at IS NOT NULL';
    if ($mine)                $where[] = "t.ticket_assigned_to = $uid";
    if ($search)              $where[] = "(t.ticket_subject LIKE '%$search%' OR t.ticket_number LIKE '%$search%')";
    if ($priority)            $where[] = "LOWER(t.ticket_priority) = '$priority'";
    if ($onsite !== -1)       $where[] = "t.ticket_onsite = $onsite";
    if ($contact_id)          $where[] = "t.ticket_contact_id = $contact_id";
    if ($client_id)           $where[] = "t.ticket_client_id = $client_id";
    if (isset($_GET['overdue']))   $where[] = "t.ticket_due_at IS NOT NULL AND t.ticket_due_at < NOW() AND t.ticket_resolved_at IS NULL";
    if (isset($_GET['due_today'])) $where[] = "t.ticket_due_at IS NOT NULL AND DATE(t.ticket_due_at) = CURDATE() AND t.ticket_resolved_at IS NULL";

    $where_sql = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM tickets t WHERE $where_sql"))['c']);

    $tickets = [];
    $sql = mysqli_query($mysqli,
        "SELECT t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
                t.ticket_created_at, t.ticket_due_at, t.ticket_resolved_at, t.ticket_updated_at,
                c.client_name, ts.ticket_status_name, ts.ticket_status_color,
                u.user_name AS assigned_to_name
         FROM tickets t
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
         LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
         WHERE $where_sql
         ORDER BY t.ticket_created_at DESC
         LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $tickets[] = [
            'id'           => intval($row['ticket_id']),
            'number'       => intval($row['ticket_number']),
            'subject'      => $row['ticket_subject'],
            'priority'     => $row['ticket_priority'],
            'status'       => $row['ticket_status_name'],
            'status_color' => $row['ticket_status_color'],
            'client'       => $row['client_name'],
            'assigned_to'  => $row['assigned_to_name'],
            'created_at'   => $row['ticket_created_at'],
            'due_at'       => $row['ticket_due_at'],
            'resolved_at'  => $row['ticket_resolved_at'],
            'updated_at'   => $row['ticket_updated_at'],
        ];
    }
    api_response(200, ['data' => $tickets, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

// DETAIL
if ($method === 'GET' && $id !== null && $sub === null) {
    $sql = mysqli_query($mysqli,
        "SELECT t.*, c.client_name, ts.ticket_status_name, ts.ticket_status_color,
                u.user_name AS assigned_to_name, ct.contact_name, ct.contact_email, ct.contact_phone
         FROM tickets t
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
         LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
         LEFT JOIN contacts ct ON t.ticket_contact_id = ct.contact_id
         WHERE t.ticket_id = $id LIMIT 1"
    );
    $ticket = mysqli_fetch_assoc($sql);
    if (!$ticket) api_error(404, 'Ticket not found');

    // Attachments on the ticket itself (not tied to a reply)
    $ticket_attachments = [];
    $asql = mysqli_query($mysqli,
        "SELECT ticket_attachment_name, ticket_attachment_reference_name FROM ticket_attachments
         WHERE ticket_attachment_ticket_id = $id AND ticket_attachment_reply_id IS NULL"
    );
    while ($row = mysqli_fetch_assoc($asql)) {
        $ticket_attachments[] = [
            'name' => $row['ticket_attachment_name'],
            'url'  => "/uploads/tickets/$id/{$row['ticket_attachment_reference_name']}",
        ];
    }

    // Replies
    $replies = [];
    $rsql = mysqli_query($mysqli,
        "SELECT r.*, u.user_name FROM ticket_replies r
         LEFT JOIN users u ON r.ticket_reply_by = u.user_id
         WHERE r.ticket_reply_ticket_id = $id AND r.ticket_reply_archived_at IS NULL
         ORDER BY r.ticket_reply_created_at ASC"
    );
    while ($row = mysqli_fetch_assoc($rsql)) {
        $reply_attachments = [];
        $rasql = mysqli_query($mysqli,
            "SELECT ticket_attachment_name, ticket_attachment_reference_name FROM ticket_attachments
             WHERE ticket_attachment_reply_id = " . intval($row['ticket_reply_id'])
        );
        while ($arow = mysqli_fetch_assoc($rasql)) {
            $reply_attachments[] = [
                'name' => $arow['ticket_attachment_name'],
                'url'  => "/uploads/tickets/$id/{$arow['ticket_attachment_reference_name']}",
            ];
        }

        $replies[] = [
            'id'           => intval($row['ticket_reply_id']),
            'body'         => $row['ticket_reply'],
            'type'         => $row['ticket_reply_type'],
            'time_worked'  => $row['ticket_reply_time_worked'],
            'onsite'       => (bool)($row['ticket_reply_onsite'] ?? false),
            'by'           => $row['user_name'],
            'created_at'   => $row['ticket_reply_created_at'],
            'attachments'  => $reply_attachments,
        ];
    }

    api_response(200, [
        'id'           => intval($ticket['ticket_id']),
        'number'       => intval($ticket['ticket_number']),
        'subject'      => $ticket['ticket_subject'],
        'details'      => $ticket['ticket_details'],
        'priority'     => $ticket['ticket_priority'],
        'status'       => $ticket['ticket_status_name'],
        'status_color' => $ticket['ticket_status_color'],
        'client'       => $ticket['client_name'],
        'assigned_to'  => $ticket['assigned_to_name'],
        'contact_name' => $ticket['contact_name'],
        'contact_email'=> $ticket['contact_email'],
        'contact_phone'=> $ticket['contact_phone'],
        'billable'     => (bool)$ticket['ticket_billable'],
        'created_at'   => $ticket['ticket_created_at'],
        'due_at'       => $ticket['ticket_due_at'],
        'resolved_at'  => $ticket['ticket_resolved_at'],
        'attachments'  => $ticket_attachments,
        'replies'      => $replies,
    ]);
}

// ADD REPLY
if ($method === 'POST' && $id !== null && $sub === 'reply') {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($content_type, 'multipart/form-data') === 0 || stripos($content_type, 'application/x-www-form-urlencoded') === 0) {
        $body = $_POST;
    } else {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    $reply_raw  = trim($body['reply'] ?? '');
    $time_w_raw = trim($body['time_worked'] ?? '');
    $reply      = mysqli_real_escape_string($mysqli, $reply_raw);
    $time_w     = mysqli_real_escape_string($mysqli, $time_w_raw);
    $onsite     = isset($body['onsite']) ? intval($body['onsite']) : 0;
    $contact_id = isset($body['contact_id']) ? intval($body['contact_id']) : 0;
    $status_id_raw = isset($body['status_id']) ? intval($body['status_id']) : 0;

    if (!$reply) api_error(400, 'reply is required');

    // Explicit status change (e.g. the mobile app's "Add Note + set status" action,
    // mirroring the web app's reply-form split-submit-button) - validated up front
    // so a bad status_id fails before any writes happen.
    $status_id_check = null;
    if ($status_id_raw) {
        $status_id_check = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_status_id, ticket_status_name FROM ticket_statuses WHERE ticket_status_id = $status_id_raw LIMIT 1"));
        if (!$status_id_check) api_error(400, 'invalid status_id');
    }

    // Normalize type: Customer/Client/Portal/Website all mean a client-side reply.
    // "note" is stored as 'Internal' to match the web app's own convention — the customer
    // portal/guest ticket views filter out replies with the literal type 'Internal'; storing
    // it as lowercase 'note' (as this endpoint used to) meant mobile-created notes were never
    // actually excluded from the customer-visible views. Everything else (reply/agent/blank)
    // is an agent reply.
    $raw_type = strtolower(trim($body['type'] ?? 'reply'));
    $is_customer = in_array($raw_type, ['client', 'customer', 'portal', 'website']);
    if ($is_customer) {
        $type = 'Client';
    } elseif ($raw_type === 'note' || $raw_type === 'internal') {
        $type = 'Internal';
    } else {
        $type = 'reply';
    }

    // Load ticket (validates it exists; also get contact fallback and current status).
    $ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_assigned_to, ticket_created_by,
                ticket_client_id, ticket_contact_id, ticket_status, ticket_resolved_at
         FROM tickets WHERE ticket_id = $id LIMIT 1"));
    if (!$ticket_row) api_error(404, 'Ticket not found');

    $t_prefix     = $ticket_row['ticket_prefix'];
    $t_number     = $ticket_row['ticket_number'];
    $t_subject    = $ticket_row['ticket_subject'];
    $t_assigned   = intval($ticket_row['ticket_assigned_to']);
    $t_created_by = intval($ticket_row['ticket_created_by']);
    $t_client_id  = intval($ticket_row['ticket_client_id']);
    $t_contact_id = intval($ticket_row['ticket_contact_id']);
    $t_client_uri = $t_client_id ? "&client_id=$t_client_id" : '';
    $t_action     = "/agent/ticket.php?ticket_id=$id$t_client_uri";

    // Resolve reply_by: for client replies, use the provided contact_id, fall back to
    // the ticket's existing contact, or the API user as a last resort.
    $reply_by = $uid;
    if ($type === 'Client') {
        if ($contact_id) {
            $contact_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT c.contact_id FROM contacts c
                 INNER JOIN tickets t ON t.ticket_client_id = c.contact_client_id
                 WHERE c.contact_id = $contact_id AND t.ticket_id = $id LIMIT 1"));
            if (!$contact_row) api_error(400, "contact_id does not belong to this ticket's client");
            $reply_by = $contact_id;
        } elseif ($t_contact_id) {
            $reply_by = $t_contact_id;
        }
    }

    // Prepared statement: $reply is user-supplied free text. Bind the raw
    // (unescaped) values so the stored bytes match the previous escaped-then-
    // interpolated INSERT exactly. Empty time_worked binds as SQL NULL.
    $time_param = $time_w_raw !== '' ? $time_w_raw : null;
    $reply_id = api_exec(
        "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_onsite, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        'sssiii',
        [$reply_raw, $type, $time_param, $onsite, $reply_by, $id]
    );
    mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $id");

    // New reply invalidates any cached AI summary for this ticket
    require_once $DOCUMENT_ROOT . '/includes/ai_functions.php';
    markTicketSummaryStale($mysqli, $id);

    // Status update: an explicit status_id in the request takes priority over the
    // type-based auto-update below (which is unchanged for callers that don't send one).
    $new_status_id = null;
    if ($status_id_raw) {
        // Capture prior status for SLA pause accounting before it's overwritten.
        $sla_old_status_id = intval($ticket_row['ticket_status']);

        // Resolved immediately becomes Closed, mirroring the web app's reply-form
        // split-submit-button exactly (agent/post/ticket.php) - and a direct request
        // for the Closed status itself gets the same resolved/closed bookkeeping,
        // since the web app's "is this open" queries key off ticket_resolved_at/
        // ticket_closed_at, not ticket_status (see the /status endpoint below).
        $target_name = $status_id_check['ticket_status_name'];
        if ($target_name === 'Resolved' || $target_name === 'Closed') {
            $closed_row = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'Closed' LIMIT 1"));
            $new_status_id = $closed_row ? intval($closed_row['ticket_status_id']) : $status_id_raw;
            mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id, ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $uid WHERE ticket_id = $id");
            if ($target_name === 'Resolved') {
                mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed.', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = $uid, ticket_reply_ticket_id = $id");
            }
        } else {
            $new_status_id = $status_id_raw;
            mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id WHERE ticket_id = $id");
        }

        require_once $DOCUMENT_ROOT . '/includes/sla_functions.php';
        slaAccruePause($mysqli, $id, $sla_old_status_id, $new_status_id);
    } elseif ($type === 'Client') {
        // Customer reply: reopen resolved/closed ticket, then move to In Progress.
        if (!empty($ticket_row['ticket_resolved_at'])) {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NULL, ticket_closed_at = NULL WHERE ticket_id = $id");
        }
        $progress_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_status_id FROM ticket_statuses
             WHERE ticket_status_name IN ('In Progress','Open') AND ticket_status_active = 1
             ORDER BY FIELD(ticket_status_name,'In Progress','Open') LIMIT 1"));
        $new_status_id = $progress_row ? intval($progress_row['ticket_status_id']) : 2;
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id WHERE ticket_id = $id");
    } elseif ($type === 'reply') {
        // Agent reply: move to Waiting on Customer so the client knows action is needed.
        $woc_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_status_id FROM ticket_statuses
             WHERE ticket_status_name = 'Waiting on Customer' AND ticket_status_active = 1 LIMIT 1"));
        if ($woc_row) {
            $new_status_id = intval($woc_row['ticket_status_id']);
            mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $new_status_id WHERE ticket_id = $id");
        }
    }

    // Notifications
    if ($type === 'Client') {
        if ($t_assigned != 0) {
            notifyUser($t_assigned, 'Ticket', "Customer reply on Ticket $t_prefix$t_number - $t_subject", $t_action, $t_client_id, $id);
        } else {
            appNotify('Ticket', "Customer reply on Ticket $t_prefix$t_number - $t_subject", $t_action, $t_client_id, $id);
        }
    } else {
        if ($t_assigned != 0 && $t_assigned != $uid) {
            notifyUser($t_assigned, 'Ticket', "$session_name replied to Ticket $t_prefix$t_number - $t_subject that is assigned to you", $t_action, $t_client_id, $id);
        } elseif ($t_created_by != 0 && $t_created_by != $uid) {
            notifyUser($t_created_by, 'Ticket', "$session_name replied to Ticket $t_prefix$t_number - $t_subject that you opened", $t_action, $t_client_id, $id);
        }
    }

    publishTicketEvent($id, 'reply', ['reply_id' => $reply_id, 'reply_type' => $type, 'by' => $session_name ?? 'API', 'by_type' => $type === 'Client' ? 'contact' : 'agent']);

    if ($new_status_id) {
        $new_status_info = getTicketStatusInfo($mysqli, $new_status_id);
        publishTicketEvent($id, 'status', ['status_id' => $new_status_info['id'], 'status_name' => $new_status_info['name'], 'status_color' => $new_status_info['color'], 'by' => $type === 'Client' ? 'Customer' : ($session_name ?? 'API')]);
    }

    // Fire the outbound webhook (mirrors agent/post/ticket.php's reply handler).
    // queueWebhookEvent() gates on webhook_enabled internally.
    queueWebhookEvent('ticket.replied', getWebhookTicketPayload($id));

    $response = ['id' => $reply_id, 'type' => $type];
    if ($new_status_id) {
        $si = getTicketStatusInfo($mysqli, $new_status_id);
        $response['ticket_status'] = ['id' => $si['id'], 'name' => $si['name']];
    }
    $attachments = api_save_ticket_attachments($mysqli, $id, $reply_id, $DOCUMENT_ROOT);
    if ($attachments) $response['attachments'] = $attachments;

    api_response(201, $response);
}

// DELETE REPLY
if ($method === 'DELETE' && $id !== null && $sub === 'reply') {
    $reply_id = isset($segments[3]) && is_numeric($segments[3]) ? intval($segments[3]) : null;
    if (!$reply_id) api_error(400, 'reply_id required');

    $reply = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_id = $reply_id AND ticket_reply_ticket_id = $id LIMIT 1"
    ));
    if (!$reply) api_error(404, 'Reply not found');

    mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_reply_id = $reply_id");
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_id = $reply_id");
    mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $id");

    api_response(200, ['ok' => true]);
}

// LOG TIME
if ($method === 'POST' && $id !== null && $sub === 'time') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $time_worked = mysqli_real_escape_string($mysqli, trim($body['time_worked'] ?? ''));
    $note        = mysqli_real_escape_string($mysqli, trim($body['note'] ?? 'Time logged via mobile app'));

    if (!$time_worked) api_error(400, 'time_worked required (HH:MM:SS)');

    mysqli_query($mysqli,
        "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_time_worked, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_created_at)
         VALUES ('$note', 'Internal', '$time_worked', $uid, $id, NOW())"
    );

    api_response(201, ['ok' => true]);
}

// GET CHAT MESSAGES (and, with ?stream=1, a live SSE feed of new ones)
if ($method === 'GET' && $id !== null && $sub === 'chat') {
    if (!$config_module_enable_live_chat) api_error(403, 'Live chat is not enabled');

    $ticket_check = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.ticket_id, cl.client_name FROM tickets t
         JOIN clients cl ON cl.client_id = t.ticket_client_id
         WHERE t.ticket_id = $id LIMIT 1"));
    if (!$ticket_check) api_error(404, 'Ticket not found');

    if (isset($_GET['stream'])) {
        require $DOCUMENT_ROOT . '/includes/sse_ticket_chat_stream.php';
        exit;
    }

    $since_id = isset($_GET['since_id']) ? intval($_GET['since_id']) : 0;

    $sql = mysqli_query($mysqli,
        "SELECT cm.*, u.user_name, c.contact_name FROM ticket_chat_messages cm
         LEFT JOIN users u ON cm.sender_type = 'agent' AND cm.sender_id = u.user_id
         LEFT JOIN contacts c ON cm.sender_type = 'contact' AND cm.sender_id = c.contact_id
         WHERE cm.ticket_id = $id AND cm.id > $since_id
         ORDER BY cm.id ASC"
    );

    $messages = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        if ($row['sender_type'] === 'agent') {
            $sender_name = $row['user_name'];
        } else {
            // sender_id = 0 means "the client generally", not one specific
            // contact (e.g. a machine client with no contact configured) -
            // fall back to the client's name rather than showing nothing.
            $sender_name = $row['contact_name'] ?? $ticket_check['client_name'];
        }
        $messages[] = [
            'id'          => intval($row['id']),
            'sender_type' => $row['sender_type'],
            'sender_id'   => intval($row['sender_id']),
            'sender_name' => $sender_name,
            'message'     => $row['message'],
            'created_at'  => $row['created_at'],
        ];
    }

    api_response(200, ['data' => $messages]);
}

// POST CHAT MESSAGE
if ($method === 'POST' && $id !== null && $sub === 'chat') {
    if (!$config_module_enable_live_chat) api_error(403, 'Live chat is not enabled');

    $ticket_check = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.ticket_id, cl.client_name FROM tickets t
         JOIN clients cl ON cl.client_id = t.ticket_client_id
         WHERE t.ticket_id = $id LIMIT 1"));
    if (!$ticket_check) api_error(404, 'Ticket not found');

    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $message = trim($body['message'] ?? '');
    if ($message === '') api_error(400, 'message is required');

    $message_escaped = mysqli_real_escape_string($mysqli, $message);

    // contact_id posts on behalf of one of the ticket's client's contacts,
    // mirroring client/post.php's add_ticket_chat_message; a real technician
    // session/token with no contact_id is attributed to that agent.
    $contact_id = isset($body['contact_id']) ? intval($body['contact_id']) : 0;
    if ($contact_id) {
        $contact_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT c.contact_id, c.contact_name FROM contacts c
             INNER JOIN tickets t ON t.ticket_client_id = c.contact_client_id
             WHERE c.contact_id = $contact_id AND t.ticket_id = $id LIMIT 1"));
        if (!$contact_row) api_error(400, "contact_id does not belong to this ticket's client");

        $sender_type = 'contact';
        $sender_id   = $contact_id;
        $sender_name = $contact_row['contact_name'];
    } elseif ($legacy_api_key_auth) {
        // Authenticated with the shared legacy API key (e.g. ITPanel Pro
        // with no Contact ID configured), not a real technician sitting
        // down to reply - $uid here is just "the first active admin" that
        // key resolves to, so attributing the message to them by name would
        // be misleading. Attribute it to the client generally instead.
        $sender_type = 'contact';
        $sender_id   = 0;
        $sender_name = $ticket_check['client_name'];
    } else {
        $sender_type = 'agent';
        $sender_id   = $uid;
        $sender_name = $session_name ?? 'API';
    }

    mysqli_query($mysqli, "INSERT INTO ticket_chat_messages SET ticket_id = $id, sender_type = '$sender_type', sender_id = $sender_id, message = '$message_escaped'");
    $chat_id = mysqli_insert_id($mysqli);

    publishTicketEvent($id, 'chat', [
        'chat_id'     => $chat_id,
        'message'     => $message,
        'sender_type' => $sender_type,
        'sender_id'   => $sender_id,
        'sender_name' => $sender_name,
        'created_at'  => date('Y-m-d H:i:s'),
    ]);

    // Push/notify the assigned agent when a contact (e.g. ITPanel Pro on a
    // client's machine) sends the message - otherwise chat never reaches
    // the mobile app, unlike ticket replies.
    if ($sender_type === 'contact') {
        $chat_ticket_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_assigned_to, ticket_client_id FROM tickets WHERE ticket_id = $id LIMIT 1"));
        if ($chat_ticket_row && intval($chat_ticket_row['ticket_assigned_to'])) {
            notifyUser(
                intval($chat_ticket_row['ticket_assigned_to']),
                'Ticket',
                "$sender_name sent a chat message on Ticket {$chat_ticket_row['ticket_prefix']}{$chat_ticket_row['ticket_number']} - {$chat_ticket_row['ticket_subject']}",
                "/agent/ticket.php?ticket_id=$id",
                intval($chat_ticket_row['ticket_client_id']),
                $id
            );
        }
    }

    api_response(201, ['id' => $chat_id]);
}

// CREATE TICKET
if ($method === 'POST' && $id === null) {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($content_type, 'multipart/form-data') === 0 || stripos($content_type, 'application/x-www-form-urlencoded') === 0) {
        $body = $_POST;
    } else {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    $subject_raw = trim($body['subject'] ?? '');
    $details_raw = trim($body['details'] ?? '');
    $subject  = mysqli_real_escape_string($mysqli, $subject_raw);
    $details  = mysqli_real_escape_string($mysqli, $details_raw);
    $client   = isset($body['client_id']) ? intval($body['client_id']) : 0;
    $priority_in = strtolower(trim($body['priority'] ?? ''));
    $priority = in_array($priority_in, ['low','medium','high','critical'])
                ? ucfirst($priority_in) : 'Low';
    $assigned = resolveTicketAssignee(isset($body['assigned_to']) ? intval($body['assigned_to']) : 0);
    $contact  = isset($body['contact_id']) ? intval($body['contact_id']) : 0;
    $contract = isset($body['contract_id']) ? intval($body['contract_id']) : 0;
    $category = isset($body['category_id']) ? intval($body['category_id']) : 0;
    $hostname = mysqli_real_escape_string($mysqli, trim($body['hostname'] ?? ''));

    if (!$subject) api_error(400, 'subject required');

    // Unlike every other write path in this file, ticket creation has no existing
    // ticket_id to run the client-scope check (lines 14-22) against - check the
    // submitted client_id directly so a client-scoped key/user can't create a
    // ticket for a client outside their permitted set.
    if (!api_client_scope_ok($client)) api_error(403, 'Access denied');

    // Fall back to the client's primary contact when the caller didn't supply
    // one - most API callers (e.g. ITPanel Pro's quick-ticket, when its own
    // contact_id config isn't set) know which client a ticket is for but not
    // which specific contact, leaving every such ticket contact-less even
    // though the client is unambiguous. When one IS explicitly submitted, it
    // must actually belong to the same client - api_client_scope_ok() above
    // only checked the caller's access to $client, it says nothing about
    // whether a caller-supplied contact_id belongs to a completely different
    // client, which would mix tenant data on the created ticket.
    if ($contact) {
        $contact_ok = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contact_id FROM contacts WHERE contact_id = $contact AND contact_client_id = $client LIMIT 1"));
        if (!$contact_ok) api_error(400, 'contact_id does not belong to client_id');
    } elseif ($client) {
        $primary_contact = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contact_id FROM contacts WHERE contact_client_id = $client AND contact_archived_at IS NULL
             ORDER BY contact_primary DESC, contact_id ASC LIMIT 1"));
        if ($primary_contact) {
            $contact = intval($primary_contact['contact_id']);
        }
    }

    // Same reasoning for contract linkage: leaving ticket_contract_id NULL
    // silently exempts every API-created ticket from included-hours allowance
    // tracking (getContractIncludedIssuesUsage() only counts tickets that are
    // actually linked). An explicitly-submitted contract_id must belong to
    // $client for the same cross-tenant reason as contact_id above. Auto-link
    // only when it's unambiguous - a client with exactly one active,
    // non-archived contract - matching the same ambiguity rule already used
    // when this feature's own DB migration carried forward old client-level
    // allowance values. A client with zero or multiple active contracts is
    // left unlinked, same as before.
    $contract_id_sql = 'NULL';
    if ($contract) {
        $contract_ok = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contract_id FROM contracts WHERE contract_id = $contract AND contract_client_id = $client LIMIT 1"));
        if (!$contract_ok) api_error(400, 'contract_id does not belong to client_id');
        $contract_id_sql = $contract;
    } elseif ($client) {
        $active_contracts = mysqli_query($mysqli,
            "SELECT contract_id FROM contracts WHERE contract_client_id = $client
             AND contract_status = 'Active' AND contract_archived_at IS NULL LIMIT 2");
        if (mysqli_num_rows($active_contracts) === 1) {
            $contract_id_sql = intval(mysqli_fetch_assoc($active_contracts)['contract_id']);
        }
    }

    // Validate category against ticket categories
    if ($category) {
        $cat_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT category_id FROM categories WHERE category_id = $category AND category_type = 'Ticket' AND category_archived_at IS NULL LIMIT 1"));
        if (!$cat_row) $category = 0;
    }

    // Auto-link to a matching asset for this client by hostname/asset name
    $asset_id = 0;
    if ($hostname && $client) {
        $asset_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT asset_id FROM assets WHERE asset_client_id = $client AND asset_archived_at IS NULL
             AND (asset_name = '$hostname' OR asset_tag = '$hostname') LIMIT 1"));
        if ($asset_row) $asset_id = intval($asset_row['asset_id']);
    }

    // Atomically increment-and-fetch via LAST_INSERT_ID(), same pattern used by
    // every other ticket-creation path (functions.php's addTicket(),
    // agent/post/ticket.php, etc.) - a plain MAX(ticket_number)+1 here raced
    // against those paths (and against itself under concurrent requests) and
    // produced duplicate ticket_number values.
    mysqli_query($mysqli, "
        UPDATE settings
        SET
            config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");
    $next_num = mysqli_insert_id($mysqli);
    $status_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_name = 'New' AND ticket_status_active = 1 LIMIT 1"));
    if (!$status_row) {
        $status_row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order ASC, ticket_status_id ASC LIMIT 1"));
    }
    $status = intval($status_row['ticket_status_id']);

    $prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix ?? '');

    // Attributing ticket_created_by to $uid is only meaningful for a real per-user
    // API token (a technician deliberately creating a ticket via the API). A legacy
    // API key resolves $uid to "the first active admin" - not the actual person
    // submitting the ticket (e.g. ITPanel Pro/client portal with no contact_id
    // configured) - so crediting that admin by name here would be misleading, the
    // same reasoning already applied to legacy-key chat/reply attribution below.
    $created_by_sql = $legacy_api_key_auth ? 0 : $uid;

    // Bearer token for every guest-facing link (CSAT rating, "view ticket",
    // resolve/reopen, etc.) - every other ticket-creation path sets this via
    // randomString(32); this one never did, leaving ticket_url_key NULL for
    // every API-created ticket and breaking all of those links with a generic
    // "Oops, something went wrong!" (NULL never equality-matches '' in SQL).
    $url_key = randomString(32);

    // Prepared statement: ticket_subject/ticket_details are user-supplied free
    // text and are bound. The remaining columns are ints or already-validated
    // values ($prefix_esc = escaped config, $priority = whitelisted enum), left
    // interpolated to keep the diff minimal. Bound raw values store the same
    // bytes the previous escaped-then-interpolated INSERT did.
    $new_id = api_exec(
        "INSERT INTO tickets (ticket_prefix, ticket_subject, ticket_details, ticket_client_id, ticket_contact_id, ticket_priority,
         ticket_status, ticket_assigned_to, ticket_created_by, ticket_source, ticket_number, ticket_category, ticket_asset_id, ticket_contract_id, ticket_url_key, ticket_created_at, ticket_updated_at)
         VALUES ('$prefix_esc', ?, ?, $client, $contact, '$priority', $status, $assigned, $created_by_sql, 'API', $next_num, $category, $asset_id, $contract_id_sql, '$url_key', NOW(), NOW())",
        'ss',
        [$subject_raw, $details_raw]
    );

    // Notify the assigned agent of the new ticket, or broadcast to active
    // agents if it wasn't auto-assigned (e.g. ITPanel Pro quick-ticket
    // submissions) so the mobile app gets a push for it either way.
    global $config_ticket_prefix;
    $client_uri = $client ? "&client_id=$client" : '';
    if ($assigned != 0 && $assigned != $uid) {
        notifyUser($assigned, 'Ticket', "New ticket {$config_ticket_prefix}$next_num - $subject has been assigned to you", "/agent/ticket.php?ticket_id=$new_id$client_uri", $client, $new_id);
    } elseif ($assigned == 0) {
        appNotify('Ticket', "New ticket {$config_ticket_prefix}$next_num - $subject has been created via API", "/agent/ticket.php?ticket_id=$new_id$client_uri", $client, $new_id);
    }

    // Fire the outbound webhook (mirrors agent/post/ticket.php's create handler).
    // queueWebhookEvent() gates on webhook_enabled internally.
    queueWebhookEvent('ticket.created', getWebhookTicketPayload($new_id));

    // Run ticket_created automation rules (fire-and-forget; never blocks/fails insert)
    require_once $DOCUMENT_ROOT . '/includes/ticket_automation_dispatch.php';
    runTicketCreatedAutomation($mysqli, $new_id);

    $response = ['id' => $new_id, 'number' => $next_num];
    $attachments = api_save_ticket_attachments($mysqli, $new_id, null, $DOCUMENT_ROOT);
    if ($attachments) $response['attachments'] = $attachments;

    api_response(201, $response);
}

// UPLOAD ATTACHMENT
if ($method === 'POST' && $id !== null && $sub === 'attachments') {
    $saved = api_save_ticket_attachments($mysqli, $id, null, $DOCUMENT_ROOT);
    if (empty($saved)) api_error(400, 'File upload failed or file type not allowed');

    mysqli_query($mysqli, "UPDATE tickets SET ticket_updated_at = NOW() WHERE ticket_id = $id");

    api_response(201, count($saved) === 1 ? $saved[0] : ['attachments' => $saved]);
}

// ── Additional POST routes ───────────────────────────────────────────────────

// UPDATE TICKET STATUS
if ($method === 'POST' && $id !== null && $sub === 'status') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $status = intval($body['status_id'] ?? 0);
    if (!$status) api_error(400, 'status_id required');

    // Resolved is an immediate alias for Closed everywhere else in this app
    // (web reply form, quick-status, status dropdown, bulk reply, kanban) -
    // match that here too, so picking "Resolved" from the mobile app's status
    // picker actually closes the ticket instead of leaving it stuck at status
    // 4 relying on the slow cron-based auto-close.
    $status = resolveTicketStatusId($status);

    $status_name_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = $status"));
    if (!$status_name_row) api_error(400, 'invalid status_id');

    // Mirror the web app's close-ticket behavior exactly (ticket_status +
    // ticket_resolved_at + ticket_closed_at + ticket_closed_by all together) -
    // the web app's "is this open" queries key off ticket_closed_at, not
    // ticket_status, so a ticket closed from the app must set it or it never
    // disappears from open-ticket views on the web.
    $closed_by = intval($session_user_id ?? 0);
    if ($status_name_row['ticket_status_name'] === 'Closed') {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $status, ticket_updated_at = NOW(), ticket_resolved_at = NOW(), ticket_closed_at = NOW(), ticket_closed_by = $closed_by WHERE ticket_id = $id");
    } else {
        // Moving to any non-Closed status reopens the ticket if it was closed.
        // ticket_closed_by is NOT NULL (default 0), unlike the two timestamp columns.
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $status, ticket_updated_at = NOW(), ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0 WHERE ticket_id = $id");
    }

    $api_status_info = getTicketStatusInfo($mysqli, $status);
    publishTicketEvent($id, 'status', ['status_id' => $api_status_info['id'], 'status_name' => $api_status_info['name'], 'status_color' => $api_status_info['color'], 'by' => $session_name ?? 'API']);

    // Fire outbound webhooks. queueWebhookEvent() gates on webhook_enabled.
    // The 'Closed' branch above is the only one that sets ticket_resolved_at, so
    // it also emits ticket.resolved (mirroring the web app's resolve handler).
    queueWebhookEvent('ticket.status_changed', getWebhookTicketPayload($id));
    if ($status_name_row['ticket_status_name'] === 'Closed') {
        queueWebhookEvent('ticket.resolved', getWebhookTicketPayload($id));
    }

    api_response(200, ['ok' => true]);
}

// GET TICKET STATUSES
if ($method === 'GET' && $resource === 'statuses' || ($method === 'GET' && $id === null && isset($_GET['statuses']))) {
    $statuses = [];
    $sql = mysqli_query($mysqli, "SELECT * FROM ticket_statuses WHERE ticket_status_active = 1 ORDER BY ticket_status_order ASC");
    while ($row = mysqli_fetch_assoc($sql)) {
        $statuses[] = ['id' => intval($row['ticket_status_id']), 'name' => $row['ticket_status_name'], 'color' => $row['ticket_status_color']];
    }
    api_response(200, $statuses);
}

api_error(404, 'Not found');
