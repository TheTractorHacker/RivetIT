<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_payroll_settings'])) {

    validateCSRFToken($_POST['csrf_token']);

    $overtime_threshold_hours = floatval($_POST['overtime_threshold_hours'] ?? 40);
    $overtime_multiplier = floatval($_POST['overtime_multiplier'] ?? 1.5);
    $default_pay_frequency = sanitizeInput($_POST['default_pay_frequency'] ?? 'biweekly');

    if ($overtime_threshold_hours <= 0) {
        $overtime_threshold_hours = 40;
    }
    if ($overtime_multiplier <= 0) {
        $overtime_multiplier = 1.5;
    }
    if (!in_array($default_pay_frequency, ['weekly', 'biweekly', 'semimonthly', 'monthly'], true)) {
        $default_pay_frequency = 'biweekly';
    }

    mysqli_query($mysqli, "UPDATE settings SET
        config_payroll_overtime_threshold_hours = $overtime_threshold_hours,
        config_payroll_overtime_multiplier = $overtime_multiplier,
        config_payroll_default_pay_frequency = '$default_pay_frequency'
        WHERE company_id = 1");

    logAction("Payroll", "Edit", "$session_name edited payroll settings");

    flash_alert("Payroll Settings updated");

    redirect();

}
