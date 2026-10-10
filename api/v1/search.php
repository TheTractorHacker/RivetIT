<?php
// GET /api/v1/search?q=   global search across tickets, clients, assets
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
$q_raw = trim($_GET['q'] ?? '');
$q = mysqli_real_escape_string($mysqli, $q_raw);
if (strlen($q) < 2) api_error(400, 'Query must be at least 2 characters');

// LIKE pattern bound as a prepared-statement parameter below. Inner %/_ keep
// acting as wildcards, exactly as the previous "LIKE '%$q%'" interpolation did.
$like = '%' . $q_raw . '%';

// Client-scope restriction, mirroring tickets.php/appointments.php, applied per-table below.
$scope_clause_for = fn(string $client_id_col) => api_client_scope_sql($client_id_col);

// Roles audit P1g/F8: every section checks the module that owns it, like the web search.
$search_support = api_has_module_permission($mysqli, $uid, 'module_support');
$search_clients = api_has_module_permission($mysqli, $uid, 'module_client');
$search_assets  = $search_support || api_has_module_permission($mysqli, $uid, 'module_assets');

// Tickets
$tickets = [];
$rows = !$search_support ? [] : api_q(
    "SELECT t.ticket_id, t.ticket_number, t.ticket_subject, t.ticket_priority,
            ts.ticket_status_name, c.client_name
     FROM tickets t
     LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
     LEFT JOIN clients c ON t.ticket_client_id = c.client_id
     WHERE t.ticket_archived_at IS NULL
       AND (t.ticket_subject LIKE ? OR t.ticket_number LIKE ?)
       AND " . $scope_clause_for('t.ticket_client_id') . "
     ORDER BY t.ticket_created_at DESC LIMIT 8",
    'ss',
    [$like, $like]
);
foreach ($rows as $row) {
    $tickets[] = [
        'id'      => intval($row['ticket_id']),
        'number'  => intval($row['ticket_number']),
        'subject' => $row['ticket_subject'],
        'status'  => $row['ticket_status_name'],
        'client'  => $row['client_name'],
        'priority'=> $row['ticket_priority'],
    ];
}

// Clients
$clients = [];
$rows = !$search_clients ? [] : api_q(
    // Departments link to locations via department_sites (many-to-many, added for
    // multi-location support) - locations.location_client_id is no longer written by the
    // department create/edit flows, so joining on it directly always missed.
    "SELECT c.client_id, c.client_name, l.location_phone
     FROM clients c
     LEFT JOIN (
         SELECT ds.client_id, MIN(ds.location_id) AS location_id
         FROM department_sites ds
         GROUP BY ds.client_id
     ) ds_primary ON ds_primary.client_id = c.client_id
     LEFT JOIN locations l ON l.location_id = ds_primary.location_id
     WHERE c.client_archived_at IS NULL AND c.client_name LIKE ?
       AND " . $scope_clause_for('c.client_id') . "
     ORDER BY c.client_name ASC LIMIT 5",
    's',
    [$like]
);
foreach ($rows as $row) {
    $clients[] = [
        'id'    => intval($row['client_id']),
        'name'  => $row['client_name'],
        'phone' => $row['location_phone'],
    ];
}

// Contacts - gated behind module_client like api/v1/contacts.php itself; omitted (not a
// hard 403) if the caller lacks it, since search blends several entity types in one response.
$contacts = [];
if (api_has_module_permission($mysqli, $uid, 'module_client')) {
    $rows = api_q(
        "SELECT ct.contact_id, ct.contact_name, ct.contact_title, ct.contact_email,
                ct.contact_phone, ct.contact_extension, c.client_id, c.client_name
         FROM contacts ct
         LEFT JOIN clients c ON ct.contact_client_id = c.client_id
         WHERE ct.contact_archived_at IS NULL
           AND (ct.contact_name LIKE ? OR ct.contact_email LIKE ?)
           AND " . $scope_clause_for('ct.contact_client_id') . "
         ORDER BY ct.contact_name ASC LIMIT 5",
        'ss',
        [$like, $like]
    );
    foreach ($rows as $row) {
        $contacts[] = [
            'id'        => intval($row['contact_id']),
            'name'      => $row['contact_name'],
            'title'     => $row['contact_title'],
            'email'     => $row['contact_email'],
            'phone'     => $row['contact_phone'],
            'extension' => $row['contact_extension'],
            'client_id' => $row['client_id'] !== null ? intval($row['client_id']) : null,
            'client'    => $row['client_name'],
        ];
    }
}

