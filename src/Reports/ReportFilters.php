<?php

namespace ITFlow\Reports;

/**
 * Universal report filters (Master Plan #62): department, location, technician, category, priority, status, asset.
 *
 * Reports declare which of these they honour (ReportCatalog 'filters'); the values are validated through the same
 * ReportCatalog::sanitizeParams() spec as every other parameter, so saved views, schedules and headless runs can only
 * ever carry known keys with in-range values. They are turned into a SQL fragment that is appended wherever a ticket
 * query already appends the department scope: ReportScope::clause() calls ticketClause() for any "...ticket_client_id"
 * column, so the shared getXxxReport() helpers and the report pages pick the filters up without each query changing.
 *
 * Active filters live in a per-request static (activate() is called once by agent/reports/includes/inc_all_reports.php for a
 * catalogued report). Nothing else activates them, so dashboards, the API and the hub are never filtered by accident.
 * Every value is an integer or a member of a fixed list: no raw text reaches SQL.
 */
final class ReportFilters
{
    /** request key => [label, column suffix on the tickets table, kind] */
    public const TICKET = [
        'f_client'   => ['Department', 'ticket_client_id',   'int'],
        'f_location' => ['Location',   'ticket_location_id', 'int'],
        'f_tech'     => ['Technician', 'ticket_assigned_to', 'int'],
        'f_category' => ['Category',   'ticket_category',    'int'],
        'f_priority' => ['Priority',   'ticket_priority',    'enum'],
        'f_status'   => ['Status',     'ticket_status',      'int'],
        'f_asset'    => ['Asset',      'ticket_asset_id',    'int'],
    ];

    public const PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    /** Asset reports honour the subset that exists on the assets table. */
    public const ASSET = [
        'f_client'   => ['Department', 'asset_client_id',   'int'],
        'f_location' => ['Location',   'asset_location_id', 'int'],
    ];

    /** @var array<string,string> */
    private static array $active = [];

    /** Parameter specs for ReportCatalog, limited to the given request keys. */
    public static function specs(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!isset(self::TICKET[$k])) {
                continue;
            }
            $out[$k] = self::TICKET[$k][2] === 'enum'
                ? ['type' => 'enum', 'values' => self::PRIORITIES]
                : ['type' => 'int', 'min' => 1, 'max' => 999999999];
        }
        return $out;
    }

    public static function ticketKeys(): array
    {
        return array_keys(self::TICKET);
    }

    /** Turn the report's sanitized parameters into the active filter set (only keys the report declares). */
    public static function activate(?string $reportKey, array $request): void
    {
        self::$active = [];
        if ($reportKey === null) {
            return;
        }
        $declared = ReportCatalog::definitions()[$reportKey]['filters'] ?? [];
        if ($declared === []) {
            return;
        }
        $clean = ReportCatalog::sanitizeParams($reportKey, $request);
        foreach ($declared as $k) {
            if (isset($clean[$k])) {
                self::$active[$k] = $clean[$k];
            }
        }
    }

    public static function reset(): void
    {
        self::$active = [];
    }

    /** @return array<string,string> */
    public static function active(): array
    {
        return self::$active;
    }

    /**
     * " AND ..." for the active ticket filters. $column is the department column the caller is already scoping on
     * ("ticket_client_id", "t.ticket_client_id"); its alias prefix is reused for the other columns.
     */
    public static function ticketClause(string $column = 'ticket_client_id'): string
    {
        return self::build(self::TICKET, self::$active, $column, 'ticket_client_id');
    }

    /** Same, for assets: $column such as "asset_client_id" or "a.asset_client_id". */
    public static function assetClause(string $column = 'asset_client_id'): string
    {
        return self::build(self::ASSET, self::$active, $column, 'asset_client_id');
    }

    /** Pure builder (tests call it directly). */
    public static function build(array $map, array $values, string $column, string $baseColumn): string
    {
        if ($values === [] || !preg_match('/^(?:([A-Za-z_][A-Za-z0-9_]*)\.)?' . preg_quote($baseColumn, '/') . '$/', $column, $m)) {
            return '';
        }
        $prefix = isset($m[1]) && $m[1] !== '' ? $m[1] . '.' : '';
        $sql = '';
        foreach ($map as $key => [$label, $col, $kind]) {
            if (!isset($values[$key])) {
                continue;
            }
            if ($kind === 'enum') {
                if (!in_array($values[$key], self::PRIORITIES, true)) {
                    return ' AND 1 = 0';
                }
                $sql .= " AND {$prefix}{$col} = '" . $values[$key] . "'";
            } else {
                if (!preg_match('/^\d{1,9}$/', (string) $values[$key])) {
                    return ' AND 1 = 0';
                }
                $sql .= " AND {$prefix}{$col} = " . (int) $values[$key];
            }
        }
        return $sql;
    }

    /** One line describing the active filters, for printed/PDF output ("Priority: High; Technician #4"). */
    public static function describe(\mysqli $db, array $values): string
    {
        $parts = [];
        foreach (self::TICKET + self::ASSET as $key => [$label, $col, $kind]) {
            if (!isset($values[$key])) {
                continue;
            }
            $v = $values[$key];
            $name = $v;
            if ($kind === 'int') {
                $id = (int) $v;
                $sqlName = [
                    'f_client'   => "SELECT client_name FROM clients WHERE client_id = $id",
                    'f_location' => "SELECT location_name FROM locations WHERE location_id = $id",
                    'f_tech'     => "SELECT user_name FROM users WHERE user_id = $id",
                    'f_category' => "SELECT category_name FROM categories WHERE category_id = $id",
                    'f_status'   => "SELECT ticket_status_name FROM ticket_statuses WHERE ticket_status_id = $id",
                    'f_asset'    => "SELECT asset_name FROM assets WHERE asset_id = $id",
                ][$key] ?? null;
                $r = $sqlName ? mysqli_fetch_row(mysqli_query($db, $sqlName)) : null;
                $name = $r[0] ?? ('#' . $id);
            }
            $parts[] = $label . ': ' . $name;
        }
        return implode('; ', $parts);
    }
}
