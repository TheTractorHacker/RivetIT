<?php

declare(strict_types=1);

namespace ITFlow\Reporting;

/**
 * RivetIT's own reporting views (RivetCore issue #91, phase 4; docs/design/reporting.md section 7). Core owns the RMM views (migration 0047); the tables
 * below are RivetIT's, so these views live here. Rules, the same as Core's:
 *  - an allow-list of columns: no ticket subject, details, replies, contact, billing, vendor, serial or credential columns, no alert message or raw payload;
 *  - client_id on every client-scoped row; stable names; the column lists below are frozen by tests/fixtures/edition_reporting_views.json;
 *  - UTC timestamps. RivetIT stores its timestamps in the COMPANY's local time (the session time zone is set from the company time zone), so when the company
 *    zone is not UTC the views convert with CONVERT_TZ and the NAMED zone (a fixed offset would be wrong half the year). That needs the database server's
 *    time zone tables; when they are not loaded the views are created WITHOUT conversion and {@see status()} says so, so a report is never silently shifted.
 *
 * The views carry no DEFINER clause: whoever runs the installer owns them, on a fresh install and on an upgrade alike.
 */
final class EditionViews
{
    /** @var array<string, list<string>> view => columns, in order. Frozen: tests/fixtures/edition_reporting_views.json must agree. */
    public const COLUMNS = [
        'rpt_clients' => ['client_id', 'client_name', 'created_at', 'archived_at'],
        'rpt_sites' => ['site_id', 'client_id', 'site_name', 'archived_at'],
        'rpt_assets' => ['asset_id', 'client_id', 'site_id', 'asset_type', 'asset_name', 'asset_status', 'created_at', 'archived_at'],
        'rpt_tickets' => ['ticket_id', 'client_id', 'site_id', 'asset_id', 'priority', 'status_name', 'is_open', 'created_at', 'first_response_at', 'resolved_at', 'closed_at',
            'sla_applies', 'sla_response_due_at', 'sla_resolution_due_at', 'sla_response_met', 'sla_resolution_met', 'first_response_s', 'resolution_s', 'csat_rating', 'is_parked'],
        'rpt_alert_tickets' => ['alert_id', 'client_id', 'ticket_id'],
        'rpt_sla' => ['policy_id', 'policy_name', 'low_response_min', 'low_resolution_min', 'medium_response_min', 'medium_resolution_min', 'high_response_min', 'high_resolution_min'],
    ];

