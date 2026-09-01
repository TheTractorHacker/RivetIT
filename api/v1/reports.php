<?php
// Dispatches GET /api/v1/reports/{report} to the matching file in reports/.
// $sub is parsed by index.php's router from the path segment after "reports/".
defined('FROM_API') || die();
if ($method !== 'GET') api_error(405, 'Method not allowed');

require_once __DIR__ . '/includes/api_permissions.php';

// Mirrors the classic web app's agent/reports/includes/inc_all_reports.php: every report
// requires the blanket "module_reporting" grant in addition to its own domain-specific
// module check below. module_reporting is intentionally company-wide ("Access to all
// reports") and is not subject to per-client user_client_permissions scoping — same as
// the classic web reports pages.
api_require_module_permission($mysqli, $api_user_id, 'module_reporting');

switch ($sub) {
    case 'time':
    case null:
        require __DIR__ . '/reports/time.php';
        break;
    case 'tickets':
        require __DIR__ . '/reports/tickets.php';
        break;
    case 'tickets-by-client':
        require __DIR__ . '/reports/tickets_by_client.php';
        break;
    case 'time-by-tech':
        require __DIR__ . '/reports/time_by_tech.php';
        break;
    case 'tech-performance':
        require __DIR__ . '/reports/tech_performance.php';
        break;
    case 'technician-performance':
        require __DIR__ . '/reports/technician_performance.php';
        break;
    case 'service-desk':
        require __DIR__ . '/reports/service_desk.php';
        break;
    case 'csat':
        require __DIR__ . '/reports/csat.php';
        break;
    case 'mrr':
        require __DIR__ . '/reports/mrr.php';
        break;
    case 'rmm-health':
        require __DIR__ . '/reports/rmm_health.php';
        break;
    case 'unbilled-tickets':
        require __DIR__ . '/reports/unbilled_tickets.php';
        break;
    case 'clients-with-balance':
        require __DIR__ . '/reports/clients_with_balance.php';
        break;
    case 'income-summary':
        require __DIR__ . '/reports/income_summary.php';
        break;
    case 'expense-summary':
        require __DIR__ . '/reports/expense_summary.php';
        break;
    case 'profit-loss':
        require __DIR__ . '/reports/profit_loss.php';
        break;
    case 'expiring':
        require __DIR__ . '/reports/expiring.php';
        break;
    case 'overview':
        require __DIR__ . '/reports/overview.php';
        break;
    default:
        api_error(404, 'Unknown report');
}
