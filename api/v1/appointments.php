<?php
// GET  /api/v1/appointments?when=today|past|future&mine=1&client_id=  - list appointments
// POST /api/v1/appointments                                          - create an appointment
//
// Backed by ticket_schedules (the multi-entry "Appointment" feature used by the ticket
// detail page's Schedule section — labeled "Appointment added" there), not the legacy
// single ticket_schedule/ticket_schedule_end columns on tickets, which are a separate,
// older single-slot field and would miss anything scheduled through the modern flow.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

$uid = $api_user_id;

// Gate on the support module permission for both GET and POST, mirroring
// tickets.php - without this, any authenticated API caller could list every
// scheduled appointment regardless of their role's module_support level.
api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

if ($method === 'GET') {
    $mine = intval($_GET['mine'] ?? 0);
    $when = $_GET['when'] ?? 'future'; // past, today, future

    $where = ['s.schedule_archived_at IS NULL', 't.ticket_archived_at IS NULL'];
    if ($mine) $where[] = "s.schedule_tech_id = $uid";
    if (isset($_GET['client_id'])) $where[] = "t.ticket_client_id = " . intval($_GET['client_id']);
    // Client-scope restriction, mirroring tickets.php's access check: agents with rows in
    // user_client_permissions may only see appointments for their permitted clients.
    $where[] = api_client_scope_sql('t.ticket_client_id');

    switch ($when) {
        case 'past':
            $where[] = "s.schedule_start < NOW()";
            break;
        case 'today':
            $where[] = "DATE(s.schedule_start) = CURDATE()";
            break;
        default: // future
            $where[] = "s.schedule_start >= NOW()";
    }

    $w = implode(' AND ', $where);
    $appts = [];
    $sql = mysqli_query($mysqli,
        "SELECT s.schedule_id, s.schedule_start, s.schedule_end, s.schedule_onsite, s.schedule_notes,
                t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
                c.client_name, u.user_name AS assigned_to_name,
                ts.ticket_status_name, ts.ticket_status_color,
                ct.contact_name, ct.contact_phone, ct.contact_mobile,
                l.location_address, l.location_city, l.location_state
         FROM ticket_schedules s
         JOIN tickets t ON t.ticket_id = s.schedule_ticket_id
         LEFT JOIN clients c ON t.ticket_client_id = c.client_id
         LEFT JOIN users u ON s.schedule_tech_id = u.user_id
         LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
         LEFT JOIN contacts ct ON t.ticket_contact_id = ct.contact_id
         LEFT JOIN locations l ON l.location_client_id = t.ticket_client_id AND l.location_primary = 1
         WHERE $w
         ORDER BY s.schedule_start ASC
         LIMIT 100"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $appts[] = [
            'id'            => intval($row['schedule_id']),
            'ticket_id'     => intval($row['ticket_id']),
            'number'        => intval($row['ticket_number']),
            'subject'       => $row['ticket_subject'],
            'schedule'      => $row['schedule_start'],
            'schedule_end'  => $row['schedule_end'],
            'onsite'        => (bool)$row['schedule_onsite'],
            'notes'         => $row['schedule_notes'],
            'priority'      => $row['ticket_priority'],
            'status'        => $row['ticket_status_name'],
            'status_color'  => $row['ticket_status_color'],
            'client'        => $row['client_name'],
            'assigned_to'   => $row['assigned_to_name'],
            // On-site contact + client's primary location — for the app's "Drive"/"Call"
            // appointment actions. The ticket's own contact (not just any client contact),
            // same source tickets.php uses for ticket detail's contact_name/contact_phone.
            'contact_name'  => $row['contact_name'],
            'contact_phone' => $row['contact_phone'] ?: $row['contact_mobile'],
            'address'       => $row['location_address'],
            'city'          => $row['location_city'],
            'state'         => $row['location_state'],
        ];
    }
    api_response(200, $appts);
}

if ($method === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $ticket_id = intval($body['ticket_id'] ?? 0);
    $start     = trim($body['schedule_start'] ?? '');
    $end_raw   = trim($body['schedule_end'] ?? '');
    $onsite    = !empty($body['onsite']) ? 1 : 0;
    $notes     = mysqli_real_escape_string($mysqli, trim($body['notes'] ?? ''));

    if (!$ticket_id) api_error(400, 'ticket_id required');
    if (!$start || !strtotime($start)) api_error(400, 'schedule_start required (Y-m-d H:i:s)');
    if ($end_raw && !strtotime($end_raw)) api_error(400, 'Invalid schedule_end');

    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT ticket_client_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_archived_at IS NULL LIMIT 1"));
    if (!$ticket) api_error(404, 'Ticket not found');

    $client_id = intval($ticket['ticket_client_id']);
    if (!api_client_scope_ok($client_id)) api_error(403, 'Access denied');

    $start_esc = mysqli_real_escape_string($mysqli, $start);
    $end_sql   = $end_raw ? "'" . mysqli_real_escape_string($mysqli, $end_raw) . "'" : 'NULL';

    mysqli_query($mysqli,
        "INSERT INTO ticket_schedules
         (schedule_ticket_id, schedule_start, schedule_end, schedule_onsite, schedule_tech_id, schedule_notes, schedule_created_by)
         VALUES ($ticket_id, '$start_esc', $end_sql, $onsite, $uid, '$notes', $uid)"
    );
    $schedule_id = mysqli_insert_id($mysqli);
    syncScheduleEntryToOutlook($schedule_id);

    logAction("Ticket", "Edit", "Added appointment to ticket #$ticket_id", intval($ticket['ticket_client_id']), $ticket_id);

    api_response(201, ['id' => $schedule_id]);
}

api_error(405, 'Method not allowed');
