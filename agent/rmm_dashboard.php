<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

// Client-restricted techs should only see RMM data for clients they have access
// to. asset_rmm_links and rmm_script_runs have no client_id of their own, so
// they're scoped via a subquery against assets; rmm_alerts/rmm_remote_sessions/
// tickets/clients carry their own client_id and are scoped directly.
$rmm_scoped = ($client_access_string && !$session_is_admin);
$rmm_asset_scope = $rmm_scoped ? "AND asset_id IN (SELECT asset_id FROM assets WHERE asset_client_id IN ($client_access_string))" : '';

// Stats
$stats = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(rmm_status='online')  as online,
       SUM(rmm_status='offline') as offline,
       SUM(rmm_status='unknown') as unknown,
       COUNT(*)                  as total
     FROM asset_rmm_links
     WHERE 1=1 $rmm_asset_scope"
));

$alert_counts = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(status='new')          as new_cnt,
       SUM(status='acknowledged') as ack_cnt,
       SUM(status='new' AND severity IN ('critical','error')) as critical_cnt
     FROM rmm_alerts
     WHERE 1=1" . ($rmm_scoped ? " AND client_id IN ($client_access_string)" : '')
));

// Fleet live-health rollup (uses the cached columns synced onto asset_rmm_links)
$health = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       SUM(rmm_needs_reboot=1)                                              AS needs_reboot,
       SUM(rmm_maintenance_mode=1)                                          AS maintenance,
       SUM(rmm_patches_pending=1)                                           AS patches_pending,
       SUM(rmm_patches_pending=0)                                           AS patches_ok,
       SUM(rmm_patches_pending IS NULL)                                     AS patches_unknown,
       SUM(rmm_cpu_percent>=90 OR rmm_ram_percent>=90 OR rmm_disk_percent>=90) AS resource_pressure
     FROM asset_rmm_links
     WHERE 1=1 $rmm_asset_scope"
)) ?: ['needs_reboot'=>0,'maintenance'=>0,'patches_pending'=>0,'patches_ok'=>0,'patches_unknown'=>0,'resource_pressure'=>0];

// Dedup helper: an asset can have simultaneous links to more than one RMM
// integration (e.g. both Tactical RMM and Level.io tracking the same
// physical device). The queries below are per-link, not per-asset, so
// without this they could list the same device twice with no explanation.
// Collapses to one row per asset_id (preferring the Tactical RMM link when
// config_rmm_prefer_tactical is on), then re-applies the original ordering
// and LIMIT.
function rmm_dedupe_by_asset($mysqli_result, $prefer_tactical, $limit) {
    $by_asset = [];
    $order    = [];
    while ($row = mysqli_fetch_assoc($mysqli_result)) {
        $aid = intval($row['asset_id']);
        if (!isset($by_asset[$aid])) {
            $by_asset[$aid] = $row;
            $order[] = $aid;
        } elseif ($prefer_tactical
            && $row['integration_type'] === 'tactical_rmm'
            && $by_asset[$aid]['integration_type'] !== 'tactical_rmm') {
            $by_asset[$aid] = $row;
        }
    }
    $rows = [];
    foreach ($order as $aid) { $rows[] = $by_asset[$aid]; }
    return array_slice($rows, 0, $limit);
}

// Top unhealthy devices (reboot pending or a resource at/over 90%)
// Fetches extra rows before deduping so a device with two RMM links doesn't
// take up two of the final 12 slots.
$sql_unhealthy_raw = mysqli_query($mysqli,
    "SELECT arl.hostname, arl.rmm_cpu_percent, arl.rmm_ram_percent, arl.rmm_disk_percent,
            arl.rmm_needs_reboot, arl.rmm_maintenance_mode,
            a.asset_name, a.asset_id, a.asset_client_id, c.client_name, i.type AS integration_type
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE (arl.rmm_needs_reboot=1 OR arl.rmm_cpu_percent>=90 OR arl.rmm_ram_percent>=90 OR arl.rmm_disk_percent>=90)"
     . ($rmm_scoped ? " AND a.asset_client_id IN ($client_access_string)" : '') . "
     ORDER BY (arl.rmm_disk_percent>=90) DESC,
              GREATEST(COALESCE(arl.rmm_cpu_percent,0),COALESCE(arl.rmm_ram_percent,0),COALESCE(arl.rmm_disk_percent,0)) DESC
     LIMIT 40"
);
$unhealthy_rows = rmm_dedupe_by_asset($sql_unhealthy_raw, $config_rmm_prefer_tactical, 12);

// Devices reporting pending patches
$sql_patch_pending_raw = mysqli_query($mysqli,
    "SELECT arl.hostname, a.asset_name, a.asset_id, a.asset_client_id, c.client_name, i.type AS integration_type
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE arl.rmm_patches_pending=1"
     . ($rmm_scoped ? " AND a.asset_client_id IN ($client_access_string)" : '') . "
     ORDER BY a.asset_name
     LIMIT 40"
);
$patch_pending_rows = rmm_dedupe_by_asset($sql_patch_pending_raw, $config_rmm_prefer_tactical, 12);

