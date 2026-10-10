<?php
// GET    /api/v1/relationships?type=asset&id=5      links of one record: outgoing, referenced_by (incoming) and impact (what depends on it)
// POST   /api/v1/relationships                      create a link {src_type, src_id, dst_type, dst_id, link_type, note?}
// DELETE /api/v1/relationships/{link_id}            remove a link
//
// Rules live in ITFlow\Links\LinkService (module permissions, department scope, audit); this file only maps HTTP onto it.
// Read-only rows derived from the older link tables (asset_documents, software_assets, ...) are returned with "derived": true and
// cannot be deleted here. Record types: asset, software, vendor, document, kb_article, service, network, domain, certificate,
// credential, contact, location, ticket.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_mobile.php';

$actor = \ITFlow\Links\LinkActor::forUser($mysqli, intval($api_user_id), $api_key_client_id ?: null);
$service = new \ITFlow\Links\LinkService($mysqli);

$shape = static function (array $e, bool $outgoing): array {
    $o = $e['other'];
    return [
        'link_id'   => $e['derived'] ? null : $e['link_id'],
        'derived'   => $e['derived'],
        'source'    => $e['source'],
        'link_type' => $e['link_type'],
        'note'      => $e['note'],
        'created_at' => $e['created_at'],
        $outgoing ? 'to' : 'from' => ['type' => $o['type'], 'id' => $o['id'], 'name' => $o['name'], 'client_id' => $o['client_id'], 'archived' => $o['archived']],
    ];
};

if ($method === 'GET') {
    if ($id !== null || $sub !== null) api_error(404, 'Not found');
    $type = (string) ($_GET['type'] ?? '');
    $rid  = intval($_GET['id'] ?? 0);
    if (!\ITFlow\Links\EntityTypes::isType($type) || $rid < 1) {
        api_error(400, 'type (' . implode(', ', array_keys(\ITFlow\Links\EntityTypes::all())) . ') and id are required');
    }
    $self = $service->lookup($type, $rid);
    // Same answer for "missing" and "not yours" so the API does not reveal records in departments the caller cannot see.
    if ($self === null || !$actor->canRead($type) || !$actor->canAccessClient($self['client_id'])) {
        api_error(404, 'Record not found');
    }
    $view = $service->view($actor, $type, $rid);
    api_response(200, [
        'record'        => ['type' => $type, 'id' => $rid, 'name' => $self['name'], 'client_id' => $self['client_id']],
        'outgoing'      => array_map(static fn ($e) => $shape($e, true), $view['outgoing']),
        'referenced_by' => array_map(static fn ($e) => $shape($e, false), $view['incoming']),
        'impact'        => array_map(static fn ($n) => ['type' => $n['type'], 'id' => $n['id'], 'name' => $n['name'], 'client_id' => $n['client_id'], 'depth' => $n['depth'], 'via' => $n['via']], $view['impact']),
    ]);
}

if ($method === 'POST') {
    if ($id !== null || $sub !== null) api_error(404, 'Not found');
    $body = api_mobile_json_body();
    $r = $service->create($actor, (string) ($body['src_type'] ?? ''), intval($body['src_id'] ?? 0), (string) ($body['dst_type'] ?? ''), intval($body['dst_id'] ?? 0),
        (string) ($body['link_type'] ?? 'related'), (string) ($body['note'] ?? ''));
    if ($r['ok']) {
        api_response(201, ['link_id' => $r['link_id']]);
    }
    $codes = ['forbidden' => 403, 'not_found' => 404, 'exists' => 409, 'cross_client' => 422, 'self_link' => 422, 'bad_type' => 400, 'bad_link_type' => 400];
    api_error($codes[$r['error']] ?? 400, $r['message']);
}

if ($method === 'DELETE') {
    if ($id === null || $sub !== null) api_error(400, 'DELETE /relationships/{link_id}');
    $r = $service->delete($actor, $id);
    if ($r['ok']) {
        api_response(200, ['success' => true]);
    }
    api_error($r['error'] === 'not_found' ? 404 : 403, $r['message']);
}

api_error(405, 'Method not allowed');
