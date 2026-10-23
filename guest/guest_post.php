<?php

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";

session_start();

require_once "../includes/inc_set_timezone.php"; // Must be included after session_start to work

if (isset($_POST['reopen_ticket'], $_POST['url_key'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $url_key = sanitizeInput($_POST['url_key']);

    // Select only the necessary fields
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key' AND ticket_resolved_at IS NOT NULL AND ticket_closed_at IS NULL");

    if (mysqli_num_rows($sql) == 1) {
        // Update the ticket
        mysqli_query($mysqli, "UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 2, ticket_resolved_at = NULL WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key'");

        // Add reply
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket reopened by department (guest URL).', ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

        customAction('ticket_update', $ticket_id);

        flash_alert("Ticket reopened");

        redirect();

    } else {
        echo "Invalid!!";
    }

}

if (isset($_POST['close_ticket'], $_POST['url_key'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $url_key = sanitizeInput($_POST['url_key']);

    // Select only the necessary fields
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key' AND ticket_resolved_at IS NOT NULL AND ticket_closed_at IS NULL");

    if (mysqli_num_rows($sql) == 1) {

        // Update the ticket
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_closed_at = NOW() WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key'");

        // Add reply
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Ticket closed by department (guest URL).', ticket_reply_type = 'Internal', ticket_reply_by = 0, ticket_reply_ticket_id = $ticket_id");

        customAction('ticket_close', $ticket_id);

        flash_alert("Ticket closed");

        redirect();

    } else {
        echo "Invalid!!";
    }
}

if (isset($_POST['add_ticket_feedback'], $_POST['url_key'])) {

    if (empty($config_ticket_csat_enable)) {
        redirect();
    }

    $ticket_id = intval($_POST['ticket_id']);
    $url_key = sanitizeInput($_POST['url_key']);
    $rating = intval($_POST['csat_rating'] ?? 0);
    $comment_raw = substr(trim($_POST['csat_comment'] ?? ''), 0, 1000);
    $comment = sanitizeInput($comment_raw);

    if ($rating < 1 || $rating > 5) {
        flash_alert("Please select a rating.", "danger");
        redirect();
    }

    // Select only the necessary fields
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key' AND ticket_closed_at IS NOT NULL");

    if (mysqli_num_rows($sql) == 1) {
        applyCsatRating($mysqli, $ticket_id, $rating, $comment, 'a guest', $config_ticket_csat_low_rating_threshold);

        customAction('ticket_feedback', $ticket_id);

        flash_alert("Feedback recorded - thank you");

        redirect();

    } else {
        echo "Invalid!!";
    }

}

if (isset($_POST['add_ticket_csat_comment'], $_POST['url_key'])) {

    $ticket_id = intval($_POST['ticket_id']);
    $url_key = sanitizeInput($_POST['url_key']);
    $comment_raw = substr(trim($_POST['csat_comment'] ?? ''), 0, 1000);
    $comment = sanitizeInput($comment_raw);

    // Only lets someone add a comment to a rating that already exists and has no
    // comment yet (the one-click email star links can't carry free text, so the
    // comment is a separate follow-up step on the landing page) - never lets this
    // action set/overwrite the rating itself.
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key' AND ticket_csat_rating IS NOT NULL AND (ticket_csat_comment IS NULL OR ticket_csat_comment = '')");

    if (mysqli_num_rows($sql) == 1 && $comment !== '') {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_csat_comment = '$comment' WHERE ticket_id = $ticket_id AND ticket_url_key = '$url_key'");
        flash_alert("Comment added - thank you");
    }

    redirect();

}

if (isset($_GET['approve_ticket_task'])) {

    $task_id = intval($_GET['approve_ticket_task']);
    $approval_id = intval($_GET['approval_id']);
    $url_key = sanitizeInput($_GET['approval_url_key']);

    $approval_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM task_approvals LEFT JOIN tasks on task_id = approval_task_id WHERE approval_id = $approval_id AND approval_task_id = $task_id AND approval_url_key = '$url_key' AND approval_status = 'pending' AND approval_scope = 'client'"));

    $task_name = nullable_htmlentities($approval_row['task_name']);
    $scope = nullable_htmlentities($approval_row['approval_scope']);
    $type = nullable_htmlentities($approval_row['approval_type']);
    $required_user = intval($approval_row['approval_required_user_id']);
    $created_by = intval($approval_row['approval_created_by']);
    $ticket_id = intval($approval_row['task_ticket_id']);

    if (!$approval_row) {
        exit("Cannot find/approve that task");
    }

    // Approve
    mysqli_query($mysqli, "UPDATE task_approvals SET approval_status = 'approved', approval_approved_by = $required_user WHERE approval_id = $approval_id AND approval_task_id = $task_id AND approval_url_key = '$url_key' AND approval_status = 'pending' AND approval_scope = 'client'");

    // Notify tech
    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = 'Guest approved ticket task $task_name', notification_action = 'ticket.php?ticket_id=$ticket_id', notification_user_id = $created_by");

    // Logging
    logAction("Task", "Edit", "Guest user approved task $task_name via link (approval $approval_id)", 0, $task_id);

    flash_alert("Task Approved");
    redirect();

}
if (isset($_POST['sign_outtake'], $_POST['outtake_sign_token'])) {

    require_once "../includes/outtake_functions.php";

    $token = sanitizeInput($_POST['outtake_sign_token']);
    $signed_name = $_POST['signed_name'] ?? '';
    $signature = isset($_POST['outtake_signature']) ? $_POST['outtake_signature'] : '';

    $sql = mysqli_query($mysqli, "SELECT outtake_id FROM ticket_outtake_forms WHERE outtake_sign_token = '$token' AND outtake_signed_at IS NULL LIMIT 1");

    if (mysqli_num_rows($sql) !== 1) {
        echo "<div style='font-family:sans-serif;padding:60px;text-align:center;'><h2>This form has already been signed or the link is invalid.</h2></div>";
        exit;
    }

    $outtake_id = intval(mysqli_fetch_assoc($sql)['outtake_id']);

    $result = signOuttakeForm($mysqli, $outtake_id, $signed_name, $signature);

    if (!$result['ok']) {
        echo "<!doctype html><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link rel=\"stylesheet\" href=\"/plugins/bootstrap5/css/bootstrap.min.css\"><script src=\"/js/rivet_dialog.js\"></script><script nonce=\"" . htmlspecialchars($csp_nonce ?? '') . "\">RivetDialog.alert(" . json_encode($result['error'], JSON_HEX_TAG | JSON_HEX_AMP) . ").then(function () { history.back(); });</script>";
        exit;
    }

    // If an agent is signed in (in-person signing), send them back to the ticket
    if (!empty($_SESSION['user_id'])) {
        $redirect_url = "/agent/ticket.php?ticket_id={$result['ticket_id']}" . ($result['client_id'] ? "&client_id={$result['client_id']}" : '');
        header("Location: $redirect_url");
    } else {
        header("Location: outtake_sign.php?token=$token");
    }
    exit;
}

if (isset($_POST['sign_worksheet'], $_POST['worksheet_sign_token'])) {

    $token = sanitizeInput($_POST['worksheet_sign_token']);
    $signed_name = sanitizeInput($_POST['signed_name']);

    $sql = mysqli_query($mysqli, "SELECT tw.worksheet_id, tw.worksheet_template_id, tw.worksheet_ticket_id FROM ticket_worksheets tw WHERE tw.worksheet_sign_token = '$token' AND tw.worksheet_is_outtake = 1 AND tw.worksheet_signed_at IS NULL LIMIT 1");

    if (mysqli_num_rows($sql) !== 1) {
        echo "<!doctype html><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link rel=\"stylesheet\" href=\"/plugins/bootstrap5/css/bootstrap.min.css\"><script src=\"/js/rivet_dialog.js\"></script><script nonce=\"" . htmlspecialchars($csp_nonce ?? '') . "\">RivetDialog.alert('This form has already been signed or the link is invalid.').then(function () { history.back(); });</script>";
        exit;
    }

    $ws = mysqli_fetch_assoc($sql);
    $worksheet_id = intval($ws['worksheet_id']);
    $template_id = intval($ws['worksheet_template_id']);

    // Save field responses
    $fields = mysqli_query($mysqli, "SELECT field_id, field_type FROM worksheet_template_fields WHERE field_template_id = $template_id ORDER BY field_order");
    $main_sig = null;
    while ($f = mysqli_fetch_assoc($fields)) {
        $fid = intval($f['field_id']);
        $key = "field_$fid";
        $val = isset($_POST[$key]) ? sanitizeInput($_POST[$key]) : '';
        if ($f['field_type'] === 'signature' && !empty($val) && preg_match('/^data:image\/(png|jpeg|jpg|gif);base64,[A-Za-z0-9+\/=]+$/', $val)) {
            $main_sig = $val;
        }
        $existing = mysqli_fetch_row(mysqli_query($mysqli, "SELECT response_id FROM ticket_worksheet_responses WHERE response_worksheet_id = $worksheet_id AND response_field_id = $fid LIMIT 1"));
        if ($existing) {
            mysqli_query($mysqli, "UPDATE ticket_worksheet_responses SET response_value = '$val' WHERE response_id = {$existing[0]}");
        } else {
            mysqli_query($mysqli, "INSERT INTO ticket_worksheet_responses SET response_worksheet_id = $worksheet_id, response_field_id = $fid, response_value = '$val'");
        }
    }

    // Use drawn main signature if provided, fall back to any signature field
    if (!empty($_POST['main_signature'])) {
        $main_sig = $_POST['main_signature'];
        if (!preg_match('/^data:image\/(png|jpeg|jpg|gif);base64,[A-Za-z0-9+\/=]+$/', $main_sig)) {
            $main_sig = '';
        }
    }
    $sig_escaped = $main_sig ? mysqli_real_escape_string($mysqli, $main_sig) : '';
    $sig_sql = $sig_escaped ? "worksheet_signature = '$sig_escaped'," : '';
    mysqli_query($mysqli, "UPDATE ticket_worksheets SET worksheet_signed_name = '$signed_name', worksheet_signed_at = NOW(), $sig_sql worksheet_completed_at = NOW() WHERE worksheet_id = $worksheet_id");

    logAction("Worksheet", "Sign", "Outtake form signed by $signed_name");

    // Redirect to a confirmation page
    header("Location: worksheet_sign.php?token=$token");
    exit;
}
