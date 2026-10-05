<?php
require_once "includes/inc_all_admin.php";
require_once "includes/admin_directory.php";

renderAdminDirectory('Maintenance', 'Check scheduled work, review activity, and manage recovery.', 'fa-tools', [
    'jobs' => [
        'title' => 'Jobs & mail', 'icon' => 'fa-clock',
        'description' => 'See what is scheduled and what is waiting to send.',
        'items' => [
            ['Scheduled jobs', 'Review cron jobs and their status.', 'cron.php', 'fa-clock'],
            ['Mail queue', 'Review outgoing messages.', 'mail_queue.php', 'fa-mail-bulk'],
        ],
    ],
    'activity' => [
        'title' => 'Activity & diagnostics', 'icon' => 'fa-history',
        'description' => 'Inspect recent events and troubleshoot problems.',
        'items' => [
            ['Email log', 'Review sent email activity.', 'email_log.php', 'fa-envelope-open-text'],
            ['Audit log', 'Review recorded user actions.', 'audit_log.php', 'fa-history'],
            ['Audit trail', 'Tamper-evident record of sign-ins, setting and user changes, credential reveals and automation runs; filter and export.', 'audit_trail.php', 'fa-fingerprint'],
            ['App log', 'Review application events.', 'app_log.php', 'fa-list-alt'],
            ['Debug', 'Inspect diagnostic information.', 'debug.php', 'fa-bug'],
        ],
    ],
    'server' => [
        'title' => 'Server', 'icon' => 'fa-server',
        'description' => 'Check the health of this server and find the commands for root-only tasks.',
        'items' => [
            ['Server status & tasks', 'Disk, backups, jobs, Redis, and copy-ready server commands.', 'server_tasks.php', 'fa-server'],
            ['Redis', 'Connection, memory and cache controls.', 'settings_redis.php', 'fa-bolt'],
        ],
    ],
    'recovery' => [
        'title' => 'Recovery & updates', 'icon' => 'fa-cloud-upload-alt',
        'description' => 'Protect data and keep the installation current.',
        'items' => [
            ['Backups', 'Create and review backups.', 'backup.php', 'fa-cloud-upload-alt'],
            ['Credential restore', 'Restore encrypted credentials.', 'credential_restore.php', 'fa-key'],
            ['Update', 'Apply application and database updates.', 'update.php', 'fa-download'],
        ],
    ],
]);

require_once "../includes/footer.php";
