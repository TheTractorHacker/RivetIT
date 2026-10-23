<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_default_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $start_page = sanitizeInput($_POST['start_page']);
    $calendar = intval($_POST['calendar']);
    $net_terms = intval($_POST['net_terms']);
    $hourly_rate = floatval($_POST['hourly_rate']);

    mysqli_query($mysqli,"UPDATE settings SET config_start_page = '$start_page', config_default_calendar = $calendar, config_default_net_terms = $net_terms, config_default_hourly_rate = $hourly_rate WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited default settings");

    flash_alert("Default settings edited");

    redirect();

}
