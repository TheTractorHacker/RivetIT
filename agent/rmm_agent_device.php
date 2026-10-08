<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');

require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Support\Sql;

mysqli_report(MYSQLI_REPORT_OFF);
$h = static fn($v) => nullable_htmlentities((string) $v);
$device_id = intval($_GET['device_id'] ?? 0);
$uid = (int) $session_user_id;
$rmm = rivetRmmModule();
if (!$rmm->enabled()) {
    itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');
    return;
}
$cfg = $rmm->settings()->get(true);

// The same visibility rule as the API: a device outside the caller's departments looks exactly like a missing one.
$dev = $rmm->technician()->visibleDevice($uid, $device_id);
if (!$dev) {
    itflow_render_denied('That device does not exist or is outside your departments.', 'Device not found');
    return;
}
$client_id = (int) $dev['client_id'];
$authz = $rmm->authorizer();
$can_saved  = $authz->allowed($uid, RmmAbility::JOB_RUN_SAVED, $client_id);
$can_script = $authz->allowed($uid, RmmAbility::JOB_RUN_SCRIPT, $client_id);
$can_reboot = $authz->allowed($uid, RmmAbility::JOB_REBOOT, $client_id);
$can_remote = $authz->allowed($uid, RmmAbility::REMOTE_LAUNCH, $client_id);
$is_admin = $authz->allowed($uid, RmmAbility::ADMIN, $client_id);
$st = $rmm->devices()->status($dev, $cfg);
$inv = $dev['inventory_json'] ? (json_decode((string) $dev['inventory_json'], true) ?: []) : [];
$met = $dev['last_metrics_json'] ? (json_decode((string) $dev['last_metrics_json'], true) ?: []) : [];
$view = $rmm->readModel()->deviceView($device_id, $can_saved, 15) ?? [];
$checks = $view['checks'] ?? [];
$jobs = $view['jobs'] ?? [];
$mesh = !empty($view['mesh']['mapped']) ? ['mesh_node_id' => $view['mesh']['node_id'], 'source' => $view['mesh']['source']] : null;
$asset = $dev['asset_id'] ? mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT asset_id, asset_name FROM assets WHERE asset_id = ' . (int) $dev['asset_id'])) : null;
$saved = [];
if ($can_saved) {
    $res = mysqli_query($mysqli, "SELECT id, name FROM rmm_scripts WHERE enabled = 1 AND script_type = 'powershell' AND script_body IS NOT NULL AND script_body <> '' ORDER BY name LIMIT 300");
    while ($res && ($sr = mysqli_fetch_assoc($res))) { $saved[] = $sr; }
}
$badge = ['online' => 'success', 'offline' => 'danger', 'stale' => 'secondary', 'never' => 'secondary'][$st['state']];
$pct = static fn($v) => $v === null ? '<span class="text-muted">no data</span>' : htmlspecialchars((string) round((float) $v, 1)) . '%';
$bytes = static function ($b) { if ($b === null) { return '<span class="text-muted">no data</span>'; } $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float) $b; while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; } return round($b, 1) . ' ' . $u[$i]; };
$stateBadge = ['succeeded' => 'success', 'failed' => 'danger', 'timed_out' => 'warning', 'cancelled' => 'secondary', 'expired' => 'secondary', 'running' => 'info', 'queued' => 'primary'];
$ts = static fn($iso) => $iso ? htmlspecialchars(str_replace('T', ' ', rtrim($iso, 'Z'))) . ' UTC' : '<span class="text-muted">never</span>';
?>
<div class="d-flex align-items-center flex-wrap gap-2 mb-3">
    <h4 class="mb-0 me-auto"><i class="fab fa-windows me-2"></i><?= $h($dev['hostname']) ?>
        <span class="badge bg-<?= $badge ?> ms-2"><?= ucfirst($st['state']) ?></span>
        <?php if ($dev['revoked_at'] !== null) { echo '<span class="badge bg-danger ms-1">Revoked</span>'; } ?>
        <?php if ($dev['retired_at'] !== null) { echo '<span class="badge bg-secondary ms-1">Retired</span>'; } ?>
        <?php if ($dev['link_state'] === 'pending_approval') { echo '<span class="badge bg-warning text-dark ms-1">Waiting for approval</span>'; } ?>
    </h4>
    <?php if ($asset) { ?><a class="btn btn-outline-secondary btn-sm" href="/agent/asset_details.php?asset_id=<?= (int) $asset['asset_id'] ?>"><i class="fas fa-laptop me-1"></i>Asset: <?= $h($asset['asset_name']) ?></a><?php } ?>
    <?php if ($can_remote) { ?><button type="button" class="btn btn-success btn-sm" id="ea-remote"><i class="fas fa-desktop me-1"></i>Launch remote session</button><?php } ?>
    <?php if ($is_admin) { ?><a class="btn btn-outline-secondary btn-sm" href="/admin/settings_endpoint_agent.php#devices"><i class="fas fa-cog me-1"></i>Manage</a><?php } ?>
