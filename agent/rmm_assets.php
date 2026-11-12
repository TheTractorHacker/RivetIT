<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

// Filter params
$filter_status    = sanitizeInput($_GET['status'] ?? '');
$filter_client_id = intval($_GET['client_id'] ?? 0);
$filter_intg_id   = intval($_GET['integration_id'] ?? 0);
$filter_q         = trim((string) ($_GET['q'] ?? ''));
$filter_os        = in_array($_GET['os'] ?? '', ['windows', 'macos', 'linux', 'other'], true) ? $_GET['os'] : '';
$filter_seen      = in_array(intval($_GET['unseen_days'] ?? 0), [1, 7, 30, 90], true) ? intval($_GET['unseen_days']) : 0;   // not seen in the last N days

// Build WHERE clause. $where_base is everything except the status, so the stat cards count within the other filters.
$where = "1=1";
if ($filter_client_id === -1) { $where .= " AND a.asset_client_id = 0"; }   // -1 = no department assigned yet
elseif ($filter_client_id > 0) { $where .= " AND a.asset_client_id=$filter_client_id"; }
if ($filter_intg_id)   { $where .= " AND arl.integration_id=$filter_intg_id"; }
if ($filter_q !== '') {
    $like = mysqli_real_escape_string($mysqli, addcslashes(mb_substr($filter_q, 0, 100), '%_\\'));
    $where .= " AND (arl.hostname LIKE '%$like%' OR a.asset_name LIKE '%$like%' OR c.client_name LIKE '%$like%' OR arl.logged_in_user LIKE '%$like%' OR arl.os_name LIKE '%$like%' OR arl.model LIKE '%$like%')";
}
if ($filter_os === 'windows')    { $where .= " AND arl.os_name LIKE '%windows%'"; }
elseif ($filter_os === 'macos')  { $where .= " AND (arl.os_name LIKE '%mac%' OR arl.os_name LIKE '%darwin%')"; }
elseif ($filter_os === 'linux')  { $where .= " AND (arl.os_name LIKE '%linux%' OR arl.os_name LIKE '%ubuntu%' OR arl.os_name LIKE '%debian%' OR arl.os_name LIKE '%centos%' OR arl.os_name LIKE '%red hat%' OR arl.os_name LIKE '%fedora%')"; }
elseif ($filter_os === 'other')  { $where .= " AND arl.os_name NOT LIKE '%windows%' AND arl.os_name NOT LIKE '%mac%' AND arl.os_name NOT LIKE '%darwin%' AND arl.os_name NOT LIKE '%linux%' AND arl.os_name NOT LIKE '%ubuntu%' AND arl.os_name NOT LIKE '%debian%'"; }
if ($filter_seen)      { $where .= " AND (arl.last_seen IS NULL OR arl.last_seen < (UTC_TIMESTAMP() - INTERVAL $filter_seen DAY))"; }
// Client-restricted techs should only see assets for clients they have access to
if ($client_access_string && !$session_is_admin) { $where .= " AND a.asset_client_id IN ($client_access_string)"; }
$where_base = $where;
if ($filter_status)    { $where .= " AND arl.rmm_status='" . mysqli_real_escape_string($mysqli, $filter_status) . "'"; }

$sql_links = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_type, a.asset_client_id, a.asset_archived_at,
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

// Counts for stat cards: within every filter except the status (the cards ARE the status filter). One row per asset, like the table.
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       COALESCE(SUM(st='online'),0) as online,
       COALESCE(SUM(st='offline'),0) as offline,
       COALESCE(SUM(st='unknown'),0) as unknown,
       COUNT(*) as total
     FROM (SELECT arl.asset_id, MIN(arl.rmm_status) AS st
           FROM asset_rmm_links arl
           JOIN assets a ON a.asset_id = arl.asset_id
           LEFT JOIN clients c ON c.client_id = a.asset_client_id
           WHERE $where_base GROUP BY arl.asset_id) t"
));

