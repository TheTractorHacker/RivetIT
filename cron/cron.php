<?php

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";

// Set Timezone
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('cron', 1800);

require_once "../includes/backup_cron_settings.php";

$sql_companies = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");

$row = mysqli_fetch_assoc($sql_companies);

// $row gets reassigned by many query loops below -- keep a stable copy of the
// companies+settings row for config values needed later (auto-backup, RMM).
$settings_row = $row;
rivetit_load_backup_cron_settings($settings_row);

// Company Details
$company_name = sanitizeInput($row['company_name']);
$company_phone = sanitizeInput(formatPhoneNumber($row['company_phone'], $row['company_phone_country_code']));
$company_email = sanitizeInput($row['company_email']);
$company_website = sanitizeInput($row['company_website']);
$company_city = sanitizeInput($row['company_city']);
$company_state = sanitizeInput($row['company_state']);
$company_country = sanitizeInput($row['company_country']);
$company_locale = sanitizeInput($row['company_locale']);
$company_currency = sanitizeInput($row['company_currency']);

// Company Settings
$config_enable_cron = intval($row['config_enable_cron']);

// Mail Settings
$config_smtp_provider = sanitizeInput($row['config_smtp_provider']);
$config_smtp_host = $row['config_smtp_host'];
$config_smtp_username = $row['config_smtp_username'];
$config_smtp_password = decryptSetting($row['config_smtp_password'] ?? '');
$config_smtp_port = intval($row['config_smtp_port']);
$config_smtp_encryption = $row['config_smtp_encryption'];
$config_mail_from_email = sanitizeInput($row['config_mail_from_email']);
$config_mail_from_name = sanitizeInput($row['config_mail_from_name']);
$config_recurring_auto_send_invoice = intval($row['config_recurring_auto_send_invoice']);

// Tickets
$config_ticket_prefix = sanitizeInput($row['config_ticket_prefix']);
$config_ticket_from_name = sanitizeInput($row['config_ticket_from_name']);
$config_ticket_from_email = sanitizeInput($row['config_ticket_from_email']);
$config_ticket_client_general_notifications = intval($row['config_ticket_client_general_notifications']);
$config_ticket_autoclose_hours = intval($row['config_ticket_autoclose_hours']);
$config_ticket_csat_enable = intval($row['config_ticket_csat_enable'] ?? 1);
$config_ticket_csat_reminder_days = intval($row['config_ticket_csat_reminder_days'] ?? 3);
$config_ticket_csat_low_rating_threshold = intval($row['config_ticket_csat_low_rating_threshold'] ?? 2);
$config_ticket_new_ticket_notification_email = sanitizeInput($row['config_ticket_new_ticket_notification_email']);
$config_ticket_default_technician_id = intval($row['config_ticket_default_technician_id'] ?? 0);

// Get Config for Telemetry
$config_theme = $row['config_theme'];
$config_ticket_email_parse = intval($row['config_ticket_email_parse']);
$config_module_enable_itdoc = intval($row['config_module_enable_itdoc']);
$config_module_enable_ticketing = intval($row['config_module_enable_ticketing']);
$config_telemetry = intval($row['config_telemetry']);

// Alerts
$config_enable_alert_domain_expire = intval($row['config_enable_alert_domain_expire']);

// Remember-me Token Expiry
$config_login_remember_me_expire = max(30, intval($row['config_login_remember_me_expire']));

// Log retention
$config_log_retention = intval($row['config_log_retention']);

// Set Currency Format
$currency_format = numfmt_create($company_locale ?: 'en_US', NumberFormatter::CURRENCY);

// Check cron is enabled
if ($config_enable_cron == 0) {
    exit("Cron: is not enabled -- Quitting..");
}

/*
 * ###############################################################################################################
 *  STARTUP ACTIONS
 * ###############################################################################################################
 */

//Logging
logApp("Cron", "info", "Cron Started");

/*
 * ###############################################################################################################
 *  CLEAN UP (OLD) DATA
 * ###############################################################################################################
 */

// Clean-up ticket views table used for collision detection
mysqli_query($mysqli, "TRUNCATE TABLE ticket_views");

// Clean-up shared items that have been used
mysqli_query($mysqli, "DELETE FROM shared_items WHERE item_view_limit > 0 AND item_views >= item_view_limit");

// Clean-up shared items that have expired
mysqli_query($mysqli, "DELETE FROM shared_items WHERE item_expire_at < NOW()");

// Invalidate any password reset links
mysqli_query($mysqli, "UPDATE users SET user_password_reset_token = NULL WHERE user_archived_at IS NULL");
mysqli_query($mysqli, "UPDATE users SET user_password_reset_token = NULL"); // TODO: Make this 'expired' tokens only when we actually use expiry

// Clean-up old dismissed notifications
mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_dismissed_at < CURDATE() - INTERVAL 90 DAY");

// Clean-up mail queue
mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_queued_at < CURDATE() - INTERVAL 90 DAY");

// Mail intake housekeeping: failure counters for messages that were never seen again, and old alert-dedupe rows.
// Quarantined entries stay until an admin dismisses them.
mysqli_query($mysqli, "DELETE FROM mail_intake_state WHERE intake_state = 'pending' AND COALESCE(intake_last_attempt_at, intake_first_seen_at) < NOW() - INTERVAL 30 DAY");
mysqli_query($mysqli, "DELETE FROM mail_alerts WHERE alert_last_sent_at < NOW() - INTERVAL 90 DAY");

// Mail intake health: poller silent, mailbox unreachable, outbound mail out of retries. Alerts are de-duplicated (6 h by
// default) and this is deliberately independent of cron/ticket_email_parser.php, which is the thing that may have died.
try {
    \ITFlow\Mail\MailHealth::runChecks($mysqli, $config_ticket_email_parse === 1);
} catch (\Throwable $e) {
    logApp("Cron", "warning", "Mail health check failed: " . $e->getMessage());
}

// Clean-up old remember me tokens
mysqli_query($mysqli, "DELETE FROM remember_tokens WHERE remember_token_created_at < CURDATE() - INTERVAL $config_login_remember_me_expire DAY");

// Sign-in sessions list (Account > Security > Active sessions): forget rows that ended or went quiet over 30 days ago
require_once dirname(__DIR__) . '/includes/security_sessions.php';
secSessionPurge($mysqli);

// SLA: make every open ticket's pause match its status (waiting-on-customer/employee/vendor statuses flagged "Pauses SLA" stop the
// clock). Status changes made by any path - kanban, API, automation, a customer reply - are repaired here at the latest.
try {
    require_once dirname(__DIR__) . '/includes/sla_functions.php';
    slaReconcilePauses($mysqli);
} catch (\Throwable $e) {
    error_log('SLA pause reconcile skipped: ' . $e->getMessage());
}

// Queued jobs (webhook deliveries, event-rule actions): a bounded pass here means they are delivered even where the separate
// integration worker is not scheduled; a webhook that fails is retried with backoff (1, 5, 30, 120 minutes) before it is dead-lettered.
try {
    require_once dirname(__DIR__) . '/includes/event_bus.php';
    rivetRunJobWorker($mysqli, 50, 25);
} catch (\Throwable $e) {
    error_log('Job worker pass skipped: ' . $e->getMessage());
}

// Retention. A compliance preset (Administration > Compliance) is a minimum: nothing is deleted younger than it, whatever the
// stored numbers say. 0 (or blank) keeps everything: before, a retention of 0 deleted every row older than today.
$compliance_profile = (string) ($settings_row['config_compliance_profile'] ?? 'none');
$audit_retention_stored = intval($settings_row['config_audit_retention_days'] ?? 365);
$has_retention_policy = class_exists(\RivetCore\Compliance\RetentionPolicy::class);
$log_retention_days = $has_retention_policy ? \RivetCore\Compliance\RetentionPolicy::effectiveDays($compliance_profile, $config_log_retention) : max(0, $config_log_retention);
$audit_retention_days = $has_retention_policy ? \RivetCore\Compliance\RetentionPolicy::effectiveDays($compliance_profile, $audit_retention_stored) : max(0, $audit_retention_stored);

// Cleanup old audit, app/debug and auth logs.
if ($log_retention_days >= 1) {
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_created_at < CURDATE() - INTERVAL $log_retention_days DAY");
    mysqli_query($mysqli, "DELETE FROM app_logs WHERE app_log_created_at < CURDATE() - INTERVAL $log_retention_days DAY");
    mysqli_query($mysqli, "DELETE FROM auth_logs WHERE auth_log_created_at < CURDATE() - INTERVAL $log_retention_days DAY");
    // Event-rule run log (Administration > Automation) follows the same horizon; the once-only SLA markers are kept twice as long.
    @mysqli_query($mysqli, "DELETE FROM automation_rule_runs WHERE created_at < CURDATE() - INTERVAL $log_retention_days DAY");
    @mysqli_query($mysqli, "DELETE FROM automation_sla_marks WHERE created_at < CURDATE() - INTERVAL " . (2 * $log_retention_days) . " DAY");
}

