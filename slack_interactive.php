<?php
/*
 * Slack interactivity endpoint (the "Request URL" under Interactivity & Shortcuts in your Slack app):
 *   https://<your RivetIT host>/slack_interactive.php
 * Public by nature (Slack calls it), so it authenticates every request itself: Slack signing secret (X-Slack-Signature, v0 HMAC-SHA256,
 * 5 minute window, constant-time compare), single use per signature, then the Slack user must map to an active agent who opted in
 * (see src/Webhooks/SlackInteractive.php). Like comet_webhook.php it needs no nginx rule: it is an ordinary root-level PHP file.
 * It answers 401 with no detail to anything that is not a valid Slack request, and never writes a payload or token to a log.
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

$raw = file_get_contents('php://input', false, null, 0, \ITFlow\Webhooks\SlackInteractive::MAX_BODY_BYTES + 1);
if ($raw === false || strlen($raw) > \ITFlow\Webhooks\SlackInteractive::MAX_BODY_BYTES) {
    http_response_code(413);
    echo '{}';
    exit;
}

$interactive = new \ITFlow\Webhooks\SlackInteractive($mysqli);
$webhook = $interactive->destinationFor((string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''), $raw, (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''), time());
if ($webhook === null) {
    http_response_code(401);
    echo '{}';
    exit;
}
if (!$interactive->claimOnce((string) $_SERVER['HTTP_X_SLACK_SIGNATURE'])) {
    // A replay of a request that was already handled: say so, do nothing.
    echo json_encode(['response_type' => 'ephemeral', 'text' => 'This request was already handled.']);
    exit;
}

parse_str($raw, $form);
$payload = isset($form['payload']) && is_string($form['payload']) ? json_decode($form['payload'], true) : null;
if (!is_array($payload)) {
    http_response_code(400);
    echo '{}';
    exit;
}

try {
    $result = $interactive->handle($webhook, $payload);
} catch (\Throwable $e) {
    error_log('slack_interactive: ' . get_class($e));
    $result = ['text' => 'Something went wrong; nothing was changed.', 'acted' => false];
}

if ($result['text'] !== null && is_string($payload['response_url'] ?? null)) {
    \ITFlow\Webhooks\SlackInteractive::respond($payload['response_url'], $result['text']);
}
echo json_encode(['ok' => true]);
