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

    // Widgets whose data() shape is 'bars' or 'series' can also render as a Chart.js chart (WidgetRenderer) -
    // everything else (stat/table) has nothing to switch between, so chartOptions() returns [].
    private const BARS_WIDGETS = ['tickets_by_status', 'tickets_by_priority', 'assets_by_type', 'rmm_alerts_open', 'rmm_patches_pending', 'training_achievements_month',
        'assets_by_status', 'tickets_by_category', 'tickets_by_technician_open'];
    private const SERIES_WIDGETS = ['created_vs_closed', 'tickets_flow_year'];
    // 'multiseries' widgets (3+ datasets) have no plain-HTML fallback, so unlike the lists above they always
    // render as a Chart.js canvas - bar/line is a style choice, not "chart vs no chart".
    private const MULTISERIES_WIDGETS = [];

    // Grouping only - for "Add a widget" (agent/my_dashboard.php), so 55 widgets (and counting) reads as a few
    // short labeled rows instead of one giant wrapped wall. Every definitions() id appears in exactly one group;
    // tests/reports_widgets.php asserts that, so a new widget added to one list without the other fails loudly.
    private const CATEGORIES = [
        'Tickets' => ['tickets_by_status', 'tickets_by_priority', 'sla_at_risk', 'my_tickets', 'created_vs_closed', 'avg_resolution', 'csat',
            'tickets_opened_today', 'tickets_resolved_week', 'tickets_waiting_on_customer', 'tickets_flow_year', 'tickets_by_category',
            'tickets_by_technician_open', 'tickets_created_year', 'tickets_resolved_year', 'avg_resolution_year', 'tickets_resolved_by_technician_year',
            'tickets_recently_resolved', 'tickets_automation_recent'],
        'Assets'    => ['assets_by_type', 'expiring', 'assets_by_status', 'assets_added_year'],
        'Departments' => ['departments_active', 'departments_added_year', 'contacts_added_year', 'workflow_runs_in_progress', 'workflow_tasks_due'],
        'RMM'       => ['rmm_devices_offline', 'rmm_alerts_open', 'rmm_patches_pending'],
        'Training'  => ['training_expiring', 'training_overdue', 'training_achievements_month'],
        'Projects'  => ['projects_open', 'project_tasks_due', 'project_tasks_overdue'],
        'General'   => ['needs_attention'],
    ];

    /** @return array<string,string[]> category label => widget ids, in display order. */
    public static function categories(): array
    {
        return self::CATEGORIES;
    }

    /** @return array<string,string> value => label, in default-first order; [] if this widget has no alternate chart type. */
    public static function chartOptions(string $id): array
    {
        if (in_array($id, self::BARS_WIDGETS, true)) {
            return ['list' => 'List', 'bar' => 'Bar', 'pie' => 'Pie'];
        }
        if (in_array($id, self::SERIES_WIDGETS, true)) {
            return ['bar' => 'Bar', 'line' => 'Line'];
        }
        if (in_array($id, self::MULTISERIES_WIDGETS, true)) {
            return ['line' => 'Line', 'bar' => 'Bar'];
        }
        return [];
    }

    public static function defaultChart(string $id): ?string
    {
        $opts = self::chartOptions($id);
        return $opts ? array_key_first($opts) : null;
    }

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
            'rmm_devices_offline' => ['title' => 'RMM devices offline',           'icon' => 'fa-plug',               'modules' => ['module_rmm'], 'size' => 'sm'],
            'rmm_alerts_open'     => ['title' => 'Open RMM alerts',               'icon' => 'fa-bell',               'modules' => ['module_rmm', 'module_rmm_alerts'], 'size' => 'md'],
            'rmm_patches_pending' => ['title' => 'Patches pending',               'icon' => 'fa-shield-alt',         'modules' => ['module_rmm'], 'size' => 'md'],
            'training_expiring'   => ['title' => 'Training expiring (60 days)',   'icon' => 'fa-calendar-times',     'modules' => ['module_training'], 'size' => 'lg'],
            'training_overdue'    => ['title' => 'Overdue required training',     'icon' => 'fa-exclamation-triangle', 'modules' => ['module_training'], 'size' => 'sm'],
            'training_achievements_month' => ['title' => 'Achievements this month', 'icon' => 'fa-award',            'modules' => ['module_training'], 'size' => 'md'],
            'projects_open'       => ['title' => 'Open projects',                 'icon' => 'fa-project-diagram',    'modules' => ['module_support'], 'size' => 'sm'],
            'project_tasks_due'   => ['title' => 'Project tasks due (7 days)',     'icon' => 'fa-tasks',              'modules' => ['module_support'], 'size' => 'md'],
            'project_tasks_overdue' => ['title' => 'Overdue project tasks',       'icon' => 'fa-exclamation-triangle', 'modules' => ['module_support'], 'size' => 'md'],

            // Merged in from the classic dashboard (agent/dashboard.php, now a redirect to this page) - see
            // CHANGELOG. 'Needs attention' is a composite: it draws on whichever of these modules the user has,
            // same as the strip it replaces, which simply omitted an item the user couldn't see.
            'needs_attention'     => ['title' => 'Needs attention',               'icon' => 'fa-bolt',               'modules' => ['module_support', 'module_client', 'module_training'], 'size' => 'lg'],
            'departments_active'  => ['title' => 'Active departments',            'icon' => 'fa-building',           'modules' => ['module_client'], 'size' => 'sm'],
            'departments_added_year' => ['title' => 'Departments added this year', 'icon' => 'fa-users',             'modules' => ['module_client'], 'size' => 'sm'],
            'contacts_added_year' => ['title' => 'New contacts this year',        'icon' => 'fa-user',               'modules' => ['module_client'], 'size' => 'sm'],
            'workflow_runs_in_progress' => ['title' => 'Onboarding / offboarding in progress', 'icon' => 'fa-tasks', 'modules' => ['module_client'], 'size' => 'lg'],
            'assets_by_status'    => ['title' => 'Assets by status',              'icon' => 'fa-desktop',            'modules' => ['module_assets', 'module_support'], 'size' => 'md'],
            'assets_added_year'   => ['title' => 'New assets this year',          'icon' => 'fa-desktop',            'modules' => ['module_assets', 'module_support'], 'size' => 'sm'],

            'tickets_opened_today'   => ['title' => 'Tickets opened today',       'icon' => 'fa-plus-circle',        'modules' => ['module_support'], 'size' => 'sm'],
            'tickets_resolved_week'  => ['title' => 'Tickets resolved this week', 'icon' => 'fa-check-circle',       'modules' => ['module_support'], 'size' => 'sm'],
            'tickets_waiting_on_customer' => ['title' => 'Waiting on employee',   'icon' => 'fa-clock',              'modules' => ['module_support'], 'size' => 'sm'],
            'tickets_flow_year'   => ['title' => 'Tickets opened vs resolved (this year)', 'icon' => 'fa-chart-line', 'modules' => ['module_support'], 'size' => 'lg'],
            'tickets_by_category' => ['title' => 'Open tickets by category',      'icon' => 'fa-chart-pie',          'modules' => ['module_support'], 'size' => 'md'],
            'tickets_by_technician_open' => ['title' => 'Open tickets by technician', 'icon' => 'fa-users',          'modules' => ['module_support'], 'size' => 'md'],
            'tickets_created_year' => ['title' => 'Tickets created this year',    'icon' => 'fa-ticket-alt',         'modules' => ['module_support'], 'size' => 'sm'],
            'tickets_resolved_year' => ['title' => 'Tickets resolved this year',  'icon' => 'fa-check-double',       'modules' => ['module_support'], 'size' => 'sm'],
            'avg_resolution_year' => ['title' => 'Average resolution time (this year)', 'icon' => 'fa-stopwatch',    'modules' => ['module_support'], 'size' => 'sm'],
            'tickets_resolved_by_technician_year' => ['title' => 'Resolved by technician (this year)', 'icon' => 'fa-user-check', 'modules' => ['module_support'], 'size' => 'md'],
            'tickets_recently_resolved' => ['title' => 'Recently resolved tickets', 'icon' => 'fa-history',          'modules' => ['module_support'], 'size' => 'lg'],
            'tickets_automation_recent' => ['title' => 'My recent automation activity', 'icon' => 'fa-history',      'modules' => ['module_support'], 'size' => 'lg'],
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
        if ($id === 'avg_resolution_year' && !$ctx->avgResolutionEnabled) {
            return ['type' => 'stat', 'value' => '-', 'caption' => 'Average resolution time is turned off.'];
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
            'rmm_devices_offline' => self::rmmDevicesOffline($db, $ctx),
            'rmm_alerts_open'     => self::rmmAlertsOpen($db, $ctx),
            'rmm_patches_pending' => self::rmmPatchesPending($db, $ctx),
            'training_expiring'   => self::trainingExpiring($db, $ctx),
            'training_overdue'    => self::trainingOverdue($db, $ctx),
            'training_achievements_month' => self::trainingAchievementsMonth($db, $ctx),
            'projects_open'       => self::projectsOpen($db, $ctx),
            'project_tasks_due'   => self::projectTasksDue($db, $ctx),
            'project_tasks_overdue' => self::projectTasksOverdue($db, $ctx),

            'needs_attention'     => self::needsAttention($db, $ctx),
            'departments_active'  => self::departmentsActive($db, $ctx),
            'departments_added_year' => self::departmentsAddedYear($db, $ctx),
            'contacts_added_year' => self::contactsAddedYear($db, $ctx),
            'workflow_runs_in_progress' => self::workflowRunsInProgress($db, $ctx),
            'assets_by_status'    => self::assetsByStatus($db, $ctx),
            'assets_added_year'   => self::assetsAddedYear($db, $ctx),

            'tickets_opened_today'   => self::ticketsOpenedToday($db, $ctx),
            'tickets_resolved_week'  => self::ticketsResolvedWeek($db, $ctx),
            'tickets_waiting_on_customer' => self::ticketsWaitingOnCustomer($db, $ctx),
            'tickets_flow_year'   => self::ticketsFlowYear($db, $ctx),
            'tickets_by_category' => self::ticketsByCategory($db, $ctx),
            'tickets_by_technician_open' => self::ticketsByTechnicianOpen($db, $ctx),
            'tickets_created_year' => self::ticketsCreatedYear($db, $ctx),
            'tickets_resolved_year' => self::ticketsResolvedYear($db, $ctx),
            'avg_resolution_year' => self::avgResolutionYear($db, $ctx),
            'tickets_resolved_by_technician_year' => self::ticketsResolvedByTechnicianYear($db, $ctx),
            'tickets_recently_resolved' => self::ticketsRecentlyResolved($db, $ctx),
            'tickets_automation_recent' => self::ticketsAutomationRecent($db, $ctx),
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

    // ------------------------------------------------------------------------------------------------ RMM

    private static function rmmDevicesOffline(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM endpoint_agent_devices
            WHERE link_state = 'linked' AND revoked_at IS NULL AND retired_at IS NULL
              AND (last_checkin_at IS NULL OR last_checkin_at < NOW() - INTERVAL 15 MINUTE)" . $ctx->scope('client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => $n === 1 ? 'device offline 15+ minutes' : 'devices offline 15+ minutes'];
    }

    private static function rmmAlertsOpen(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT COALESCE(NULLIF(a.severity, ''), 'Unknown') AS n, COUNT(*) AS c FROM rmm_alerts a
            WHERE a.status <> 'resolved' AND a.resolved_at IS NULL" . $ctx->scope('a.client_id') . " GROUP BY n ORDER BY FIELD(n, 'critical', 'warning', 'info', 'Unknown'), n");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => ucfirst((string) $r['n']), 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No open RMM alerts.'];
    }

    private static function rmmPatchesPending(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT pa.status AS n, COUNT(*) AS c FROM rmm_patch_available pa
            JOIN endpoint_agent_devices d ON d.device_id = pa.device_id
            WHERE pa.status IN ('available', 'approved') AND d.link_state = 'linked' AND d.revoked_at IS NULL AND d.retired_at IS NULL"
            . $ctx->scope('d.client_id') . " GROUP BY pa.status");
        $counts = ['available' => 0, 'approved' => 0];
        while ($r = mysqli_fetch_assoc($res)) { $counts[$r['n']] = (int) $r['c']; }
        $items = [
            ['label' => 'Pending approval', 'value' => $counts['available']],
            ['label' => 'Approved, not yet installed', 'value' => $counts['approved']],
        ];
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No patches pending.'];
    }

    // ------------------------------------------------------------------------------------------------ Training

    private static function trainingExpiring(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT c.completion_id, c.completion_snap_contact_name, c.completion_snap_course_name, c.completion_expires_on
            FROM training_completions c LEFT JOIN training_completion_voids v ON v.cvoid_completion_id = c.completion_id
            WHERE v.cvoid_id IS NULL AND c.completion_expires_on IS NOT NULL AND c.completion_expires_on <= CURDATE() + INTERVAL 60 DAY"
            . $ctx->scope('c.completion_snap_client_id') . " ORDER BY c.completion_expires_on ASC LIMIT 15");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['completion_snap_contact_name'], $r['completion_snap_course_name'], $r['completion_expires_on']],
                'href' => '/agent/training_record.php?id=' . (int) $r['completion_id']];
        }
        return ['type' => 'table', 'columns' => ['Person', 'Course', 'Expires'], 'rows' => $rows, 'empty' => 'Nothing expires in the next 60 days.'];
    }

    private static function trainingOverdue(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM training_assignments ta JOIN contacts c ON c.contact_id = ta.tassign_contact_id
            WHERE ta.tassign_status = 'open' AND ta.tassign_required = 1 AND ta.tassign_due_on < CURDATE()" . $ctx->scope('c.contact_client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => $n === 1 ? 'required training overdue' : 'required trainings overdue'];
    }

    private static function trainingAchievementsMonth(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT a.taward_snap_name AS n, COUNT(*) AS c FROM training_achievement_awards a JOIN contacts c ON c.contact_id = a.taward_contact_id
            WHERE a.taward_awarded_at_utc >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01')" . $ctx->scope('c.contact_client_id') . " GROUP BY n ORDER BY c DESC LIMIT 12");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No achievements earned yet this month.'];
    }

    // ------------------------------------------------------------------------------------------------ Projects

    private static function projectsOpen(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM projects
            WHERE project_completed_at IS NULL AND project_archived_at IS NULL" . $ctx->scope('project_client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => $n === 1 ? 'open project' : 'open projects'];
    }

    private static function projectTasksDue(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_due_at FROM tickets
            WHERE " . self::OPEN . " AND ticket_project_id <> 0 AND ticket_due_at IS NOT NULL AND ticket_due_at <= NOW() + INTERVAL 7 DAY"
            . $ctx->scope('ticket_client_id') . " ORDER BY ticket_due_at ASC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['ticket_prefix'] . $r['ticket_number'], $r['ticket_subject'], $r['ticket_due_at']], 'href' => '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id']];
        }
        return ['type' => 'table', 'columns' => ['Task', 'Subject', 'Due'], 'rows' => $rows, 'empty' => 'No project tasks due in the next 7 days.'];
    }

    private static function projectTasksOverdue(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_due_at FROM tickets
            WHERE " . self::OPEN . " AND ticket_project_id <> 0 AND ticket_due_at IS NOT NULL AND ticket_due_at < NOW()"
            . $ctx->scope('ticket_client_id') . " ORDER BY ticket_due_at ASC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['ticket_prefix'] . $r['ticket_number'], $r['ticket_subject'], $r['ticket_due_at']], 'href' => '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id']];
        }
        return ['type' => 'table', 'columns' => ['Task', 'Subject', 'Was due'], 'rows' => $rows, 'empty' => 'No overdue project tasks.'];
    }

    // ------------------------------------------------------------------------------------------------ Merged in from the classic dashboard (agent/dashboard.php)
    //
    // Tightened vs. the page these replace: "Active departments", "Departments/contacts/assets added this
    // year" and "Unpaid invoices" used to run unscoped (every department's numbers, even for a department-
    // restricted user) - every other widget in this registry is department-scoped, so that was the odd one
    // out rather than a deliberate exception, and it's fixed here rather than carried forward. Financial
    // widgets (tagged in FINANCE_WIDGETS) stay unscoped, matching the page they replace: financial totals are
    // a whole-company view for whoever can see financial data at all, the same way Admin > Reports' financial
    // reports work.

    private static function needsAttention(\mysqli $db, WidgetContext $ctx): array
    {
        $items = [];
        if ($ctx->can('module_support')) {
            $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets WHERE " . self::OPEN . " AND (ticket_assigned_to IS NULL OR ticket_assigned_to = 0)" . $ctx->scope('ticket_client_id')));
            $items[] = ['label' => 'Unassigned tickets', 'value' => (int) ($row['n'] ?? 0), 'href' => '/agent/tickets.php?assigned=0'];
            $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM domains WHERE domain_expire IS NOT NULL AND domain_expire > CURDATE() AND domain_expire < CURDATE() + INTERVAL 30 DAY AND domain_archived_at IS NULL" . $ctx->scope('domain_client_id')));
            $items[] = ['label' => 'Domains expiring (30d)', 'value' => (int) ($row['n'] ?? 0), 'href' => '/agent/domains.php?sort=domain_expire&order=ASC'];
            $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM certificates WHERE certificate_expire IS NOT NULL AND certificate_expire > CURDATE() AND certificate_expire < CURDATE() + INTERVAL 30 DAY AND certificate_archived_at IS NULL" . $ctx->scope('certificate_client_id')));
            $items[] = ['label' => 'Certificates expiring (30d)', 'value' => (int) ($row['n'] ?? 0), 'href' => '/agent/certificates.php?sort=certificate_expire&order=ASC'];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'Nothing needs attention right now.'];
    }

    private static function departmentsActive(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM clients WHERE client_archived_at IS NULL" . $ctx->scope('client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => $n === 1 ? 'active department' : 'active departments'];
    }

    private static function departmentsAddedYear(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM clients WHERE YEAR(client_created_at) = {$ctx->year} AND client_archived_at IS NULL" . $ctx->scope('client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => "added in {$ctx->year}"];
    }

    private static function contactsAddedYear(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM contacts WHERE YEAR(contact_created_at) = {$ctx->year}" . $ctx->scope('contact_client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => "added in {$ctx->year}"];
    }

    private static function workflowRunsInProgress(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT wr.run_id, wr.type, wr.started_at, c.contact_name, cl.client_name FROM workflow_runs wr
            INNER JOIN contacts c ON c.contact_id = wr.contact_id LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE wr.status = 'in_progress'" . $ctx->scope('c.contact_client_id') . " ORDER BY wr.started_at ASC LIMIT 15");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $rows[] = ['cells' => [$r['contact_name'], ['text' => ucfirst($r['type']), 'badge' => $r['type'] === 'onboarding' ? 'success' : 'danger'], $r['client_name'], $r['started_at']],
                'href' => '/agent/workflow_run.php?run_id=' . (int) $r['run_id']];
        }
        return ['type' => 'table', 'columns' => ['Person', 'Type', 'Department', 'Started'], 'rows' => $rows, 'empty' => 'No onboarding or offboarding runs in progress.'];
    }

    private static function assetsByStatus(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT COALESCE(asset_status, 'Unknown') AS n, COUNT(*) AS c FROM assets WHERE asset_archived_at IS NULL"
            . $ctx->scope('asset_client_id') . " GROUP BY n ORDER BY c DESC");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No assets found.'];
    }

    private static function assetsAddedYear(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM assets WHERE YEAR(asset_created_at) = {$ctx->year}" . $ctx->scope('asset_client_id')));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => "added in {$ctx->year}"];
    }

    // ------------------------------------------------------------------------------------------------ Tickets (classic dashboard's "Technical" section)

    private static function ticketsOpenedToday(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND DATE(ticket_created_at) = CURDATE()"));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => 'opened today'];
    }

    private static function ticketsResolvedWeek(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND ticket_closed_at IS NOT NULL AND ticket_closed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => 'resolved in the last 7 days'];
    }

    private static function ticketsWaitingOnCustomer(\mysqli $db, WidgetContext $ctx): array
    {
        // "Waiting on Employee" is this install's name for upstream's "Waiting on Customer" status; matched by
        // name (either counts) since setup does not seed this status at all.
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets LEFT JOIN ticket_statuses ON ticket_status = ticket_status_id
            WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND ticket_closed_at IS NULL AND ticket_status_name IN ('Waiting on Employee', 'Waiting on Customer')"));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => 'open tickets waiting on the employee'];
    }

    private static function ticketsFlowYear(\mysqli $db, WidgetContext $ctx): array
    {
        $scope = $ctx->scope('ticket_client_id');
        $opened = $resolved = [];
        for ($m = 1; $m <= 12; $m++) {
            $r = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS c FROM tickets WHERE 1 = 1$scope AND YEAR(ticket_created_at) = {$ctx->year} AND MONTH(ticket_created_at) = $m"));
            $opened[] = (int) $r['c'];
            $r = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS c FROM tickets WHERE 1 = 1$scope AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = {$ctx->year} AND MONTH(ticket_closed_at) = $m"));
            $resolved[] = (int) $r['c'];
        }
        return ['type' => 'series', 'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], 'a' => $opened, 'b' => $resolved, 'a_label' => 'Opened', 'b_label' => 'Resolved'];
    }

    private static function ticketsByCategory(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT COALESCE(category_name, 'Uncategorized') AS n, COUNT(*) AS c FROM tickets LEFT JOIN categories ON ticket_category = category_id
            WHERE " . self::OPEN . $ctx->scope('ticket_client_id') . " GROUP BY n ORDER BY c DESC LIMIT 8");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No open tickets.'];
    }

    private static function ticketsByTechnicianOpen(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT user_name AS n, COUNT(*) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id
            WHERE " . self::OPEN . $ctx->scope('ticket_client_id') . " AND ticket_assigned_to > 0 GROUP BY n ORDER BY c DESC LIMIT 8");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => 'No open tickets are assigned to a technician.'];
    }

    private static function ticketsCreatedYear(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND YEAR(ticket_created_at) = {$ctx->year}"));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => "created in {$ctx->year}"];
    }

    private static function ticketsResolvedYear(\mysqli $db, WidgetContext $ctx): array
    {
        $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT COUNT(*) AS n FROM tickets WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = {$ctx->year}"));
        $n = (int) ($row['n'] ?? 0);
        return ['type' => 'stat', 'value' => (string) $n, 'caption' => "resolved in {$ctx->year}"];
    }

    private static function avgResolutionYear(\mysqli $db, WidgetContext $ctx): array
    {
        $hours = getAvgResolutionTimeHours($db, $ctx->year);
        return ['type' => 'stat', 'value' => $hours > 0 ? $hours . ' h' : '-', 'caption' => $hours > 0 ? "across {$ctx->year}" : "No tickets resolved in {$ctx->year}."];
    }

    private static function ticketsResolvedByTechnicianYear(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT user_name AS n, COUNT(*) AS c FROM tickets LEFT JOIN users ON ticket_assigned_to = user_id
            WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND ticket_closed_at IS NOT NULL AND YEAR(ticket_closed_at) = {$ctx->year} AND ticket_assigned_to > 0
            GROUP BY n ORDER BY c DESC LIMIT 8");
        $items = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $items[] = ['label' => $r['n'], 'value' => (int) $r['c']];
        }
        return ['type' => 'bars', 'items' => $items, 'empty' => "No tickets resolved in {$ctx->year} yet."];
    }

    private static function ticketsRecentlyResolved(\mysqli $db, WidgetContext $ctx): array
    {
        $res = mysqli_query($db, "SELECT tickets.ticket_id, ticket_subject, ticket_prefix, ticket_number, ticket_closed_at, ticket_priority, client_name
            FROM tickets LEFT JOIN clients ON ticket_client_id = client_id
            WHERE 1 = 1" . $ctx->scope('ticket_client_id') . " AND ticket_closed_at IS NOT NULL ORDER BY ticket_closed_at DESC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $color = $r['ticket_priority'] === 'High' ? 'danger' : ($r['ticket_priority'] === 'Medium' ? 'warning' : 'info');
            $rows[] = ['cells' => [$r['ticket_prefix'] . $r['ticket_number'], $r['ticket_subject'], $r['client_name'], ['text' => $r['ticket_priority'], 'badge' => $color]],
                'href' => '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id']];
        }
        return ['type' => 'table', 'columns' => ['#', 'Subject', 'Department', 'Priority'], 'rows' => $rows, 'empty' => 'No tickets resolved yet.'];
    }

    private static function ticketsAutomationRecent(\mysqli $db, WidgetContext $ctx): array
    {
        $labels = ['schedule' => 'Scheduled check', 'rmm_alert' => 'New RMM alert', 'asset_offline' => 'Asset offline', 'asset_online' => 'Asset online'];
        $res = mysqli_query($db, "SELECT r.id, r.rule_name, r.trigger_type, r.summary, r.created_at, r.ticket_id, t.ticket_subject, t.ticket_prefix, t.ticket_number
            FROM ticket_automation_runs r INNER JOIN tickets t ON t.ticket_id = r.ticket_id
            WHERE t.ticket_assigned_to = {$ctx->userId}" . $ctx->scope('t.ticket_client_id') . " ORDER BY r.id DESC LIMIT 10");
        $rows = [];
        while ($r = mysqli_fetch_assoc($res)) {
            $ticketLabel = $r['ticket_id'] ? $r['ticket_prefix'] . $r['ticket_number'] . ' ' . $r['ticket_subject'] : '-';
            $rows[] = ['cells' => [$r['rule_name'], ['text' => $labels[$r['trigger_type']] ?? $r['trigger_type'], 'badge' => 'info'], $ticketLabel, $r['summary']],
                'href' => $r['ticket_id'] ? '/agent/ticket.php?ticket_id=' . (int) $r['ticket_id'] : null];
        }
        return ['type' => 'table', 'columns' => ['Rule', 'Trigger', 'Ticket', 'Result'], 'rows' => $rows, 'empty' => 'No automation activity on your tickets yet.'];
    }
}
