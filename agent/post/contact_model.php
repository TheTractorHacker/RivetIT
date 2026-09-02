<?php
defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$name = sanitizeInput($_POST['name']);
$title = sanitizeInput($_POST['title']);
$department = sanitizeInput($_POST['department']);
$phone_country_code = preg_replace("/[^0-9]/", '', $_POST['phone_country_code']);
$phone = preg_replace("/[^0-9]/", '', $_POST['phone']);
$extension = preg_replace("/[^0-9]/", '', $_POST['extension']);
$mobile_country_code = preg_replace("/[^0-9]/", '', $_POST['mobile_country_code']);
$mobile = preg_replace("/[^0-9]/", '', $_POST['mobile']);
$email_raw = trim($_POST['email'] ?? '');
$email = ($email_raw === '' || filter_var($email_raw, FILTER_VALIDATE_EMAIL)) ? sanitizeInput($email_raw) : '';
$notes = sanitizeInput($_POST['notes']);
$contact_primary = intval($_POST['contact_primary'] ?? 0);
$contact_important = intval($_POST['contact_important'] ?? 0);
$contact_billing = intval($_POST['contact_billing'] ?? 0);
$contact_technical = intval($_POST['contact_technical'] ?? 0);
$location_id = intval($_POST['location'] ?? 0);
$pin = sanitizeInput($_POST['pin']);
$auth_method = sanitizeInput($_POST['auth_method']);

// Master-plan Phase 2 employee fields
$employee_id = sanitizeInput($_POST['employee_id'] ?? '');
$manager_id = intval($_POST['manager_id'] ?? 0);
$employee_type = sanitizeInput($_POST['employee_type'] ?? 'employee');
$employment_status = sanitizeInput($_POST['employment_status'] ?? 'active');
$work_arrangement = sanitizeInput($_POST['work_arrangement'] ?? '');
$start_date_raw = trim($_POST['start_date'] ?? '');
$start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date_raw) ? $start_date_raw : '';
