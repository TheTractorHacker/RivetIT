<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm_alerts');

$filter_source   = sanitizeInput($_GET['source'] ?? 'all');
$filter_status   = sanitizeInput($_GET['status'] ?? 'new');
$filter_severity = sanitizeInput($_GET['severity'] ?? '');
$filter_client   = intval($_GET['client_id'] ?? 0);
$filter_search   = sanitizeInput($_GET['q'] ?? '');

// HTML-safe versions for href attributes (sanitizeInput uses mysqli_real_escape_string
// which escapes " to \" — that's SQL escaping, not HTML escaping; use these instead)
$h_source   = htmlspecialchars($filter_source,   ENT_QUOTES, 'UTF-8');
$h_status   = htmlspecialchars($filter_status,   ENT_QUOTES, 'UTF-8');
$h_severity = htmlspecialchars($filter_severity, ENT_QUOTES, 'UTF-8');

$alerts = [];

// ── RMM alerts ──────────────────────────────────────────────────────────────
if ($filter_source === 'all' || $filter_source === 'rmm') {
    $where = "1=1";
    if ($filter_status && $filter_status !== 'all') {
        $where .= " AND a.status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
    }
    if ($filter_severity) { $where .= " AND a.severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'"; }
    if ($filter_client)   { $where .= " AND a.client_id=$filter_client"; }
    if ($filter_search) {
        $sq = mysqli_real_escape_string($mysqli, $filter_search);
        $where .= " AND (a.message LIKE '%$sq%' OR ast.asset_name LIKE '%$sq%' OR c.client_name LIKE '%$sq%')";
    }
    // Client-restricted techs should only see alerts for clients they have access to
    if ($client_access_string && !$session_is_admin) { $where .= " AND a.client_id IN ($client_access_string)"; }
    $sql = mysqli_query($mysqli,
        "SELECT a.*, ast.asset_name, c.client_name, u.user_name, t.ticket_prefix, t.ticket_number,
                i.name AS integration_name, i.type AS integration_type
         FROM rmm_alerts a
         LEFT JOIN assets ast ON ast.asset_id = a.asset_id
         LEFT JOIN clients c ON c.client_id = a.client_id
         LEFT JOIN users u ON u.user_id = a.acknowledged_by
         LEFT JOIN tickets t ON t.ticket_id = a.ticket_id
         LEFT JOIN rmm_integrations i ON i.id = a.integration_id
         WHERE $where
         ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC
         LIMIT 300"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $alerts[] = [
            'source'     => 'rmm',
            'id'         => intval($row['id']),
            'severity'   => $row['severity'] ?: 'info',
            'message'    => $row['message'],
            'subject'    => $row['asset_name'] ? $row['asset_name'] : null,
            'subject_url'=> $row['asset_id'] ? "/agent/asset_details.php?asset_id={$row['asset_id']}" : null,
            'client_id'  => $row['client_id'] ? intval($row['client_id']) : null,
            'client_name'=> $row['client_name'],
            'integration_name' => $row['integration_name'],
            'integration_type' => $row['integration_type'],
            'status'     => $row['status'] ?: 'new',
            'ack_by'     => $row['user_name'],
            'created_at' => $row['created_at'],
            'ticket_id'  => $row['ticket_id'] ? intval($row['ticket_id']) : null,
            'ticket_label'=> $row['ticket_id'] ? ($row['ticket_prefix'] . $row['ticket_number']) : null,
            'can_create_ticket' => !$row['ticket_id'],
        ];
    }
}

