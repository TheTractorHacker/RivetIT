<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

// Ticket events are the original set (queued/delivered async via
// queueWebhookEvent()/cron.php). Platform events are AuditService event_type
// strings (see src/Audit/AuditService.php callers) - subscribing a webhook to
// one of these only records the subscription; nothing dispatches on them yet
// (that's WebhookDispatcher's job, once a real trigger point wires it in).
require_once __DIR__ . '/../includes/webhook_events.php';
$ALL_EVENTS = all_webhook_event_types();

/**
 * Reject webhook endpoint URLs that would let a saved webhook be used to make the
 * RivetIT server itself request internal/cloud-metadata targets (SSRF) when the
 * queued webhook is delivered server-side from cron/cron.php. Only http(s) URLs
 * whose host resolves exclusively to public IP addresses are allowed - loopback,
 * link-local (incl. 169.254.169.254 cloud metadata), and RFC1918 private ranges
 * are rejected, including via DNS resolution (not just literal IPs).
 */
function webhookUrlIsSafe(string $url): bool {
    return (new \RivetCore\Webhooks\UrlPolicy())->isSafe($url);
}

/**
 * Slack / Teams routing fields shared by add and edit. Returns [type, min_priority, client_ids_csv].
 * Priority and clients only mean something to a chat destination; a generic webhook always stores the defaults.
 */
function webhookChatFields(): array {
    $type = \ITFlow\Webhooks\ChatFormatter::normalizeType($_POST['webhook_type'] ?? '');
    if (!\ITFlow\Webhooks\ChatFormatter::isChatType($type)) {
        return [$type, '', ''];
    }
    $min = (string) ($_POST['webhook_min_priority'] ?? '');
    if (!isset(\ITFlow\Webhooks\ChatFormatter::PRIORITIES[$min])) {
        $min = '';
    }
    $ids = [];
    foreach ((array) ($_POST['webhook_client_ids'] ?? []) as $cid) {
        if (is_scalar($cid) && ctype_digit((string) $cid) && (int) $cid > 0) {
            $ids[(int) $cid] = (int) $cid;
        }
    }

    return [$type, $min, implode(',', array_slice(array_values($ids), 0, 60))];
}

