<?php
// GET /api/v1/reports/unbilled-tickets?year= — wraps agent/reports/tickets_unbilled.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_sales');

$year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) unbilled-ticket breakdown - an unrestricted
// key keeps the same company-wide behavior as before.
$client_filter = !empty($api_key_client_id) ? " AND c.client_id = " . intval($api_key_client_id) : '';

$sql = mysqli_query($mysqli,
    "SELECT
        c.client_id, c.client_name,
        COUNT(t.ticket_id) AS raised,
        SUM(CASE WHEN t.ticket_closed_at IS NOT NULL AND t.ticket_billable = 1 THEN 1 ELSE 0 END) AS billable_closed,
        SUM(CASE WHEN t.ticket_closed_at IS NOT NULL AND t.ticket_billable = 1 AND t.ticket_invoice_id = 0 THEN 1 ELSE 0 END) AS unbilled
     FROM clients c
     JOIN tickets t ON t.ticket_client_id = c.client_id AND YEAR(t.ticket_created_at) = $year$client_filter
     GROUP BY c.client_id, c.client_name
     HAVING unbilled > 0
     ORDER BY unbilled DESC"
);

$clients = [];
while ($row = mysqli_fetch_assoc($sql)) {
    $clients[] = [
        'client_id'       => intval($row['client_id']),
        'client'          => $row['client_name'],
        'raised'          => intval($row['raised']),
        'billable_closed' => intval($row['billable_closed']),
        'unbilled'        => intval($row['unbilled']),
    ];
}

api_response(200, ['year' => $year, 'clients' => $clients]);