</div>
<div id="ea-msg" class="alert d-none" role="status"></div>

<div class="row">
<div class="col-lg-6">
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Status</h5></div><div class="card-body">
        <table class="table table-sm table-borderless mb-0">
            <tr><td class="text-muted" style="width:40%">Last check-in</td><td><?= $ts($st['last_checkin_at']) ?><?php if ($st['age_s'] !== null) { echo ' <span class="text-muted">(' . (int) $st['age_s'] . ' s ago)</span>'; } ?></td></tr>
            <tr><td class="text-muted">Last successful collection</td><td><?= $ts(Sql::iso($dev['last_collected_at'])) ?></td></tr>
            <tr><td class="text-muted">Last inventory</td><td><?= $ts(Sql::iso($dev['last_inventory_at'])) ?></td></tr>
            <?php if ($st['offline_since']) { ?><tr><td class="text-muted">Considered offline since</td><td><?= $ts($st['offline_since']) ?></td></tr><?php } ?>
            <tr><td class="text-muted">Agent version / ring</td><td><?= $h($dev['agent_version']) ?> / <?= $h($dev['ring']) ?></td></tr>
            <tr><td class="text-muted">Enrolled</td><td><?= $h($dev['first_seen_at']) ?> UTC (<?= (int) $dev['enroll_count'] ?> enrollment<?= (int) $dev['enroll_count'] === 1 ? '' : 's' ?>)</td></tr>
            <?php $us = $dev['update_state_json'] ? json_decode((string) $dev['update_state_json'], true) : null; if (!empty($us['last'])) { echo '<tr><td class="text-muted">Last agent update</td><td>' . $h($us['last']['version']) . ': ' . $h($us['last']['state']) . ($us['last']['detail'] ? ' (' . $h($us['last']['detail']) . ')' : '') . '</td></tr>'; } ?>
            <tr><td class="text-muted">Remote access</td><td><?= $mesh ? 'Mapped to a MeshCentral node (' . $h($mesh['source']) . ')' : 'Not mapped to a MeshCentral node' ?></td></tr>
        </table>
    </div></div>

    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Inventory</h5></div><div class="card-body">
        <table class="table table-sm table-borderless mb-2">
            <tr><td class="text-muted" style="width:40%">OS</td><td><?= $h($dev['os_version']) ?> (<?= $h($dev['arch']) ?>)</td></tr>
            <tr><td class="text-muted">Make / model</td><td><?= $h(trim(($dev['manufacturer'] ?? '') . ' ' . ($dev['model'] ?? ''))) ?></td></tr>
            <tr><td class="text-muted">Serial</td><td><?= $h($dev['serial'] ?? '') ?></td></tr>
            <tr><td class="text-muted">CPU</td><td><?= $h($inv['cpu']['model'] ?? '') ?><?= !empty($inv['cpu']['cores']) ? ' (' . (int) $inv['cpu']['cores'] . ' cores)' : '' ?></td></tr>
            <tr><td class="text-muted">Memory</td><td><?= $bytes($inv['memory_total_bytes'] ?? null) ?></td></tr>
            <tr><td class="text-muted">Uptime</td><td><?= $dev['uptime_s'] === null ? '<span class="text-muted">no data</span>' : $h(round($dev['uptime_s'] / 3600, 1) . ' h') ?></td></tr>
            <tr><td class="text-muted">Logged-in user</td><td><?= $h($dev['logged_in_user'] ?? '') ?></td></tr>
            <tr><td class="text-muted">Pending reboot</td><td><?= $dev['pending_reboot'] === null ? '<span class="text-muted">no data</span>' : ($dev['pending_reboot'] ? '<span class="badge bg-warning text-dark">Yes</span>' : 'No') ?></td></tr>
        </table>
        <?php if (!empty($inv['disks'])) { ?><div class="small text-muted">Disks</div><table class="table table-sm mb-2"><tbody>
            <?php foreach ($inv['disks'] as $d) { echo '<tr><td>' . $h($d['mount']) . '</td><td>' . $h($d['fs'] ?? '') . '</td><td>' . $bytes($d['free_bytes'] ?? null) . ' free of ' . $bytes($d['total_bytes'] ?? null) . '</td></tr>'; } ?></tbody></table><?php } ?>
        <?php if (!empty($inv['network'])) { ?><div class="small text-muted">Network adapters</div><table class="table table-sm mb-0"><tbody>
            <?php foreach ($inv['network'] as $n) { echo '<tr><td>' . $h($n['name'] ?? '') . '</td><td class="font-monospace">' . $h($n['mac'] ?? '') . '</td><td>' . $h(implode(', ', $n['ips'] ?? [])) . '</td></tr>'; } ?></tbody></table><?php } ?>
    </div></div>
