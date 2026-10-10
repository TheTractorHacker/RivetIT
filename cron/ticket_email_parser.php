<?php
/*
 * CRON - Email Parser (Webklex PHP-IMAP)
 * Process emails and create/update tickets using Webklex\PHPIMAP instead of native IMAP
 */

// Start the timer
$script_start_time = microtime(true);

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Autoload (Webklex & any composer deps)
require_once "../plugins/vendor/autoload.php";

// Get RivetIT config & helper functions
require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('ticket_email_parser', 300);

// Get settings for the "default" company
require_once "../includes/load_global_settings.php";

// Multi-mailbox support: Webklex\PHPIMAP is used per-mailbox inside pollMailbox().
use Webklex\PHPIMAP\ClientManager;

// The parsing, dedupe, threading and health logic lives in src/Mail (unit-tested against .eml fixtures without a mailbox,
// see tests/mail_intake_parser.php); this script wires it to IMAP / Microsoft Graph and the ticket functions.
require_once "../vendor/autoload.php";
use ITFlow\Mail\ClamScanner;
use ITFlow\Mail\InboundPreparer;
use ITFlow\Mail\InboundRouter;
use ITFlow\Mail\IntakeStore;
use ITFlow\Mail\MailHealth;
use ITFlow\Mail\MailSettings;
use ITFlow\Mail\MessageId;
use ITFlow\Mail\MessageNormalizer;
use ITFlow\Mail\QuotedTextStripper;
use ITFlow\Mail\RawHeaders;

$config_ticket_prefix = sanitizeInput($config_ticket_prefix);
$config_ticket_from_name = sanitizeInput($config_ticket_from_name);
// NOTE: unknown-sender parsing is now a per-mailbox setting (mailbox_parse_unknown_senders),
// not a global one - see pollMailbox() below.

// Check setting enabled
if ($config_ticket_email_parse == 0) {
    exit("Email Parser: Feature is not enabled - check Settings > Ticketing > Email-to-ticket parsing -- Quitting..");
}

// System temp directory & lock
$temp_dir = sys_get_temp_dir();
$lock_file_path = "{$temp_dir}/itflow_email_parser_{$installation_id}.lock";

if (file_exists($lock_file_path)) {
    $file_age = time() - filemtime($lock_file_path);
    if ($file_age > 300) {
        unlink($lock_file_path);
        logApp("Cron-Email-Parser", "warning", "Cron Email Parser detected a lock file was present but was over 5 minutes old so it removed it.");
    } else {
        logApp("Cron-Email-Parser", "warning", "Lock file present. Cron Email Parser attempted to execute but was already executing, so instead it terminated.");
        exit("Script is already running. Exiting.");
    }
}
file_put_contents($lock_file_path, "Locked");

// Ensure lock gets removed even on fatal error
register_shutdown_function(function() use ($lock_file_path) {
    if (file_exists($lock_file_path)) {
        @unlink($lock_file_path);
    }
});

/** ------------------------------------------------------------------
 * OAuth helpers + provider guard
 * ------------------------------------------------------------------ */

// returns true if expires_at ('Y-m-d H:i:s') is in the past (or missing)
function tokenExpired(?string $expires_at): bool {
    if (empty($expires_at)) return true;
    $ts = strtotime($expires_at);
    if ($ts === false) return true;
    // refresh a little early (60s) to avoid race
    return ($ts - 60) <= time();
}

// very small form-encoded POST helper using curl
function httpFormPost(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields, '', '&'));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => ($raw !== false && $code >= 200 && $code < 300), 'body' => $raw, 'code' => $code, 'err' => $err];
}

/**
 * Get a valid access token for Google Workspace IMAP via refresh token if needed.
 * The OAuth app registration (client id/secret) is still the shared, global
 * config_mail_oauth_* settings, but the refresh/access tokens are per-mailbox.
 * Refreshed tokens are persisted back onto the `mailboxes` row.
 */
function getGoogleAccessToken(string $username, array $mailbox): ?string {
    global $mysqli,
           $config_mail_oauth_client_id,
           $config_mail_oauth_client_secret;

    $mailbox_id = intval($mailbox['mailbox_id']);
    $refresh_token = decryptSetting($mailbox['mailbox_oauth_refresh_token_enc'] ?? '');
    $access_token = decryptSetting($mailbox['mailbox_oauth_access_token_enc'] ?? '');
    $access_token_expires_at = $mailbox['mailbox_oauth_access_token_expires_at'] ?? null;

    // If we have a not-expired token, use it
    if (!empty($access_token) && !tokenExpired($access_token_expires_at)) {
        return $access_token;
    }

    // Need to refresh?
    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($refresh_token)) {
        // Nothing we can do
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Google OAuth token refresh skipped - client_id/client_secret/refresh_token not fully configured in Admin > Settings > Mail.");
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Google Workspace', 'client id, client secret or refresh token is missing');
        return null;
    }

    $resp = httpFormPost(
        'https://oauth2.googleapis.com/token',
        [
            'client_id'     => $config_mail_oauth_client_id,
            'client_secret' => $config_mail_oauth_client_secret,
            'refresh_token' => $refresh_token,
            'grant_type'    => 'refresh_token',
        ]
    );

    if (!$resp['ok']) {
        // Surface Google's actual reason (e.g. invalid_grant: token revoked/expired, invalid_client) like the Microsoft path
        // does, and raise the (de-duplicated) admin alert - a silent null used to hide exactly what needed fixing.
        $err_json = json_decode($resp['body'] ?? '', true);
        $err_detail = is_array($err_json) ? trim(($err_json['error'] ?? '') . ' ' . ($err_json['error_description'] ?? '')) : ($resp['err'] ?: (string) $resp['body']);
        $err_detail = substr(trim((string) $err_detail) !== '' ? (string) $err_detail : 'no detail returned', 0, 500);
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Google OAuth token refresh failed (HTTP {$resp['code']}): " . $err_detail);
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Google Workspace', "HTTP {$resp['code']} $err_detail");
        return null;
    }

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) {
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Google OAuth token refresh returned no access_token despite HTTP {$resp['code']}.");
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Google Workspace', "token endpoint returned no access_token (HTTP {$resp['code']})");
        return null;
    }

    // Calculate new expiry
    $new_access_token = $json['access_token'];
    $expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

    // Persist the refreshed token onto this mailbox's row
    $at_enc_esc  = mysqli_real_escape_string($mysqli, encryptSetting($new_access_token));
    $exp_esc     = mysqli_real_escape_string($mysqli, $expires_at);
    mysqli_query($mysqli, "UPDATE mailboxes SET
        mailbox_oauth_access_token_enc = '{$at_enc_esc}',
        mailbox_oauth_access_token_expires_at = '{$exp_esc}'
        WHERE mailbox_id = {$mailbox_id}
    ");

    return $new_access_token;
}