// RivetCore's own log tables: the audit trail has its own horizon; the webhook delivery log and finished integration jobs
// follow the activity-log horizon. Never fatal: the cron's other work must run even if these tables are missing.
if (class_exists(\RivetCore\Retention\RetentionService::class)) {
    try {
        (new \RivetCore\Retention\RetentionService(new \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter($mysqli)))->prune($log_retention_days, $audit_retention_days);
    } catch (\Throwable $e) {
        error_log('RivetCore retention skipped: ' . $e->getMessage());
    }
}

// Compliance status: one saved snapshot per calendar month, so the history shows how the position changed. Never fatal.
try {
    if (\ITFlow\Compliance\ComplianceService::ready($mysqli)) {
        $snapshot_store = \ITFlow\Compliance\ComplianceService::snapshots($mysqli);
        $last_snapshot = $snapshot_store->latestTakenAt();
        if ($last_snapshot === null || substr($last_snapshot, 0, 7) !== date('Y-m')) {
            $snapshot_store->save(\ITFlow\Compliance\ComplianceService::assess($mysqli), null, 'scheduled', defined('APP_VERSION') ? APP_VERSION : null);
        }
    }
} catch (\Throwable $e) {
    error_log('Compliance snapshot skipped: ' . $e->getMessage());
}

// CLeanup old domain history
$sql = mysqli_query($mysqli, "SELECT domain_id FROM domains");
while ($row = mysqli_fetch_assoc($sql)) {
    $domain_id = intval($row['domain_id']);
    mysqli_query($mysqli, "
        DELETE FROM domain_history
        WHERE domain_history_id NOT IN (
            SELECT domain_history_id FROM (
                SELECT domain_history_id FROM domain_history
                WHERE domain_history_domain_id = $domain_id
                ORDER BY domain_history_modified_at DESC
                LIMIT 25
            ) AS recent_entries
        ) AND domain_history_domain_id = $domain_id
    ");
}

// Logging
// logAction("Cron", "Task", "Cron cleaned up old data");

/*
 * ###############################################################################################################
 *  ACTION DATA
 * ###############################################################################################################
 */

// GET NOTIFICATIONS

// DOMAINS EXPIRING

if ($config_enable_alert_domain_expire == 1) {

    $domainAlertArray = [1,7,45];

    foreach ($domainAlertArray as $day) {

        //Get Domains Expiring
        $sql = mysqli_query(
            $mysqli,
            "SELECT * FROM domains
            LEFT JOIN clients ON domain_client_id = client_id
            WHERE domain_expire IS NOT NULL AND domain_expire = CURDATE() + INTERVAL $day DAY"
        );

        while ($row = mysqli_fetch_assoc($sql)) {
            $domain_id = intval($row['domain_id']);
            $domain_name = sanitizeInput($row['domain_name']);
            $domain_expire = sanitizeInput($row['domain_expire']);
            $client_id = intval($row['client_id']);
            $client_name = sanitizeInput($row['client_name']);

            appNotify("Domain Expiring", "Domain $domain_name for $client_name will expire in $day Days on $domain_expire", "/agent/domains.php?client_id=$client_id", $client_id);

        }

    }
    // Logging
    // logAction("Cron", "Task", "Cron created notifications for domains expiring");
}

// CERTIFICATES EXPIRING

$certificateAlertArray = [1,7,45];

foreach ($certificateAlertArray as $day) {

    //Get Certs Expiring
    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM certificates
        LEFT JOIN clients ON certificate_client_id = client_id
        WHERE certificate_expire = CURDATE() + INTERVAL $day DAY"
    );

    while ($row = mysqli_fetch_assoc($sql)) {
        $certificate_id = intval($row['certificate_id']);
        $certificate_name = sanitizeInput($row['certificate_name']);
        $certificate_domain = sanitizeInput($row['certificate_domain']);
        $certificate_expire = sanitizeInput($row['certificate_expire']);
        $certificate_public_key = $row['certificate_public_key']; // Sanitize input breaks parsing
        $client_id = intval($row['client_id']);
        $client_name = sanitizeInput($row['client_name']);

        // Calculate the validity period
        if (!empty($certificate_public_key)) {
            $cert_public_key_obj = openssl_x509_parse($certificate_public_key);
            $validity_days = intval(round(($cert_public_key_obj['validTo_time_t'] - $cert_public_key_obj['validFrom_time_t']) / (60 * 60 * 24)));

            // Only raise a notification at 45 days if the certificate is valid for more than 90 days (i.e. not a LE)

            if ($day == 45 && $validity_days < 91) {
                // LE certificate - Do nothing here
                echo "Not raising notification for LE certificate $certificate_name expiring in 45 days";

            } else {
                // This certificate is either expiring in 1 or 7 days or is a non-LE certificate expiring in 45 days
                appNotify("Certificate Expiring", "Certificate $certificate_name for $client_name will expire in $day day(s) on $certificate_expire", "/agent/certificates.php?client_id=$client_id", $client_id);
            }

        } else {
            // No public key - notify anyway as we can't check the validity period
            appNotify("Certificate Expiring", "Certificate $certificate_name for $client_name will expire in $day day(s) on $certificate_expire", "/agent/certificates.php?client_id=$client_id", $client_id);
        }

    }

}
// Logging
// logAction("Cron", "Task", "Cron created notifications for certificates expiring");

// Asset Warranties Expiring

$warranty_alert_array = [1,7,45];

foreach ($warranty_alert_array as $day) {

    //Get Asset Warranty Expiring
    $sql = mysqli_query(
        $mysqli,
        "SELECT * FROM assets
        LEFT JOIN clients ON asset_client_id = client_id
        WHERE asset_warranty_expire = CURDATE() + INTERVAL $day DAY"
    );

    while ($row = mysqli_fetch_assoc($sql)) {
        $asset_id = intval($row['asset_id']);
        $asset_name = sanitizeInput($row['asset_name']);
        $asset_warranty_expire = sanitizeInput($row['asset_warranty_expire']);
        $client_id = intval($row['client_id']);
        $client_name = sanitizeInput($row['client_name']);

        appNotify("Asset Warranty Expiring", "Asset $asset_name warranty for $client_name will expire in $day Days on $asset_warranty_expire", "/agent/assets.php?client_id=$client_id", $client_id);

    }

}
// Logging
// logAction("Cron", "Task", "Cron created notifications for asset warranties expiring");

// Notify of New Tickets
// Get Ticket Pending Assignment
$sql_tickets_pending_assignment = mysqli_query($mysqli,"SELECT ticket_id FROM tickets WHERE ticket_status = 1");

$tickets_pending_assignment = mysqli_num_rows($sql_tickets_pending_assignment);

if ($tickets_pending_assignment > 0) {

    appNotify("Pending Tickets", "There are $tickets_pending_assignment new tickets pending assignment", "/agent/tickets.php?status=New");

    // Logging
    logApp("Cron", "info", "Cron created notifications for new tickets that are pending assignment");
}

// Recurring tickets

// Get recurring tickets for today
$sql_recurring_tickets = mysqli_query($mysqli, "SELECT * FROM recurring_tickets WHERE recurring_ticket_next_run = CURDATE()");

