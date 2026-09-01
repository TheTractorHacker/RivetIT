<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

// Filter params
$filter_status    = sanitizeInput($_GET['status'] ?? '');
$filter_client_id = intval($_GET['client_id'] ?? 0);
$filter_intg_id   = intval($_GET['integration_id'] ?? 0);

// Build WHERE clause
$where = "1=1";
if ($filter_status)    { $where .= " AND arl.rmm_status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'"; }
if ($filter_client_id) { $where .= " AND a.asset_client_id=$filter_client_id"; }
if ($filter_intg_id)   { $where .= " AND arl.integration_id=$filter_intg_id"; }
// Client-restricted techs should only see assets for clients they have access to
if ($client_access_string && !$session_is_admin) { $where .= " AND a.asset_client_id IN ($client_access_string)"; }

$sql_links = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_type, a.asset_client_id,
            c.client_name, i.type AS integration_type
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE $where
     ORDER BY arl.rmm_status ASC, arl.hostname ASC"
);

// A single physical asset can be linked to more than one RMM integration at
// once (e.g. both Tactical RMM and Level.io tracking the same device). This
// query is per-link, not per-asset, so without deduping it that device would
// be listed twice with no explanation. Collapse to one row per asset_id,
// preferring the Tactical RMM link when config_rmm_prefer_tactical is on,
// then re-sort to match the original status/hostname ordering.
$rmm_link_rows = [];
while ($row = mysqli_fetch_assoc($sql_links)) {
    $aid = intval($row['asset_id']);
    if (!isset($rmm_link_rows[$aid])) {
        $rmm_link_rows[$aid] = $row;
    } elseif ($config_rmm_prefer_tactical
        && $row['integration_type'] === 'tactical_rmm'
        && $rmm_link_rows[$aid]['integration_type'] !== 'tactical_rmm') {
        $rmm_link_rows[$aid] = $row;
    }
}
$rmm_link_rows = array_values($rmm_link_rows);
usort($rmm_link_rows, function ($a, $b) {
    $status_cmp = strcmp($a['rmm_status'] ?? '', $b['rmm_status'] ?? '');
    if ($status_cmp !== 0) return $status_cmp;
    return strcasecmp($a['hostname'] ?? '', $b['hostname'] ?? '');
});

// Counts for stat cards
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(rmm_status='online') as online,
       SUM(rmm_status='offline') as offline,
       SUM(rmm_status='unknown') as unknown,
       COUNT(*) as total
     FROM asset_rmm_links WHERE integration_id=" . ($filter_intg_id ?: 'integration_id')
));

$sql_integrations = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 ORDER BY name");
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . ($client_access_string && !$session_is_admin ? " AND client_id IN ($client_access_string)" : '') . " ORDER BY client_name ASC");
$clients_list = [];
while ($c = mysqli_fetch_assoc($sql_clients)) $clients_list[] = $c;

// Button label should reflect whichever integration is actually selected
// (Tactical, Sophos, etc.) rather than a hardcoded provider name.
$sync_target_name = 'RMM';
if ($filter_intg_id) {
    $sync_target_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM rmm_integrations WHERE id=$filter_intg_id"));
    if ($sync_target_row) { $sync_target_name = $sync_target_row['name']; }
}
$sync_target_name_js = json_encode($sync_target_name, JSON_HEX_TAG);
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-desktop me-2"></i>RMM Assets</h4>
    <?php if (lookupUserPermission('module_rmm_sync') >= 1 && $filter_intg_id): ?>
    <button class="btn btn-success btn-sm me-2 js-trigger-sync" id="syncBtn">
        <i class="fas fa-sync me-1"></i>Sync from <?= nullable_htmlentities($sync_target_name) ?>
    </button>
    <?php endif; ?>
    <a href="/admin/settings_integrations.php?tab=rmm" class="btn btn-secondary btn-sm">
        <i class="fas fa-cog me-1"></i>Settings
    </a>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-md-3">
        <div class="small-box bg-success">
            <div class="inner"><h3><?= intval($cnt['online']) ?></h3><p>Online</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <a href="?status=online" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-danger">
            <div class="inner"><h3><?= intval($cnt['offline']) ?></h3><p>Offline</p></div>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <a href="?status=offline" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-secondary">
            <div class="inner"><h3><?= intval($cnt['unknown']) ?></h3><p>Unknown</p></div>
            <div class="icon"><i class="fas fa-question-circle"></i></div>
            <a href="?status=unknown" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-info">
            <div class="inner"><h3><?= intval($cnt['total']) ?></h3><p>Total Managed</p></div>
            <div class="icon"><i class="fas fa-desktop"></i></div>
            <a href="?" class="small-box-footer">Show All <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
</div>

