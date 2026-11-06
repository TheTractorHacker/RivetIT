<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_payroll_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $threshold_raw = trim((string) ($_POST['overtime_threshold_hours'] ?? ''));
    $multiplier_raw = trim((string) ($_POST['overtime_multiplier'] ?? ''));
    $default_pay_frequency = sanitizeInput($_POST['default_pay_frequency'] ?? '');

    // Reject bad input by name instead of silently substituting defaults.
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $threshold_raw) || floatval($threshold_raw) < 1 || floatval($threshold_raw) > 168) {
        flash_alert("Overtime threshold must be a number of hours between 1 and 168. Nothing was saved.", 'error');
        redirect();
    }
    if (!preg_match('/^\d{1,2}(\.\d{1,2})?$/', $multiplier_raw) || floatval($multiplier_raw) < 1 || floatval($multiplier_raw) > 10) {
        flash_alert("Overtime multiplier must be a number between 1.00 and 10.00. Nothing was saved.", 'error');
        redirect();
    }
    if (!in_array($default_pay_frequency, ['weekly', 'biweekly', 'semimonthly', 'monthly'], true)) {
        flash_alert("Choose a valid default pay frequency. Nothing was saved.", 'error');
        redirect();
    }
    $overtime_threshold_hours = floatval($threshold_raw);
    $overtime_multiplier = floatval($multiplier_raw);

    mysqli_query($mysqli, "UPDATE settings SET
        config_payroll_overtime_threshold_hours = $overtime_threshold_hours,
        config_payroll_overtime_multiplier = $overtime_multiplier,
        config_payroll_default_pay_frequency = '$default_pay_frequency'
        WHERE company_id = 1");

    logAction("Payroll", "Edit", "$session_name edited payroll settings");

    flash_alert("Payroll Settings updated");

    redirect();

}
