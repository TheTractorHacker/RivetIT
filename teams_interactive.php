<?php
/*
 * Microsoft Teams interactive-action endpoint:  POST https://<your RivetIT host>/teams_interactive.php
 * Called by the relay flow the admin owns (see docs/SLACK_TEAMS_SETUP.md), never by Teams directly. Public by nature, so it authenticates
 * every request itself (src/Webhooks/TeamsInteractive.php): per-destination shared token in X-Rivet-Teams-Token (constant-time compare),
 * X-Rivet-Timestamp within 5 minutes, single use per request, then the reported Teams user must map to exactly one active agent with
 * rights on the ticket. Anything unauthenticated gets 401 with no detail; the body and token are never logged.
 */

ob_start();
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/event_bus.php';
ob_end_clean();

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{}';
    exit;
}

$raw = file_get_contents('php://input', false, null, 0, \ITFlow\Webhooks\TeamsInteractive::MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > \ITFlow\Webhooks\TeamsInteractive::MAX_BODY_BYTES) {
    http_response_code(413);
    echo '{}';
    exit;
}

$token = (string) ($_SERVER['HTTP_X_RIVET_TEAMS_TOKEN'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_RIVET_TIMESTAMP'] ?? '');
$interactive = new \ITFlow\Webhooks\TeamsInteractive($mysqli);
$webhook = $interactive->destinationFor($token, $timestamp, time());
if ($webhook === null) {
    http_response_code(401);
    echo '{}';
    exit;
}
if (!$interactive->claimOnce($token, $timestamp, $raw)) {
    echo json_encode(['ok' => true, 'acted' => false, 'text' => 'This request was already handled.']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo '{}';
    exit;
}

try {
    $result = $interactive->handle($webhook, $payload);
} catch (\Throwable $e) {
    error_log('teams_interactive: ' . get_class($e));
    $result = ['text' => 'Something went wrong; nothing was changed.', 'acted' => false];
}

// The relay shows "text" to the clicker (Teams has no response_url).
echo json_encode(['ok' => true, 'acted' => $result['acted'], 'text' => $result['text']]);
