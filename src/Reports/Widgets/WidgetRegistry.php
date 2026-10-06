<?php

namespace ITFlow\Reports\Widgets;

/**
 * Dashboard widgets for agent/my_dashboard.php.
 *
 * definitions(): id => title, icon, the modules that must be readable (ANY of them; a widget whose modules the user
 * cannot read is not offered and never computed), default order. data() returns a plain structure that
 * WidgetRenderer turns into escaped HTML:
 *   ['type' => 'stat',   'value' => string, 'caption' => string]
 *   ['type' => 'bars',   'items' => [['label' =>, 'value' => int, 'href' =>?]]]
 *   ['type' => 'table',  'columns' => [...], 'rows' => [['cells' => [...], 'href' =>?]], 'empty' => string]
 *   ['type' => 'series', 'labels' => [...], 'a' => [...], 'b' => [...], 'a_label' =>, 'b_label' =>]
 * Every query is restricted to the context's departments (WidgetContext::scope).
 */
final class WidgetRegistry
{
    public const SIZES = ['sm' => 4, 'md' => 6, 'lg' => 12];

    public static function definitions(): array
    {
        return [
            'tickets_by_status'   => ['title' => 'Open tickets by status',        'icon' => 'fa-layer-group',        'modules' => ['module_support'], 'size' => 'md'],
            'tickets_by_priority' => ['title' => 'Open tickets by priority',      'icon' => 'fa-exclamation-circle', 'modules' => ['module_support'], 'size' => 'md'],
            'sla_at_risk'         => ['title' => 'SLA at risk (next 4 hours)',    'icon' => 'fa-stopwatch',          'modules' => ['module_support'], 'size' => 'md'],
            'my_tickets'          => ['title' => 'My open tickets',               'icon' => 'fa-user-check',         'modules' => ['module_support'], 'size' => 'md'],
            'created_vs_closed'   => ['title' => 'Tickets created vs closed (30 days)', 'icon' => 'fa-chart-bar',    'modules' => ['module_support'], 'size' => 'lg'],
            'avg_resolution'      => ['title' => 'Average resolution time (30 days)', 'icon' => 'fa-hourglass-half',  'modules' => ['module_support'], 'size' => 'sm'],
            'csat'                => ['title' => 'Customer satisfaction (30 days)', 'icon' => 'fa-star',             'modules' => ['module_support'], 'size' => 'sm'],
            'assets_by_type'      => ['title' => 'Assets by type',                'icon' => 'fa-laptop',             'modules' => ['module_assets', 'module_support'], 'size' => 'md'],
            'expiring'            => ['title' => 'Expiring warranties, licenses and domains (60 days)', 'icon' => 'fa-calendar-times', 'modules' => ['module_assets', 'module_support', 'module_client'], 'size' => 'lg'],
            'workflow_tasks_due'  => ['title' => 'Workflow tasks due',            'icon' => 'fa-tasks',              'modules' => ['module_client'], 'size' => 'md'],
        ];
    }

    public static function exists(string $id): bool
    {
        return isset(self::definitions()[$id]);
    }

    public static function isAvailable(string $id, WidgetContext $ctx): bool
    {
        $def = self::definitions()[$id] ?? null;
        if (!$def) {
            return false;
        }
        foreach ($def['modules'] as $m) {
            if ($ctx->can($m)) {
                return true;
            }
        }
        return false;
    }

    /** Widget ids this user may see, in registry order. */
    public static function available(WidgetContext $ctx): array
    {
        return array_values(array_filter(array_keys(self::definitions()), static fn ($id) => self::isAvailable($id, $ctx)));
    }

    public static function data(string $id, \mysqli $db, WidgetContext $ctx): ?array
    {
        if (!self::isAvailable($id, $ctx)) {
            return null; // not allowed (or unknown): nothing is queried
        }
        if (in_array($id, ['csat'], true) && !$ctx->csatEnabled) {
            return ['type' => 'stat', 'value' => '-', 'caption' => 'Customer satisfaction surveys are turned off.'];
        }
        return match ($id) {
            'tickets_by_status'   => self::ticketsByStatus($db, $ctx),
            'tickets_by_priority' => self::ticketsByPriority($db, $ctx),
            'sla_at_risk'         => self::slaAtRisk($db, $ctx),
            'my_tickets'          => self::myTickets($db, $ctx),
            'created_vs_closed'   => self::createdVsClosed($db, $ctx),
            'avg_resolution'      => self::avgResolution($db, $ctx),
            'csat'                => self::csat($db, $ctx),
            'assets_by_type'      => self::assetsByType($db, $ctx),
            'expiring'            => self::expiring($db, $ctx),
            'workflow_tasks_due'  => self::workflowTasksDue($db, $ctx),
            default               => null,
        };
    }

