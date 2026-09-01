<?php

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

$ticket_id = intval($_GET['ticket_id'] ?? 0);

if ($ticket_id) {
    // Appointments for a specific ticket; enforce client-scoped API key access via tickets JOIN
    $sql = mysqli_query($mysqli,
        "SELECT
            ts.schedule_id           AS appointment_id,
            ts.schedule_ticket_id    AS appointment_ticket_id,
            ts.schedule_start        AS appointment_start,
            ts.schedule_end          AS appointment_end,
            ts.schedule_onsite       AS appointment_onsite,
            ts.schedule_tech_id      AS appointment_tech_id,
            u.user_name              AS appointment_tech_name,
            ts.schedule_notes        AS appointment_notes
         FROM ticket_schedules ts
         LEFT JOIN users u ON ts.schedule_tech_id = u.user_id
         INNER JOIN tickets t ON ts.schedule_ticket_id = t.ticket_id
         WHERE ts.schedule_ticket_id = '$ticket_id'
         AND t.ticket_client_id LIKE '$client_id'
         ORDER BY ts.schedule_start ASC
         LIMIT $limit OFFSET $offset"
    );
} else {
    // All appointments for the key's client scope
    $sql = mysqli_query($mysqli,
        "SELECT
            ts.schedule_id           AS appointment_id,
            ts.schedule_ticket_id    AS appointment_ticket_id,
            ts.schedule_start        AS appointment_start,
            ts.schedule_end          AS appointment_end,
            ts.schedule_onsite       AS appointment_onsite,
            ts.schedule_tech_id      AS appointment_tech_id,
            u.user_name              AS appointment_tech_name,
            ts.schedule_notes        AS appointment_notes
         FROM ticket_schedules ts
         LEFT JOIN users u ON ts.schedule_tech_id = u.user_id
         INNER JOIN tickets t ON ts.schedule_ticket_id = t.ticket_id
         WHERE t.ticket_client_id LIKE '$client_id'
         ORDER BY ts.schedule_start ASC
         LIMIT $limit OFFSET $offset"
    );
}

require_once '../read_output.php';
