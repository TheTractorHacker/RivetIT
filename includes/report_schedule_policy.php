<?php

/*
 * Who may schedule an emailed report, and who may receive it (pentest IT-8).
 *
 * A scheduled report is rendered by cron with company-wide queries and mailed to a free-text recipient list, so the
 * checks the on-screen report pages make (module permission) have to be repeated at schedule time and again at send
 * time, and external addresses must be approved by an administrator. The functions up to report_schedule_filter_recipients()
 * are pure (no database) so tests/report_schedule_policy.php can exercise them.
 */

/**
 * The module whose access the on-screen version of this report requires, beyond module_reporting.
 * Financial totals (income, expenses, AR aging, MRR) need module_financial; the rest only need Reporting.
 */
function report_schedule_required_module($report_key): ?string
{
    static $financial = ['income_summary', 'expense_summary', 'clients_with_balance', 'mrr'];
    return in_array((string) $report_key, $financial, true) ? 'module_financial' : null;
}

/**
 * Parse a comma / semicolon / whitespace separated address list into unique, lower-cased, valid addresses.
 */
function report_schedule_parse_recipients($text): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($e)] = true;
        }
    }
    return array_keys($out);
}

/**
 * Parse the administrator's approved-recipient list. Entries are full addresses (a@b.com) or whole domains
 * written with a leading "@" (@b.com). Anything else is dropped.
 */
function report_schedule_parse_allowlist($text): array
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $e) {
        $e = strtolower($e);
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) {
            $out[$e] = true;
        } elseif (preg_match('/^@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $e)) {
            $out[$e] = true;
        }
    }
    return array_keys($out);
}

/**
 * Whether $email may receive scheduled reports: it is an active staff address, or the administrator approved it
 * (exact address or "@domain" entry).
 *
 * @param string[] $staff_emails   lower-cased addresses of active agents
 * @param string[] $allowlist      output of report_schedule_parse_allowlist()
 */
function report_schedule_recipient_allowed($email, array $staff_emails, array $allowlist): bool
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    if (in_array($email, $staff_emails, true) || in_array($email, $allowlist, true)) {
        return true;
    }
    $at = strrpos($email, '@');
    return $at !== false && in_array(substr($email, $at), $allowlist, true);
}

/**
 * Split a recipient list into [allowed, refused] addresses.
 *
 * @return array{0: string[], 1: string[]}
 */
function report_schedule_filter_recipients($recipients_text, array $staff_emails, array $allowlist): array
{
    $allowed = [];
    $refused = [];
    foreach (report_schedule_parse_recipients($recipients_text) as $e) {
        if (report_schedule_recipient_allowed($e, $staff_emails, $allowlist)) {
            $allowed[] = $e;
        } else {
            $refused[] = $e;
        }
    }
    return [$allowed, $refused];
}

/** Lower-cased addresses of active, non-archived agents (the "internal" recipients). */
function report_schedule_staff_emails($mysqli): array
{
    $out = [];
    $res = mysqli_query($mysqli, "SELECT user_email FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL");
    while ($res && ($row = mysqli_fetch_assoc($res))) {
        $out[] = strtolower(trim((string) $row['user_email']));
    }
    return $out;
}

/** The administrator-approved external recipient list from settings (empty when unset or the column is missing). */
function report_schedule_allowlist($mysqli): array
{
    $res = @mysqli_query($mysqli, "SELECT config_report_schedule_allowed_recipients AS v FROM settings WHERE company_id = 1 LIMIT 1");
    $row = $res ? mysqli_fetch_assoc($res) : null;
    return report_schedule_parse_allowlist($row['v'] ?? '');
}

/**
 * Whether a user may (still) receive/own a schedule of this report: an active agent whose role is an administrator,
 * or has module_reporting and, for financial reports, module_financial (read or better). Used by cron so a schedule
 * stops when its owner is archived or loses access.
 */
function report_schedule_owner_may_send($mysqli, $owner_user_id, $report_key): bool
{
    $owner_user_id = intval($owner_user_id);
    if ($owner_user_id <= 0) {
        return false;
    }
    $res = mysqli_query($mysqli,
        "SELECT user_role_id, IFNULL(role_is_admin, 0) AS is_admin
         FROM users LEFT JOIN user_roles ON user_roles.role_id = users.user_role_id
         WHERE user_id = $owner_user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL LIMIT 1");
    $user = $res ? mysqli_fetch_assoc($res) : null;
    if (!$user) {
        return false;
    }
    if (intval($user['is_admin']) === 1) {
        return true;
    }
    $role_id = intval($user['user_role_id']);
    $needed = ['module_reporting'];
    if (($extra = report_schedule_required_module($report_key)) !== null) {
        $needed[] = $extra;
    }
    foreach ($needed as $module) {
        $module = mysqli_real_escape_string($mysqli, $module);
        $p = mysqli_query($mysqli,
            "SELECT p.user_role_permission_level AS lvl FROM modules m
             JOIN user_role_permissions p ON p.module_id = m.module_id
             WHERE m.module_name = '$module' AND p.user_role_id = $role_id LIMIT 1");
        $row = $p ? mysqli_fetch_assoc($p) : null;
        if (!$row || intval($row['lvl']) < 1) {
            return false;
        }
    }
    return true;
}