if (isset($_POST['add_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    // These values are bound as prepared-statement parameters below, so they must
    // NOT be pre-escaped: cleanInput() is sanitizeInput() minus the SQL escape.
    // Running mysqli_real_escape_string() on a bound value would store the literal
    // backslashes in the row (and, for the secret, encrypt the wrong plaintext).
    $webhook_name    = cleanInput($_POST['webhook_name']);
    // FILTER_SANITIZE_URL is a URL-charset filter, NOT an escaper - both ' and "
    // survive it - so this value is only ever safe as a bound parameter. It is
    // also deliberately left unescaped so webhookUrlIsSafe() below parse_url()s
    // the real URL rather than a backslash-mangled copy of it.
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    [$webhook_type, $webhook_min_priority, $webhook_client_ids] = webhookChatFields();
    $is_chat         = \ITFlow\Webhooks\ChatFormatter::isChatType($webhook_type);
    $webhook_secret  = encryptSetting($is_chat ? '' : cleanInput($_POST['webhook_secret'] ?? ''));
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if ($is_chat) {
        // The chat URL is a secret: validated (https, public address) here and stored encrypted; it is never echoed back.
        $vet = \ITFlow\Webhooks\ChatDelivery::vetUrl($webhook_url);
        if (!$vet['ok']) {
            flash_alert("Chat webhook URL rejected: " . $vet['error'], 'error');
            redirect();
        }
        $webhook_url = encryptSetting($webhook_url);
    } elseif (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL must be a public http(s) address - internal, loopback, and link-local addresses are not allowed.", 'error');
        redirect();
    }

    $stmt = mysqli_prepare(
        $mysqli,
        "INSERT INTO webhooks
         SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?,
             webhook_type = ?, webhook_min_priority = ?, webhook_client_ids = ?"
    );

    mysqli_stmt_bind_param($stmt, "ssssisss", $webhook_name, $webhook_url, $webhook_secret, $webhook_events, $webhook_enabled, $webhook_type, $webhook_min_priority, $webhook_client_ids);

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name added " . ($is_chat ? "$webhook_type " : "") . "webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> added");
    redirect();
}

if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Same rule as the add branch: everything below is bound, so nothing is pre-escaped.
    $webhook_id      = intval($_POST['webhook_id']);
    $webhook_name    = cleanInput($_POST['webhook_name']);
    $webhook_url     = filter_var(trim($_POST['webhook_url'] ?? ''), FILTER_SANITIZE_URL);
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));
    [$webhook_type, $webhook_min_priority, $webhook_client_ids] = webhookChatFields();
    $is_chat         = \ITFlow\Webhooks\ChatFormatter::isChatType($webhook_type);

    $existing_wh = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT webhook_type FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
    if (!$existing_wh) {
        flash_alert("Webhook not found.", 'error');
        redirect();
    }
    $existing_was_chat = \ITFlow\Webhooks\ChatFormatter::isChatType(\ITFlow\Webhooks\ChatFormatter::normalizeType($existing_wh['webhook_type'] ?? ''));
    // A chat destination's URL is never shown, so a blank URL on edit means "keep the saved one".
    $keep_url = $is_chat && $existing_was_chat && $webhook_url === '';

    if (empty($webhook_name) || (empty($webhook_url) && !$keep_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if ($is_chat) {
        if (!$keep_url) {
            $vet = \ITFlow\Webhooks\ChatDelivery::vetUrl($webhook_url);
            if (!$vet['ok']) {
                flash_alert("Chat webhook URL rejected: " . $vet['error'], 'error');
                redirect();
            }
            $webhook_url = encryptSetting($webhook_url);
        }
    } elseif (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL must be a public http(s) address - internal, loopback, and link-local addresses are not allowed.", 'error');
        redirect();
    }

    // Rotate secret only if a new one was provided (chat destinations carry no signing secret)
    $raw_secret = $is_chat ? '' : trim($_POST['webhook_secret'] ?? '');
    $set = "webhook_name = ?, webhook_events = ?, webhook_enabled = ?, webhook_type = ?, webhook_min_priority = ?, webhook_client_ids = ?";
    $types = "ssisss";
    $vals = [$webhook_name, $webhook_events, $webhook_enabled, $webhook_type, $webhook_min_priority, $webhook_client_ids];
    if (!$keep_url) {
        $set .= ", webhook_url = ?";
        $types .= "s";
        $vals[] = $webhook_url;
    }
    if (!empty($raw_secret)) {
        $set .= ", webhook_secret = ?";
        $types .= "s";
        $vals[] = encryptSetting(cleanInput($raw_secret));
    } elseif ($is_chat) {
        $set .= ", webhook_secret = ''";
    }
    $types .= "i";
    $vals[] = $webhook_id;
    $stmt = mysqli_prepare($mysqli, "UPDATE webhooks SET $set WHERE webhook_id = ?");
    mysqli_stmt_bind_param($stmt, $types, ...$vals);

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name edited webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> updated");
    redirect();
}

// Sends one clearly labelled test message to a Slack / Teams destination and reports the HTTP result (never the URL).
if (isset($_POST['test_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    $webhook_id = intval($_POST['webhook_id']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM webhooks WHERE webhook_id = $webhook_id LIMIT 1"));
    if (!$row || !\ITFlow\Webhooks\ChatFormatter::isChatType(\ITFlow\Webhooks\ChatFormatter::normalizeType($row['webhook_type'] ?? ''))) {
        flash_alert("Test messages can be sent to Slack and Teams destinations only.", 'error');
        redirect();
    }

    $r = \ITFlow\Webhooks\ChatDelivery::deliverRow($mysqli, $row, 'test.message', ['summary' => 'This is a test message. Nothing is wrong.'], 1, true);
    $name = nullable_htmlentities($row['webhook_name']);
    logAction("Settings", "Webhook", "$session_name sent a test message to webhook " . sanitizeInput($row['webhook_name']) . ($r['ok'] ? " (HTTP {$r['http_status']})" : " (failed)"));

    if ($r['ok']) {
        flash_alert("Test message sent to <strong>$name</strong>: HTTP " . intval($r['http_status']));
    } else {
        flash_alert("Test message to <strong>$name</strong> failed: " . nullable_htmlentities($r['error'] ?? 'unknown error'), 'error');
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
    redirect();
}