</div>

<div class="col-lg-6">
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Latest health sample</h5></div><div class="card-body">
        <div class="d-flex flex-wrap gap-4">
            <div><div class="text-muted small">CPU</div><div class="h5 mb-0"><?= $pct($met['cpu_pct'] ?? null) ?></div></div>
            <div><div class="text-muted small">Memory</div><div class="h5 mb-0"><?= $pct($met['mem_pct'] ?? null) ?></div></div>
            <?php foreach ($met['disk'] ?? [] as $d) { echo '<div><div class="text-muted small">Disk ' . $h($d['mount']) . '</div><div class="h5 mb-0">' . $pct($d['used_pct'] ?? null) . '</div></div>'; } ?>
            <div><div class="text-muted small">Net in</div><div class="h5 mb-0"><?= isset($met['net_rx_bps']) && $met['net_rx_bps'] !== null ? $h(round($met['net_rx_bps'] / 1000000, 2)) . ' Mbit/s' : '<span class="text-muted">no data</span>' ?></div></div>
            <div><div class="text-muted small">Net out</div><div class="h5 mb-0"><?= isset($met['net_tx_bps']) && $met['net_tx_bps'] !== null ? $h(round($met['net_tx_bps'] / 1000000, 2)) . ' Mbit/s' : '<span class="text-muted">no data</span>' ?></div></div>
        </div>
        <div class="small text-muted mt-2">A reading the agent could not take is shown as "no data", never as zero.</div>
    </div></div>

    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Checks</h5></div><div class="card-body p-0">
        <table class="table table-sm mb-0"><thead><tr><th>Check</th><th>Status</th><th>Detail</th><th>Reported (UTC)</th></tr></thead><tbody>
        <?php foreach ($checks as $c) { $cb = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger', 'unknown' => 'secondary'][$c['status']] ?? 'secondary';
            echo '<tr><td>' . $h($c['key']) . '</td><td><span class="badge bg-' . $cb . '">' . $h($c['status']) . '</span>' . ((int) $c['consecutive_failures'] > 0 ? ' <span class="small text-muted">' . (int) $c['consecutive_failures'] . ' in a row</span>' : '') . '</td><td>' . $h($c['detail']) . '</td><td>' . $h(str_replace('T', ' ', rtrim((string) $c['last_reported_at'], 'Z'))) . '</td></tr>'; }
        if (!$checks) { echo '<tr><td colspan="4" class="text-muted p-3">No check result yet.</td></tr>'; } ?>
        </tbody></table>
    </div></div>
</div>
</div>

<?php if ($dev['asset_id'] && $dev['link_state'] === 'linked') { ?>
<div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Performance</h5></div><div class="card-body p-0">
    <div class="tab-content">
    <?php
    $asset_id = (int) $dev['asset_id'];
    $metrics_tab_asset_id = $asset_id;
    $metrics_tab_render_pane = true;
    $metrics_tab_pane_active = true;
    require_once __DIR__ . '/includes/asset/metrics_tab.php';
    ?>
    </div>
</div></div>
<?php } ?>

