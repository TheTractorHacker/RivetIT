<?php

namespace ITFlow\Reports\Queries;

use ITFlow\Reports\ReportScope;

/**
 * Asset lifecycle and warranty. Non-archived assets: warranty state (expired, expiring inside the window, no date),
 * age from the purchase date (install date when there is none) and the devices past a replacement age.
 * Department scope and the universal asset filters (department, location) arrive through ReportScope::clause().
 */
final class AssetLifecycleReport
{
    public static function data(\mysqli $db, int $windowDays = 90, int $ageYears = 5, ?string $today = null, int $limit = 300): array
    {
        $windowDays = max(1, min($windowDays, 3650));
        $ageYears   = max(1, min($ageYears, 50));
        $today = $today !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : date('Y-m-d');
        $where = "a.asset_archived_at IS NULL" . ReportScope::clause('a.asset_client_id');
        $born  = "COALESCE(a.asset_purchase_date, a.asset_install_date)";

        $r = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT COUNT(*) AS total,
                SUM(a.asset_warranty_expire IS NOT NULL AND a.asset_warranty_expire < '$today') AS expired,
                SUM(a.asset_warranty_expire >= '$today' AND a.asset_warranty_expire <= DATE_ADD('$today', INTERVAL $windowDays DAY)) AS expiring,
                SUM(a.asset_warranty_expire IS NULL) AS no_warranty,
                SUM($born IS NULL) AS no_purchase,
                SUM($born IS NOT NULL AND $born <= DATE_SUB('$today', INTERVAL $ageYears YEAR)) AS aged
             FROM assets a WHERE $where")) ?: [];
        $totals = ['assets' => (int) ($r['total'] ?? 0), 'warranty_expired' => (int) ($r['expired'] ?? 0), 'warranty_expiring' => (int) ($r['expiring'] ?? 0),
            'no_warranty_date' => (int) ($r['no_warranty'] ?? 0), 'no_purchase_date' => (int) ($r['no_purchase'] ?? 0), 'aged' => (int) ($r['aged'] ?? 0)];

        $byType = [];
        $res = mysqli_query($db,
            "SELECT a.asset_type AS label, COUNT(*) AS cnt, AVG(TIMESTAMPDIFF(MONTH, $born, '$today')) / 12 AS avg_years,
                SUM(a.asset_warranty_expire IS NOT NULL AND a.asset_warranty_expire < '$today') AS expired,
                SUM($born IS NOT NULL AND $born <= DATE_SUB('$today', INTERVAL $ageYears YEAR)) AS aged
             FROM assets a WHERE $where GROUP BY a.asset_type ORDER BY cnt DESC LIMIT 30");
        while ($res && ($x = mysqli_fetch_assoc($res))) {
            $byType[] = ['label' => (string) $x['label'], 'count' => (int) $x['cnt'], 'avg_years' => $x['avg_years'] !== null ? round((float) $x['avg_years'], 1) : null, 'expired' => (int) $x['expired'], 'aged' => (int) $x['aged']];
        }

        $byClient = [];
        $res = mysqli_query($db,
            "SELECT COALESCE(c.client_name, 'No department') AS label, COUNT(*) AS cnt,
                SUM(a.asset_warranty_expire IS NOT NULL AND a.asset_warranty_expire < '$today') AS expired,
                SUM(a.asset_warranty_expire >= '$today' AND a.asset_warranty_expire <= DATE_ADD('$today', INTERVAL $windowDays DAY)) AS expiring,
                SUM($born IS NOT NULL AND $born <= DATE_SUB('$today', INTERVAL $ageYears YEAR)) AS aged
             FROM assets a LEFT JOIN clients c ON c.client_id = a.asset_client_id WHERE $where GROUP BY a.asset_client_id, c.client_name ORDER BY SUM(a.asset_warranty_expire <= DATE_ADD('$today', INTERVAL $windowDays DAY)) DESC, cnt DESC LIMIT 25");
        while ($res && ($x = mysqli_fetch_assoc($res))) {
            $byClient[] = ['label' => (string) $x['label'], 'count' => (int) $x['cnt'], 'expired' => (int) $x['expired'], 'expiring' => (int) $x['expiring'], 'aged' => (int) $x['aged']];
        }

        $list = [];
        $res = mysqli_query($db,
            "SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_make, a.asset_model, a.asset_serial, a.asset_status, a.asset_purchase_date, a.asset_install_date,
                    a.asset_warranty_expire, c.client_name, l.location_name,
                    DATEDIFF(a.asset_warranty_expire, '$today') AS days_left,
                    TIMESTAMPDIFF(MONTH, $born, '$today') / 12 AS age_years
             FROM assets a LEFT JOIN clients c ON c.client_id = a.asset_client_id LEFT JOIN locations l ON l.location_id = a.asset_location_id
             WHERE $where AND ((a.asset_warranty_expire IS NOT NULL AND a.asset_warranty_expire <= DATE_ADD('$today', INTERVAL $windowDays DAY)) OR ($born IS NOT NULL AND $born <= DATE_SUB('$today', INTERVAL $ageYears YEAR)))
             ORDER BY (a.asset_warranty_expire IS NULL), a.asset_warranty_expire ASC LIMIT " . max(1, min($limit, 2000)));
        while ($res && ($x = mysqli_fetch_assoc($res))) {
            $list[] = $x;
        }
        return ['totals' => $totals, 'by_type' => $byType, 'by_client' => $byClient, 'assets' => $list, 'window_days' => $windowDays, 'age_years' => $ageYears];
    }
}
