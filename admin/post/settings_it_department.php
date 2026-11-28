<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_it_department'])) {

    validateCSRFToken($_POST['csrf_token']);
    validateAdminRole(); // Old function

    try {
        $v = \ITFlow\Company\ItDepartment::clean($_POST);
    } catch (\InvalidArgumentException $e) {
        flash_alert(nullable_htmlentities($e->getMessage()), 'error');
        redirect('settings_it_department.php');
    }
    $name = sanitizeInput($v['name']);
    $phone = sanitizeInput($v['phone']);
    $email = sanitizeInput($v['email']);
    $hours = sanitizeInput($v['hours']);

    mysqli_query($mysqli, "UPDATE companies SET company_it_name = '$name', company_it_phone = '$phone', company_it_email = '$email', company_it_hours = '$hours' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited the IT department details");

    flash_alert('IT department details saved');
    redirect('settings_it_department.php');
}
