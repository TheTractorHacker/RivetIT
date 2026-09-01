<?php

/*
 * ITFlow - GET/POST request handler for CRM Engagement Campaigns
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

require_once __DIR__ . '/../modals/crm/crm_helpers.php';

if (isset($_POST['add_campaign'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $campaign_name = sanitizeInput($_POST['name'] ?? '');
    if ($campaign_name === '') {
        $campaign_name = 'Untitled Campaign';
    }
    $segment_id = intval($_POST['segment_id'] ?? 0);
    $segment_id_sql = $segment_id ? $segment_id : "NULL";
    $subject = sanitizeInput($_POST['subject'] ?? '');
    // Body is an HTML email body authored by trusted staff - keep markup, escape for SQL only.
    $body = mysqli_real_escape_string($mysqli, (string)($_POST['body'] ?? ''));

    mysqli_query($mysqli, "INSERT INTO crm_campaigns SET
        campaign_name = '$campaign_name',
        campaign_segment_id = $segment_id_sql,
        campaign_subject = '$subject',
        campaign_body = '$body',
        campaign_status = 'draft'");

    $campaign_id = mysqli_insert_id($mysqli);

    logAction("CRM Campaign", "Create", "$session_name created campaign $campaign_name", 0, $campaign_id);

    flash_alert("Campaign <strong>$campaign_name</strong> saved as draft");

    redirect("campaigns.php?campaign_id=$campaign_id");
}

if (isset($_POST['edit_campaign'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $campaign_id = intval($_POST['campaign_id']);

    // Sent campaigns are locked to preserve an accurate send record.
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT campaign_status FROM crm_campaigns WHERE campaign_id = $campaign_id"));
    if ($existing && $existing['campaign_status'] === 'sent') {
        flash_alert("Sent campaigns cannot be edited", "warning");
        redirect("campaigns.php?campaign_id=$campaign_id");
    }

    $campaign_name = sanitizeInput($_POST['name'] ?? '');
    if ($campaign_name === '') {
        $campaign_name = 'Untitled Campaign';
    }
    $segment_id = intval($_POST['segment_id'] ?? 0);
    $segment_id_sql = $segment_id ? $segment_id : "NULL";
    $subject = sanitizeInput($_POST['subject'] ?? '');
    $body = mysqli_real_escape_string($mysqli, (string)($_POST['body'] ?? ''));

    mysqli_query($mysqli, "UPDATE crm_campaigns SET
        campaign_name = '$campaign_name',
        campaign_segment_id = $segment_id_sql,
        campaign_subject = '$subject',
        campaign_body = '$body'
        WHERE campaign_id = $campaign_id");

    logAction("CRM Campaign", "Edit", "$session_name edited campaign $campaign_name", 0, $campaign_id);

    flash_alert("Campaign <strong>$campaign_name</strong> updated");

    redirect("campaigns.php?campaign_id=$campaign_id");
}

if (isset($_POST['send_campaign'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_sales', 2);

    $campaign_id = intval($_POST['campaign_id']);

    $campaign = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM crm_campaigns WHERE campaign_id = $campaign_id LIMIT 1"));
    if (!$campaign) {
        flash_alert("Campaign not found", "error");
        redirect("campaigns.php");
    }

    if ($campaign['campaign_status'] === 'sent') {
        flash_alert("This campaign has already been sent", "warning");
        redirect("campaigns.php?campaign_id=$campaign_id");
    }

    $segment_id = intval($campaign['campaign_segment_id']);
    if (!$segment_id) {
        flash_alert("Select a target segment before sending", "warning");
        redirect("campaigns.php?campaign_id=$campaign_id");
    }

    if (empty($config_mail_from_email)) {
        flash_alert("No outbound 'from' email is configured (Settings &gt; Mail). Campaign not sent.", "error");
        redirect("campaigns.php?campaign_id=$campaign_id");
    }

    $segment = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT segment_criteria_json FROM crm_segments WHERE segment_id = $segment_id LIMIT 1"));
    $criteria = $segment ? crmCriteriaFromJson($segment['segment_criteria_json']) : [];

    // Only contacts with an email; scoped to the sender's client access.
    $recipients = crmResolveContacts($mysqli, $criteria, $client_access_string ?? '', $session_is_admin ?? false, true);

    $campaign_subject = $campaign['campaign_subject'];
    $campaign_body = $campaign['campaign_body'];

    // Unsubscribe-safe footer note (no per-contact opt-out field exists on contacts).
    $footer = "<br><br><hr><small style=\"color:#888\">You are receiving this message because you are a contact of $session_company_name. "
            . "If you would prefer not to receive these emails, please reply to this message and let us know.</small>";

    $sent_count = 0;
    $mail_batch = [];

    foreach ($recipients as $r) {
        $r_contact_id = intval($r['contact_id']);
        $r_email = trim((string)$r['contact_email']);
        $r_name = (string)$r['contact_name'];

        if (!filter_var($r_email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        // Materialize recipient row
        $r_email_sql = mysqli_real_escape_string($mysqli, $r_email);
        mysqli_query($mysqli, "INSERT INTO crm_campaign_recipients SET
            campaign_id = $campaign_id,
            contact_id = $r_contact_id,
            email = '$r_email_sql',
            sent = 1");

        $mail_batch[] = [
            'from' => $config_mail_from_email,
            'from_name' => $config_mail_from_name,
            'recipient' => $r_email,
            'recipient_name' => $r_name,
            'subject' => $campaign_subject,
            'body' => $campaign_body . $footer,
        ];

        $sent_count++;
    }

    if ($sent_count > 0) {
        addToMailQueue($mail_batch);
    }

    mysqli_query($mysqli, "UPDATE crm_campaigns SET campaign_status = 'sent', campaign_sent_at = NOW() WHERE campaign_id = $campaign_id");

    $campaign_name = sanitizeInput($campaign['campaign_name']);
    logAction("CRM Campaign", "Send", "$session_name sent campaign $campaign_name to $sent_count recipient(s)", 0, $campaign_id);

    flash_alert("Campaign <strong>$campaign_name</strong> queued to <strong>$sent_count</strong> recipient(s)");

    redirect("campaigns.php?campaign_id=$campaign_id");
}

if (isset($_GET['delete_campaign'])) {

    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_sales', 3);

    $campaign_id = intval($_GET['delete_campaign']);

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT campaign_name FROM crm_campaigns WHERE campaign_id = $campaign_id"));
    $campaign_name = sanitizeInput($row['campaign_name'] ?? '');

    mysqli_query($mysqli, "DELETE FROM crm_campaign_recipients WHERE campaign_id = $campaign_id");
    mysqli_query($mysqli, "DELETE FROM crm_campaigns WHERE campaign_id = $campaign_id");

    logAction("CRM Campaign", "Delete", "$session_name deleted campaign $campaign_name", 0, $campaign_id);

    flash_alert("Campaign <strong>$campaign_name</strong> deleted");

    redirect("campaigns.php");
}
