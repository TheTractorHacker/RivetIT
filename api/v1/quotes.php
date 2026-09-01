<?php
// GET /api/v1/quotes        list
// GET /api/v1/quotes/{id}   detail with line items
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_sales');

// Client-scope restriction, mirroring tickets.php/client_tabs.php.
$quote_client_scope_clause = api_client_scope_sql('q.quote_client_id');

if ($id === null) {
    $page      = max(1, intval($_GET['page'] ?? 1));
    $limit     = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset    = ($page - 1) * $limit;
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
    $search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');

    $where = ['q.quote_archived_at IS NULL', $quote_client_scope_clause];
    if ($client_id) $where[] = "q.quote_client_id = $client_id";
    if ($search)    $where[] = "(q.quote_scope LIKE '%$search%' OR q.quote_number LIKE '%$search%')";
    $w = implode(' AND ', $where);

    $total  = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS c FROM quotes q WHERE $w"))['c']);
    $quotes = [];
    $sql    = mysqli_query($mysqli,
        "SELECT q.quote_id, q.quote_number, q.quote_scope, q.quote_status, q.quote_date,
                q.quote_amount, q.quote_url_key,
                c.client_name
         FROM quotes q LEFT JOIN clients c ON q.quote_client_id = c.client_id
         WHERE $w ORDER BY q.quote_date DESC LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $quotes[] = [
            'id'       => intval($row['quote_id']),
            'number'   => $row['quote_number'],
            'subject'  => $row['quote_scope'],
            'status'   => $row['quote_status'],
            'date'     => $row['quote_date'],
            'total'    => floatval($row['quote_amount']),
            'client'   => $row['client_name'],
            'guest_url'=> $row['quote_url_key'] ? '/quote.php?key=' . $row['quote_url_key'] : null,
        ];
    }
    api_response(200, ['data' => $quotes, 'total' => $total]);
}

$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT q.*, c.client_name
     FROM quotes q
     LEFT JOIN clients c ON q.quote_client_id = c.client_id
     WHERE q.quote_id = $id AND q.quote_archived_at IS NULL AND $quote_client_scope_clause LIMIT 1"
));
if (!$row) api_error(404, 'Quote not found');

$items = [];
$isql  = mysqli_query($mysqli,
    "SELECT qi.*, p.product_name FROM quote_items qi
     LEFT JOIN products p ON qi.item_product_id = p.product_id
     WHERE qi.item_quote_id = $id ORDER BY qi.item_order ASC"
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
    'id'           => intval($row['quote_id']),
    'number'       => $row['quote_number'],
    'subject'      => $row['quote_scope'],
    'status'       => $row['quote_status'],
    'date'         => $row['quote_date'],
    'subtotal'     => floatval($row['quote_amount']),
    'tax'          => 0.0,
    'total'        => floatval($row['quote_amount']),
    'client'       => $row['client_name'],
    'notes'        => $row['quote_note'] ?? '',
    'guest_url'    => $row['quote_url_key'] ? '/quote.php?key=' . $row['quote_url_key'] : null,
    'items'        => $items,
]);
