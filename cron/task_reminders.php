<?php
/*
 * task_reminders.php - project task reminder worker (DB 2.6.209)
 *
 * Finds open project tasks whose reminder time (tasks.task_reminder_at) has passed and that have not been reminded yet, and creates an
 * in-app notification (plus the app's usual push) for everyone on the task, or for the administrators when nobody is assigned. Each
 * task is claimed with task_reminder_sent_at before it is sent, so it fires once, and overlapping runs cannot both send it.
 * It sends no email.
 *
 * WIRING: required from cron/cron.php on its normal cadence. Also runnable standalone from the CLI:  php cron/task_reminders.php
 * The bootstrap below only loads config/functions when they are not already present, so it is safe to require_once from cron.php.
 */

$task_reminders_standalone = !isset($mysqli);

if ($task_reminders_standalone) {
    chdir(dirname(__FILE__));

    if (php_sapi_name() !== 'cli') {
        die("This script must be run from the command line.\n");
    }

    require_once "../config.php";
    require_once "../includes/inc_set_timezone.php";
    require_once "../functions.php";
    require_once dirname(__DIR__) . '/includes/redis_guards.php';
    rivetCronGuard('task_reminders', 600);
}

require_once dirname(__DIR__) . '/includes/project_tasks.php';

$task_reminders_sent = projectTaskProcessReminders($mysqli);

if ($task_reminders_standalone) {
    echo "Task reminders sent: $task_reminders_sent\n";
}
