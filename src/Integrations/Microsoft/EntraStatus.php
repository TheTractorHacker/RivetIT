<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * Read-only data for the Entra / Intune sync status page (admin/entra_status.php): per sync its switch, schedule, last real run, last preview
 * (dry run), counts, classified error and when the next scheduled run is due. No Graph calls, no writes. Every query is defensive: before
 * DB 2.6.225 / 2.6.226 the missing tables or columns simply give "never run".
 */
final class EntraStatus
{
    /** Words for GraphException codes (the same ones the settings page uses). */
    public const ERROR_LABELS = [
        'auth_failed' => 'Sign-in failed', 'consent_missing' => 'Admin consent missing', 'throttled' => 'Throttled by Microsoft',
        'unreachable' => 'Could not reach Microsoft', 'server_error' => 'Microsoft error', 'tenant_not_applicable' => 'Not available for this tenant',
        'bad_response' => 'Unexpected response', 'time_limit' => 'Time limit reached', 'delta_reset' => 'Delta token reset', 'other' => 'Error',
    ];

    /**
     * @return list<array<string,mixed>> one entry per sync: key, label, on (switch), schedule (words), last (run|null), preview (run|null), next (words), lastOk (run|null)
     *   run = [id, started (unix), finished (unix|null), status, mode, counts (words), error, error_label, trigger (cron|manual)]
     */
    public static function syncs(\mysqli $db, array $row, int $nowTs): array
    {
        $id = (int) ($row['microsoft_integration_id'] ?? 0);
        $dirInterval = max(15, (int) ($row['directory_cron_interval_min'] ?? 360));
        $exInterval = max(60, (int) ($row['extras_interval_min'] ?? 1440));
        $defs = [
            ['directory', 'Entra directory (people)', !empty($row['directory_sync_enabled']),
                empty($row['directory_cron_enabled']) ? 'Manual only (Sync now)' : 'Every ' . self::human($dirInterval * 60) . (!empty($row['directory_delta_enabled']) ? ', incremental (delta)' : ', full read'),
                !empty($row['directory_cron_enabled']) ? $dirInterval : null, 'microsoft_directory_sync_log', null],
            ['intune', 'Intune devices', !empty($row['intune_sync_enabled']), 'Every cron run', null, 'intune_sync_log', null],
            ['groups', 'Entra groups', !empty($row['groups_sync_enabled']), self::extrasSchedule($row, 'groups_sync_enabled', $exInterval), !empty($row['groups_sync_enabled']) ? $exInterval : null, 'entra_sync_log', 'groups'],
            ['licenses', 'License SKUs', !empty($row['licenses_sync_enabled']), self::extrasSchedule($row, 'licenses_sync_enabled', $exInterval), !empty($row['licenses_sync_enabled']) ? $exInterval : null, 'entra_sync_log', 'licenses'],
            ['intune_details', 'Intune compliance and apps', !empty($row['intune_details_enabled']), self::extrasSchedule($row, 'intune_details_enabled', $exInterval), !empty($row['intune_details_enabled']) && !empty($row['intune_sync_enabled']) ? $exInterval : null, 'entra_sync_log', 'intune_details'],
        ];
        $out = [];
        foreach ($defs as [$key, $label, $on, $schedule, $interval, $table, $kind]) {
            $last = self::run($db, $table, $id, $kind, false, null);
            $lastOk = $last && $last['status'] === 'success' ? $last : self::run($db, $table, $id, $kind, false, 'success');
            $next = '';
            if (!$on) {
                $next = 'switched off';
            } elseif ($interval !== null) {
                $due = $last ? $last['started'] + $interval * 60 : null;
                $next = $due === null || $due <= $nowTs ? 'due on the next cron run' : 'in ' . self::human($due - $nowTs);
            } elseif ($key === 'intune') {
                $next = 'on the next cron run';
            }
            $out[] = ['key' => $key, 'label' => $label, 'on' => $on, 'schedule' => $schedule, 'last' => $last, 'lastOk' => $lastOk, 'preview' => self::run($db, $table, $id, $kind, true, null), 'next' => $next];
        }

        return $out;
    }

    private static function extrasSchedule(array $row, string $flag, int $interval): string
    {
        return empty($row[$flag]) ? 'Off' : 'Every ' . self::human($interval * 60);
    }