    /** Views whose rows belong to a client (the tenant tests). */
    public const CLIENT_SCOPED = ['rpt_clients', 'rpt_sites', 'rpt_assets', 'rpt_tickets', 'rpt_alert_tickets'];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::COLUMNS);
    }

    /** A zone name is letters, digits and / _ + - only (it ends up in view text); anything else counts as "no zone". */
    public static function validZone(?string $zone): ?string
    {
        return $zone !== null && preg_match('#^[A-Za-z][A-Za-z0-9_+\-]*(/[A-Za-z0-9_+\-]+){0,2}\z#D', $zone) === 1 ? $zone : null;
    }

    public static function isUtc(?string $zone): bool
    {
        return $zone === null || in_array(strtoupper($zone), ['UTC', 'ETC/UTC', 'GMT', 'ETC/GMT', 'Z', 'UCT', 'ETC/UCT', 'ZULU'], true);
    }

    /**
     * The CREATE statements. $zone is the NAMED zone the stored timestamps are in, or null when they are already UTC (or the zone cannot be applied).
     *
     * @return array<string,string> view name => statement
     */
    public static function statements(?string $zone): array
    {
        $zone = self::validZone($zone);
        $ts = static fn (string $col): string => ($zone === null || self::isUtc($zone)) ? $col : "CONVERT_TZ($col, '$zone', 'UTC')";

        return [
            'rpt_clients' => 'CREATE OR REPLACE VIEW rpt_clients (client_id, client_name, created_at, archived_at) AS SELECT client_id, client_name, ' . $ts('client_created_at') . ', ' . $ts('client_archived_at') . ' FROM clients',
            'rpt_sites' => 'CREATE OR REPLACE VIEW rpt_sites (site_id, client_id, site_name, archived_at) AS SELECT location_id, location_client_id, location_name, ' . $ts('location_archived_at') . ' FROM locations',
            'rpt_assets' => 'CREATE OR REPLACE VIEW rpt_assets (asset_id, client_id, site_id, asset_type, asset_name, asset_status, created_at, archived_at) AS SELECT asset_id, asset_client_id, NULLIF(asset_location_id, 0), asset_type, asset_name, asset_status, '
                . $ts('asset_created_at') . ', ' . $ts('asset_archived_at') . ' FROM assets',
            'rpt_tickets' => 'CREATE OR REPLACE VIEW rpt_tickets (' . implode(', ', self::COLUMNS['rpt_tickets']) . ') AS SELECT t.ticket_id, t.ticket_client_id, NULLIF(t.ticket_location_id, 0), NULLIF(t.ticket_asset_id, 0), t.ticket_priority, s.ticket_status_name, '
                . 'CASE WHEN t.ticket_closed_at IS NULL AND t.ticket_resolved_at IS NULL THEN 1 ELSE 0 END, '
                . $ts('t.ticket_created_at') . ', ' . $ts('t.ticket_first_response_at') . ', ' . $ts('t.ticket_resolved_at') . ', ' . $ts('t.ticket_closed_at') . ', '
                . 't.ticket_counts_sla, '
                . 'CASE WHEN t.ticket_counts_sla = 1 THEN ' . $ts('t.ticket_sla_response_due') . ' END, CASE WHEN t.ticket_counts_sla = 1 THEN ' . $ts('t.ticket_sla_resolution_due') . ' END, '
                . 'CASE WHEN t.ticket_counts_sla = 1 THEN t.ticket_sla_response_met END, CASE WHEN t.ticket_counts_sla = 1 THEN t.ticket_sla_resolution_met END, '
                . 'TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_first_response_at), TIMESTAMPDIFF(SECOND, t.ticket_created_at, t.ticket_resolved_at), t.ticket_csat_rating, COALESCE(s.ticket_status_parked, 0) '
                . 'FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status WHERE t.ticket_archived_at IS NULL',
            'rpt_alert_tickets' => 'CREATE OR REPLACE VIEW rpt_alert_tickets (alert_id, client_id, ticket_id) AS SELECT id, client_id, ticket_id FROM rmm_alerts WHERE ticket_id IS NOT NULL',
            'rpt_sla' => 'CREATE OR REPLACE VIEW rpt_sla (' . implode(', ', self::COLUMNS['rpt_sla']) . ') AS SELECT policy_id, policy_name, policy_low_response, policy_low_resolution, policy_medium_response, policy_medium_resolution, policy_high_response, policy_high_resolution '
                . 'FROM sla_policies WHERE policy_archived_at IS NULL',
        ];
    }

    /** True when CONVERT_TZ knows $zone on this server (the time zone tables are loaded). */
    public static function zoneUsable(\mysqli $db, string $zone): bool
    {
        $zone = self::validZone($zone);
        if ($zone === null) {
            return false;
        }
        $r = @mysqli_query($db, "SELECT CONVERT_TZ('2026-07-01 12:00:00', '$zone', 'UTC') AS v, CONVERT_TZ('2026-01-01 12:00:00', '$zone', 'UTC') AS w");
        $row = $r ? mysqli_fetch_assoc($r) : null;

        return $row !== null && $row['v'] !== null && $row['w'] !== null;
    }

    /** The company's time zone name (settings.config_timezone), or null. */
    public static function companyZone(\mysqli $db): ?string
    {
        $r = @mysqli_query($db, 'SELECT config_timezone FROM settings WHERE company_id = 1');
        $row = $r ? mysqli_fetch_assoc($r) : null;

        return self::validZone($row['config_timezone'] ?? null);
    }

    /**
     * (Re)create the views. Idempotent. Never throws: a view that cannot be created is reported in `errors`.
     *
     * @param ?string $zone the zone to convert from; null = the company's zone
     * @return array{zone:?string, converted:bool, utc:bool, created:list<string>, errors:list<string>, warning:?string}
     */
    public static function install(\mysqli $db, ?string $zone = null): array
    {
        $zone = self::validZone($zone) ?? self::companyZone($db);
        $utc = self::isUtc($zone);
        $apply = null;
        $warning = null;
        if (!$utc) {
            if (self::zoneUsable($db, (string) $zone)) {
                $apply = $zone;
            } else {
                $warning = "This database server cannot convert from the company time zone ($zone): its time zone tables are not loaded (run mariadb-tzinfo-to-sql). "
                    . 'The views were created WITHOUT conversion, so their timestamps are in company local time, not UTC. Load the tables and rebuild the views.';
            }
        }
        $created = [];
        $errors = [];
        foreach (self::statements($apply) as $name => $sql) {
            try {
                if (@mysqli_query($db, $sql)) {
                    $created[] = $name;
                } else {
                    $errors[] = $name . ': ' . mysqli_error($db);
                }
            } catch (\Throwable $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        return ['zone' => $zone, 'converted' => $apply !== null, 'utc' => $utc, 'created' => $created, 'errors' => $errors, 'warning' => $warning];
    }
}
