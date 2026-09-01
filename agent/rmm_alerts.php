<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm_alerts');

$filter_status   = sanitizeInput($_GET['status'] ?? 'new');
$filter_severity = sanitizeInput($_GET['severity'] ?? '');
$filter_asset_id = intval($_GET['asset_id'] ?? 0);
$filter_client   = intval($_GET['client_id'] ?? 0);
$filter_search   = sanitizeInput($_GET['q'] ?? '');

$where = "1=1";
if ($filter_status && $filter_status !== 'all') {
    $where .= " AND a.status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'";
}
if ($filter_severity) {
    $where .= " AND a.severity='" . mysqli_real_escape_string($mysqli, $filter_severity) . "'";
}
if ($filter_asset_id) { $where .= " AND a.asset_id=$filter_asset_id"; }
if ($filter_client)   { $where .= " AND a.client_id=$filter_client"; }
// Client-restricted techs should only see alerts for clients they have access to
if ($client_access_string && !$session_is_admin) { $where .= " AND a.client_id IN ($client_access_string)"; }
if ($filter_search) {
    $sq = mysqli_real_escape_string($mysqli, $filter_search);
    $where .= " AND (a.message LIKE '%$sq%' OR ast.asset_name LIKE '%$sq%' OR c.client_name LIKE '%$sq%')";
}

$sql_alerts = mysqli_query($mysqli,
    "SELECT a.*, ast.asset_name, c.client_name,
            u.user_name, t.ticket_prefix, t.ticket_number
     FROM rmm_alerts a
     LEFT JOIN assets ast ON ast.asset_id = a.asset_id
     LEFT JOIN clients c ON c.client_id = a.client_id
     LEFT JOIN users u ON u.user_id = a.acknowledged_by
     LEFT JOIN tickets t ON t.ticket_id = a.ticket_id
     WHERE $where
     ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC
     LIMIT 300"
);
$total_shown = mysqli_num_rows($sql_alerts);

// Counts
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(status='new')          as new_cnt,
       SUM(status='acknowledged') as ack_cnt,
       SUM(status='resolved')     as res_cnt,
       SUM(status='new' AND severity='critical') as crit_cnt,
       SUM(status='new' AND severity='error')    as err_cnt,
       SUM(status='new' AND severity='warning')  as warn_cnt,
       SUM(status='new' AND severity='info')     as info_cnt
     FROM rmm_alerts WHERE 1=1" . ($client_access_string && !$session_is_admin ? " AND client_id IN ($client_access_string)" : '')
));

// Client list for filter
$sql_clients = mysqli_query($mysqli,
    "SELECT DISTINCT c.client_id, c.client_name
     FROM rmm_alerts a JOIN clients c ON c.client_id = a.client_id
     WHERE c.client_archived_at IS NULL ORDER BY c.client_name"
);

