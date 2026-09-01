<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

$filter_client_id = intval($_GET['client_id'] ?? 0);
$filter_status    = sanitizeInput($_GET['status'] ?? '');
$filter_type      = sanitizeInput($_GET['device_type'] ?? '');
$filter_search    = trim((string) ($_GET['q'] ?? ''));

$allowed_types = ['Firewall/Router', 'Switch', 'Access Point'];
$type_esc = $filter_type && in_array($filter_type, $allowed_types)
    ? "'" . mysqli_real_escape_string($mysqli, $filter_type) . "'"
    : null;

$where = "a.asset_type IN ('Firewall/Router','Switch','Access Point') AND a.asset_archived_at IS NULL";
// Client-scope restriction, matching agent/assets.php's convention: a tech restricted
// via user_client_permissions must only see network devices for their permitted clients.
if (!$session_is_admin && $client_access_string) { $where .= " AND a.asset_client_id IN ($client_access_string)"; }
if ($filter_client_id) { $where .= " AND a.asset_client_id=" . intval($filter_client_id); }
if ($filter_status === 'online') {
    $where .= " AND (arl.rmm_status='online' OR (arl.rmm_status IS NULL AND a.asset_status IN ('Deployed','Active','Connected')))";
} elseif ($filter_status === 'offline') {
    $where .= " AND (arl.rmm_status='offline' OR (arl.rmm_status IS NULL AND a.asset_status NOT IN ('Deployed','Active','Connected')))";
}
if ($type_esc)         { $where .= " AND a.asset_type=$type_esc"; }
if ($filter_search !== '') {
    $sq = mysqli_real_escape_string($mysqli, $filter_search);
    $where .= " AND (a.asset_name LIKE '%$sq%' OR arl.hostname LIKE '%$sq%' OR arl.model LIKE '%$sq%'
                     OR c.client_name LIKE '%$sq%' OR ai.interface_ip LIKE '%$sq%')";
}

$sql_devices = mysqli_query($mysqli,
    "SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_client_id,
            a.asset_make, a.asset_model, a.asset_status AS asset_status_raw, a.asset_notes,
            c.client_name,
            arl.rmm_status, arl.hostname, arl.model AS rmm_model, arl.os_version AS rmm_firmware,
            arl.last_seen, arl.integration_id,
            ri.name AS integration_name,
            COALESCE(arl.model, a.asset_model) AS display_model,
            (SELECT COUNT(*) FROM rmm_alerts WHERE asset_id=a.asset_id AND status='new') AS alert_count,
            (SELECT ticket_id FROM rmm_alerts WHERE asset_id=a.asset_id AND status='new'
             AND ticket_id IS NOT NULL ORDER BY created_at DESC LIMIT 1) AS open_alert_ticket_id,
            ai.interface_ip
     FROM assets a
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN asset_rmm_links arl ON arl.asset_id = a.asset_id
     LEFT JOIN rmm_integrations ri ON ri.id = arl.integration_id
     LEFT JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id AND ai.interface_primary = 1
     WHERE $where
     GROUP BY a.asset_id
     ORDER BY FIELD(a.asset_type,'Firewall/Router','Switch','Access Point'), a.asset_name ASC"
);

$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(arl.rmm_status='online')  AS online,
       SUM(arl.rmm_status='offline') AS offline,
       COUNT(*) AS total,
       SUM(a.asset_type='Firewall/Router') AS fw_count,
       SUM(a.asset_type='Switch')          AS sw_count,
       SUM(a.asset_type='Access Point')    AS ap_count,
       SUM((SELECT COUNT(*) FROM rmm_alerts WHERE asset_id=a.asset_id AND status='new') > 0) AS with_alerts
     FROM assets a
     LEFT JOIN asset_rmm_links arl ON arl.asset_id = a.asset_id
     WHERE a.asset_type IN ('Firewall/Router','Switch','Access Point') AND a.asset_archived_at IS NULL"
));

$sql_sophos = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 AND type='sophos_central' ORDER BY name");
$sophos_integrations = [];
while ($i = mysqli_fetch_assoc($sql_sophos)) { $sophos_integrations[] = $i; }
$default_sophos_id = !empty($sophos_integrations) ? $sophos_integrations[0]['id'] : 0;

$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name");