/**
 * Get a valid access token for Microsoft 365 IMAP via refresh token if needed.
 * The OAuth app registration (client id/secret/tenant) is still the shared, global
 * config_mail_oauth_* settings, but the refresh/access tokens are per-mailbox.
 * Refreshed tokens are persisted back onto the `mailboxes` row.
 */
function getMicrosoftAccessToken(string $username, array $mailbox): ?string {
    global $mysqli,
           $config_mail_oauth_client_id,
           $config_mail_oauth_client_secret,
           $config_mail_oauth_tenant_id;

    $mailbox_id = intval($mailbox['mailbox_id']);
    $refresh_token = decryptSetting($mailbox['mailbox_oauth_refresh_token_enc'] ?? '');
    $access_token = decryptSetting($mailbox['mailbox_oauth_access_token_enc'] ?? '');
    $access_token_expires_at = $mailbox['mailbox_oauth_access_token_expires_at'] ?? null;

    if (!empty($access_token) && !tokenExpired($access_token_expires_at)) {
        return $access_token;
    }

    if (empty($config_mail_oauth_client_id) || empty($config_mail_oauth_client_secret) || empty($refresh_token) || empty($config_mail_oauth_tenant_id)) {
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Microsoft OAuth token refresh skipped - client_id/client_secret/tenant_id/refresh_token not fully configured in Admin > Settings > Mail.");
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Microsoft 365', 'client id, client secret, tenant id or refresh token is missing');
        return null;
    }

    $url = "https://login.microsoftonline.com/".rawurlencode($config_mail_oauth_tenant_id)."/oauth2/v2.0/token";

    $resp = httpFormPost($url, [
        'client_id'     => $config_mail_oauth_client_id,
        'client_secret' => $config_mail_oauth_client_secret,
        'refresh_token' => $refresh_token,
        'grant_type'    => 'refresh_token',
        // IMAP/SMTP scopes typically included at initial consent; not needed for refresh
    ]);

    if (!$resp['ok']) {
        // Surface Microsoft's actual reason (e.g. AADSTS7000215 invalid client
        // secret, AADSTS700082 expired refresh token) instead of silently
        // returning null - a generic "no usable access token" downstream error
        // used to hide exactly what needed fixing and where.
        $err_json = json_decode($resp['body'] ?? '', true);
        $err_detail = is_array($err_json) ? ($err_json['error_description'] ?? $err_json['error'] ?? $resp['body']) : $resp['body'];
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Microsoft OAuth token refresh failed (HTTP {$resp['code']}): " . substr((string) $err_detail, 0, 500));
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Microsoft 365', "HTTP {$resp['code']} " . substr((string) $err_detail, 0, 300));
        return null;
    }

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) {
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Microsoft OAuth token refresh returned no access_token despite HTTP {$resp['code']}.");
        MailHealth::oauthFailure($mysqli, $mailbox_id, 'Microsoft 365', "token endpoint returned no access_token (HTTP {$resp['code']})");
        return null;
    }

    $new_access_token = $json['access_token'];
    $expires_at = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 3600));

    // Persist the refreshed token onto this mailbox's row
    $at_enc_esc  = mysqli_real_escape_string($mysqli, encryptSetting($new_access_token));
    $exp_esc     = mysqli_real_escape_string($mysqli, $expires_at);
    mysqli_query($mysqli, "UPDATE mailboxes SET
        mailbox_oauth_access_token_enc = '{$at_enc_esc}',
        mailbox_oauth_access_token_expires_at = '{$exp_esc}'
        WHERE mailbox_id = {$mailbox_id}
    ");

    return $new_access_token;
}

/** ------------------------------------------------------------------
 * Shared classification logic (protocol-agnostic)
 *
 * Given a normalized, already-extracted message (works the same whether it
 * came from Webklex/IMAP or Microsoft Graph), decides what it is, in this order:
 *   0. duplicate Message-ID           -> skipped (already imported)
 *   1. machine-generated mail         -> auto-reply / OOO / list / our own mail looping back: logged as "suppressed",
 *                                        never a ticket or a reply; a bounce (DSN) only notifies an admin
 *   2. per-sender hourly cap          -> quarantined as a mail request (reason "rate limited")
 *   3. In-Reply-To / References       -> reply to the ticket owning that Message-ID (inbound or one we sent)
 *   4. [PREFIX-123] subject token     -> reply to that ticket
 *   5. fuzzy subject, known contact / domain, unknown sender (mail request)
 * and calls addTicket()/addReply() accordingly.
 * Returns whether the message was handled (true) or should stay unread/
 * flagged for manual review (false).
 *
 * $intake (optional): ['norm' => MessageNormalizer array, 'prep' => InboundPreparer array]. The Message-ID, headers and
 * quote stripping come from it; without it the function behaves as before (no dedupe, threading or stripping).
 * ------------------------------------------------------------------ */
