<?php
require_once "includes/inc_all_admin.php";

use ITFlow\EndpointAgent\Config;
use ITFlow\EndpointAgent\Db;
use ITFlow\EndpointAgent\Devices;

mysqli_report(MYSQLI_REPORT_OFF);
$cfg = Config::get(true);
$csrf = $_SESSION['csrf_token'];
$h = static fn($v) => nullable_htmlentities((string) $v);
$newToken = $_SESSION['ea_new_token'] ?? null;
unset($_SESSION['ea_new_token']);

$tableReady = Db::val("SHOW TABLES LIKE 'endpoint_agent_settings'") !== null;
if (!$tableReady) {
    echo '<div class="alert alert-warning">Run the database update first (Administration &rarr; Updates), then reload this page.</div>';
    require_once "../includes/footer.php";
    return;
}

$clients = Db::all('SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name');
$clientName = [];
foreach ($clients as $c) { $clientName[(int) $c['client_id']] = $c['client_name']; }
$devices = Db::all('SELECT d.*, a.asset_name FROM endpoint_agent_devices d LEFT JOIN assets a ON a.asset_id = d.asset_id ORDER BY d.hostname, d.device_id LIMIT 500');
$pending = array_values(array_filter($devices, static fn($d) => $d['link_state'] === 'pending_approval' && $d['revoked_at'] === null && $d['retired_at'] === null));
$tokens = Db::all('SELECT * FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 50');
$attempts = Db::all('SELECT attempted_at, ip_text, reason, token_selector FROM endpoint_agent_enroll_attempts WHERE success = 0 ORDER BY attempt_id DESC LIMIT 15');
$releases = Db::all('SELECT * FROM endpoint_agent_releases ORDER BY release_id DESC LIMIT 30');
$counts = ['online' => 0, 'offline' => 0, 'stale' => 0, 'never' => 0];
foreach ($devices as $d) { if ($d['retired_at'] === null && $d['revoked_at'] === null) { $counts[Devices::status($d, $cfg)['state']]++; } }
$checksText = json_encode(Config::checks(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$badge = ['online' => 'success', 'offline' => 'danger', 'stale' => 'secondary', 'never' => 'secondary'];
$serviceUrlHint = 'https://' . $config_base_url;
?>
<div class="card mb-3">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0"><i class="fas fa-fw fa-satellite me-2"></i>Endpoint agent</h3>
        <span class="badge bg-<?= $cfg['enabled'] ? 'success' : 'secondary' ?> fs-6"><?= $cfg['enabled'] ? 'On' : 'Off' ?></span>
    </div>
    <div class="card-body">
        <p class="text-muted mb-2">The built-in Windows agent reports inventory, health and checks, runs controlled maintenance jobs and links each endpoint to its asset. Remote access uses your MeshCentral server. Enrolling an agent is optional per device: Tactical RMM, Level, Action1 and Sophos links keep working beside it.</p>
        <div class="d-flex flex-wrap gap-3 small mb-0">
            <span><span class="badge bg-success">Online</span> checked in within <?= (int) $cfg['offline_after_s'] ?> s: <strong><?= $counts['online'] ?></strong></span>
            <span><span class="badge bg-danger">Offline</span> quiet for longer than that: <strong><?= $counts['offline'] ?></strong></span>
            <span><span class="badge bg-secondary">Stale</span> not seen for <?= (int) round($cfg['stale_after_s'] / 86400) ?> days or more: <strong><?= $counts['stale'] ?></strong></span>
            <span><span class="badge bg-secondary">Never</span> enrolled, no check-in yet: <strong><?= $counts['never'] ?></strong></span>
        </div>
    </div>
</div>

<?php if ($newToken) { ?>
<div class="alert alert-success">
    <strong>New enrollment token.</strong> It is shown once and only its hash is stored. Put it in the installer command (<code>RivetITAgent.msi ENROLLMENT_TOKEN=...</code>).
    <div class="input-group mt-2"><input type="text" class="form-control font-monospace" readonly data-ea-select value="<?= $h($newToken['token']) ?>"></div>
</div>
<?php } ?>

<?php if (!$config_module_enable_rmm) { ?>
<div class="alert alert-warning">The RMM module is switched off (Administration &rarr; Modules). Devices still enroll and report, but they will not appear in the RMM pages until it is on.</div>
<?php } ?>

<div class="card mb-3" id="settings">
    <div class="card-header"><h4 class="card-title mb-0">Service and defaults</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" id="ea_enabled" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="ea_enabled">Turn on the endpoint agent service (off by default)</label>
                <div class="form-text">The first time this is switched on an instance signing key is generated. Agents use its public half to verify jobs and updates.</div>
            </div>
            <div class="row g-3">
                <div class="col-lg-6">
                    <label class="form-label" for="ea_url">Service URL agents connect to</label>
                    <input type="url" class="form-control" id="ea_url" name="service_url" maxlength="500" value="<?= $h($cfg['service_url']) ?>" placeholder="<?= $h($serviceUrlHint) ?>">
                    <div class="form-text">TLS is required. Shown to installers and included in the deployment command.</div>
                </div>
                <div class="col-lg-3"><label class="form-label" for="ea_ci">Check-in interval (s)</label><input type="number" class="form-control" id="ea_ci" name="check_in_interval_s" min="30" max="3600" value="<?= (int) $cfg['check_in_interval_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_col">Collect interval (s)</label><input type="number" class="form-control" id="ea_col" name="collect_interval_s" min="10" max="3600" value="<?= (int) $cfg['collect_interval_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_off">Offline after (s)</label><input type="number" class="form-control" id="ea_off" name="offline_after_s" min="60" max="86400" value="<?= (int) $cfg['offline_after_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_stale">Stale after (s)</label><input type="number" class="form-control" id="ea_stale" name="stale_after_s" min="3600" value="<?= (int) $cfg['stale_after_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_fd">Failures before an alert</label><input type="number" class="form-control" id="ea_fd" name="failure_debounce" min="1" max="20" value="<?= (int) $cfg['failure_debounce'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_rd">Successes before it recovers</label><input type="number" class="form-control" id="ea_rd" name="recovery_debounce" min="1" max="20" value="<?= (int) $cfg['recovery_debounce'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_ret">Check-in retention (days)</label><input type="number" class="form-control" id="ea_ret" name="retention_days" min="1" max="400" value="<?= (int) $cfg['retention_days'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_jret">Job history retention (days)</label><input type="number" class="form-control" id="ea_jret" name="job_retention_days" min="1" value="<?= (int) $cfg['job_retention_days'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_jo">Job output limit (bytes)</label><input type="number" class="form-control" id="ea_jo" name="job_output_max_bytes" min="1024" max="200000" value="<?= (int) $cfg['job_output_max_bytes'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_jt">Default job timeout (s)</label><input type="number" class="form-control" id="ea_jt" name="job_default_timeout_s" min="5" value="<?= (int) $cfg['job_default_timeout_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_jm">Maximum job timeout (s)</label><input type="number" class="form-control" id="ea_jm" name="job_max_timeout_s" min="30" value="<?= (int) $cfg['job_max_timeout_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_je">Job expires if not started (s)</label><input type="number" class="form-control" id="ea_je" name="job_expiry_s" min="60" value="<?= (int) $cfg['job_expiry_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_ja">Acknowledgement wait (s)</label><input type="number" class="form-control" id="ea_ja" name="job_ack_timeout_s" min="30" value="<?= (int) $cfg['job_ack_timeout_s'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_jn">Attempts (harmless jobs)</label><input type="number" class="form-control" id="ea_jn" name="job_max_attempts" min="1" max="10" value="<?= (int) $cfg['job_max_attempts'] ?>"></div>
                <div class="col-lg-3"><label class="form-label" for="ea_ttl">Longest enrollment token life (h)</label><input type="number" class="form-control" id="ea_ttl" name="enroll_max_ttl_h" min="1" max="720" value="<?= (int) $cfg['enroll_max_ttl_h'] ?>"></div>
                <div class="col-lg-6">
                    <label class="form-label" for="ea_pol">A device that matches no asset</label>
                    <select class="form-select" id="ea_pol" name="unmatched_policy">
                        <option value="approval" <?= $cfg['unmatched_policy'] === 'approval' ? 'selected' : '' ?>>Wait for an administrator to approve it (recommended)</option>
                        <option value="auto_create" <?= $cfg['unmatched_policy'] === 'auto_create' ? 'selected' : '' ?>>Create a new asset automatically</option>
                    </select>
                    <div class="form-text">A hostname match alone never links a device. Ambiguous matches always wait for approval.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="ea_checks">Check schedule delivered to agents (JSON)</label>
                    <textarea class="form-control font-monospace" id="ea_checks" name="checks_json" rows="9"><?= $h($checksText) ?></textarea>
                    <div class="form-text">Types: <code>service</code>, <code>disk</code>, <code>pending_reboot</code>, <code>script</code> (bounded, at most 8 KiB and 60 s). Every entry is signed. Leave empty to restore the defaults.</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="ea_co">Coexistence and ownership policy (shown to technicians)</label>
                    <textarea class="form-control" id="ea_co" name="coexistence_policy" rows="3" maxlength="4000"><?= $h($cfg['coexistence_policy']) ?></textarea>
                    <div class="form-text">The agent never removes or reconfigures another RMM, antivirus/EDR, backup or remote-access agent. Retiring a device does not uninstall MeshCentral. Details: docs/ENDPOINT_AGENT.md.</div>
                </div>
            </div>
            <button type="submit" name="save_agent_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Save settings</button>
        </form>
    </div>
</div>

<div class="card mb-3" id="signing">
    <div class="card-header"><h4 class="card-title mb-0">Signing key</h4></div>
    <div class="card-body">
        <?php if ($cfg['signing_public_key'] === '') { ?>
            <p class="text-muted mb-0">Generated automatically the first time the service is switched on.</p>
        <?php } else { ?>
            <p class="mb-1">Key id <code><?= $h($cfg['signing_key_id']) ?></code>, created <?= $h($cfg['signing_key_created_at']) ?> UTC. The private key is stored encrypted.</p>
            <p class="mb-2 small">Public key (agents receive this at enrollment): <code class="text-break"><?= $h($cfg['signing_public_key']) ?></code></p>
            <form action="post.php" method="post" data-ea-confirm="Generate a new signing key? Every enrolled agent must re-enroll before it accepts jobs or updates again.">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="rotate_signing_key" class="btn btn-outline-danger btn-sm"><i class="fas fa-sync me-1"></i>Rotate signing key</button>
            </form>
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="tokens">
    <div class="card-header"><h4 class="card-title mb-0">Enrollment tokens</h4></div>
    <div class="card-body">
        <form action="post.php" method="post" class="row g-2 align-items-end mb-3" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-lg-3"><label class="form-label" for="et_client">Department</label>
                <select class="form-select" id="et_client" name="client_id" required><option value="">Choose...</option>
                    <?php foreach ($clients as $c) { echo '<option value="' . (int) $c['client_id'] . '">' . $h($c['client_name']) . '</option>'; } ?>
                </select></div>
            <div class="col-lg-2"><label class="form-label" for="et_loc">Location id</label><input type="number" class="form-control" id="et_loc" name="location_id" min="0" value="0"></div>
            <div class="col-lg-2"><label class="form-label" for="et_ring">Update ring</label><select class="form-select" id="et_ring" name="ring"><option value="stable">stable</option><option value="pilot">pilot</option></select></div>
            <div class="col-lg-1"><label class="form-label" for="et_ttl">Hours</label><input type="number" class="form-control" id="et_ttl" name="ttl_hours" min="1" max="<?= (int) $cfg['enroll_max_ttl_h'] ?>" value="24"></div>
            <div class="col-lg-1"><label class="form-label" for="et_uses">Uses</label><input type="number" class="form-control" id="et_uses" name="max_uses" min="1" max="5000" value="1"></div>
            <div class="col-lg-2"><label class="form-label" for="et_label">Label</label><input type="text" class="form-control" id="et_label" name="label" maxlength="100"></div>
            <div class="col-lg-1"><button type="submit" name="create_enroll_token" class="btn btn-primary w-100">Create</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
            <thead><tr><th>Token</th><th>Label</th><th>Department</th><th>Ring</th><th>Uses</th><th>Expires (UTC)</th><th>State</th><th></th></tr></thead><tbody>
            <?php foreach ($tokens as $t) {
                $state = $t['revoked_at'] !== null ? ['Revoked', 'danger'] : (strtotime($t['expires_at'] . ' UTC') <= time() ? ['Expired', 'secondary'] : ((int) $t['use_count'] >= (int) $t['max_uses'] ? ['Used up', 'secondary'] : ['Active', 'success'])); ?>
                <tr><td><code><?= $h(substr($t['token_selector'], 0, 8)) ?>...</code></td><td><?= $h($t['label']) ?></td><td><?= $h($clientName[(int) $t['client_id']] ?? ('#' . (int) $t['client_id'])) ?></td>
                    <td><?= $h($t['ring']) ?></td><td><?= (int) $t['use_count'] ?> / <?= (int) $t['max_uses'] ?><?= $t['last_used_at'] ? '<div class="small text-muted">last ' . $h($t['last_used_at']) . '</div>' : '' ?></td>
                    <td><?= $h($t['expires_at']) ?></td><td><span class="badge bg-<?= $state[1] ?>"><?= $state[0] ?></span></td>
                    <td><?php if ($state[0] === 'Active') { ?><form action="post.php" method="post" data-ea-confirm="Revoke this enrollment token?"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="token_id" value="<?= (int) $t['token_id'] ?>"><button class="btn btn-xs btn-outline-danger" name="revoke_enroll_token">Revoke</button></form><?php } ?></td></tr>
            <?php } if (!$tokens) { echo '<tr><td colspan="8" class="text-muted">No enrollment tokens yet.</td></tr>'; } ?>
        </tbody></table></div>
        <?php if ($attempts) { ?>
        <h6 class="mt-3">Recent rejected enrollment attempts</h6>
        <ul class="small mb-0"><?php foreach ($attempts as $a) { echo '<li>' . $h($a['attempted_at']) . ' UTC, ' . $h($a['ip_text']) . ': ' . $h($a['reason']) . ($a['token_selector'] !== '' ? ' (token ' . $h(substr($a['token_selector'], 0, 8)) . '...)' : '') . '</li>'; } ?></ul>
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="pending">
    <div class="card-header"><h4 class="card-title mb-0">Waiting for approval <span class="badge bg-<?= $pending ? 'warning text-dark' : 'secondary' ?>"><?= count($pending) ?></span></h4></div>
    <div class="card-body">
        <?php if (!$pending) { echo '<p class="text-muted mb-0">No device is waiting. Devices that match no asset, match several, or match only by hostname appear here and are never linked or merged automatically.</p>'; } ?>
        <?php foreach ($pending as $d) {
            $cands = $d['match_candidates_json'] ? (json_decode((string) $d['match_candidates_json'], true) ?: []) : [];
            $reasons = ['no_match' => 'No asset has this serial number or MAC address.', 'hostname_only' => 'Only the hostname matches an asset. A hostname is not proof of identity.',
                'ambiguous' => 'Several assets match, or the serial number and MAC address point at different assets.', 'scope_mismatch' => 'The matching asset belongs to a different department than the enrollment token.',
                'asset_already_linked' => 'The matching asset already belongs to another live device (duplicate enrollment).', 'asset_retired' => 'The asset this device was linked to was retired or removed.']; ?>
        <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between flex-wrap">
                <div><strong><?= $h($d['hostname']) ?></strong> <span class="text-muted small">device #<?= (int) $d['device_id'] ?>, serial <?= $h($d['serial'] ?: 'none reported') ?>, <?= $h($d['manufacturer']) ?> <?= $h($d['model']) ?>, <?= $h($clientName[(int) $d['client_id']] ?? '') ?></span></div>
                <span class="small text-muted">first seen <?= $h($d['first_seen_at']) ?> UTC</span>
            </div>
            <p class="small mb-2 mt-1"><i class="fas fa-info-circle me-1"></i><?= $h($reasons[$d['match_reason']] ?? $d['match_reason']) ?></p>
            <form action="post.php" method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="device_id" value="<?= (int) $d['device_id'] ?>">
                <div class="col-lg-6"><label class="form-label small mb-0" for="pa_<?= (int) $d['device_id'] ?>">Link to asset</label>
                    <select class="form-select form-select-sm" id="pa_<?= (int) $d['device_id'] ?>" name="asset_id"><option value="0">Choose an asset...</option>
                        <?php foreach ($cands as $c) { echo '<option value="' . (int) $c['asset_id'] . '">' . $h($c['asset_name']) . ' (#' . (int) $c['asset_id'] . ', matched by ' . $h(implode('+', $c['matched_by'])) . (!$c['in_scope'] ? ', other department' : '') . ($c['owned_by_device_id'] ? ', already owned' : '') . ')</option>'; } ?>
                    </select></div>
                <div class="col-lg-6 d-flex flex-wrap gap-2">
                    <button class="btn btn-sm btn-success" name="device_action" value="approve_link">Link to the chosen asset</button>
                    <button class="btn btn-sm btn-outline-primary" name="device_action" value="approve_create">Create a new asset</button>
                    <button class="btn btn-sm btn-outline-danger" name="device_action" value="reject" data-ea-confirm="Reject this device and revoke its credential?">Reject</button>
                </div>
            </form>
        </div>
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="devices">
    <div class="card-header"><h4 class="card-title mb-0">Devices</h4></div>
    <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0">
        <thead><tr><th>Device</th><th>Status</th><th>Last check-in (UTC)</th><th>Agent</th><th>Asset</th><th>Ring</th><th></th></tr></thead><tbody>
        <?php foreach ($devices as $d) {
            $st = Devices::status($d, $cfg);
            $gone = $d['revoked_at'] !== null || $d['retired_at'] !== null; ?>
            <tr>
                <td><a href="/agent/rmm_agent_device.php?device_id=<?= (int) $d['device_id'] ?>"><?= $h($d['hostname']) ?></a><div class="small text-muted">#<?= (int) $d['device_id'] ?>, <?= $h($clientName[(int) $d['client_id']] ?? '') ?></div></td>
                <td><?php if ($d['retired_at'] !== null) { echo '<span class="badge bg-secondary">Retired</span>'; } elseif ($d['revoked_at'] !== null) { echo '<span class="badge bg-danger">Revoked</span>'; } else { ?><span class="badge bg-<?= $badge[$st['state']] ?>"><?= ucfirst($st['state']) ?></span><?php if ($d['link_state'] === 'pending_approval') { echo ' <span class="badge bg-warning text-dark">Pending</span>'; } } ?>
                    <?php if ($st['offline_since']) { echo '<div class="small text-muted">since ' . $h(str_replace('T', ' ', rtrim($st['offline_since'], 'Z'))) . '</div>'; } ?></td>
                <td><?= $d['last_checkin_at'] ? $h($d['last_checkin_at']) : '<span class="text-muted">never</span>' ?></td>
                <td><?= $h($d['agent_version']) ?><?php $us = $d['update_state_json'] ? json_decode((string) $d['update_state_json'], true) : null; if (!empty($us['failed_versions'])) { echo '<div class="small text-danger">update failed: ' . $h(implode(', ', $us['failed_versions'])) . '</div>'; } ?></td>
                <td><?= $d['asset_id'] ? '<a href="/agent/asset_details.php?asset_id=' . (int) $d['asset_id'] . '">' . $h($d['asset_name'] ?? ('#' . $d['asset_id'])) . '</a>' : '<span class="text-muted">none</span>' ?>
                    <?php if ($d['asset_name'] !== null && strcasecmp($d['asset_name'], $d['hostname']) !== 0) { echo '<div class="small text-muted">hostname differs from asset name</div>'; } ?></td>
                <td><?= $h($d['ring']) ?></td>
                <td class="text-nowrap">
                    <form action="post.php" method="post" class="d-inline-flex gap-1 flex-wrap">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="device_id" value="<?= (int) $d['device_id'] ?>">
                        <?php if (!$gone) { ?>
                        <button class="btn btn-xs btn-outline-secondary" name="device_action" value="rotate" data-ea-confirm="Invalidate this device credential? The agent must re-enroll.">Rotate</button>
                        <button class="btn btn-xs btn-outline-danger" name="device_action" value="revoke" data-ea-confirm="Revoke this device? It stops checking in and fetching jobs immediately.">Revoke</button>
                        <button class="btn btn-xs btn-outline-dark" name="device_action" value="retire" data-ea-confirm="Retire this device? Queued jobs are cancelled and monitoring stops. The asset is kept.">Retire</button>
                        <?php } else { ?>
                        <button class="btn btn-xs btn-outline-primary" name="device_action" value="allow_reenroll">Allow re-enroll</button>
                        <?php } ?>
                    </form>
                </td>
            </tr>
        <?php } if (!$devices) { echo '<tr><td colspan="7" class="text-muted">No device has enrolled yet.</td></tr>'; } ?>
    </tbody></table></div>
</div>

<div class="card mb-3" id="releases">
    <div class="card-header"><h4 class="card-title mb-0">Agent updates and rings</h4></div>
    <div class="card-body">
        <p class="small text-muted">Each release is signed with the instance key. A device only receives a release newer than the one it runs, only when it meets the release's minimum version, and only when it falls inside the rollout percentage for its ring (pilot devices also get stable releases). A version that failed on a device is not offered to it again.</p>
        <form action="post.php" method="post" class="row g-2 align-items-end mb-3" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-lg-1"><label class="form-label small" for="rl_v">Version</label><input class="form-control form-control-sm" id="rl_v" name="version" placeholder="1.1.0" required></div>
            <div class="col-lg-3"><label class="form-label small" for="rl_u">Package URL (https)</label><input class="form-control form-control-sm" id="rl_u" name="url" type="url" required></div>
            <div class="col-lg-3"><label class="form-label small" for="rl_s">SHA-256 of the package</label><input class="form-control form-control-sm font-monospace" id="rl_s" name="sha256" maxlength="64" required></div>
            <div class="col-lg-1"><label class="form-label small" for="rl_m">Min version</label><input class="form-control form-control-sm" id="rl_m" name="min_version" value="0.0.0"></div>
            <div class="col-lg-1"><label class="form-label small" for="rl_r">Ring</label><select class="form-select form-select-sm" id="rl_r" name="ring"><option>pilot</option><option selected>stable</option></select></div>
            <div class="col-lg-1"><label class="form-label small" for="rl_p">Rollout %</label><input class="form-control form-control-sm" id="rl_p" name="rollout_pct" type="number" min="0" max="100" value="10"></div>
            <div class="col-lg-2"><button class="btn btn-sm btn-primary w-100" name="add_agent_release">Publish release</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Version</th><th>Ring</th><th>Min version</th><th>Rollout</th><th>Active</th><th>Package SHA-256</th><th></th></tr></thead><tbody>
        <?php foreach ($releases as $r) { $fid = 'rel_' . (int) $r['release_id']; ?>
            <tr>
                <td><?= $h($r['version']) ?></td><td><?= $h($r['ring']) ?></td><td><?= $h($r['min_version']) ?></td>
                <td style="max-width:7rem"><input form="<?= $fid ?>" class="form-control form-control-sm" type="number" name="rollout_pct" min="0" max="100" value="<?= (int) $r['rollout_pct'] ?>" aria-label="Rollout percent"></td>
                <td><input form="<?= $fid ?>" type="checkbox" class="form-check-input" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?> aria-label="Active"></td>
                <td class="small font-monospace text-break"><?= $h($r['sha256']) ?></td>
                <td><form id="<?= $fid ?>" action="post.php" method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="release_id" value="<?= (int) $r['release_id'] ?>"><button class="btn btn-xs btn-outline-primary" name="update_agent_release">Save</button></form></td></tr>
        <?php } if (!$releases) { echo '<tr><td colspan="7" class="text-muted">No release published. Agents stay on the version they have.</td></tr>'; } ?>
        </tbody></table></div>
    </div>
</div>

<div class="card mb-3" id="mesh">
    <div class="card-header"><h4 class="card-title mb-0">MeshCentral remote access</h4></div>
    <div class="card-body">
        <p class="small text-muted">Technicians with the "RMM remote connect" permission can open a session from the device or asset page. RivetIT signs a one-click MeshCentral login token for ONE limited MeshCentral account (no shared administrator credential) at the moment of the click; nothing is stored or logged. The login token key is the value printed by <code>node node_modules/meshcentral --loginTokenKey</code> on the MeshCentral server.</p>
        <form action="post.php" method="post" autocomplete="off" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-12"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" id="mc_on" name="mesh_enabled" value="1" <?= $cfg['mesh_enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="mc_on">Enable remote sessions through MeshCentral</label></div></div>
            <div class="col-lg-5"><label class="form-label" for="mc_url">MeshCentral address</label><input type="url" class="form-control" id="mc_url" name="mesh_url" maxlength="500" value="<?= $h($cfg['mesh_url']) ?>" placeholder="https://mesh.example.com"></div>
            <div class="col-lg-2"><label class="form-label" for="mc_dom">Domain</label><input class="form-control" id="mc_dom" name="mesh_domain" maxlength="100" value="<?= $h($cfg['mesh_domain']) ?>" placeholder="(default)"></div>
            <div class="col-lg-3"><label class="form-label" for="mc_acc">MeshCentral account</label><input class="form-control" id="mc_acc" name="mesh_account_template" maxlength="100" value="<?= $h($cfg['mesh_account_template']) ?>"><div class="form-text">Optional placeholders: {username}, {user_id} for one account per technician.</div></div>
            <div class="col-lg-2"><label class="form-label" for="mc_pol">Session policy</label><select class="form-select" id="mc_pol" name="mesh_policy"><?php foreach (['unattended' => 'Unattended', 'attended' => 'Attended (user consent)', 'both' => 'Both'] as $k => $l) { echo '<option value="' . $k . '"' . ($cfg['mesh_policy'] === $k ? ' selected' : '') . '>' . $h($l) . '</option>'; } ?></select></div>
            <div class="col-lg-6"><label class="form-label" for="mc_key">Login token key <?= $cfg['mesh_login_key_enc'] ? '<span class="badge bg-success">stored encrypted</span>' : '' ?></label><input type="password" class="form-control font-monospace" id="mc_key" name="mesh_login_key" autocomplete="new-password" placeholder="<?= $cfg['mesh_login_key_enc'] ? 'Leave empty to keep the stored key' : 'Paste the hex key' ?>"><div class="form-text">Write-only. The consent prompt for attended access is enforced by the MeshCentral device group settings.</div></div>
            <div class="col-lg-3"><label class="form-label" for="mc_ttl">Token lifetime (s)</label><input type="number" class="form-control" id="mc_ttl" name="mesh_token_ttl_s" min="60" max="3600" value="<?= (int) $cfg['mesh_token_ttl_s'] ?>"><div class="form-text">Informational: MeshCentral applies its own login token timeout.</div></div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary" name="save_mesh_settings">Save</button><button class="btn btn-outline-secondary" name="test_mesh">Save and test connection</button></div>
        </form>
    </div>
</div>

<script nonce="<?= $h($csp_nonce ?? '') ?>">
document.querySelectorAll('form[data-ea-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.getAttribute('data-ea-confirm'))) { e.preventDefault(); } });
});
document.querySelectorAll('button[data-ea-confirm]').forEach(function (b) {
    b.addEventListener('click', function (e) { if (!confirm(b.getAttribute('data-ea-confirm'))) { e.preventDefault(); } });
});
document.querySelectorAll('input[data-ea-select]').forEach(function (i) { i.addEventListener('focus', function () { i.select(); }); });
</script>
<?php require_once "../includes/footer.php"; ?>
