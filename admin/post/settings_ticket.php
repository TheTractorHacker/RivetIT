<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_ticket_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $config_ticket_prefix = sanitizeInput($_POST['config_ticket_prefix']);
    $config_ticket_next_number = intval($_POST['config_ticket_next_number']);
    $config_ticket_email_parse = intval($_POST['config_ticket_email_parse'] ?? 0);
    // config_ticket_email_parse_unknown_senders is no longer read/written here - the setting
    // is dead (superseded by the per-mailbox mailbox_parse_unknown_senders column, see
    // cron/ticket_email_parser.php and admin/mailbox.php). Its column/value is left untouched
    // in the DB rather than silently reset on every settings save.
    $config_ticket_default_billable = intval($_POST['config_ticket_default_billable'] ?? 0);
    $config_ticket_autoclose_hours = intval($_POST['config_ticket_autoclose_hours']);
    $config_ticket_new_ticket_notification_email = '';
    if (filter_var($_POST['config_ticket_new_ticket_notification_email'], FILTER_VALIDATE_EMAIL)) {
        $config_ticket_new_ticket_notification_email = sanitizeInput($_POST['config_ticket_new_ticket_notification_email']);
    }
    $config_ticket_default_view = intval($_POST['config_ticket_default_view']);
    $config_ticket_moving_columns = intval($_POST['config_ticket_moving_columns']);
    $config_ticket_ordering = intval($_POST['config_ticket_ordering']);
    $config_ticket_timer_autostart = intval($_POST['config_ticket_timer_autostart']);
    $config_ticket_csat_enable = intval($_POST['config_ticket_csat_enable'] ?? 0);
    $config_ticket_csat_reminder_days = max(1, intval($_POST['config_ticket_csat_reminder_days'] ?? 3));
    $config_ticket_csat_low_rating_threshold = min(4, max(1, intval($_POST['config_ticket_csat_low_rating_threshold'] ?? 2)));
    $config_ticket_default_technician_id = intval($_POST['config_ticket_default_technician_id'] ?? 0);

    // Optional field - blank clears it (CTA stays hidden). An invalid non-blank
    // value doesn't abort the whole settings save (this is one field among many
    // in the same form); it just falls back to whatever was already saved, with
    // a warning, rather than silently accepting a broken link.
    $raw_review_url = trim($_POST['config_ticket_csat_google_review_url'] ?? '');
    $config_ticket_csat_google_review_url = '';
    if ($raw_review_url !== '') {
        if (filter_var($raw_review_url, FILTER_VALIDATE_URL) && strtolower(parse_url($raw_review_url, PHP_URL_SCHEME)) === 'https') {
            $config_ticket_csat_google_review_url = sanitizeInput($raw_review_url);
        } else {
            $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_ticket_csat_google_review_url FROM settings WHERE company_id = 1"));
            $config_ticket_csat_google_review_url = sanitizeInput($existing['config_ticket_csat_google_review_url'] ?? '');
            flash_alert('Google review link must be a valid HTTPS URL - left unchanged.', 'warning');
        }
    }
    $config_ticket_csat_google_review_url_esc = mysqli_real_escape_string($mysqli, $config_ticket_csat_google_review_url);

    mysqli_query($mysqli,"UPDATE settings SET config_ticket_prefix = '$config_ticket_prefix', config_ticket_next_number = $config_ticket_next_number, config_ticket_email_parse = $config_ticket_email_parse, config_ticket_autoclose_hours = $config_ticket_autoclose_hours, config_ticket_new_ticket_notification_email = '$config_ticket_new_ticket_notification_email', config_ticket_default_billable = $config_ticket_default_billable, config_ticket_default_view = $config_ticket_default_view, config_ticket_moving_columns = $config_ticket_moving_columns, config_ticket_ordering = $config_ticket_ordering, config_ticket_timer_autostart = $config_ticket_timer_autostart, config_ticket_csat_enable = $config_ticket_csat_enable, config_ticket_csat_reminder_days = $config_ticket_csat_reminder_days, config_ticket_csat_low_rating_threshold = $config_ticket_csat_low_rating_threshold, config_ticket_csat_google_review_url = '$config_ticket_csat_google_review_url_esc', config_ticket_default_technician_id = $config_ticket_default_technician_id WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited ticket settings");

    flash_alert("Ticket Settings updated");

    redirect();

}