<div class="card mb-3" id="jobs"><div class="card-header"><h5 class="card-title mb-0">Maintenance jobs</h5></div><div class="card-body">
    <?php if ($dev['link_state'] !== 'linked' || $dev['revoked_at'] !== null || $dev['retired_at'] !== null) { ?>
        <p class="text-muted">Jobs can only be sent to an approved, active device.</p>
    <?php } elseif (!$can_saved && !$can_script && !$can_reboot) { ?>
        <p class="text-muted">Your role can view this device but not run jobs on it.</p>
    <?php } else { ?>
    <form id="ea-job-form" class="row g-2 mb-3" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>"><input type="hidden" name="device_id" value="<?= $device_id ?>">
        <div class="col-md-3"><label class="form-label small" for="ea-type">Job</label>
            <select class="form-select form-select-sm" id="ea-type" name="type">
                <?php if ($can_script) { echo '<option value="powershell">PowerShell script</option>'; } ?>
                <?php if ($saved) { echo '<option value="saved">Saved script</option>'; } ?>
                <?php if ($can_saved) { echo '<option value="collect">Collect inventory now</option>'; } ?>
                <?php if ($can_reboot) { echo '<option value="reboot">Reboot</option>'; } ?>
            </select></div>
        <div class="col-md-5 d-none" id="ea-saved-wrap"><label class="form-label small" for="ea-saved">Saved script</label>
            <select class="form-select form-select-sm" id="ea-saved" name="script_id"><?php foreach ($saved as $s) { echo '<option value="' . (int) $s['id'] . '">' . $h($s['name']) . '</option>'; } ?></select></div>
        <div class="col-md-2"><label class="form-label small" for="ea-timeout">Timeout (s)</label><input class="form-control form-control-sm" type="number" id="ea-timeout" name="timeout_s" min="1" max="<?= (int) $cfg['job_max_timeout_s'] ?>" value="<?= (int) $cfg['job_default_timeout_s'] ?>"></div>
        <div class="col-md-12" id="ea-script-wrap"><label class="form-label small" for="ea-script">Script (runs as SYSTEM, output is limited to <?= (int) $cfg['job_output_max_bytes'] ?> bytes and has credentials redacted)</label>
            <textarea class="form-control font-monospace" id="ea-script" name="script" rows="6" spellcheck="false" placeholder="Get-Service | Where-Object Status -eq 'Running' | Select-Object -First 10"></textarea></div>
        <div class="col-md-12">
            <div class="form-check"><input class="form-check-input" type="checkbox" id="ea-destructive" name="destructive" value="1"><label class="form-check-label" for="ea-destructive">This job changes the device (destructive). It will never be retried automatically.</label></div>
            <div class="form-check d-none" id="ea-confirm-wrap"><input class="form-check-input" type="checkbox" id="ea-confirm" name="confirm" value="1"><label class="form-check-label" for="ea-confirm">I confirm: run this on <strong><?= $h($dev['hostname']) ?></strong> now.</label></div>
        </div>
        <div class="col-12"><button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-play me-1"></i>Queue job</button></div>
    </form>
    <?php } ?>
    <table class="table table-sm mb-0"><thead><tr><th>Job</th><th>Type</th><th>State</th><th>Created (UTC)</th><th>Finished (UTC)</th><th>Exit</th><th></th></tr></thead><tbody>
    <?php foreach ($jobs as $j) { ?>
        <tr><td class="font-monospace small"><?= $h(substr($j['job_id'], 0, 8)) ?></td><td><?= $h($j['type']) ?><?= $j['destructive'] ? ' <span class="badge bg-warning text-dark">destructive</span>' : '' ?></td>
            <td><span class="badge bg-<?= $stateBadge[$j['state']] ?? 'secondary' ?>"><?= $h($j['state']) ?></span><?= $j['reason'] ? ' <span class="small text-muted">' . $h($j['reason']) . '</span>' : '' ?></td>
            <td><?= $ts($j['created_at']) ?></td><td><?= $j['finished_at'] ? $ts($j['finished_at']) : '' ?></td><td><?= $j['exit_code'] === null ? '' : (int) $j['exit_code'] ?></td>
            <td><?php if ($j['state'] === 'queued' && $can_saved) { ?><button type="button" class="btn btn-xs btn-outline-secondary ea-cancel" data-job="<?= $h($j['job_id']) ?>">Cancel</button><?php } ?></td></tr>
        <?php if (!empty($j['output'])) { ?><tr><td colspan="7"><pre class="small bg-light p-2 mb-0" style="max-height:14rem;overflow:auto"><?= $h($j['output']) ?><?= !empty($j['output_truncated']) ? "\n[output truncated]" : '' ?></pre></td></tr><?php } ?>
    <?php } if (!$jobs) { echo '<tr><td colspan="7" class="text-muted">No jobs yet.</td></tr>'; } ?>
    </tbody></table>