// ── Backup alerts ───────────────────────────────────────────────────────────
if ($filter_source === 'all' || $filter_source === 'backup') {
    $where = "1=1";
    if ($filter_status && $filter_status !== 'all') {
        $where .= " AND a.alert_status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
    }
    if ($filter_severity) { $where .= " AND a.alert_severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'"; }
    if ($filter_client)   { $where .= " AND a.alert_client_id=$filter_client"; }
    if ($filter_search) {
        $sq = mysqli_real_escape_string($mysqli, $filter_search);
        $where .= " AND (a.alert_message LIKE '%$sq%' OR a.alert_device_name LIKE '%$sq%' OR c.client_name LIKE '%$sq%')";
    }
    // Client-restricted techs should only see alerts for clients they have access to
    if ($client_access_string && !$session_is_admin) { $where .= " AND a.alert_client_id IN ($client_access_string)"; }
    $sql = mysqli_query($mysqli,
        "SELECT a.*, c.client_name, u.user_name, t.ticket_prefix, t.ticket_number
         FROM comet_backup_alerts a
         LEFT JOIN clients c ON c.client_id = a.alert_client_id
         LEFT JOIN users u ON u.user_id = a.alert_acknowledged_by
         LEFT JOIN tickets t ON t.ticket_id = a.alert_ticket_id
         WHERE $where
         ORDER BY FIELD(a.alert_severity,'critical','error','warning','info'), a.alert_created_at DESC
         LIMIT 300"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $alerts[] = [
            'source'     => 'backup',
            'id'         => intval($row['alert_id']),
            'severity'   => $row['alert_severity'] ?: 'critical',
            'message'    => $row['alert_message'] ?: ($row['alert_type'] === 'missed' ? "Backup missed — {$row['alert_device_name']}" : "Backup failed — {$row['alert_device_name']}"),
            'subject'    => $row['alert_device_name'],
            'subject_url'=> null,
            'client_id'  => $row['alert_client_id'] ? intval($row['alert_client_id']) : null,
            'client_name'=> $row['client_name'],
            'status'     => $row['alert_status'] ?: 'new',
            'ack_by'     => $row['user_name'],
            'created_at' => $row['alert_created_at'],
            'ticket_id'  => $row['alert_ticket_id'] ? intval($row['alert_ticket_id']) : null,
            'ticket_label'=> $row['alert_ticket_id'] ? ($row['ticket_prefix'] . $row['ticket_number']) : null,
            'can_create_ticket' => false,
            'alert_type' => $row['alert_type'],
        ];
    }
}

// ── Network alerts (RMM alerts scoped to network device types) ──────────────
if ($filter_source === 'network') {
    $where = "ast.asset_type IN ('Firewall/Router','Switch','Access Point')";
    if ($filter_status && $filter_status !== 'all') {
        $where .= " AND a.status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
    }
    if ($filter_severity) { $where .= " AND a.severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'"; }
    if ($filter_client)   { $where .= " AND a.client_id=$filter_client"; }
    if ($filter_search) {
        $sq = mysqli_real_escape_string($mysqli, $filter_search);
        $where .= " AND (a.message LIKE '%$sq%' OR ast.asset_name LIKE '%$sq%' OR c.client_name LIKE '%$sq%')";
    }
    // Client-restricted techs should only see alerts for clients they have access to
    if ($client_access_string && !$session_is_admin) { $where .= " AND a.client_id IN ($client_access_string)"; }
    $sql = mysqli_query($mysqli,
        "SELECT a.*, ast.asset_name, c.client_name, u.user_name, t.ticket_prefix, t.ticket_number,
                i.name AS integration_name, i.type AS integration_type
         FROM rmm_alerts a
         LEFT JOIN assets ast ON ast.asset_id = a.asset_id
         LEFT JOIN clients c ON c.client_id = a.client_id
         LEFT JOIN users u ON u.user_id = a.acknowledged_by
         LEFT JOIN tickets t ON t.ticket_id = a.ticket_id
         LEFT JOIN rmm_integrations i ON i.id = a.integration_id
         WHERE $where
         ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC
         LIMIT 300"
    );
    while ($row = mysqli_fetch_assoc($sql)) {
        $alerts[] = [
            'source'      => 'network',
            'id'          => intval($row['id']),
            'severity'    => $row['severity'] ?: 'info',
            'message'     => $row['message'],
            'subject'     => $row['asset_name'] ? $row['asset_name'] : null,
            'subject_url' => $row['asset_id'] ? "/agent/asset_details.php?asset_id={$row['asset_id']}" : null,
            'client_id'   => $row['client_id'] ? intval($row['client_id']) : null,
            'client_name' => $row['client_name'],
            'integration_name' => $row['integration_name'],
            'integration_type' => $row['integration_type'],
            'status'      => $row['status'] ?: 'new',
            'ack_by'      => $row['user_name'],
            'created_at'  => $row['created_at'],
            'ticket_id'   => $row['ticket_id'] ? intval($row['ticket_id']) : null,
            'ticket_label'=> $row['ticket_id'] ? ($row['ticket_prefix'] . $row['ticket_number']) : null,
            'can_create_ticket' => !$row['ticket_id'],
        ];
    }
}

