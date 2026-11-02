<?php
// Inbound events from the QMS (issue #52). Reached only from api/v1/index.php, only with a valid integration access token:
//
//   POST /api/v1/integrations/qms/events     quality:events    HMAC-signed JSON event (X-Rivet-Signature-V2), replay protected
//
// Layers: the token's scope, then the Quality module + "accept events" switch (503 module_disabled while off), then the signature and timestamp, then the
// body checks and the (source, event id) replay table. See src/Quality/InboundEvents.php and docs/QUALITY_CONTRACT.md. The caller is the integration client in
// $integration_client / $integration_scopes; there is no user and no session. Department scope does not apply: an event carries no department data.
defined('FROM_API') || die();

use ITFlow\Integration\ApiResponse;
use ITFlow\Quality\InboundEvents;
use ITFlow\Quality\QualityConfig;

if (!isset($integration_client, $integration_scopes)) {
    ApiResponse::error(401, 'unauthorized', 'Authentication required.');
}
$qe_parts = explode('/', trim((string) $path, '/'));
if ($qe_parts !== ['integrations', 'qms', 'events']) {
    ApiResponse::error(404, 'not_found', 'Not found.');
}
if ($method !== 'POST') {
    ApiResponse::error(405, 'method_not_allowed', 'Use POST.', ['Allow' => 'POST']);
}
if (!in_array('quality:events', $integration_scopes, true)) {
    ApiResponse::error(403, 'insufficient_scope', 'The access token does not carry the scope quality:events.');
}
// Rejected server-side while the module is off, whatever the UI shows.
if (!QualityConfig::moduleOn($mysqli)) {
    ApiResponse::error(503, 'module_disabled', 'The Quality module is not enabled on this server.');
}
$qe_ct = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($qe_ct !== 'application/json') {
    ApiResponse::error(415, 'unsupported_media_type', 'Send application/json.');
}
$qe_body = (string) file_get_contents('php://input', false, null, 0, InboundEvents::MAX_BODY + 1);
$qe_sig = (string) ($_SERVER['HTTP_X_RIVET_SIGNATURE_V2'] ?? '');
try {
    $qe = InboundEvents::handle($mysqli, $qe_body, $qe_sig, 'client-' . (int) $integration_client['client_id']);
} catch (\Throwable $e) {
    error_log('Quality events: ' . get_class($e) . ': ' . $e->getMessage());
    ApiResponse::error(500, 'server_error', 'The request could not be completed.');
}
if ($qe['http'] >= 400) {
    $err = $qe['body']['error'] ?? ['code' => 'error', 'message' => 'Error'];
    ApiResponse::error($qe['http'], (string) $err['code'], (string) $err['message']);
}
ApiResponse::json($qe['http'], $qe['body']);
