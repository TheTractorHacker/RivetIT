<?php
// GET /api/v1/ticket_categories   list categories usable for tickets
defined('FROM_API') || die();
if ($method !== 'GET') api_error(405, 'Method not allowed');

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
