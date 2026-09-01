<?php
// GET /api/v1/reports/expiring?type=domains|certificates&days=30
// New endpoint — no equivalent web report; dashboard only shows a 30-day count, this returns the full list.
defined('FROM_API') || die();

api_require_module_permission($mysqli, $api_user_id, 'module_support');

$type = $_GET['type'] ?? 'domains';
$days = max(1, intval($_GET['days'] ?? 30));

if (!in_array($type, ['domains', 'certificates'], true)) {
    api_error(400, "type must be 'domains' or 'certificates'");
}

// A legacy API key deliberately restricted to one client ($api_key_client_id) must never
// see another client's (or the whole company's) expiring domains/certificates - an
// unrestricted key keeps the same company-wide behavior as before.
if ($type === 'domains') {
    $client_filter = !empty($api_key_client_id) ? " AND domain_client_id = " . intval($api_key_client_id) : '';
    $sql = mysqli_query($mysqli,
        "SELECT domain_id AS id, domain_name AS name, domain_expire AS expire_date, client_name
         FROM domains
         LEFT JOIN clients ON client_id = domain_client_id
         WHERE domain_expire IS NOT NULL
           AND domain_expire > CURRENT_DATE
           AND domain_expire < CURRENT_DATE + INTERVAL $days DAY
           AND domain_archived_at IS NULL$client_filter
         ORDER BY domain_expire ASC"
    );
} else {
    $client_filter = !empty($api_key_client_id) ? " AND certificate_client_id = " . intval($api_key_client_id) : '';
    $sql = mysqli_query($mysqli,
        "SELECT certificate_id AS id, certificate_name AS name, certificate_expire AS expire_date, client_name
         FROM certificates
         LEFT JOIN clients ON client_id = certificate_client_id
         WHERE certificate_expire IS NOT NULL
           AND certificate_expire > CURRENT_DATE
           AND certificate_expire < CURRENT_DATE + INTERVAL $days DAY
           AND certificate_archived_at IS NULL$client_filter
         ORDER BY certificate_expire ASC"
    );
}

$items = [];
while ($row = mysqli_fetch_assoc($sql)) {
    $items[] = [
        'id'          => intval($row['id']),
        'name'        => $row['name'],
        'expire_date' => $row['expire_date'],
        'client_name' => $row['client_name'],
    ];
}

api_response(200, ['type' => $type, 'days' => $days, 'items' => $items]);
