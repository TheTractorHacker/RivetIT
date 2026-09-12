<?php
// GET /api/v1/projects         list
// GET /api/v1/projects/{id}    detail
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';
if ($method !== 'GET') api_error(405, 'Method not allowed');

$uid = $api_user_id;
api_require_module_permission($mysqli, $uid, 'module_support');

// Client-scope restriction, mirroring tickets.php/assets.php - with the
// project_client_id = 0 ("internal project", no department) carve-out that
// agent/projects.php also applies, so scoped API callers don't lose visibility
// into internal projects they can see on the web.
$project_client_scope_clause = '(' . api_client_scope_sql('p.project_client_id') . ' OR p.project_client_id = 0)';

if ($id !== null) {
    $project_access_check = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT p.project_id FROM projects p WHERE p.project_id = $id AND $project_client_scope_clause LIMIT 1"
    ));
    if (!$project_access_check) api_error(403, 'Access denied');
}

if ($id === null) {
    $page    = max(1, intval($_GET['page'] ?? 1));
    $limit   = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset  = ($page - 1) * $limit;
    $search    = mysqli_real_escape_string($mysqli, $_GET['search'] ?? '');
    $status    = $_GET['status'] ?? 'open'; // open|completed|all
    $archived  = isset($_GET['archived']) ? intval($_GET['archived']) : 0;
    $client_id = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
    $mine      = isset($_GET['mine']) ? intval($_GET['mine']) : 0;

    $where = ['1=1', $project_client_scope_clause];
    if ($status === 'open')      $where[] = 'p.project_completed_at IS NULL';
    if ($status === 'completed') $where[] = 'p.project_completed_at IS NOT NULL';
    $where[] = $archived ? 'p.project_archived_at IS NOT NULL' : 'p.project_archived_at IS NULL';
    if ($client_id) $where[] = "p.project_client_id = $client_id";
    if ($mine)       $where[] = "p.project_manager = $uid";
    if ($search)      $where[] = "(CONCAT(p.project_prefix, p.project_number) LIKE '%$search%' OR p.project_name LIKE '%$search%' OR p.project_description LIKE '%$search%' OR u.user_name LIKE '%$search%')";
    $where_sql = implode(' AND ', $where);

    $total = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT COUNT(*) AS c FROM projects p
         LEFT JOIN clients c ON c.client_id = p.project_client_id
         LEFT JOIN users u ON u.user_id = p.project_manager
         WHERE $where_sql"))['c']);

    $projects = [];
    $sql = mysqli_query($mysqli,
        "SELECT p.project_id, p.project_prefix, p.project_number, p.project_name,
                p.project_due, p.project_created_at, p.project_completed_at, p.project_archived_at,
                c.client_name, u.user_name AS manager_name,
                (SELECT COUNT(*) FROM tickets WHERE ticket_project_id = p.project_id) AS ticket_count,
                (SELECT COUNT(*) FROM tickets WHERE ticket_project_id = p.project_id AND ticket_closed_at IS NOT NULL) AS ticket_closed_count,
                (SELECT COUNT(*) FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
                    WHERE tickets.ticket_project_id = p.project_id OR tasks.task_project_id = p.project_id) AS task_count,
                (SELECT COUNT(*) FROM tasks LEFT JOIN tickets ON tickets.ticket_id = tasks.task_ticket_id
                    WHERE (tickets.ticket_project_id = p.project_id OR tasks.task_project_id = p.project_id)
                      AND tasks.task_completed_at IS NOT NULL) AS task_completed_count
         FROM projects p
         LEFT JOIN clients c ON c.client_id = p.project_client_id
         LEFT JOIN users u ON u.user_id = p.project_manager
         WHERE $where_sql
         ORDER BY p.project_name ASC
         LIMIT $limit OFFSET $offset"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $projects[] = [
            'id'                  => intval($row['project_id']),
            'prefix'              => $row['project_prefix'],
            'number'              => intval($row['project_number']),
            'name'                => $row['project_name'],
            'due_at'              => $row['project_due'],
            'created_at'          => $row['project_created_at'],
            'completed_at'        => $row['project_completed_at'],
            'archived_at'         => $row['project_archived_at'],
            'client'              => $row['client_name'],
            'manager'             => $row['manager_name'],
            'ticket_count'        => intval($row['ticket_count']),
            'ticket_closed_count' => intval($row['ticket_closed_count']),
            'task_count'          => intval($row['task_count']),
            'task_completed_count'=> intval($row['task_completed_count']),
        ];
    }
    api_response(200, ['data' => $projects, 'total' => $total, 'page' => $page, 'limit' => $limit]);
}

