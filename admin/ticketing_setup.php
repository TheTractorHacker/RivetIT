<?php
require_once "includes/inc_all_admin.php";
require_once "includes/admin_directory.php";

renderAdminDirectory('Ticketing', 'Set up ticket handling, mail intake, and service targets.', 'fa-life-ring', [
    'tickets' => [
        'title' => 'Tickets', 'icon' => 'fa-life-ring',
        'description' => 'Control ticket labels and automated actions.',
        'items' => [
            ['Ticket statuses', 'Set the stages tickets move through.', 'ticket_status.php', 'fa-info-circle'],
            ['Labor types', 'Classify time spent on tickets.', 'labor_type.php', 'fa-clock'],
            ['Ticket automation', 'Configure automatic ticket actions.', 'ticket_automation.php', 'fa-robot'],
        ],
    ],
    'mail' => [
        'title' => 'Email intake', 'icon' => 'fa-inbox',
        'description' => 'Manage incoming support mail.',
        'items' => [
            ['Mailboxes', 'Connect inboxes used for support.', 'mailbox.php', 'fa-inbox'],
            ['Mail requests', 'Review messages awaiting a ticket.', 'mail_requests.php', 'fa-envelope-open-text'],
        ],
    ],
    'service' => [
        'title' => 'Service levels', 'icon' => 'fa-stopwatch',
        'description' => 'Define response targets and working time.',
        'items' => [
            ['SLA policies', 'Set response and resolution targets.', 'sla_policies.php', 'fa-stopwatch'],
            ['Business hours', 'Set when service time is counted.', 'sla_calendars.php', 'fa-business-time'],
            ['Holidays', 'Exclude non-working days.', 'holidays.php', 'fa-calendar-day'],
        ],
    ],
]);

require_once "../includes/footer.php";
