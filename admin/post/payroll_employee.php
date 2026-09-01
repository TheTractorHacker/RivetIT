<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['save_payroll_pay_rate'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id']);
    $pay_type = sanitizeInput($_POST['pay_type'] ?? 'hourly');
    $pay_amount = round(floatval($_POST['pay_amount'] ?? 0), 2);

    if (!in_array($pay_type, ['hourly', 'salary'], true)) {
        $pay_type = 'hourly';
    }

    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));
    if ($user_id <= 0 || $user_name === '') {
        flash_alert("Employee not found.", 'error');
        redirect('/admin/payroll_employees.php');
    }

    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT payroll_pay_rate_id FROM payroll_pay_rates WHERE payroll_pay_rate_user_id = $user_id AND payroll_pay_rate_archived_at IS NULL LIMIT 1"));

    if ($existing) {
        $rate_id = intval($existing['payroll_pay_rate_id']);
        mysqli_query($mysqli, "UPDATE payroll_pay_rates SET payroll_pay_rate_type = '$pay_type', payroll_pay_rate_amount = $pay_amount WHERE payroll_pay_rate_id = $rate_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO payroll_pay_rates SET payroll_pay_rate_user_id = $user_id, payroll_pay_rate_type = '$pay_type', payroll_pay_rate_amount = $pay_amount");
    }

    logAction("Payroll", "Edit", "$session_name set $user_name's payroll pay rate to $pay_type \$$pay_amount");

    flash_alert("Pay rate for <strong>$user_name</strong> saved");
    redirect("/admin/payroll_employee.php?id=$user_id");
}

if (isset($_GET['unenroll_payroll_employee'])) {

    validateCSRFToken($_GET['csrf_token']);

    $user_id = intval($_GET['unenroll_payroll_employee']);
    $user_name = sanitizeInput(getFieldById('users', $user_id, 'user_name'));

    mysqli_query($mysqli, "UPDATE payroll_pay_rates SET payroll_pay_rate_archived_at = NOW() WHERE payroll_pay_rate_user_id = $user_id AND payroll_pay_rate_archived_at IS NULL");

    logAction("Payroll", "Delete", "$session_name removed $user_name from payroll");

    flash_alert("<strong>$user_name</strong> removed from payroll", 'error');
    redirect("/admin/payroll_employee.php?id=$user_id");
}

if (isset($_POST['add_payroll_employee_deduction'])) {

    validateCSRFToken($_POST['csrf_token']);

    $user_id = intval($_POST['user_id']);
    $category_id = intval($_POST['category_id']);
    $amount = round(floatval($_POST['amount'] ?? 0), 2);

    $category_name = sanitizeInput(getFieldById('payroll_deduction_categories', $category_id, 'payroll_deduction_category_name'));
    if ($user_id <= 0 || $category_id <= 0 || $category_name === '') {
        flash_alert("Invalid employee or deduction category.", 'error');
        redirect("/admin/payroll_employee.php?id=$user_id");
    }

    mysqli_query($mysqli, "INSERT INTO payroll_employee_deductions SET payroll_employee_deduction_user_id = $user_id, payroll_employee_deduction_category_id = $category_id, payroll_employee_deduction_amount = $amount");

    logAction("Payroll", "Create", "$session_name assigned deduction $category_name to user #$user_id");

    flash_alert("Deduction <strong>$category_name</strong> added");
    redirect("/admin/payroll_employee.php?id=$user_id");
}

if (isset($_GET['remove_payroll_employee_deduction'])) {

    validateCSRFToken($_GET['csrf_token']);

    $deduction_id = intval($_GET['remove_payroll_employee_deduction']);
    $user_id = intval($_GET['user_id'] ?? 0);

    mysqli_query($mysqli, "UPDATE payroll_employee_deductions SET payroll_employee_deduction_archived_at = NOW() WHERE payroll_employee_deduction_id = $deduction_id");

    logAction("Payroll", "Delete", "$session_name removed a payroll deduction assignment (#$deduction_id)");

    flash_alert("Deduction removed", 'error');
    redirect("/admin/payroll_employee.php?id=$user_id");
}
