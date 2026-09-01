<?php

/*
 * ITFlow - GET/POST request handler for misc (functionality that doesn't quite fit elsewhere)
 */

// Records to show per page

if(isset($_POST['change_records_per_page'])){

    validateCSRFToken($_POST['csrf_token']);

    $records_per_page = intval($_POST['change_records_per_page']);

    mysqli_query($mysqli,"UPDATE user_settings SET user_config_records_per_page = $records_per_page WHERE user_id = $session_user_id");

    redirect();

}

// In app notifications

if (isset($_GET['dismiss_notification'])) {

    validateCSRFToken($_GET['csrf_token']);

    $notification_id = intval($_GET['dismiss_notification']);

    mysqli_query($mysqli,"UPDATE notifications SET notification_dismissed_at = NOW(), notification_dismissed_by = $session_user_id WHERE notification_user_id = $session_user_id AND notification_id = $notification_id");

    // Logging
    logAction("Notification", "Dismiss", "$session_name dismissed notification");

    $_SESSION['alert_message'] = "Notification Dismissed";

    redirect();

}

if (isset($_GET['dismiss_all_notifications'])) {

    validateCSRFToken($_GET['csrf_token']);

    $sql = mysqli_query($mysqli,"SELECT * FROM notifications WHERE notification_user_id = $session_user_id AND notification_dismissed_at IS NULL");

    $num_notifications = mysqli_num_rows($sql);

    while($row = mysqli_fetch_assoc($sql)) {
        $notification_id = intval($row['notification_id']);
        $notification_dismissed_at = sanitizeInput($row['notification_dismissed_at']);

        mysqli_query($mysqli,"UPDATE notifications SET notification_dismissed_at = NOW(), notification_dismissed_by = $session_user_id WHERE notification_id = $notification_id");

    }

    // Logging
    logAction("Notification", "Dismiss", "$session_name dismissed $num_notifications notifications");

    $_SESSION['alert_message'] = "<strong>$num_notifications</strong> Notifications Dismissed";

    redirect();

}

require_once __DIR__ . '/../includes/firebase.php';

// Push a single in-app notification to mobile devices
if (isset($_GET['push_notification'])) {
    validateCSRFToken($_GET['csrf_token']);
    $notification_id = intval($_GET['push_notification']);
    $sql_notif = mysqli_query($mysqli, "SELECT notification, notification_type FROM notifications WHERE notification_id = $notification_id AND notification_user_id = $session_user_id");
    if ($row = mysqli_fetch_assoc($sql_notif)) {
        $msg   = $row['notification'];
        $type  = $row['notification_type'];
        $sent  = false;
        $sql_tokens = mysqli_query($mysqli, "SELECT token_fcm_token FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL AND token_fcm_token != ''");
        while ($tok = mysqli_fetch_assoc($sql_tokens)) {
            if (firebase_send_push($tok['token_fcm_token'], $type, $msg, ['type' => 'notification'])) {
                $sent = true;
            }
        }
        $_SESSION['alert_message'] = $sent ? 'Notification sent to your devices.' : 'No registered devices or push delivery failed.';
    }
    redirect();
}

// Test push notification from the notifications page
if (isset($_POST['test_push_notification'])) {
    validateCSRFToken($_POST['csrf_token']);
    $sent = false;
    $sql_tokens = mysqli_query($mysqli, "SELECT token_fcm_token FROM api_tokens WHERE token_user_id = $session_user_id AND token_fcm_token IS NOT NULL AND token_fcm_token != ''");
    while ($tok = mysqli_fetch_assoc($sql_tokens)) {
        if (firebase_send_push($tok['token_fcm_token'], 'ITFlow Test', 'Push notifications are working!', ['type' => 'test'])) {
            $sent = true;
        }
    }
    if ($sent) {
        $_SESSION['alert_message'] = '<i class="fas fa-bell mr-2"></i>Test push notification sent to your registered devices.';
    } else {
        $_SESSION['alert_message'] = 'No registered devices found, or push delivery failed.';
    }
    redirect();
}

// Revoke sharing (sharing itself is done via ajax.php)
if (isset($_GET['deactivate_shared_item'])) {

    validateCSRFToken($_GET['csrf_token']);

    $item_id = intval($_GET['deactivate_shared_item']);

    // Get details of the shared link
    $sql = mysqli_query($mysqli, "SELECT item_type, item_related_id, item_client_id FROM shared_items WHERE item_id = $item_id");
    $row = mysqli_fetch_assoc($sql);
    $item_type = sanitizeInput($row['item_type']);
    $item_related_id = intval($row['item_related_id']);
    $client_id = intval($row['item_client_id']);

    enforceClientAccess();

    // Deactivate item id
    mysqli_query($mysqli, "DELETE FROM shared_items WHERE item_id = $item_id");

    // Logging
    logAction("Sharing", "Delete", "$session_name deactivated shared $item_type link Item ID: $item_related_id. Share ID $item_id", $client_id, $item_id);

    $_SESSION['alert_message'] = "Share Link deactivated";

    redirect();
}
