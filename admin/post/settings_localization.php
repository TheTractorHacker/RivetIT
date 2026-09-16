<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_localization'])) {

    validateCSRFToken($_POST['csrf_token']);

    $locale = sanitizeInput($_POST['locale']);
    $currency_code = sanitizeInput($_POST['currency_code']);
    $timezone = sanitizeInput($_POST['timezone']);

    mysqli_query($mysqli,"UPDATE companies SET company_locale = '$locale', company_currency = '$currency_code' WHERE company_id = 1");

    mysqli_query($mysqli,"UPDATE settings SET config_timezone = '$timezone' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited localization settings");

    flash_alert("Company localization updated");

    redirect();

}

if (isset($_POST['edit_phone_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $phone_default_country_code = preg_replace('/\D/', '', $_POST['phone_default_country_code'] ?? '') ?: '1';
    $whatsapp_enabled = isset($_POST['whatsapp_enabled']) ? 1 : 0;

    mysqli_query($mysqli, "UPDATE settings SET config_phone_default_country_code = '$phone_default_country_code', config_whatsapp_enabled = $whatsapp_enabled WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited phone number settings");

    flash_alert("Phone number settings updated");

    redirect();

}