// Credentials - name/description only, never decrypted secrets, matching the web app's own
// global search. Gated behind module_credential like api/v1/credentials.php itself.
$credentials = [];
if (api_has_module_permission($mysqli, $uid, 'module_credential')) {
    $rows = api_q(
        "SELECT cr.credential_id, cr.credential_name, cr.credential_uri, c.client_name
         FROM credentials cr
         LEFT JOIN clients c ON cr.credential_client_id = c.client_id
         WHERE cr.credential_archived_at IS NULL
           AND (cr.credential_name LIKE ? OR cr.credential_description LIKE ?)
           AND " . $scope_clause_for('cr.credential_client_id') . "
         ORDER BY cr.credential_name ASC LIMIT 5",
        'ss',
        [$like, $like]
    );
    foreach ($rows as $row) {
        $credentials[] = [
            'id'     => intval($row['credential_id']),
            'name'   => $row['credential_name'],
            'uri'    => $row['credential_uri'],
            'client' => $row['client_name'],
        ];
    }
}

// Knowledge base articles - gated behind module_kb like api/v1/kb.php itself. Company-wide
// articles (kb_article_client_id = 0) are visible to everyone, mirroring kb.php's own scope
// clause.
$articles = [];
if (api_has_module_permission($mysqli, $uid, 'module_kb')) {
    $kb_scope = "(k.kb_article_client_id = 0 OR " . $scope_clause_for('k.kb_article_client_id') . ")";
    $rows = api_q(
        "SELECT k.kb_article_id, k.kb_article_title
         FROM kb_articles k
         WHERE k.kb_article_archived_at IS NULL
           AND k.kb_article_title LIKE ?
           AND $kb_scope
         ORDER BY k.kb_article_title ASC LIMIT 5",
        's',
        [$like]
    );
    foreach ($rows as $row) {
        $articles[] = [
            'id'    => intval($row['kb_article_id']),
            'title' => $row['kb_article_title'],
        ];
    }
}

// Assets (Assets module or Tickets/assets/docs - P4)
$assets = [];
$rows = !$search_assets ? [] : api_q(
    "SELECT a.asset_id, a.asset_name, a.asset_tag, a.asset_serial, a.asset_make, a.asset_model, c.client_name
     FROM assets a LEFT JOIN clients c ON a.asset_client_id = c.client_id
     WHERE a.asset_archived_at IS NULL
       AND (a.asset_name LIKE ? OR a.asset_tag LIKE ? OR a.asset_serial LIKE ?
            OR a.asset_make LIKE ? OR a.asset_model LIKE ?)
       AND " . $scope_clause_for('a.asset_client_id') . "
     ORDER BY a.asset_name ASC LIMIT 5",
    'sssss',
    [$like, $like, $like, $like, $like]
);
foreach ($rows as $row) {
    $assets[] = [
        'id'     => intval($row['asset_id']),
        'name'   => $row['asset_name'],
        'tag'    => $row['asset_tag'],
        'serial' => $row['asset_serial'],
        'make'   => $row['asset_make'],
        'model'  => $row['asset_model'],
        'client' => $row['client_name'],
    ];
}

// Software, networks, services and linked records (ITFlow\Links\PlatformSearch: the same code as the web search), additive keys.
$platform_actor  = \ITFlow\Links\LinkActor::forUser($mysqli, intval($uid), $api_key_client_id ?: null);
$platform_search = new \ITFlow\Links\PlatformSearch($mysqli);
$platform_map = static fn (array $rows): array => array_map(static fn ($r) => ['id' => $r['id'], 'name' => $r['name'], 'detail' => $r['detail'], 'client_id' => $r['client_id'], 'client' => $r['client_name']], $rows);
$software = $platform_map($platform_search->software($platform_actor, $q_raw, 5));
$networks = $platform_map($platform_search->networks($platform_actor, $q_raw, 5));
$services = $platform_map($platform_search->services($platform_actor, $q_raw, 5));
$linked   = array_map(static fn ($r) => ['type' => $r['type'], 'id' => $r['id'], 'name' => $r['name'], 'client_id' => $r['client_id'], 'linked_to' => $r['linked_to'], 'relation' => $r['relation']], $platform_search->linkedRecords($platform_actor, $q_raw, 10));

api_response(200, [
    'tickets'     => $tickets,
    'clients'     => $clients,
    'assets'      => $assets,
    'contacts'    => $contacts,
    'credentials' => $credentials,
    'articles'    => $articles,
    'software'    => $software,
    'networks'    => $networks,
    'services'    => $services,
    'linked'      => $linked,
]);
