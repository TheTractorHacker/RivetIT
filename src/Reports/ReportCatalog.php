<?php

namespace ITFlow\Reports;

/**
 * What every report page is called, which module gates it and which query-string parameters it understands.
 * Saved views, scheduled reports and the print/CSV toolbar all key off this one list, so a parameter that is not
 * listed here can never be stored, replayed or passed to a headless report run.
 *
 * Parameter specs:
 *   ['type' => 'int',   'min' => 1, 'max' => 12]
 *   ['type' => 'year',  'allow_all' => true]      (4-digit year, optionally the literal "all")
 *   ['type' => 'enum',  'values' => ['a', 'b']]
 *   ['type' => 'date']                            (Y-m-d, a real calendar date)
 *   ['type' => 'flag']                            (present or not: stored as 1)
 *
 * A report may also declare 'filters' => [request keys]: the universal filters (ReportFilters) it honours. Their specs are merged into
 * 'params' by definitions(), so they are validated, saved in views and replayed by schedules like any other parameter.
 *
 * Registering a report is additive: put a new entry in the array below (or in ReportCatalog::extra() for a feature branch).
 */
final class ReportCatalog
{
    private const CANNED = ['today', 'yesterday', 'thisweek', 'lastweek', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'last7', 'last14', 'last30', 'last90', 'last12months', 'thisquarter', 'lastquarter', 'next7', 'next30', 'alltime', 'custom'];

    public static function definitions(): array
    {
        $dates   = ['canned_date' => ['type' => 'enum', 'values' => self::CANNED], 'dtf' => ['type' => 'date'], 'dtt' => ['type' => 'date']];
        $year    = ['year' => ['type' => 'year']];
        $month   = ['month' => ['type' => 'int', 'min' => 1, 'max' => 12]];
        $days    = ['days' => ['type' => 'int', 'min' => 1, 'max' => 3650]];

        $tf = ReportFilters::ticketKeys();
        $af = ['f_client', 'f_location'];

        $defs = [
            'client_ticket_time_detail' => ['label' => 'Department Time Detail Audit', 'module' => 'module_sales',  'params' => [
                'from' => ['type' => 'date'], 'to' => ['type' => 'date'],
                'billable_only' => ['type' => 'enum', 'values' => ['0', '1']],
                'billing_increment' => ['type' => 'enum', 'values' => ['0.1', '0.25', '0.5']],
            ]],
            'included_issues'        => ['label' => 'Included Support Issues',      'module' => 'module_sales',     'params' => $month + $year],
            'service_desk'           => ['label' => 'Service Desk & SLA',           'module' => 'module_support',   'params' => $dates, 'filters' => $tf],
            'ticket_summary'         => ['label' => 'Tickets',                      'module' => 'module_support',   'params' => $year, 'filters' => $tf],
            'ticket_day_breakdown'   => ['label' => 'Tickets: Day by Day',          'module' => 'module_support',   'params' => $dates, 'filters' => $tf],
            'ticket_by_client'       => ['label' => 'Tickets by Department',        'module' => 'module_support',   'params' => $year + $month],
            'time_by_tech'           => ['label' => 'Time by Technician',           'module' => 'module_support',   'params' => $year + $month, 'filters' => $tf],
            'technician_performance' => ['label' => 'Technician Performance',       'module' => 'module_support',   'params' => $dates, 'filters' => $tf],
            'csat'                   => ['label' => 'Customer Satisfaction',        'module' => 'module_support',   'params' => $dates, 'filters' => $tf],
            'rmm_health'             => ['label' => 'RMM Health',                   'module' => 'module_support',   'params' => $dates],
            'credential_rotation'    => ['label' => 'Credential rotation',          'module' => 'module_credential', 'params' => $days],
            'credential_rotation_v2' => ['label' => 'Credential rotation due',      'module' => 'module_credential', 'params' => $days],
            'sla_breach_aging'       => ['label' => 'SLA Breach and Aging',         'module' => 'module_support',   'params' => $dates, 'filters' => $tf],
            'ticket_backlog'         => ['label' => 'Ticket Backlog',               'module' => 'module_support',   'params' => ['weeks' => ['type' => 'int', 'min' => 2, 'max' => 52]], 'filters' => $tf],
            'asset_lifecycle'        => ['label' => 'Asset Lifecycle and Warranty', 'module' => 'module_assets',    'params' => ['days' => ['type' => 'int', 'min' => 1, 'max' => 3650], 'age_years' => ['type' => 'int', 'min' => 1, 'max' => 50]], 'filters' => $af],
        ];

        // Merge each report's universal-filter specs into its params (validated like every other parameter).
        foreach ($defs as $key => &$def) {
            if (!empty($def['filters'])) {
                $def['params'] = $def['params'] + ReportFilters::specs($def['filters']);
            }
        }
        unset($def);
        return $defs;
    }

    public static function exists(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    public static function label(string $key): ?string
    {
        return self::definitions()[$key]['label'] ?? null;
    }

    public static function module(string $key): ?string
    {
        return self::definitions()[$key]['module'] ?? null;
    }

    /** The report key for the current script ("/agent/reports/csat.php" => "csat"), or null if it is not a catalogued report. */
    public static function keyFromScript(string $script): ?string
    {
        $key = basename($script, '.php');
        return self::exists($key) ? $key : null;
    }

    /**
     * Keep only the parameters the report declares, each re-validated against its spec. Anything else (unknown keys,
     * arrays, out-of-range numbers, impossible dates, unlisted enum values) is dropped, never coerced into something else.
     */
    public static function sanitizeParams(string $key, $raw): array
    {
        $defs = self::definitions();
        if (!isset($defs[$key]) || !is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($defs[$key]['params'] as $name => $spec) {
            if (!array_key_exists($name, $raw) || !is_scalar($raw[$name])) {
                continue;
            }
            $v = trim((string) $raw[$name]);
            switch ($spec['type']) {
                case 'int':
                    if (preg_match('/^\d{1,9}$/', $v) && (int) $v >= $spec['min'] && (int) $v <= $spec['max']) {
                        $out[$name] = (string) (int) $v;
                    }
                    break;
                case 'year':
                    if (preg_match('/^(19|20)\d{2}$/', $v) || (!empty($spec['allow_all']) && $v === 'all')) {
                        $out[$name] = $v;
                    }
                    break;
                case 'enum':
                    if (in_array($v, $spec['values'], true)) {
                        $out[$name] = $v;
                    }
                    break;
                case 'date':
                    $d = \DateTime::createFromFormat('!Y-m-d', $v);
                    if ($d && $d->format('Y-m-d') === $v) {
                        $out[$name] = $v;
                    }
                    break;
                case 'flag':
                    if ($v !== '' && $v !== '0') {
                        $out[$name] = '1';
                    }
                    break;
            }
        }
        // A custom date range only makes sense with both ends.
        if (isset($out['dtf']) xor isset($out['dtt'])) {
            unset($out['dtf'], $out['dtt']);
        }
        return $out;
    }

    /** Whitelisted parameters picked out of a request array ($_GET). */
    public static function paramsFromRequest(string $key, array $request): array
    {
        return self::sanitizeParams($key, $request);
    }

    public static function url(string $key, array $params): string
    {
        $q = http_build_query(self::sanitizeParams($key, $params));
        return '/agent/reports/' . $key . '.php' . ($q !== '' ? '?' . $q : '');
    }

    /** Schedulable keys for saved views: every catalogued report. */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }
}