// Keep every other filter when a stat card or a clear link is used
$rmm_filter_qs = function (array $override = []) use ($filter_status, $filter_client_id, $filter_intg_id, $filter_q, $filter_os, $filter_seen) {
    $p = array_merge(['status' => $filter_status, 'client_id' => $filter_client_id ?: '', 'integration_id' => $filter_intg_id ?: '', 'q' => $filter_q, 'os' => $filter_os, 'unseen_days' => $filter_seen ?: ''], $override);

    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
$rmm_filters_active = $filter_status || $filter_client_id || $filter_q !== '' || $filter_os || $filter_seen;

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
$can_rmm_remote_connect = lookupUserPermission('module_rmm_remote_connect') >= 1;
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
            <a href="<?= $rmm_filter_qs(['status' => 'online']) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-danger">
            <div class="inner"><h3><?= intval($cnt['offline']) ?></h3><p>Offline</p></div>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <a href="<?= $rmm_filter_qs(['status' => 'offline']) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-secondary">
            <div class="inner"><h3><?= intval($cnt['unknown']) ?></h3><p>Unknown</p></div>
            <div class="icon"><i class="fas fa-question-circle"></i></div>
            <a href="<?= $rmm_filter_qs(['status' => 'unknown']) ?>" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-md-3">
        <div class="small-box bg-info">
            <div class="inner"><h3><?= intval($cnt['total']) ?></h3><p>Total Managed</p></div>
            <div class="icon"><i class="fas fa-desktop"></i></div>
            <a href="<?= $rmm_filter_qs(['status' => '']) ?>" class="small-box-footer">Show All <i class="fas fa-arrow-circle-right"></i></a>
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
        <form method="get" class="d-flex flex-wrap align-items-center gap-2">
            <input type="search" name="q" value="<?= nullable_htmlentities($filter_q) ?>" class="form-control form-control-sm w-auto" placeholder="Search hostname, department, user, OS..." aria-label="Search devices" style="min-width:16rem">
            <select name="integration_id" class="form-control form-control-sm w-auto auto-submit-select" aria-label="Integration">
                <option value="">All Integrations</option>
                <?php while ($i = mysqli_fetch_assoc($sql_integrations)): ?>
                <option value="<?= $i['id'] ?>" <?= $filter_intg_id == $i['id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($i['name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <select name="client_id" class="form-control form-control-sm w-auto auto-submit-select" aria-label="Department">
                <option value="">All Departments</option>
                <option value="-1" <?= $filter_client_id === -1 ? 'selected' : '' ?>>Not assigned yet</option>
                <?php foreach ($clients_list as $cl): ?>
                <option value="<?= intval($cl['client_id']) ?>" <?= $filter_client_id === intval($cl['client_id']) ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="status" class="form-control form-control-sm w-auto auto-submit-select" aria-label="Status">
                <option value="">All Statuses</option>
                <option value="online"  <?= $filter_status === 'online'  ? 'selected' : '' ?>>Online</option>
                <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>Offline</option>
                <option value="unknown" <?= $filter_status === 'unknown' ? 'selected' : '' ?>>Unknown</option>
            </select>
            <select name="os" class="form-control form-control-sm w-auto auto-submit-select" aria-label="Operating system">
                <option value="">All Operating Systems</option>
                <?php foreach (['windows' => 'Windows', 'macos' => 'macOS', 'linux' => 'Linux', 'other' => 'Other'] as $ov => $ol): ?>
                <option value="<?= $ov ?>" <?= $filter_os === $ov ? 'selected' : '' ?>><?= $ol ?></option>
                <?php endforeach; ?>
            </select>
            <select name="unseen_days" class="form-control form-control-sm w-auto auto-submit-select" aria-label="Last seen">
                <option value="">Any Last Seen</option>
                <?php foreach ([1 => 'Not seen in 1 day', 7 => 'Not seen in 7 days', 30 => 'Not seen in 30 days', 90 => 'Not seen in 90 days'] as $dv => $dl): ?>
                <option value="<?= $dv ?>" <?= $filter_seen === $dv ? 'selected' : '' ?>><?= $dl ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Search</button>
            <?php if ($rmm_filters_active): ?>
                <a href="<?= $rmm_filter_qs(['status' => '', 'client_id' => '', 'q' => '', 'os' => '', 'unseen_days' => '']) ?>" class="btn btn-secondary btn-sm">Clear Filters</a>
            <?php endif; ?>
            <span class="small text-muted ms-auto"><?= count($rmm_link_rows) ?> shown</span>
        </form>
    </div>
</div>

<!-- Bulk actions bar: hidden until at least one row is ticked (js/bulk_actions.js toggles #bulkActionButton by its id) -->
<div class="card card-dark mb-2" id="bulkActionButton" hidden>
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <?php if (lookupUserPermission('module_rmm_sync') >= 1): ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="fas fa-fw fa-layer-group me-2"></i>Bulk Action (<span id="selectedCount">0</span>)
            </button>
            <div class="dropdown-menu">
                <a class="dropdown-item" href="#" id="bulkAssignOpen">
                    <i class="fas fa-fw fa-arrow-right me-2"></i>Assign department...
                </a>
            </div>
        </div>
        <span id="bulkAssignRow" class="d-flex flex-wrap align-items-center gap-2" hidden>
            <select class="form-control form-control-sm w-auto" id="bulkAssignClient">
                <option value="">Choose department...</option>
                <?php foreach ($clients_list as $cl): ?>
                <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-sm btn-primary" id="bulkAssignGo" disabled>Apply to selected</button>
        </span>
        <?php else: ?>
        <span class="small text-muted"><span id="selectedCount">0</span> selected</span>
        <?php endif; ?>
    </div>
</div>

<!-- Asset table -->
<div class="card card-dark">
    <div class="card-body p-0 table-responsive">
        <?php if (count($rmm_link_rows) === 0): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-desktop fa-3x mb-3"></i>
                <p>No RMM assets found. <a href="#" class="js-trigger-sync">Sync from <?= nullable_htmlentities($sync_target_name) ?></a> to import devices.</p>
            </div>
        <?php else: ?>
        <style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
            #rmm-assets-table td { vertical-align: middle; }
            #rmm-assets-table { min-width: 980px; }
            #rmm-assets-table .rmm-host { white-space: nowrap; }
            #rmm-assets-table .rmm-user { overflow-wrap: normal; word-break: normal; }
            #rmm-assets-table .rmm-actions { white-space: nowrap; text-align: left; width: 1%; }
        </style>
        <table class="table table-hover table-sm mb-0" id="rmm-assets-table">
            <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                <tr>
                    <th class="ps-3 checkbox-column"><div class="form-check"><input class="form-check-input" id="selectAllCheckbox" type="checkbox"></div></th>
                    <th>Status</th>
                    <th>Hostname</th>
                    <th>Department</th>
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
                <td class="ps-3 checkbox-column">
                    <div class="form-check"><input class="form-check-input bulk-select" type="checkbox" name="asset_ids[]" value="<?= intval($row['asset_id']) ?>"></div>
                </td>
                <td>
                    <i class="fas <?= $icon ?>" data-bs-toggle="tooltip" title="<?= ucfirst($status) ?>"></i>
                </td>
                <td class="rmm-host">
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
                            <option value="">Assign department...</option>
                            <?php foreach ($clients_list as $cl): ?>
                            <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted small"><?= nullable_htmlentities($row['os_name']) ?></td>
                <td class="text-muted small rmm-user"><?= $row['logged_in_user'] ? str_replace('\\', '\\<wbr>', nullable_htmlentities($row['logged_in_user'])) : '—' ?></td>
                <td class="text-muted small">
                    <?= $row['last_seen'] ? nullable_htmlentities(rivetFormatTime($row['last_seen'], 'local', true, 'Y-m-d H:i')) : '—' ?>
                </td>
                <td class="text-muted small">
                    <?= $row['last_sync'] ? nullable_htmlentities(rivetFormatTime($row['last_sync'], 'local', true, 'Y-m-d H:i')) : '—' ?>
                </td>
                <td class="rmm-actions pe-2">
                    <div class="btn-group">
                        <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="btn btn-xs btn-info" title="Open this asset">
                            <i class="fas fa-arrow-right me-1" aria-hidden="true"></i>Open
                        </a>
                        <?php if ($can_rmm_remote_connect && !empty($row['tactical_agent_id'])): ?>
                            <button type="button" class="btn btn-success btn-xs" data-rmm-action="connect" data-link-id="<?= intval($row['id']) ?>" data-rmm-type="tactical" title="Remote connect">
                                <i class="fas fa-desktop me-1" aria-hidden="true"></i>Connect
                            </button>
                            <?php if (!empty($row['mesh_node_id'])): ?>
                            <button type="button" class="btn btn-success btn-xs dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" data-boundary="window"></button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" data-rmm-action="connect" data-link-id="<?= intval($row['id']) ?>" data-rmm-type="mesh">
                                    <i class="fas fa-tv me-2"></i>MeshCentral Remote Desktop
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-xs dropdown-toggle" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-label="More actions"><i class="fas fa-ellipsis-h"></i></button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/asset/asset_edit.php?id=<?= intval($row['asset_id']) ?>">
                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                            </a>
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/asset/asset_copy.php?id=<?= intval($row['asset_id']) ?>">
                                <i class="fas fa-fw fa-copy me-2"></i>Copy
                            </a>
                            <?php if ($session_user_role > 2): ?>
                                <?php if ($row['asset_archived_at']): ?>
                                <a class="dropdown-item text-info" href="post.php?restore_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </a>
                                <a class="dropdown-item text-danger text-bold confirm-link" href="post.php?delete_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </a>
                                <?php else: ?>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?archive_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<script src="../js/bulk_actions.js"></script>

<?php if ($can_rmm_remote_connect): ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function rmmConnect(linkId, type) {
    fetch('/agent/post/rmm_remote.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION["csrf_token"] ?>&link_id=' + linkId + '&type=' + type
    }).then(r => r.json()).then(d => {
        if (d.success && d.url) window.open(d.url, '_blank', 'noopener,noreferrer');
        else RivetDialog.alert('Connect failed: ' + (d.error || 'Unknown error'));
    });
}

// Delegated wiring (CSP forbids inline onclick= attributes)
document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-rmm-action="connect"]');
    if (!el) return;
    e.preventDefault();
    rmmConnect(parseInt(el.dataset.linkId, 10), el.dataset.rmmType);
});
</script>
<?php endif; ?>

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
                RivetDialog.alert('Failed to assign department: ' + (d.error || 'Unknown error'));
                this.disabled = false;
            }
        })
        .catch(() => {
            RivetDialog.alert('Network error assigning department.');
            this.disabled = false;
        });
    });
});

