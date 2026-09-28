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
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    $host = $parts['host'];

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = [];
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if ($records) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
        if (empty($ips)) {
            $resolved = @gethostbyname($host);
            if ($resolved !== $host) {
                $ips[] = $resolved;
            }
        }
    }

    if (empty($ips)) {
        // Couldn't resolve the host - reject rather than allow an unknown destination
        return false;
    }

    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return true;
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
    $webhook_secret  = encryptSetting(cleanInput($_POST['webhook_secret'] ?? ''));
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL must be a public http(s) address - internal, loopback, and link-local addresses are not allowed.", 'error');
        redirect();
    }

    $stmt = mysqli_prepare(
        $mysqli,
        "INSERT INTO webhooks
         SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?"
    );

    mysqli_stmt_bind_param($stmt, "ssssi", $webhook_name, $webhook_url, $webhook_secret, $webhook_events, $webhook_enabled);

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name added webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> added");
    redirect();
}

if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    // Same rule as the add branch: everything below is bound, so nothing is pre-escaped.
    $webhook_id      = intval($_POST['webhook_id']);
    $webhook_name    = cleanInput($_POST['webhook_name']);
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = cleanInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL must be a public http(s) address - internal, loopback, and link-local addresses are not allowed.", 'error');
        redirect();
    }

    // Rotate secret only if a new one was provided
    $raw_secret = trim($_POST['webhook_secret'] ?? '');
    if (!empty($raw_secret)) {
        $webhook_secret = encryptSetting(cleanInput($raw_secret));
        $stmt = mysqli_prepare(
            $mysqli,
            "UPDATE webhooks
             SET webhook_name = ?, webhook_url = ?, webhook_secret = ?, webhook_events = ?, webhook_enabled = ?
             WHERE webhook_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ssssii", $webhook_name, $webhook_url, $webhook_secret, $webhook_events, $webhook_enabled, $webhook_id);
    } else {
        $stmt = mysqli_prepare(
            $mysqli,
            "UPDATE webhooks
             SET webhook_name = ?, webhook_url = ?, webhook_events = ?, webhook_enabled = ?
             WHERE webhook_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "sssii", $webhook_name, $webhook_url, $webhook_events, $webhook_enabled, $webhook_id);
    }

    mysqli_stmt_execute($stmt);

    logAction("Settings", "Webhook", "$session_name edited webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> updated");
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