// Detail
$row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT p.*, c.client_name, u.user_name AS manager_name
     FROM projects p
     LEFT JOIN clients c ON c.client_id = p.project_client_id
     LEFT JOIN users u ON u.user_id = p.project_manager
     WHERE p.project_id = $id LIMIT 1"
));
if (!$row) api_error(404, 'Project not found');

$milestones = [];
$sql = mysqli_query($mysqli,
    "SELECT milestone_id, milestone_name, milestone_description, milestone_due, milestone_order, milestone_status, milestone_completed_at
     FROM project_milestones WHERE milestone_project_id = $id ORDER BY milestone_order ASC, milestone_id ASC"
);
while ($m = mysqli_fetch_assoc($sql)) {
    $milestones[] = [
        'id'           => intval($m['milestone_id']),
        'name'         => $m['milestone_name'],
        'description'  => $m['milestone_description'],
        'due_at'       => $m['milestone_due'],
        'order'        => intval($m['milestone_order']),
        'status'       => $m['milestone_status'],
        'completed_at' => $m['milestone_completed_at'],
    ];
}

$tasks = [];
$sql = mysqli_query($mysqli,
    "SELECT tasks.task_id, tasks.task_name, tasks.task_status, tasks.task_progress, tasks.task_due, tasks.task_start,
            tasks.task_milestone_id, tasks.task_completed_at,
            tt.ticket_prefix, tt.ticket_number, u.user_name AS assigned_to_name
     FROM tasks
     LEFT JOIN tickets tt ON tt.ticket_id = tasks.task_ticket_id
     LEFT JOIN users u ON u.user_id = tasks.task_assigned_to
     WHERE tt.ticket_project_id = $id OR tasks.task_project_id = $id
     ORDER BY tasks.task_order ASC, tasks.task_created_at ASC"
);
while ($t = mysqli_fetch_assoc($sql)) {
    $tasks[] = [
        'id'           => intval($t['task_id']),
        'name'         => $t['task_name'],
        'status'       => $t['task_status'],
        'progress'     => intval($t['task_progress']),
        'due_at'       => $t['task_due'],
        'start_at'     => $t['task_start'],
        'milestone_id' => $t['task_milestone_id'] !== null ? intval($t['task_milestone_id']) : null,
        'completed_at' => $t['task_completed_at'],
        'assigned_to'  => $t['assigned_to_name'],
        'ticket_number'=> $t['ticket_number'] !== null ? $t['ticket_prefix'] . $t['ticket_number'] : null,
    ];
}

$tickets = [];
$sql = mysqli_query($mysqli,
    "SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, t.ticket_subject,
            ts.ticket_status_name, ts.ticket_status_color, t.ticket_due_at, t.ticket_closed_at,
            u.user_name AS assigned_to_name
     FROM tickets t
     LEFT JOIN ticket_statuses ts ON t.ticket_status = ts.ticket_status_id
     LEFT JOIN users u ON t.ticket_assigned_to = u.user_id
     WHERE t.ticket_project_id = $id
     ORDER BY t.ticket_created_at DESC"
);
while ($t = mysqli_fetch_assoc($sql)) {
    $tickets[] = [
        'id'           => intval($t['ticket_id']),
        'number'       => $t['ticket_prefix'] . $t['ticket_number'],
        'subject'      => $t['ticket_subject'],
        'status'       => $t['ticket_status_name'],
        'status_color' => $t['ticket_status_color'],
        'due_at'       => $t['ticket_due_at'],
        'closed_at'    => $t['ticket_closed_at'],
        'assigned_to'  => $t['assigned_to_name'],
    ];
}

api_response(200, [
    'id'               => intval($row['project_id']),
    'prefix'           => $row['project_prefix'],
    'number'           => intval($row['project_number']),
    'name'             => $row['project_name'],
    'description'      => $row['project_description'] ?? '',
    'due_at'           => $row['project_due'],
    'start_at'         => $row['project_start'],
    'created_at'       => $row['project_created_at'],
    'updated_at'       => $row['project_updated_at'],
    'completed_at'     => $row['project_completed_at'],
    'archived_at'      => $row['project_archived_at'],
    'client'           => $row['client_name'],
    'manager'          => $row['manager_name'],
    'estimated_hours'  => $row['project_estimated_hours'] !== null ? floatval($row['project_estimated_hours']) : null,
    'budget_amount'    => $row['project_budget_amount'] !== null ? floatval($row['project_budget_amount']) : null,
    'hourly_rate'      => $row['project_hourly_rate'] !== null ? floatval($row['project_hourly_rate']) : null,
    'milestones'       => $milestones,
    'tasks'            => $tasks,
    'tickets'          => $tickets,
]);
