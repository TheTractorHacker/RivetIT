<?php

// Auditor export of the compliance status: ?format=csv|html, optional &framework=, optional &snapshot=ID. Administrators only.
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';

if (!isset($session_is_admin) || !$session_is_admin) {
    http_response_code(403);
    exit('Administrators only.');
}

use ITFlow\Audit\AuditService;
use ITFlow\Compliance\ComplianceService;
use RivetCore\Compliance\Framework;
use RivetCore\Compliance\ReportRenderer;

if (!ComplianceService::ready($mysqli)) {
    http_response_code(503);
    exit('Run the database update first.');
}

$format = ($_GET['format'] ?? 'html') === 'csv' ? 'csv' : 'html';
$framework = (string) ($_GET['framework'] ?? '');
$framework = Framework::isValid($framework) ? $framework : null;
$snapshot_id = intval($_GET['snapshot'] ?? 0);

$taken = null;
if ($snapshot_id > 0) {
    $taken = ComplianceService::snapshots($mysqli)->get($snapshot_id);
    if ($taken === null) {
        http_response_code(404);
        exit('Snapshot not found.');
    }
    $assessment = $taken['assessment'];
} else {
    $assessment = ComplianceService::assess($mysqli);
}

$renderer = new ReportRenderer();
$stamp = $assessment->generatedAt->format('Ymd-His');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
AuditService::record('compliance.report_exported', (int) $session_user_id, 'compliance', $snapshot_id ?: 'live', 'export', 'Compliance report exported (' . $format . ')', ['format' => $format, 'framework' => $framework, 'snapshot_id' => $snapshot_id ?: null]);

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compliance-status-' . $stamp . '.csv"');
    echo $renderer->csv($assessment, $framework);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'");
echo $renderer->html($assessment, (string) ($session_company_name ?? ''), $framework, defined('APP_VERSION') ? (string) APP_VERSION : null);
