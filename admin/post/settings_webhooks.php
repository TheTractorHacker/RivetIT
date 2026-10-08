<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Events a webhook may subscribe to: the picker's catalog (RivetCore EventCatalog + this edition's extras), the original list and every
// audit event type this server has recorded. A subscription may also be a pattern ("ticket.*", "*"): DestinationConfig validates those.
require_once __DIR__ . '/../includes/webhook_events.php';
require_once __DIR__ . '/../../includes/event_bus.php';

use ITFlow\Webhooks\DestinationConfig;
use ITFlow\Webhooks\WebhookTester;

/**
 * Validate the add/edit form against the chosen platform preset (RivetCore Destinations): URL shape, the shared SSRF policy
 * (rivetWebhookUrlPolicy: public addresses plus the admin's allowed networks), auth mode, method, body template, events.
 * The values are bound as prepared-statement parameters, so nothing here is pre-escaped for SQL.
 *
 * @return array{errors:list<string>,row:array<string,mixed>,keep_url:bool,destination:?\RivetCore\Webhooks\Destination}
 */
function webhookValidateForm(?array $existing): array {
    global $mysqli;
    $known = array_flip(all_webhook_event_types());

    return DestinationConfig::validateForm($_POST, $existing, static fn (string $e): bool => isset($known[$e]), static fn (string $u): bool => rivetWebhookUrlPolicy($mysqli)->isSafe($u));
}

/** The Add / Edit pages save through fetch (wh_ajax=1) and expect JSON; the old form post keeps its flash + redirect. */
function webhookWantsJson(): bool {
    return !empty($_POST['wh_ajax']);
}

/** Answer a wh_ajax request. The CSRF token is checked here (not validateCSRFToken, which redirects) so the page can show the problem. */
function webhookJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function webhookCheckCsrf(): void {
    if (webhookWantsJson()) {
        $t = $_POST['csrf_token'] ?? null;
        if (!is_string($t) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $t)) {
            webhookJson(['ok' => false, 'errors' => ['Your session expired. Reload the page and try again.']], 403);
        }
        return;
    }
    validateCSRFToken($_POST['csrf_token'] ?? null);
}

/** Show every problem at once, as one flash (or as JSON for the Add / Edit pages). */
function webhookFail(array $errors): void {
    if (webhookWantsJson()) {
        webhookJson(['ok' => false, 'errors' => $errors]);
    }
    flash_alert('Webhook not saved:<br>' . implode('<br>', array_map('nullable_htmlentities', $errors)), 'error');
    redirect();
}

if (isset($_POST['add_webhook'])) {

    webhookCheckCsrf();

    $v = webhookValidateForm(null);
    if ($v['errors']) {
        webhookFail($v['errors']);
    }
    $row = $v['row'];
    $row += ['webhook_secret' => ''];

    $cols = array_keys($row);
    $stmt = mysqli_prepare($mysqli, "INSERT INTO webhooks SET " . implode(', ', array_map(static fn ($c) => "`$c` = ?", $cols)));
    $types = '';
    foreach ($row as $val) {
        $types .= is_int($val) ? 'i' : 's';
    }
    $vals = array_values($row);
    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $new_id = (int) mysqli_insert_id($mysqli);

    $webhook_name = nullable_htmlentities($row['webhook_name']);
    logAction("Settings", "Webhook", "$session_name added " . $v['destination']->name . " webhook " . sanitizeInput($row['webhook_name']));

    if (webhookWantsJson()) {
        webhookJson(['ok' => true, 'id' => $new_id, 'name' => $row['webhook_name'], 'destination' => $v['destination']->id]);
    }
    flash_alert("Webhook <strong>$webhook_name</strong> added");
    redirect();
}

if (isset($_POST['edit_webhook'])) {

    webhookCheckCsrf();

    $webhook_id = intval($_POST['webhook_id']);
    $existing = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
    if (!$existing) {
        if (webhookWantsJson()) {
            webhookJson(['ok' => false, 'errors' => ['Webhook not found.']], 404);
        }
        flash_alert("Webhook not found.", 'error');
        redirect();
    }

    $v = webhookValidateForm($existing);
    if ($v['errors']) {
        webhookFail($v['errors']);
    }
    $row = $v['row'];

    // A kept URL or secret is simply not in $row; everything else is replaced, including options and auth the new form no longer has.
    $cols = array_keys($row);
    $stmt = mysqli_prepare($mysqli, "UPDATE webhooks SET " . implode(', ', array_map(static fn ($c) => "`$c` = ?", $cols)) . " WHERE webhook_id = ?");
    $types = '';
    foreach ($row as $val) {
        $types .= is_int($val) ? 'i' : 's';
    }
    $vals = array_values($row);
    $vals[] = $webhook_id;
    $types .= 'i';
    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name edited webhook " . sanitizeInput($row['webhook_name']));

    if (webhookWantsJson()) {
        webhookJson(['ok' => true, 'id' => $webhook_id, 'name' => $row['webhook_name'], 'destination' => $v['destination']->id]);
    }
    flash_alert("Webhook <strong>" . nullable_htmlentities($row['webhook_name']) . "</strong> updated");
    redirect();
}

