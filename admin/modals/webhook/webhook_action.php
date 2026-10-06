<?php

// JSON endpoint behind the Add / Edit webhook form (js/webhook_form.js): "Send test", "Preview payload" and the live template check.
// Admin only (modal_header.php), POST only, CSRF-checked. Nothing is saved. Secrets are never returned: the preview masks auth headers
// and shows the URL's host only, and a test result carries the HTTP status, duration and a short cut of the response body.

require_once '../../../includes/modal_header.php';
require_once '../../includes/webhook_events.php';
require_once '../../../includes/event_bus.php';

use ITFlow\Webhooks\DestinationConfig;
use ITFlow\Webhooks\WebhookTester;
use RivetCore\Webhooks\PayloadTemplate;

$out = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $out(['ok' => false, 'errors' => ['POST only.']], 405);
}
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $csrf)) {
    $out(['ok' => false, 'errors' => ['Your session expired. Reload the page and try again.']], 403);
}
$action = (string) ($_POST['wh_action'] ?? '');

if ($action === 'validate_template') {
    $enc = (string) ($_POST['webhook_template_encoding'] ?? 'json');
    $tpl = str_replace("\r\n", "\n", (string) ($_POST['webhook_template'] ?? ''));
    $errors = PayloadTemplate::validate($tpl, $enc);
    $sample = '';
    if (!$errors) {
        try {
            $sample = PayloadTemplate::render($tpl, PayloadTemplate::sampleContext('ticket.created'), $enc);
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
    $out(['ok' => !$errors, 'errors' => $errors, 'sample' => $sample]);
}

if (!in_array($action, ['test', 'preview'], true)) {
    $out(['ok' => false, 'errors' => ['Unknown action.']], 400);
}

// The form is validated exactly like a save (same rules), against the saved row when editing so blank secrets mean "keep".
$existing = null;
$wid = intval($_POST['webhook_id'] ?? 0);
if ($wid > 0) {
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $wid LIMIT 1")) ?: null;
    if (!$existing) {
        $out(['ok' => false, 'errors' => ['Webhook not found.']], 404);
    }
}
$known = array_flip(all_webhook_event_types());
$v = DestinationConfig::validateForm($_POST, $existing, static fn (string $e): bool => isset($known[$e]), static fn (string $u): bool => rivetWebhookUrlPolicy($GLOBALS['mysqli'])->isSafe($u));
if ($v['errors']) {
    $out(['ok' => false, 'errors' => $v['errors']]);
}
$draft = DestinationConfig::draftRow($v, $existing);

$event = (string) ($_POST['sample_event'] ?? 'ticket.created');
if (!in_array($event, WebhookTester::TEST_EVENTS, true)) {
    $event = 'ticket.created';
}

if ($action === 'preview') {
    $p = DestinationConfig::preview($draft, $event);
    $out(['ok' => true, 'preview' => $p, 'event' => $event, 'events' => WebhookTester::TEST_EVENTS]);
}

$r = WebhookTester::send($mysqli, $draft, $event);
logAction('Settings', 'Webhook', "$session_name sent a test to " . sanitizeInput($draft['webhook_name']) . ($r['ok'] ? " (HTTP {$r['http_status']})" : ' (failed)'));
$out(['ok' => true, 'result' => $r]);
