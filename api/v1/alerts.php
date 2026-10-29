<?php
// GET  /api/v1/alerts   - list RMM + backup alerts (combined)
// POST /api/v1/alerts   - body: {source: 'rmm'|'backup', id: <int>, action: 'acknowledge'|'resolve'}
defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

// Require module_rmm_alerts permission (mirrors enforceUserPermission on the web page). An integration client is decided by its scopes
// inside api_has_module_permission(); a user by the role lookup there (admin roles always pass).
api_require_module_permission($mysqli, intval($api_user_id), 'module_rmm_alerts', 1);

if ($method === 'GET') {
    $filter_status   = $_GET['status'] ?? 'new';
    $filter_severity = $_GET['severity'] ?? '';
    $filter_source   = $_GET['source'] ?? 'all';
    $filter_client   = intval($_GET['client_id'] ?? 0);

    // Client-restricted API users (rows in user_client_permissions) should only
    // ever see alerts for clients they're permitted to access - unrestricted
    // users (no rows) see everything, matching enforceClientAccess()'s model.
    // Alerts with no client_id at all aren't tied to any specific client, so
    // they stay visible to everyone rather than being hidden by the scoping.
    $client_scope_for = fn(string $col) => "($col IS NULL OR " . api_client_scope_sql($col) . ")";

    $alerts = [];

    if ($filter_source === 'all' || $filter_source === 'rmm') {
        $where = "1=1 AND " . $client_scope_for('a.client_id');
        if ($filter_status && $filter_status !== 'all') {
            $where .= " AND a.status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
        }
        if ($filter_severity) { $where .= " AND a.severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'"; }
        if ($filter_client)   { $where .= " AND a.client_id=$filter_client"; }

        $sql = mysqli_query($mysqli,
            "SELECT a.*, ast.asset_name, c.client_name, t.ticket_prefix, t.ticket_number
             FROM rmm_alerts a
             LEFT JOIN assets ast ON ast.asset_id = a.asset_id
             LEFT JOIN clients c ON c.client_id = a.client_id
             LEFT JOIN tickets t ON t.ticket_id = a.ticket_id
             WHERE $where
             ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC
             LIMIT 200"
        );
        while ($row = mysqli_fetch_assoc($sql)) {
            $alerts[] = [
                'source'      => 'rmm',
                'id'          => intval($row['id']),
                'severity'    => $row['severity'] ?: 'info',
                'message'     => $row['message'],
                'subject'     => $row['asset_name'],
                'client_id'   => $row['client_id'] ? intval($row['client_id']) : null,
                'client_name' => $row['client_name'],
                'status'      => $row['status'] ?: 'new',
                'created_at'  => $row['created_at'],
                'ticket_id'   => $row['ticket_id'] ? intval($row['ticket_id']) : null,
                'ticket_label'=> $row['ticket_id'] ? ($row['ticket_prefix'] . $row['ticket_number']) : null,
            ];
        }
    }

    if ($filter_source === 'all' || $filter_source === 'backup') {
        $where = "1=1 AND " . $client_scope_for('a.alert_client_id');
        if ($filter_status && $filter_status !== 'all') {
            $where .= " AND a.alert_status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
        }
        if ($filter_severity) { $where .= " AND a.alert_severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'"; }
        if ($filter_client)   { $where .= " AND a.alert_client_id=$filter_client"; }

        $sql = mysqli_query($mysqli,
            "SELECT a.*, c.client_name, t.ticket_prefix, t.ticket_number
             FROM comet_backup_alerts a
             LEFT JOIN clients c ON c.client_id = a.alert_client_id
             LEFT JOIN tickets t ON t.ticket_id = a.alert_ticket_id
             WHERE $where
             ORDER BY FIELD(a.alert_severity,'critical','error','warning','info'), a.alert_created_at DESC
             LIMIT 200"
        );
        while ($row = mysqli_fetch_assoc($sql)) {
            $alerts[] = [
                'source'      => 'backup',
                'id'          => intval($row['alert_id']),
                'severity'    => $row['alert_severity'] ?: 'critical',
                'message'     => $row['alert_message'] ?: ($row['alert_type'] === 'missed' ? "Backup missed — {$row['alert_device_name']}" : "Backup failed — {$row['alert_device_name']}"),
                'subject'     => $row['alert_device_name'],
                'client_id'   => $row['alert_client_id'] ? intval($row['alert_client_id']) : null,
                'client_name' => $row['client_name'],
                'status'      => $row['alert_status'] ?: 'new',
                'created_at'  => $row['alert_created_at'],
                'ticket_id'   => $row['alert_ticket_id'] ? intval($row['alert_ticket_id']) : null,
                'ticket_label'=> $row['alert_ticket_id'] ? ($row['ticket_prefix'] . $row['ticket_number']) : null,
            ];
        }
    }

    $sev_rank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
    usort($alerts, function ($a, $b) use ($sev_rank) {
        $ra = $sev_rank[$a['severity']] ?? 4;
        $rb = $sev_rank[$b['severity']] ?? 4;
        if ($ra !== $rb) return $ra - $rb;
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    api_response(200, ['data' => $alerts, 'total' => count($alerts)]);
}

if ($method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $source   = $body['source'] ?? '';
    $alert_id = intval($body['id'] ?? 0);
    $action   = $body['action'] ?? '';

    if (!$alert_id || !in_array($source, ['rmm', 'backup'], true)) {
        api_error(400, 'source and id are required');
    }
    if (!in_array($action, ['acknowledge', 'resolve'], true)) {
        api_error(400, 'Unknown action');
    }

    if ($source === 'rmm') {
        $alert = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT id, client_id, asset_id FROM rmm_alerts WHERE id=$alert_id"));
        if (!$alert) api_error(404, 'Alert not found');

        // Enforce per-client access
        $alert_client = intval($alert['client_id']);
        if ($alert_client && !api_client_scope_ok($alert_client)) {
            api_error(403, 'Access denied');
        }

        if ($action === 'acknowledge') {
            mysqli_query($mysqli, "UPDATE rmm_alerts SET status='acknowledged', acknowledged_by=$api_user_id, acknowledged_at=NOW() WHERE id=$alert_id");
        } else {
            mysqli_query($mysqli, "UPDATE rmm_alerts SET status='resolved', resolved_at=NOW() WHERE id=$alert_id");
        }
        logAction('RMM', $action === 'acknowledge' ? 'Alert Acknowledged' : 'Alert Resolved', "$session_name {$action}d RMM alert ID $alert_id (mobile)", intval($alert['client_id']), intval($alert['asset_id']));
    } else {
        $alert = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT alert_id, alert_client_id FROM comet_backup_alerts WHERE alert_id=$alert_id"));
        if (!$alert) api_error(404, 'Alert not found');

        // Enforce per-client access
        $alert_client = intval($alert['alert_client_id']);
        if ($alert_client && !api_client_scope_ok($alert_client)) {
            api_error(403, 'Access denied');
        }

        if ($action === 'acknowledge') {
            mysqli_query($mysqli, "UPDATE comet_backup_alerts SET alert_status='acknowledged', alert_acknowledged_by=$api_user_id, alert_acknowledged_at=NOW() WHERE alert_id=$alert_id");
        } else {
            mysqli_query($mysqli, "UPDATE comet_backup_alerts SET alert_status='resolved', alert_resolved_at=NOW() WHERE alert_id=$alert_id");
        }
        logAction('Comet', $action === 'acknowledge' ? 'Alert Acknowledged' : 'Alert Resolved', "$session_name {$action}d backup alert ID $alert_id (mobile)", intval($alert['alert_client_id']));
    }

    api_response(200, ['ok' => true]);
}

api_error(405, 'Method not allowed');
