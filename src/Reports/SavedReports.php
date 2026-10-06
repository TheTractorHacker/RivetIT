<?php

namespace ITFlow\Reports;

/**
 * Saved report views (saved_reports): a named, optionally shared set of whitelisted filters for one report.
 * Ownership rules: anyone with Reporting access may save a view for themself; only the owner (or an administrator)
 * may rename, share/unshare or delete it; a shared view is visible to other users but cannot be changed by them.
 * Parameters are filtered through ReportCatalog on the way in AND on the way out, so a hand-edited row cannot
 * inject anything into a report URL or a headless run.
 */
final class SavedReports
{
    public const MAX_NAME = 100;
    public const MAX_PER_USER = 200;

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_substr($name, 0, self::MAX_NAME);
    }

    /** @return int|string new id, or an error message */
    public static function create(\mysqli $db, int $userId, string $key, string $name, array $rawParams, bool $shared)
    {
        if (!ReportCatalog::exists($key)) {
            return 'Unknown report.';
        }
        $name = self::cleanName($name);
        if ($name === '') {
            return 'Give the saved view a name.';
        }
        $count = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM saved_reports WHERE saved_report_user_id = $userId"))[0] ?? 0);
        if ($count >= self::MAX_PER_USER) {
            return 'You have reached the saved view limit. Delete some first.';
        }
        $params = json_encode(ReportCatalog::sanitizeParams($key, $rawParams), JSON_UNESCAPED_SLASHES);
        $stmt = mysqli_prepare($db, "INSERT INTO saved_reports (saved_report_user_id, saved_report_key, saved_report_name, saved_report_params, saved_report_shared, saved_report_updated_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $sharedInt = $shared ? 1 : 0;
        mysqli_stmt_bind_param($stmt, 'isssi', $userId, $key, $name, $params, $sharedInt);
        if (!mysqli_stmt_execute($stmt)) {
            return 'Could not save the view.';
        }
        return (int) mysqli_insert_id($db);
    }

    public static function get(\mysqli $db, int $id): ?array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT * FROM saved_reports WHERE saved_report_id = " . (int) $id));
        if (!$row) {
            return null;
        }
        $decoded = json_decode((string) $row['saved_report_params'], true);
        $row['params'] = ReportCatalog::sanitizeParams($row['saved_report_key'], is_array($decoded) ? $decoded : []);
        return $row;
    }

    /** May this user see/use the view (own, or shared)? */
    public static function canView(array $row, int $userId): bool
    {
        return (int) $row['saved_report_user_id'] === $userId || (int) $row['saved_report_shared'] === 1;
    }

    public static function canEdit(array $row, int $userId, bool $isAdmin): bool
    {
        return (int) $row['saved_report_user_id'] === $userId || $isAdmin;
    }

    /**
     * Views for one report (or all reports with $key = null) that this user may open: their own plus shared ones.
     * $canAccess(module) lets the caller hide views of reports the user's role cannot read.
     */
    public static function listFor(\mysqli $db, int $userId, ?string $key = null, ?callable $canAccess = null): array
    {
        $where = "(saved_report_user_id = $userId OR saved_report_shared = 1)";
        if ($key !== null) {
            $where .= " AND saved_report_key = '" . mysqli_real_escape_string($db, $key) . "'";
        }
        $res = mysqli_query($db, "SELECT sr.*, u.user_name FROM saved_reports sr LEFT JOIN users u ON u.user_id = sr.saved_report_user_id WHERE $where ORDER BY (saved_report_user_id = $userId) DESC, saved_report_name ASC");
        $out = [];
        while ($row = mysqli_fetch_assoc($res)) {
            if (!ReportCatalog::exists($row['saved_report_key'])) {
                continue;
            }
            if ($canAccess !== null && !$canAccess((string) ReportCatalog::module($row['saved_report_key']))) {
                continue;
            }
            $decoded = json_decode((string) $row['saved_report_params'], true);
            $row['params'] = ReportCatalog::sanitizeParams($row['saved_report_key'], is_array($decoded) ? $decoded : []);
            $out[] = $row;
        }
        return $out;
    }

    public static function update(\mysqli $db, int $id, int $userId, bool $isAdmin, string $name, bool $shared): bool
    {
        $row = self::get($db, $id);
        $name = self::cleanName($name);
        if (!$row || $name === '' || !self::canEdit($row, $userId, $isAdmin)) {
            return false;
        }
        $stmt = mysqli_prepare($db, "UPDATE saved_reports SET saved_report_name = ?, saved_report_shared = ?, saved_report_updated_at = NOW() WHERE saved_report_id = ?");
        $sharedInt = $shared ? 1 : 0;
        mysqli_stmt_bind_param($stmt, 'sii', $name, $sharedInt, $id);
        return mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) >= 0;
    }

    public static function delete(\mysqli $db, int $id, int $userId, bool $isAdmin): bool
    {
        $row = self::get($db, $id);
        if (!$row || !self::canEdit($row, $userId, $isAdmin)) {
            return false;
        }
        mysqli_query($db, "DELETE FROM saved_reports WHERE saved_report_id = " . (int) $id);
        // A schedule that pointed at the view keeps running as the plain report rather than silently emailing nothing.
        mysqli_query($db, "UPDATE report_schedules SET schedule_saved_report_id = NULL WHERE schedule_saved_report_id = " . (int) $id);
        return true;
    }
}