$has_active_filter = $filter_severity || $filter_client || $filter_search || $filter_asset_id;
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-bell me-2"></i>RMM Alerts</h4>
    <a href="/agent/rmm_dashboard.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-tachometer-alt me-1"></i>Dashboard
    </a>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-md-4">
        <a href="?status=new" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'new' ? 'bg-danger' : 'bg-secondary' ?> mb-0">
            <div class="inner">
                <h3><?= intval($cnt['new_cnt']) ?></h3>
                <p>New Alerts</p>
            </div>
            <div class="icon"><i class="fas fa-exclamation-circle"></i></div>
            <span class="small-box-footer">
                <?php if ($cnt['crit_cnt']): ?><span class="badge text-bg-light me-1"><?= intval($cnt['crit_cnt']) ?> critical</span><?php endif; ?>
                <?php if ($cnt['err_cnt']): ?><span class="badge text-bg-light me-1"><?= intval($cnt['err_cnt']) ?> error</span><?php endif; ?>
                <?php if ($cnt['warn_cnt']): ?><span class="badge text-bg-light"><?= intval($cnt['warn_cnt']) ?> warning</span><?php endif; ?>
            </span>
        </div></a>
    </div>
    <div class="col-md-4">
        <a href="?status=acknowledged" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'acknowledged' ? 'bg-warning' : 'bg-secondary' ?> mb-0">
            <div class="inner"><h3><?= intval($cnt['ack_cnt']) ?></h3><p>Acknowledged</p></div>
            <div class="icon"><i class="fas fa-eye"></i></div>
            <span class="small-box-footer">Click to view</span>
        </div></a>
    </div>
    <div class="col-md-4">
        <a href="?status=resolved" class="text-decoration-none">
        <div class="small-box <?= $filter_status === 'resolved' ? 'bg-success' : 'bg-secondary' ?> mb-0">
            <div class="inner"><h3><?= intval($cnt['res_cnt']) ?></h3><p>Resolved</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <span class="small-box-footer">Click to view</span>
        </div></a>
    </div>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center" style="gap:6px">
            <!-- Status filter -->
            <div class="btn-group btn-group-sm me-2">
                <a href="?status=all<?= $filter_severity ? '&severity='.$filter_severity : '' ?>" class="btn <?= $filter_status === 'all' ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                <a href="?status=new<?= $filter_severity ? '&severity='.$filter_severity : '' ?>" class="btn <?= $filter_status === 'new' ? 'btn-danger' : 'btn-outline-secondary' ?>">New</a>
                <a href="?status=acknowledged<?= $filter_severity ? '&severity='.$filter_severity : '' ?>" class="btn <?= $filter_status === 'acknowledged' ? 'btn-warning' : 'btn-outline-secondary' ?>">Acked</a>
                <a href="?status=resolved<?= $filter_severity ? '&severity='.$filter_severity : '' ?>" class="btn <?= $filter_status === 'resolved' ? 'btn-success' : 'btn-outline-secondary' ?>">Resolved</a>
            </div>
            <!-- Severity filter -->
            <div class="btn-group btn-group-sm me-2">
                <?php foreach (['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'] as $sev => $col): ?>
                <a href="?status=<?= $filter_status ?>&severity=<?= $filter_severity === $sev ? '' : $sev ?>"
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
            <?php if ($filter_severity): ?><input type="hidden" name="severity" value="<?= htmlspecialchars($filter_severity) ?>"><?php endif; ?>
            <?php endif; ?>
            <!-- Search -->
            <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>" class="form-control form-control-sm me-2" placeholder="Search message, asset…" style="max-width:200px" id="alertSearch">
            <button type="submit" class="btn btn-sm btn-secondary me-2"><i class="fas fa-search"></i></button>
            <?php if ($has_active_filter || $filter_status !== 'new'): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-times me-1"></i>Clear
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Bulk actions bar (shown when alerts exist) -->
<?php if ($total_shown > 0 && $filter_status === 'new' && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
<div class="d-flex align-items-center mb-2 px-1">
    <small class="text-muted mr-auto">Showing <?= $total_shown ?> alert<?= $total_shown != 1 ? 's' : '' ?></small>
    <button class="btn btn-sm btn-outline-warning me-2 js-bulk-action" data-bulk-action="acknowledge">
        <i class="fas fa-check-double me-1"></i>Ack All Shown
    </button>
    <button class="btn btn-sm btn-outline-success js-bulk-action" data-bulk-action="resolve">
        <i class="fas fa-check-circle me-1"></i>Resolve All Shown
    </button>
</div>
<?php elseif ($total_shown > 0): ?>
<div class="mb-2 px-1">
    <small class="text-muted">Showing <?= $total_shown ?> alert<?= $total_shown != 1 ? 's' : '' ?></small>
</div>
<?php endif; ?>

<?php if ($total_shown > 0 && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
<div class="text-muted small mb-2 px-1">
    <i class="fas fa-info-circle me-1"></i>Acknowledge / Resolve also updates the alert at the RMM vendor where supported (Tactical RMM). Level.io is view-only for alerts.
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
        <table class="table table-sm table-hover mb-0" id="alertsTable">
            <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                <tr>
                    <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1 && $filter_status === 'new'): ?>
                    <th class="ps-3" style="width:32px">
                        <input type="checkbox" id="selectAll" class="js-select-all-alerts" title="Select all">
                    </th>
                    <?php else: ?>
                    <th class="ps-3" style="width:10px"></th>
                    <?php endif; ?>
                    <th>Severity</th>
                    <th>Message</th>
                    <th>Asset</th>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Time</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php while ($alert = mysqli_fetch_assoc($sql_alerts)):
                $sev_color    = ['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'][$alert['severity']] ?? 'secondary';
                $status_color = ['new'=>'danger','acknowledged'=>'warning','resolved'=>'success'][$alert['status']] ?? 'secondary';
                $aid = intval($alert['id']);
                $row_class = $alert['severity'] === 'critical' ? 'table-danger' : '';
            ?>
            <tr id="alert-row-<?= $aid ?>" class="<?= $row_class ?>">
                <td class="ps-3">
                    <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1 && $alert['status'] !== 'resolved'): ?>
                    <input type="checkbox" class="alert-chk" data-id="<?= $aid ?>">
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $sev_color ?>"><?= nullable_htmlentities($alert['severity']) ?></span>
                </td>
                <td class="small" style="max-width:280px;overflow:hidden">
                    <?= nullable_htmlentities($alert['message']) ?>
                </td>
                <td class="small">
                    <?php if ($alert['asset_id']): ?>
                    <a href="/agent/asset_details.php?asset_id=<?= intval($alert['asset_id']) ?>">
                        <?= nullable_htmlentities($alert['asset_name']) ?>
                    </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="small">
                    <?php if ($alert['client_id']): ?>
                    <a href="/agent/client_details.php?client_id=<?= intval($alert['client_id']) ?>">
                        <?= nullable_htmlentities($alert['client_name']) ?>
                    </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= $status_color ?>"><?= $alert['status'] ?></span>
                    <?php if ($alert['status'] === 'acknowledged' && $alert['user_name']): ?>
                    <br><small class="text-muted"><?= nullable_htmlentities($alert['user_name']) ?></small>
                    <?php endif; ?>
                </td>
                <td class="text-muted small" style="white-space:nowrap">
                    <?= nullable_htmlentities(date('M j', strtotime($alert['created_at']))) ?>
                    <br><?= nullable_htmlentities(date('g:ia', strtotime($alert['created_at']))) ?>
                </td>
                <td class="text-end pe-2" style="white-space:nowrap">
                    <?php if ($alert['status'] === 'new' && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
                    <button class="btn btn-xs btn-warning js-alert-action" data-alert-id="<?= $aid ?>" data-alert-action="acknowledge" title="Acknowledge">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($alert['status'] !== 'resolved' && lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
                    <button class="btn btn-xs btn-success ms-1 js-alert-action" data-alert-id="<?= $aid ?>" data-alert-action="resolve" title="Resolve">
                        <i class="fas fa-check"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($alert['ticket_id']): ?>
                    <a href="/agent/ticket.php?ticket_id=<?= intval($alert['ticket_id']) ?>" class="btn btn-xs btn-outline-primary ms-1" title="View linked ticket">
                        <i class="fas fa-ticket-alt me-1"></i><?= nullable_htmlentities($alert['ticket_prefix'] . $alert['ticket_number']) ?>
                    </a>
                    <?php elseif (lookupUserPermission('module_support') >= 2): ?>
                    <button class="btn btn-xs btn-primary ms-1 js-alert-action" data-alert-id="<?= $aid ?>" data-alert-action="create_ticket" title="Create Ticket">
                        <i class="fas fa-ticket-alt"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF = '<?= $_SESSION['csrf_token'] ?>';

// Delegated wiring (CSP forbids inline onclick=/onchange= attributes).
document.addEventListener('click', function (e) {
    var el;
    if ((el = e.target.closest('.js-bulk-action'))) { bulkAction(el.dataset.bulkAction); return; }
    if ((el = e.target.closest('.js-alert-action'))) { alertAction(parseInt(el.dataset.alertId, 10), el.dataset.alertAction); return; }
});
document.addEventListener('change', function (e) {
    if (e.target.closest('.js-select-all-alerts')) { toggleSelectAll(e.target); }
});

function alertAction(alertId, action) {
    if (action === 'create_ticket' && !confirm('Create a ticket from this alert?')) return;
    fetch('/agent/post/rmm_alert.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF}&action=${action}&alert_id=${alertId}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            if (d.redirect) { window.location.href = d.redirect; return; }
            const row = document.getElementById('alert-row-' + alertId);
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
    const checked = [...document.querySelectorAll('.alert-chk:checked')].map(c => c.dataset.id);
    if (!checked.length) { alert('No alerts selected.'); return; }
    if (!confirm(`${action === 'acknowledge' ? 'Acknowledge' : 'Resolve'} ${checked.length} alert(s)?`)) return;

    let done = 0;
    checked.forEach(id => {
        fetch('/agent/post/rmm_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `csrf_token=${CSRF}&action=${action}&alert_id=${id}`
        }).then(r => r.json()).then(d => {
            if (d.success) {
                const row = document.getElementById('alert-row-' + id);
                if (row) row.style.opacity = '0.3';
            }
            if (++done === checked.length) {
                setTimeout(() => location.reload(), 800);
            }
        });
    });
}
</script>

<?php require_once "../includes/footer.php"; ?>
