<?php

namespace ITFlow\Reports;

/**
 * Headline KPIs for the reports hub (Master Plan #61 "Reporting V2"): Open Tickets, Mean Resolution Time, SLA Compliance,
 * Tickets This Month, User Satisfaction. All are scoped to the session's departments through ReportScope.
 *
 *   mean_resolution_hours  average creation-to-resolution time of tickets resolved in the last 30 days (null when none)
 *   sla_compliance_pct     share of tickets created in the last 30 days that carry an SLA and missed none of it (null when none)
 *   csat_avg               average CSAT rating of tickets rated in the last 30 days (null when none)
 */
final class ReportKpis
{
    public static function headline(\mysqli $db, ?string $now = null): array
    {
        $now = $now !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now) ? $now : date('Y-m-d H:i:s');
        $scope = ReportScope::clause('ticket_client_id');
        $scopeT = ReportScope::clause('t.ticket_client_id');
        $one = static fn (string $sql) => mysqli_fetch_row(mysqli_query($db, $sql)) ?: [null];
        $monthStart = substr($now, 0, 7) . '-01 00:00:00';

        // Open Tickets excludes Parked statuses (they stay unresolved but are not part of the open-ticket metric).
        require_once dirname(__DIR__, 2) . '/includes/ticket_parked.php';
        $open = (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL$scope" . \ticketNotParkedSql($db))[0];
        $month = (int) $one("SELECT COUNT(*) FROM tickets WHERE ticket_archived_at IS NULL AND ticket_created_at >= '$monthStart' AND ticket_created_at <= '$now'$scope")[0];

        $rs = function_exists('ticketResolutionStartSql') ? ticketResolutionStartSql('t.') : 't.ticket_created_at';
        $re = function_exists('ticketResolutionEndSql') ? ticketResolutionEndSql('t.') : 'COALESCE(t.ticket_resolved_at, t.ticket_closed_at)';
        $ro = function_exists('ticketResolvedOnlySql') ? ticketResolvedOnlySql('t.') : '(t.ticket_resolved_at IS NOT NULL OR t.ticket_closed_at IS NOT NULL)';
        $avg = $one("SELECT AVG(TIMESTAMPDIFF(SECOND, $rs, $re)) FROM tickets t WHERE t.ticket_archived_at IS NULL AND $ro AND $re BETWEEN DATE_SUB('$now', INTERVAL 30 DAY) AND '$now'$scopeT")[0];

        $sla = function_exists('slaCountsClause') ? slaCountsClause($db, 't.') : '';
        $s = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT COUNT(*) AS total,
                SUM((t.ticket_sla_response_due IS NOT NULL AND ((t.ticket_first_response_at IS NULL AND t.ticket_sla_response_due < '$now' AND t.ticket_sla_paused_at IS NULL) OR t.ticket_first_response_at > t.ticket_sla_response_due))
                 OR (t.ticket_sla_resolution_due IS NOT NULL AND ((t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL AND t.ticket_sla_resolution_due < '$now' AND t.ticket_sla_paused_at IS NULL) OR COALESCE(t.ticket_resolved_at, t.ticket_closed_at) > t.ticket_sla_resolution_due))) AS breached
             FROM tickets t WHERE t.ticket_archived_at IS NULL AND t.ticket_created_at BETWEEN DATE_SUB('$now', INTERVAL 30 DAY) AND '$now'
               AND (t.ticket_sla_response_due IS NOT NULL OR t.ticket_sla_resolution_due IS NOT NULL)$sla$scopeT")) ?: [];
        $slaTotal = (int) ($s['total'] ?? 0);

        $csat = $one("SELECT AVG(ticket_csat_rating) FROM tickets WHERE ticket_csat_rating IS NOT NULL AND ticket_csat_rated_at >= DATE_SUB('$now', INTERVAL 30 DAY)$scope")[0];

        return [
            'open' => $open,
            'tickets_this_month' => $month,
            'mean_resolution_hours' => $avg !== null ? round(((float) $avg) / 3600, 1) : null,
            'sla_compliance_pct' => $slaTotal > 0 ? round(($slaTotal - (int) $s['breached']) / $slaTotal * 100, 1) : null,
            'csat_avg' => $csat !== null ? round((float) $csat, 2) : null,
        ];
    }

    /** "5.5 h" / "2.3 d" / "-" */
    public static function durationText(?float $hours): string
    {
        if ($hours === null) {
            return '-';
        }
        return $hours >= 48 ? round($hours / 24, 1) . ' d' : $hours . ' h';
    }
}