function processInboundMessage(
    int $mailbox_id,
    int $mailbox_default_client_id,
    int $mailbox_parse_unknown_senders,
    string $from_email,
    string $from_name,
    string $subject,
    array $ccs,
    string $date,
    string $message_body,
    string $message_body_text,
    array $attachments,
    array $raw_parts,
    string $original_message_file,
    array $intake = []
): bool {
    global $mysqli, $config_ticket_prefix;

    $email_processed = false;

    // Tracked across whichever branch below fires, then recorded once via logMailEvent()
    // at the end - see Admin > Maintenance > Email Log.
    $mail_log_outcome = 'ignored';
    $mail_log_detail = null;
    $mail_log_ticket_id = null;
    $mail_log_mail_request_id = null;

    $norm = $intake['norm'] ?? null;
    $prep = $intake['prep'] ?? null;
    $mail_message_id = (string) ($norm['message_id'] ?? '');

    $from_domain = explode("@", $from_email);
    $from_domain = sanitizeInput(end($from_domain));

    // Body used when the message is a reply: quoted history removed (text part preferred), never emptied.
    $reply_body = $message_body;
    if ($norm !== null && $prep !== null) {
        $stripped = QuotedTextStripper::stripReply((string) $prep['body_html'], (string) $norm['text']);
        if ($stripped['stripped']) {
            $reply_body = $stripped['body'] . (string) $prep['note'];
        }
    }

    // Runs addReply() for a ticket number and records the outcome for the mail log. Returns whether the message was handled.
    $reply_to = function (int $ticket_number, string $detail) use (&$mail_log_outcome, &$mail_log_detail, &$mail_log_ticket_id, &$mail_log_mail_request_id, $from_email, $date, $subject, $reply_body, $attachments, $mailbox_id, $from_name, $ccs, $original_message_file, $mail_message_id, $mysqli): bool {
        $meta = [];
        $handled = addReply($from_email, $date, $subject, $ticket_number, $reply_body, $attachments, $mailbox_id, $from_name, $ccs, $original_message_file, $mail_message_id, $meta);
        if (!$handled) {
            return false;
        }
        $tid_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_number = " . intval($ticket_number) . " LIMIT 1"));
        $mail_log_ticket_id = $tid_row ? intval($tid_row['ticket_id']) : null;
        switch ($meta['outcome'] ?? 'reply_added') {
            case 'mail_request':
                $mail_log_outcome = 'mail_request';
                $mail_log_detail = "Sender is not a contact of ticket #$ticket_number - queued for review";
                $mail_log_mail_request_id = !empty($meta['request_id']) ? intval($meta['request_id']) : null;
                $mail_log_ticket_id = null;
                break;
            case 'ticket_closed':
                $mail_log_outcome = 'reply_added';
                $mail_log_detail = "Ticket #$ticket_number is closed - sender told to open a new ticket ($detail)";
                break;
            default:
                $mail_log_outcome = 'reply_added';
                $mail_log_detail = $detail;
        }
        return true;
    };

    // 0-3. Duplicate / machine-generated / rate cap / Message-ID threading
    if ($norm !== null) {
        $store = new IntakeStore($mysqli);
        $route = InboundRouter::preRoute($store, $norm, ['rate_cap' => MailSettings::int($mysqli, 'rate_cap_per_hour')]);

        switch ($route['action']) {
            case 'duplicate':
                logMailEvent($mailbox_id, $from_email, $from_name, $subject, 'duplicate', $route['detail'], null, null);
                return true; // already imported: the redelivered copy just gets moved out of the inbox

            case 'suppressed':
                logMailEvent($mailbox_id, $from_email, $from_name, $subject, 'suppressed', "Auto-generated mail ({$route['rule']}): {$route['detail']}", null, null);
                return true;

            case 'dsn':
                $mail_log_detail = handleInboundNdr($raw_parts, $subject, $message_body_text, $from_email, $mailbox_id);
                logMailEvent($mailbox_id, $from_email, $from_name, $subject, 'ndr', $mail_log_detail, null, null);
                return true;

            case 'rate_limited':
                $quarantine_id = null;
                if ($store->quarantinedForSender($from_email) < 50) { // bound the quarantine itself
                    $quarantine_id = createMailRequestFromInbound($mailbox_id, $from_email, $from_name, $subject, $ccs, $date, substr($message_body, 0, 20000), [], $original_message_file, $mail_message_id, 'rate_limited');
                }
                MailHealth::alert($mysqli, 'ratelimit:' . sha1(strtolower($from_email)), 'Sender rate limit reached', "$from_email sent {$route['count']} messages in the last hour (cap " . MailSettings::int($mysqli, 'rate_cap_per_hour') . "). Further mail from this sender is held under Mail requests (reason: rate limited) until an admin reviews it - possible mail loop or flood.", '/admin/mail_requests.php');
                logMailEvent($mailbox_id, $from_email, $from_name, $subject, 'rate_limited', $route['detail'], null, $quarantine_id);
                return true;

            case 'thread':
                $tn_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_number FROM tickets WHERE ticket_id = " . intval($route['ticket_id']) . " LIMIT 1"));
                if ($tn_row && $reply_to(intval($tn_row['ticket_number']), $route['detail'])) {
                    $email_processed = true;
                }
                break;
        }
    }

    // 1. Reply to existing ticket with the number in subject
    if (!$email_processed && preg_match("/\[$config_ticket_prefix(\d+)\]/", $subject, $ticket_number_matches)) {
        $ticket_number = intval($ticket_number_matches[1]);
        $email_processed = $reply_to($ticket_number, "Matched ticket #$ticket_number by subject tag");
    }

    // 2. Fuzzy duplicate check using a known contact/domain and similar_text subject
    if (!$email_processed && strlen(trim($subject)) > 10) {
        $contact_id = 0;
        $client_id  = 0;

        // First: check if sender is a registered contact
        $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);
        $contact_sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_email = '$from_email_esc' AND contact_archived_at IS NULL LIMIT 1");
        $contact_row = mysqli_fetch_assoc($contact_sql);

        if ($contact_row) {
            $contact_id = intval($contact_row['contact_id']);
            $client_id  = intval($contact_row['contact_client_id']);
        } else {
            // Else: check if sender domain is registered
            $from_domain_esc = mysqli_real_escape_string($mysqli, $from_domain);
            $domain_sql = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_name = '$from_domain_esc' AND domain_archived_at IS NULL LIMIT 1");
            $domain_row = mysqli_fetch_assoc($domain_sql);

            if ($domain_row && $from_domain == $domain_row['domain_name']) {
                $client_id = intval($domain_row['domain_client_id']);
            }
        }

        // If we found either a contact or a domain, check recent tickets for a matching subject
        if ($client_id) {
            $recent_tickets_sql = mysqli_query($mysqli,
                "SELECT ticket_id, ticket_number, ticket_subject
                FROM tickets
                WHERE ticket_client_id = $client_id AND ticket_resolved_at IS NULL
                AND ticket_created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );

            while ($rowt = mysqli_fetch_assoc($recent_tickets_sql)) {
                $ticket_number = intval($rowt['ticket_number']);
                $existing_subject = $rowt['ticket_subject'];

                // Calculate similarity percentage
                similar_text(strtolower($subject), strtolower($existing_subject), $percent);

                if ($percent >= 95) {
                    // Treat as a reply/duplicate
                    $email_processed = $reply_to($ticket_number, "Matched ticket #$ticket_number by fuzzy subject match ({$percent}%)");
                    break;
                }
            }
        }
    }

    // 3. A known, registered contact?
    if (!$email_processed) {
        $from_email_esc = mysqli_real_escape_string($mysqli, $from_email);
        $any_contact_sql = mysqli_query($mysqli, "SELECT * FROM contacts WHERE contact_email = '$from_email_esc' AND contact_archived_at IS NULL LIMIT 1");
        $rowc = mysqli_fetch_assoc($any_contact_sql);

        if ($rowc) {
            $contact_name  = sanitizeInput($rowc['contact_name']);
            $contact_id    = intval($rowc['contact_id']);
            $contact_email = sanitizeInput($rowc['contact_email']);
            $client_id     = intval($rowc['contact_client_id']);

            $email_processed = addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message_body, $attachments, $original_message_file, $ccs, $mailbox_id, $mail_message_id);
            if ($email_processed) {
                $mail_log_outcome = 'ticket_created';
                $mail_log_detail = 'New ticket from known contact';
                $mail_log_ticket_id = intval($email_processed);
            }
        }
    }

    // 4. A known domain?
    if (!$email_processed) {
        $from_domain_esc = mysqli_real_escape_string($mysqli, $from_domain);
        $domain_sql = mysqli_query($mysqli, "SELECT * FROM domains WHERE domain_name = '$from_domain_esc' AND domain_archived_at IS NULL LIMIT 1");
        $rowd = mysqli_fetch_assoc($domain_sql);

        if ($rowd && $from_domain == $rowd['domain_name']) {
            $client_id = intval($rowd['domain_client_id']);

            // Create a new contact
            $contact_name  = $from_name;
            $contact_email = $from_email;
            mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '".mysqli_real_escape_string($mysqli, $contact_name)."', contact_email = '".mysqli_real_escape_string($mysqli, $contact_email)."', contact_notes = 'Added automatically via email parsing.', contact_client_id = $client_id");
            $contact_id = mysqli_insert_id($mysqli);

            logAction("Contact", "Create", "Email parser: created contact " . $contact_name, $client_id, $contact_id);
            customAction('contact_create', $contact_id);

            $email_processed = addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message_body, $attachments, $original_message_file, $ccs, $mailbox_id, $mail_message_id);
            if ($email_processed) {
                $mail_log_outcome = 'ticket_created';
                $mail_log_detail = 'New ticket from known domain (new contact created)';
                $mail_log_ticket_id = intval($email_processed);
            }
        }
    }

    // 5. Unknown sender allowed?
    if (!$email_processed && $mailbox_parse_unknown_senders) {

        $bad_from_pattern = "/daemon|postmaster|bounce|mta/i"; //  Stop NDRs with bad subjects raising new tickets
        if (!preg_match($bad_from_pattern, $from_email)) {
            // Queue for review instead of ticketing immediately - see admin/mail_requests.php.
            // The mailbox's default client (if any) is applied later, at convert time.
            $new_mail_request_id = createMailRequestFromInbound($mailbox_id, $from_email, $from_name, $subject, $ccs, $date, $message_body, $attachments, $original_message_file, $mail_message_id, 'unknown_sender');
            $email_processed = (bool) $new_mail_request_id;
            if ($email_processed) {
                $mail_log_outcome = 'mail_request';
                $mail_log_detail = 'Unknown sender queued for review';
                $mail_log_mail_request_id = $new_mail_request_id;
            }

        } else {

            // Probably an NDR message that carried no machine-readable DSN part
            $mail_log_detail = handleInboundNdr($raw_parts, $subject, $message_body_text, $from_email, $mailbox_id);
            $email_processed = true;
            $mail_log_outcome = 'ndr';
        }
    }

    if ($mail_log_outcome === 'ignored') {
        $mail_log_detail = $mailbox_parse_unknown_senders
            ? 'Unknown sender - did not match a ticket, contact, or domain'
            : 'Unknown sender - mailbox does not queue unknown senders';
    }

    logMailEvent($mailbox_id, $from_email, $from_name, $subject, $mail_log_outcome, $mail_log_detail, $mail_log_ticket_id, $mail_log_mail_request_id);

    return $email_processed;
}

