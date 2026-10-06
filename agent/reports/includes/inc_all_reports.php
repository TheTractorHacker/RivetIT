<?php

// Headless run (scheduled report rendered by scripts/report_render.php, CLI only): that script has already loaded
// config, functions and the owner's session globals, so no login check or page chrome happens here. The Reporting
// permission is still enforced against the owner's role below, exactly like an interactive request.
$report_headless = defined('RIVETIT_REPORT_HEADLESS') && PHP_SAPI === 'cli';

if ($report_headless) {
    enforceUserPermission('module_reporting');
    $report_export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
} else {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
    // Buffer the page (roles audit P1h) so a permission check that runs after the shell is printed can still
    // answer HTTP 403 (itflow_render_denied); PHP flushes this buffer when the page ends.
    ob_start();
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/page_title.php';
    // Reporting Perms
    enforceUserPermission('module_reporting');

    // CSV export mode: when ?export=csv is requested we still run the full auth +
    // reporting-permission bootstrap and resolve the date filter, but suppress the
    // HTML chrome (header/nav/wrapper) so the report page can stream a clean text/csv
    // download and exit. Report pages that implement their own export block check this flag; every other report
    // page falls back to exporting the tables it rendered (see report_export_fallback() below).
    $report_export_csv = (isset($_GET['export']) && $_GET['export'] === 'csv');
    $report_print_view = (!$report_export_csv && isset($_GET['print']) && $_GET['print'] === '1');
    $report_key = \ITFlow\Reports\ReportCatalog::keyFromScript($_SERVER['SCRIPT_NAME'] ?? '');

    if ($report_export_csv) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
        if ($report_key !== null) {
            ob_start(); // page output, converted to CSV at shutdown unless the page streamed its own CSV
            register_shutdown_function(function () use ($report_key) {
                if (!empty($GLOBALS['report_csv_sent']) || http_response_code() >= 300) { // own CSV already streamed, or a denial/redirect page
                    while (ob_get_level() > 0) { ob_end_flush(); }
                    return;
                }
                $html = '';
                while (ob_get_level() > 0) { $html = ob_get_clean() . $html; }
                $rows = \ITFlow\Reports\ReportExport::fromHtml($html);
                if ($rows === []) {
                    $rows = [['No tabular data on this report for the selected filters.']];
                }
                if (!headers_sent()) {
                    header('Content-Type: text/csv; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="' . \ITFlow\Reports\ReportExport::filename($report_key . '_' . date('Y-m-d')) . '"');
                    header('Cache-Control: no-store, no-cache, must-revalidate');
                }
                echo \ITFlow\Reports\ReportExport::toCsv([], $rows);
            });
        }
    } else {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/top_nav.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/reports/includes/reports_side_nav.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_wrapper.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_alert_feedback.php';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_header.php';
        // Saved views, CSV export, print/PDF view and schedule shortcut for every catalogued report.
        if ($report_key !== null) {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/agent/reports/includes/report_toolbar.php';
        }
    }
}

// Set variable default values
$largest_income_month = 0;
$largest_invoice_month = 0;
$recurring_total = 0;