if (mysqli_num_rows($sql_recurring_tickets) > 0) {
    while ($row = mysqli_fetch_assoc($sql_recurring_tickets)) {

        $recurring_ticket_id = intval($row['recurring_ticket_id']);
        $subject = sanitizeInput($row['recurring_ticket_subject']);
        $details = mysqli_real_escape_string($mysqli, $row['recurring_ticket_details']);
        $priority = sanitizeInput($row['recurring_ticket_priority']);
        $frequency = sanitizeInput(strtolower($row['recurring_ticket_frequency']));
        $billable = intval($row['recurring_ticket_billable']);
        $created_id = intval($row['recurring_ticket_created_by']);
        $assigned_id = resolveTicketAssignee(intval($row['recurring_ticket_assigned_to']));
        $client_id = intval($row['recurring_ticket_client_id']);
        $contact_id = intval($row['recurring_ticket_contact_id']);
        $asset_id = intval($row['recurring_ticket_asset_id']);
        $category = resolveTicketCategory(intval($row['recurring_ticket_category']));

        $ticket_status = resolveTicketCreationStatus($assigned_id);

        if ($client_id) {
            $client_uri = "&client_id=$client_id";
        } else {
            $client_uri = '';
        }

        // Atomically increment and get the new ticket number
        mysqli_query($mysqli, "
            UPDATE settings
            SET
                config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number),
                config_ticket_next_number = config_ticket_next_number + 1
            WHERE company_id = 1
        ");

        $ticket_number = mysqli_insert_id($mysqli);

        // Raise the ticket
        mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = '$config_ticket_prefix', ticket_number = $ticket_number, ticket_source = 'Recurring', ticket_subject = '$subject', ticket_details = '$details', ticket_priority = '$priority', ticket_status = '$ticket_status', ticket_billable = $billable, ticket_created_by = $created_id, ticket_assigned_to = $assigned_id, ticket_contact_id = $contact_id, ticket_client_id = $client_id, ticket_asset_id = $asset_id, ticket_category = $category, ticket_recurring_ticket_id = $recurring_ticket_id");
        $id = mysqli_insert_id($mysqli);

        // Copy Additional Assets from Recurring ticket to new ticket
        mysqli_query($mysqli, "INSERT INTO ticket_assets (ticket_id, asset_id)
        SELECT $id, asset_id
        FROM recurring_ticket_assets
        WHERE recurring_ticket_id = $recurring_ticket_id");

        // Logging
        logAction("Ticket", "Create", "Cron created recurring scheduled $frequency ticket - $subject", $client_id, $id);

        customAction('ticket_create', $id);

        // Notifications

        // Get client/contact/ticket details
        $sql = mysqli_query(
            $mysqli,
            "SELECT client_name, contact_name, contact_email, ticket_prefix, ticket_number, ticket_priority, ticket_subject, ticket_details FROM tickets
                LEFT JOIN clients ON ticket_client_id = client_id
                LEFT JOIN contacts ON ticket_contact_id = contact_id
                WHERE ticket_id = $id"
        );
        $row = mysqli_fetch_assoc($sql);

        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $client_name = sanitizeInput($row['client_name']);
        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_priority = sanitizeInput($row['ticket_priority']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $ticket_details = mysqli_real_escape_string($mysqli, $row['ticket_details']);

        $ticket_from = resolveTicketFromIdentity($id);

        $data = [];

        // Notify client by email their ticket has been raised, if general notifications are turned on & there is a valid contact email
        if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1 && filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $email_subject = "Ticket created - [$ticket_prefix$ticket_number] - $ticket_subject (scheduled)";
            $email_body = "<i style=\'color: #808080\'>##- Please type your reply above this line -##</i><br><br>Hello $contact_name,<br><br>A ticket regarding \"$ticket_subject\" has been automatically created for you.<br><br>--------------------------------<br>$ticket_details--------------------------------<br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Status: Open<br>Portal: https://$config_base_url/client/ticket.php?id=$id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $email = [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $contact_email,
                    'recipient_name' => $contact_name,
                    'subject' => $email_subject,
                    'body' => $email_body
            ];

            $data[] = $email;

        }

        // Notify agent's via the DL address of the new ticket, if it's populated with a valid email
        if (filter_var($config_ticket_new_ticket_notification_email, FILTER_VALIDATE_EMAIL)) {

            $email_subject = "$config_app_name - New Recurring Ticket - $client_name: $ticket_subject";
            $email_body = "Hello, <br><br>This is a notification that a recurring (scheduled) ticket has been raised in " . nullable_htmlentities($config_app_name) . ". <br>Ticket: $ticket_prefix$ticket_number<br>Department: $client_name<br>Priority: $priority<br>Link: https://$config_base_url/agent/ticket.php?ticket_id=$id$client_uri <br><br>--------------------------------<br><br><b>$ticket_subject</b><br>$ticket_details";

            $email = [
                    'from' => $ticket_from['email'],
                    'from_name' => $ticket_from['name'],
                    'recipient' => $config_ticket_new_ticket_notification_email,
                    'recipient_name' => $ticket_from['name'],
                    'subject' => $email_subject,
                    'body' => $email_body
            ];

            $data[] = $email;
        }

        // Add to the mail queue
        addToMailQueue($data);

        // Set the next run date
        if ($frequency == "three days") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('3 days'));
        } elseif ($frequency == "weekly") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('1 week'));
        } elseif ($frequency == "biweekly") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('2 weeks'));
        } elseif ($frequency == "monthly") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('1 month'));
        } elseif ($frequency == "quarterly") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('3 months'));
        } elseif ($frequency == "biannually") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('6 months'));
        } elseif ($frequency == "annually") {
            $now = new DateTime();
            $next_run = date_add($now, date_interval_create_from_date_string('12 months'));
        }

        // Update the run date
        $next_run = $next_run->format('Y-m-d');
        $a = mysqli_query($mysqli, "UPDATE recurring_tickets SET recurring_ticket_next_run = '$next_run' WHERE recurring_ticket_id = $recurring_ticket_id");

    }
}

// Flag any active recurring "next run" dates that are in the past
$sql_invalid_recurring_tickets = mysqli_query($mysqli, "SELECT * FROM recurring_tickets WHERE recurring_ticket_next_run < CURDATE()");
while ($row = mysqli_fetch_assoc($sql_invalid_recurring_tickets)) {
    $subject = sanitizeInput($row['recurring_ticket_subject']);
    appNotify("Ticket", "Recurring ticket $subject next run date is in the past!", "/agent/recurring_tickets.php");
}

// Logging
// logAction("Cron", "Task", "Cron created sent out recurring tickets");


// TICKET RESOLUTION/CLOSURE PROCESS
// Changes tickets status from 'Resolved' >> 'Closed' after a defined interval

$sql_resolved_tickets_to_close = mysqli_query(
    $mysqli,
    "SELECT * FROM tickets
    WHERE ticket_status = 4
    AND ticket_updated_at < NOW() - INTERVAL $config_ticket_autoclose_hours HOUR"
);

while ($row = mysqli_fetch_assoc($sql_resolved_tickets_to_close)) {

    $ticket_id = $row['ticket_id'];
    $ticket_prefix = sanitizeInput($row['ticket_prefix']);
    $ticket_number = intval($row['ticket_number']);
    $ticket_subject = sanitizeInput($row['ticket_subject']);
    $ticket_status = sanitizeInput($row['ticket_status']);
    $ticket_assigned_to = sanitizeInput($row['ticket_assigned_to']);
    $client_id = intval($row['ticket_client_id']);

    mysqli_query($mysqli,"UPDATE tickets SET ticket_status = 5, ticket_closed_at = NOW(), ticket_closed_by = $ticket_assigned_to WHERE ticket_id = $ticket_id");

    //Logging
    logAction("Ticket", "Closed", "$ticket_prefix$ticket_number auto closed", $client_id, $ticket_id);

    customAction('ticket_close', $ticket_id);

    // Notify the client their ticket was automatically closed
    if ((!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

        $contact_sql = mysqli_query($mysqli, "SELECT contact_name, contact_email, ticket_url_key FROM tickets
            LEFT JOIN contacts ON ticket_contact_id = contact_id
            WHERE ticket_id = $ticket_id
        ");
        $contact_row = mysqli_fetch_assoc($contact_sql);

        $contact_name = sanitizeInput($contact_row['contact_name']);
        $contact_email = sanitizeInput($contact_row['contact_email']);
        $url_key = sanitizeInput($contact_row['ticket_url_key']);

        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $email_subject = "Ticket closed - [$ticket_prefix$ticket_number] - $ticket_subject | (do not reply)";
            $csat_rating_html = ($config_ticket_csat_enable == 1) ? csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key) : '';
            $email_body = "Hello $contact_name,<br><br>Your ticket regarding \"$ticket_subject\" has been automatically closed after a period of inactivity. <br><br> We hope the request/issue was resolved to your satisfaction - how would you rate it?<br>$csat_rating_html<a href='https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key'>Or click here to leave feedback</a>. <br>If you need further assistance, please raise a new ticket using the below details. Please do not reply to this email. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $ticket_from = resolveTicketFromIdentity($ticket_id);

            $email = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $email_subject,
                'body' => $email_body
            ];

            addToMailQueue([$email]);
        }
    }
}

