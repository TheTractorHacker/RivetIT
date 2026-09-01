<?php
// GET    /api/v1/tickets/{id}/outtakes  - list outtake forms for a ticket
// POST   /api/v1/tickets/{id}/outtake   - create outtake form
// GET    /api/v1/outtakes/{id}          - outtake detail
// POST   /api/v1/outtakes/{id}/sign     - sign an outtake
// DELETE /api/v1/outtakes/{id}          - delete an outtake
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

$uid = $api_user_id;

api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

// Client-scope restriction, mirroring tickets.php/worksheets.php: agents with rows in
// user_client_permissions may only access tickets/outtakes whose client is in their
// permitted set.
function _api_outtakes_ticket_scope_ok($mysqli, int $uid, int $ticket_id): bool {
    return (bool) mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.ticket_id FROM tickets t
         WHERE t.ticket_id = $ticket_id
           AND " . api_client_scope_sql('t.ticket_client_id') . "
         LIMIT 1"
    ));
}

// tickets/{id}/outtakes and tickets/{id}/outtake: $id is the ticket_id directly.
if ($resource === 'tickets' && $id !== null) {
    if (!_api_outtakes_ticket_scope_ok($mysqli, $uid, $id)) api_error(403, 'Access denied');
}

// outtakes/{id}/*: $id is the outtake_id — resolve to its ticket to check scope.
if ($resource === 'outtakes' && $id !== null) {
    $ot_ticket = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT outtake_ticket_id FROM ticket_outtake_forms WHERE outtake_id = $id LIMIT 1"));
    if (!$ot_ticket) api_error(404, 'Outtake not found');
    if (!_api_outtakes_ticket_scope_ok($mysqli, $uid, intval($ot_ticket['outtake_ticket_id']))) {
        api_error(403, 'Access denied');
    }
}

// List outtakes for a ticket
if ($resource === 'tickets' && $id !== null && $sub === 'outtakes' && $method === 'GET') {
    $outtakes = [];
    $sql = mysqli_query($mysqli,
        "SELECT ot.outtake_id, ot.outtake_sign_token, ot.outtake_tech_notes,
                ot.outtake_created_at, ot.outtake_signed_name, ot.outtake_signed_at,
                u.user_name AS created_by_name
         FROM ticket_outtake_forms ot
         LEFT JOIN users u ON ot.outtake_created_by = u.user_id
         WHERE ot.outtake_ticket_id = $id
         ORDER BY ot.outtake_created_at DESC"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $outtakes[] = [
            'id'          => intval($row['outtake_id']),
            'sign_token'  => $row['outtake_sign_token'],
            'notes'       => $row['outtake_tech_notes'],
            'created_by'  => $row['created_by_name'],
            'created_at'  => $row['outtake_created_at'],
            'signed_name' => $row['outtake_signed_name'],
            'signed_at'   => $row['outtake_signed_at'],
            'signed'      => !empty($row['outtake_signed_at']),
        ];
    }
    api_response(200, $outtakes);
}

// Create outtake form
if ($resource === 'tickets' && $id !== null && $sub === 'outtake' && $method === 'POST') {
    $sign_token = bin2hex(random_bytes(32));
    mysqli_query($mysqli,
        "INSERT INTO ticket_outtake_forms
         (outtake_ticket_id, outtake_sign_token, outtake_created_by)
         VALUES ($id, '$sign_token', $uid)"
    );
    $outtake_id = mysqli_insert_id($mysqli);
    api_response(201, ['id' => $outtake_id]);
}

// Outtake detail
if ($resource === 'outtakes' && $id !== null && $sub === null && $method === 'GET') {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ot.*, u.user_name AS created_by_name,
                t.ticket_subject, c.client_name,
                co.contact_name
         FROM ticket_outtake_forms ot
         LEFT JOIN users u ON ot.outtake_created_by = u.user_id
         LEFT JOIN tickets t ON ot.outtake_ticket_id = t.ticket_id
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
         WHERE ot.outtake_id = $id LIMIT 1"
    ));
    if (!$row) api_error(404, 'Outtake not found');
    api_response(200, [
        'id'             => intval($row['outtake_id']),
        'sign_token'     => $row['outtake_sign_token'],
        'notes'          => $row['outtake_tech_notes'],
        'created_by'     => $row['created_by_name'],
        'created_at'     => $row['outtake_created_at'],
        'signed_name'    => $row['outtake_signed_name'],
        'signed_at'      => $row['outtake_signed_at'],
        'signed'         => !empty($row['outtake_signed_at']),
        'ticket_subject' => $row['ticket_subject'],
        'client'         => $row['client_name'],
        'contact_name'   => $row['contact_name'],
    ]);
}

// Sign an outtake
if ($resource === 'outtakes' && $id !== null && $sub === 'sign' && $method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $signed_name = mysqli_real_escape_string($mysqli, trim($body['signed_name'] ?? ''));
    $signature   = $body['signature'] ?? '';

    if (empty($signed_name)) api_error(400, 'signed_name required');

    if ($signature && strlen($signature) > 524288) api_error(400, 'Signature payload too large');
    if ($signature && !preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $signature)) {
        api_error(400, 'Invalid signature format');
    }

    $esc_sig = $signature ? "outtake_signature = '" . mysqli_real_escape_string($mysqli, $signature) . "'," : '';

    mysqli_query($mysqli,
        "UPDATE ticket_outtake_forms SET
            outtake_signed_name = '$signed_name',
            outtake_signed_at   = NOW(),
            $esc_sig
            outtake_id          = outtake_id
         WHERE outtake_id = $id"
    );

    $ot = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT outtake_ticket_id FROM ticket_outtake_forms WHERE outtake_id = $id"));
    if ($ot) {
        $ticket_id = intval($ot['outtake_ticket_id']);
        $msg = mysqli_real_escape_string($mysqli, "Outtake form signed by $signed_name");
        mysqli_query($mysqli,
            "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_by, ticket_reply_ticket_id)
             VALUES ('$msg', 'note', $uid, $ticket_id)"
        );
    }

    api_response(200, ['ok' => true]);
}

// Delete an outtake
if ($resource === 'outtakes' && $id !== null && $sub === null && $method === 'DELETE') {
    mysqli_query($mysqli, "DELETE FROM ticket_outtake_forms WHERE outtake_id = $id");
    api_response(200, ['ok' => true]);
}

api_error(404, 'Not found');
