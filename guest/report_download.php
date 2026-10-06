<?php
// Download of a scheduled report CSV from the emailed link (guest/report_download.php?t=TOKEN).
// No login: the 256-bit random token is the credential, only its SHA-256 is stored, and the file expires
// (ReportScheduler::EXPIRY_DAYS). Same security headers as the rest of guest/. See src/Reports/ReportScheduler.php.
header("Content-Security-Policy: default-src 'none'");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Cache-Control: no-store, no-cache, must-revalidate");

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

$export = \ITFlow\Reports\ReportScheduler::fetchExport($mysqli, (string) ($_GET['t'] ?? ''));
if (!$export) {
    http_response_code(404);
    exit("This report link has expired or is not valid.");
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . \ITFlow\Reports\ReportExport::filename(pathinfo($export['export_filename'], PATHINFO_FILENAME)) . '"');
echo $export['export_content'];