// CSAT REMINDER
// One nudge email for a closed ticket that's sat unrated past the configured delay.
// ticket_csat_reminded_at is the dedupe column - set immediately after queueing so
// this can never send twice for the same ticket, no matter how often cron runs.
if ($config_ticket_csat_enable == 1 && (!empty($config_smtp_host) || !empty($config_smtp_provider)) && $config_ticket_client_general_notifications == 1) {

    $sql_unrated_tickets = mysqli_query(
        $mysqli,
        "SELECT tickets.*, contact_name, contact_email FROM tickets
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        WHERE ticket_status = 5
        AND ticket_csat_rating IS NULL
        AND ticket_csat_reminded_at IS NULL
        AND ticket_archived_at IS NULL
        AND ticket_closed_at < NOW() - INTERVAL $config_ticket_csat_reminder_days DAY"
    );

    while ($row = mysqli_fetch_assoc($sql_unrated_tickets)) {

        $ticket_id = intval($row['ticket_id']);
        $ticket_prefix = sanitizeInput($row['ticket_prefix']);
        $ticket_number = intval($row['ticket_number']);
        $ticket_subject = sanitizeInput($row['ticket_subject']);
        $contact_name = sanitizeInput($row['contact_name']);
        $contact_email = sanitizeInput($row['contact_email']);
        $url_key = sanitizeInput($row['ticket_url_key']);

        if (filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {

            $email_subject = "How did we do? - [$ticket_prefix$ticket_number] - $ticket_subject | (do not reply)";
            $csat_rating_html = csatEmailRatingLinksHtml($config_base_url, $ticket_id, $url_key);
            $email_body = "Hello $contact_name,<br><br>We hope your ticket regarding \"$ticket_subject\" was resolved to your satisfaction. You haven't had a chance to rate it yet - how would you rate it?<br>$csat_rating_html<a href='https://$config_base_url/guest/guest_view_ticket.php?ticket_id=$ticket_id&url_key=$url_key'>Or click here to leave feedback</a>. <br><br>Ticket: $ticket_prefix$ticket_number<br>Subject: $ticket_subject<br>Portal: https://$config_base_url/client/ticket.php?id=$ticket_id<br><br>--<br>$company_name - Support<br>$config_ticket_from_email<br>$company_phone";

            $ticket_from = resolveTicketFromIdentity($ticket_id);

            $email = [
                'from' => $ticket_from['email'],
                'from_name' => $ticket_from['name'],
                'recipient' => $contact_email,
                'recipient_name' => $contact_name,
                'subject' => $email_subject,
                'body' => $email_body
            ];

            addToMailQueue([$email]);
        }

        // Set regardless of whether a valid contact email existed, so a ticket with
        // no/invalid contact email doesn't get re-scanned by cron indefinitely.
        mysqli_query($mysqli, "UPDATE tickets SET ticket_csat_reminded_at = NOW() WHERE ticket_id = $ticket_id");
    }
}

// Telemetry was removed: this used to POST installation/company details and usage counts to the
// upstream ITFlow project's telemetry.itflow.org whenever config_telemetry was enabled in Admin >
// Settings > Telemetry. RivetIT sends nothing anywhere; config_telemetry keeps its schema default (0).


// Fetch Updates
$updates = fetchUpdates();

$update_message = $updates->update_message;

if ($updates->current_version !== $updates->latest_version) {
    // Throttled to once/day (previously re-sent on every cron run with no gate at all - a fresh "Update" notification
    // for every admin on every tick of this schedule). A newly-available version still notifies immediately even if
    // the last notice was under a day old, so bumping to a brand new release is never delayed by the throttle.
    $update_notify_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_update_notify_last_version, config_update_notify_last_at FROM settings WHERE company_id = 1"));
    $update_notify_last_version = $update_notify_row['config_update_notify_last_version'] ?? null;
    $update_notify_last_at = $update_notify_row['config_update_notify_last_at'] ?? null;
    $update_notify_due = $update_notify_last_version !== $updates->latest_version
        || $update_notify_last_at === null
        || strtotime($update_notify_last_at) <= (time() - 86400);
    if ($update_notify_due) {
        // Send Alert to inform Updates Available
        appNotify("Update", "$update_message", "/admin/update.php");
        $update_notify_version_esc = mysqli_real_escape_string($mysqli, (string) $updates->latest_version);
        mysqli_query($mysqli, "UPDATE settings SET config_update_notify_last_version = '$update_notify_version_esc', config_update_notify_last_at = NOW() WHERE company_id = 1");
    }
}



/*
 * ###############################################################################################################
 *  AUTO-BACKUP
 * ###############################################################################################################
 */

$config_backup_auto_enabled = intval($settings_row['config_backup_auto_enabled'] ?? 0);
$config_backup_frequency    = $settings_row['config_backup_frequency'] ?? 'daily';
$config_backup_retain_count = max(1, intval($settings_row['config_backup_retain_count'] ?? 7));

if ($config_backup_auto_enabled) {
    $backup_dir   = dirname(__DIR__) . '/backups';
    $should_run   = false;
    // Backup files keep the pre-RivetIT name itflow_<YmdHis>_<manual|auto>.zip on purpose: the Backup
    // page lists, prunes and serves downloads by that exact pattern (admin/post/backup.php).

    if ($config_backup_frequency === 'daily') {
        // Run if no auto-backup exists from today
        $today_pattern = $backup_dir . '/itflow_' . date('Ymd') . '*_auto.zip';
        $should_run    = empty(glob($today_pattern));
    } elseif ($config_backup_frequency === 'weekly') {
        // Run if no auto-backup exists from this ISO week
        $week_prefix  = date('YW'); // e.g. 202620
        $all_auto     = glob($backup_dir . '/itflow_*_auto.zip') ?: [];
        $has_this_week = false;
        foreach ($all_auto as $f) {
            $fdate = substr(basename($f), 7, 8); // YYYYMMDD portion
            if (date('YW', mktime(0, 0, 0, substr($fdate,4,2), substr($fdate,6,2), substr($fdate,0,4))) === $week_prefix) {
                $has_this_week = true;
                break;
            }
        }
        $should_run = !$has_this_week;
    }

    if ($should_run) {
        // Inline build_backup logic (cron runs as CLI, can't call HTTP handlers)
        // backup.php guards against direct access -- mark this as an authorized include.
        if (!defined('FROM_POST_HANDLER')) {
            define('FROM_POST_HANDLER', true);
        }
        require_once dirname(__DIR__) . '/admin/post/backup.php';
        try {
            $result = build_backup($mysqli, 'auto', $backup_dir);
        } catch (BackupPassphraseRequired $e) {
            logApp('Backup', 'error', 'Auto-backup refused: ' . $e->getMessage());
            appNotify('Backup', 'Automatic backup was NOT taken: set a backup passphrase (16+ characters) in Admin > Backup.', '/admin/backup.php');
            echo gmdate('Y-m-d\TH:i:s\Z') . " cron: auto-backup refused (no backup passphrase)\n";
            $result = null;
        }
        if ($result !== null) {
        prune_backups($backup_dir, $config_backup_retain_count);
        logApp('Backup', 'info', "Auto-backup completed: {$result['name']}");
        appNotify('Backup', "Auto-backup saved: {$result['name']}", '/admin/backup.php');
        // Mirrors cron/backup_cron.php's own heartbeat line - see its comment for why an
        // otherwise-silent cron block is worth a one-liner in the log on the run(s) that
        // actually do something, even on this straight-line, mostly-silent script.
        echo gmdate('Y-m-d\TH:i:s\Z') . " cron: auto-backup built {$result['name']}\n";
        }
    }
}

/*
 * ###############################################################################################################
 *  RECOVERY WATCH
 *  Alerts (in-app, email, event) for a stale or failed backup, a failing or stalled integration sync (RMM, Intune, UniFi)
 *  and a restore drill that stopped running. De-duplicated to one alert per alert_repeat_hours (default 24) each; dormant syncs are shown, not mailed. See src/Recovery/RecoveryWatch.php.
 * ###############################################################################################################
 */
try {
    $recovery_found = \ITFlow\Recovery\RecoveryWatch::run($mysqli, dirname(__DIR__), (bool) $config_backup_auto_enabled, (bool) intval($settings_row['config_module_enable_rmm'] ?? 0));
    if (($recovery_found['backup'] ?? 'ok') === 'stale' || ($recovery_found['sync'] ?? []) !== [] || in_array($recovery_found['drill'] ?? 'ok', ['stale'], true)) {
        echo gmdate('Y-m-d\TH:i:s\Z') . " cron: recovery watch found problems: " . json_encode($recovery_found) . "\n";
    }
} catch (\Throwable $e) {
    logApp("Cron", "error", "Recovery watch failed: " . $e->getMessage());
}

/*
 * ###############################################################################################################
 *  PLATFORM TASKS
 *  Every tick: seal any audit rows nobody sealed yet (hash chain) and copy them to the audit sink. Once a day: verify the audit hash chain
 *  (alert on a break), document review reminders, auto-retire of stale RMM assets (OFF unless switched on), orphaned entity links.
 *  See src/Platform/Nightly.php.
 * ###############################################################################################################
 */
try {
    $platform_found = \ITFlow\Platform\Nightly::run($mysqli, dirname(__DIR__));
    if (count($platform_found) > 1 || (($platform_found['sealed'] ?? 0) !== 0 && ($platform_found['sealed'] ?? 0) !== null)) {
        echo gmdate('Y-m-d\TH:i:s\Z') . " cron: platform tasks: " . json_encode($platform_found) . "\n";
    }
} catch (\Throwable $e) {
    logApp("Cron", "error", "Platform tasks failed: " . $e->getMessage());
}

/*
 * ###############################################################################################################
 *  COMET BACKUP — SESSION KEY REFRESH
 * ###############################################################################################################
 */
// Real-time failure detection happens via webhooks (comet_webhook.php), but
// that depends on the MSP having configured the webhook in Comet Server and
// it actually reaching us. Cron also refreshes the session key and polls
// recent jobs as a fallback, so a failed backup always raises an alert even
// if the webhook was never set up or didn't fire.
$config_comet_enabled    = intval($row['config_comet_enabled'] ?? 0);
$config_comet_auto_ticket = intval($row['config_comet_auto_ticket'] ?? 0);   // read by comet_auto_ticket_enabled()
$config_comet_totp_secret = decryptSetting($row['config_comet_totp_secret'] ?? '');
$config_comet_server_url  = $row['config_comet_server_url'] ?? '';
$config_comet_admin_user  = $row['config_comet_admin_user'] ?? '';
$config_comet_admin_pass  = decryptSetting($row['config_comet_admin_pass'] ?? '');

if ($config_comet_enabled && !empty($config_comet_server_url)) {
    require_once dirname(__DIR__) . '/includes/comet.php';
    // Refresh the session key if it's missing or about to expire
    if (!comet_get_session_key()) {
        comet_start_session();
    }

    // Fallback failure/recovery detection via polling
    $recent_jobs = comet_get_jobs_recent() ?: [];
    foreach ($recent_jobs as $job) {
        $result = comet_process_job($job);
        if ($result['action'] === 'ticket_created') {
            logApp('Comet', 'info', "Cron poll: backup failure ticket #{$result['ticket_id']} created for " . ($result['device_name'] ?? 'unknown'));
        } elseif ($result['action'] === 'ticket_resolved') {
            logApp('Comet', 'info', "Cron poll: backup recovered — ticket #{$result['ticket_id']} resolved");
        }
    }

    // Devices that have gone quiet entirely (no job at all within the
    // window) never show up in get-jobs-recent, so they need a separate pass.
    $missed = comet_check_missed_backups(48);
    if (!empty($missed['flagged'])) {
        logApp('Comet', 'info', "Cron poll: flagged {$missed['flagged']} device(s) with no backup activity in 48+ hours");
    }
}

/*
 * ###############################################################################################################
 *  WEBHOOK DELIVERY
 * ###############################################################################################################
 */

$sql_wq = mysqli_query($mysqli,
    "SELECT wq.queue_id, wq.queue_event, wq.queue_payload, wq.queue_attempts,
            w.*
     FROM webhook_queue wq
     JOIN webhooks w ON wq.queue_webhook_id = w.webhook_id
     WHERE wq.queue_status = 'pending'
       AND wq.queue_next_attempt_at <= NOW()
       AND w.webhook_enabled = 1
     LIMIT 50"
);

while ($wq = mysqli_fetch_assoc($sql_wq)) {
    $wq_id       = intval($wq['queue_id']);
    $wq_payload  = $wq['queue_payload'];
    $wq_secret   = decryptSetting($wq['webhook_secret']);
    $wq_url      = $wq['webhook_url'];
    $wq_attempts = intval($wq['queue_attempts']) + 1;
    $wq_event    = $wq['queue_event'];

    // Slack / Teams destination: a chat-formatted message (ITFlow\Webhooks\ChatDelivery) with the same retry/backoff below.
    // The generic signed POST that follows is untouched and skipped for these.
    $wq_chat = \ITFlow\Webhooks\ChatFormatter::isChatType(\ITFlow\Webhooks\ChatFormatter::normalizeType($wq['webhook_type'] ?? ''));
    $wq_chat_code = 0;
    if ($wq_chat) {
        $wq_decoded = json_decode($wq_payload, true);
        $wq_res = \ITFlow\Webhooks\ChatDelivery::deliverRow($mysqli, $wq, $wq_event, is_array($wq_decoded) ? (array) ($wq_decoded['data'] ?? []) : [], $wq_attempts);
        $wq_chat_code = $wq_res['skipped'] ? 204 : intval($wq_res['http_status'] ?? 0);
    }

    $signature = 'sha256=' . hash_hmac('sha256', $wq_payload, $wq_secret);

    // X-ITFlow-* are the header names existing receivers verify: keep them. X-RivetIT-* carry the same
    // values so new receivers can use the product's name; either pair is enough (same as WebhookDispatcher).
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json
X-ITFlow-Signature: $signature
X-ITFlow-Event: $wq_event
X-RivetIT-Signature: $signature
X-RivetIT-Event: $wq_event
",
        'content'       => $wq_payload,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);

    $resp_code = 0;
    if ($wq_chat) {
        $resp_code = $wq_chat_code;
    } elseif (($wq['webhook_destination'] ?? '') !== '') {
        // Preset webhook (n8n, ntfy, Discord, ...): its URL is stored encrypted and it has its own body format, method and
        // auth headers, so it goes through the same dispatcher as the job queue instead of the plain POST below.
        require_once __DIR__ . '/../includes/event_bus.php';
        $wq_decoded = json_decode($wq_payload, true);
        $wq_data = is_array($wq_decoded) ? (array) ($wq_decoded['data'] ?? []) : [];
        $wq_opts = \ITFlow\Webhooks\DestinationConfig::options($wq, $wq_event, $wq_data);
        $wq_res = rivetWebhookDispatcher($mysqli)->deliverTo(intval($wq['webhook_id']), $wq_event, $wq_data, $wq_attempts, is_array($wq_decoded) ? ($wq_decoded['timestamp'] ?? null) : null, time(), $wq_opts === [] ? null : $wq_opts);
        $resp_code = intval($wq_res['http_status'] ?? 0);
    } else {
        @file_get_contents($wq_url, false, $ctx);
        if (isset($http_response_header) && preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0], $m)) {
            $resp_code = intval($m[1]);
        }
    }

    $success = ($resp_code >= 200 && $resp_code < 300);

    if ($success) {
        mysqli_query($mysqli, "UPDATE webhook_queue SET queue_status = 'delivered', queue_attempts = $wq_attempts, queue_response_code = $resp_code, queue_delivered_at = NOW() WHERE queue_id = $wq_id");
    } else {
        // Exponential backoff: 5 min, 30 min, 2 hr, 6 hr → then failed
        $backoffs = [5, 30, 120, 360];
        if ($wq_attempts >= 5) {
            mysqli_query($mysqli, "UPDATE webhook_queue SET queue_status = 'failed', queue_attempts = $wq_attempts, queue_response_code = $resp_code WHERE queue_id = $wq_id");
        } else {
            $delay = $backoffs[min($wq_attempts - 1, count($backoffs) - 1)];
            mysqli_query($mysqli, "UPDATE webhook_queue SET queue_attempts = $wq_attempts, queue_response_code = $resp_code, queue_next_attempt_at = DATE_ADD(NOW(), INTERVAL $delay MINUTE) WHERE queue_id = $wq_id");
        }
    }
}

