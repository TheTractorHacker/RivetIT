<?php
// GET    /api/v1/tickets/{id}/charges       - list charges for ticket
// GET    /api/v1/tickets/{id}/worksheets     - list worksheets for ticket
// GET    /api/v1/worksheets/{id}             - worksheet detail with fields
// POST   /api/v1/worksheets/{id}/sign        - sign a worksheet
// GET    /api/v1/worksheet-templates         - list available templates
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

$uid = $api_user_id;

api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

// Client-scope restriction, mirroring tickets.php: agents with rows in
// user_client_permissions may only access tickets/worksheets whose client is
// in their permitted set.
function _api_worksheets_ticket_scope_ok($mysqli, int $uid, int $ticket_id): bool {
    return (bool) mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT t.ticket_id FROM tickets t
         WHERE t.ticket_id = $ticket_id
           AND " . api_client_scope_sql('t.ticket_client_id') . "
         LIMIT 1"
    ));
}

// tickets/{id}/charges and tickets/{id}/worksheets: $id is the ticket_id directly.
if ($resource === 'tickets' && $id !== null) {
    if (!_api_worksheets_ticket_scope_ok($mysqli, $uid, $id)) api_error(403, 'Access denied');
}

// worksheets/{id}/*: $id is the worksheet_id — resolve to its ticket to check scope.
if ($resource === 'worksheets' && $id !== null) {
    $ws_ticket = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT worksheet_ticket_id FROM ticket_worksheets WHERE worksheet_id = $id LIMIT 1"));
    if (!$ws_ticket) api_error(404, 'Worksheet not found');
    if (!_api_worksheets_ticket_scope_ok($mysqli, $uid, intval($ws_ticket['worksheet_ticket_id']))) {
        api_error(403, 'Access denied');
    }
}

// Charges for a ticket
if ($resource === 'tickets' && $id !== null && $sub === 'charges' && $method === 'GET') {
    $charges = [];
    $sql = mysqli_query($mysqli,
        "SELECT c.*, u.user_name AS created_by_name
         FROM ticket_charges c
         LEFT JOIN users u ON c.charge_created_by = u.user_id
         WHERE c.charge_ticket_id = $id AND c.charge_archived_at IS NULL
         ORDER BY c.charge_created_at ASC"
    );
    $total = 0;
    while ($row = mysqli_fetch_assoc($sql)) {
        $charges[] = [
            'id'          => intval($row['charge_id']),
            'name'        => $row['charge_name'],
            'description' => $row['charge_description'],
            'quantity'    => floatval($row['charge_quantity']),
            'unit_price'  => floatval($row['charge_unit_price']),
            'total'       => floatval($row['charge_total']),
            'invoiced'    => !empty($row['charge_invoiced_at']),
            'created_by'  => $row['created_by_name'],
            'created_at'  => $row['charge_created_at'],
        ];
        $total += floatval($row['charge_total']);
    }
    api_response(200, ['charges' => $charges, 'total' => $total]);
}

// Worksheets list for a ticket (regular worksheets only — outtake forms are in ticket_outtake_forms)
if ($resource === 'tickets' && $id !== null && $sub === 'worksheets' && $method === 'GET') {
    $worksheets = [];
    $sql = mysqli_query($mysqli,
        "SELECT w.*, t.worksheet_template_name, u.user_name AS created_by_name
         FROM ticket_worksheets w
         LEFT JOIN worksheet_templates t ON w.worksheet_template_id = t.worksheet_template_id
         LEFT JOIN users u ON w.worksheet_created_by = u.user_id
         WHERE w.worksheet_ticket_id = $id AND (w.worksheet_is_outtake = 0 OR w.worksheet_is_outtake IS NULL)
         ORDER BY w.worksheet_created_at DESC"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $worksheets[] = [
            'id'           => intval($row['worksheet_id']),
            'template_name'=> $row['worksheet_template_name'],
            'created_by'   => $row['created_by_name'],
            'created_at'   => $row['worksheet_created_at'],
            'completed_at' => $row['worksheet_completed_at'],
            'signed_name'  => $row['worksheet_signed_name'],
            'signed_at'    => $row['worksheet_signed_at'],
            'is_outtake'   => (bool)$row['worksheet_is_outtake'],
            'signed'       => !empty($row['worksheet_signed_at']),
        ];
    }
    api_response(200, $worksheets);
}