<!-- Sync status bar -->
<div id="syncStatus" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="syncStatusText">Syncing...</span>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-2">
    <div class="card-body py-2">
        <form method="get" class="form-inline">
            <select name="integration_id" class="form-control form-control-sm me-2 auto-submit-select">
                <option value="">All Integrations</option>
                <?php while ($i = mysqli_fetch_assoc($sql_integrations)): ?>
                <option value="<?= $i['id'] ?>" <?= $filter_intg_id == $i['id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($i['name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <select name="status" class="form-control form-control-sm me-2 auto-submit-select">
                <option value="">All Statuses</option>
                <option value="online"  <?= $filter_status === 'online'  ? 'selected' : '' ?>>Online</option>
                <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>Offline</option>
                <option value="unknown" <?= $filter_status === 'unknown' ? 'selected' : '' ?>>Unknown</option>
            </select>
            <?php if ($filter_status || $filter_client_id): ?>
                <a href="?" class="btn btn-secondary btn-sm">Clear Filters</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Asset table -->
<div class="card card-dark">
    <div class="card-body p-0">
        <?php if (count($rmm_link_rows) === 0): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-desktop fa-3x mb-3"></i>
                <p>No RMM assets found. <a href="#" class="js-trigger-sync">Sync from <?= nullable_htmlentities($sync_target_name) ?></a> to import devices.</p>
            </div>
        <?php else: ?>
        <table class="table table-hover table-sm mb-0" id="rmm-assets-table">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th class="ps-3">Status</th>
                    <th>Hostname</th>
                    <th>Client</th>
                    <th>OS</th>
                    <th>Logged In User</th>
                    <th>Last Seen</th>
                    <th>Last Sync</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rmm_link_rows as $row):
                $status    = $row['rmm_status'];
                $badge     = $status === 'online' ? 'text-bg-success' : ($status === 'offline' ? 'text-bg-danger' : 'text-bg-secondary');
                $icon      = $status === 'online' ? 'fa-circle text-success' : ($status === 'offline' ? 'fa-circle text-danger' : 'fa-question-circle text-muted');
            ?>
            <tr>
                <td class="ps-3">
                    <i class="fas <?= $icon ?>" data-bs-toggle="tooltip" title="<?= ucfirst($status) ?>"></i>
                </td>
                <td>
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="fw-bold">
                        <?= nullable_htmlentities($row['hostname']) ?>
                    </a>
                </td>
                <td>
                    <?php if ($row['asset_client_id']): ?>
                        <a href="/agent/client_overview.php?client_id=<?= intval($row['asset_client_id']) ?>">
                            <?= nullable_htmlentities($row['client_name']) ?>
                        </a>
                    <?php elseif (lookupUserPermission('module_rmm_sync') >= 1): ?>
                        <select class="form-control form-control-sm assign-client-select" style="width:auto;display:inline-block" data-asset-id="<?= intval($row['asset_id']) ?>">
                            <option value="">Assign client...</option>
                            <?php foreach ($clients_list as $cl): ?>
                            <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= nullable_htmlentities($row['os_name']) ?></td>
                <td class="text-muted small"><?= nullable_htmlentities($row['logged_in_user']) ?: '—' ?></td>
                <td class="text-muted small">
                    <?= $row['last_seen'] ? nullable_htmlentities($row['last_seen']) : '—' ?>
                </td>
                <td class="text-muted small">
                    <?= $row['last_sync'] ? nullable_htmlentities($row['last_sync']) : '—' ?>
                </td>
                <td class="text-end pe-2">
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="btn btn-xs btn-info" data-bs-toggle="tooltip" title="View Asset">
                        <i class="fas fa-tachometer-alt"></i>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Delegated wiring (CSP forbids inline onclick= attributes).
document.addEventListener('click', function (e) {
    if (e.target.closest('.js-trigger-sync')) { e.preventDefault(); triggerSync(); }
});

function triggerSync() {
    const btn = document.getElementById('syncBtn');
    const bar = document.getElementById('syncStatus');
    const txt = document.getElementById('syncStatusText');
    const syncTargetName = <?= $sync_target_name_js ?>;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...'; }
    bar.classList.remove('d-none');
    txt.textContent = `Syncing assets from ${syncTargetName}...`;

    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=sync&integration_id=<?= $filter_intg_id ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            txt.textContent = `Sync complete: ${d.created} created, ${d.updated} updated, ${d.matched} matched, ${d.skipped} skipped.`;
            bar.classList.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown error');
            bar.classList.replace('alert-info', 'alert-danger');
            if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
        }
    })
    .catch(() => {
        txt.textContent = 'Network error during sync.';
        bar.classList.replace('alert-info', 'alert-danger');
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
    });
}

document.querySelectorAll('.assign-client-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const assetId = this.dataset.assetId;
        const clientId = this.value;
        if (!clientId) return;
        this.disabled = true;
        fetch('/agent/post/rmm_sync.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=assign_client&asset_id=' + encodeURIComponent(assetId) + '&client_id=' + encodeURIComponent(clientId)
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                location.reload();
            } else {
                alert('Failed to assign client: ' + (d.error || 'Unknown error'));
                this.disabled = false;
            }
        })
        .catch(() => {
            alert('Network error assigning client.');
            this.disabled = false;
        });
    });
});
</script>

<?php require_once "../includes/footer.php"; ?>
