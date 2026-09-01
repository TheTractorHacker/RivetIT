<?php
/*
 * CRON - Outlook Schedule Sync (pull direction)
 *
 * syncScheduleEntryToOutlook() (functions.php) already pushes ITFlow appointment
 * changes out to each tech's Outlook calendar (ITFlow -> Outlook). This script
 * is the other direction: it looks at every appointment ITFlow has already
 * pushed to Outlook (ticket_schedules rows with a schedule_outlook_event_id)
 * and checks whether the tech moved or deleted that event directly in Outlook,
 * pulling the change back into ticket_schedules. Together the two make
 * appointment scheduling two-way.
 */

// Start the timer
$script_start_time = microtime(true);

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Get ITFlow config & helper functions
require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

// Get settings for the "default" company
require_once "../includes/load_global_settings.php";

// System temp directory & lock
$temp_dir = sys_get_temp_dir();
$lock_file_path = "{$temp_dir}/itflow_outlook_schedule_sync_{$installation_id}.lock";

if (file_exists($lock_file_path)) {
    $file_age = time() - filemtime($lock_file_path);
    if ($file_age > 300) {
        unlink($lock_file_path);
        logApp("Cron-Outlook-Schedule-Sync", "warning", "Cron Outlook Schedule Sync detected a lock file was present but was over 5 minutes old so it removed it.");
    } else {
        exit("Script is already running. Exiting.");
    }
}
file_put_contents($lock_file_path, "Locked");

register_shutdown_function(function() use ($lock_file_path) {
    if (file_exists($lock_file_path)) {
        @unlink($lock_file_path);
    }
});

$tz_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1 LIMIT 1"));
$tz = ($tz_row && $tz_row['config_timezone']) ? $tz_row['config_timezone'] : 'America/Chicago';

// Only appointments ITFlow has already pushed to Outlook, still active, not too far in the past.
$sql = mysqli_query($mysqli,
    "SELECT ts.schedule_id, ts.schedule_start, ts.schedule_end, ts.schedule_tech_id, ts.schedule_outlook_event_id,
            t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject
     FROM ticket_schedules ts
     LEFT JOIN tickets t ON t.ticket_id = ts.schedule_ticket_id
     WHERE ts.schedule_archived_at IS NULL
       AND t.ticket_archived_at IS NULL
       AND ts.schedule_outlook_event_id IS NOT NULL
       AND ts.schedule_outlook_event_id != ''
       AND ts.schedule_start >= DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY ts.schedule_tech_id ASC"
);

$checked = 0;
$updated = 0;
$cancelled = 0;
$failed = 0;

// Reuse one access token per tech across all their appointments instead of one per appointment.
$tokens_by_tech = [];

while ($row = mysqli_fetch_assoc($sql)) {
    $checked++;

    $tech_id = intval($row['schedule_tech_id']);
    if (!$tech_id) {
        continue;
    }

    if (!array_key_exists($tech_id, $tokens_by_tech)) {
        $tokens_by_tech[$tech_id] = getOutlookAccessToken($tech_id);
    }
    $access_token = $tokens_by_tech[$tech_id];
    if (!$access_token) {
        // Tech isn't connected (or their connection was just revoked) - nothing to pull from.
        continue;
    }

    $schedule_id = intval($row['schedule_id']);
    $event_id    = $row['schedule_outlook_event_id'];

    $ch = curl_init("https://graph.microsoft.com/v1.0/me/events/" . rawurlencode($event_id));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $access_token",
            "Prefer: outlook.timezone=\"$tz\"",
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw_response = curl_exec($ch);
    $http_code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $event = json_decode($raw_response, true);

    $ticket_id     = intval($row['ticket_id']);
    $ticket_label  = $row['ticket_prefix'] . $row['ticket_number'];

    // Deleted directly in Outlook (a plain single event that no longer exists returns 404;
    // isCancelled covers the rarer case where Outlook marks it cancelled instead of removing it).
    if ($http_code === 404 || (!empty($event['isCancelled']))) {
        mysqli_query($mysqli, "UPDATE ticket_schedules SET schedule_archived_at = NOW() WHERE schedule_id = $schedule_id");

        if ($ticket_id) {
            $note = mysqli_real_escape_string($mysqli,
                "Appointment on " . date('M j, Y g:ia', strtotime($row['schedule_start'])) . " was deleted/cancelled directly in Outlook and has been removed from the schedule.");
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$note', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");
            publishTicketEvent($ticket_id, 'reply', ['reply_type' => 'System', 'by' => 'Outlook Sync', 'by_type' => 'system']);
        }

        $cancelled++;
        continue;
    }

    if ($http_code !== 200 || empty($event['start']['dateTime']) || empty($event['end']['dateTime'])) {
        error_log("ITFlow: Outlook schedule pull failed for schedule $schedule_id (HTTP $http_code): " . json_encode($event['error'] ?? $raw_response));
        $failed++;
        continue;
    }

    // Graph returns fractional seconds (e.g. "2026-07-18T09:00:00.0000000") - strtotime handles it fine.
    $graph_start = strtotime($event['start']['dateTime']);
    $graph_end   = strtotime($event['end']['dateTime']);
    $our_start   = strtotime($row['schedule_start']);
    $our_end     = $row['schedule_end'] ? strtotime($row['schedule_end']) : null;

    // Small tolerance for formatting/rounding differences, not a real reschedule.
    $start_changed = abs($graph_start - $our_start) > 60;
    $end_changed   = $our_end !== null && abs($graph_end - $our_end) > 60;

    if ($start_changed || $end_changed) {
        $new_start_sql = date('Y-m-d H:i:s', $graph_start);
        $new_end_sql   = date('Y-m-d H:i:s', $graph_end);

        mysqli_query($mysqli, "UPDATE ticket_schedules SET schedule_start = '$new_start_sql', schedule_end = '$new_end_sql' WHERE schedule_id = $schedule_id");

        if ($ticket_id) {
            $note = mysqli_real_escape_string($mysqli,
                "Appointment rescheduled in Outlook: was " . date('M j, Y g:ia', $our_start) . ", now " . date('M j, Y g:ia', $graph_start) . ".");
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$note', ticket_reply_type = 'System', ticket_reply_time_worked = '00:01:00', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");
            publishTicketEvent($ticket_id, 'reply', ['reply_type' => 'System', 'by' => 'Outlook Sync', 'by_type' => 'system']);
        }

        $updated++;
    }
}

logApp("Cron-Outlook-Schedule-Sync", "info", "Checked $checked appointment(s): $updated updated, $cancelled cancelled, $failed failed.");

$script_end_time = microtime(true);
echo "Outlook Schedule Sync complete in " . round($script_end_time - $script_start_time, 2) . "s - checked: $checked, updated: $updated, cancelled: $cancelled, failed: $failed\n";