// Merge sort: severity rank, then newest first
$sev_rank = ['critical' => 0, 'error' => 1, 'warning' => 2, 'info' => 3];
usort($alerts, function ($a, $b) use ($sev_rank) {
    $ra = $sev_rank[$a['severity']] ?? 4;
    $rb = $sev_rank[$b['severity']] ?? 4;
    if ($ra !== $rb) return $ra - $rb;
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
$total_shown = count($alerts);

// Counts (combined, ignoring current status filter so the tabs stay usable)
$rmm_scope_clause = ($client_access_string && !$session_is_admin) ? " AND client_id IN ($client_access_string)" : '';
$backup_scope_clause = ($client_access_string && !$session_is_admin) ? " AND alert_client_id IN ($client_access_string)" : '';
$rmm_cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT SUM(status='new') new_cnt, SUM(status='acknowledged') ack_cnt, SUM(status='resolved') res_cnt FROM rmm_alerts WHERE 1=1$rmm_scope_clause"
));
$backup_cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT SUM(alert_status='new') new_cnt, SUM(alert_status='acknowledged') ack_cnt, SUM(alert_status='resolved') res_cnt FROM comet_backup_alerts WHERE 1=1$backup_scope_clause"
));
$cnt = [
    'new_cnt' => intval($rmm_cnt['new_cnt']) + intval($backup_cnt['new_cnt']),
    'ack_cnt' => intval($rmm_cnt['ack_cnt']) + intval($backup_cnt['ack_cnt']),
    'res_cnt' => intval($rmm_cnt['res_cnt']) + intval($backup_cnt['res_cnt']),
];

// Client list for filter (union of clients with either alert type)
$sql_clients = mysqli_query($mysqli,
    "SELECT DISTINCT c.client_id, c.client_name FROM clients c
     WHERE c.client_archived_at IS NULL
       AND (c.client_id IN (SELECT client_id FROM rmm_alerts WHERE client_id IS NOT NULL)
            OR c.client_id IN (SELECT alert_client_id FROM comet_backup_alerts WHERE alert_client_id IS NOT NULL))"
     . ($client_access_string && !$session_is_admin ? " AND c.client_id IN ($client_access_string)" : '') . "
     ORDER BY c.client_name"
);