/**
 * A bounce (NDR / DSN): work out who it failed for and why, tell the admins, and leave a system note on the ticket the
 * original message belonged to. Never creates a ticket and never sends anything. Returns the detail line for the mail log.
 */
function handleInboundNdr(array $raw_parts, string $subject, string $message_body_text, string $from_email, int $mailbox_id): string {
    global $mysqli, $config_ticket_prefix;

    $failed_recipient  = null;
    $diagnostic_code   = null;
    $status_code       = null;
    $original_subject  = null;

    // DSN info shows up as regular attachment/part entries, not the visible body
    foreach ($raw_parts as $attachment) {

        $ctype = strtolower($attachment['content_type'] ?? '');
        $body  = (string) ($attachment['content'] ?? '');

        // 1. Delivery status block
        if (strpos($ctype, 'delivery-status') !== false) {

            if (preg_match('/Final-Recipient:\s*rfc822;\s*(.+)/i', $body, $m)) {
                $failed_recipient = sanitizeInput(trim($m[1]));
            }

            if (preg_match('/Diagnostic-Code:\s*(.+)/i', $body, $m)) {
                $diagnostic_code = sanitizeInput(trim($m[1]));
            }

            if (preg_match('/Status:\s*([0-9\.]+)/i', $body, $m)) {
                $status_code = sanitizeInput(trim($m[1]));
            }
        }

        // 2. Original message headers
        if (strpos($ctype, 'message/rfc822') !== false || strpos($ctype, 'text/rfc822-headers') !== false) {

            if (preg_match('/^Subject:\s*(.+)$/mi', $body, $m)) {
                $original_subject = sanitizeInput(trim($m[1]));
            }
        }
    }

    // 3. Fallback: extract diagnostic from human-readable text/plain
    if (!$diagnostic_code) {
        // Exim puts diagnostics on an indented line
        if (preg_match('/\n\s{2,}(.+)/', $message_body_text, $m)) {
            $diagnostic_code = sanitizeInput(trim($m[1]));
        }
    }

    // Fallbacks
    $failed_recipient = $failed_recipient ?: 'unknown recipient';
    $diagnostic_code  = $diagnostic_code ?: 'unknown diagnostic code';
    $status_code      = $status_code ?: 'unknown status code';
    $original_subject = $original_subject ?: $subject;

    appNotify(
        "Ticket",
        "Email parser NDR: Message to $failed_recipient bounced. Subject: $original_subject Diagnostics: $status_code / $diagnostic_code - check the mailbox's processed folder to see the email",
        "",
        0
    );

    // If the original subject has a ticket, leave a system note there (not a client reply: the sender is a mail server)
    if (preg_match("/\[$config_ticket_prefix(\d+)\]/", $original_subject, $ticket_number_matches)) {
        $ticket_number = intval($ticket_number_matches[1]);
        $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_number = $ticket_number LIMIT 1"));
        if ($t) {
            $note = mysqli_real_escape_string($mysqli, "Email delivery failed.<br>Recipient: $failed_recipient<br>Status: $status_code<br>Diagnostic: $diagnostic_code");
            mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$note', ticket_reply_type = 'System', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = " . intval($t['ticket_id']));
        }
    }

    return "Bounce for $failed_recipient - $status_code / $diagnostic_code";
}

