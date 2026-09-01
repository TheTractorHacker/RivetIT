<?php
// Comet Backup webhook receiver
// Configure in Comet Server: Admin → Server Settings → Webhooks
//   URL: https://itflow.foleyit.com/comet_webhook.php
//   Events: Job Completed (SEVT_JOB_COMPLETED = 4201)
//   Custom Header: X-Comet-Secret: <your webhook secret>

ob_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/load_global_settings.php';
require_once __DIR__ . '/includes/comet.php';
ob_end_clean();

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verify secret header. Fail closed: an unconfigured secret must reject every
// request rather than treat "no secret set" as "no auth required" - this is a
// public, unauthenticated-by-default endpoint otherwise.
$received = $_SERVER['HTTP_X_COMET_SECRET'] ?? '';
if (empty($config_comet_webhook_secret) || !hash_equals($config_comet_webhook_secret, $received)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$event_type = intval($body['Type'] ?? 0);

// We only care about job completed events
if ($event_type !== COMET_SEVT_JOB_COMPLETED) {
    echo json_encode(['ok' => true, 'note' => 'Event type ignored']);
    exit;
}

$job = $body['Data'] ?? null;
if (!$job || !is_array($job)) {
    echo json_encode(['ok' => true, 'note' => 'No job data']);
    exit;
}

$username = $job['Username'] ?? '';

$result   = comet_process_job($job);
$dev_name = $result['device_name'] ?? 'Unknown Device';

if ($result['action'] === 'ticket_created') {
    logApp('Comet', 'info', "Webhook: backup failure ticket #{$result['ticket_id']} created for $dev_name ($username)");
} elseif ($result['action'] === 'ticket_resolved') {
    logApp('Comet', 'info', "Webhook: backup recovered for $dev_name — ticket #{$result['ticket_id']} resolved");
}

echo json_encode(array_merge(['ok' => true], $result));