$device_icons = [
    'Firewall/Router' => 'shield-alt',
    'Switch'          => 'network-wired',
    'Access Point'    => 'wifi',
];
$type_labels = [
    'Firewall/Router' => 'Firewall',
    'Switch'          => 'Switch',
    'Access Point'    => 'Access Point',
];
?>

<!-- Header -->
<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-network-wired me-2"></i>Network</h4>
    <?php if (lookupUserPermission('module_rmm_sync') >= 1 && !empty($sophos_integrations)): ?>
    <div class="d-flex align-items-center">
        <?php if (count($sophos_integrations) > 1): ?>
        <select id="netSyncIntg" class="form-control form-control-sm me-2" style="max-width:180px">
            <?php foreach ($sophos_integrations as $i): ?>
            <option value="<?= intval($i['id']) ?>"><?= nullable_htmlentities($i['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <button class="btn btn-success btn-sm me-2 js-trigger-net-sync" id="netSyncBtn">
            <i class="fas fa-sync me-1"></i>Sync Firewalls
        </button>
    </div>
    <?php endif; ?>
    <a href="/admin/settings_integrations.php?tab=firewalls" class="btn btn-secondary btn-sm">
        <i class="fas fa-cog me-1"></i>Settings
    </a>
</div>

<!-- Sync status bar -->
<div id="netSyncStatus" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="netSyncStatusText">Syncing...</span>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-6 col-md-3">
        <div class="small-box bg-info shadow-sm">
            <div class="inner"><h3><?= intval($cnt['total']) ?></h3><p>Total Devices</p></div>
            <div class="icon"><i class="fas fa-network-wired"></i></div>
            <a href="?" class="small-box-footer">Show All <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="small-box bg-success shadow-sm">
            <div class="inner"><h3><?= intval($cnt['online']) ?></h3><p>Online</p></div>
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <a href="?status=online" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="small-box bg-danger shadow-sm">
            <div class="inner"><h3><?= intval($cnt['offline']) ?></h3><p>Offline</p></div>
            <div class="icon"><i class="fas fa-times-circle"></i></div>
            <a href="?status=offline" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="small-box bg-warning shadow-sm">
            <div class="inner"><h3><?= intval($cnt['with_alerts']) ?></h3><p>With Alerts</p></div>
            <div class="icon"><i class="fas fa-bell"></i></div>
            <a href="/agent/alerts.php?source=network&status=new" class="small-box-footer">View Alerts <i class="fas fa-arrow-circle-right"></i></a>
        </div>
    </div>
</div>

<!-- Device type breakdown -->
<div class="row mb-3">
    <?php foreach ([
        ['label' => 'Firewalls',      'count' => $cnt['fw_count'], 'icon' => 'shield-alt',    'type' => 'Firewall/Router', 'color' => '#e74c3c'],
        ['label' => 'Switches',       'count' => $cnt['sw_count'], 'icon' => 'network-wired', 'type' => 'Switch',          'color' => '#3498db'],
        ['label' => 'Access Points',  'count' => $cnt['ap_count'], 'icon' => 'wifi',          'type' => 'Access Point',    'color' => '#27ae60'],
    ] as $dt): ?>
    <div class="col-md-4">
        <a href="?device_type=<?= urlencode($dt['type']) ?><?= $filter_client_id ? '&client_id='.$filter_client_id : '' ?>"
           class="info-box shadow-sm mb-0 text-decoration-none <?= $filter_type === $dt['type'] ? 'border border-primary' : '' ?>">
            <span class="info-box-icon" style="background-color:<?= $dt['color'] ?>;min-width:70px;">
                <i class="fas fa-<?= $dt['icon'] ?>"></i>
            </span>
            <div class="info-box-content">
                <span class="info-box-text text-dark"><?= $dt['label'] ?></span>
                <span class="info-box-number text-dark"><?= intval($dt['count']) ?></span>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filter bar -->
<div class="card card-dark mb-3">
    <div class="card-body py-2">
        <form method="get" class="d-flex flex-wrap align-items-center" style="gap:6px">
            <?php if ($filter_type): ?>
            <input type="hidden" name="device_type" value="<?= htmlspecialchars($filter_type) ?>">
            <?php endif; ?>
            <select name="client_id" class="form-control form-control-sm auto-submit-select" style="max-width:200px">
                <option value="">All Clients</option>
                <?php while ($cl = mysqli_fetch_assoc($sql_clients)): ?>
                <option value="<?= $cl['client_id'] ?>" <?= $filter_client_id == $cl['client_id'] ? 'selected' : '' ?>>
                    <?= nullable_htmlentities($cl['client_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
            <select name="status" class="form-control form-control-sm auto-submit-select" style="max-width:150px">
                <option value="">All Statuses</option>
                <option value="online"  <?= $filter_status === 'online'  ? 'selected' : '' ?>>Online</option>
                <option value="offline" <?= $filter_status === 'offline' ? 'selected' : '' ?>>Offline</option>
            </select>
            <div class="input-group" style="max-width:240px">
                <input type="text" name="q" value="<?= htmlspecialchars($filter_search) ?>"
                       class="form-control form-control-sm" placeholder="Search device, client, IP…">
                <div class="input-group-append">
                    <button type="submit" class="btn btn-sm btn-secondary"><i class="fas fa-search"></i></button>
                </div>
            </div>
            <?php if ($filter_client_id || $filter_status || $filter_search !== '' || $filter_type): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Devices -->
<?php
$all_devices = [];
while ($dev = mysqli_fetch_assoc($sql_devices)) {
    $all_devices[] = $dev;
}

if (empty($all_devices)): ?>
<div class="card card-dark">
    <div class="card-body text-center text-muted py-5">
        <i class="fas fa-network-wired fa-3x mb-3 d-block"></i>
        <p class="mb-1">No network devices found<?= ($filter_client_id || $filter_status || $filter_type || $filter_search !== '') ? ' matching your filters' : '' ?>.</p>
        <?php if (!empty($sophos_integrations) && (!$filter_type || $filter_type === 'Firewall/Router')): ?>
        <p class="small"><a href="#" class="js-trigger-net-sync">Sync from Sophos Central</a> to import firewalls.</p>
        <?php endif; ?>
    </div>
</div>
<?php else:
$by_type = ['Firewall/Router' => [], 'Switch' => [], 'Access Point' => []];
foreach ($all_devices as $dev) {
    $t = $dev['asset_type'];
    if (isset($by_type[$t])) $by_type[$t][] = $dev;
}

$proc = function(array $dev) use ($device_icons): array {
    if (!empty($dev['rmm_status'])) {
        $status = $dev['rmm_status'];
    } elseif (!empty($dev['asset_status_raw'])) {
        $s = strtolower($dev['asset_status_raw']);
        $status = str_contains($s, 'deploy') || str_contains($s, 'active') || str_contains($s, 'connect') ? 'online' : 'offline';
    } else {
        $status = 'unknown';
    }
    $firmware = nullable_htmlentities($dev['rmm_firmware'] ?: '');
    if (!$firmware && !empty($dev['asset_notes']) && preg_match('/Firmware:\s*([^|]+)/i', $dev['asset_notes'], $m2)) {
        $firmware = nullable_htmlentities(trim($m2[1]));
    }
    $ago = '—';
    if (!empty($dev['last_seen'])) {
        $ago = timeAgo($dev['last_seen']);
    } elseif (!empty($dev['asset_notes']) && preg_match('/Last synced:\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2})/i', $dev['asset_notes'], $m2)) {
        $ago = timeAgo($m2[1]);
    }
    if (!empty($dev['integration_name'])) {
        $source = nullable_htmlentities($dev['integration_name']);
    } elseif (!empty($dev['asset_make']) && strtolower($dev['asset_make']) === 'ubiquiti') {
        $source = 'UniFi';
    } else {
        $source = 'Manual';
    }
    $sc     = ['online' => 'success', 'offline' => 'danger'][$status] ?? 'secondary';
    $si     = ['online' => 'check-circle', 'offline' => 'times-circle'][$status] ?? 'question-circle';
    $border = ['success' => '#28a745', 'danger' => '#dc3545', 'secondary' => '#6c757d'][$sc];
    return [
        'asset_id'    => intval($dev['asset_id']),
        'display_name'=> nullable_htmlentities($dev['hostname'] ?: $dev['asset_name']),
        'status'      => $status, 'sc' => $sc, 'si' => $si, 'border' => $border,
        'ip'          => nullable_htmlentities($dev['interface_ip'] ?? ''),
        'model'       => nullable_htmlentities($dev['display_model'] ?: ''),
        'firmware'    => $firmware, 'source' => $source, 'ago' => $ago,
        'alerts'      => intval($dev['alert_count']),
        'ticket_id'   => $dev['open_alert_ticket_id'] ? intval($dev['open_alert_ticket_id']) : null,
        'client_id'   => $dev['asset_client_id'] ? intval($dev['asset_client_id']) : null,
        'client_name' => nullable_htmlentities($dev['client_name']),
    ];
};
?>

<?php if (!empty($by_type['Firewall/Router'])): ?>
<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mb-0"><i class="fas fa-shield-alt me-2 text-danger"></i>Firewalls
            <span class="badge text-bg-secondary ms-2"><?= count($by_type['Firewall/Router']) ?></span>
        </h3>
        <?php if ($filter_type): ?><a href="?" class="btn btn-sm btn-outline-secondary ml-auto">Show All</a><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row">
        <?php foreach ($by_type['Firewall/Router'] as $dev):
            $d = $proc($dev);
            $alert_href = $d['ticket_id'] ? "/agent/ticket.php?ticket_id={$d['ticket_id']}" : "/agent/alerts.php?source=network&status=new";
        ?>
        <div class="col-lg-6 mb-3">
            <div class="card mb-0 h-100" style="border-left:4px solid <?= $d['border'] ?>">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-start">
                        <div class="me-3 pt-1">
                            <i class="fas fa-shield-alt fa-2x text-<?= $d['sc'] ?>"></i>
                        </div>
                        <div class="flex-grow-1 min-width-0">
                            <div class="d-flex align-items-start flex-wrap mb-2" style="gap:4px">
                                <a href="/agent/asset_details.php?asset_id=<?= $d['asset_id'] ?>"
                                   class="fw-bold text-dark me-1" style="font-size:15px;line-height:1.4">
                                    <?= $d['display_name'] ?>
                                </a>
                                <span class="badge badge-<?= $d['sc'] ?>"><?= ucfirst($d['status']) ?></span>
                                <?php if ($d['alerts'] > 0): ?>
                                <a href="<?= $alert_href ?>" class="badge text-bg-danger" title="<?= $d['alerts'] ?> open alert(s)">
                                    <i class="fas fa-bell me-1"></i>Click to view
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php if ($d['client_id']): ?>
                            <div class="small text-muted mb-2">
                                <i class="fas fa-building me-1"></i>
                                <a href="/agent/client_overview.php?client_id=<?= $d['client_id'] ?>"><?= $d['client_name'] ?></a>
                            </div>
                            <?php endif; ?>
                            <div class="row no-gutters" style="font-size:13px">
                                <div class="col-6 pe-2 mb-1">
                                    <div class="text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.4px">IP Address</div>
                                    <div class="text-monospace fw-bold"><?= $d['ip'] ?: '<span class="text-muted">—</span>' ?></div>
                                </div>
                                <div class="col-6 mb-1">
                                    <div class="text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.4px">Model</div>
                                    <div><?= $d['model'] ?: '<span class="text-muted">—</span>' ?></div>
                                </div>
                                <div class="col-6 pe-2">
                                    <div class="text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.4px">Firmware</div>
                                    <div class="text-secondary" style="font-size:12px"><?= $d['firmware'] ?: '<span class="text-muted">—</span>' ?></div>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted" style="font-size:10px;text-transform:uppercase;letter-spacing:.4px">Last Seen</div>
                                    <div style="font-size:12px"><?= $d['ago'] ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer py-2 d-flex justify-content-between align-items-center bg-light" style="font-size:12px">
                    <span class="text-muted"><i class="fas fa-plug me-1"></i><?= $d['source'] ?></span>
                    <a href="/agent/asset_details.php?asset_id=<?= $d['asset_id'] ?>" class="btn btn-xs btn-outline-secondary">
                        <i class="fas fa-external-link-alt me-1"></i>Details
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$net_sections = [
    ['type' => 'Switch',       'icon' => 'network-wired', 'label' => 'Switches',     'color' => 'text-primary'],
    ['type' => 'Access Point', 'icon' => 'wifi',          'label' => 'Access Points', 'color' => 'text-success'],
];
foreach ($net_sections as $sect):
    if (empty($by_type[$sect['type']])) continue;
?>
<div class="card card-dark mb-3">
    <div class="card-header py-2 d-flex align-items-center">
        <h3 class="card-title mb-0">
            <i class="fas fa-<?= $sect['icon'] ?> me-2 <?= $sect['color'] ?>"></i><?= $sect['label'] ?>
            <span class="badge text-bg-secondary ms-2"><?= count($by_type[$sect['type']]) ?></span>
        </h3>
        <?php if ($filter_type): ?><a href="?" class="btn btn-sm btn-outline-secondary ml-auto">Show All</a><?php endif; ?>
    </div>
    <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;background:#f8f9fa" class="text-muted border-bottom">
            <tr>
                <th class="ps-3" style="width:50px"></th>
                <th>Device</th>
                <th>Client</th>
                <th>IP Address</th>
                <th>Model / Firmware</th>
                <th>Source</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th style="width:70px"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($by_type[$sect['type']] as $dev):
            $d = $proc($dev);
            $alert_href = $d['ticket_id'] ? "/agent/ticket.php?ticket_id={$d['ticket_id']}" : "/agent/alerts.php?source=network&status=new";
        ?>
        <tr>
            <td class="ps-3 text-center align-middle">
                <i class="fas fa-<?= $d['si'] ?> text-<?= $d['sc'] ?> fa-lg"></i>
            </td>
            <td class="align-middle" style="padding-top:12px;padding-bottom:12px">
                <a class="fw-bold text-dark" href="/agent/asset_details.php?asset_id=<?= $d['asset_id'] ?>">
                    <?= $d['display_name'] ?>
                </a>
                <?php if ($d['alerts'] > 0): ?>
                <a href="<?= $alert_href ?>" class="badge text-bg-danger ms-1" title="<?= $d['alerts'] ?> open alert(s)">
                    <i class="fas fa-bell me-1"></i>Click to view
                </a>
                <?php endif; ?>
            </td>
            <td class="align-middle small">
                <?php if ($d['client_id']): ?>
                <a href="/agent/client_overview.php?client_id=<?= $d['client_id'] ?>"><?= $d['client_name'] ?></a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="align-middle small text-monospace"><?= $d['ip'] ?: '<span class="text-muted">—</span>' ?></td>
            <td class="align-middle small text-muted">
                <?php if ($d['model'] || $d['firmware']): ?>
                    <?php if ($d['model']): ?><div><?= $d['model'] ?></div><?php endif; ?>
                    <?php if ($d['firmware']): ?><div class="text-secondary" style="font-size:10px"><?= $d['firmware'] ?></div><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td class="align-middle small text-muted"><?= $d['source'] ?></td>
            <td class="align-middle"><span class="badge badge-<?= $d['sc'] ?>"><?= ucfirst($d['status']) ?></span></td>
            <td class="align-middle small text-muted"><?= $d['ago'] ?></td>
            <td class="text-end pe-3 align-middle">
                <a href="/agent/asset_details.php?asset_id=<?= $d['asset_id'] ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fas fa-external-link-alt"></i>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('click', function (e) {
    var el = e.target.closest('.js-trigger-net-sync');
    if (!el) { return; }
    if (el.tagName === 'A') { e.preventDefault(); }
    triggerNetSync();
});

function triggerNetSync() {
    const sel = document.getElementById('netSyncIntg');
    const integrationId = sel ? sel.value : <?= intval($default_sophos_id) ?>;
    if (!integrationId) { alert('No Sophos Central integration configured.'); return; }
    const btn = document.getElementById('netSyncBtn');
    const bar = document.getElementById('netSyncStatus');
    const txt = document.getElementById('netSyncStatusText');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...'; }
    bar.classList.remove('d-none');
    bar.className = bar.className.replace(/alert-(success|danger)/, 'alert-info');
    txt.textContent = 'Syncing firewalls from Sophos Central...';

    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=sync&integration_id=' + integrationId
    })
    .then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(d => {
        if (d.success) {
            txt.textContent = `Sync complete — ${d.created} created, ${d.updated} updated, ${d.matched} matched.`;
            bar.className = bar.className.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown error');
            bar.className = bar.className.replace('alert-info', 'alert-danger');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Firewalls'; }
        }
    })
    .catch(err => {
        txt.textContent = 'Error: ' + err.message;
        bar.className = bar.className.replace('alert-info', 'alert-danger');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-sync me-1"></i>Sync Firewalls'; }
    });
}
</script>

<?php require_once "../includes/footer.php"; ?>