// Bulk "Assign department to selected": loops the same per-asset endpoint the single-row select above uses (it has no bulk form of
// its own), one request per ticked row, then reloads once. getBulkCheckboxes() is js/bulk_actions.js's helper.
(function () {
    var sel = document.getElementById('bulkAssignClient');
    var go = document.getElementById('bulkAssignGo');
    if (!sel || !go) { return; }
    document.getElementById('bulkAssignOpen').addEventListener('click', function (e) {
        e.preventDefault();
        document.getElementById('bulkAssignRow').hidden = false;
        sel.focus();
    });
    sel.addEventListener('change', function () { go.disabled = sel.value === ''; });
    go.addEventListener('click', function () {
        var clientId = sel.value;
        var ids = getBulkCheckboxes().filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
        if (!clientId || ids.length === 0) { return; }
        go.disabled = true;
        go.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Assigning...';
        Promise.all(ids.map(function (assetId) {
            return fetch('/agent/post/rmm_sync.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=assign_client&asset_id=' + encodeURIComponent(assetId) + '&client_id=' + encodeURIComponent(clientId)
            }).then(function (r) { return r.json(); });
        })).then(function (results) {
            var failed = results.filter(function (d) { return !d.success; }).length;
            if (failed > 0) {
                RivetDialog.alert(failed + ' of ' + ids.length + ' could not be assigned.');
                go.disabled = false;
                go.textContent = 'Apply to selected';
            } else {
                location.reload();
            }
        }).catch(function () {
            RivetDialog.alert('Network error assigning department.');
            go.disabled = false;
            go.textContent = 'Apply to selected';
        });
    });
})();
</script>

<?php require_once "../includes/footer.php"; ?>
