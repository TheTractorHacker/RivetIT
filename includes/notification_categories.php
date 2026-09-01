<?php

/*
 * ITFlow - Mobile push notification categories
 * Maps the freeform notification "type" strings used by appNotify()/notifyUser()
 * into a small set of user-selectable categories for push targeting.
 */

function push_notification_categories(): array {
    return [
        'tickets' => [
            'label' => 'Tickets',
            'icon'  => 'fas fa-ticket-alt',
            'types' => ['Ticket', 'Recurring Ticket', 'Pending Tickets', 'Feedback', 'Automation'],
        ],
        'invoices' => [
            'label' => 'Invoices & Expenses',
            'icon'  => 'fas fa-file-invoice-dollar',
            'types' => ['Invoice', 'Invoice Paid', 'Invoice Overdue', 'Invoice Late Charge', 'Invoice Viewed', 'Recurring Sent', 'Expense', 'Expense Created'],
        ],
        'quotes' => [
            'label' => 'Quotes',
            'icon'  => 'fas fa-file-signature',
            'types' => ['Quote Accepted', 'Quote Declined', 'Quote File', 'Quote Viewed'],
        ],
        'expirations' => [
            'label' => 'Expirations',
            'icon'  => 'fas fa-hourglass-end',
            'types' => ['Domain Expiring', 'Certificate Expiring', 'Asset Warranty Expiring'],
        ],
        'backups' => [
            'label' => 'Backups',
            'icon'  => 'fas fa-database',
            'types' => ['Backup', 'Comet Backup'],
        ],
        'system' => [
            'label' => 'System & Admin',
            'icon'  => 'fas fa-cogs',
            'types' => ['Cron', 'Cron-Mail-Queue', 'Mail', 'Master Key', 'Settings', 'Update', 'Share Viewed', 'Integration'],
        ],
    ];
}

function push_category_for_type(string $type): ?string {
    static $lookup = null;
    if ($lookup === null) {
        $lookup = [];
        foreach (push_notification_categories() as $key => $cat) {
            foreach ($cat['types'] as $t) {
                $lookup[$t] = $key;
            }
        }
    }
    return $lookup[$type] ?? null;
}

// Categories globally allowed to push, per admin settings. Null/empty config = all allowed.
function push_globally_enabled_categories(): array {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_push_enabled_types FROM settings WHERE company_id = 1"));
    $raw = $row['config_push_enabled_types'] ?? null;
    if (!$raw) return array_keys(push_notification_categories());
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array_keys(push_notification_categories());
}

// Categories this user wants pushed, intersected with what's globally allowed. Null user config = all globally-allowed categories.
function push_enabled_categories_for_user(int $user_id): array {
    global $mysqli;
    $global = push_globally_enabled_categories();
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_config_push_types FROM user_settings WHERE user_id = " . intval($user_id)));
    $raw = $row['user_config_push_types'] ?? null;
    if (!$raw) return $global;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $global;
    return array_values(array_intersect($decoded, $global));
}

// Should a notification of $type be pushed to $user_id's mobile devices?
function push_allowed_for_user(int $user_id, string $type): bool {
    $category = push_category_for_type($type);
    if ($category === null) return true; // unmapped types default to allowed
    return in_array($category, push_enabled_categories_for_user($user_id), true);
}
