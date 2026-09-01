<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

$link_id = intval($_GET['id'] ?? 0);
if (!$link_id) { flash_alert('No asset specified', 'danger'); redirect('/agent/rmm_assets.php'); }

$link = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_type, a.asset_serial, a.asset_make, a.asset_model,
            a.asset_os, a.asset_notes, a.asset_client_id, a.asset_location_id,
            c.client_name, loc.location_name
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN locations loc ON loc.location_id = a.asset_location_id
     WHERE arl.id=$link_id"
));

if (!$link) { flash_alert('RMM asset not found', 'danger'); redirect('/agent/rmm_assets.php'); }

$asset_id  = intval($link['asset_id']);
$client_id = intval($link['asset_client_id']);
enforceClientAccess($client_id);
// Redirect to unified asset view — rmm_asset.php is deprecated
redirect('/agent/asset_details.php?asset_id=' . $asset_id);

$status_badge = $link['rmm_status'] === 'online'
    ? '<span class="badge text-bg-success"><i class="fas fa-circle me-1"></i>Online</span>'
    : ($link['rmm_status'] === 'offline'
        ? '<span class="badge text-bg-danger"><i class="fas fa-circle me-1"></i>Offline</span>'
        : '<span class="badge text-bg-secondary"><i class="fas fa-question-circle me-1"></i>Unknown</span>');

// Recent tickets for this asset
$sql_tickets = mysqli_query($mysqli,
    "SELECT t.ticket_id, t.ticket_subject, t.ticket_status, ts.ticket_status_name, ts.ticket_status_color,
            t.ticket_created_at
     FROM tickets t
     LEFT JOIN ticket_statuses ts ON ts.ticket_status_id = t.ticket_status
     WHERE t.ticket_asset_id=$asset_id AND t.ticket_archived_at IS NULL
     ORDER BY t.ticket_created_at DESC LIMIT 5"
);

// Recent alerts for this asset
$sql_alerts = mysqli_query($mysqli,
    "SELECT * FROM rmm_alerts WHERE asset_id=$asset_id AND status != 'resolved'
     ORDER BY created_at DESC LIMIT 5"
);

// Recent remote sessions
$sql_sessions = mysqli_query($mysqli,
    "SELECT rs.*, u.user_name FROM rmm_remote_sessions rs
     LEFT JOIN users u ON u.user_id = rs.user_id
     WHERE rs.asset_id=$asset_id ORDER BY rs.created_at DESC LIMIT 5"
);

// Recent script runs
$sql_runs = mysqli_query($mysqli,
    "SELECT sr.*, s.name as script_name, u.user_name
     FROM rmm_script_runs sr
     LEFT JOIN rmm_scripts s ON s.id = sr.script_id
     LEFT JOIN users u ON u.user_id = sr.user_id
     WHERE sr.asset_id=$asset_id ORDER BY sr.started_at DESC LIMIT 5"
);

// Network interfaces
$sql_ifaces = mysqli_query($mysqli,
    "SELECT * FROM asset_interfaces WHERE interface_asset_id=$asset_id"
);

// Software licenses linked
$sql_software = mysqli_query($mysqli,
    "SELECT s.software_id, s.software_name FROM software s
     JOIN software_assets sa ON sa.software_asset_id = s.software_id
     WHERE sa.software_asset_asset_id=$asset_id AND s.software_archived_at IS NULL"
);

// Credentials linked
$sql_creds = mysqli_query($mysqli,
    "SELECT c.credential_id, c.credential_name FROM credentials c
     JOIN asset_credentials ac ON ac.asset_credential_credential_id = c.credential_id
     WHERE ac.asset_credential_asset_id=$asset_id AND c.credential_archived_at IS NULL"
);
?>

