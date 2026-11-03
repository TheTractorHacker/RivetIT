<?php
require_once "includes/inc_all_admin.php";
require_once "includes/admin_directory.php";

// Read-only status chips so the page answers "what is set up?" at a glance.
$ts_count = static function (string $sql) use ($mysqli): int {
    $r = mysqli_query($mysqli, $sql);
    return $r ? (int) (mysqli_fetch_row($r)[0] ?? 0) : 0;
};
$ts_mail_attention = \ITFlow\Mail\MailHealth::summary($mysqli)['attention'];
$ts_sla = $ts_count("SELECT COUNT(*) FROM sla_policies WHERE policy_archived_at IS NULL");
$ts_holidays = $ts_count("SELECT COUNT(*) FROM holidays");
$ts_plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');

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
            ['Mailboxes', 'Connect inboxes used for support.', 'mailbox.php', 'fa-inbox', true, null, $ts_mail_attention > 0 ? ['warn', $ts_mail_attention . ($ts_mail_attention === 1 ? ' needs' : ' need') . ' attention'] : ['ok', 'Healthy']],
            ['Mail requests', 'Review messages awaiting a ticket.', 'mail_requests.php', 'fa-envelope-open-text'],
        ],
    ],
    'service' => [
        'title' => 'Service levels', 'icon' => 'fa-stopwatch',
        'description' => 'Define response targets and working time.',
        'items' => [
            ['SLA policies', 'Set response and resolution targets.', 'sla_policies.php', 'fa-stopwatch', true, null, $ts_sla > 0 ? ['ok', $ts_sla . ($ts_sla === 1 ? ' policy' : ' policies')] : ['warn', 'No SLA policy yet']],
            ['Business hours', 'Set when service time is counted.', 'sla_calendars.php', 'fa-business-time'],
            ['Holidays', 'Exclude non-working days.', 'holidays.php', 'fa-calendar-day', true, null, ['off', $ts_plural($ts_holidays, 'holiday')]],
        ],
    ],
]);

require_once "../includes/footer.php";
