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

// Get ITFlow config & helper functions
require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";

// Get settings for the "default" company
require_once "../includes/load_global_settings.php";

// Multi-mailbox support: Webklex\PHPIMAP is used per-mailbox inside pollMailbox().
use Webklex\PHPIMAP\ClientManager;

$config_ticket_prefix = sanitizeInput($config_ticket_prefix);
$config_ticket_from_name = sanitizeInput($config_ticket_from_name);
// NOTE: unknown-sender parsing is now a per-mailbox setting (mailbox_parse_unknown_senders),
// not a global one - see pollMailbox() below.

// Check setting enabled
if ($config_ticket_email_parse == 0) {
    logApp("Cron-Email-Parser", "error", "Cron Email Parser unable to run - not enabled in admin settings.");
    exit("Email Parser: Feature is not enabled - check Settings > Ticketing > Email-to-ticket parsing. See https://docs.itflow.org/ticket_email_parse  -- Quitting..");
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

    if (!$resp['ok']) return null;

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) return null;

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
        return null;
    }

    $json = json_decode($resp['body'], true);
    if (!is_array($json) || empty($json['access_token'])) {
        logApp("Cron-Email-Parser", "error", "Mailbox #{$mailbox_id}: Microsoft OAuth token refresh returned no access_token despite HTTP {$resp['code']}.");
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
 * came from Webklex/IMAP or Microsoft Graph), decides whether it's a reply
 * to an existing ticket, a new ticket for a known contact/domain, or an
 * unknown-sender/NDR case - and calls addTicket()/addReply() accordingly.
 * Returns whether the message was handled (true) or should stay unread/
 * flagged for manual review (false).
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
    string $original_message_file
): bool {
    global $mysqli, $config_ticket_prefix;

    $email_processed = false;

    // Tracked across whichever branch below fires, then recorded once via logMailEvent()
    // at the end - see Admin > Maintenance > Email Log.
    $mail_log_outcome = 'ignored';
    $mail_log_detail = null;
    $mail_log_ticket_id = null;
    $mail_log_mail_request_id = null;

    $from_domain = explode("@", $from_email);
    $from_domain = sanitizeInput(end($from_domain));

    // 1. Reply to existing ticket with the number in subject
    if (preg_match("/\[$config_ticket_prefix(\d+)\]/", $subject, $ticket_number_matches)) {
        $ticket_number = intval($ticket_number_matches[1]);
        $email_processed = addReply($from_email, $date, $subject, $ticket_number, $message_body, $attachments, $mailbox_id, $from_name, $ccs, $original_message_file);
        if ($email_processed) {
            $mail_log_outcome = 'reply_added';
            $mail_log_detail = "Matched ticket #$ticket_number by subject tag";
            $tid_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_number = " . intval($ticket_number) . " LIMIT 1"));
            $mail_log_ticket_id = $tid_row ? intval($tid_row['ticket_id']) : null;
        }
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
                    $email_processed = addReply($from_email, $date, $subject, $ticket_number, $message_body, $attachments, $mailbox_id, $from_name, $ccs, $original_message_file);
                    if ($email_processed) {
                        $mail_log_outcome = 'reply_added';
                        $mail_log_detail = "Matched ticket #$ticket_number by fuzzy subject match ({$percent}%)";
                        $mail_log_ticket_id = intval($rowt['ticket_id']);
                    }
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

            $email_processed = addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message_body, $attachments, $original_message_file, $ccs, $mailbox_id);
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

            $email_processed = addTicket($contact_id, $contact_name, $contact_email, $client_id, $date, $subject, $message_body, $attachments, $original_message_file, $ccs, $mailbox_id);
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
            $new_mail_request_id = createMailRequestFromInbound($mailbox_id, $from_email, $from_name, $subject, $ccs, $date, $message_body, $attachments, $original_message_file);
            $email_processed = (bool) $new_mail_request_id;
            if ($email_processed) {
                $mail_log_outcome = 'mail_request';
                $mail_log_detail = 'Unknown sender queued for review';
                $mail_log_mail_request_id = $new_mail_request_id;
            }

        } else {

            // Probably an NDR message without a ticket ref in the subject

            $failed_recipient  = null;
            $diagnostic_code   = null;
            $status_code       = null;
            $original_subject  = null;
            $original_to       = null;

            // DSN info shows up as regular attachment/part entries, not the visible body
            foreach ($raw_parts as $attachment) {

                $ctype = strtolower($attachment['content_type'] ?? '');
                $body  = $attachment['content'] ?? '';

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
                if (strpos($ctype, 'message/rfc822') !== false) {

                    if (preg_match('/^To:\s*(.+)$/mi', $body, $m)) {
                        $original_to = sanitizeInput(trim($m[1]));
                    }

                    if (preg_match('/^Subject:\s*(.+)$/mi', $body, $m)) {
                        $original_subject = sanitizeInput(trim($m[1]));
                    }
                }
            }

            // 3. Fallback: extract diagnostic from human-readable text/plain
            if (!$diagnostic_code) {
                $text = $message_body_text ?? '';

                // Exim puts diagnostics on an indented line
                if (preg_match('/\n\s{2,}(.+)/', $text, $m)) {
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
                "Email parser NDR: Message to $failed_recipient bounced. Subject: $original_subject Diagnostics: $status_code / $diagnostic_code - check ITFlow folder manually to see email",
                "",
                0
            );

            // If the original subject has a ticket, add the NDR there too
            if (preg_match("/\[$config_ticket_prefix(\d+)\]/", $original_subject, $ticket_number_matches)) {

                $ticket_number = intval($ticket_number_matches[1]);

                // Craft a clean bounce message
                $reply_body = "Email delivery failed.\n".
                    "Recipient: $failed_recipient\n".
                    "Status: $status_code\n".
                    "Diagnostic: $diagnostic_code\n";

                // No attachments
                addReply(
                    $from_email,
                    $date,
                    $original_subject,
                    $ticket_number,
                    $reply_body,
                    [],
                    $mailbox_id,
                    $from_name,
                    $ccs,
                    $original_message_file
                );

            }

            $email_processed = true;
            $mail_log_outcome = 'ndr';
            $mail_log_detail = "Bounce for $failed_recipient - $status_code / $diagnostic_code";
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

    $targetFolderPath = 'ITFlow';
    try {
        $targetFolder = $client->getFolderByPath($targetFolderPath);
    } catch (\Throwable $e) {
        $client->createFolder($targetFolderPath);
        $targetFolder = $client->getFolderByPath($targetFolderPath);
    }

    // Fetch unseen messages
    $messages = $inbox->messages()->leaveUnread()->unseen()->get();

    // Counters
    $processed_count = 0;
    $unprocessed_count = 0;

    // Process messages
    foreach ($messages as $message) {
        $email_processed = false;

        // Save original message as .eml (getRawMessage() doesn't seem to work properly)
        mkdirMissing('../uploads/tmp/');
        $original_message_file = "processed-eml-" . randomString(200) . ".eml";
        $raw_message = (string)$message->getHeader()->raw . "\r\n\r\n" . ($message->getRawBody() ?? $message->getHTMLBody() ?? $message->getTextBody());
        file_put_contents("../uploads/tmp/{$original_message_file}", $raw_message);

        // From
        $from_col    = $message->getFrom();
        $from_first  = ($from_col && $from_col->count()) ? $from_col->first() : null;
        $from_email = sanitizeInput($from_first->mail ?? 'itflow-guest@example.com');
        $from_name  = sanitizeInput($from_first->personal ?? 'Unknown');

        // Subject
        $subject = sanitizeInput((string)$message->getSubject() ?: 'No Subject');

        // CC
        $ccs = array();
        $cc_attr = $message->header->cc;
        $cc_list = $cc_attr->toArray();
        foreach ($cc_list as $cc_addr) {
            if ($cc_addr instanceof \Webklex\PHPIMAP\Address) {
                $ccs[] = $cc_addr->mail;
            }
        }

        // Date (string)
        $dateAttr = $message->getDate();                  // Attribute
        $dateRaw  = $dateAttr ? (string)$dateAttr : '';   // e.g. "Tue, 10 Sep 2025 13:22:05 +0000"
        $ts       = $dateRaw ? strtotime($dateRaw) : false;
        $date     = sanitizeInput($ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'));

        // Body (prefer HTML)
        $message_body_html = $message->getHTMLBody();
        $message_body_text = $message->getTextBody();
        $message_body_raw  = $message->getRawBody();

        if (!empty($message_body_html)) {
            $message_body = $message_body_html;
        } elseif (!empty($message_body_text)) {
            $message_body = nl2br(htmlspecialchars($message_body_text));
        } else {
            // Final fallback
            $message_body = nl2br(htmlspecialchars($message_body_raw));
        }

        // Handle attachments (inline vs regular), and keep every part around
        // (including DSN sub-parts) for NDR sniffing in processInboundMessage().
        $attachments = [];
        $raw_parts = [];
        foreach ($message->getAttachments() as $att) {
            $attrs   = $att->getAttributes(); // v6.2: canonical source
            $dispo   = strtolower((string)($attrs['disposition'] ?? ''));
            $cid     = $attrs['id'] ?? null;            // Content-ID
            $content = $attrs['content'] ?? null;       // binary
            $mime    = $att->getMimeType();
            $name    = $att->getName() ?: 'attachment';

            $raw_parts[] = ['name' => $name, 'content' => $content, 'content_type' => $mime];

            $is_inline = false;
            if ($dispo === 'inline' && $cid && $content !== null) {
                $cid_trim  = trim($cid, '<>');
                $dataUri   = "data:$mime;base64,".base64_encode($content);
                $message_body = str_replace(["cid:$cid_trim", "cid:$cid"], $dataUri, $message_body);
                $is_inline = true;
            }

            if (!$is_inline && $content !== null) {
                $attachments[] = ['name' => $name, 'content' => $content];
            }
        }

        $email_processed = processInboundMessage(
            $mailbox_id,
            $mailbox_default_client_id,
            $mailbox_parse_unknown_senders,
            $from_email,
            $from_name,
            $subject,
            $ccs,
            $date,
            $message_body,
            (string)($message_body_text ?? ''),
            $attachments,
            $raw_parts,
            $original_message_file
        );

        // Flag/move based on processing result
        if ($email_processed) {
            $processed_count++; // increment first so a move failure doesn't hide the success
            try {
                $message->setFlag('Seen');
                // Move using the Folder object (top-level "ITFlow")
                $message->move($targetFolderPath);
                // optional: logApp("Cron-Email-Parser", "info", "Moved message to ITFlow");
            } catch (\Throwable $e) {
                // >>> Put the extra logging RIGHT HERE
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
            try {
                $message->setFlag('Flagged');
                $message->unsetFlag('Seen');
            } catch (\Throwable $e) {
                logApp("Cron-Email-Parser", "warning", "Flag update failed: ".$e->getMessage());
            }
        }

        // Cleanup temp .eml if still present (e.g., reply path)
        if (isset($original_message_file)) {
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

    $select = 'id,subject,from,ccRecipients,receivedDateTime,hasAttachments,body';
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
            try {
                $handled = graphProcessOneMessage($graph_base, $access_token, $msg, $folder_id, $mailbox_id, $mailbox_default_client_id, $mailbox_parse_unknown_senders);
                if ($handled) {
                    $processed_count++;
                } else {
                    $unprocessed_count++;
                }
            } catch (\Throwable $e) {
                $unprocessed_count++;
                logApp("Cron-Email-Parser", "warning", "Graph message " . ($msg['id'] ?? '?') . " failed: " . $e->getMessage());
            }
        }

        $url = $resp['json']['@odata.nextLink'] ?? null;
    }

    return ['processed' => $processed_count, 'unprocessed' => $unprocessed_count];
}

// Finds (or creates) the top-level "ITFlow" mail folder, matching the sibling-of-Inbox
// folder Webklex creates for Standard IMAP / Google mailboxes. Returns the folder's Graph id.
function graphFindOrCreateItflowFolder(string $graph_base, string $access_token): string {
    $list_url = $graph_base . "/mailFolders?" . http_build_query([
        '$filter' => "displayName eq 'ITFlow'",
        '$select' => 'id',
    ]);

    $resp = microsoftGraphRequest('GET', $list_url, $access_token);
    if ($resp['ok'] && !empty($resp['json']['value'][0]['id'])) {
        return $resp['json']['value'][0]['id'];
    }

    $create = microsoftGraphRequest('POST', $graph_base . "/mailFolders", $access_token, ['displayName' => 'ITFlow']);
    if ($create['ok'] && !empty($create['json']['id'])) {
        return $create['json']['id'];
    }

    throw new \RuntimeException("Microsoft Graph: could not find or create the ITFlow mail folder.");
}

// Fetches, normalizes, classifies, and marks read/moves (or flags) a single Graph message.
// Returns whether it was handled (mirrors the IMAP path's $email_processed).
function graphProcessOneMessage(string $graph_base, string $access_token, array $msg, string $folder_id, int $mailbox_id, int $mailbox_default_client_id, int $mailbox_parse_unknown_senders): bool {
    $message_id = $msg['id'];

    $from_email = sanitizeInput($msg['from']['emailAddress']['address'] ?? 'itflow-guest@example.com');
    $from_name  = sanitizeInput($msg['from']['emailAddress']['name'] ?? 'Unknown');

    $subject = sanitizeInput(!empty($msg['subject']) ? $msg['subject'] : 'No Subject');

    $ccs = [];
    foreach (($msg['ccRecipients'] ?? []) as $cc) {
        if (!empty($cc['emailAddress']['address'])) {
            $ccs[] = $cc['emailAddress']['address'];
        }
    }

    $ts = !empty($msg['receivedDateTime']) ? strtotime($msg['receivedDateTime']) : false;
    $date = sanitizeInput($ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'));

    // Graph returns HTML body content by default (contentType 'html' unless the message is plain text)
    $message_body = $msg['body']['content'] ?? '';
    if (($msg['body']['contentType'] ?? 'html') !== 'html') {
        $message_body = nl2br(htmlspecialchars($message_body));
    }
    $message_body_text = trim(html_entity_decode(strip_tags($msg['body']['content'] ?? ''), ENT_QUOTES));

    // Raw .eml (used as the "Original-parsed-email.eml" ticket attachment, same as IMAP path)
    mkdirMissing('../uploads/tmp/');
    $original_message_file = "processed-eml-" . randomString(200) . ".eml";
    $eml = graphFetchRaw($graph_base . "/messages/" . rawurlencode($message_id) . '/$value', $access_token);
    if ($eml === false) {
        throw new \RuntimeException("Microsoft Graph: could not fetch raw .eml for message $message_id");
    }
    file_put_contents("../uploads/tmp/{$original_message_file}", $eml);

    // Attachments (inline vs regular), same split as the IMAP path
    $attachments = [];
    $raw_parts = [];
    if (!empty($msg['hasAttachments'])) {
        $att_resp = microsoftGraphRequest('GET', $graph_base . "/messages/" . rawurlencode($message_id) . "/attachments", $access_token);
        if ($att_resp['ok']) {
            foreach (($att_resp['json']['value'] ?? []) as $att) {
                // Skip item/reference attachments (forwarded messages, OneDrive links) - no raw bytes to read.
                if (($att['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment' || !isset($att['contentBytes'])) {
                    continue;
                }

                $name = $att['name'] ?? 'attachment';
                $content_type = $att['contentType'] ?? 'application/octet-stream';
                $content = base64_decode($att['contentBytes']);

                $raw_parts[] = ['name' => $name, 'content' => $content, 'content_type' => $content_type];

                $is_inline = !empty($att['isInline']);
                if ($is_inline && !empty($att['contentId'])) {
                    $cid = trim($att['contentId'], '<>');
                    $dataUri = "data:$content_type;base64," . $att['contentBytes'];
                    $message_body = str_replace("cid:$cid", $dataUri, $message_body);
                } elseif (!$is_inline) {
                    $attachments[] = ['name' => $name, 'content' => $content];
                }
            }
        }
    }

    $email_processed = processInboundMessage(
        $mailbox_id,
        $mailbox_default_client_id,
        $mailbox_parse_unknown_senders,
        $from_email,
        $from_name,
        $subject,
        $ccs,
        $date,
        $message_body,
        $message_body_text,
        $attachments,
        $raw_parts,
        $original_message_file
    );

    if ($email_processed) {
        microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($message_id), $access_token, ['isRead' => true]);
        microsoftGraphRequest('POST', $graph_base . "/messages/" . rawurlencode($message_id) . "/move", $access_token, ['destinationId' => $folder_id]);
    } else {
        microsoftGraphRequest('PATCH', $graph_base . "/messages/" . rawurlencode($message_id), $access_token, ['flag' => ['flagStatus' => 'flagged']]);
    }

    // Cleanup temp .eml if still present (addTicket() renames/moves it away on success; replies don't)
    $tmp_path = "../uploads/tmp/{$original_message_file}";
    if (file_exists($tmp_path)) { @unlink($tmp_path); }

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

        mysqli_query($mysqli, "UPDATE mailboxes SET mailbox_last_polled_at = NOW() WHERE mailbox_id = $mailbox_id");
    } catch (\Throwable $e) {
        $err_message = "Mailbox #$mailbox_id ($mailbox_label) failed: " . $e->getMessage();
        error_log("Cron-Email-Parser: " . $err_message);
        logApp("Cron-Email-Parser", "error", $err_message);
    }
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
