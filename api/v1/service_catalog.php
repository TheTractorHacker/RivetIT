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
    // describeItem() is the one shape for a catalog item (the same description a chat integration uses).
    $items = [];
    foreach ($catalog_service->activeItems() as $row) {
        $items[] = $catalog_service->describeItem($row);
    }
    $categories = array_map(fn($c) => [
        'id'          => intval($c['category_id']),
        'name'        => (string) $c['name'],
        'description' => (string) ($c['description'] ?? ''),
        'icon'        => (string) ($c['icon'] ?: 'fa-folder'),
    ], $catalog_service->getCategories(true));

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

    api_response(200, ['categories' => $categories, 'items' => $items, 'popular' => $popular, 'recent' => $recent]);
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

    // Contact: none unless one is chosen. The request is made by the signed-in user ($uid); it must not be attached to the client's primary or first contact.
    $contact_id = 0;

    // Validation, the ticket, the stored answers, the computed risk and the approval hold all happen in the service, which is also
    // the entry point for chat (Slack / Teams). Access was decided above; the service trusts its caller for that.
    $made = $catalog_service->createRequest($item_id, $answers, $client_id, $contact_id, $uid, 'API', 'Requested via the mobile app: ' . (string) $item['name']);
    if (!$made['ok']) {
        if ($made['code'] === 422 && count($made['errors']) > 0 && $made['error'] !== 'client_id does not exist') {
            api_response(422, ['error' => $made['error'], 'errors' => $made['errors']]);
        }
        api_error($made['code'], (string) $made['error']);
    }
    $ticket_id = $made['ticket_id'];
    $status_out = $made['status'];
    $subject_raw = $made['subject'];
    $next_num = $made['ticket_number'];
    $assigned = $made['assigned_to'];
    $category = $made['category_id'];

    // Per-category SLA-exempt / Non-billable (DB 2.6.205), before the SLA engine runs.
    require_once $DOCUMENT_ROOT . '/includes/ticket_category_rules.php';
    ticketCategoryApplyOnCreate($mysqli, $ticket_id, intval($category));
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