</div></div>

<?php if ($is_admin) { ?>
<div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">MeshCentral node (administrators)</h5></div><div class="card-body">
    <form id="ea-mesh-form" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['csrf_token']) ?>"><input type="hidden" name="device_id" value="<?= $device_id ?>">
        <div class="col-lg-8"><label class="form-label small" for="ea-node">Node id (node//...)</label><input class="form-control form-control-sm font-monospace" id="ea-node" name="mesh_node_id" maxlength="200" value="<?= $h($mesh['mesh_node_id'] ?? '') ?>" placeholder="node//AbCd..."></div>
        <div class="col-lg-4"><button class="btn btn-sm btn-primary" type="submit">Save mapping</button> <span class="small text-muted">Leave empty to clear. An id set here is never overwritten by what the device reports.</span></div>
    </form>
</div></div>
<?php } ?>
<?php if ($cfg['coexistence_policy'] !== '') { ?><div class="small text-muted mb-3"><?= nl2br($h($cfg['coexistence_policy'])) ?></div><?php } ?>

<script nonce="<?= $h($csp_nonce ?? '') ?>">
(function () {
    var post = function (action, data) {
        var body = new URLSearchParams(data); body.set('action', action); body.set('device_id', '<?= $device_id ?>'); body.set('csrf_token', '<?= $h($_SESSION['csrf_token']) ?>');
        return fetch('/agent/post/rmm_agent.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()}).then(function (r) { return r.json(); });
    };
    var msg = function (text, ok) { var m = document.getElementById('ea-msg'); m.className = 'alert alert-' + (ok ? 'success' : 'danger'); m.textContent = text; };
    var r = document.getElementById('ea-remote');
    if (r) r.addEventListener('click', function (force) {
        post('remote', {}).then(function (d) {
            if (d.success) { window.open(d.url, '_blank', 'noopener'); msg('Remote session opened.', true); }
            else if (d.code === 'device_offline' && confirm(d.error + '\n\nLaunch anyway?')) { post('remote', {force: '1'}).then(function (d2) { if (d2.success) { window.open(d2.url, '_blank', 'noopener'); msg('Remote session opened.', true); } else { msg(d2.error, false); } }); }
            else { msg(d.error || 'Could not start the session.', false); }
        }).catch(function () { msg('Network error.', false); });
    });
    var f = document.getElementById('ea-job-form');
    if (f) {
        var type = document.getElementById('ea-type'), destr = document.getElementById('ea-destructive'), cw = document.getElementById('ea-confirm-wrap');
        var sync = function () {
            document.getElementById('ea-saved-wrap').classList.toggle('d-none', type.value !== 'saved');
            document.getElementById('ea-script-wrap').classList.toggle('d-none', type.value !== 'powershell');
            var d = type.value === 'reboot' || destr.checked; cw.classList.toggle('d-none', !d);
            if (type.value === 'reboot') { destr.checked = true; destr.disabled = true; } else { destr.disabled = false; }
        };
        type.addEventListener('change', sync); destr.addEventListener('change', sync); sync();
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(f), data = {type: type.value === 'saved' ? 'powershell' : type.value, timeout_s: fd.get('timeout_s'), destructive: (destr.checked || type.value === 'reboot') ? '1' : '', confirm: fd.get('confirm') ? '1' : ''};
            if (type.value === 'powershell') data.script = fd.get('script'); if (type.value === 'saved') data.script_id = fd.get('script_id');
            post('submit_job', data).then(function (d) { if (d.success) { location.reload(); } else { msg(d.error || 'Not queued.', false); } }).catch(function () { msg('Network error.', false); });
        });
    }
    document.querySelectorAll('.ea-cancel').forEach(function (b) { b.addEventListener('click', function () { post('cancel_job', {job_id: b.dataset.job}).then(function (d) { if (d.success) { location.reload(); } else { msg(d.error, false); } }); }); });
    var mf = document.getElementById('ea-mesh-form');
    if (mf) mf.addEventListener('submit', function (e) { e.preventDefault(); post('set_mesh_node', {mesh_node_id: document.getElementById('ea-node').value}).then(function (d) { msg(d.success ? 'Mapping saved.' : d.error, !!d.success); }); });
})();
</script>
<?php require_once "../includes/footer.php"; ?>
