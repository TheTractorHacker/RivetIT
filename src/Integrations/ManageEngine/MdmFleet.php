<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Counts of the MDM-managed fleet for pages and dashboards: one place for the definitions (compliant / not compliant / lost / not seen / needs review),
 * so the Mobile devices page, the asset list and any dashboard widget agree. Read only; every query is a single indexed aggregate.
 */
final class MdmFleet
{
    /** SQL fragments over asset_mdm_links aliased "l". */
    public const NONCOMPLIANT = '(l.passcode_compliant = 0 OR l.is_rooted = 1)';

    public static function staleSql(int $days): string
    {
        return '(l.last_contact_at IS NOT NULL AND l.last_contact_at < DATE_SUB(NOW(), INTERVAL ' . max(1, $days) . ' DAY))';
    }

    /**
     * @param string $scopeSql extra " AND a.asset_client_id IN (...)" for a department-scoped viewer
     * @return array{total:int,compliant:int,noncompliant:int,stale:int,lost:int,unmatched:int,needs_mapping:int}
     */
    public static function counts(\mysqli $db, int $integrationId, int $staleDays, string $scopeSql = '', bool $includeUnmatched = true): array
    {
        $out = ['total' => 0, 'compliant' => 0, 'noncompliant' => 0, 'stale' => 0, 'lost' => 0, 'unmatched' => 0, 'needs_mapping' => 0];
        if ($integrationId < 1) {
            return $out;
        }
        $nc = self::NONCOMPLIANT;
        $stale = self::staleSql($staleDays);
        $res = mysqli_query($db, "SELECT COUNT(*) AS total, COALESCE(SUM(NOT $nc AND l.passcode_compliant = 1), 0) AS compliant, COALESCE(SUM($nc), 0) AS noncompliant,
                COALESCE(SUM($stale), 0) AS stale, COALESCE(SUM(l.is_lost_mode = 1), 0) AS lost
            FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id
            WHERE l.integration_id = $integrationId AND a.asset_archived_at IS NULL $scopeSql");
        if ($res && ($r = mysqli_fetch_assoc($res))) {
            foreach (['total', 'compliant', 'noncompliant', 'stale', 'lost'] as $k) {
                $out[$k] = (int) $r[$k];
            }
        }
        if ($includeUnmatched) {
            $res = mysqli_query($db, "SELECT COUNT(*) AS n, COALESCE(SUM(reason = 'needs_mapping'), 0) AS m FROM mdm_unmatched_devices WHERE integration_id = $integrationId AND dismissed = 0");
            if ($res && ($r = mysqli_fetch_assoc($res))) {
                $out['unmatched'] = (int) $r['n'];
                $out['needs_mapping'] = (int) $r['m'];
            }
        }

        return $out;
    }
}