$has_active_filter = $filter_severity || $filter_client || $filter_search || $filter_source !== 'all';
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-bell me-2"></i>Alerts</h4>
    <a href="/agent/rmm_dashboard.php" class="btn btn-secondary btn-sm me-2">
        <i class="fas fa-tachometer-alt me-1"></i>RMM Dashboard
    </a>
    <a href="/agent/backups.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-cloud-upload-alt me-1"></i>Backup Dashboard
    </a>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-md-4">
        <a href="?status=new&source=<?= $h_source ?>" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'new' ? 'bg-danger' : 'bg-secondary' ?> mb-0">
            <div class="inner"><h3><?= $cnt['new_cnt'] ?></h3><p>New Alerts</p></div>
            <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
            <span class="small-box-footer">RMM + Backup combined</span>
        </div></a>
    </div>
    <div class="col-md-4">
        <a href="?status=acknowledged&source=<?= $h_source ?>" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'acknowledged' ? 'bg-warning' : 'bg-secondary' ?> mb-0">
            <div class="inner"><h3><?= $cnt['ack_cnt'] ?></h3><p>Acknowledged</p></div>
            <div class="icon"><i class="fas fa-eye"></i></div>
            <span class="small-box-footer">Click to view</span>
        </div></a>
    </div>
    <div class="col-md-4">
        <a href="?status=resolved&source=<?= $h_source ?>" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'resolved' ? 'bg-success' : 'bg-secondary' ?> mb-0">
            <div class="inner"><h3><?= $cnt['res_cnt'] ?></h3><p>Resolved</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <span class="small-box-footer">Click to view</span>
        </div></a>
    </div>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center" style="gap:6px">
            <!-- Source filter -->
            <div class="btn-group btn-group-sm me-2">
                <a href="?source=all&status=<?= $h_status ?>" class="btn <?= $filter_source === 'all' ? 'btn-primary' : 'btn-outline-primary' ?>">All</a>
                <a href="?source=rmm&status=<?= $h_status ?>" class="btn <?= $filter_source === 'rmm' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="fas fa-server me-1"></i>RMM</a>
                <a href="?source=backup&status=<?= $h_status ?>" class="btn <?= $filter_source === 'backup' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="fas fa-cloud-upload-alt me-1"></i>Backup</a>
                <a href="?source=network&status=<?= $h_status ?>" class="btn <?= $filter_source === 'network' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="fas fa-network-wired me-1"></i>Network</a>
            </div>
            <!-- Status filter -->
            <div class="btn-group btn-group-sm me-2">
                <a href="?status=all&source=<?= $h_source ?>" class="btn <?= $filter_status === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                <a href="?status=new&source=<?= $h_source ?>" class="btn <?= $filter_status === 'new' ? 'btn-danger' : 'btn-outline-secondary' ?>">New</a>
                <a href="?status=acknowledged&source=<?= $h_source ?>" class="btn <?= $filter_status === 'acknowledged' ? 'btn-warning' : 'btn-outline-secondary' ?>">Acked</a>
                <a href="?status=resolved&source=<?= $h_source ?>" class="btn <?= $filter_status === 'resolved' ? 'btn-success' : 'btn-outline-secondary' ?>">Resolved</a>
            </div>
            <!-- Severity filter -->
            <div class="btn-group btn-group-sm me-2">
                <?php foreach (['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'] as $sev => $col): ?>
                <a href="?status=<?= $h_status ?>&source=<?= $h_source ?>&severity=<?= $filter_severity === $sev ? '' : $sev ?>"
                   class="btn <?= $filter_severity === $sev ? "btn-$col" : "btn-outline-$col" ?>">
                    <?= ucfirst($sev) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- Client filter -->
            <?php if (mysqli_num_rows($sql_clients) > 0): ?>
            <select name="client_id" class="form-control form-control-sm me-2 auto-submit-select" style="max-width:180px">
                <option value="">All Clients</option>
                <?php while ($cl = mysqli_fetch_assoc($sql_clients)): ?>
                <option value="<?= $cl['client_id'] ?>" <?= $filter_client == $cl['client_id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($cl['client_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
            <input type="hidden" name="source" value="<?= htmlspecialchars($filter_source) ?>">
            <?php if ($filter_severity): ?><input type="hidden" name="severity" value="<?= htmlspecialchars($filter_severity) ?>"><?php endif; ?>
            <?php endif; ?>
            <!-- Search -->
            <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>" class="form-control form-control-sm me-2" placeholder="Search message, device, client…" style="max-width:220px">
            <button type="submit" class="btn btn-sm btn-secondary me-2"><i class="fas fa-search"></i></button>
            <?php if ($has_active_filter || $filter_status !== 'new'): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Bulk actions bar -->
<?php if ($total_shown > 0 && $filter_status === 'new' && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
<div class="d-flex align-items-center mb-2 px-1">
    <small class="text-muted mr-auto">Showing <?= $total_shown ?> alert<?= $total_shown != 1 ? 's' : '' ?></small>
    <button class="btn btn-sm btn-outline-warning me-2 js-bulk-alert-action" data-bulk-action="acknowledge">
        <i class="fas fa-check-double me-1"></i>Ack All Shown
    </button>
    <button class="btn btn-sm btn-outline-success js-bulk-alert-action" data-bulk-action="resolve">
        <i class="fas fa-check-circle me-1"></i>Resolve All Shown
    </button>
</div>
<?php elseif ($total_shown > 0): ?>
<div class="mb-2 px-1">
    <small class="text-muted">Showing <?= $total_shown ?> alert<?= $total_shown != 1 ? 's' : '' ?></small>
</div>
<?php endif; ?>

<div class="card card-dark" id="alertsCard">
    <div class="card-body p-0">
        <?php if ($total_shown === 0): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
            <p class="mb-0">No alerts match your filters.</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" id="alertsTable">
            <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                <tr>
                    <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1 && $filter_status === 'new'): ?>
                    <th class="ps-3" style="width:32px"><input type="checkbox" id="selectAll" class="js-toggle-select-all"></th>
                    <?php else: ?>
                    <th class="ps-3" style="width:10px"></th>
                    <?php endif; ?>
                    <th style="width:70px">Source</th>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Asset / Device</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Time</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert):
                $sev_color    = ['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'][$alert['severity']] ?? 'secondary';
                $status_color = ['new'=>'danger','acknowledged'=>'warning','resolved'=>'success'][$alert['status']] ?? 'secondary';
                $aid          = $alert['id'];
                $row_class    = $alert['severity'] === 'critical' ? 'table-danger' : '';
                $endpoint     = in_array($alert['source'], ['rmm', 'network']) ? '/agent/post/rmm_alert.php' : '/agent/post/comet_alert.php';
                $row_id       = $alert['source'] . '-' . $aid;
            ?>
            <tr id="alert-row-<?= $row_id ?>" class="<?= $row_class ?>">
                <td class="ps-3">
                    <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1 && $alert['status'] !== 'resolved'): ?>
                    <input type="checkbox" class="alert-chk" data-id="<?= $aid ?>" data-source="<?= $alert['source'] ?>" data-row="<?= $row_id ?>">
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($alert['source'] === 'rmm') {
                        $itype = $alert['integration_type'] ?? '';
                        $iname = $alert['integration_name'] ?? 'RMM';
                        $iicon = $itype === 'sophos_central' ? 'fa-fire-alt' : 'fa-server';
                        $ibadge = $itype === 'sophos_central' ? 'text-bg-success' : 'text-bg-primary';
                    ?>
                    <span class="badge <?= $ibadge ?>" title="<?= nullable_htmlentities($iname) ?>">
                        <i class="fas <?= $iicon ?>"></i>
                    </span>
                    <div class="text-muted" style="font-size:.65rem;margin-top:.1rem;line-height:1;"><?= nullable_htmlentities($iname) ?></div>
                    <?php } elseif ($alert['source'] === 'network') {
                        $iname = $alert['integration_name'] ?? 'Network';
                    ?>
                    <span class="badge text-bg-info" title="<?= nullable_htmlentities($iname) ?>">
                        <i class="fas fa-network-wired"></i>
                    </span>
                    <div class="text-muted" style="font-size:.65rem;margin-top:.1rem;line-height:1;"><?= nullable_htmlentities($iname) ?></div>
                    <?php } else { ?>
                    <span class="badge" style="background:#6f42c1;color:#fff;" title="<?= ($alert['alert_type'] ?? '') === 'missed' ? 'Backup — Missed' : 'Backup — Failed' ?>">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </span>
                    <?php } ?>
                </td>
                <td><span class="badge text-bg-<?= $sev_color ?>"><?= nullable_htmlentities($alert['severity']) ?></span></td>
                <td class="small" style="max-width:280px;overflow:hidden"><?= nullable_htmlentities($alert['message']) ?></td>
                <td class="small">
                    <?php if ($alert['subject_url']) { ?>
                    <a href="<?= $alert['subject_url'] ?>"><?= nullable_htmlentities($alert['subject']) ?></a>
                    <?php } elseif ($alert['subject']) { ?>
                    <?= nullable_htmlentities($alert['subject']) ?>
                    <?php } else { ?>—<?php } ?>
                </td>
                <td class="small">
                    <?php if ($alert['client_id']) { ?>
                    <a href="/agent/client_details.php?client_id=<?= $alert['client_id'] ?>"><?= nullable_htmlentities($alert['client_name']) ?></a>
                    <?php } else { ?>—<?php } ?>
                </td>
                <td>
                    <span class="badge text-bg-<?= $status_color ?>"><?= $alert['status'] ?></span>
                    <?php if ($alert['status'] === 'acknowledged' && $alert['ack_by']) { ?>
                    <br><small class="text-muted"><?= nullable_htmlentities($alert['ack_by']) ?></small>
                    <?php } ?>
                </td>
                <td class="text-muted small" style="white-space:nowrap">
                    <?= nullable_htmlentities(date('M j', strtotime($alert['created_at']))) ?>
                    <br><?= nullable_htmlentities(date('g:ia', strtotime($alert['created_at']))) ?>
                </td>
                <td class="text-end pe-2" style="white-space:nowrap">
                    <?php if ($alert['status'] === 'new' && lookupUserPermission('module_rmm_alerts_ack') >= 1) { ?>
                    <button class="btn btn-xs btn-warning js-alert-action" data-source="<?= nullable_htmlentities($alert['source']) ?>" data-alert-id="<?= $aid ?>" data-alert-action="acknowledge" data-row-id="<?= nullable_htmlentities($row_id) ?>" title="Acknowledge">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php } ?>
                    <?php if ($alert['status'] !== 'resolved' && lookupUserPermission('module_rmm_alerts_ack') >= 1) { ?>
                    <button class="btn btn-xs btn-success ms-1 js-alert-action" data-source="<?= nullable_htmlentities($alert['source']) ?>" data-alert-id="<?= $aid ?>" data-alert-action="resolve" data-row-id="<?= nullable_htmlentities($row_id) ?>" title="Resolve">
                        <i class="fas fa-check"></i>
                    </button>
                    <?php } ?>
                    <?php if ($alert['ticket_id']) { ?>
                    <a href="/agent/ticket.php?ticket_id=<?= $alert['ticket_id'] ?>" class="btn btn-xs btn-outline-primary ms-1" title="View linked ticket">
                        <i class="fas fa-ticket-alt me-1"></i><?= nullable_htmlentities($alert['ticket_label']) ?>
                    </a>
                    <?php } elseif ($alert['can_create_ticket'] && lookupUserPermission('module_support') >= 2) { ?>
                    <button class="btn btn-xs btn-primary ms-1 js-alert-action" data-source="<?= nullable_htmlentities($alert['source']) ?>" data-alert-id="<?= $aid ?>" data-alert-action="create_ticket" data-row-id="<?= nullable_htmlentities($row_id) ?>" title="Create Ticket">
                        <i class="fas fa-ticket-alt"></i>
                    </button>
                    <?php } ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF = '<?= $_SESSION['csrf_token'] ?>';
