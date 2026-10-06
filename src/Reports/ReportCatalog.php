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
 */
final class ReportCatalog
{
    private const CANNED = ['today', 'yesterday', 'thisweek', 'lastweek', 'thismonth', 'lastmonth', 'thisyear', 'lastyear', 'alltime', 'custom'];

    public static function definitions(): array
    {
        $dates   = ['canned_date' => ['type' => 'enum', 'values' => self::CANNED], 'dtf' => ['type' => 'date'], 'dtt' => ['type' => 'date']];
        $year    = ['year' => ['type' => 'year']];
        $yearAll = ['year' => ['type' => 'year', 'allow_all' => true]];
        $month   = ['month' => ['type' => 'int', 'min' => 1, 'max' => 12]];
        $days    = ['days' => ['type' => 'int', 'min' => 1, 'max' => 3650]];

        return [
            'income_summary'         => ['label' => 'Income',                       'module' => 'module_financial', 'params' => $year,  'company_wide' => true],
            'income_by_client'       => ['label' => 'Income By Department',         'module' => 'module_financial', 'params' => $yearAll],
            'recurring_by_client'    => ['label' => 'Recurring Income By Department', 'module' => 'module_financial', 'params' => []],
            'mrr'                    => ['label' => 'MRR & Forecast',               'module' => 'module_financial', 'params' => []],
            'clients_with_balance'   => ['label' => 'Departments with a Balance',   'module' => 'module_financial', 'params' => []],
            'expense_summary'        => ['label' => 'Expense',                      'module' => 'module_financial', 'params' => $year,  'company_wide' => true],
            'expense_by_vendor'      => ['label' => 'Expense By Vendor',            'module' => 'module_financial', 'params' => $year,  'company_wide' => true],
            'tax_summary'            => ['label' => 'Tax Summary',                  'module' => 'module_financial', 'params' => $year + ['view' => ['type' => 'enum', 'values' => ['monthly', 'quarterly']]], 'company_wide' => true],
            'profit_loss'            => ['label' => 'Profit & Loss',                'module' => 'module_financial', 'params' => $year,  'company_wide' => true],
            'budget'                 => ['label' => 'Annual Budget',                'module' => 'module_financial', 'params' => $year,  'company_wide' => true],
            'tickets_unbilled'       => ['label' => 'Unbilled Tickets',             'module' => 'module_sales',     'params' => $year],
            'client_ticket_time_detail' => ['label' => 'Department Time Detail Audit', 'module' => 'module_sales',  'params' => [
                'from' => ['type' => 'date'], 'to' => ['type' => 'date'],
                'billable_only' => ['type' => 'enum', 'values' => ['0', '1']],
                'billing_increment' => ['type' => 'enum', 'values' => ['0.1', '0.25', '0.5']],
            ]],
            'included_issues'        => ['label' => 'Included Support Issues',      'module' => 'module_sales',     'params' => $month + $year],
            'service_desk'           => ['label' => 'Service Desk & SLA',           'module' => 'module_support',   'params' => $dates],
            'ticket_summary'         => ['label' => 'Tickets',                      'module' => 'module_support',   'params' => $year],
            'ticket_day_breakdown'   => ['label' => 'Tickets: Day by Day',          'module' => 'module_support',   'params' => $dates],
            'ticket_charges'         => ['label' => 'Ticket Charges',               'module' => 'module_support',   'params' => $year + ['uninvoiced' => ['type' => 'flag']]],
            'ticket_by_client'       => ['label' => 'Tickets by Department',        'module' => 'module_support',   'params' => $year + $month],
            'time_by_tech'           => ['label' => 'Time by Technician',           'module' => 'module_support',   'params' => $year + $month],
            'technician_performance' => ['label' => 'Technician Performance',       'module' => 'module_support',   'params' => $dates],
            'csat'                   => ['label' => 'Customer Satisfaction',        'module' => 'module_support',   'params' => $dates],
            'rmm_health'             => ['label' => 'RMM Health',                   'module' => 'module_support',   'params' => $dates],
            'credential_rotation'    => ['label' => 'Credential rotation',          'module' => 'module_credential', 'params' => $days],
            'credential_rotation_v2' => ['label' => 'Credential rotation due',      'module' => 'module_credential', 'params' => $days],
        ];
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
                    if (preg_match('/^\d{1,5}$/', $v) && (int) $v >= $spec['min'] && (int) $v <= $spec['max']) {
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
