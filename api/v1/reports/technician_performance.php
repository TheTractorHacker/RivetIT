<?php
// GET /api/v1/reports/technician-performance?from=YYYY-MM-DD&to=YYYY-MM-DD
// Technician utilization metrics — mirrors agent/reports/technician_performance.php.
// Shares getTechnicianPerformanceReport() in functions.php so web + API match.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-01-01');
$to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : date('Y-m-d');

api_response(200, getTechnicianPerformanceReport($mysqli, $from, $to, !empty($api_key_client_id) ? intval($api_key_client_id) : null));
