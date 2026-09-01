<?php

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

$ALL_EVENTS = ['ticket.created','ticket.replied','ticket.assigned','ticket.status_changed','ticket.resolved'];

/**
 * Reject webhook endpoint URLs that would let a saved webhook be used to make the
 * ITFlow server itself request internal/cloud-metadata targets (SSRF) when the
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

    $webhook_name    = sanitizeInput($_POST['webhook_name']);
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    $webhook_secret  = mysqli_real_escape_string($mysqli, encryptSetting(sanitizeInput($_POST['webhook_secret'] ?? '')));
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = sanitizeInput(implode(',', $valid_events));

    if (empty($webhook_name) || empty($webhook_url) || empty($valid_events)) {
        flash_alert("Name, URL, and at least one event are required.", 'error');
        redirect();
    }

    if (!webhookUrlIsSafe($webhook_url)) {
        flash_alert("Endpoint URL must be a public http(s) address - internal, loopback, and link-local addresses are not allowed.", 'error');
        redirect();
    }

    mysqli_query($mysqli, "INSERT INTO webhooks SET webhook_name = '$webhook_name', webhook_url = '$webhook_url', webhook_secret = '$webhook_secret', webhook_events = '$webhook_events', webhook_enabled = $webhook_enabled");

    logAction("Settings", "Webhook", "$session_name added webhook $webhook_name");

    flash_alert("Webhook <strong>$webhook_name</strong> added");
    redirect();
}

if (isset($_POST['edit_webhook'])) {

    validateCSRFToken($_POST['csrf_token']);

    $webhook_id      = intval($_POST['webhook_id']);
    $webhook_name    = sanitizeInput($_POST['webhook_name']);
    $webhook_url     = filter_var(trim($_POST['webhook_url']), FILTER_SANITIZE_URL);
    $webhook_enabled = isset($_POST['webhook_enabled']) ? 1 : 0;
    $raw_events      = $_POST['webhook_events'] ?? [];
    $valid_events    = array_intersect($raw_events, $ALL_EVENTS);
    $webhook_events  = sanitizeInput(implode(',', $valid_events));

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
        $webhook_secret = mysqli_real_escape_string($mysqli, encryptSetting(sanitizeInput($raw_secret)));
        mysqli_query($mysqli, "UPDATE webhooks SET webhook_name = '$webhook_name', webhook_url = '$webhook_url', webhook_secret = '$webhook_secret', webhook_events = '$webhook_events', webhook_enabled = $webhook_enabled WHERE webhook_id = $webhook_id");
    } else {
        mysqli_query($mysqli, "UPDATE webhooks SET webhook_name = '$webhook_name', webhook_url = '$webhook_url', webhook_events = '$webhook_events', webhook_enabled = $webhook_enabled WHERE webhook_id = $webhook_id");
    }

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
