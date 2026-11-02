<?php
/*
 * Microsoft Teams bot messaging endpoint (the "Messaging endpoint" of your Azure Bot registration):
 *   https://<your RivetIT host>/teams_bot.php
 * Public by nature (the Bot Framework Service calls it), so every request is authenticated here: the Authorization bearer JWT must be signed by a
 * Bot Framework key, issued by api.botframework.com, for THIS bot's App ID, and carry the same serviceUrl as the activity (src/Chat/TeamsJwt.php).
 * Only when "Teams bot" is switched on in Administration > Chat (OFF by default). UNVERIFIED LIVE: see docs/CHAT_LIVE_VERIFICATION.md.
 * 401 with no detail for anything that fails; the activity, token and secrets are never logged.
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
$raw = file_get_contents('php://input', false, null, 0, 200001);
if ($raw === false || strlen($raw) > 200000) {
    http_response_code(413);
    echo '{}';
    exit;
}
$activity = json_decode($raw, true);
if (!is_array($activity)) {
    http_response_code(401); // not distinguishable from an unauthenticated request
    echo '{}';
    exit;
}
$bot = new \ITFlow\Chat\TeamsBot($mysqli);
$auth = $bot->authenticate((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $activity);
if (!$auth['ok']) {
    http_response_code(401);
    echo '{}';
    exit;
}
// Acknowledge first, then work: the Bot Framework expects an answer within 15 seconds and the reply is a separate call to the connector.
http_response_code(200);
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
try {
    $seen = new \ITFlow\Webhooks\SlackInteractive($mysqli);
    $bot->handle($activity, static fn (string $k): bool => $seen->claimOnce($k));
} catch (\Throwable $e) {
    error_log('teams_bot: ' . get_class($e));
}
