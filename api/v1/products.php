<?php
// GET /api/v1/products   - list products/services for charge selection
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

// Products belong to Sales on the web; the app lists them to pick ticket charges, so Tickets/assets/docs
// also reads them (the Technician holds both). Roles audit P1g / security review 2026-09-26.
if (!api_has_module_permission($mysqli, intval($api_user_id), 'module_sales')) {
    api_require_module_permission($mysqli, intval($api_user_id), 'module_support');
}

$type   = mysqli_real_escape_string($mysqli, $_GET['type'] ?? '');
$search = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
$where  = ['product_archived_at IS NULL'];
if ($type)   $where[] = "product_type = '$type'";
if ($search) $where[] = "product_name LIKE '%$search%'";
$w = implode(' AND ', $where);

$products = [];
$sql = mysqli_query($mysqli,
    "SELECT product_id, product_name, product_type, product_description,
            product_price, product_currency_code, product_code
     FROM products WHERE $w ORDER BY product_type, product_name ASC LIMIT 100"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $products[] = [
        'id'          => intval($row['product_id']),
        'name'        => $row['product_name'],
        'type'        => $row['product_type'],
        'description' => $row['product_description'],
        'price'       => floatval($row['product_price']),
        'currency'    => $row['product_currency_code'],
        'code'        => $row['product_code'],
    ];
}
api_response(200, $products);