$open_tickets = intval(mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT COUNT(*) as c FROM tickets WHERE ticket_archived_at IS NULL AND ticket_resolved_at IS NULL"
    . ($rmm_scoped ? " AND ticket_client_id IN ($client_access_string)" : '')
))['c']);

$last_sync_row = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT finished_at, status, assets_created, assets_updated FROM rmm_sync_log
     WHERE status='success' ORDER BY id DESC LIMIT 1"
));

// Offline assets
$sql_offline_raw = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_name, a.asset_id, c.client_name, a.asset_client_id, i.type AS integration_type
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE arl.rmm_status='offline'"
     . ($rmm_scoped ? " AND a.asset_client_id IN ($client_access_string)" : '') . "
     ORDER BY arl.last_seen DESC
     LIMIT 60"
);
$offline_rows = rmm_dedupe_by_asset($sql_offline_raw, $config_rmm_prefer_tactical, 20);

// Active alerts with asset link
$sql_new_alerts = mysqli_query($mysqli,
    "SELECT a.*, ast.asset_name, ast.asset_id as ast_id, ast.asset_client_id, c.client_name
     FROM rmm_alerts a
     LEFT JOIN assets ast ON ast.asset_id = a.asset_id
     LEFT JOIN clients c ON c.client_id = a.client_id
     WHERE a.status='new'"
     . ($rmm_scoped ? " AND a.client_id IN ($client_access_string)" : '') . "
     ORDER BY FIELD(a.severity,'critical','error','warning','info'), a.created_at DESC
     LIMIT 15"
);

// Recent remote sessions
$sql_recent_sessions = mysqli_query($mysqli,
    "SELECT rs.*, a.asset_name, a.asset_id, a.asset_client_id, u.user_name
     FROM rmm_remote_sessions rs
     JOIN assets a ON a.asset_id = rs.asset_id
     LEFT JOIN users u ON u.user_id = rs.user_id
     WHERE 1=1" . ($rmm_scoped ? " AND rs.client_id IN ($client_access_string)" : '') . "
     ORDER BY rs.created_at DESC
     LIMIT 8"
);

// Recent script runs
$sql_recent_runs = mysqli_query($mysqli,
    "SELECT sr.*, s.name as script_name, a.asset_name, a.asset_id, a.asset_client_id, u.user_name
     FROM rmm_script_runs sr
     LEFT JOIN rmm_scripts s ON s.id = sr.script_id
     JOIN assets a ON a.asset_id = sr.asset_id
     LEFT JOIN users u ON u.user_id = sr.user_id
     WHERE 1=1" . ($rmm_scoped ? " AND a.asset_client_id IN ($client_access_string)" : '') . "
     ORDER BY sr.started_at DESC
     LIMIT 8"
);

// Client health
$sql_clients = mysqli_query($mysqli,
    "SELECT c.client_id, c.client_name,
       SUM(arl.rmm_status='online')  as online,
       SUM(arl.rmm_status='offline') as offline,
       COUNT(arl.id)                 as total,
       (SELECT COUNT(*) FROM rmm_alerts al WHERE al.client_id = c.client_id AND al.status='new') as alerts,
       (SELECT COUNT(*) FROM tickets t WHERE t.ticket_client_id = c.client_id AND t.ticket_archived_at IS NULL AND t.ticket_resolved_at IS NULL) as open_tickets
     FROM clients c
     JOIN assets a ON a.asset_client_id = c.client_id
     JOIN asset_rmm_links arl ON arl.asset_id = a.asset_id
     WHERE c.client_archived_at IS NULL"
     . ($rmm_scoped ? " AND c.client_id IN ($client_access_string)" : '') . "
     GROUP BY c.client_id
     HAVING total > 0
     ORDER BY offline DESC, alerts DESC, c.client_name ASC"
);

// Integrations for sync selector
$sql_integrations = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 ORDER BY name");
$integrations = [];
while ($i = mysqli_fetch_assoc($sql_integrations)) { $integrations[] = $i; }
$default_intg = $config_rmm_default_integration_id ?: ($integrations[0]['id'] ?? 0);

// ---- Analytics: alert volume trend, severity breakdown, noisiest assets/clients ----
// All set-based; client-scoped identically to the alert counts above.
$alert_scope = $rmm_scoped ? " AND client_id IN ($client_access_string)" : '';

// Alert volume trend (last 30 days, grouped by day)
$trend_map = [];
$sql_trend = mysqli_query($mysqli,
    "SELECT DATE(created_at) AS d, COUNT(*) AS c
     FROM rmm_alerts
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)$alert_scope
     GROUP BY DATE(created_at)");
while ($r = mysqli_fetch_assoc($sql_trend)) { $trend_map[$r['d']] = intval($r['c']); }
$alert_trend_labels = $alert_trend_data = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $alert_trend_labels[] = date('M j', strtotime($day));
    $alert_trend_data[] = $trend_map[$day] ?? 0;
}