// Prune old delivered/failed webhook entries after 30 days
mysqli_query($mysqli, "DELETE FROM webhook_queue WHERE queue_status IN ('delivered','failed') AND queue_created_at < NOW() - INTERVAL 30 DAY");

/*
 * ###############################################################################################################
 *  FINISH UP
 * ###############################################################################################################
 */

/*
 * ###############################################################################################################
 *  SCHEDULED TICKET REOPEN (Syncro-Beta)
 * ###############################################################################################################
 */

$sql_due_reopens = mysqli_query($mysqli,
    "SELECT ticket_id, ticket_prefix, ticket_number, ticket_client_id, ticket_reopen_at
     FROM tickets
     WHERE ticket_closed_at IS NOT NULL AND ticket_reopen_at IS NOT NULL AND ticket_reopen_at <= NOW()"
);
$reopen_count = 0;
while ($due_reopen = mysqli_fetch_assoc($sql_due_reopens)) {
    $reopen_ticket_id = intval($due_reopen['ticket_id']);
    $reopen_client_id = intval($due_reopen['ticket_client_id']);
    $reopen_ref = $due_reopen['ticket_prefix'] . $due_reopen['ticket_number'];
    $reopen_at_display = date('M j, Y g:i A', strtotime($due_reopen['ticket_reopen_at']));

    // Same fields the manual Reopen action resets (agent/post/ticket.php's
    // reopen_ticket handler) - status back to Open, clear resolved/closed.
    mysqli_query($mysqli, "UPDATE tickets SET " . ticketReopenSql() . "ticket_status = 2, ticket_resolved_at = NULL, ticket_closed_at = NULL, ticket_closed_by = 0, ticket_reopen_at = NULL WHERE ticket_id = $reopen_ticket_id");
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'Automatically reopened (scheduled on $reopen_at_display).', ticket_reply_type = 'System', ticket_reply_time_worked = '00:00:00', ticket_reply_by = 0, ticket_reply_ticket_id = $reopen_ticket_id");

    logAction("Ticket", "Reopened", "Ticket $reopen_ref automatically reopened (scheduled reopen)", $reopen_client_id, $reopen_ticket_id);
    customAction('ticket_update', $reopen_ticket_id);
    $reopen_count++;
}
if ($reopen_count > 0) {
    logApp("Cron", "info", "Scheduled reopen: $reopen_count ticket(s) automatically reopened");
}

