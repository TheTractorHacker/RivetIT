<?php
/*
 * crm_reminders.php - CRM Engagement follow-up reminder worker
 *
 * Finds crm_activities whose reminder time has passed, that are not completed
 * and not yet reminded, and creates an in-app notification for the activity
 * owner (falling back to all admins when no owner is set). Each processed row
 * is stamped activity_reminded_at so it only ever fires once.
 *
 * WIRING: required from cron/cron.php on its normal hourly cadence.
 * It is also runnable standalone from the CLI:  php cron/crm_reminders.php
 * The bootstrap below only loads config/functions when they are not already
 * present, so it is safe to require_once from cron.php after its bootstrap.
 */

// True when executed directly (config/functions not yet loaded by cron.php).
$crm_reminders_standalone = !isset($mysqli);

if ($crm_reminders_standalone) {
    // Set working directory to the directory this cron script lives at.
    chdir(dirname(__FILE__));

    // Ensure we're running from command line
    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";
}

// Find due, incomplete, not-yet-reminded follow-ups.
$crm_reminder_result = mysqli_query($mysqli, "SELECT activity_id, activity_type, activity_subject, activity_related_type, activity_related_id, activity_owner
    FROM crm_activities
    WHERE activity_reminder_at IS NOT NULL
      AND activity_reminder_at <= NOW()
      AND activity_completed = 0
      AND activity_reminded_at IS NULL");

$crm_reminders_sent = 0;

while ($crm_activity = mysqli_fetch_assoc($crm_reminder_result)) {
    $crm_activity_id   = intval($crm_activity['activity_id']);
    $crm_activity_type = ucfirst(strtolower((string)$crm_activity['activity_type']));
    $crm_subject       = trim((string)$crm_activity['activity_subject']);
    $crm_related_type  = $crm_activity['activity_related_type'];
    $crm_related_id    = intval($crm_activity['activity_related_id']);
    $crm_owner         = intval($crm_activity['activity_owner']);

    // Resolve the owning client and a deep-link action for the notification.
    if ($crm_related_type === 'contact') {
        $crm_who_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_name, contact_client_id FROM contacts WHERE contact_id = $crm_related_id LIMIT 1"));
        $crm_client_id = intval($crm_who_row['contact_client_id'] ?? 0);
        $crm_who = sanitizeInput($crm_who_row['contact_name'] ?? 'a contact');
        $crm_action = "contact_details.php?client_id=$crm_client_id&contact_id=$crm_related_id";
    } else {
        $crm_who_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $crm_related_id LIMIT 1"));
        $crm_client_id = $crm_related_id;
        $crm_who = sanitizeInput($crm_who_row['client_name'] ?? 'a client');
        $crm_action = "client_overview.php?client_id=$crm_client_id";
    }

    $crm_details = "Follow-up reminder: $crm_activity_type with $crm_who";
    if ($crm_subject !== '') {
        $crm_details .= " - $crm_subject";
    }

    // Notify the owner if we have one; otherwise fall back to all admins.
    if ($crm_owner > 0 && function_exists('notifyUser')) {
        notifyUser($crm_owner, "CRM Follow-up", $crm_details, $crm_action, $crm_client_id, $crm_activity_id);
    } else {
        appNotify("CRM Follow-up", $crm_details, $crm_action, $crm_client_id, $crm_activity_id);
    }

    // Mark reminded so it never fires again.
    mysqli_query($mysqli, "UPDATE crm_activities SET activity_reminded_at = NOW() WHERE activity_id = $crm_activity_id");

    $crm_reminders_sent++;
}

if ($crm_reminders_standalone) {
    echo "CRM follow-up reminders processed: $crm_reminders_sent\n";
}