/** ------------------------------------------------------------------
 * Intake limits and poison-message handling (shared by the IMAP and Graph paths)
 * ------------------------------------------------------------------ */

// Attachment size limits, inline-image cap and the optional ClamAV hook, from Admin > Mailboxes > Intake settings.
function mailIntakeLimits(): array {
    global $mysqli;

    $scan = null;
    if (MailSettings::int($mysqli, 'clamav_enabled') === 1) {
        if (ClamScanner::binary() !== null) {
            $scan = static fn (string $content): ?string => ClamScanner::scan($content);
        } else {
            static $warned = false;
            if (!$warned) {
                $warned = true;
                logApp("Cron-Email-Parser", "warning", "Virus scanning is switched on but clamdscan is not installed; attachments are not being scanned.");
            }
        }
    }

    return [
        'max_file_bytes'    => MailSettings::int($mysqli, 'max_attachment_mb') * 1048576,
        'max_message_bytes' => MailSettings::int($mysqli, 'max_message_attachments_mb') * 1048576,
        'inline_max_bytes'  => MailSettings::int($mysqli, 'inline_cid_max_kb') * 1024,
        'scan'              => $scan,
    ];
}

// Normalise + prepare one fetched message, give it a stand-in Message-ID when it has none, and run processInboundMessage().
// Returns whether it was handled. Throws on a genuine failure (the caller counts it toward quarantine).
function intakeHandleNormalized(int $mailbox_id, int $default_client_id, int $parse_unknown, array $norm, string $original_message_file): bool {
    $subject = sanitizeInput($norm['subject']);
    $from_email = sanitizeInput($norm['from_email']);
    $from_name = sanitizeInput($norm['from_name']);
    $date = sanitizeInput($norm['date']);

    $prep = InboundPreparer::prepare($norm, mailIntakeLimits());
    if ($norm['message_id'] === '') {
        $norm['message_id'] = MessageId::synthetic($from_email, $date, $subject, (string) $norm['text'] . (string) $norm['html']);
    }

    return processInboundMessage(
        $mailbox_id,
        $default_client_id,
        $parse_unknown,
        $from_email,
        $from_name,
        $subject,
        $norm['ccs'],
        $date,
        $prep['body'],
        $prep['body_text'],
        $prep['attachments'],
        $prep['raw_parts'],
        $original_message_file,
        ['norm' => $norm, 'prep' => $prep]
    );
}

// Records a failed attempt for a message (exception, or "could not be placed"). Returns true when this attempt tipped
// it into quarantine, so the caller sets it aside exactly once and tells the admins.
function intakeRecordFailure(int $mailbox_id, string $key, ?string $message_id, ?string $from, ?string $subject, string $error): bool {
    global $mysqli;

    $store = new IntakeStore($mysqli);
    $max = max(1, MailSettings::int($mysqli, 'poison_max_attempts'));
    $r = $store->recordFailure($mailbox_id, $key, $message_id, $from, $subject, $error, $max);
    if ($r['quarantined']) {
        logMailEvent($mailbox_id, $from, null, $subject, 'quarantined', "Failed {$r['attempts']} times - set aside. Last error: " . substr($error, 0, 300), null, null);
        MailHealth::alert($mysqli, 'quarantine:' . $mailbox_id . ':' . substr($key, 0, 12), 'Message set aside after repeated failures', "A message from " . ($from ?: 'unknown sender') . " (\"" . substr((string) $subject, 0, 80) . "\") failed {$r['attempts']} times and was set aside: " . substr($error, 0, 200) . ". See Admin > Mailboxes > Quarantined mail.");
    }
    return $r['quarantined'];
}

/** ------------------------------------------------------------------
 * Per-mailbox polling (supports Standard IMAP / Google OAuth / Microsoft OAuth)
 *
 * Connects to a single `mailboxes` row, fetches unseen messages, and routes
 * each one to processInboundMessage(). Any connection-level failure (bad
 * password, unreachable host, expired/revoked token) is raised as an
 * exception so the caller can log it and continue with the next mailbox
 * instead of aborting the whole run. Microsoft mailboxes are read via
 * Microsoft Graph (no raw IMAP protocol involved); Standard IMAP and Google
 * Workspace mailboxes still use Webklex/IMAP.
 * ------------------------------------------------------------------ */
function pollMailbox(array $mailbox): array {
    $mailbox_type = $mailbox['mailbox_type'] ?: 'standard_imap';

    if ($mailbox_type === 'microsoft_oauth') {
        return pollMailboxMicrosoftGraph($mailbox);
    }

    return pollMailboxImap($mailbox);
}

