<?php
/*
 * Slack Events API endpoint (Event Subscriptions > Request URL in your Slack app):
 *   https://<your RivetIT host>/slack_events.php
 * Public by nature, so every request is authenticated here: Slack signing secret + 5 minute window + constant-time compare + single use per
 * signature (src/Chat/SlackAuth.php), then each event once (Slack retries). Handles thread replies -> ticket replies (two-way) and direct
 * messages / mentions -> the virtual agent, each only when switched on in Administration > Chat (all OFF by default).
 * 401 with no detail for anything that is not a valid Slack request; payloads and tokens are never logged.
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
$auth = \ITFlow\Chat\SlackAuth::authenticate($mysqli, (string) ($_SERVER['HTTP_X_SLACK_REQUEST_TIMESTAMP'] ?? ''), $raw, (string) ($_SERVER['HTTP_X_SLACK_SIGNATURE'] ?? ''), time());
if ($auth === null) {
    http_response_code(401);
    echo '{}';
    exit;
}
$interactive = new \ITFlow\Webhooks\SlackInteractive($mysqli);
if (!$interactive->claimOnce((string) $_SERVER['HTTP_X_SLACK_SIGNATURE'])) {
    echo '{}';
    exit;
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo '{}';
    exit;
}
try {
    $answer = (new \ITFlow\Chat\SlackApp($mysqli))->events($body, static fn (string $k): bool => $interactive->claimOnce($k));
} catch (\Throwable $e) {
    error_log('slack_events: ' . get_class($e));
    $answer = null;
}
echo json_encode($answer ?? new \stdClass());