// Alerts by severity (last 30 days)
$sev_color_map = ['critical' => '#dc3545', 'error' => '#e83e8c', 'warning' => '#ffc107', 'info' => '#17a2b8'];
$sev_labels = $sev_counts = $sev_colors = [];
$sql_sev = mysqli_query($mysqli,
    "SELECT severity, COUNT(*) AS c
     FROM rmm_alerts
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)$alert_scope
     GROUP BY severity
     ORDER BY FIELD(severity,'critical','error','warning','info')");
while ($r = mysqli_fetch_assoc($sql_sev)) {
    $sev = $r['severity'] ?: 'unknown';
    $sev_labels[] = ucfirst($sev);
    $sev_counts[] = intval($r['c']);
    $sev_colors[] = $sev_color_map[$sev] ?? '#6c757d';
}

// Noisiest assets (last 30 days, top 10)
$noisy_asset_labels = $noisy_asset_counts = [];
$sql_noisy_assets = mysqli_query($mysqli,
    "SELECT ast.asset_name, ra.asset_id, COUNT(*) AS cnt
     FROM rmm_alerts ra
     LEFT JOIN assets ast ON ast.asset_id = ra.asset_id
     WHERE ra.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND ra.asset_id IS NOT NULL"
     . ($rmm_scoped ? " AND ra.client_id IN ($client_access_string)" : '') . "
     GROUP BY ra.asset_id, ast.asset_name
     ORDER BY cnt DESC
     LIMIT 10");
while ($r = mysqli_fetch_assoc($sql_noisy_assets)) {
    $noisy_asset_labels[] = $r['asset_name'] ?: ('Asset #' . intval($r['asset_id']));
    $noisy_asset_counts[] = intval($r['cnt']);
}

// Noisiest clients (last 30 days, top 10)
$noisy_client_labels = $noisy_client_counts = [];
$sql_noisy_clients = mysqli_query($mysqli,
    "SELECT c.client_name, ra.client_id, COUNT(*) AS cnt
     FROM rmm_alerts ra
     LEFT JOIN clients c ON c.client_id = ra.client_id
     WHERE ra.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND ra.client_id IS NOT NULL"
     . ($rmm_scoped ? " AND ra.client_id IN ($client_access_string)" : '') . "
     GROUP BY ra.client_id, c.client_name
     ORDER BY cnt DESC
     LIMIT 10");
while ($r = mysqli_fetch_assoc($sql_noisy_clients)) {
    $noisy_client_labels[] = $r['client_name'] ?: ('Client #' . intval($r['client_id']));
    $noisy_client_counts[] = intval($r['cnt']);
}
$has_alert_analytics = array_sum($alert_trend_data) > 0 || !empty($sev_counts);
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 mr-auto"><i class="fas fa-tachometer-alt me-2"></i>RMM Dashboard</h4>
    <?php if ($last_sync_row): ?>
    <small class="text-muted me-3">
        <i class="fas fa-sync-alt me-1 text-success"></i>Last sync: <?= nullable_htmlentities($last_sync_row['finished_at']) ?>
    </small>
    <?php endif; ?>
    <a href="/agent/rmm_assets.php" class="btn btn-info btn-sm me-2">
        <i class="fas fa-desktop me-1"></i>All Assets
    </a>
    <a href="/agent/rmm_alerts.php" class="btn btn-warning btn-sm me-2">
        <i class="fas fa-bell me-1"></i>Alerts<?php if ($alert_counts['new_cnt'] > 0): ?> <span class="badge text-bg-light"><?= intval($alert_counts['new_cnt']) ?></span><?php endif; ?>
    </a>
    <?php if (lookupUserPermission('module_rmm_sync') >= 1 && $default_intg): ?>
    <?php if (count($integrations) > 1): ?>
    <select id="syncIntegrationId" class="form-control form-control-sm d-inline-block w-auto me-2">
        <?php foreach ($integrations as $i): ?>
        <option value="<?= intval($i['id']) ?>" <?= $i['id'] == $default_intg ? 'selected' : '' ?>>
            <?= nullable_htmlentities($i['name']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn-success btn-sm js-quick-sync">
        <i class="fas fa-sync me-1"></i>Sync Now
    </button>
    <?php endif; ?>
</div>

<!-- Stat cards -->
<div class="row mb-3">
    <div class="col-6 col-md-2">
        <a href="/agent/rmm_assets.php?status=online" class="text-decoration-none">
        <div class="info-box bg-success mb-0">
            <span class="info-box-icon"><i class="fas fa-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Online</span>
                <span class="info-box-number"><?= intval($stats['online']) ?></span>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="/agent/rmm_assets.php?status=offline" class="text-decoration-none">
        <div class="info-box bg-danger mb-0">
            <span class="info-box-icon"><i class="fas fa-times-circle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Offline</span>
                <span class="info-box-number"><?= intval($stats['offline']) ?></span>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="/agent/rmm_assets.php" class="text-decoration-none">
        <div class="info-box bg-secondary mb-0">
            <span class="info-box-icon"><i class="fas fa-desktop"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Managed</span>
                <span class="info-box-number"><?= intval($stats['total']) ?></span>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="/agent/rmm_alerts.php?status=new" class="text-decoration-none">
        <div class="info-box <?= intval($alert_counts['critical_cnt']) > 0 ? 'bg-danger' : 'bg-warning' ?> mb-0">
            <span class="info-box-icon"><i class="fas fa-exclamation-triangle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">New Alerts</span>
                <span class="info-box-number"><?= intval($alert_counts['new_cnt']) ?></span>
                <?php if ($alert_counts['critical_cnt'] > 0): ?>
                <span class="info-box-text" style="font-size:10px"><?= intval($alert_counts['critical_cnt']) ?> critical</span>
                <?php endif; ?>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="/agent/tickets.php" class="text-decoration-none">
        <div class="info-box bg-primary mb-0">
            <span class="info-box-icon"><i class="fas fa-ticket-alt"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Open Tickets</span>
                <span class="info-box-number"><?= $open_tickets ?></span>
            </div>
        </div></a>
    </div>
    <div class="col-6 col-md-2">
        <a href="/agent/rmm_scripts.php" class="text-decoration-none">
        <div class="info-box bg-dark mb-0">
            <span class="info-box-icon"><i class="fas fa-code"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Script Runs</span>
                <?php
                $run_count = intval(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) as c FROM rmm_script_runs WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"))['c']);
                ?>
                <span class="info-box-number"><?= $run_count ?></span>
                <span class="info-box-text" style="font-size:10px">last 24h</span>
            </div>
        </div></a>
    </div>
</div>

<!-- Fleet health + patch compliance -->
<div class="row">
    <div class="col-lg-7">
        <div class="card card-dark mb-3" style="border-top:3px solid #fd7e14">
            <div class="card-header py-2 d-flex align-items-center flex-wrap" style="gap:4px">
                <h6 class="mb-0 mr-auto"><i class="fas fa-heartbeat me-2 text-warning"></i>Fleet Health</h6>
                <span class="badge text-bg-warning"><?= intval($health['needs_reboot']) ?> need reboot</span>
                <span class="badge text-bg-danger"><?= intval($health['resource_pressure']) ?> pressured</span>
                <span class="badge text-bg-info"><?= intval($health['maintenance']) ?> maintenance</span>
            </div>
            <div class="card-body p-0">
            <?php if (count($unhealthy_rows) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small"><i class="fas fa-check-circle text-success me-1"></i>No devices need attention.</p>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                        <tr>
                            <th class="ps-3">Device</th>
                            <th>Client</th>
                            <th class="text-center">CPU</th>
                            <th class="text-center">RAM</th>
                            <th class="text-center">Disk</th>
                            <th class="text-center">Flags</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $health_pill = function ($v) {
                        if ($v === null || $v === '') return '<span class="text-muted">—</span>';
                        $v = intval($v);
                        $c = $v >= 90 ? 'danger' : ($v >= 75 ? 'warning' : 'success');
                        return "<span class='badge badge-$c'>$v%</span>";
                    };
                    foreach ($unhealthy_rows as $u): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="/agent/asset_details.php?client_id=<?= intval($u['asset_client_id']) ?>&asset_id=<?= intval($u['asset_id']) ?>" class="fw-bold">
                                <?= nullable_htmlentities($u['hostname'] ?: $u['asset_name']) ?>
                            </a>
                        </td>
                        <td class="text-muted"><?= nullable_htmlentities($u['client_name']) ?></td>
                        <td class="text-center"><?= $health_pill($u['rmm_cpu_percent']) ?></td>
                        <td class="text-center"><?= $health_pill($u['rmm_ram_percent']) ?></td>
                        <td class="text-center"><?= $health_pill($u['rmm_disk_percent']) ?></td>
                        <td class="text-center" style="white-space:nowrap">
                            <?php if ($u['rmm_needs_reboot']): ?><span class="badge text-bg-warning" title="Reboot required"><i class="fas fa-power-off"></i></span><?php endif; ?>
                            <?php if ($u['rmm_maintenance_mode']): ?><span class="badge text-bg-info" title="Maintenance mode"><i class="fas fa-tools"></i></span><?php endif; ?>
                            <?php if (!$u['rmm_needs_reboot'] && !$u['rmm_maintenance_mode']): ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card card-dark mb-3" style="border-top:3px solid #17a2b8">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-download me-2 text-info"></i>Patch Compliance</h6></div>
            <div class="card-body">
                <div class="row text-center mb-2">
                    <div class="col-4"><div class="h4 mb-0 text-success"><?= intval($health['patches_ok']) ?></div><small class="text-muted">Up to date</small></div>
                    <div class="col-4"><div class="h4 mb-0 text-warning"><?= intval($health['patches_pending']) ?></div><small class="text-muted">Pending</small></div>
                    <div class="col-4"><div class="h4 mb-0 text-muted"><?= intval($health['patches_unknown']) ?></div><small class="text-muted">Unknown</small></div>
                </div>
                <?php
                $patch_total = intval($health['patches_ok']) + intval($health['patches_pending']);
                $patch_pct = $patch_total > 0 ? round(intval($health['patches_ok']) / $patch_total * 100) : 100;
                ?>
                <div class="progress mb-2" style="height:8px">
                    <div class="progress-bar bg-success" style="width:<?= $patch_pct ?>%"></div>
                </div>
                <div class="text-muted small mb-2"><?= $patch_pct ?>% compliant<span class="ms-1">(of devices reporting patch status)</span></div>
                <?php if (count($patch_pending_rows) > 0): ?>
                <div class="border-top pt-2">
                    <div class="text-muted small mb-1" style="text-transform:uppercase;letter-spacing:.4px">Needs updates</div>
                    <?php foreach ($patch_pending_rows as $pp): ?>
                    <div class="small mb-1">
                        <i class="fas fa-download text-warning me-1"></i>
                        <a href="/agent/asset_details.php?client_id=<?= intval($pp['asset_client_id']) ?>&asset_id=<?= intval($pp['asset_id']) ?>"><?= nullable_htmlentities($pp['hostname'] ?: $pp['asset_name']) ?></a>
                        <span class="text-muted">— <?= nullable_htmlentities($pp['client_name']) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
  .rmm-chart-h { position: relative; height: 280px; }
  @media (max-width: 576px) { .rmm-chart-h { height: 240px; } }

  /* KPI stat tiles: Bootstrap's .bg-* utilities only set background-color, so the
     default dark body text sits directly on a saturated fill (unreadable on
     bg-dark/success/danger/primary/secondary). Muted surface + colored left
     accent + colored icon instead, matching the app's contextual-row treatment
     (see itflow_design.css "Contextual rows"). */
  .info-box.bg-primary, .info-box.bg-secondary, .info-box.bg-success, .info-box.bg-danger,
  .info-box.bg-warning, .info-box.bg-info, .info-box.bg-dark, .info-box.bg-teal {
      background-color: var(--if-surface) !important;
      color: var(--if-ink) !important;
      border: 1px solid var(--if-border);
      border-inline-start-width: 3px;
      box-shadow: var(--if-shadow);
  }
  .info-box.bg-primary   { border-inline-start-color: #0d6efd; }
  .info-box.bg-secondary { border-inline-start-color: #6c757d; }
  .info-box.bg-success   { border-inline-start-color: #059669; }
  .info-box.bg-danger    { border-inline-start-color: #dc2626; }
  .info-box.bg-warning   { border-inline-start-color: #d97706; }
  .info-box.bg-info      { border-inline-start-color: #0891b2; }
  .info-box.bg-dark      { border-inline-start-color: #475569; }
  .info-box.bg-teal      { border-inline-start-color: var(--color-accent, #0d9488); }
  .info-box.bg-primary .info-box-icon   { color: #0d6efd; }
  .info-box.bg-secondary .info-box-icon { color: #6c757d; }
  .info-box.bg-success .info-box-icon   { color: #059669; }
  .info-box.bg-danger .info-box-icon    { color: #dc2626; }
  .info-box.bg-warning .info-box-icon   { color: #b45309; }
  .info-box.bg-info .info-box-icon      { color: #0891b2; }
  .info-box.bg-dark .info-box-icon      { color: #475569; }
  .info-box.bg-teal .info-box-icon      { color: var(--color-accent, #0d9488); }
</style>

<!-- Alert analytics (last 30 days) -->
<div class="row">
    <div class="col-lg-8">
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-chart-area me-2"></i>Alert Volume (last 30 days)</h6></div>
            <div class="card-body">
                <?php if (array_sum($alert_trend_data) > 0): ?>
                <div class="rmm-chart-h"><canvas id="alertTrendChart"></canvas></div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0 small"><i class="fas fa-check-circle text-success me-1"></i>No alerts in the last 30 days.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-layer-group me-2"></i>Alerts by Severity</h6></div>
            <div class="card-body">
                <?php if (!empty($sev_counts)): ?>
                <div class="rmm-chart-h"><canvas id="alertSeverityChart"></canvas></div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0 small">No alert data.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-volume-up me-2"></i>Noisiest Assets (last 30 days)</h6></div>
            <div class="card-body">
                <?php if (!empty($noisy_asset_counts)): ?>
                <div class="rmm-chart-h"><canvas id="noisyAssetsChart"></canvas></div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0 small">No asset alerts.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card card-dark mb-3">
            <div class="card-header py-2"><h6 class="mb-0"><i class="fas fa-volume-up me-2"></i>Noisiest Clients (last 30 days)</h6></div>
            <div class="card-body">
                <?php if (!empty($noisy_client_counts)): ?>
                <div class="rmm-chart-h"><canvas id="noisyClientsChart"></canvas></div>
                <?php else: ?>
                <p class="text-muted text-center py-4 mb-0 small">No client alerts.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div id="syncBar" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="syncBarText">Syncing...</span>
</div>

<div class="row">

    <!-- Left column -->
    <div class="col-md-6">

        <!-- Offline Assets -->
        <div class="card card-dark mb-3" style="border-top:3px solid #dc3545">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto">
                    <i class="fas fa-times-circle me-2 text-danger"></i>Offline Assets
                    <?php if (count($offline_rows) > 0): ?>
                    <span class="badge text-bg-danger ms-1"><?= count($offline_rows) ?></span>
                    <?php endif; ?>
                </h6>
                <a href="/agent/rmm_assets.php?status=offline" class="btn btn-xs btn-secondary">View All</a>
            </div>
            <div class="card-body p-0">
            <?php if (count($offline_rows) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    <i class="fas fa-check-circle text-success me-1"></i>All assets online
                </p>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                        <tr>
                            <th class="ps-3">Asset</th>
                            <th>Client</th>
                            <th>Last Seen</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($offline_rows as $row): ?>
                    <tr>
                        <td class="ps-3">
                            <i class="fas fa-circle text-danger me-1" style="font-size:8px"></i>
                            <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="fw-bold">
                                <?= nullable_htmlentities($row['hostname'] ?: $row['asset_name']) ?>
                            </a>
                            <?php if ($row['os_name']): ?>
                            <br><small class="text-muted ms-2"><?= nullable_htmlentities(substr($row['os_name'], 0, 30)) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= nullable_htmlentities($row['client_name']) ?></td>
                        <td class="text-muted" style="white-space:nowrap">
                            <?= $row['last_seen'] ? nullable_htmlentities(date('M j g:ia', strtotime($row['last_seen']))) : '—' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>

        <!-- New Alerts -->
        <div class="card card-dark mb-3" style="border-top:3px solid #ffc107">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto">
                    <i class="fas fa-bell me-2 text-warning"></i>New Alerts
                </h6>
                <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1 && intval($alert_counts['new_cnt']) > 0): ?>
                <button class="btn btn-xs btn-outline-warning me-2 js-ack-all-new">
                    <i class="fas fa-check-double me-1"></i>Ack All
                </button>
                <?php endif; ?>
                <a href="/agent/rmm_alerts.php" class="btn btn-xs btn-secondary">All Alerts</a>
            </div>
            <div class="card-body p-0" id="alertList">
            <?php if (mysqli_num_rows($sql_new_alerts) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small">
                    <i class="fas fa-check-circle text-success me-1"></i>No new alerts
                </p>
            <?php else: ?>
                <?php while ($alert = mysqli_fetch_assoc($sql_new_alerts)):
                    $sev_color = ['critical'=>'danger','error'=>'danger','warning'=>'warning','info'=>'info'][$alert['severity']] ?? 'secondary';
                    $aid = intval($alert['id']);
                ?>
                <div class="d-flex align-items-start border-bottom px-3 py-2 small" id="dash-alert-<?= $aid ?>">
                    <div class="mr-auto">
                        <div>
                            <span class="badge badge-<?= $sev_color ?> me-1"><?= nullable_htmlentities($alert['severity']) ?></span>
                            <?php if ($alert['asset_id']): ?>
                            <a href="/agent/asset_details.php?client_id=<?= intval($alert['asset_client_id'] ?? 0) ?>&asset_id=<?= intval($alert['asset_id']) ?>" class="fw-bold">
                                <?= nullable_htmlentities($alert['asset_name']) ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="text-muted mt-1" style="line-height:1.3">
                            <?= nullable_htmlentities(substr($alert['message'], 0, 100)) ?>
                        </div>
                        <div class="text-muted" style="font-size:10px">
                            <?= nullable_htmlentities($alert['client_name']) ?>
                            &bull; <?= nullable_htmlentities(date('M j g:ia', strtotime($alert['created_at']))) ?>
                        </div>
                    </div>
                    <div class="ms-2 flex-shrink-0">
                        <?php if (lookupUserPermission('module_rmm_alerts_ack') >= 1): ?>
                        <button class="btn btn-xs btn-outline-warning js-dash-alert-ack" data-alert-id="<?= $aid ?>" title="Acknowledge">
                            <i class="fas fa-eye"></i>
                        </button>
                        <?php endif; ?>
                        <?php if (lookupUserPermission('module_support') >= 2): ?>
                        <button class="btn btn-xs btn-outline-primary ms-1 js-dash-alert-ticket" data-alert-id="<?= $aid ?>" title="Create Ticket">
                            <i class="fas fa-ticket-alt"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
            </div>
        </div>

    </div><!-- /left col -->

    <!-- Right column -->
    <div class="col-md-6">

        <!-- Client Health -->
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-users me-2"></i>Client Health</h6>
                <a href="/agent/clients.php" class="btn btn-xs btn-secondary">All Clients</a>
            </div>
            <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_clients) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small">No clients with RMM assets.</p>
            <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="text-muted border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px">
                        <tr>
                            <th class="ps-3">Client</th>
                            <th class="text-center" title="Online / Total">Assets</th>
                            <th class="text-center text-danger">Offline</th>
                            <th class="text-center text-warning">Alerts</th>
                            <th class="text-center text-primary">Tickets</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($cl = mysqli_fetch_assoc($sql_clients)):
                        $row_class = '';
                        if ($cl['offline'] > 0 && $cl['alerts'] > 0) $row_class = 'table-danger';
                        elseif ($cl['offline'] > 0 || $cl['alerts'] > 0) $row_class = 'table-warning';
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td class="ps-3">
                            <a href="/agent/rmm_assets.php?client_id=<?= intval($cl['client_id']) ?>">
                                <i class="fas fa-desktop me-1 text-muted"></i><?= nullable_htmlentities($cl['client_name']) ?>
                            </a>
                        </td>
                        <td class="text-center">
                            <span class="text-success fw-bold"><?= intval($cl['online']) ?></span>
                            <span class="text-muted">/<?= intval($cl['total']) ?></span>
                        </td>
                        <td class="text-center <?= $cl['offline'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>">
                            <?= intval($cl['offline']) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($cl['alerts'] > 0): ?>
                            <a href="/agent/rmm_alerts.php?client_id=<?= intval($cl['client_id']) ?>" class="badge text-bg-warning"><?= intval($cl['alerts']) ?></a>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($cl['open_tickets'] > 0): ?>
                            <a href="/agent/tickets.php?client_id=<?= intval($cl['client_id']) ?>" class="badge text-bg-primary"><?= intval($cl['open_tickets']) ?></a>
                            <?php else: ?>
                            <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            </div>
        </div>

        <!-- Recent Remote Sessions -->
        <div class="card card-dark mb-3">
            <div class="card-header py-2">
                <h6 class="mb-0"><i class="fas fa-desktop me-2"></i>Recent Connections</h6>
            </div>
            <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_recent_sessions) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small">No remote sessions logged.</p>
            <?php else: ?>
                <?php while ($sess = mysqli_fetch_assoc($sql_recent_sessions)): ?>
                <div class="d-flex border-bottom px-3 py-2 small align-items-center">
                    <i class="fas fa-desktop me-2 text-info"></i>
                    <a href="/agent/asset_details.php?client_id=<?= intval($sess['asset_client_id'] ?? 0) ?>&asset_id=<?= intval($sess['asset_id']) ?>" class="mr-auto fw-bold">
                        <?= nullable_htmlentities($sess['asset_name']) ?>
                    </a>
                    <span class="text-muted"><?= nullable_htmlentities($sess['user_name']) ?></span>
                    <span class="text-muted ms-2" style="font-size:10px"><?= nullable_htmlentities(date('M j g:ia', strtotime($sess['created_at']))) ?></span>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Recent Script Runs -->
        <div class="card card-dark mb-3">
            <div class="card-header py-2 d-flex align-items-center">
                <h6 class="mb-0 mr-auto"><i class="fas fa-code me-2"></i>Recent Script Runs</h6>
                <a href="/agent/rmm_scripts.php" class="btn btn-xs btn-secondary">Library</a>
            </div>
            <div class="card-body p-0">
            <?php if (mysqli_num_rows($sql_recent_runs) === 0): ?>
                <p class="text-muted text-center py-3 mb-0 small">No script runs yet.</p>
            <?php else: ?>
                <?php while ($run = mysqli_fetch_assoc($sql_recent_runs)):
                    $run_badge = ['completed'=>'success','failed'=>'danger','running'=>'warning','pending'=>'secondary'][$run['status']] ?? 'secondary';
                ?>
                <div class="d-flex border-bottom px-3 py-2 small align-items-center">
                    <span class="badge badge-<?= $run_badge ?> me-2"><?= $run['status'] ?></span>
                    <div class="mr-auto">
                        <div class="fw-bold"><?= nullable_htmlentities($run['script_name'] ?? 'Manual') ?></div>
                        <div class="text-muted" style="font-size:10px">
                            <a href="/agent/asset_details.php?client_id=<?= intval($run['asset_client_id'] ?? 0) ?>&asset_id=<?= intval($run['asset_id']) ?>"><?= nullable_htmlentities($run['asset_name']) ?></a>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted" style="font-size:10px"><?= nullable_htmlentities($run['user_name']) ?></div>
                        <a href="/agent/rmm_script_run.php?run_id=<?= intval($run['id']) ?>" class="btn btn-xs btn-outline-secondary mt-1">
                            <i class="fas fa-eye"></i>
                        </a>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php endif; ?>
            </div>
        </div>

    </div><!-- /right col -->
</div>

<?php
// footer.php must load before the inline scripts below: it's what pulls in
// chart.umd.min.js. It used to sit after these <script> blocks, which threw
// "Chart is not defined" and silently left every chart on this page blank.
require_once "../includes/footer.php";
?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
const CSRF = '<?= $_SESSION['csrf_token'] ?>';

function quickSync() {
    const bar = document.getElementById('syncBar');
    const txt = document.getElementById('syncBarText');
    const select = document.getElementById('syncIntegrationId');
    const integrationId = select ? select.value : <?= intval($default_intg) ?>;
    bar.classList.remove('d-none');
    bar.className = bar.className.replace(/alert-\w+/g, 'alert') + ' alert-info';
    txt.textContent = 'Syncing assets...';
    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&action=sync&integration_id=' + integrationId
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            txt.textContent = `Sync complete: ${d.created} created, ${d.updated} updated, ${d.matched} matched.`;
            bar.classList.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown');
            bar.classList.replace('alert-info', 'alert-danger');
        }
    })
    .catch(() => {
        txt.textContent = 'Network error during sync.';
        bar.classList.replace('alert-info', 'alert-danger');
    });
}

function dashAlertAck(id, btn) {
    btn.disabled = true;
    fetch('/agent/post/rmm_alert.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&action=acknowledge&alert_id=' + id
    }).then(r => r.json()).then(d => {
        if (d.success) {
            const row = document.getElementById('dash-alert-' + id);
            if (row) row.style.opacity = '0.35';
            btn.innerHTML = '<i class="fas fa-check"></i>';
        } else { alert(d.error || 'Failed'); btn.disabled = false; }
    });
}

function dashAlertTicket(id, btn) {
    if (!confirm('Create a support ticket from this alert?')) return;
    btn.disabled = true;
    fetch('/agent/post/rmm_alert.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=' + CSRF + '&action=create_ticket&alert_id=' + id
    }).then(r => r.json()).then(d => {
        if (d.success && d.redirect) { window.location.href = d.redirect; }
        else { alert(d.error || 'Failed'); btn.disabled = false; }
    });
}

function ackAllNew(btn) {
    if (!confirm('Acknowledge all new alerts?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Acking...';
    document.querySelectorAll('[id^="dash-alert-"]').forEach(row => {
        const id = row.id.replace('dash-alert-', '');
        fetch('/agent/post/rmm_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=' + CSRF + '&action=acknowledge&alert_id=' + id
        }).then(r => r.json()).then(d => {
            if (d.success) row.style.opacity = '0.3';
        });
    });
    setTimeout(() => { btn.innerHTML = '<i class="fas fa-check-double me-1"></i>Done'; }, 1500);
}

// Delegated wiring (CSP forbids inline onclick= attributes): the buttons above
// used to call these functions directly via onclick, which this app's strict
// CSP silently blocks - Sync Now / Ack / Create Ticket / Ack All did nothing.
document.addEventListener('click', function (e) {
    var btn;
    if ((btn = e.target.closest('.js-quick-sync'))) { quickSync(); return; }
    if ((btn = e.target.closest('.js-ack-all-new'))) { ackAllNew(btn); return; }
    if ((btn = e.target.closest('.js-dash-alert-ack'))) { dashAlertAck(btn.dataset.alertId, btn); return; }
    if ((btn = e.target.closest('.js-dash-alert-ticket'))) { dashAlertTicket(btn.dataset.alertId, btn); return; }
});
</script>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
document.addEventListener('DOMContentLoaded', function () {
    // Bootstrap-like defaults for Chart.js v4
    Chart.defaults.font.family = '-apple-system,system-ui,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif';
    Chart.defaults.color = '#292b2c';

    // ALERT VOLUME TREND (last 30 days)
    (function () {
        var ctx = document.getElementById("alertTrendChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($alert_trend_labels); ?>,
                datasets: [{
                    label: 'Alerts',
                    borderColor: '#ffc107',
                    backgroundColor: 'rgba(255,193,7,0.12)',
                    pointBackgroundColor: '#ffc107',
                    fill: true,
                    tension: 0.3,
                    data: <?php echo json_encode($alert_trend_data); ?>
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 10 } },
                    y: { beginAtZero: true, ticks: { maxTicksLimit: 6, precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();

    // ALERTS BY SEVERITY
    (function () {
        var ctx = document.getElementById("alertSeverityChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($sev_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($sev_counts); ?>,
                    backgroundColor: <?php echo json_encode($sev_colors); ?>
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: true, position: 'right' } } }
        });
    })();

    // NOISIEST ASSETS (horizontal bar)
    (function () {
        var ctx = document.getElementById("noisyAssetsChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($noisy_asset_labels); ?>,
                datasets: [{
                    label: 'Alerts',
                    data: <?php echo json_encode($noisy_asset_counts); ?>,
                    backgroundColor: '#17a2b8'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } },
                    y: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();

    // NOISIEST CLIENTS (horizontal bar)
    (function () {
        var ctx = document.getElementById("noisyClientsChart");
        if (!ctx) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($noisy_client_labels); ?>,
                datasets: [{
                    label: 'Alerts',
                    data: <?php echo json_encode($noisy_client_counts); ?>,
                    backgroundColor: '#6f42c1'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(0,0,0,.125)' } },
                    y: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });
    })();
});
</script>
