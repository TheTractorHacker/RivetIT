<?php

namespace ITFlow\Reports\Queries;

use ITFlow\Reports\ReportScope;

/**
 * Ticket backlog: what is open right now (age, status, priority, technician, department, oldest tickets) and how the
 * backlog moved week by week. "Open" = neither resolved nor closed, not archived. Department scope and the universal
 * filters arrive through ReportScope::clause().
 */
final class TicketBacklogReport
{
    public const AGE = [
        ['label' => 'Under 1 day', 'min' => 0,   'max' => 24],
        ['label' => '1 to 3 days', 'min' => 24,  'max' => 72],
        ['label' => '3 to 7 days', 'min' => 72,  'max' => 168],
        ['label' => '7 to 30 days', 'min' => 168, 'max' => 720],
        ['label' => 'Over 30 days', 'min' => 720, 'max' => null],
    ];

    public static function data(\mysqli $db, int $weeks = 12, ?string $now = null, int $oldestLimit = 25): array
    {
        $weeks = max(2, min($weeks, 52));
        $now = $now !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now) ? $now : date('Y-m-d H:i:s');
        $scope = ReportScope::clause('t.ticket_client_id');
        $base  = "t.ticket_archived_at IS NULL" . $scope;
        $open  = "$base AND t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL";
        $age   = "TIMESTAMPDIFF(HOUR, t.ticket_created_at, '$now')";

        $row = mysqli_fetch_assoc(mysqli_query($db,
            "SELECT COUNT(*) AS open_total,
                SUM(t.ticket_assigned_to = 0 OR t.ticket_assigned_to IS NULL) AS unassigned,
                SUM($age >= 168) AS older_7d,
                SUM($age >= 720) AS older_30d,
                AVG($age) AS avg_age_h
             FROM tickets t WHERE $open")) ?: [];
        $totals = [
            'open' => (int) ($row['open_total'] ?? 0),
            'unassigned' => (int) ($row['unassigned'] ?? 0),
            'older_7d' => (int) ($row['older_7d'] ?? 0),
            'older_30d' => (int) ($row['older_30d'] ?? 0),
            'avg_age_days' => isset($row['avg_age_h']) && $row['avg_age_h'] !== null ? round($row['avg_age_h'] / 24, 1) : null,
        ];

        $buckets = [];
        foreach (self::AGE as $b) {
            $cond = "$age >= {$b['min']}" . ($b['max'] !== null ? " AND $age < {$b['max']}" : '');
            $buckets[] = ['label' => $b['label'], 'count' => (int) (mysqli_fetch_row(mysqli_query($db, "SELECT COUNT(*) FROM tickets t WHERE $open AND $cond"))[0] ?? 0)];
        }

        $group = static function (string $select, string $join, string $groupBy, int $limit) use ($db, $open, $now): array {
            $out = [];
            $res = mysqli_query($db, "SELECT $select AS label, COUNT(*) AS cnt, MAX(TIMESTAMPDIFF(DAY, t.ticket_created_at, '$now')) AS oldest_d
                FROM tickets t $join WHERE $open GROUP BY $groupBy ORDER BY cnt DESC LIMIT $limit");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $out[] = ['label' => (string) $r['label'], 'count' => (int) $r['cnt'], 'oldest_days' => (int) $r['oldest_d']];
            }
            return $out;
        };
        $byStatus   = $group("COALESCE(s.ticket_status_name, 'Unknown')", 'LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status', 't.ticket_status, s.ticket_status_name', 25);
        $byPriority = $group("COALESCE(NULLIF(t.ticket_priority, ''), 'None')", '', 't.ticket_priority', 25);
        $byTech     = $group("COALESCE(u.user_name, 'Unassigned')", 'LEFT JOIN users u ON u.user_id = t.ticket_assigned_to', 't.ticket_assigned_to, u.user_name', 25);
        $byClient   = $group("COALESCE(c.client_name, 'No department')", 'LEFT JOIN clients c ON c.client_id = t.ticket_client_id', 't.ticket_client_id, c.client_name', 15);

        // Weekly trend: backlog at the end of each of the last N weeks (created by then, not resolved/closed by then).
        $trend = [];
        $end = new \DateTime($now);
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $e = (clone $end)->modify("-$i week");
            $s = (clone $e)->modify('-1 week');
            $eS = $e->format('Y-m-d H:i:s');
            $sS = $s->format('Y-m-d H:i:s');
            $r = mysqli_fetch_assoc(mysqli_query($db,
                "SELECT
                    SUM(t.ticket_created_at <= '$eS' AND (COALESCE(t.ticket_resolved_at, t.ticket_closed_at) IS NULL OR COALESCE(t.ticket_resolved_at, t.ticket_closed_at) > '$eS')) AS backlog,
                    SUM(t.ticket_created_at > '$sS' AND t.ticket_created_at <= '$eS') AS opened,
                    SUM(COALESCE(t.ticket_resolved_at, t.ticket_closed_at) > '$sS' AND COALESCE(t.ticket_resolved_at, t.ticket_closed_at) <= '$eS') AS closed
                 FROM tickets t WHERE $base"));
            $trend[] = ['week_ending' => $e->format('Y-m-d'), 'opened' => (int) ($r['opened'] ?? 0), 'closed' => (int) ($r['closed'] ?? 0), 'backlog' => (int) ($r['backlog'] ?? 0)];
        }

        $oldest = [];
        $res = mysqli_query($db,
            "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject, t.ticket_priority, t.ticket_created_at, t.ticket_updated_at,
                    c.client_name, u.user_name, s.ticket_status_name, TIMESTAMPDIFF(DAY, t.ticket_created_at, '$now') AS age_days
             FROM tickets t LEFT JOIN clients c ON c.client_id = t.ticket_client_id LEFT JOIN users u ON u.user_id = t.ticket_assigned_to
             LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
             WHERE $open ORDER BY t.ticket_created_at ASC LIMIT " . max(1, min($oldestLimit, 500)));
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $oldest[] = $r;
        }
        return ['totals' => $totals, 'age' => $buckets, 'by_status' => $byStatus, 'by_priority' => $byPriority, 'by_tech' => $byTech, 'by_client' => $byClient, 'trend' => $trend, 'oldest' => $oldest, 'weeks' => $weeks];
    }
}
