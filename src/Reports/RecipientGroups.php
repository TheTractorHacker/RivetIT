<?php

namespace ITFlow\Reports;

require_once dirname(__DIR__, 2) . '/includes/report_schedule_policy.php';

/**
 * Report recipient groups and schedule targets (DB 2.6.224).
 *
 * A group is a named list of addresses ("Executives", "Service desk leads"). A schedule can have several targets, each a group plus the
 * saved view that group receives, so one schedule sends the executive summary to one group and the technician view to another.
 * Addresses are checked against the scheduled-report recipient policy (active agents or administrator-approved addresses) when the group is
 * saved, and cron checks them again every time it sends.
 */
final class RecipientGroups
{
    public const MAX_RECIPIENTS = 50;

    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)));
        return mb_substr($name, 0, 100);
    }

    /** @return int|string new group id, or an error message */
    public static function create(\mysqli $db, int $userId, string $name, string $recipientsText, array $staff, array $allowlist)
    {
        $name = self::cleanName($name);
        if ($name === '') {
            return 'Give the group a name.';
        }
        [$ok, $refused] = report_schedule_filter_recipients($recipientsText, $staff, $allowlist);
        if ($refused) {
            return 'Not an agent address or an administrator-approved recipient: ' . implode(', ', $refused) . '.';
        }
        if ($ok === []) {
            return 'Enter at least one valid email address.';
        }
        if (count($ok) > self::MAX_RECIPIENTS) {
            return 'A group can have at most ' . self::MAX_RECIPIENTS . ' recipients.';
        }
        $list = implode(', ', $ok);
        $stmt = mysqli_prepare($db, "INSERT INTO report_recipient_groups (group_name, group_recipients, group_created_by) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'ssi', $name, $list, $userId);
        try {
            if (!mysqli_stmt_execute($stmt)) {
                return mysqli_errno($db) === 1062 ? 'A group with that name already exists.' : 'Could not save the group.';
            }
        } catch (\mysqli_sql_exception $e) { // PHP 8.1+ throws by default; 1062 = duplicate group name
            return $e->getCode() === 1062 ? 'A group with that name already exists.' : 'Could not save the group.';
        }
        return (int) mysqli_insert_id($db);
    }

    public static function get(\mysqli $db, int $id): ?array
    {
        return mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM report_recipient_groups WHERE group_id = " . (int) $id)) ?: null;
    }

    public static function all(\mysqli $db): array
    {
        $out = [];
        try {
            $res = mysqli_query($db, "SELECT g.*, u.user_name AS creator_name, (SELECT COUNT(*) FROM report_schedule_targets t WHERE t.target_group_id = g.group_id) AS used_by
                FROM report_recipient_groups g LEFT JOIN users u ON u.user_id = g.group_created_by ORDER BY g.group_name");
        } catch (\mysqli_sql_exception $e) {
            return []; // DB 2.6.224 not applied yet
        }
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $out[] = $r;
        }
        return $out;
    }

    /** Only the creator or an administrator may delete a group; the targets that used it go with it (a schedule keeps its other targets). */
    public static function delete(\mysqli $db, int $id, int $userId, bool $isAdmin): bool
    {
        $g = self::get($db, $id);
        if (!$g || !($isAdmin || (int) $g['group_created_by'] === $userId)) {
            return false;
        }
        mysqli_query($db, "DELETE FROM report_recipient_groups WHERE group_id = " . (int) $id);
        return true;
    }

    /** Replace a schedule's targets. $targets: group id => saved view id (0/null = the schedule's own report). Unknown groups are skipped. */
    public static function setTargets(\mysqli $db, int $scheduleId, array $targets): int
    {
        mysqli_query($db, "DELETE FROM report_schedule_targets WHERE target_schedule_id = " . (int) $scheduleId);
        $n = 0;
        foreach ($targets as $gid => $viewId) {
            $gid = (int) $gid;
            if (!self::get($db, $gid)) {
                continue;
            }
            $view = (int) $viewId > 0 ? (int) $viewId : null;
            $stmt = mysqli_prepare($db, "INSERT INTO report_schedule_targets (target_schedule_id, target_group_id, target_saved_report_id) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'iii', $scheduleId, $gid, $view);
            if (mysqli_stmt_execute($stmt)) {
                $n++;
            }
        }
        return $n;
    }
}