// Worksheet detail (fields + responses)
if ($resource === 'worksheets' && $id !== null && $sub === null && $method === 'GET') {
    $ws = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT w.*, t.worksheet_template_name
         FROM ticket_worksheets w
         LEFT JOIN worksheet_templates t ON w.worksheet_template_id = t.worksheet_template_id
         WHERE w.worksheet_id = $id LIMIT 1"
    ));
    if (!$ws) api_error(404, 'Worksheet not found');

    $fields = [];
    if (!empty($ws['worksheet_template_id'])) {
        $sql = mysqli_query($mysqli,
            "SELECT f.*, r.response_value
             FROM worksheet_template_fields f
             LEFT JOIN ticket_worksheet_responses r
               ON r.response_field_id = f.field_id AND r.response_worksheet_id = $id
             WHERE f.field_template_id = {$ws['worksheet_template_id']}
             ORDER BY f.field_order ASC"
        );
        while ($row = mysqli_fetch_assoc($sql)) {
            $fields[] = [
                'id'       => intval($row['field_id']),
                'name'     => $row['field_name'],
                'type'     => $row['field_type'],
                'options'  => $row['field_options'],
                'required' => (bool)$row['field_required'],
                'value'    => $row['response_value'],
            ];
        }
    }
    // outtake with no template → $fields stays empty, sign screen handles it

    api_response(200, [
        'id'            => intval($ws['worksheet_id']),
        'template_name' => $ws['worksheet_template_name'],
        'signed_name'   => $ws['worksheet_signed_name'],
        'signed_at'     => $ws['worksheet_signed_at'],
        'completed_at'  => $ws['worksheet_completed_at'],
        'signed'        => !empty($ws['worksheet_signed_at']),
        'fields'        => $fields,
    ]);
}

// Sign a worksheet
if ($resource === 'worksheets' && $id !== null && $sub === 'sign' && $method === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $signed_name = mysqli_real_escape_string($mysqli, trim($body['signed_name'] ?? ''));
    $signature   = $body['signature'] ?? '';

    if (empty($signed_name)) api_error(400, 'signed_name required');

    // Validate signature format
    if ($signature && strlen($signature) > 524288) api_error(400, 'Signature payload too large');
    if ($signature && !preg_match('/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/', $signature)) {
        api_error(400, 'Invalid signature format');
    }

    $esc_sig = $signature ? "worksheet_signature = '" . mysqli_real_escape_string($mysqli, $signature) . "'," : '';

    mysqli_query($mysqli,
        "UPDATE ticket_worksheets SET
            worksheet_signed_name = '$signed_name',
            worksheet_signed_at = NOW(),
            worksheet_completed_at = IFNULL(worksheet_completed_at, NOW()),
            $esc_sig
            worksheet_id = worksheet_id
         WHERE worksheet_id = $id"
    );

    // Log the signing as a ticket reply
    $ws = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT worksheet_ticket_id FROM ticket_worksheets WHERE worksheet_id = $id"));
    if ($ws) {
        $ticket_id = intval($ws['worksheet_ticket_id']);
        $msg = mysqli_real_escape_string($mysqli, "Worksheet signed by $signed_name");
        mysqli_query($mysqli,
            "INSERT INTO ticket_replies (ticket_reply, ticket_reply_type, ticket_reply_by, ticket_reply_ticket_id)
             VALUES ('$msg', 'note', $uid, $ticket_id)"
        );
    }

    api_response(200, ['ok' => true]);
}

