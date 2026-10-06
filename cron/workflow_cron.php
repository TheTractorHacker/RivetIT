<?php

/*
 * RivetIT - employee workflow reminders.
 *
 * Notifies the assignee (or whoever started the run, or every agent) of workflow tasks that are due within a day or overdue,
 * once per task per state (due soon, then overdue), and hands automated tasks that were interrupted mid-run to a person.
 * In-app notifications only: it sends no email, so it is safe to run on any install. Nothing to switch on: with no due dates
 * on any template task it finds nothing to do.
 *
 * Schedule (installed by deploy/install.sh): every 15 minutes.
 *   *\/15 * * * * www-data /usr/bin/php /var/www/<app>/cron/workflow_cron.php
 * Must run as www-data (config.php is www-data 0640). Prints one line per run.
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
// Only one copy at a time (Redis lock through RivetCore; skipped if Redis is down).
require_once dirname(__DIR__) . '/includes/redis_guards.php';
rivetCronGuard('workflow_cron', 600);

require_once "../vendor/autoload.php";

$workflow_cron_gateway = new \ITFlow\Workflow\LiveActionGateway($mysqli);
$workflow_cron_result = (new \ITFlow\Workflow\ReminderService($mysqli, $workflow_cron_gateway))->run();

echo "Workflow reminders: {$workflow_cron_result['due_soon']} due soon, {$workflow_cron_result['overdue']} overdue, {$workflow_cron_result['recovered']} interrupted action(s) handed to a person.\n";