// The enabled switch on the list and the Edit page header: flips one column, answers JSON.
if (isset($_POST['toggle_webhook'])) {

    webhookCheckCsrf();

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $enabled = !empty($_POST['enabled']) ? 1 : 0;
    $name = getFieldById('webhooks', $webhook_id, 'webhook_name');
    if ($name === null || $name === false) {
        webhookJson(['ok' => false, 'errors' => ['Webhook not found.']], 404);
    }
    $stmt = mysqli_prepare($mysqli, "UPDATE webhooks SET webhook_enabled = ? WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, "ii", $enabled, $webhook_id);
    mysqli_stmt_execute($stmt);
    logAction("Settings", "Webhook", "$session_name " . ($enabled ? 'enabled' : 'disabled') . " webhook " . sanitizeInput($name));
    webhookJson(['ok' => true, 'id' => $webhook_id, 'enabled' => $enabled]);
}

// A copy of a webhook (secrets copied as stored: same keys, still encrypted), created disabled so it never doubles deliveries by accident.
if (isset($_POST['duplicate_webhook'])) {

    validateCSRFToken($_POST['csrf_token'] ?? null);

    $webhook_id = intval($_POST['webhook_id'] ?? 0);
    $src = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
    if (!$src) {
        flash_alert("Webhook not found.", 'error');
        redirect('settings_webhooks.php');
    }
    unset($src['webhook_id']);
    foreach (['webhook_created_at', 'webhook_updated_at'] as $ts) {
        unset($src[$ts]);
    }
    $src['webhook_name'] = mb_substr($src['webhook_name'], 0, 190) . ' (copy)';
    $src['webhook_enabled'] = 0;
    $cols = array_keys($src);
    $stmt = mysqli_prepare($mysqli, "INSERT INTO webhooks SET " . implode(', ', array_map(static fn ($c) => "`$c` = ?", $cols)));
    $types = '';
    foreach ($src as $val) {
        $types .= is_int($val) ? 'i' : 's';
    }
    $vals = array_values($src);
    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    $new_id = (int) mysqli_insert_id($mysqli);
    logAction("Settings", "Webhook", "$session_name duplicated webhook " . sanitizeInput($src['webhook_name']));
    flash_alert("Webhook duplicated as <strong>" . nullable_htmlentities($src['webhook_name']) . "</strong> (disabled until you turn it on)");
    redirect('webhook_edit.php?id=' . $new_id);
}

// Sends one clearly labelled test event to a saved webhook through the real delivery path and reports the HTTP result (never the URL).
// The Add / Edit form has its own Send test (modals/webhook/webhook_action.php) that tries unsaved settings.
if (isset($_POST['test_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    $webhook_id = intval($_POST['webhook_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
    if (!$row) {
        flash_alert("Webhook not found.", 'error');
        redirect();
    }

    $r = WebhookTester::send($mysqli, $row, 'ticket.created');
    $name = nullable_htmlentities($row['webhook_name']);
    logAction("Settings", "Webhook", "$session_name sent a test message to webhook " . sanitizeInput($row['webhook_name']) . ($r['ok'] ? " (HTTP {$r['http_status']})" : " (failed)"));

    $snippet = $r['response'] !== null && $r['response'] !== '' ? ' Response: ' . nullable_htmlentities(mb_substr($r['response'], 0, 200)) : '';
    if ($r['ok']) {
        flash_alert("Test message sent to <strong>$name</strong>: HTTP " . intval($r['http_status']) . " in " . intval($r['duration_ms']) . " ms." . $snippet);
    } else {
        flash_alert("Test message to <strong>$name</strong> failed: " . nullable_htmlentities($r['error'] ?? 'unknown error') . $snippet, 'error');
    }
    redirect();
}

// Sends a logged event again (only the standard JSON body, and test sends, can be rebuilt from the log).
if (isset($_POST['replay_webhook_delivery'])) {

    validateCSRFToken($_POST['csrf_token']);

    $delivery_id = intval($_POST['delivery_id']);
    $r = WebhookTester::replay($mysqli, $delivery_id);
    logAction("Settings", "Webhook", "$session_name replayed webhook delivery #$delivery_id" . ($r['ok'] ? " (HTTP {$r['http_status']})" : " (failed)"));
    if ($r['ok']) {
        flash_alert("Delivery #$delivery_id replayed: HTTP " . intval($r['http_status']) . " in " . intval($r['duration_ms']) . " ms.");
    } else {
        flash_alert("Replay of delivery #$delivery_id failed: " . nullable_htmlentities($r['error'] ?? 'unknown error'), 'error');
    }
    redirect();
}

if (isset($_GET['delete_webhook'])) {

    validateCSRFToken($_GET['csrf_token']);

    $webhook_id   = intval($_GET['delete_webhook']);
    $webhook_name = sanitizeInput(getFieldById('webhooks', $webhook_id, 'webhook_name'));

    mysqli_query($mysqli, "DELETE FROM webhook_queue WHERE queue_webhook_id = $webhook_id");
    mysqli_query($mysqli, "DELETE FROM webhooks WHERE webhook_id = $webhook_id");

    logAction("Settings", "Webhook", "$session_name deleted webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> deleted", 'error');
    redirect('settings_webhooks.php');
}

// Slack interactive actions: install-wide options (the signing secret itself lives on each Slack destination).
if (isset($_POST['save_slack_interactive'])) {

    validateCSRFToken($_POST['csrf_token']);

    $link_by_email = isset($_POST['config_slack_link_by_email']) ? 1 : 0;
    $team_id = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) ($_POST['config_slack_team_id'] ?? ''))));
    $team_id = substr($team_id, 0, 32);
    $token = trim((string) ($_POST['config_slack_bot_token'] ?? ''));

    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_slack_link_by_email = ?, config_slack_team_id = ? WHERE company_id = 1");
    mysqli_stmt_bind_param($stmt, "is", $link_by_email, $team_id);
    mysqli_stmt_execute($stmt);

    if (isset($_POST['config_slack_bot_token_clear'])) {
        mysqli_query($mysqli, "UPDATE settings SET config_slack_bot_token = '' WHERE company_id = 1");
    } elseif ($token !== '') {
        if (!preg_match('/^xoxb-[A-Za-z0-9-]{10,200}$/', $token)) {
            flash_alert("That does not look like a Slack bot token (it starts with xoxb-). Nothing was changed for the token.", 'error');
            redirect();
        }
        $enc = encryptSetting($token);
        $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_slack_bot_token = ? WHERE company_id = 1");
        mysqli_stmt_bind_param($stmt, "s", $enc);
        mysqli_stmt_execute($stmt);
    }

    logAction("Settings", "Edit", "$session_name " . ($link_by_email ? "allowed" : "disallowed") . " Slack users to be matched to agents by verified email (Slack interactive actions)");

    flash_alert("Slack interactive settings saved");
    redirect();
}