// SLA clocks as events (ticket.sla_warning / ticket.sla_breached) for event rules and webhooks. Never fatal.
try {
    require_once dirname(__DIR__) . '/includes/event_bus.php';
    \ITFlow\Automation\SlaEventEmitter::run($mysqli, 'rivetEmitEvent');
} catch (\Throwable $e) {
    error_log('SLA events skipped: ' . $e->getMessage());
}

/*
 * ###############################################################################################################
 *  TICKET AUTOMATION RULES
 * ###############################################################################################################
 */

require_once dirname(__DIR__) . '/includes/automation_functions.php';
require_once dirname(__DIR__) . '/includes/sla_functions.php'; // slaStatus() for SLA %-consumed escalation conditions

$automation_rules = [];
$sql_rules = mysqli_query($mysqli,
    "SELECT * FROM ticket_automation_rules WHERE rule_enabled = 1 ORDER BY rule_order ASC, rule_id ASC"
);
while ($r = mysqli_fetch_assoc($sql_rules)) {
    $automation_rules[] = $r;
}

if (!empty($automation_rules)) {
    $schedule_rules      = array_filter($automation_rules, function ($r) { return ($r['rule_trigger'] ?: 'schedule') === 'schedule'; });
    $rmm_alert_rules     = array_filter($automation_rules, function ($r) { return $r['rule_trigger'] === 'rmm_alert'; });
    $asset_offline_rules = array_filter($automation_rules, function ($r) { return $r['rule_trigger'] === 'asset_offline'; });
    $asset_online_rules  = array_filter($automation_rules, function ($r) { return $r['rule_trigger'] === 'asset_online'; });
    $vacation_return_rules = array_filter($automation_rules, function ($r) { return $r['rule_trigger'] === 'vacation_return'; });

    // ----- Trigger: requester returns from vacation -----
    // Consider only tickets closed during dates explicitly recorded on the
    // requester and after the rule was created. This avoids reopening an
    // employee's entire historical closed-ticket archive when a rule is added.
    if (!empty($vacation_return_rules)) {
        $first_rule_at = mysqli_real_escape_string($mysqli, min(array_column($vacation_return_rules, 'rule_created_at')));
        $sql_vacation_tickets = mysqli_query($mysqli,
            "SELECT t.*, c.contact_vacation_start, c.contact_vacation_end
             FROM tickets t
             JOIN contacts c ON c.contact_id = t.ticket_contact_id
             WHERE t.ticket_closed_at IS NOT NULL
               AND t.ticket_closed_at >= '$first_rule_at'
               AND t.ticket_archived_at IS NULL
               AND (t.ticket_merged_into_id IS NULL OR t.ticket_merged_into_id = 0)
               AND t.ticket_reopen_at IS NULL
               AND c.contact_vacation_start IS NOT NULL
               AND c.contact_vacation_end < CURDATE()
               AND t.ticket_closed_at >= c.contact_vacation_start
               AND t.ticket_closed_at < DATE_ADD(c.contact_vacation_end, INTERVAL 1 DAY)"
        );
        while ($ticket = mysqli_fetch_assoc($sql_vacation_tickets)) {
            $tid = intval($ticket['ticket_id']);
            $context = [
                'tid'         => $tid,
                'client_id'   => intval($ticket['ticket_client_id']),
                'asset_id'    => intval($ticket['ticket_asset_id']),
                'assigned_to' => intval($ticket['ticket_assigned_to']),
                'priority'    => strtolower((string) $ticket['ticket_priority']),
                'category'    => intval($ticket['ticket_category']),
                'subject'     => (string) $ticket['ticket_subject'],
            ];
            foreach ($vacation_return_rules as $rule) {
                if (strtotime($ticket['ticket_closed_at']) < strtotime($rule['rule_created_at'])) continue;
                $already_ran = mysqli_num_rows(mysqli_query($mysqli,
                    "SELECT 1 FROM ticket_automation_runs
                     WHERE rule_id = " . intval($rule['rule_id']) . "
                       AND ticket_id = $tid AND trigger_type = 'vacation_return' LIMIT 1"
                )) > 0;
                if ($already_ran) continue;
                $conditions = automationGetConditions($rule);
                if ($conditions && !automationConditionsMatch($conditions, $context)) continue;

                $summaries = [];
                foreach (automationGetActions($rule) as $action) {
                    $summary = automationExecuteAction($mysqli, $action, $context, $rule);
                    if ($summary !== null) $summaries[] = $summary;
                }
                if ($summaries) {
                    automationLogRun($mysqli, $rule, 'vacation_return', $context, implode('; ', $summaries));
                }
                if (!empty($context['reopened'])) break;
            }
        }
    }

    // ----- Trigger: schedule (open tickets evaluated on every run) -----
    if (!empty($schedule_rules)) {
        $sql_open = mysqli_query($mysqli,
            "SELECT t.*, ts.ticket_status_name, u.user_email AS assignee_email, u.user_name AS assignee_name
             FROM tickets t
             LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
             LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
             WHERE t.ticket_archived_at IS NULL
               AND t.ticket_resolved_at IS NULL"
        );

        while ($ticket = mysqli_fetch_assoc($sql_open)) {
            $tid          = intval($ticket['ticket_id']);
            $age_hours    = round((time() - strtotime($ticket['ticket_created_at'])) / 3600, 1);
            $priority     = strtolower($ticket['ticket_priority'] ?? '');
            $status_id    = intval($ticket['ticket_status']);
            $assigned_to  = intval($ticket['ticket_assigned_to']);
            $client_id    = intval($ticket['ticket_client_id']);

            // Hours since last reply
            $last_reply = mysqli_fetch_assoc(mysqli_query($mysqli,
                "SELECT MAX(ticket_reply_created_at) AS lr FROM ticket_replies WHERE ticket_reply_ticket_id = $tid"
            ));
            $idle_since = $last_reply['lr'] ? $last_reply['lr'] : $ticket['ticket_created_at'];
            $idle_hours = round((time() - strtotime($idle_since)) / 3600, 1);

            $now_str = date('Y-m-d H:i:s');
            // SLA %-consumed for tiered escalation rules. slaStatus() derives pct from
            // the stored (business-hours-aware) due dates; the calendar arg is unused so
            // we pass [] to avoid a per-ticket policy/calendar lookup in the cron loop.
            // 'none' state (no due date) reports 0 so a "greater_than" rule never fires.
            $sla_st = slaStatus($ticket, []);
            $context = [
                'age_hours'               => $age_hours,
                'priority'                => $priority,
                'status_id'               => $status_id,
                'assigned_to'             => $assigned_to,
                'idle_hours'              => $idle_hours,
                'category'                => intval($ticket['ticket_category']),
                'tid'                     => $tid,
                'client_id'               => $client_id,
                'asset_id'                => intval($ticket['ticket_asset_id']),
                'sla_response_breached'   => (!empty($ticket['ticket_sla_response_due']) && empty($ticket['ticket_first_response_at']) && slaDueState($mysqli, $ticket, $ticket['ticket_sla_response_due'])['breached']) ? 1 : 0,
                'sla_resolution_breached' => (!empty($ticket['ticket_sla_resolution_due']) && slaDueState($mysqli, $ticket, $ticket['ticket_sla_resolution_due'])['breached']) ? 1 : 0,
                'sla_response_pct'        => ($sla_st['response']['pct']   === null) ? 0 : $sla_st['response']['pct'],
                'sla_resolution_pct'      => ($sla_st['resolution']['pct'] === null) ? 0 : $sla_st['resolution']['pct'],
            ];

            foreach ($schedule_rules as $rule) {
                $conditions = automationGetConditions($rule);
                if (!automationConditionsMatch($conditions, $context)) continue;

                // Unlike rmm_alert/asset_offline/online (each gated by an
                // automation_processed_at column on the source event so they only
                // ever fire once), a schedule rule re-evaluates every open ticket on
                // every cron pass with no such gate. That's fine for naturally
                // idempotent actions (set_priority, set_status, assign_to,
                // escalate - re-asserting the same state is a harmless no-op), but
                // add_note/ai_triage post a NEW ticket reply and a NEW billed AI
                // call every single time, so a long-lived matching ticket
                // accumulates duplicate notes forever. Fire those two at most once
                // per (rule, ticket): skip them (but still let other actions in the
                // same rule run) once this rule has already logged a run against
                // this ticket.
                $already_ran = mysqli_num_rows(mysqli_query($mysqli,
                    "SELECT 1 FROM ticket_automation_runs
                     WHERE rule_id = " . intval($rule['rule_id']) . "
                       AND ticket_id = $tid AND trigger_type = 'schedule' LIMIT 1")) > 0;

                // Optional once-per-ticket mode. A run is recorded only after
                // at least one action succeeds, so no-op rules can retry later.
                if (!empty($rule['rule_run_once']) && $already_ran) continue;

                $summaries = [];
                foreach (automationGetActions($rule) as $action) {
                    if ($already_ran && in_array($action['action'] ?? '', ['add_note', 'ai_triage'], true)) {
                        continue;
                    }
                    $summary = automationExecuteAction($mysqli, $action, $context, $rule);
                    if ($summary !== null) $summaries[] = $summary;
                }
                if (!empty($summaries)) {
                    automationLogRun($mysqli, $rule, 'schedule', $context, implode('; ', $summaries));
                }
            }
        }
    }

    // ----- Trigger: rmm_alert (new alerts not yet processed by automation) -----
    if (!empty($rmm_alert_rules)) {
        $sql_alerts = mysqli_query($mysqli,
            "SELECT ra.*, arl.hostname, arl.tactical_agent_id
             FROM rmm_alerts ra
             LEFT JOIN asset_rmm_links arl ON arl.asset_id = ra.asset_id AND arl.integration_id = ra.integration_id
             WHERE ra.automation_processed_at IS NULL"
        );

        while ($alert = mysqli_fetch_assoc($sql_alerts)) {
            $context = [
                'severity'       => strtolower($alert['severity'] ?? ''),
                'message'        => $alert['message'] ?? '',
                'asset_id'       => intval($alert['asset_id'] ?? 0),
                'client_id'      => intval($alert['client_id'] ?? 0),
                'integration_id' => intval($alert['integration_id']),
                'hostname'       => $alert['hostname'] ?? '',
                'status'         => $alert['status'],
                'alert_id'       => intval($alert['id']),
                'alert'          => $alert,
            ];

            foreach ($rmm_alert_rules as $rule) {
                $conditions = automationGetConditions($rule);
                if (!automationConditionsMatch($conditions, $context)) continue;

                $summaries = [];
                foreach (automationGetActions($rule) as $action) {
                    $summary = automationExecuteAction($mysqli, $action, $context, $rule);
                    if ($summary !== null) $summaries[] = $summary;
                }
                // This trigger only ever fires once per alert (gated by
                // automation_processed_at below), so log the match even when every
                // action was a no-op - otherwise a broken action (e.g. AI triage)
                // leaves zero trace that the rule ran at all.
                automationLogRun($mysqli, $rule, 'rmm_alert', $context,
                    !empty($summaries) ? implode('; ', $summaries) : 'Matched - no action produced a result (check error log)');
            }

            mysqli_query($mysqli, "UPDATE rmm_alerts SET automation_processed_at = NOW() WHERE id = " . intval($alert['id']));
        }
    }

    // ----- Trigger: asset_offline / asset_online (RMM status transitions) -----
    if (!empty($asset_offline_rules) || !empty($asset_online_rules)) {
        $sql_assets = mysqli_query($mysqli,
            "SELECT arl.*, a.asset_client_id AS client_id FROM asset_rmm_links arl
             JOIN assets a ON a.asset_id = arl.asset_id
             WHERE arl.rmm_status_changed_at IS NOT NULL
               AND (arl.automation_processed_at IS NULL OR arl.automation_processed_at < arl.rmm_status_changed_at)"
        );

        while ($link = mysqli_fetch_assoc($sql_assets)) {
            $trigger_type = null;
            $rules_for_status = [];
            if ($link['rmm_status'] === 'offline') {
                $trigger_type     = 'asset_offline';
                $rules_for_status = $asset_offline_rules;
            } elseif ($link['rmm_status'] === 'online') {
                $trigger_type     = 'asset_online';
                $rules_for_status = $asset_online_rules;
            }

            if ($trigger_type !== null && !empty($rules_for_status)) {
                $context = [
                    'asset_id'       => intval($link['asset_id']),
                    'client_id'      => intval($link['client_id']),
                    'hostname'       => $link['hostname'] ?? '',
                    'integration_id' => intval($link['integration_id']),
                    'rmm_status'     => $link['rmm_status'],
                ];

                foreach ($rules_for_status as $rule) {
                    $conditions = automationGetConditions($rule);
                    if (!automationConditionsMatch($conditions, $context)) continue;

                    $summaries = [];
                    foreach (automationGetActions($rule) as $action) {
                        $summary = automationExecuteAction($mysqli, $action, $context, $rule);
                        if ($summary !== null) $summaries[] = $summary;
                    }
                    // This trigger only ever fires once per status change (gated by
                    // automation_processed_at below), so log the match even when
                    // every action was a no-op - otherwise a broken action (e.g. AI
                    // triage) leaves zero trace that the rule ran at all.
                    automationLogRun($mysqli, $rule, $trigger_type, $context,
                        !empty($summaries) ? implode('; ', $summaries) : 'Matched - no action produced a result (check error log)');
                }
            }

            mysqli_query($mysqli, "UPDATE asset_rmm_links SET automation_processed_at = NOW() WHERE id = " . intval($link['id']));
        }
    }

    logApp("Cron", "info", "Ticket automation rules processed (" . count($automation_rules) . " rules)");
}

/*
 * ###############################################################################################################
 *  RMM ALERT SYNC (Syncro-Beta)
 * ###############################################################################################################
 */

$config_module_enable_rmm = intval($settings_row['config_module_enable_rmm'] ?? 0);

if ($config_module_enable_rmm) {
    require_once dirname(__DIR__) . '/includes/rmm_client_factory.php';
    require_once dirname(__DIR__) . '/includes/class_rmm_asset_mapper.php';

    $sql_rmm_integrations = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 AND type <> 'rivetit_agent'");
    while ($rmm_intg = mysqli_fetch_assoc($sql_rmm_integrations)) {
        $rmm_intg_id = intval($rmm_intg['id']);
        $rmm_log_id = 0;
        try {
            $rmm_client = getRmmClient($rmm_intg_id);
            $rmm_mapper = new RmmAssetMapper($mysqli, $rmm_intg_id, 0, $rmm_client);
            // A sync-log row per scheduled run (like Intune and UniFi already do) so the recovery watch can see a stalled or failing sync.
            $rmm_log_id   = $rmm_mapper->startSyncLog();

            $agents       = $rmm_client->getAgents();
            $asset_stats  = $rmm_mapper->syncAgents($agents);
            $alert_stats  = $rmm_mapper->syncAlerts();
            // A few bad devices must not make every scheduled run "failed" (and trip the watch): only a run where every device errored is.
            $rmm_agent_errors = $asset_stats['errors'] ?? [];
            $rmm_all_failed   = count($agents) > 0 && count($rmm_agent_errors) >= count($agents);
            $rmm_mapper->finishSyncLog($rmm_log_id, ['errors' => $rmm_all_failed ? $rmm_agent_errors : []] + $asset_stats);
            if (!$rmm_all_failed && $rmm_agent_errors) {
                logApp("Cron", "warning", "RMM sync for '{$rmm_intg['name']}': " . count($rmm_agent_errors) . " device(s) skipped with errors, first: " . $rmm_agent_errors[0]);
            }

            logApp("Cron", "info",
                "RMM sync for '{$rmm_intg['name']}': assets {$asset_stats['created']} created, {$asset_stats['updated']} updated, {$asset_stats['matched']} matched, {$asset_stats['skipped']} skipped" .
                "; alerts {$alert_stats['created']} new, {$alert_stats['resolved']} resolved"
            );
        } catch (RuntimeException $e) {
            logApp("Cron", "error", "RMM sync failed for '{$rmm_intg['name']}': " . $e->getMessage());
            if ($rmm_log_id > 0) {
                $rmm_mapper->finishSyncLog($rmm_log_id, ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => [$e->getMessage()]]);
            }
        }
    }
}

/*
 * ###############################################################################################################
 *  BUILT-IN ENDPOINT AGENT HOUSEKEEPING
 *  Devices that stopped checking in go offline on their RMM link, lost job acknowledgements are settled (destructive jobs
 *  become failed/result_lost and are never retried), and old check-in, attempt and job rows are pruned. A no-op while the
 *  agent service is off.
 * ###############################################################################################################
 */
try {
    // RivetCore\Rmm\Maintenance\Housekeeping (rivet/rivet-core). While the module is off this is one primary-key SELECT and no module is loaded.
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
    $ea_stats = rivetRmmHousekeeping($mysqli);
    if (array_sum($ea_stats) > 0) {
        logApp("Cron", "info", "Endpoint agent housekeeping: " . json_encode($ea_stats));
    }
} catch (\Throwable $e) {
    logApp("Cron", "error", "Endpoint agent housekeeping failed: " . $e->getMessage());
}

/*
 * ###############################################################################################################
 *  INTUNE DEVICE SYNC (Syncro-Beta)
 * ###############################################################################################################
 */

$sql_ms_integration = mysqli_query($mysqli, "SELECT * FROM microsoft_integrations WHERE enabled=1 AND intune_sync_enabled=1 ORDER BY microsoft_integration_id DESC LIMIT 1");
$ms_integration_row = $sql_ms_integration ? mysqli_fetch_assoc($sql_ms_integration) : null;

if ($ms_integration_row) {
    if (!class_exists(\ITFlow\Integrations\Microsoft\GraphClient::class)) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    $ms_intg_id = intval($ms_integration_row['microsoft_integration_id']);

    if (empty($ms_integration_row['client_secret_enc'])) {
        logApp("Cron", "error", "Intune sync skipped: Microsoft integration has no client secret saved");
    } else {
        // Same service as the Sync Now button: lock, sync log, 120 s time limit, classified errors, shared token cache.
        $intune_run = \ITFlow\Integrations\Microsoft\IntuneSyncService::run($mysqli, $ms_integration_row, 0, 120);
        if ($intune_run['locked']) {
            logApp("Cron", "info", "Intune sync skipped: another run is still in progress");
        } elseif ($intune_run['stats'] !== null) {
            logApp("Cron", $intune_run['ok'] ? "info" : "error", $intune_run['message']);
        } else {
            logApp("Cron", "error", "Intune sync failed" . ($intune_run['error_code'] ? " [{$intune_run['error_code']}]" : "") . ": " . $intune_run['message']);
        }
    }
}

/*
 * ###############################################################################################################
 *  MANAGEENGINE MDM DEVICE SYNC (read-only; off until an administrator saves a connection and switches it on)
 *  Honors the saved sync interval (cron.php runs more often than that); same service as the Sync now button.
 * ###############################################################################################################
 */
try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mdm_row = \ITFlow\Integrations\ManageEngine\MdmClientFactory::row($mysqli);
    if ($mdm_row) {
        \ITFlow\Integrations\ManageEngine\MdmSyncService::checkKeyExpiry($mysqli, $mdm_row);   // on-premises API key: reminder 30 days before it expires
    }
    if ($mdm_row && \ITFlow\Integrations\ManageEngine\MdmSyncService::isDue($mysqli, $mdm_row)) {
        $mdm_run = \ITFlow\Integrations\ManageEngine\MdmSyncService::run($mysqli, $mdm_row, 0, 120);
        if ($mdm_run['locked']) {
            logApp("Cron", "info", "ManageEngine MDM sync skipped: another run is still in progress");
        } elseif ($mdm_run['stats'] !== null) {
            logApp("Cron", $mdm_run['ok'] ? "info" : "error", $mdm_run['message']);
        } else {
            logApp("Cron", "error", "ManageEngine MDM sync failed" . ($mdm_run['error_code'] ? " [{$mdm_run['error_code']}]" : "") . ": " . $mdm_run['message']);
        }
    }
} catch (\Throwable $e) {
    logApp("Cron", "error", "ManageEngine MDM sync failed: " . $e->getMessage());
}

