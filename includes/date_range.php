<?php

/*
 * Shared date-range helpers (RivetCore\Ui\DateRange) for every list page, report and the Service Desk filters.
 *
 *  - dateRangeFromRequest($_GET, $default)  resolve ?canned_date= / ?dtf= / ?dtt= into a validated DateRange, in the app's
 *    configured timezone (includes/inc_set_timezone.php sets PHP's default timezone). Weeks start on Monday, as the legacy
 *    presets always did.
 *  - dateRangeSqlBetween($column, $range)   a SARGABLE `col >= 'from 00:00:00' AND col < 'to+1 00:00:00'` fragment built only
 *    from the resolved dates (never from raw request text), so an index on the column can be used.
 *  - dateRangeUrlParams($range)             the canonical query params for links/saved views (presets carry only canned_date).
 *  - ticketDateFields() / ticketDateFieldFromRequest()   the whitelist behind the Service Desk "Date field" select.
 *
 * Pulled in by includes/filter_header.php; the picker component lives in includes/date_range_picker.php.
 */

use RivetCore\Ui\DateRange;

if (!function_exists('dateRangeFromRequest')) {
    /**
     * @param array  $get     normally $_GET
     * @param string $default preset used when the request carries no canned_date and no dates ('alltime' = unfiltered)
     */
    function dateRangeFromRequest(array $get, string $default = 'alltime'): DateRange
    {
        $str = static function ($v): string {
            return is_string($v) ? trim($v) : '';
        };
        $canned = strtolower($str($get['canned_date'] ?? ''));
        $from = $str($get['dtf'] ?? '');
        $to = $str($get['dtt'] ?? '');
        if ($canned === '') {
            // Legacy bookmarks: ?dtf=..&dtt=.. with no canned_date meant "custom".
            $canned = ($from !== '' || $to !== '') ? 'custom' : $default;
        }
        try {
            $tz = new DateTimeZone(date_default_timezone_get());
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        return DateRange::resolve($canned, $from, $to, null, $tz, 1);
    }
}

if (!function_exists('dateRangeSqlBetween')) {
    /**
     * Sargable half-open datetime filter for a column (`1 = 1` for all time). The column name is checked against a strict identifier pattern
     * (optionally table-qualified) and the dates come from the already-validated DateRange, so the result is injection-safe.
     */
    function dateRangeSqlBetween(string $column, DateRange $range): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException('Invalid date column');
        }
        if ($range->isAllTime()) {
            // "All time" means no date restriction at all (it must not hide rows whose date column is NULL, e.g. unresolved tickets).
            return '1 = 1';
        }
        [$a, $b] = $range->sqlBounds();

        return "$column >= '$a' AND $column < '$b'";
    }
}

if (!function_exists('dateRangeUrlParams')) {
    /** @return array<string,string> canned_date (+ dtf/dtt for custom only) merged over $extra */
    function dateRangeUrlParams(DateRange $range, array $extra = []): array
    {
        return $extra + $range->toQuery();
    }
}

if (!function_exists('ticketDateFields')) {
    /** Service Desk "Date field" whitelist: GET value => label + the real tickets column. */
    function ticketDateFields(): array
    {
        return [
            'created'  => ['label' => 'Created',  'column' => 'ticket_created_at'],
            'updated'  => ['label' => 'Updated',  'column' => 'ticket_updated_at'],
            'resolved' => ['label' => 'Resolved', 'column' => 'ticket_resolved_at'],
            'closed'   => ['label' => 'Closed',   'column' => 'ticket_closed_at'],
            'due'      => ['label' => 'Due date', 'column' => 'ticket_due_at'],
            'sla_due'  => ['label' => 'SLA resolution due', 'column' => 'ticket_sla_resolution_due'],
        ];
    }
}

if (!function_exists('ticketDateFieldFromRequest')) {
    function ticketDateFieldFromRequest(array $get): string
    {
        $f = isset($get['datefield']) && is_string($get['datefield']) ? strtolower(trim($get['datefield'])) : '';

        return array_key_exists($f, ticketDateFields()) ? $f : 'created';
    }
}

require_once __DIR__ . '/date_range_picker.php';