// Internal networks webhooks may reach. The list is validated by RivetCore's NetworkList (private ranges only, not too wide);
// loopback, link-local and cloud-metadata addresses can never be listed.
function webhookSaveNetworks(string $raw, string $audit_summary): bool {
    global $mysqli, $session_user_id;

    $parsed = \RivetCore\Webhooks\NetworkList::parse($raw);
    $stored = implode("\n", $parsed['networks']);
    $errors = $parsed['errors'];
    if (strlen($stored) > 500) {
        $errors[] = 'The list is too long to store (maximum 500 characters).';
    }
    if ($errors) {
        $_SESSION['webhook_networks_draft'] = $raw;
        flash_alert('Networks not saved:<br>' . implode('<br>', array_map('nullable_htmlentities', $errors)), 'error');
        return false;
    }

    $before = rivetWebhookAllowedNetworks($mysqli);
    $stmt = mysqli_prepare($mysqli, "UPDATE settings SET config_webhook_allowed_networks = ?");
    mysqli_stmt_bind_param($stmt, "s", $stored);
    mysqli_stmt_execute($stmt);
    unset($_SESSION['webhook_networks_draft']);

    if ($before !== $parsed['networks']) {
        logAction("Settings", "Webhook", "$GLOBALS[session_name] changed the webhook allowed networks");
        rivetAudit('webhooks.networks_changed', (int) $session_user_id, 'settings', 1, 'update', $audit_summary,
            ['before' => $before, 'after' => $parsed['networks']]);
    }
    flash_alert('Allowed internal networks saved' . ($parsed['networks'] ? ': <strong>' . nullable_htmlentities(implode(', ', $parsed['networks'])) . '</strong>' : ' (none: webhooks may only call public addresses)'));
    return true;
}

if (isset($_POST['save_webhook_networks'])) {

    validateCSRFToken($_POST['csrf_token']);

    webhookSaveNetworks((string) ($_POST['webhook_allowed_networks'] ?? ''), 'Webhook allowed networks changed');
    redirect();
}

// One-click add of a detected / suggested network to the saved list.
if (isset($_POST['add_webhook_network'])) {

    validateCSRFToken($_POST['csrf_token']);

    $current = rivetWebhookAllowedNetworks($mysqli);
    webhookSaveNetworks(implode("\n", $current) . "\n" . (string) ($_POST['network'] ?? ''), 'Webhook allowed network added');
    redirect();
}
