<?php
// GET /api/v1/ticket_categories   list categories usable for tickets
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

// Ticket categories are part of Tickets (roles audit P1g): an Assets-only or Sales-only token gets 403.
api_require_module_permission($mysqli, intval($api_user_id), 'module_support');

$categories = [];
$sql = mysqli_query($mysqli,
    "SELECT category_id, category_name, category_color FROM categories
     WHERE category_type = 'Ticket' AND category_archived_at IS NULL
     ORDER BY category_order ASC, category_name ASC"
);
while ($row = mysqli_fetch_assoc($sql)) {
    $categories[] = [
        'id'    => intval($row['category_id']),
        'name'  => $row['category_name'],
        'color' => $row['category_color'],
    ];
}

api_response(200, $categories);