/*
 * ###############################################################################################################
 *  ODOO DIRECTORY SYNC (Departments + Employees)
 * ###############################################################################################################
 */

$sql_odoo_integration = mysqli_query($mysqli, "SELECT * FROM odoo_integrations WHERE enabled=1 ORDER BY odoo_integration_id DESC LIMIT 1");
$odoo_integration_row = $sql_odoo_integration ? mysqli_fetch_assoc($sql_odoo_integration) : null;

if ($odoo_integration_row) {
    if (!class_exists(\ITFlow\Integrations\Odoo\OdooClient::class)) {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    }

    $odoo_intg_id = intval($odoo_integration_row['odoo_integration_id']);

    if (empty($odoo_integration_row['base_url']) || empty($odoo_integration_row['database_name']) ||
        empty($odoo_integration_row['username']) || empty($odoo_integration_row['api_key_enc'])) {
        logApp("Cron", "error", "Odoo directory sync skipped: integration is missing base URL/database/username/API key");
    } else {
        // Protocol from odoo_integrations.api_protocol (JSON-RPC unless Test Connection proved JSON-2).
        $odoo_client = \ITFlow\Integrations\Odoo\OdooConnectorFactory::clientFromRow($odoo_integration_row);
        $odoo_mapper = new \ITFlow\Integrations\Odoo\OdooDirectoryMapper($mysqli, $odoo_intg_id, 0);
        $odoo_log_id = $odoo_mapper->startSyncLog();

        try {
            $odoo_departments = $odoo_client->listDepartments();
            $odoo_dept_stats = $odoo_mapper->syncDepartments($odoo_departments);

            $odoo_employees = $odoo_client->listEmployees();
            $odoo_emp_stats = $odoo_mapper->syncEmployees($odoo_employees, $odoo_dept_stats['idMap']);

            $odoo_mapper->finishSyncLog($odoo_log_id, $odoo_dept_stats, $odoo_emp_stats);

            logApp("Cron", "info",
                "Odoo directory sync: departments {$odoo_dept_stats['created']} created/{$odoo_dept_stats['updated']} updated/{$odoo_dept_stats['matched']} matched/{$odoo_dept_stats['skipped']} skipped" .
                "; employees {$odoo_emp_stats['created']} created/{$odoo_emp_stats['updated']} updated/{$odoo_emp_stats['matched']} matched/{$odoo_emp_stats['skipped']} skipped"
            );
        } catch (RuntimeException $e) {
            mysqli_query($mysqli, "UPDATE odoo_sync_log SET finished_at=NOW(), status='failed', errors='" .
                mysqli_real_escape_string($mysqli, $e->getMessage()) . "' WHERE id=$odoo_log_id");
            logApp("Cron", "error", "Odoo directory sync failed: " . $e->getMessage());
        }
    }
}