<!-- Header -->
<div class="card card-dark mb-3" style="border-top:3px solid <?= $link['rmm_status'] === 'online' ? '#28a745' : ($link['rmm_status'] === 'offline' ? '#dc3545' : '#6c757d') ?>">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-start">
            <div class="mr-auto mb-2">
                <h4 class="mb-1">
                    <?= nullable_htmlentities($link['hostname']) ?>
                    <?= $status_badge ?>
                </h4>
                <div class="text-muted small">
                    <?php if ($link['client_name']): ?>
                        <a href="/agent/client_details.php?client_id=<?= $client_id ?>">
                            <i class="fas fa-users me-1"></i><?= nullable_htmlentities($link['client_name']) ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($link['location_name']): ?>
                        &nbsp;&middot;&nbsp;<i class="fas fa-map-marker-alt me-1"></i><?= nullable_htmlentities($link['location_name']) ?>
                    <?php endif; ?>
                    <?php if ($link['last_seen']): ?>
                        &nbsp;&middot;&nbsp;<i class="fas fa-clock me-1"></i>Last seen <?= nullable_htmlentities($link['last_seen']) ?>
                    <?php endif; ?>
                    <?php if ($link['logged_in_user']): ?>
                        &nbsp;&middot;&nbsp;<i class="fas fa-user me-1"></i><?= nullable_htmlentities($link['logged_in_user']) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-1">
                <?php if (lookupUserPermission('module_rmm_remote_connect') >= 1): ?>
                <div class="btn-group me-1">
                    <button class="btn btn-success btn-sm js-rmm-remote-connect" data-link-id="<?= $link_id ?>" data-type="tactical">
                        <i class="fas fa-desktop me-1"></i>Connect
                    </button>
                    <?php if ($link['mesh_node_id']): ?>
                    <button type="button" class="btn btn-success btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item js-rmm-remote-connect" href="#" data-link-id="<?= $link_id ?>" data-type="tactical">
                            <i class="fas fa-external-link-alt me-1"></i>Open in Tactical RMM
                        </a>
                        <a class="dropdown-item js-rmm-remote-connect" href="#" data-link-id="<?= $link_id ?>" data-type="mesh">
                            <i class="fas fa-desktop me-1"></i>MeshCentral Remote Desktop
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($link['tactical_agent_id']): ?>
                <button class="btn btn-info btn-sm me-1 js-rmm-open-tactical">
                    <i class="fas fa-external-link-alt me-1"></i>Tactical RMM
                </button>
                <?php endif; ?>
                <?php if (lookupUserPermission('module_support') >= 2): ?>
                <a href="/agent/ticket.php?action=new&asset_id=<?= $asset_id ?>&client_id=<?= $client_id ?>"
                   class="btn btn-warning btn-sm me-1">
                    <i class="fas fa-ticket-alt me-1"></i>Create Ticket
                </a>
                <?php endif; ?>
                <button class="btn btn-secondary btn-sm me-1 js-copy-asset-info">
                    <i class="fas fa-copy me-1"></i>Copy Info
                </button>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Left column: identity + hardware + network -->
    <div class="col-md-4">
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>System Identity</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><td class="text-muted ps-3" style="width:40%">Hostname</td><td><?= nullable_htmlentities($link['hostname']) ?></td></tr>
                    <tr><td class="text-muted ps-3">OS</td><td><?= nullable_htmlentities($link['os_name']) ?> <?= nullable_htmlentities($link['os_version']) ?></td></tr>
                    <tr><td class="text-muted ps-3">Manufacturer</td><td><?= nullable_htmlentities($link['manufacturer'] ?: $link['asset_make']) ?></td></tr>
                    <tr><td class="text-muted ps-3">Model</td><td><?= nullable_htmlentities($link['model'] ?: $link['asset_model']) ?></td></tr>
                    <tr><td class="text-muted ps-3">Serial</td><td><?= nullable_htmlentities($link['asset_serial']) ?></td></tr>
                    <tr><td class="text-muted ps-3">CPU</td><td><?= nullable_htmlentities($link['cpu']) ?></td></tr>
                    <tr><td class="text-muted ps-3">RAM</td><td><?= nullable_htmlentities($link['ram_gb']) ?> <?= $link['ram_gb'] ? 'GB' : '' ?></td></tr>
                    <tr><td class="text-muted ps-3">Logged In</td><td><?= nullable_htmlentities($link['logged_in_user']) ?></td></tr>
                    <tr><td class="text-muted ps-3">Last Sync</td><td class="text-muted small"><?= nullable_htmlentities($link['last_sync']) ?></td></tr>
                </table>
            </div>
        </div>

        <!-- Network interfaces -->
        <?php if (mysqli_num_rows($sql_ifaces) > 0): ?>
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-network-wired me-2"></i>Network</h6></div>
            <div class="card-body p-0">
                <table class="table table-sm table-borderless mb-0 small">
                <?php while ($iface = mysqli_fetch_assoc($sql_ifaces)): ?>
                    <tr class="border-bottom">
                        <td class="ps-3 text-muted" style="width:40%">IP</td>
                        <td><?= nullable_htmlentities($iface['interface_ip_address']) ?></td>
                    </tr>
                    <?php if ($iface['interface_mac']): ?>
                    <tr><td class="ps-3 text-muted">MAC</td><td class="font-monospace small"><?= nullable_htmlentities($iface['interface_mac']) ?></td></tr>
                    <?php endif; ?>
                <?php endwhile; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ITFlow asset link -->
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-link me-2"></i>ITFlow Asset</h6>
                <a href="/agent/asset_details.php?asset_id=<?= $asset_id ?>" class="btn btn-xs btn-secondary">View</a>
            </div>
            <div class="card-body p-2 small">
                <strong><?= nullable_htmlentities($link['asset_name']) ?></strong><br>
                <span class="text-muted"><?= nullable_htmlentities($link['asset_type']) ?></span>
                <?php if (mysqli_num_rows($sql_software) > 0): ?>
                <hr class="my-2">
                <div class="text-muted mb-1">Licenses</div>
                <?php while ($sw = mysqli_fetch_assoc($sql_software)): ?>
                    <div><?= nullable_htmlentities($sw['software_name']) ?></div>
                <?php endwhile; ?>
                <?php endif; ?>
                <?php if (mysqli_num_rows($sql_creds) > 0): ?>
                <hr class="my-2">
                <div class="text-muted mb-1">Credentials</div>
                <?php while ($cr = mysqli_fetch_assoc($sql_creds)): ?>
                    <a href="/agent/credential_details.php?credential_id=<?= $cr['credential_id'] ?>">
                        <?= nullable_htmlentities($cr['credential_name']) ?>
                    </a><br>
                <?php endwhile; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right column: alerts, tickets, sessions, activity -->
    <div class="col-md-8">

        <!-- Recent Alerts -->
        <?php if (lookupUserPermission('module_rmm_alerts') >= 1): ?>
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-bell me-2"></i>Active Alerts</h6>
                <a href="/agent/rmm_alerts.php?asset_id=<?= $asset_id ?>" class="btn btn-xs btn-secondary">All</a>
            </div>
            <div class="card-body p-0">
            <?php
            $has_alerts = false;
            while ($alert = mysqli_fetch_assoc($sql_alerts)):
                $has_alerts = true;
                $sev_color = ['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'][$alert['severity']] ?? 'secondary';
            ?>
                <div class="d-flex align-items-center border-bottom px-3 py-2">
                    <span class="badge badge-<?= $sev_color ?> me-2"><?= nullable_htmlentities($alert['severity']) ?></span>
                    <span class="small mr-auto"><?= nullable_htmlentities($alert['message']) ?></span>
                    <span class="text-muted small ms-2"><?= nullable_htmlentities($alert['created_at']) ?></span>
                    <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
                    <button class="btn btn-xs btn-outline-secondary ms-2 js-ack-alert" data-alert-id="<?= $alert['id'] ?>">Ack</button>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
            <?php if (!$has_alerts): ?>
                <p class="text-muted text-center py-3 mb-0 small"><i class="fas fa-check-circle me-1 text-success"></i>No active alerts</p>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Tickets -->
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-ticket-alt me-2"></i>Recent Tickets</h6>
                <a href="/agent/tickets.php?asset_id=<?= $asset_id ?>" class="btn btn-xs btn-secondary">All</a>
            </div>
            <div class="card-body p-0">
            <?php
            $has_tickets = false;
            while ($tkt = mysqli_fetch_assoc($sql_tickets)):
                $has_tickets = true;
            ?>
                <div class="d-flex align-items-center border-bottom px-3 py-2">
                    <a href="/agent/ticket.php?ticket_id=<?= intval($tkt['ticket_id']) ?>" class="small mr-auto fw-bold">
                        <?= nullable_htmlentities($tkt['ticket_subject']) ?>
                    </a>
                    <span class="badge ms-2 <?= tagTextClass($tkt['ticket_status_color'] ?? '#6c757d') ?>" style="background:<?= nullable_htmlentities($tkt['ticket_status_color'] ?? '#6c757d') ?>">
                        <?= nullable_htmlentities($tkt['ticket_status_name']) ?>
                    </span>
                    <span class="text-muted small ms-2"><?= nullable_htmlentities($tkt['ticket_created_at']) ?></span>
                </div>
            <?php endwhile; ?>
            <?php if (!$has_tickets): ?>
                <p class="text-muted text-center py-3 mb-0 small">No tickets</p>
            <?php endif; ?>
            </div>
        </div>

        <!-- Tabbed sections for live data -->
        <div class="card card-dark mb-3">
            <div class="card-header py-0">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-hw">Hardware</a></li>
                    <li class="nav-item"><a class="nav-link js-load-tab" data-bs-toggle="tab" href="#tab-sw" data-tab-type="software">Software</a></li>
                    <li class="nav-item"><a class="nav-link js-load-tab" data-bs-toggle="tab" href="#tab-svc" data-tab-type="services">Services</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-scripts">Scripts</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-sessions">Sessions</a></li>
                </ul>
            </div>
            <div class="tab-content">
                <!-- Hardware tab (cached) -->
                <div class="tab-pane active" id="tab-hw">
                    <div class="p-3 small">
                        <?php
                        $raw = json_decode($link['raw_data_json'] ?? '{}', true);
                        $wmi = $raw['wmi_detail'] ?? [];
                        ?>
                        <?php if (!empty($wmi['disk'])): ?>
                            <strong>Disks</strong>
                            <table class="table table-sm table-borderless mt-1 mb-3">
                            <?php foreach ($wmi['disk'] as $disk): ?>
                                <tr>
                                    <td class="text-muted ps-0"><?= nullable_htmlentities($disk['DeviceID'] ?? '') ?></td>
                                    <td><?= nullable_htmlentities($disk['Model'] ?? '') ?></td>
                                    <td class="text-end">
                                        <?php
                                        $gb = round(($disk['Size'] ?? 0) / 1073741824, 0);
                                        echo $gb > 0 ? $gb . ' GB' : '';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </table>
                        <?php endif; ?>
                        <div class="row">
                            <div class="col-6">
                                <strong>CPU</strong><br><span class="text-muted"><?= nullable_htmlentities($link['cpu']) ?></span>
                            </div>
                            <div class="col-6">
                                <strong>RAM</strong><br><span class="text-muted"><?= nullable_htmlentities($link['ram_gb']) ?> GB</span>
                            </div>
                        </div>
                        <div class="text-muted mt-2 small"><em>Hardware data cached from last sync. Use live fetch below for real-time detail.</em></div>
                        <button class="btn btn-sm btn-outline-info mt-2 js-load-live-hw">
                            <i class="fas fa-sync me-1"></i>Fetch Live Hardware (WMI)
                        </button>
                        <div id="live-hw-result" class="mt-2"></div>
                    </div>
                </div>

                <!-- Software tab -->
                <div class="tab-pane" id="tab-sw">
                    <div id="sw-content" class="p-3 text-muted small text-center">
                        <button class="btn btn-sm btn-outline-info js-load-tab" data-tab-type="software">
                            <i class="fas fa-sync me-1"></i>Load Software List
                        </button>
                    </div>
                </div>

                <!-- Services tab -->
                <div class="tab-pane" id="tab-svc">
                    <div id="svc-content" class="p-3 text-muted small text-center">
                        <button class="btn btn-sm btn-outline-info js-load-tab" data-tab-type="services">
                            <i class="fas fa-sync me-1"></i>Load Running Services
                        </button>
                    </div>
                </div>

                <!-- Scripts tab -->
                <div class="tab-pane" id="tab-scripts">
                    <div class="p-3">
                        <?php if (lookupUserPermission('module_rmm_scripts') >= 1): ?>
                        <div class="mb-3">
                            <select id="script_select" class="form-control form-control-sm d-inline-block" style="width:auto">
                                <option value="">— Select script —</option>
                                <?php
                                $sql_scripts = mysqli_query($mysqli, "SELECT id, name, category FROM rmm_scripts WHERE enabled=1 ORDER BY category, name");
                                while ($scr = mysqli_fetch_assoc($sql_scripts)):
                                ?>
                                <option value="<?= $scr['id'] ?>">[<?= nullable_htmlentities($scr['category']) ?>] <?= nullable_htmlentities($scr['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                            <button class="btn btn-sm btn-warning ms-2 js-run-script">
                                <i class="fas fa-play me-1"></i>Run Script
                            </button>
                        </div>
                        <?php endif; ?>
                        <div id="script-runs-list">
                        <?php
                        mysqli_data_seek($sql_runs, 0);
                        $has_runs = false;
                        while ($run = mysqli_fetch_assoc($sql_runs)):
                            $has_runs = true;
                            $run_badge = ['completed'=>'success','failed'=>'danger','running'=>'warning','pending'=>'secondary'][$run['status']] ?? 'secondary';
                        ?>
                            <div class="border rounded mb-2 p-2 small">
                                <div class="d-flex align-items-center mb-1">
                                    <strong class="mr-auto"><?= nullable_htmlentities($run['script_name'] ?? 'Manual') ?></strong>
                                    <span class="badge badge-<?= $run_badge ?>"><?= $run['status'] ?></span>
                                    <span class="text-muted ms-2"><?= nullable_htmlentities($run['started_at']) ?></span>
                                </div>
                                <div class="text-muted"><?= nullable_htmlentities($run['user_name']) ?></div>
                                <?php if ($run['output']): ?>
                                <pre class="bg-dark text-light p-2 mt-1 rounded small" style="max-height:100px;overflow:auto"><?= nullable_htmlentities($run['output']) ?></pre>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                        <?php if (!$has_runs): ?>
                            <p class="text-muted text-center mb-0">No script runs yet.</p>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Remote sessions tab -->
                <div class="tab-pane" id="tab-sessions">
                    <div class="p-0">
                    <?php
                    $has_sess = false;
                    mysqli_data_seek($sql_sessions, 0);
                    while ($sess = mysqli_fetch_assoc($sql_sessions)):
                        $has_sess = true;
                    ?>
                        <div class="d-flex border-bottom px-3 py-2 small align-items-center">
                            <i class="fas fa-desktop me-2 text-info"></i>
                            <span class="mr-auto">
                                <?= nullable_htmlentities($sess['user_name']) ?>
                                <span class="text-muted ms-1">(<?= nullable_htmlentities($sess['connection_type']) ?>)</span>
                            </span>
                            <span class="text-muted"><?= nullable_htmlentities($sess['created_at']) ?></span>
                        </div>
                    <?php endwhile; ?>
                    <?php if (!$has_sess): ?>
                        <p class="text-muted text-center py-3 mb-0 small">No remote sessions logged.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Copy info textarea (hidden) -->
<textarea id="copy-asset-info" class="d-none">Hostname: <?= nullable_htmlentities($link['hostname']) ?>
OS: <?= nullable_htmlentities($link['os_name']) ?> <?= nullable_htmlentities($link['os_version']) ?>
Manufacturer: <?= nullable_htmlentities($link['manufacturer'] ?: $link['asset_make']) ?>
Model: <?= nullable_htmlentities($link['model'] ?: $link['asset_model']) ?>
Serial: <?= nullable_htmlentities($link['asset_serial']) ?>
CPU: <?= nullable_htmlentities($link['cpu']) ?>
RAM: <?= nullable_htmlentities($link['ram_gb']) ?> GB
Status: <?= $link['rmm_status'] ?>
Last Seen: <?= nullable_htmlentities($link['last_seen']) ?>
Client: <?= nullable_htmlentities($link['client_name']) ?>
</textarea>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const LINK_ID    = <?= $link_id ?>;
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const AGENT_ID   = '<?= addslashes($link['tactical_agent_id']) ?>';

function remoteConnect(linkId, type) {
    fetch('/agent/post/rmm_remote.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF_TOKEN}&link_id=${linkId}&type=${type}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success && d.url) {
            window.open(d.url, '_blank', 'noopener,noreferrer');
        } else {
            alert('Remote connect failed: ' + (d.error || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error during remote connect.'));
}

function openTactical() {
    remoteConnect(LINK_ID, 'tactical');
}

function loadTab(type) {
    const containerMap = {software: 'sw-content', services: 'svc-content'};
    const container = document.getElementById(containerMap[type]);
    if (!container || container.dataset.loaded) return;
    container.dataset.loaded = '1';
    container.innerHTML = '<p class="text-muted text-center py-3"><i class="fas fa-spinner fa-spin me-2"></i>Loading...</p>';

    fetch(`/agent/post/rmm_live_data.php?link_id=${LINK_ID}&type=${type}&csrf_token=${CSRF_TOKEN}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { container.innerHTML = `<p class="text-muted text-center py-3">${d.error || 'Failed to load'}</p>`; return; }
            if (type === 'software') {
                let html = '<table class="table table-sm table-hover mb-0"><thead class="text-muted small border-bottom"><tr><th class="ps-3">Name</th><th>Version</th><th>Publisher</th></tr></thead><tbody>';
                (d.data || []).forEach(s => {
                    html += `<tr><td class="ps-3 small">${esc(s.name||s.Name||'')}</td><td class="small text-muted">${esc(s.version||s.Version||'')}</td><td class="small text-muted">${esc(s.publisher||s.Publisher||'')}</td></tr>`;
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            } else if (type === 'services') {
                let html = '<table class="table table-sm table-hover mb-0"><thead class="text-muted small border-bottom"><tr><th class="ps-3">Service</th><th>Status</th><th>Display Name</th></tr></thead><tbody>';
                (d.data || []).forEach(s => {
                    const running = (s.status || s.Status || '').toLowerCase() === 'running';
                    html += `<tr><td class="ps-3 small font-monospace">${esc(s.name||s.Name||'')}</td><td><span class="badge badge-${running?'success':'secondary'}">${esc(s.status||s.Status||'')}</span></td><td class="small text-muted">${esc(s.display_name||s.DisplayName||'')}</td></tr>`;
                });
                html += '</tbody></table>';
                container.innerHTML = html;
            }
        })
        .catch(() => { container.innerHTML = '<p class="text-muted text-center py-3">Failed to fetch live data.</p>'; });
}

function loadLiveHardware() {
    const div = document.getElementById('live-hw-result');
    div.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Fetching...';
    fetch(`/agent/post/rmm_live_data.php?link_id=${LINK_ID}&type=wmi&csrf_token=${CSRF_TOKEN}`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) { div.innerHTML = `<p class="text-danger small">${d.error}</p>`; return; }
            div.innerHTML = `<pre class="bg-dark text-light p-2 rounded small" style="max-height:300px;overflow:auto">${esc(JSON.stringify(d.data, null, 2))}</pre>`;
        });
}

function runScript() {
    const sel = document.getElementById('script_select');
    const sid = sel.value;
    if (!sid) { alert('Please select a script.'); return; }
    if (!confirm('Run this script on ' + '<?= addslashes($link['hostname']) ?>' + '?')) return;

    fetch('/agent/post/rmm_script_run.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF_TOKEN}&script_id=${sid}&link_id=${LINK_ID}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            alert('Script queued. Run ID: ' + d.run_id);
            location.reload();
        } else {
            alert('Script failed: ' + (d.error || 'Unknown'));
        }
    });
}

function ackAlert(alertId, btn) {
    fetch('/agent/post/rmm_alert.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `csrf_token=${CSRF_TOKEN}&action=acknowledge&alert_id=${alertId}`
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) { btn.closest('.d-flex').remove(); }
        else { alert('Failed: ' + d.error); }
    });
}

function copyAssetInfo() {
    const txt = document.getElementById('copy-asset-info').value;
    navigator.clipboard.writeText(txt).then(() => alert('Asset info copied to clipboard.'));
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
}
</script>

<?php require_once "../includes/footer.php"; ?>