function pollMailboxImap(array $mailbox): array {
    global $mysqli;

    $mailbox_id = intval($mailbox['mailbox_id']);
    $mailbox_type = $mailbox['mailbox_type'] ?: 'standard_imap';
    $mailbox_parse_unknown_senders = intval($mailbox['mailbox_parse_unknown_senders']);
    $mailbox_default_client_id = intval($mailbox['mailbox_default_client_id'] ?? 0);

    $validate_cert = true;

    // Defaults from the mailbox row (standard IMAP)
    $host = $mailbox['mailbox_imap_host'];
    $port = (int)$mailbox['mailbox_imap_port'];
    $encr = !empty($mailbox['mailbox_imap_encryption']) ? $mailbox['mailbox_imap_encryption'] : 'notls'; // 'ssl'|'tls'|'notls'
    $user = $mailbox['mailbox_imap_username'];
    $pass = decryptSetting($mailbox['mailbox_imap_password_enc'] ?? '');
    $auth = null; // 'oauth' for OAuth providers

    if ($mailbox_type === 'google_oauth') {
        $host = 'imap.gmail.com';
        $port = 993;
        $encr = 'ssl';
        $auth = 'oauth';
        $pass = getGoogleAccessToken($user, $mailbox);
        if (empty($pass)) {
            throw new \RuntimeException("Google OAuth: no usable access token (check refresh token/client credentials).");
        }
    } else {
        // standard_imap (username/password)
        if (empty($host) || empty($port) || empty($user)) {
            throw new \RuntimeException("Standard IMAP: missing host/port/username.");
        }
    }

    $cm = new ClientManager();

    $client = $cm->make(array_filter([
        'host'           => $host,
        'port'           => $port,
        'encryption'     => $encr,            // 'ssl' | 'tls' | null
        'validate_cert'  => (bool)$validate_cert,
        'username'       => $user,            // full mailbox address (OAuth uses user as principal)
        'password'       => $pass,            // access token when $auth === 'oauth'
        'authentication' => $auth,            // 'oauth' or null
        'protocol'       => 'imap',
    ]));

    try {
        $client->connect();
    } catch (\Throwable $e) {
        throw new \RuntimeException("Error connecting to IMAP server: " . $e->getMessage(), 0, $e);
    }

    $inbox = $client->getFolderByPath('INBOX');

    // Processed mail goes to a folder still named "ITFlow" (the pre-RivetIT name) on purpose: every
    // monitored mailbox already has it, and a new name would silently start a second folder.
    $targetFolderPath = 'ITFlow';
    try {
        $targetFolder = $client->getFolderByPath($targetFolderPath);
    } catch (\Throwable $e) {
        $client->createFolder($targetFolderPath);
        $targetFolder = $client->getFolderByPath($targetFolderPath);
    }

    // Messages that fail repeatedly are moved here (and listed under Admin > Mailboxes > Quarantined mail) instead of
    // being fetched again on every run forever.
    $quarantinePath = 'ITFlow-Quarantine';
    $quarantineFolderReady = false;

    $store = new IntakeStore($mysqli);

    // Fetch unseen messages
    $messages = $inbox->messages()->leaveUnread()->unseen()->get();

    // Counters
    $processed_count = 0;
    $unprocessed_count = 0;

    // Process messages
    foreach ($messages as $message) {
        $email_processed = false;
        $original_message_file = null;
        $key = null;
        $norm = null;
        $counted = false; // a thrown failure is counted once, in the catch below

        try {
            // Identity of this message for the attempt counter: its Message-ID, else folder UID.
            $hdr = RawHeaders::parse((string) $message->getHeader()->raw);
            $hdr_mid = MessageId::normalize(RawHeaders::first($hdr, 'message-id'));
            $uid = method_exists($message, 'getUid') ? (string) $message->getUid() : '';
            $key = IntakeStore::messageKey($mailbox_id, $hdr_mid, 'uid:' . $uid . '|' . (string) $message->getSubject());

            if ($store->isQuarantined($mailbox_id, $key)) {
                // Already set aside (e.g. the move failed last time): keep it out of the unseen set.
                try { $message->setFlag('Seen'); } catch (\Throwable $e) { /* ignore */ }
                continue;
            }

            // Save original message as .eml (getRawMessage() doesn't seem to work properly)
            mkdirMissing('../uploads/tmp/');
            $original_message_file = "processed-eml-" . randomString(200) . ".eml";
            $raw_message = (string)$message->getHeader()->raw . "\r\n\r\n" . ($message->getRawBody() ?? $message->getHTMLBody() ?? $message->getTextBody());
            file_put_contents("../uploads/tmp/{$original_message_file}", $raw_message);

            $norm = MessageNormalizer::fromWebklex($message);
            if ($norm['message_id'] === '' && $hdr_mid !== '') {
                $norm['message_id'] = $hdr_mid;
            }
            if (empty($norm['html']) && empty($norm['text'])) {
                // Final fallback, as before: the raw body
                $norm['text'] = (string) $message->getRawBody();
            }

            $email_processed = intakeHandleNormalized($mailbox_id, $mailbox_default_client_id, $mailbox_parse_unknown_senders, $norm, $original_message_file);
        } catch (\Throwable $e) {
            $email_processed = false;
            $failure = get_class($e) . ': ' . $e->getMessage();
            logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: message processing failed: " . substr($failure, 0, 500));
            if ($key !== null) {
                $counted = true;
                if (intakeRecordFailure($mailbox_id, $key, $norm['message_id'] ?? null, $norm['from_email'] ?? null, $norm['subject'] ?? null, $failure)) {
                    // Set it aside: move to the quarantine folder, falling back to marking it read + flagged in place.
                    try {
                        if (!$quarantineFolderReady) {
                            try { $client->getFolderByPath($quarantinePath); } catch (\Throwable $e2) { $client->createFolder($quarantinePath); }
                            $quarantineFolderReady = true;
                        }
                        $message->setFlag('Seen');
                        $message->move($quarantinePath);
                    } catch (\Throwable $e3) {
                        logApp("Cron-Email-Parser", "warning", "Could not move quarantined message to [$quarantinePath]: " . $e3->getMessage());
                        try { $message->setFlag('Flagged'); $message->setFlag('Seen'); } catch (\Throwable $e4) { /* ignore */ }
                    }
                    $unprocessed_count++;
                    if ($original_message_file !== null) { @unlink("../uploads/tmp/{$original_message_file}"); }
                    continue;
                }
            }
        }

        // Flag/move based on processing result
        if ($email_processed) {
            if ($key !== null) { $store->clear($mailbox_id, $key); }
            $processed_count++; // increment first so a move failure doesn't hide the success
            try {
                $message->setFlag('Seen');
                // Move using the Folder object (top-level "ITFlow")
                $message->move($targetFolderPath);
            } catch (\Throwable $e) {
                $subj = (string)$message->getSubject();
                $uid  = method_exists($message, 'getUid') ? $message->getUid() : 'n/a';
                $path = (is_object($targetFolder) && property_exists($targetFolder, 'path')) ? (string)$targetFolder->path : $targetFolderPath;
                logApp(
                    "Cron-Email-Parser",
                    "warning",
                    "Move failed (subject=\"$subj\", uid=$uid) to [$path]: ".$e->getMessage()
                );
            }
        } else {
            $unprocessed_count++;
            // Could not be placed (or threw): count the attempt. After the configured number of attempts the message is
            // marked read (flag kept) so it stops being fetched and logged on every run, and it is listed for admins.
            $tipped = false;
            if ($key !== null && $norm !== null && !$counted) {
                $tipped = intakeRecordFailure($mailbox_id, $key, $norm['message_id'] ?? null, $norm['from_email'] ?? null, $norm['subject'] ?? null, 'Not handled: no ticket, contact or domain matched, or processing failed');
            }
            try {
                $message->setFlag('Flagged');
                if ($tipped) {
                    $message->setFlag('Seen');
                } else {
                    $message->unsetFlag('Seen');
                }
            } catch (\Throwable $e) {
                logApp("Cron-Email-Parser", "warning", "Flag update failed: ".$e->getMessage());
            }
        }

        // Cleanup temp .eml if still present (e.g., reply path)
        if ($original_message_file !== null) {
            $tmp_path = "../uploads/tmp/{$original_message_file}";
            if (file_exists($tmp_path)) { @unlink($tmp_path); }
        }
    }

    // Expunge & disconnect
    try {
        $client->expunge();
    } catch (\Throwable $e) {
        // ignore
    }
    $client->disconnect();

    return ['processed' => $processed_count, 'unprocessed' => $unprocessed_count];
}

