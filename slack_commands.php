<?php
/*
 * Slack slash command endpoint (Slash Commands > /ticket > Request URL in your Slack app):
 *   https://<your RivetIT host>/slack_commands.php
 * Public by nature, so every request is authenticated here exactly like slack_interactive.php (signing secret, 5 minute window, single use), then the
 * Slack user must map to an agent or portal contact (src/Chat/ChatIdentity.php). Only when "slash commands" is switched on in Administration > Chat
 * (OFF by default). The answer is always ephemeral (only the person who typed the command sees it).
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
if (!(new \ITFlow\Webhooks\SlackInteractive($mysqli))->claimOnce((string) $_SERVER['HTTP_X_SLACK_SIGNATURE'])) {
    echo json_encode(['response_type' => 'ephemeral', 'text' => 'This request was already handled.']);
    exit;
}
parse_str($raw, $form);
try {
    $answer = (new \ITFlow\Chat\SlackApp($mysqli))->command(array_map('strval', array_filter($form, 'is_scalar')));
} catch (\Throwable $e) {
    error_log('slack_commands: ' . get_class($e));
    $answer = ['response_type' => 'ephemeral', 'text' => 'Something went wrong; nothing was changed.'];
}
echo $answer === [] ? '' : json_encode($answer);