    private const OPEN = 'ticket_closed_at IS NULL AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL';

    private static function ticketsByStatus(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT COALESCE(s.ticket_status_name, 'Unknown') AS n, COUNT(*) AS c FROM tickets t LEFT JOIN ticket_statuses s ON s.ticket_status_id = t.ticket_status
            WHERE t.ticket_closed_at IS NULL AND t.ticket_resolved_at IS NULL AND t.ticket_archived_at IS NULL" . $ctx->scope('t.ticket_client_id') . " GROUP BY n ORDER BY c DESC");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No open tickets.'];
    }

    private static function ticketsByPriority(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT COALESCE(NULLIF(ticket_priority, ''), 'None') AS n, COUNT(*) AS c FROM tickets WHERE " . self::OPEN . $ctx->scope('ticket_client_id') . " GROUP BY n ORDER BY FIELD(n, 'High', 'Medium', 'Low', 'None'), n");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No open tickets.'];
    }

    private static function slaAtRisk(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_sla_resolution_due FROM tickets
            WHERE " . self::OPEN . " AND ticket_sla_resolution_due IS NOT NULL AND ticket_sla_paused_at IS NULL AND ticket_sla_resolution_due <= NOW() + INTERVAL 4 HOUR"
            . $ctx->scope('ticket_client_id') . " ORDER BY ticket_sla_resolution_due ASC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['ticket_prefix'] . $r['ticket_number'], $r['ticket_subject'], $r['ticket_sla_resolution_due']], 'href' => '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id']];
        }
        return ['type' => 'table', 'columns' => ['Ticket', 'Subject', 'Due'], 'rows' => $rows, 'empty' => 'Nothing is close to breaching its SLA.'];
    }

    private static function myTickets(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_priority FROM tickets
            WHERE " . self::OPEN . " AND ticket_assigned_to = " . (int) $ctx->userId . $ctx->scope('ticket_client_id') . " ORDER BY ticket_updated_at DESC, ticket_id DESC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['ticket_prefix'] . $r['ticket_number'], $r['ticket_subject'], (string) $r['ticket_priority']], 'href' => '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id']];
        }
        return ['type' => 'table', 'columns' => ['Ticket', 'Subject', 'Priority'], 'rows' => $rows, 'empty' => 'No open tickets assigned to you.'];
    }

    private static function createdVsClosed(\mysqli $db, WidgetContext $ctx): array
    {
        $scope = $ctx->scope('ticket_client_id');
        $created = $closed = [];
        $res = mysqli_query($db, "SELECT DATE(ticket_created_at) d, COUNT(*) c FROM tickets WHERE ticket_created_at >= CURDATE() - INTERVAL 29 DAY AND ticket_archived_at IS NULL$scope GROUP BY d");
        while ($r = mysqli_fetch_assoc($res)) { $created[$r['d']] = (int) $r['c']; }
        $res = mysqli_query($db, "SELECT DATE(ticket_closed_at) d, COUNT(*) c FROM tickets WHERE ticket_closed_at >= CURDATE() - INTERVAL 29 DAY AND ticket_archived_at IS NULL$scope GROUP BY d");
        while ($r = mysqli_fetch_assoc($res)) { $closed[$r['d']] = (int) $r['c']; }
        $labels = $a = $b = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i day"));
            $labels[] = date('M j', strtotime($d));
            $a[] = $created[$d] ?? 0;
            $b[] = $closed[$d] ?? 0;
        }
        return ['type' => 'series', 'labels' => $labels, 'a' => $a, 'b' => $b, 'a_label' => 'Created', 'b_label' => 'Closed'];
    }

    private static function avgResolution(\mysqli $db, WidgetContext $ctx): array
    {
        // Resolution-time rules (CHANGELOG 26.10.17): only resolved tickets count, the clock runs from the (re)open
        // start to the resolved date, and project-linked tickets are left out when that setting is on.
        $rs = ticketResolutionStartSql(); $re = ticketResolutionEndSql(); $ro = ticketResolvedOnlySql();
        $project = $ctx->excludeProjects ? ' AND ticket_project_id = 0' : '';
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, $rs, $re)) / 60, 1) AS h, COUNT(*) AS c FROM tickets
            WHERE $ro AND $re >= NOW() - INTERVAL 30 DAY AND ticket_archived_at IS NULL$project" . $ctx->scope('ticket_client_id')));
        $n = (int) ($row['c'] ?? 0);
        return ['type' => 'stat', 'value' => $n > 0 ? ((float) $row['h']) . ' h' : '-', 'caption' => $n > 0 ? "across $n resolved ticket" . ($n === 1 ? '' : 's') : 'No tickets resolved in the last 30 days.'];
    }

    private static function csat(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(ticket_csat_rating) AS n, AVG(ticket_csat_rating) AS a FROM tickets
            WHERE ticket_csat_rated_at >= NOW() - INTERVAL 30 DAY AND ticket_archived_at IS NULL" . $ctx->scope('ticket_client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => $n > 0 ? round((float) $row['a'], 2) . ' / 5' : '-', 'caption' => $n > 0 ? "from $n rating" . ($n === 1 ? '' : 's') : 'No ratings in the last 30 days.'];
    }

    private static function assetsByType(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT asset_type AS n, COUNT(*) AS c FROM assets WHERE asset_archived_at IS NULL" . $ctx->scope('asset_client_id') . " GROUP BY asset_type ORDER BY c DESC LIMIT 12");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No assets.'];
    }

    private static function expiring(\mysqli $db, WidgetContext $ctx): array
    {
        $rows = [];
        $assetsOk = $ctx->can('module_assets') || $ctx->can('module_support');
        if ($assetsOk) {
            $res = mysqli_query($db, "SELECT asset_id, asset_name, asset_warranty_expire AS d FROM assets WHERE asset_archived_at IS NULL AND asset_warranty_expire IS NOT NULL
                AND asset_warranty_expire <= CURDATE() + INTERVAL 60 DAY" . $ctx->scope('asset_client_id') . " ORDER BY d ASC LIMIT 8");
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = ['cells' => ['Warranty', $r['asset_name'], $r['d']], 'href' => '/agent/asset_details.php?asset_id=' . (int) $r['asset_id']];
            }
        }
        if ($ctx->can('module_client')) {
            $res = mysqli_query($db, "SELECT software_id, software_name, software_expire AS d FROM software WHERE software_archived_at IS NULL AND software_expire IS NOT NULL
                AND software_expire <= CURDATE() + INTERVAL 60 DAY" . $ctx->scope('software_client_id') . " ORDER BY d ASC LIMIT 8");
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = ['cells' => ['License', $r['software_name'], $r['d']]];
            }
            $res = mysqli_query($db, "SELECT domain_id, domain_name, domain_expire AS d FROM domains WHERE domain_archived_at IS NULL AND domain_expire IS NOT NULL
                AND domain_expire <= CURDATE() + INTERVAL 60 DAY" . $ctx->scope('domain_client_id') . " ORDER BY d ASC LIMIT 8");
            while ($r = mysqli_fetch_assoc($res)) {
                $rows[] = ['cells' => ['Domain', $r['domain_name'], $r['d']]];
            }
        }
        usort($rows, static fn ($x, $y) => strcmp($x['cells'][2], $y['cells'][2]));
        return ['type' => 'table', 'columns' => ['Type', 'Name', 'Expires'], 'rows' => array_slice($rows, 0, 15), 'empty' => 'Nothing expires in the next 60 days.'];
    }

    private static function workflowTasksDue(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT wt.run_task_id, wt.run_id, wt.title, wt.due_at, c.contact_name FROM workflow_run_tasks wt
            JOIN workflow_runs wr ON wr.run_id = wt.run_id JOIN contacts c ON c.contact_id = wr.contact_id
            WHERE wr.status = 'in_progress' AND wt.status IN ('pending','running','blocked') AND wt.due_at IS NOT NULL AND wt.due_at <= NOW() + INTERVAL 7 DAY"
            . $ctx->scope('c.contact_client_id') . " ORDER BY wt.due_at ASC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['title'], $r['contact_name'], $r['due_at']], 'href' => '/agent/workflow_run.php?run_id=' . (int) $r['run_id']];
        }
        return ['type' => 'table', 'columns' => ['Task', 'Person', 'Due'], 'rows' => $rows, 'empty' => 'No workflow tasks due in the next 7 days.'];
    }
}