/*
 * ###############################################################################################################
 *  RMM ALERT AUTO-TICKETING (Syncro-Beta)
 * ###############################################################################################################
 */

$config_rmm_auto_ticket_severities = sanitizeInput($settings_row['config_rmm_auto_ticket_severities'] ?? '');

if ($config_module_enable_rmm && !empty($config_rmm_auto_ticket_severities)) {
    require_once dirname(__DIR__) . '/includes/rmm_functions.php';

    $auto_ticket_severities = array_filter(explode(',', $config_rmm_auto_ticket_severities));
    if (!empty($auto_ticket_severities)) {
        $severity_list = implode("','", array_map(function ($s) use ($mysqli) {
            return mysqli_real_escape_string($mysqli, $s);
        }, $auto_ticket_severities));

        $sql_new_alerts = mysqli_query($mysqli,
            "SELECT * FROM rmm_alerts
             WHERE status = 'new' AND ticket_id IS NULL
               AND severity IN ('$severity_list')"
        );

        $auto_ticket_count = 0;
        while ($alert = mysqli_fetch_assoc($sql_new_alerts)) {
            createTicketFromRmmAlert($mysqli, $alert, 0, 'RMM Automation');
            $auto_ticket_count++;
        }

        if ($auto_ticket_count > 0) {
            logApp("Cron", "info", "RMM auto-ticketing created $auto_ticket_count ticket(s) from new alerts");
        }
    }
}

/*
 * ###############################################################################################################
 *  ANALYTICS DELIVERY (daily metrics rollup + scheduled/emailed reports)
 * ###############################################################################################################
 */

// Upsert yesterday's + today's ticket-flow snapshot into ticket_metrics_daily.
require_once dirname(__DIR__) . '/cron/metrics_rollup.php';

// Render + enqueue any report_schedules that are due.
require_once dirname(__DIR__) . '/cron/report_scheduler.php';

// Fire due CRM follow-up reminders (activity_reminder_at) to the activity owner.
require_once dirname(__DIR__) . '/cron/crm_reminders.php';

// Logging
logApp("Cron", "info", "Cron executed successfully");
