<?php
// GET /api/v1/invoices        list
// GET /api/v1/invoices/{id}   detail with line items
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_sales');

// Client-scope restriction, mirroring tickets.php/client_tabs.php.
$invoice_client_scope_clause = api_client_scope_sql('i.invoice_client_id');

if ($id === null) {
    $page      = max(1, intval($_GET['page'] ?? 1));
    $limit     = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    $status    = mysqli_real_escape_string($mysqli, $_GET['status'] ?? '');

    $where = ['i.invoice_archived_at IS NULL', $invoice_client_scope_clause];
    if ($client_id) $where[] = "i.invoice_client_id = $client_id";
    if ($status)    $where[] = "i.invoice_status = '$status'";
    $w = implode(' AND ', $where);

    $total    = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM invoices i WHERE $w"))['c']);
    $invoices = [];
    $sql      = mysqli_query($mysqli,
        "SELECT i.invoice_id, i.invoice_number, i.invoice_date, i.invoice_due,
                i.invoice_status, i.invoice_amount,
                i.invoice_url_key, c.client_name
         FROM invoices i LEFT JOIN clients c ON i.invoice_client_id = c.client_id
         WHERE $w ORDER BY i.invoice_date DESC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $invoices[] = [
            'id'       => intval($row['invoice_id']),
            'number'   => $row['invoice_number'],
            'date'     => $row['invoice_date'],
            'due_date' => $row['invoice_due'],
            'status'   => $row['invoice_status'],
            'total'    => floatval($row['invoice_amount']),
            'client'   => $row['client_name'],
            'guest_url'=> $row['invoice_url_key'] ? '/invoice.php?key=' . $row['invoice_url_key'] : null,
        ];
    }
    api_response(200, ['data' => $invoices, 'total' => $total]);
}

$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT i.*, c.client_name FROM invoices i
     LEFT JOIN clients c ON i.invoice_client_id = c.client_id
     WHERE i.invoice_id = $id AND i.invoice_archived_at IS NULL AND $invoice_client_scope_clause LIMIT 1"
));
if (!$row) api_error(404, 'Invoice not found');

$items = [];
$isql  = mysqli_query($mysqli,
    "SELECT * FROM invoice_items WHERE item_invoice_id = $id ORDER BY item_order ASC"
);
while ($item = mysqli_fetch_assoc($isql)) {
    $items[] = [
        'description' => $item['item_description'],
        'quantity'    => floatval($item['item_quantity']),
        'unit_price'  => floatval($item['item_price']),
        'total'       => floatval($item['item_total']),
        'taxable'     => intval($item['item_tax_id']) > 0,
    ];
}

api_response(200, [
    'id'           => intval($row['invoice_id']),
    'number'       => $row['invoice_number'],
    'date'         => $row['invoice_date'],
    'due_date'     => $row['invoice_due'],
    'status'       => $row['invoice_status'],
    'subtotal'     => floatval($row['invoice_amount']),
    'tax'          => 0.0,
    'total'        => floatval($row['invoice_amount']),
    'client'       => $row['client_name'],
    'notes'        => $row['invoice_note'] ?? '',
    'guest_url'    => $row['invoice_url_key'] ? '/invoice.php?key=' . $row['invoice_url_key'] : null,
    'items'        => $items,
]);