// Check a worksheet complete/incomplete without a signature
if ($resource === 'worksheets' && $id !== null && $sub === 'complete' && $method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $completed = !empty($body['completed']);

    $ws = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT worksheet_signed_at FROM ticket_worksheets WHERE worksheet_id = $id"));
    if (!$ws) api_error(404, 'Worksheet not found');

    if ($completed) {
        mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_completed_at = NOW() WHERE worksheet_id = $id AND worksheet_completed_at IS NULL");
    } else {
        if (!empty($ws['worksheet_signed_at'])) api_error(400, 'Cannot uncheck a signed worksheet');
        mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_completed_at = NULL WHERE worksheet_id = $id");
    }
    api_response(200, ['ok' => true]);
}

// List worksheet templates
if ($resource === 'worksheet-templates' && $method === 'GET') {
    $templates = [];
    $sql = mysqli_query($mysqli,
        "SELECT worksheet_template_id, worksheet_template_name, worksheet_template_description
         FROM worksheet_templates WHERE worksheet_template_archived_at IS NULL
         ORDER BY worksheet_template_name ASC"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $templates[] = [
            'id'          => intval($row['worksheet_template_id']),
            'name'        => $row['worksheet_template_name'],
            'description' => $row['worksheet_template_description'],
        ];
    }
    api_response(200, $templates);
}

// Create ticket charge
if ($resource === 'tickets' && $id !== null && $sub === 'charges' && $method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $name      = mysqli_real_escape_string($mysqli, trim($body['name'] ?? ''));
    $desc      = mysqli_real_escape_string($mysqli, trim($body['description'] ?? ''));
    $qty       = floatval($body['quantity'] ?? 1);
    $unit_price= floatval($body['unit_price'] ?? 0);
    $total     = round($qty * $unit_price, 2);

    if (!$name) api_error(400, 'name required');

    mysqli_query($mysqli,
        "INSERT INTO ticket_charges (charge_ticket_id, charge_name, charge_description,
         charge_quantity, charge_unit_price, charge_total, charge_created_by)
         VALUES ($id, '$name', '$desc', $qty, $unit_price, $total, $uid)"
    );
    api_response(201, ['id' => mysqli_insert_id($mysqli), 'total' => $total]);
}

// Create worksheet from template
if ($resource === 'tickets' && $id !== null && $sub === 'worksheets' && $method === 'POST') {
    $body        = json_decode(file_get_contents('php://input'), true) ?? [];
    $template_id = intval($body['template_id'] ?? 0);
    $is_outtake  = intval($body['is_outtake'] ?? 0);
    if (!$template_id) api_error(400, 'template_id required');

    $sign_token = $is_outtake ? "'" . bin2hex(random_bytes(32)) . "'" : 'NULL';
    mysqli_query($mysqli,
        "INSERT INTO ticket_worksheets (worksheet_ticket_id, worksheet_template_id,
         worksheet_is_outtake, worksheet_sign_token, worksheet_created_by)
         VALUES ($id, $template_id, $is_outtake, $sign_token, $uid)"
    );
    $ws_id = mysqli_insert_id($mysqli);
    api_response(201, ['id' => $ws_id]);
}

// Save worksheet field responses
if ($resource === 'worksheets' && $id !== null && $sub === 'responses' && $method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $responses = $body['responses'] ?? [];
    foreach ($responses as $resp) {
        $field_id = intval($resp['field_id'] ?? 0);
        $value    = mysqli_real_escape_string($mysqli, $resp['value'] ?? '');
        if (!$field_id) continue;
        mysqli_query($mysqli,
            "INSERT INTO ticket_worksheet_responses (response_worksheet_id, response_field_id, response_value)
             VALUES ($id, $field_id, '$value')
             ON DUPLICATE KEY UPDATE response_value = '$value'"
        );
    }
    // Mark completed
    mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_completed_at = NOW() WHERE worksheet_id = $id AND worksheet_completed_at IS NULL");
    api_response(200, ['ok' => true]);
}

// Delete a worksheet
if ($resource === 'worksheets' && $id !== null && $sub === null && $method === 'DELETE') {
    mysqli_query($mysqli, "DELETE FROM ticket_worksheet_responses WHERE response_worksheet_id = $id");
    mysqli_query($mysqli, "DELETE FROM ticket_worksheets WHERE worksheet_id = $id");
    api_response(200, ['ok' => true]);
}

api_error(404, 'Not found');