const ENDPOINTS = {rmm: '/agent/post/rmm_alert.php', network: '/agent/post/rmm_alert.php', backup: '/agent/post/comet_alert.php'};

function alertAction(source, alertId, action, rowId) {
    if (action === 'create_ticket' && !confirm('Create a ticket from this alert?')) return;
    fetch(ENDPOINTS[source], {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF}&action=${action}&alert_id=${alertId}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            if (d.redirect) { window.location.href = d.redirect; return; }
            const row = document.getElementById('alert-row-' + rowId);
            if (row) row.style.opacity = '0.3';
        } else {
            alert('Failed: ' + (d.error || 'Unknown error'));
        }
    });
}

function toggleSelectAll(cb) {
    document.querySelectorAll('.alert-chk').forEach(c => c.checked = cb.checked);
}

function bulkAction(action) {
    const checked = [...document.querySelectorAll('.alert-chk:checked')];
    if (!checked.length) { alert('No alerts selected.'); return; }
    if (!confirm(`${action === 'acknowledge' ? 'Acknowledge' : 'Resolve'} ${checked.length} alert(s)?`)) return;

    let done = 0;
    checked.forEach(chk => {
        const source = chk.dataset.source, id = chk.dataset.id, rowId = chk.dataset.row;
        fetch(ENDPOINTS[source], {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `csrf_token=${CSRF}&action=${action}&alert_id=${id}`
        }).then(r => r.json()).then(d => {
            if (d.success) {
                const row = document.getElementById('alert-row-' + rowId);
                if (row) row.style.opacity = '0.3';
            }
            if (++done === checked.length) {
                setTimeout(() => location.reload(), 800);
            }
        });
    });
}

document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-bulk-alert-action'))) { bulkAction(el.dataset.bulkAction); return; }
    if ((el = e.target.closest('.js-alert-action'))) {
        alertAction(el.dataset.source, parseInt(el.dataset.alertId, 10), el.dataset.alertAction, el.dataset.rowId);
    }
});
document.addEventListener('change', function (e) {
    if (e.target.closest('.js-toggle-select-all')) { toggleSelectAll(e.target); }
});
</script>

<?php require_once "../includes/footer.php"; ?>