/** ------------------------------------------------------------------
 * Microsoft 365 mailbox polling via Microsoft Graph (no raw IMAP).
 *
 * Microsoft's "Office 365 Exchange Online" delegated API (IMAP.AccessAsUser.All
 * / SMTP.Send) is increasingly unavailable on new app registrations, so
 * Microsoft-connected mailboxes read mail through Graph's /messages API
 * instead - see getMicrosoftAccessToken() above for the token, and
 * admin/post/mailbox.php for the Mail.ReadWrite consent scope.
 * ------------------------------------------------------------------ */
function pollMailboxMicrosoftGraph(array $mailbox): array {
    $mailbox_id = intval($mailbox['mailbox_id']);
    $mailbox_parse_unknown_senders = intval($mailbox['mailbox_parse_unknown_senders']);
    $mailbox_default_client_id = intval($mailbox['mailbox_default_client_id'] ?? 0);
    $user = $mailbox['mailbox_imap_username'] ?: $mailbox['mailbox_email'];

    $access_token = getMicrosoftAccessToken($user, $mailbox);
    if (empty($access_token)) {
        throw new \RuntimeException("Microsoft Graph: no usable access token (check refresh token/client credentials/tenant).");
    }

    $graph_base = "https://graph.microsoft.com/v1.0/users/" . rawurlencode($user);
    $folder_id = graphFindOrCreateItflowFolder($graph_base, $access_token);

    $processed_count = 0;
    $unprocessed_count = 0;
    $store = new IntakeStore($GLOBALS['mysqli']);
    $quarantine_id = null;

    $select = 'id,internetMessageId,subject,from,ccRecipients,receivedDateTime,hasAttachments,body';
    $url = $graph_base . "/mailFolders/inbox/messages?" . http_build_query([
        '$filter' => 'isRead eq false',
        '$top'    => 25,
        '$select' => $select,
    ]);

    $page_guard = 0;
    while ($url && $page_guard < 40) {
        $page_guard++;

        $resp = microsoftGraphRequest('GET', $url, $access_token);
        if (!$resp['ok']) {
            throw new \RuntimeException("Microsoft Graph: failed to list messages (HTTP {$resp['code']}): " . ($resp['json']['error']['message'] ?? $resp['error'] ?? 'unknown error'));
        }

        foreach (($resp['json']['value'] ?? []) as $msg) {
            $graph_id = (string) ($msg['id'] ?? '');
            $key = IntakeStore::messageKey($mailbox_id, $msg['internetMessageId'] ?? null, 'graph:' . $graph_id);
            try {
                if ($store->isQuarantined($mailbox_id, $key)) {
                    microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($graph_id), $access_token, ['isRead' => true]);
                    continue;
                }
                $handled = graphProcessOneMessage($graph_base, $access_token, $msg, $folder_id, $mailbox_id, $mailbox_default_client_id, $mailbox_parse_unknown_senders, $key);
                if ($handled) {
                    $store->clear($mailbox_id, $key);
                    $processed_count++;
                } else {
                    $unprocessed_count++;
                }
            } catch (\Throwable $e) {
                $unprocessed_count++;
                $failure = get_class($e) . ': ' . $e->getMessage();
                logApp("Cron-Email-Parser", "warning", "Graph message " . ($graph_id ?: '?') . " failed: " . substr($failure, 0, 500));
                $from_addr = $msg['from']['emailAddress']['address'] ?? null;
                if (intakeRecordFailure($mailbox_id, $key, $msg['internetMessageId'] ?? null, $from_addr, $msg['subject'] ?? null, $failure)) {
                    try {
                        $quarantine_id = $quarantine_id ?? graphFindOrCreateItflowFolder($graph_base, $access_token, 'ITFlow-Quarantine');
                        microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($graph_id), $access_token, ['isRead' => true]);
                        microsoftGraphRequest('POST', $graph_base . "/messages/" . rawurlencode($graph_id) . "/move", $access_token, ['destinationId' => $quarantine_id]);
                    } catch (\Throwable $e2) {
                        logApp("Cron-Email-Parser", "warning", "Could not move quarantined Graph message: " . $e2->getMessage());
                    }
                }
            }
        }

        $url = $resp['json']['@odata.nextLink'] ?? null;
    }

    return ['processed' => $processed_count, 'unprocessed' => $unprocessed_count];
}

// Finds (or creates) a top-level mail folder by name - "ITFlow" (processed mail) or "ITFlow-Quarantine" - matching the
// sibling-of-Inbox folders Webklex creates for Standard IMAP / Google mailboxes. Returns the folder's Graph id.
// "ITFlow" keeps its pre-RivetIT name on purpose (see $targetFolderPath in the IMAP path).
function graphFindOrCreateItflowFolder(string $graph_base, string $access_token, string $name = 'ITFlow'): string {
    $list_url = $graph_base . "/mailFolders?" . http_build_query([
        '$filter' => "displayName eq '" . str_replace("'", "''", $name) . "'",
        '$select' => 'id',
    ]);

    $resp = microsoftGraphRequest('GET', $list_url, $access_token);
    if ($resp['ok'] && !empty($resp['json']['value'][0]['id'])) {
        return $resp['json']['value'][0]['id'];
    }

    $create = microsoftGraphRequest('POST', $graph_base . "/mailFolders", $access_token, ['displayName' => $name]);
    if ($create['ok'] && !empty($create['json']['id'])) {
        return $create['json']['id'];
    }

    throw new \RuntimeException("Microsoft Graph: could not find or create the $name mail folder.");
}

