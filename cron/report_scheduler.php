<?php

/*
 * ###############################################################################################################
 *  REPORT SCHEDULER  (cron/report_scheduler.php)
 * ###############################################################################################################
 *
 *  Walks active rows in report_schedules, and for each schedule that is DUE (never sent, or last sent longer
 *  ago than its frequency), renders a headline summary of the report to an HTML email via
 *  report_render_email_html() and enqueues one message per recipient with addToMailQueue(). The mail_queue
 *  cron worker then delivers them. schedule_last_sent is stamped after enqueue so the same run isn't repeated.
 *
 *  Report figures come from the same getXxxReport() helpers the web pages use, so an emailed summary
 *  reconciles with the interactive report.
 *
 *  Included daily from cron/cron.php. Also runnable stand-alone from the CLI for testing:
 *      php cron/report_scheduler.php
 */

// Stand-alone bootstrap (skipped when included from cron.php, which already has $mysqli + config).
$scheduler_standalone = !isset($mysqli);
if ($scheduler_standalone) {
    chdir(dirname(__FILE__));

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";

    // Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
    require_once dirname(__DIR__) . '/includes/redis_guards.php';
    rivetCronGuard('report_scheduler', 600);

    // Load the from-name/email + currency the render helper and mail queue expect.
    $settings_row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1"));
    if ($settings_row) {
        $company_name                = $settings_row['company_name'] ?? APP_NAME;
        $company_currency            = $settings_row['company_currency'] ?? 'USD';
        $config_mail_from_email      = $settings_row['config_mail_from_email'] ?? '';
        $config_mail_from_name       = $settings_row['config_mail_from_name'] ?? $company_name;
        if (!isset($currency_format)) {
            $currency_format = @numfmt_create($settings_row['company_locale'] ?? 'en_US', NumberFormatter::CURRENCY);
        }
    }
}

// Resolve a sensible From address/name (fall back to company email if the mail-from isn't configured).
$scheduler_from_email = !empty($config_mail_from_email) ? $config_mail_from_email : ($company_email ?? '');
$scheduler_from_name  = !empty($config_mail_from_name)  ? $config_mail_from_name  : ($company_name ?? APP_NAME);

require_once dirname(__DIR__) . '/includes/report_schedule_policy.php';

// Recipient policy (pentest IT-8): active agents plus the administrator-approved list, re-checked on every send.
$scheduler_staff_emails = report_schedule_staff_emails($mysqli);
$scheduler_allowlist    = report_schedule_allowlist($mysqli);

// Frequency -> strtotime interval used to decide whether a schedule is due again.
$scheduler_intervals = [
    'daily'   => '+1 day',
    'weekly'  => '+7 day',
    'monthly' => '+1 month',
];

$scheduler_now    = date('Y-m-d H:i:s');
$scheduler_queued = 0;
$scheduler_sent_schedules = 0;

$res = mysqli_query($mysqli, "SELECT * FROM report_schedules WHERE schedule_active = 1");
while ($sched = mysqli_fetch_assoc($res)) {
    $schedule_id = intval($sched['schedule_id']);
    $report_key  = $sched['schedule_report'];
    $frequency   = $sched['schedule_frequency'];
    $recipients  = (string) $sched['schedule_recipients'];
    $last_sent   = $sched['schedule_last_sent'];

    // Due check: never sent => due now; otherwise due once now() has passed last_sent + interval.
    if (!empty($last_sent) && $last_sent !== '0000-00-00 00:00:00') {
        $interval  = $scheduler_intervals[$frequency] ?? '+1 day';
        $due_after = date('Y-m-d H:i:s', strtotime($last_sent . ' ' . $interval));
        if ($due_after > $scheduler_now) {
            continue; // not due yet
        }
    }

    // The schedule's owner must still be an active agent with access to the data in this report. Schedules created
    // before owners were recorded (owner NULL) are only sent for non-financial reports.
    $owner_id = intval($sched['schedule_owner_user_id'] ?? 0);
    $needs_owner = report_schedule_required_module($report_key) !== null;
    if (($owner_id > 0 || $needs_owner) && !report_schedule_owner_may_send($mysqli, $owner_id, $report_key)) {
        if (function_exists('logApp')) {
            logApp("Cron", "warning", "Report scheduler skipped schedule #$schedule_id: owner is missing, archived or lacks access to '$report_key'");
        }
        continue;
    }

    $rendered = report_render_email_html($mysqli, $report_key);
    if ($rendered === null) {
        // Unknown/removed report key — skip without stamping so a fixed key can resume later.
        if (function_exists('logApp')) {
            logApp("Cron", "warning", "Report scheduler skipped schedule #$schedule_id: unknown report '$report_key'");
        }
        continue;
    }

    // Recipients may be comma/semicolon/whitespace separated.
    [$emails, $refused] = report_schedule_filter_recipients($recipients, $scheduler_staff_emails, $scheduler_allowlist);
    if (!empty($refused) && function_exists('logApp')) {
        logApp("Cron", "warning", "Report scheduler dropped " . count($refused) . " recipient(s) of schedule #$schedule_id that are not agent or approved addresses");
    }
    $mail = [];
    foreach ($emails as $to) {
        $mail[] = [
            'from'           => $scheduler_from_email,
            'from_name'      => $scheduler_from_name,
            'recipient'      => $to,
            'recipient_name' => $to,
            'subject'        => $rendered['subject'],
            'body'           => $rendered['html'],
        ];
    }

    if (!empty($mail)) {
        addToMailQueue($mail);
        $scheduler_queued += count($mail);
    }

    // Stamp as sent regardless of valid-recipient count so a schedule with a bad
    // recipient list doesn't re-render on every cron tick.
    mysqli_query($mysqli, "UPDATE report_schedules SET schedule_last_sent = NOW() WHERE schedule_id = $schedule_id");
    $scheduler_sent_schedules++;
}

if ($scheduler_sent_schedules > 0 && function_exists('logApp')) {
    logApp("Cron", "info", "Report scheduler processed $scheduler_sent_schedules due schedule(s), queued $scheduler_queued email(s)");
}

if ($scheduler_standalone && php_sapi_name() === 'cli') {
    echo "report_scheduler: processed $scheduler_sent_schedules due schedule(s), queued $scheduler_queued email(s)\n";
}
