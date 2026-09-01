<?php
// GET /api/v1/reports/clients-with-balance — wraps agent/reports/clients_with_balance.php
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_financial');

$client_scope = api_client_scope_sql('clients.client_id');

$sql = mysqli_query($mysqli, "
    SELECT
        clients.client_id,
        clients.client_name,
        IFNULL(SUM(invoices.invoice_amount), 0) - IFNULL(SUM(payments.payment_amount), 0) AS balance
    FROM clients
    LEFT JOIN invoices
        ON clients.client_id = invoices.invoice_client_id
        AND invoices.invoice_status != 'Draft'
        AND invoices.invoice_status != 'Cancelled'
        AND invoices.invoice_status != 'Non-Billable'
    LEFT JOIN (
        SELECT payment_invoice_id, SUM(payment_amount) AS payment_amount
        FROM payments
        GROUP BY payment_invoice_id
    ) AS payments ON invoices.invoice_id = payments.payment_invoice_id
    WHERE $client_scope
    GROUP BY clients.client_id, clients.client_name
    HAVING balance > 0
    ORDER BY balance DESC
");

$clients = [];
while ($row = mysqli_fetch_assoc($sql)) {
    $clients[] = [
        'client_id'   => intval($row['client_id']),
        'client_name' => $row['client_name'],
        'balance'     => round(floatval($row['balance']), 2),
    ];
}

api_response(200, ['clients' => $clients]);