    /** @return ?array<string,mixed> */
    private static function run(\mysqli $db, string $table, int $id, ?string $kind, bool $dry, ?string $status): ?array
    {
        $isDir = $table === 'microsoft_directory_sync_log';
        $isIntune = $table === 'intune_sync_log';
        $where = "microsoft_integration_id=$id" . ($kind !== null ? " AND kind='" . mysqli_real_escape_string($db, $kind) . "'" : '')
            . " AND dry_run=" . ($dry ? 1 : 0) . ($status !== null ? " AND status='" . mysqli_real_escape_string($db, $status) . "'" : '');
        try {
            $r = mysqli_query($db, "SELECT *, UNIX_TIMESTAMP(started_at) AS s, UNIX_TIMESTAMP(finished_at) AS f FROM `$table` WHERE $where ORDER BY id DESC LIMIT 1");
            $x = $r ? mysqli_fetch_assoc($r) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$x) {
            return null;
        }
        if ($isDir) {
            $counts = $x['users_seen'] . ' read; people ' . $x['employees_created'] . ' new, ' . $x['employees_updated'] . ' updated, ' . $x['employees_matched'] . ' matched, ' . $x['employees_skipped'] . ' skipped; departments ' . $x['departments_created'] . ' new'
                . ((int) $x['users_removed'] > 0 ? '; ' . $x['users_removed'] . ' archived (deleted in Entra)' : '');
        } elseif ($isIntune) {
            $counts = $x['devices_created'] . ' new, ' . $x['devices_updated'] . ' updated, ' . $x['devices_matched'] . ' matched, ' . $x['devices_skipped'] . ' skipped';
        } else {
            $counts = $x['items_seen'] . ' read, ' . $x['items_created'] . ' new, ' . $x['items_updated'] . ' updated, ' . $x['items_removed'] . ' removed' . ((int) $x['items_skipped'] > 0 ? ', ' . $x['items_skipped'] . ' left for next run' : '');
        }
        $code = (string) ($x['error_code'] ?? '');

        return [
            'id' => (int) $x['id'], 'started' => (int) $x['s'], 'finished' => $x['f'] === null ? null : (int) $x['f'], 'status' => (string) $x['status'],
            'mode' => $isDir ? (string) ($x['sync_mode'] ?? '') : '', 'counts' => $counts, 'error' => (string) ($x['errors'] ?? ''),
            'error_label' => $code !== '' ? (self::ERROR_LABELS[$code] ?? 'Error') : '', 'trigger' => ((int) $x['triggered_by']) === 0 ? 'cron' : 'manual',
        ];
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 5400) {
            return max(1, (int) round($seconds / 60)) . ' min';
        }

        return $seconds < 172800 ? round($seconds / 3600, 1) . ' h' : round($seconds / 86400, 1) . ' days';
    }

    /** Groups, license and Intune detail summaries for the page. @return array{groups:list<array>,licenses:list<array>,details:array<string,int>} */
    public static function inventory(\mysqli $db, int $integrationId): array
    {
        $id = (int) $integrationId;
        $groups = [];
        $licenses = [];
        $details = ['devices' => 0, 'with_details' => 0, 'policies' => 0, 'noncompliant' => 0, 'apps' => 0];
        try {
            $q = mysqli_query($db, "SELECT display_name, group_kind, member_count, members_synced_at FROM entra_groups WHERE microsoft_integration_id=$id ORDER BY display_name LIMIT 100");
            while ($q && ($r = mysqli_fetch_assoc($q))) {
                $groups[] = $r;
            }
            $q = mysqli_query($db, "SELECT l.sku_part_number, l.friendly_name, l.capability_status, l.units_enabled, l.units_suspended, l.units_warning, l.units_consumed, l.synced_at,
                                    (SELECT COUNT(*) FROM entra_user_licenses u WHERE u.microsoft_integration_id=l.microsoft_integration_id AND u.sku_id=l.sku_id) AS assigned
                                    FROM entra_licenses l WHERE l.microsoft_integration_id=$id ORDER BY l.friendly_name");
            while ($q && ($r = mysqli_fetch_assoc($q))) {
                $licenses[] = $r;
            }
            $r = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n, SUM(details_synced_at IS NOT NULL) AS d FROM asset_intune_links WHERE microsoft_integration_id=$id"));
            $details['devices'] = (int) ($r['n'] ?? 0);
            $details['with_details'] = (int) ($r['d'] ?? 0);
            $r = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n, SUM(policy_state IN ('nonCompliant','error','conflict')) AS bad FROM intune_device_compliance WHERE microsoft_integration_id=$id"));
            $details['policies'] = (int) ($r['n'] ?? 0);
            $details['noncompliant'] = (int) ($r['bad'] ?? 0);
            $details['apps'] = (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM intune_device_apps WHERE microsoft_integration_id=$id"))[0] ?? 0);
        } catch (\Throwable) {
        }

        return ['groups' => $groups, 'licenses' => $licenses, 'details' => $details];
    }
}
