<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_default_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $start_page = sanitizeInput($_POST['start_page']);
    $calendar = intval($_POST['calendar']);
    $net_terms = intval($_POST['net_terms']);
    $hourly_rate_raw = trim((string) ($_POST['hourly_rate'] ?? ''));
    $hourly_rate = floatval($hourly_rate_raw);

    if ($hourly_rate_raw === '' || !is_numeric($hourly_rate_raw) || $hourly_rate < 0) {
        flash_alert("Department hourly rate must be a number of 0 or more, for example 125.00. Nothing was saved.", 'error');
        redirect();
    }

    if (!preg_match('/^[A-Za-z0-9_\/.\-]+\.php$/', $_POST['start_page'] ?? '')) {
        flash_alert("That start page is not valid. Nothing was saved.", 'error');
        redirect();
    }

    mysqli_query($mysqli,"UPDATE settings SET config_start_page = '$start_page', config_default_calendar = $calendar, config_default_net_terms = $net_terms, config_default_hourly_rate = $hourly_rate WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited default settings");

    flash_alert("Default settings edited");

    redirect();

}
