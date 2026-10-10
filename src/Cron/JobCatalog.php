<?php

namespace ITFlow\Cron;

use RivetCore\Cron\JobCatalog as CoreJobCatalog;

/**
 * Plain-language facts about each cron script, and whether the admin UI may start it. A script that sends
 * real email, writes to outside systems, or needs arguments is not startable from the UI: it keeps running
 * on its schedule, and the reason is shown instead of a button.
 *
 * The job list is RivetIT's own data and stays here; the lookup logic lives in RivetCore\Cron\JobCatalog.
 */
final class JobCatalog
{
    /** @return array<string, array{label:string, description:string, run_now:bool, note:string, dir?:string}> keyed by script file name; dir defaults to cron */
    public static function all(): array
    {
        return [
            'cron.php' => ['label' => 'Main cron', 'description' => 'Recurring tickets and invoices, overdue notices, reminders and housekeeping.', 'run_now' => false, 'note' => 'Has its own Run Now button above and can send real email.'],
            'mail_queue.php' => ['label' => 'Outgoing mail queue', 'description' => 'Sends queued email.', 'run_now' => false, 'note' => 'Sends real email.'],
            'ticket_email_parser.php' => ['label' => 'Ticket email parser', 'description' => 'Reads the support mailbox and turns messages into tickets and replies.', 'run_now' => false, 'note' => 'Reads the live mailbox.'],
            'outlook_schedule_sync.php' => ['label' => 'Outlook calendar sync', 'description' => 'Keeps scheduled tickets and Outlook calendars in step.', 'run_now' => false, 'note' => 'Writes to Outlook calendars.'],
            'crm_reminders.php' => ['label' => 'CRM reminders', 'description' => 'Sends follow-up reminders.', 'run_now' => false, 'note' => 'Sends real email.'],
            'report_scheduler.php' => ['label' => 'Scheduled reports', 'description' => 'Emails reports on their schedules.', 'run_now' => false, 'note' => 'Sends real email.'],
            'accounting_sync.php' => ['label' => 'Accounting sync', 'description' => 'Syncs invoices and payments with the accounting system.', 'run_now' => false, 'note' => 'Writes to the accounting system.'],
            'accounting_sync_standalone.php' => ['label' => 'Accounting sync (standalone)', 'description' => 'Standalone version of the accounting sync.', 'run_now' => false, 'note' => 'Writes to the accounting system.'],
            'domain_refresher.php' => ['label' => 'Domain refresh', 'description' => 'Looks up domain expiry and DNS details and updates the records.', 'run_now' => true, 'note' => ''],
            'certificate_refresher.php' => ['label' => 'Certificate refresh', 'description' => 'Checks SSL certificates and updates their expiry dates.', 'run_now' => true, 'note' => ''],
            'backup_cron.php' => ['label' => 'Scheduled backup', 'description' => 'Builds an encrypted backup when one is due under your backup settings, and removes old ones.', 'run_now' => true, 'note' => 'Follows your backup schedule; it may report that nothing is due.'],
            'restore_drill.php' => ['label' => 'Restore drill', 'description' => 'Restores the newest backup into a scratch database, verifies it, drops it and records the result and restore time.', 'run_now' => true, 'note' => 'Does nothing until the drill is enabled (Admin > Backup > Restore drill); never touches live data.'],
            'metrics_collect.php' => ['label' => 'Device metrics collection', 'description' => 'Collects CPU, memory, disk and other device metrics from the RMM.', 'run_now' => true, 'note' => ''],
            'metrics_rollup.php' => ['label' => 'Device metrics roll-up', 'description' => 'Summarizes and prunes old device metrics.', 'run_now' => true, 'note' => ''],
            'integration_worker.php' => ['label' => 'Integration job worker', 'description' => 'Processes queued integration jobs.', 'run_now' => true, 'note' => ''],
            'odoo_sync_cron.php' => ['label' => 'Odoo directory sync', 'description' => 'Nightly employee directory sync from Odoo.', 'run_now' => true, 'note' => 'Runs once per day; a second run the same day does nothing.'],
            'workflow_cron.php' => ['label' => 'Employee workflow reminders', 'description' => 'Reminds assignees of employee workflow tasks that are due within a day or overdue (once per task per state) and hands interrupted automated tasks to a person.', 'run_now' => true, 'note' => 'In-app notifications only; it sends no email.'],
            'training_cron.php' => ['label' => 'Training daily tasks', 'description' => 'Verifies the training ledger, reconciles assignments and takes the compliance snapshot.', 'run_now' => true, 'note' => 'Runs once per day; a second run the same day does nothing.'],
            'training_kiosk_cron.php' => ['label' => 'Training kiosk upkeep', 'description' => 'Closes stale kiosk sessions and refreshes PIN state.', 'run_now' => true, 'note' => ''],
            'training_worker.php' => ['label' => 'Training worker', 'description' => 'Odoo, daily and discovery tasks for training.', 'run_now' => true, 'note' => 'Uses the task from its schedule line.'],
            'unifi_sync_cli.php' => ['label' => 'UniFi sync', 'description' => 'Pulls devices and clients from every enabled UniFi integration into assets.', 'run_now' => true, 'note' => 'Does nothing unless a UniFi integration is enabled.', 'dir' => 'scripts'],
        ];
    }

    /** Directory (under the app root) a catalog script lives in: cron or scripts. */
    public static function dir(string $scriptFile): string
    {
        return self::core()->dir($scriptFile);
    }

    public static function describe(string $scriptFile): array
    {
        return self::core()->describe($scriptFile);
    }

    /** Scripts that need arguments, so they can only run from a schedule line that supplies them. */
    public static function needsArguments(string $scriptFile): bool
    {
        return self::core()->needsArguments($scriptFile);
    }

    private static function core(): CoreJobCatalog
    {
        static $c = null;

        return $c ??= new CoreJobCatalog(self::all(), ['training_worker.php']);
    }
}
