<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_ticket_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    // flash_alert() keeps one message (the last call wins), so field warnings are collected and shown in the final message.
    $settings_warnings = [];

    $config_ticket_prefix = sanitizeInput($_POST['config_ticket_prefix']);
    $config_ticket_next_number = intval($_POST['config_ticket_next_number']);
    $config_ticket_email_parse = intval($_POST['config_ticket_email_parse'] ?? 0);
    // config_ticket_email_parse_unknown_senders is no longer read/written here - the setting
    // is dead (superseded by the per-mailbox mailbox_parse_unknown_senders column, see
    // cron/ticket_email_parser.php and admin/mailbox.php). Its column/value is left untouched
    // in the DB rather than silently reset on every settings save.
    $config_ticket_autoclose_hours = intval($_POST['config_ticket_autoclose_hours']);
    $config_ticket_new_ticket_notification_email = '';
    if (filter_var($_POST['config_ticket_new_ticket_notification_email'], FILTER_VALIDATE_EMAIL)) {
        $config_ticket_new_ticket_notification_email = sanitizeInput($_POST['config_ticket_new_ticket_notification_email']);
    }
    $config_ticket_default_view = intval($_POST['config_ticket_default_view']);
    $config_ticket_moving_columns = intval($_POST['config_ticket_moving_columns']);
    $config_ticket_ordering = intval($_POST['config_ticket_ordering']);
    $config_ticket_timer_autostart = intval($_POST['config_ticket_timer_autostart'] ?? 0);
    $config_ticket_csat_enable = intval($_POST['config_ticket_csat_enable'] ?? 0);
    $config_ticket_csat_reminder_days = max(1, intval($_POST['config_ticket_csat_reminder_days'] ?? 3));
    $config_ticket_csat_low_rating_threshold = min(4, max(1, intval($_POST['config_ticket_csat_low_rating_threshold'] ?? 2)));
    $config_ticket_default_technician_id = intval($_POST['config_ticket_default_technician_id'] ?? 0);
    $config_ticket_default_category_id = intval($_POST['config_ticket_default_category_id'] ?? 0);
    $config_ticket_default_status_id = intval($_POST['config_ticket_default_status_id'] ?? 0);
    $config_dashboard_avg_resolution_enable = intval($_POST['config_dashboard_avg_resolution_enable'] ?? 0);
    $config_avg_resolution_exclude_projects = intval($_POST['config_avg_resolution_exclude_projects'] ?? 0);

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
            $settings_warnings[] = 'The Google review link must be a valid HTTPS URL - left unchanged.';
        }
    }
    $config_ticket_csat_google_review_url_esc = mysqli_real_escape_string($mysqli, $config_ticket_csat_google_review_url);

    // Company terms link for the signing pages (includes/company_terms.php). Blank clears it; an invalid value keeps what was saved.
    require_once __DIR__ . '/../../includes/company_terms.php';
    $raw_terms_url = trim($_POST['config_terms_url'] ?? '');
    $config_terms_url_save = '';
    if ($raw_terms_url !== '') {
        if (itflow_company_terms_url($raw_terms_url) !== '') {
            $config_terms_url_save = $raw_terms_url;
        } else {
            $existing_terms = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_terms_url FROM settings WHERE company_id = 1"));
            $config_terms_url_save = itflow_company_terms_url($existing_terms['config_terms_url'] ?? '');
            $settings_warnings[] = 'The Terms & Conditions link must be a valid http(s) address - left unchanged.';
        }
    }
    $config_terms_url_esc = mysqli_real_escape_string($mysqli, $config_terms_url_save);

    mysqli_query($mysqli,"UPDATE settings SET config_ticket_prefix = '$config_ticket_prefix', config_ticket_next_number = $config_ticket_next_number, config_ticket_email_parse = $config_ticket_email_parse, config_ticket_autoclose_hours = $config_ticket_autoclose_hours, config_ticket_new_ticket_notification_email = '$config_ticket_new_ticket_notification_email', config_ticket_default_view = $config_ticket_default_view, config_ticket_moving_columns = $config_ticket_moving_columns, config_ticket_ordering = $config_ticket_ordering, config_ticket_timer_autostart = $config_ticket_timer_autostart, config_ticket_csat_enable = $config_ticket_csat_enable, config_ticket_csat_reminder_days = $config_ticket_csat_reminder_days, config_ticket_csat_low_rating_threshold = $config_ticket_csat_low_rating_threshold, config_ticket_csat_google_review_url = '$config_ticket_csat_google_review_url_esc', config_ticket_default_technician_id = $config_ticket_default_technician_id, config_ticket_default_category_id = $config_ticket_default_category_id, config_ticket_default_status_id = $config_ticket_default_status_id, config_dashboard_avg_resolution_enable = $config_dashboard_avg_resolution_enable, config_avg_resolution_exclude_projects = $config_avg_resolution_exclude_projects, config_terms_url = '$config_terms_url_esc' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited ticket settings");

    if ($settings_warnings) {
        flash_alert('Ticket Settings updated, with a warning: ' . implode(' ', $settings_warnings), 'warning');
    } else {
        flash_alert("Ticket Settings updated");
    }

    redirect();

}
