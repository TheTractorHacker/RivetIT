<?php

namespace ITFlow\Reports\Queries;

use ITFlow\Reports\ReportScope;

/**
 * SLA breach and aging. Tickets created in the range that carry an SLA due date, and which of them breached it.
 *
 * Response breach:   first response later than the response due, or no response yet and the due has passed.
 * Resolution breach: resolved/closed later than the resolution due, or still open and the due has passed.
 * An open ticket whose SLA clock is paused (ticket_sla_paused_at set) is not counted as overdue. SLA-exempt categories
 * (ticket_counts_sla = 0) are left out, exactly as the Service Desk report does. Department scope and the universal
 * filters arrive through ReportScope::clause().
 */
final class SlaBreachReport
{
    public const AGING = [
        ['label' => 'Under 4 hours', 'min' => 0,   'max' => 4],
        ['label' => '4 to 24 hours', 'min' => 4,   'max' => 24],
        ['label' => '1 to 3 days',   'min' => 24,  'max' => 72],
        ['label' => '3 to 7 days',   'min' => 72,  'max' => 168],
        ['label' => 'Over 7 days',   'min' => 168, 'max' => null],
    ];

    private const RESP_BREACH = "(t.ticket_sla_response_due IS NOT NULL AND ((t.ticket_first_response_at IS NULL AND t.ticket_sla_response_due < :now AND t.ticket_sla_paused_at IS NULL) OR t.ticket_first_response_at > t.ticket_sla_response_due))";
    private const RESO_BREACH = "(t.ticket_sla_resolution_due IS NOT NULL AND ((t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL AND t.ticket_sla_resolution_due < :now AND t.ticket_sla_paused_at IS NULL) OR COALESCE(t.ticket_resolved_at, t.ticket_closed_at) > t.ticket_sla_resolution_due))";

    /** @return array{totals:array,by_priority:array,by_tech:array,by_client:array,aging:array,tickets:array,limit:int} */
    public static function data(\mysqli $db, string $from, string $to, ?string $now = null, int $limit = 200): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : date('Y-m-01');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : date('Y-m-d');
        $now  = $now !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now) ? $now : date('Y-m-d H:i:s');
        $resp = str_replace(':now', "'$now'", self::RESP_BREACH);
        $reso = str_replace(':now', "'$now'", self::RESO_BREACH);
        $sla  = function_exists('slaCountsClause') ? slaCountsClause($db, 't.') : '';
        $where = "t.ticket_archived_at IS NULL AND t.ticket_created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'"
            . " AND (t.ticket_sla_response_due IS NOT NULL OR t.ticket_sla_resolution_due IS NOT NULL)" . $sla
            . ReportScope::clause('t.ticket_client_id');

        $t = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT COUNT(*) AS total,
                SUM($resp) AS resp_breached, SUM(t.ticket_sla_response_due IS NOT NULL) AS resp_total,
                SUM($reso) AS reso_breached, SUM(t.ticket_sla_resolution_due IS NOT NULL) AS reso_total,
                SUM(($resp) OR ($reso)) AS any_breached
             FROM tickets t WHERE $where")) ?: [];
        $total = (int) ($t['total'] ?? 0);
        $anyB  = (int) ($t['any_breached'] ?? 0);
        $totals = [
            'tickets'        => $total,
            'response_total' => (int) ($t['resp_total'] ?? 0),
            'response_breached' => (int) ($t['resp_breached'] ?? 0),
            'resolution_total'  => (int) ($t['reso_total'] ?? 0),
            'resolution_breached' => (int) ($t['reso_breached'] ?? 0),
            'any_breached'   => $anyB,
            'compliance_pct' => $total > 0 ? round(($total - $anyB) / $total * 100, 1) : null,
        ];

        $group = static function (\mysqli $db, string $select, string $join, string $groupBy, string $where, string $resp, string $reso): array {
            $out = [];
            $res = mysqli_query($db,
                "SELECT $select AS label, COUNT(*) AS tickets, SUM($resp) AS resp_b, SUM($reso) AS reso_b, SUM(($resp) OR ($reso)) AS any_b
                 FROM tickets t $join WHERE $where GROUP BY $groupBy ORDER BY any_b DESC, tickets DESC LIMIT 25");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $n = (int) $r['tickets'];
                $b = (int) $r['any_b'];
                $out[] = ['label' => (string) $r['label'], 'tickets' => $n, 'response_breached' => (int) $r['resp_b'], 'resolution_breached' => (int) $r['reso_b'],
                    'breached' => $b, 'compliance_pct' => $n > 0 ? round(($n - $b) / $n * 100, 1) : null];
            }
            return $out;
        };
        $byPriority = $group($db, "COALESCE(NULLIF(t.ticket_priority, ''), 'None')", '', 't.ticket_priority', $where, $resp, $reso);
        $byTech     = $group($db, "COALESCE(u.user_name, 'Unassigned')", 'LEFT JOIN users u ON u.user_id = t.ticket_assigned_to', 't.ticket_assigned_to, u.user_name', $where, $resp, $reso);
        $byClient   = $group($db, "COALESCE(c.client_name, 'No department')", 'LEFT JOIN clients c ON c.client_id = t.ticket_client_id', 't.ticket_client_id, c.client_name', $where, $resp, $reso);

        // Aging: how long each currently overdue ticket has been past its (earliest) due date.
        $overdueExpr = "TIMESTAMPDIFF(HOUR, LEAST(COALESCE(IF($resp, t.ticket_sla_response_due, NULL), '9999-12-31'), COALESCE(IF($reso, t.ticket_sla_resolution_due, NULL), '9999-12-31')), '$now')";
        $aging = [];
        foreach (self::AGING as $b) {
            $cond = "$overdueExpr >= {$b['min']}" . ($b['max'] !== null ? " AND $overdueExpr < {$b['max']}" : '');
            $c = (int) (mysqli_fetch_row(mysqli_query($db,
                "SELECT COUNT(*) FROM tickets t WHERE $where AND t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL AND (($resp) OR ($reso)) AND $cond"))[0] ?? 0);
            $aging[] = ['label' => $b['label'], 'count' => $c];
        }

        $tickets = [];
        $res = mysqli_query($db,
            "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_priority, t.ticket_created_at,
                    t.ticket_sla_response_due, t.ticket_first_response_at, t.ticket_sla_resolution_due,
                    COALESCE(t.ticket_resolved_at, t.ticket_closed_at) AS done_at,
                    ($resp) AS resp_b, ($reso) AS reso_b, c.client_name, u.user_name,
                    $overdueExpr AS overdue_hours
             FROM tickets t LEFT JOIN clients c ON c.client_id = t.ticket_client_id LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
             WHERE $where AND (($resp) OR ($reso)) ORDER BY (t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL) DESC, t.ticket_sla_resolution_due ASC LIMIT " . max(1, min($limit, 1000)));
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $r['resp_b'] = (int) $r['resp_b'];
            $r['reso_b'] = (int) $r['reso_b'];
            $r['open']   = $r['done_at'] === null;
            $tickets[] = $r;
        }
        return ['totals' => $totals, 'by_priority' => $byPriority, 'by_tech' => $byTech, 'by_client' => $byClient, 'aging' => $aging, 'tickets' => $tickets, 'limit' => $limit];
    }
}
