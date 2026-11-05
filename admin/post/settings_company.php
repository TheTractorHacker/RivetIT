<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['edit_company'])) {

    validateCSRFToken($_POST['csrf_token']);

    $name = sanitizeInput($_POST['name']);
    $address = sanitizeInput($_POST['address']);
    $city = sanitizeInput($_POST['city']);
    $state = sanitizeInput($_POST['state']);
    $zip = sanitizeInput($_POST['zip']);
    $country = sanitizeInput($_POST['country']);
    $phone_country_code = preg_replace("/[^0-9]/", '',$_POST['phone_country_code']);
    $phone = preg_replace("/[^0-9]/", '',$_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $website = sanitizeInput($_POST['website']);
    $tax_id = sanitizeInput($_POST['tax_id']);
    $ms_tenant_id = sanitizeInput($_POST['ms_tenant_id'] ?? '');
    $default_email_domain = sanitizeInput($_POST['default_email_domain'] ?? '');
    $security_contact_email = sanitizeInput($_POST['security_contact_email'] ?? '');
    $hr_contact_email = sanitizeInput($_POST['hr_contact_email'] ?? '');

    // Refuse malformed values by name instead of storing them.
    $company_errors = [];
    if (trim($name) === '') { $company_errors[] = 'A company name is required.'; }
    if ($email !== '' && !filter_var(stripslashes($email), FILTER_VALIDATE_EMAIL)) { $company_errors[] = 'The company email is not a valid address.'; }
    foreach (['security contact' => $security_contact_email, 'HR contact' => $hr_contact_email] as $ce_label => $ce_value) {
        if ($ce_value !== '' && !filter_var(stripslashes($ce_value), FILTER_VALIDATE_EMAIL)) { $company_errors[] = "The $ce_label email is not a valid address."; }
    }
    if ($ms_tenant_id !== '' && !preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $ms_tenant_id)) {
        $company_errors[] = 'The Microsoft/Entra Tenant ID must be a GUID like 72f988bf-86f1-41af-91ab-2d7cd011db47.';
    }
    if ($default_email_domain !== '' && !preg_match('/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}$/', $default_email_domain)) {
        $company_errors[] = 'The default email domain must be a domain name such as example.com (no @, spaces or http://).';
    }
    if ($company_errors) {
        flash_alert(implode(' ', $company_errors) . ' Nothing was saved.', 'error');
        redirect();
    }

    $sql = mysqli_query($mysqli,"SELECT company_logo FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $existing_file_name = sanitizeInput($row['company_logo']);

    // Company logo
    if (isset($_FILES['file']['tmp_name'])) {
        if ($new_file_name = checkFileUpload($_FILES['file'], array('jpg', 'jpeg', 'png'))) {
            $file_tmp_path = $_FILES['file']['tmp_name'];

            // directory in which the uploaded file will be moved
            $upload_file_dir = "../uploads/settings/";
            $dest_path = $upload_file_dir . $new_file_name;

            move_uploaded_file($file_tmp_path, $dest_path);

            // Delete old file - only if one was actually set, otherwise this
            // unlinks the uploads/settings/ directory itself (no filename to
            // append) and throws a warning on every first-ever logo upload.
            if ($existing_file_name) {
                unlink("../uploads/settings/$existing_file_name");
            }

            // Set Logo
            mysqli_query($mysqli,"UPDATE companies SET company_logo = '$new_file_name' WHERE company_id = 1");

        }
    }

    mysqli_query($mysqli,"UPDATE companies SET company_name = '$name', company_address = '$address', company_city = '$city', company_state = '$state', company_zip = '$zip', company_country = '$country', company_phone_country_code = '$phone_country_code', company_phone = '$phone', company_email = '$email', company_website = '$website', company_tax_id = '$tax_id', company_ms_tenant_id = '$ms_tenant_id', company_default_email_domain = '$default_email_domain', company_security_contact_email = '$security_contact_email', company_hr_contact_email = '$hr_contact_email' WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name edited company details");

    flash_alert("Company <strong>$name</strong> edited");

    redirect();

}

if (isset($_GET['remove_company_logo'])) {

    validateCSRFToken($_GET['csrf_token']);

    $sql = mysqli_query($mysqli,"SELECT company_logo FROM companies WHERE company_id = 1");
    $row = mysqli_fetch_assoc($sql);
    $company_logo = $row['company_logo']; // FileSystem Operation Logo is already sanitized

    if ($company_logo) {
        unlink("../uploads/settings/$company_logo");
    }

    mysqli_query($mysqli,"UPDATE companies SET company_logo = NULL WHERE company_id = 1");

    logAction("Settings", "Edit", "$session_name deleted company logo");

    flash_alert("Removed company logo", 'error');

    redirect();

}
