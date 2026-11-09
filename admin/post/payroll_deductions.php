<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['add_payroll_deduction_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description'] ?? '');

    $order = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(payroll_deduction_category_order),0)+1 FROM payroll_deduction_categories"))[0]);

    mysqli_query($mysqli, "INSERT INTO payroll_deduction_categories SET payroll_deduction_category_name='$name', payroll_deduction_category_description='$description', payroll_deduction_category_order=$order");

    logAction("Payroll", "Create", "$session_name created payroll deduction category $name");

    flash_alert("Deduction category <strong>$name</strong> created");
    redirect();
}

if (isset($_POST['edit_payroll_deduction_category'])) {

    validateCSRFToken($_POST['csrf_token']);

    $payroll_deduction_category_id = intval($_POST['payroll_deduction_category_id']);
    $name = sanitizeInput($_POST['name']);
    $description = sanitizeInput($_POST['description'] ?? '');
    $order = intval($_POST['order']);

    mysqli_query($mysqli, "UPDATE payroll_deduction_categories SET payroll_deduction_category_name='$name', payroll_deduction_category_description='$description', payroll_deduction_category_order=$order WHERE payroll_deduction_category_id=$payroll_deduction_category_id");

    logAction("Payroll", "Edit", "$session_name edited payroll deduction category $name");

    flash_alert("Deduction category <strong>$name</strong> updated");
    redirect();
}

if (isset($_GET['delete_payroll_deduction_category'])) {

    validateCSRFToken($_GET['csrf_token']);

    $payroll_deduction_category_id = intval($_GET['delete_payroll_deduction_category']);
    $name = sanitizeInput(getFieldById('payroll_deduction_categories', $payroll_deduction_category_id, 'payroll_deduction_category_name'));

    mysqli_query($mysqli, "UPDATE payroll_deduction_categories SET payroll_deduction_category_archived_at=NOW() WHERE payroll_deduction_category_id=$payroll_deduction_category_id");
    mysqli_query($mysqli, "UPDATE payroll_employee_deductions SET payroll_employee_deduction_archived_at=NOW() WHERE payroll_employee_deduction_category_id=$payroll_deduction_category_id AND payroll_employee_deduction_archived_at IS NULL");

    logAction("Payroll", "Delete", "$session_name deleted payroll deduction category $name");

    flash_alert("Deduction category <strong>$name</strong> deleted", 'error');
    redirect();
}
