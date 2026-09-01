<?php

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
// Reporting Perms
enforceUserPermission('module_reporting');

// CSV export mode: when ?export=csv is requested we still run the full auth +
// reporting-permission bootstrap and resolve the date filter, but suppress the
// HTML chrome (header/nav/wrapper) so the report page can stream a clean text/csv
// download and exit. Report pages that implement an export block check this flag.
$report_export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');

if ($report_export_csv) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
} else {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/reports/includes/reports_side_nav.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
}

// Set variable default values
$largest_income_month = 0;
$largest_invoice_month = 0;
$recurring_total = 0;
