<?php
/**
 * iCal subscription feed for Outlook / Apple Calendar / Google Calendar.
 * URL: /agent/calendar_feed.php?token=HMAC_TOKEN
 * Token = hash_hmac('sha256', user_id, installation_id)
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

// Load the configured timezone from ITFlow settings
$tz_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1"));
$tz_id  = ($tz_row && $tz_row['config_timezone']) ? $tz_row['config_timezone'] : 'America/Chicago';
date_default_timezone_set($tz_id);

$token = isset($_GET['token']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['token']) : '';

if (!$token || strlen($token) < 32) {
    http_response_code(401);
    exit('Unauthorized');
}

// Verify HMAC token against all active users
$users_result = mysqli_query($mysqli,
    "SELECT user_id, user_name FROM users
     WHERE user_status = 1 AND user_archived_at IS NULL AND user_type = 1"
);

$user_id   = 0;
$user_name = '';
while ($u = mysqli_fetch_assoc($users_result)) {
    $expected = hash_hmac('sha256', intval($u['user_id']), $installation_id);
    if (hash_equals($expected, $token)) {
        $user_id   = intval($u['user_id']);
        $user_name = $u['user_name'];
        break;
    }
}

if (!$user_id) {
    http_response_code(401);
    exit('Unauthorized');
}

// Fetch this user's scheduled tickets (upcoming + past 30 days)
$cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
$result = mysqli_query($mysqli,
    "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject,
            t.ticket_schedule, t.ticket_schedule_end, t.ticket_appointment_notes,
            t.ticket_onsite, t.ticket_priority,
            c.client_name,
            l.location_address
     FROM tickets t
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
     LEFT JOIN locations l ON co.contact_location_id = l.location_id
     WHERE t.ticket_schedule IS NOT NULL
       AND t.ticket_schedule >= '$cutoff'
       AND t.ticket_assigned_to = $user_id
       AND t.ticket_archived_at IS NULL
     ORDER BY t.ticket_schedule ASC"
);

// Format DB datetime as iCal local time (no Z — TZID handles the zone)
function ical_dt($sql_dt) {
    return date('Ymd\THis', strtotime($sql_dt));
}

function ical_escape($str) {
    $str = str_replace('\\', '\\\\', $str);
    $str = str_replace(',',  '\,',   $str);
    $str = str_replace(';',  '\;',   $str);
    $str = str_replace("\n", '\n',   $str);
    return $str;
}

function ical_fold($line) {
    $out = '';
    while (strlen($line) > 75) {
        $out .= substr($line, 0, 75) . "\r\n ";
        $line = substr($line, 75);
    }
    return $out . $line . "\r\n";
}

$now_stamp = date('Ymd\THis');
$cal_name  = ical_escape($user_name . "'s Schedule");

$ical  = "BEGIN:VCALENDAR\r\n";
$ical .= "VERSION:2.0\r\n";
$ical .= "PRODID:-//ITFlow//Ticket Schedule//EN\r\n";
$ical .= "METHOD:PUBLISH\r\n";
$ical .= "CALSCALE:GREGORIAN\r\n";
$ical .= "X-WR-CALNAME:" . $cal_name . "\r\n";
$ical .= "X-WR-TIMEZONE:" . $tz_id . "\r\n";

while ($row = mysqli_fetch_assoc($result)) {
    $tid   = intval($row['ticket_id']);
    $start = ical_dt($row['ticket_schedule']);
    $end   = $row['ticket_schedule_end'] ? ical_dt($row['ticket_schedule_end']) : $start;
    $uid   = 'ticket-' . $tid . '@' . $config_base_url;

    $summary = $row['ticket_prefix'] . $row['ticket_number'] . ': ' . $row['ticket_subject'];
    if ($row['client_name']) $summary .= ' - ' . $row['client_name'];

    $url  = 'https://' . $config_base_url . '/agent/ticket.php?ticket_id=' . $tid;
    $desc = ($row['ticket_appointment_notes'] ? $row['ticket_appointment_notes'] . '\n' : '') . $url;
    $loc  = $row['location_address'] ?: ($row['ticket_onsite'] ? 'Onsite' : 'Remote');

    $ical .= "BEGIN:VEVENT\r\n";
    $ical .= ical_fold("UID:" . $uid);
    $ical .= ical_fold("DTSTAMP;TZID=$tz_id:" . $now_stamp);
    $ical .= ical_fold("DTSTART;TZID=$tz_id:" . $start);
    $ical .= ical_fold("DTEND;TZID=$tz_id:" . $end);
    $ical .= ical_fold("SUMMARY:"     . ical_escape($summary));
    $ical .= ical_fold("DESCRIPTION:" . ical_escape($desc));
    $ical .= ical_fold("LOCATION:"    . ical_escape($loc));
    $ical .= "URL:" . $url . "\r\n";
    if ($row['ticket_priority'] == 'High') $ical .= "PRIORITY:1\r\n";
    $ical .= "END:VEVENT\r\n";
}

// Fetch this user's appointments from the modern multi-entry "Appointment"
// feature (ticket_schedules — used by the ticket detail page's Schedule
// section and the mobile app), which the legacy ticket_schedule columns above
// never capture since that flow no longer writes to them.
$result_ts = mysqli_query($mysqli,
    "SELECT s.schedule_id, s.schedule_start, s.schedule_end, s.schedule_onsite, s.schedule_notes,
            t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_priority,
            c.client_name,
            l.location_address
     FROM ticket_schedules s
     JOIN tickets t ON t.ticket_id = s.schedule_ticket_id
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     LEFT JOIN contacts co ON t.ticket_contact_id = co.contact_id
     LEFT JOIN locations l ON co.contact_location_id = l.location_id
     WHERE s.schedule_archived_at IS NULL
       AND t.ticket_archived_at IS NULL
       AND s.schedule_start >= '$cutoff'
       AND s.schedule_tech_id = $user_id
     ORDER BY s.schedule_start ASC"
);

while ($row = mysqli_fetch_assoc($result_ts)) {
    $tid = intval($row['ticket_id']);
    $sid = intval($row['schedule_id']);
    $start = ical_dt($row['schedule_start']);
    $end   = $row['schedule_end'] ? ical_dt($row['schedule_end']) : $start;
    $uid   = 'schedule-' . $sid . '@' . $config_base_url;

    $summary = $row['ticket_prefix'] . $row['ticket_number'] . ': ' . $row['ticket_subject'];
    if ($row['client_name']) $summary .= ' - ' . $row['client_name'];

    $url  = 'https://' . $config_base_url . '/agent/ticket.php?ticket_id=' . $tid;
    $desc = ($row['schedule_notes'] ? $row['schedule_notes'] . '\n' : '') . $url;
    $loc  = $row['location_address'] ?: ($row['schedule_onsite'] ? 'Onsite' : 'Remote');

    $ical .= "BEGIN:VEVENT\r\n";
    $ical .= ical_fold("UID:" . $uid);
    $ical .= ical_fold("DTSTAMP;TZID=$tz_id:" . $now_stamp);
    $ical .= ical_fold("DTSTART;TZID=$tz_id:" . $start);
    $ical .= ical_fold("DTEND;TZID=$tz_id:" . $end);
    $ical .= ical_fold("SUMMARY:"     . ical_escape($summary));
    $ical .= ical_fold("DESCRIPTION:" . ical_escape($desc));
    $ical .= ical_fold("LOCATION:"    . ical_escape($loc));
    $ical .= "URL:" . $url . "\r\n";
    if ($row['ticket_priority'] == 'High') $ical .= "PRIORITY:1\r\n";
    $ical .= "END:VEVENT\r\n";
}

$ical .= "END:VCALENDAR\r\n";

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="itflow-schedule.ics"');
header('Cache-Control: no-store, no-cache');
header('Pragma: no-cache');
echo $ical;