// Fetches, normalizes, classifies, and marks read/moves (or flags) a single Graph message.
// Returns whether it was handled (mirrors the IMAP path's $email_processed).
function graphProcessOneMessage(string $graph_base, string $access_token, array $msg, string $folder_id, int $mailbox_id, int $mailbox_default_client_id, int $mailbox_parse_unknown_senders, string $key = ''): bool {
    global $mysqli;

    $message_id = $msg['id'];

    // Raw .eml (used as the "Original-parsed-email.eml" ticket attachment, same as IMAP path). Its header block also carries
    // Message-ID / In-Reply-To / Auto-Submitted, which the Graph JSON does not expose reliably.
    mkdirMissing('../uploads/tmp/');
    $original_message_file = "processed-eml-" . randomString(200) . ".eml";
    $eml = graphFetchRaw($graph_base . "/messages/" . rawurlencode($message_id) . '/$value', $access_token);
    if ($eml === false) {
        throw new \RuntimeException("Microsoft Graph: could not fetch raw .eml for message $message_id");
    }
    file_put_contents("../uploads/tmp/{$original_message_file}", $eml);

    $graph_attachments = [];
    if (!empty($msg['hasAttachments'])) {
        $att_resp = microsoftGraphRequest('GET', $graph_base . "/messages/" . rawurlencode($message_id) . "/attachments", $access_token);
        if ($att_resp['ok']) {
            $graph_attachments = $att_resp['json']['value'] ?? [];
        }
    }

    $norm = MessageNormalizer::fromGraph($msg, $eml, $graph_attachments);

    try {
        $email_processed = intakeHandleNormalized($mailbox_id, $mailbox_default_client_id, $mailbox_parse_unknown_senders, $norm, $original_message_file);
    } finally {
        // Cleanup temp .eml if still present (addTicket() renames/moves it away on success; replies don't)
        $tmp_path = "../uploads/tmp/{$original_message_file}";
        if (file_exists($tmp_path)) { @unlink($tmp_path); }
    }

    if ($email_processed) {
        microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($message_id), $access_token, ['isRead' => true]);
        microsoftGraphRequest('POST', $graph_base . "/messages/" . rawurlencode($message_id) . "/move", $access_token, ['destinationId' => $folder_id]);
    } else {
        // Could not be placed: count the attempt; after the configured number it is marked read (flag kept) and listed for admins.
        $tipped = $key !== '' && intakeRecordFailure($mailbox_id, $key, $norm['message_id'], $norm['from_email'], $norm['subject'], 'Not handled: no ticket, contact or domain matched, or processing failed');
        $patch = ['flag' => ['flagStatus' => 'flagged']];
        if ($tipped) {
            $patch['isRead'] = true;
        }
        microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($message_id), $access_token, $patch);
    }

    return $email_processed;
}

// Fetches a raw (non-JSON) Graph response body, e.g. GET /messages/{id}/$value for the raw .eml.
function graphFetchRaw(string $url, string $access_token) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token"],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return false;
    }

    return $raw;
}

/** ------------------------------------------------------------------
 * Driver: poll every active mailbox independently.
 * A single run-wide lock (acquired above) still covers the whole multi-
 * mailbox run - there is intentionally no per-mailbox lock. One mailbox's
 * connection failure is logged and does not stop the others from polling.
 * ------------------------------------------------------------------ */
$mailboxes = [];
$mailboxes_sql = mysqli_query($mysqli, "SELECT * FROM mailboxes WHERE mailbox_active = 1 AND mailbox_archived_at IS NULL ORDER BY mailbox_order, mailbox_id");
if ($mailboxes_sql) {
    while ($mailbox_row = mysqli_fetch_assoc($mailboxes_sql)) {
        $mailboxes[] = $mailbox_row;
    }
}

if (empty($mailboxes)) {
    // No active mailboxes configured: exit cleanly
    // (matches legacy behavior when config_imap_provider was empty)
    logApp("Cron-Email-Parser", "info", "IMAP polling skipped: no active mailboxes configured.");
    @unlink($lock_file_path);
    exit(0);
}

$total_processed = 0;
$total_unprocessed = 0;

foreach ($mailboxes as $mailbox) {
    $mailbox_id = intval($mailbox['mailbox_id']);
    $mailbox_label = $mailbox['mailbox_email'] ?: $mailbox['mailbox_name'];

    try {
        $result = pollMailbox($mailbox);
        $total_processed += $result['processed'] ?? 0;
        $total_unprocessed += $result['unprocessed'] ?? 0;

        // Health shown on Admin > Mailboxes: last polled / last success / consecutive failures.
        MailHealth::recordPollSuccess($mysqli, $mailbox_id);
    } catch (\Throwable $e) {
        $err_message = "Mailbox #$mailbox_id ($mailbox_label) failed: " . $e->getMessage();
        error_log("Cron-Email-Parser: " . $err_message);
        logApp("Cron-Email-Parser", "error", $err_message);
        MailHealth::recordPollFailure($mysqli, $mailbox_id, $e->getMessage());
    }
}

// Standing mail checks (mailbox unreachable N times, poller silent, exhausted outbound retries). Never allowed to break polling.
try {
    MailHealth::runChecks($mysqli, true);
} catch (\Throwable $e) {
    logApp("Cron-Email-Parser", "warning", "Mail health check failed: " . $e->getMessage());
}

// Execution timing (optional)
$script_end_time = microtime(true);
$execution_time = $script_end_time - $script_start_time;
$execution_time_formatted = number_format($execution_time, 2);

$processed_info = "Processed: $total_processed email(s), Unprocessed: $total_unprocessed email(s)";
// logAction("Cron-Email-Parser", "Execution", "Cron Email Parser executed in $execution_time_formatted seconds. $processed_info");

// Remove the lock file
unlink($lock_file_path);

// DEBUG
echo "\nLock File Path: $lock_file_path\n";
if (file_exists($lock_file_path)) {
    echo "\nLock is present\n\n";
}
echo "Processed Emails: $total_processed\n";
echo "Unprocessed Emails: $total_unprocessed\n";
