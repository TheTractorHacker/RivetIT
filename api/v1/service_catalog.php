<?php
// GET  /api/v1/service_catalog.php   active catalog items with their request-form fields, popular + recent item ids
// POST /api/v1/service_catalog.php   {"catalog_item_id":N,"answers":{"<key>":value},"client_id":N|null} -> raises the ticket
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
require_once __DIR__ . '/includes/api_mobile.php';
require_once $DOCUMENT_ROOT . '/src/ITSM/ServiceCatalogService.php';

api_mobile_require_user_token();
$uid = intval($api_user_id);

// Same gate as agent/service_catalog.php (view) and the New Ticket handler (create = edit level).
api_require_module_permission($mysqli, $uid, 'module_support', $method === 'GET' ? 1 : 2);

$catalog_service = new \ITFlow\ITSM\ServiceCatalogService($mysqli);

if ($method === 'GET') {
    $items = [];
    $rs = mysqli_query($mysqli, "SELECT * FROM service_catalog_items WHERE is_active = 1 ORDER BY sort_order ASC, name ASC");
    while ($rs && ($row = mysqli_fetch_assoc($rs))) {
        $item_id = intval($row['catalog_item_id']);
        $fields = [];
        foreach ($catalog_service->getFields($item_id) as $f) {
            $options = [];
            if ($f['field_type'] === 'select') {
                $options = array_values(array_filter(array_map('trim', explode("\n", (string) ($f['options'] ?? ''))), 'strlen'));
            }
            $fields[] = [
                'key'         => (string) $f['field_key'],
                'label'       => (string) $f['label'],
                'type'        => (string) $f['field_type'],
                'options'     => $options,
                'required'    => !empty($f['is_required']),
                'placeholder' => (string) ($f['placeholder'] ?? ''),
                // Conditional visibility is not part of the catalog schema in this release; always null.
                'show_if'     => null,
            ];
        }
        $items[] = [
            'id'                => $item_id,
            'name'              => (string) $row['name'],
            'description'       => (string) ($row['description'] ?? ''),
            'icon'              => (string) ($row['icon'] ?: 'fa-ticket-alt'),
            'requires_approval' => !empty($row['requires_approval']),
            'risk_score'        => intval($row['risk_score']),
            'fields'            => $fields,
        ];
    }

    $popular = array_map(fn($t) => intval($t['catalog_item_id']), $catalog_service->trending(5, 30));

    // This user's last 5 distinct active items (tickets they raised from the catalog), newest first.
    $recent = [];
    $rs = mysqli_query($mysqli,
        "SELECT i.catalog_item_id, MAX(t.ticket_created_at) AS last_used
         FROM tickets t JOIN service_catalog_items i ON i.catalog_item_id = t.ticket_catalog_item_id
         WHERE t.ticket_created_by = $uid AND i.is_active = 1 AND t.ticket_archived_at IS NULL
         GROUP BY i.catalog_item_id ORDER BY last_used DESC, i.catalog_item_id DESC LIMIT 5");
    while ($rs && ($r = mysqli_fetch_assoc($rs))) {
        $recent[] = intval($r['catalog_item_id']);
    }

    api_response(200, ['items' => $items, 'popular' => $popular, 'recent' => $recent]);
}

if ($method === 'POST') {
    $body = api_mobile_json_body();
    $item_id = $body['catalog_item_id'] ?? null;
    $answers = $body['answers'] ?? [];
    $client  = $body['client_id'] ?? null;

    if (!is_int($item_id) || $item_id < 1) {
        api_error(422, 'catalog_item_id must be a positive integer');
    }
    if (!is_array($answers)) {
        api_error(422, 'answers must be an object');
    }
    if ($client !== null && (!is_int($client) || $client < 0)) {
        api_error(422, 'client_id must be a positive integer or null');
    }
    $client_id = intval($client ?? 0);

    $item = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM service_catalog_items WHERE catalog_item_id = $item_id AND is_active = 1 LIMIT 1"));
    if (!$item) {
        api_error(404, 'Catalog item not found');
    }

    // Department access: same as ticket creation in tickets.php (and enforceClientAccess() on the web).
    if (!api_mobile_client_ok($client_id)) {
        api_error(403, 'Access denied');
    }
    if ($client_id > 0) {
        $c = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_id = $client_id AND client_archived_at IS NULL LIMIT 1"));
        if (!$c) {
            api_error(422, 'client_id does not exist');
        }
    }

    // Answers: scalars arrive as the web form's strings (a JSON true/false/number becomes '1'/'0'/'12'). Arrays and objects are
    // left as they are so validateInput() rejects them. Keys that are not a defined field are never read, so nothing is stored.
    $post_answers = [];
    foreach ($answers as $k => $v) {
        if (is_bool($v)) {
            $v = $v ? '1' : '0';
        } elseif (is_int($v) || is_float($v)) {
            $v = (string) $v;
        }
        $post_answers[(string) $k] = $v;
    }
    $check = \ITFlow\ITSM\ServiceCatalogService::validateInput($catalog_service->getFields($item_id), $post_answers);
    if ($check['errors']) {
        api_response(422, ['error' => implode(' ', $check['errors']), 'errors' => $check['errors']]);
    }

    // Contact: the client's primary/first contact, like tickets.php (none for an internal, department-less request).
    $contact_id = 0;
    if ($client_id > 0) {
        $pc = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT contact_id FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
             ORDER BY contact_primary DESC, contact_id ASC LIMIT 1"));
        $contact_id = $pc ? intval($pc['contact_id']) : 0;
    }

    $subject_raw = trim((string) ($item['ticket_subject_template'] ?? '')) !== '' ? trim((string) $item['ticket_subject_template']) : (string) $item['name'];
    $details_raw = '<p>Requested via the mobile app: ' . htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') . '</p>';

    $priority = ucfirst(strtolower((string) ($item['default_priority'] ?? '')));
    if (!in_array($priority, ['Low', 'Medium', 'High', 'Critical'], true)) {
        $priority = 'Low';
    }
    $category = intval($item['ticket_category_id'] ?? 0);
    if ($category) {
        $cat = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT category_id FROM categories WHERE category_id = $category AND category_type = 'Ticket' AND category_archived_at IS NULL LIMIT 1"));
        if (!$cat) {
            $category = 0;
        }
    }
    $category = resolveTicketCategory($category);
    $assigned = resolveTicketAssignee(0);
    $status = resolveTicketCreationStatus($assigned);

    mysqli_query($mysqli, "
        UPDATE settings
        SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
            config_ticket_next_number = config_ticket_next_number + 1
        WHERE company_id = 1
    ");
    $next_num = mysqli_insert_id($mysqli);
    $prefix_esc = mysqli_real_escape_string($mysqli, $config_ticket_prefix ?? '');
    $url_key = randomString(32);

    $ticket_id = api_exec(
        "INSERT INTO tickets (ticket_prefix, ticket_subject, ticket_details, ticket_client_id, ticket_contact_id, ticket_priority,
         ticket_status, ticket_assigned_to, ticket_created_by, ticket_source, ticket_number, ticket_category, ticket_url_key,
         ticket_catalog_item_id, ticket_created_at, ticket_updated_at)
         VALUES ('$prefix_esc', ?, ?, $client_id, $contact_id, '$priority', $status, $assigned, $uid, 'API', $next_num, $category, '$url_key',
                 $item_id, NOW(), NOW())",
        'ss',
        [$subject_raw, $details_raw]
    );
    if (!$ticket_id) {
        api_error(500, 'Could not create the ticket');
    }

    // Store the answers and, when the item needs approval, hold the ticket and ask the first step's approvers.
    $status_out = 'created';
    if ($catalog_service->hasRequestFlow($item)) {
        $sub = $catalog_service->submit($item, $ticket_id, $client_id, $contact_id, $uid, $check['values']);
        if ($sub['status'] === 'pending_approval') {
            $status_out = 'pending_approval';
        }
    }

    require_once $DOCUMENT_ROOT . '/includes/sla_functions.php';
    recalculateTicketSla($mysqli, $ticket_id);

    require_once $DOCUMENT_ROOT . '/includes/ticket_automation_dispatch.php';
    runTicketCreatedAutomation($mysqli, $ticket_id);

    $client_uri = $client_id ? "&client_id=$client_id" : '';
    if ($assigned != 0 && $assigned != $uid) {
        notifyUser($assigned, 'Ticket', "New ticket {$config_ticket_prefix}$next_num - $subject_raw has been assigned to you", "/agent/ticket.php?ticket_id=$ticket_id$client_uri", $client_id, $ticket_id);
    }
    queueWebhookEvent('ticket.created', getWebhookTicketPayload($ticket_id));

    api_mobile_audit_context();
    logAction('Ticket', 'Create', "$session_name raised ticket {$config_ticket_prefix}$next_num from catalog item {$item['name']} via the mobile API", $client_id, $ticket_id);

    api_response(201, ['ok' => true, 'ticket_id' => $ticket_id, 'status' => $status_out]);
}

api_error(405, 'Method not allowed');
