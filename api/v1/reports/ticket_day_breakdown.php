<?php
// GET /api/v1/reports/ticket-day-breakdown?from=YYYY-MM-DD&to=YYYY-MM-DD
// Ticket volume created vs. closed, per calendar day — mirrors
// agent/reports/ticket_day_breakdown.php. Shares getTicketDayBreakdownReport()
// in functions.php so web + API return identical numbers.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
$to   = isset($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])   ? $_GET['to']   : date('Y-m-d');

api_response(200, getTicketDayBreakdownReport($mysqli, $from, $to, !empty($api_key_client_id) ? intval($api_key_client_id) : null));
