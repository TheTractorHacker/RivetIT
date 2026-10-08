<?php
require_once "includes/inc_all_admin.php";

require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';

use RivetCore\Rmm\Binaries\BinaryStore;

mysqli_report(MYSQLI_REPORT_OFF);
$csrf = $_SESSION['csrf_token'];
$h = static fn($v) => nullable_htmlentities((string) $v);
$dt = static fn($iso) => $iso ? str_replace('T', ' ', rtrim((string) $iso, 'Z')) : '';   // the read models return RFC 3339 UTC
$newToken = $_SESSION['ea_new_token'] ?? null;
unset($_SESSION['ea_new_token']);

// All data comes from RivetCore\Rmm (rivet/rivet-core) read models; this page only renders it. The page stays reachable while the module is
// off (the switch is on it), and the module is built lazily, so a database that has not been updated yet gets the notice below.
$tableReady = false;
$res = mysqli_query($mysqli, "SHOW TABLES LIKE 'endpoint_agent_binaries'");
if ($res && mysqli_num_rows($res) > 0) {
    $res = mysqli_query($mysqli, "SHOW COLUMNS FROM endpoint_agent_settings LIKE 'features_json'");
    $tableReady = $res && mysqli_num_rows($res) > 0;
}
if (!$tableReady) {
    echo '<div class="alert alert-warning">Run the database update first (Administration &rarr; Updates), then reload this page.</div>';
    require_once "../includes/footer.php";
    return;
}
$rmm = rivetRmmModule();
$read = $rmm->readModel();
rivetRmmSyncState();   // the page is where the switch lives: make sure the zero-database state file agrees with the database
$cfg = $read->settingsSummary();
$moduleOn = $rmm->enabled();
// The gate (api/v1/rmm_gate.php) trusts the state file. If it cannot be written (a directory created by another user, a read-only backups/), a stale
// "off" would keep turning agents away after the switch is on: say so here instead of letting it go unnoticed.
$stateDir = rivetRmmStateDir();
$stateFile = $stateDir === null ? null : \RivetCore\Rmm\RmmStateFile::read($stateDir);
$stateProblem = $stateDir !== null && ($stateFile === null || $stateFile['master'] !== (bool) $cfg['enabled']);

$clients = [];
$res = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name');
while ($res && ($c = mysqli_fetch_assoc($res))) { $clients[] = $c; }
$clientName = [];
foreach ($clients as $c) { $clientName[(int) $c['client_id']] = $c['client_name']; }
$devices = $read->listDevices(['retired' => 'all'], null, 500, 0)['items'];
$pending = $read->pendingApprovals(null, 200);
$tokens = $read->tokens(50);
$attempts = $read->recentFailedAttempts(15);
$binaries = $read->binaries(40);
$curBin = $read->currentBinaries();
$uploadLimit = $read->uploadLimit();
$releases = $read->releases(30);
$counts = $read->fleetCounts();
$checksText = json_encode($cfg['checks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

<?php if ($stateProblem) { ?>
<div class="alert alert-warning"><strong>The module state file is missing or does not match the settings.</strong> The agent gate reads <code><?= $h($stateDir) ?>/rmm_state.json</code>. Make the directory writable by the web server user (for example <code>sudo -u www-data mkdir -p <?= $h($stateDir) ?></code>, or <code>chown -R www-data</code> it) and save the switch again; until then a stale file can keep answering agents with "disabled".</div>
<?php } ?>
<div class="card mb-3" id="module">
    <div class="card-header py-3 d-flex align-items-center justify-content-between">
        <h4 class="card-title mb-0"><i class="fas fa-fw fa-power-off me-2"></i>RMM module</h4>
        <span class="badge bg-<?= $moduleOn ? 'success' : 'secondary' ?> fs-6"><?= $moduleOn ? 'On' : 'Off' ?></span>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">The master switch for the whole endpoint agent / RMM module. While it is off the menus and device pages are hidden, the agent service answers <code>503 module_disabled</code> without touching the database (enrolled agents keep their data and back off to one attempt an hour), and housekeeping and queued work wait. Nothing is deleted: switching it on again resumes everything.</p>
        <form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-end" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <?php if (!$moduleOn) { ?>
            <div><label class="form-label small mb-0" for="rm_preset">Features when switching on</label>
                <select class="form-select form-select-sm" id="rm_preset" name="feature_preset"><option value="">Keep the current set (monitoring, metrics, jobs, updates; remote follows MeshCentral)</option><option value="light">Light: monitoring and updates</option><option value="standard">Standard: monitoring, metrics, jobs, remote, updates</option></select></div>
            <button type="submit" name="rmm_module_switch" value="on" class="btn btn-success"><i class="fas fa-power-off me-1"></i>Switch the RMM module on</button>
            <?php } else { ?>
            <button type="submit" name="rmm_module_switch" value="off" class="btn btn-outline-danger" data-ea-confirm="Switch the RMM module off? Enrolled agents back off until it is on again; nothing is deleted.">Switch the RMM module off</button>
            <?php } ?>
        </form>
    </div>
</div>

<?php if ($newToken) { $dep = $newToken['deploy'] ?? null; ?>
<div class="alert alert-success" id="new-token">
    <strong>New enrollment token.</strong> It is shown once and only its hash is stored. Anyone holding it can enroll a device into this department until it expires or is revoked, so treat it as a secret.
    <div class="input-group mt-2"><input type="text" class="form-control font-monospace" readonly data-ea-select value="<?= $h($newToken['token']) ?>" aria-label="Enrollment token"><button type="button" class="btn btn-outline-secondary" data-ea-copy-value="<?= $h($newToken['token']) ?>">Copy</button></div>
    <?php if (!$dep) { ?><div class="form-text">Use it with the agent: <code>set RIVETIT_ENROLL_TOKEN=...</code> then <code>rivetit-agent.exe install --server <?= $h($cfg['service_url'] ?: $serviceUrlHint) ?></code>, or create a per-department installer under Deployment, which embeds a token for you.</div><?php } ?>
</div>
<?php if ($dep) {
    $depFile = $dep['commands']['filename'] ?? $rmm->installerDownload()->filename($dep['department'], $dep['arch']);
    $snippet = (string) ($dep['commands']['powershell'] ?? '');
    ?>
<div class="card mb-3" id="deploy-commands">
    <div class="card-header"><h4 class="card-title mb-0">Deployment commands for <?= $h($dep['department']) ?> (<?= $dep['arch'] === 'arm64' ? 'ARM64' : 'Windows x64' ?>)</h4></div>
    <div class="card-body">
        <p class="small text-muted">This token allows <?= (int) $dep['max_uses'] ?> enrollments and expires <?= $h($dep['expires_at']) ?> UTC. The commands below contain it: keep them out of tickets, chat and shared script shares.</p>
        <h6>1. Interactive (one PC)</h6>
        <p class="small mb-3">Use <strong>Download installer</strong> in the Deployment card for this department, copy <code><?= $h($depFile) ?></code> to the PC and double-click it (accept the administrator prompt), or run <code><?= $h($depFile) ?> setup</code> from an elevated prompt. The file already contains the server address, the token and the department.</p>
        <h6>2. Silent install for an RMM, Intune platform script or GPO startup script</h6>
        <?php if ($snippet === '') { ?><p class="text-danger small">Set an https:// service URL first.</p><?php } else { ?>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_snippet">Copy script</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_snippet" rows="16" readonly spellcheck="false"><?= $h($snippet) ?></textarea>
        <?php } ?>
        <h6>3. Intune Win32 app and Group Policy</h6>
        <ul class="small mb-3">
            <li><strong>Intune Win32 app:</strong> wrap <code><?= $h($depFile) ?></code> with the Win32 Content Prep Tool (<code>IntuneWinAppUtil.exe -c folder -s <?= $h($depFile) ?> -o out</code>). Install command: <code><?= $h($depFile) ?> setup --silent</code>. Uninstall command: <code>"%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe" uninstall</code>. Install behavior: System. The embedded token expires, so create the installer with a lifetime and use count that cover the rollout.</li>
            <li><strong>Detection rule (either one):</strong> service <code>RivetITAgent</code> exists, or file <code>%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe</code> exists. Custom script: <code>if ((Get-Service RivetITAgent -ErrorAction SilentlyContinue) -and (Test-Path "$env:ProgramFiles\RivetIT\Agent\rivetit-agent.exe")) { 'installed'; exit 0 } else { exit 1 }</code></li>
            <li><strong>Group Policy:</strong> Computer Configuration, Policies, Windows Settings, Scripts, Startup, PowerShell Scripts: add the script from step 2. Startup scripts run as SYSTEM before logon. SYSVOL is readable by every domain user, so use a token with a small use count, or prefer Intune or your RMM, which keep the script private.</li>
            <li>The setup is idempotent: a machine that already has the agent keeps its identity. Windows PCs that do not trust your server certificate need the issuing CA in their store (or set the CA certificate in the service settings, which the installer embeds for the agent).</li>
        </ul>
        <h6>4. New agent versions</h6>
        <p class="small mb-0">The Windows agent is built and released from the RivetCore repository (<code>endpoint-agent/</code>, release tags <code>agent-v*</code>, see <code>docs/rmm/AGENT_BUILD.md</code> there). Download the release executables and upload <code>rivetit-agent-windows-amd64.exe</code> and <code>...-arm64.exe</code> under Agent binaries, or run <code>sudo -u www-data php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.exe --version 1.2.0 --arch amd64 --activate [--release pilot --rollout 10]</code>. Installers created afterwards use the current binary; enrolled agents update through the release ring.</p>
    </div>
</div>
<?php } } ?>

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
                    <label class="form-label" for="ea_ca">CA certificate (PEM, optional)</label>
                    <textarea class="form-control font-monospace" id="ea_ca" name="ca_pem" rows="4" maxlength="8000" placeholder="-----BEGIN CERTIFICATE-----"><?= $h($cfg['ca_pem'] ?? '') ?></textarea>
                    <div class="form-text">Only for servers whose certificate is issued by a private CA. Per-department installers embed it so the agent trusts this server. Leave empty when the certificate comes from a public CA. Public information, not a secret.</div>
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

<div class="card mb-3" id="binaries">
    <div class="card-header"><h4 class="card-title mb-0">Agent binaries</h4></div>
    <div class="card-body">
        <p class="small text-muted">Upload the Windows agent executable (unstamped, as built by <code>make build</code>) for each architecture. The server checks the PE header, the machine type and that the file is not already an installer, and stores it outside the web-served area. The <strong>current</strong> binary per architecture is what per-department installers are made from; offering a binary as an update lets enrolled agents fetch it from this server. Largest accepted upload: <strong><?= $h(BinaryStore::human($uploadLimit)) ?></strong> (the lower of the <?= $h(BinaryStore::human($rmm->binaryStore()->maxBytes())) ?> cap, PHP <code>upload_max_filesize</code> <?= $h(ini_get('upload_max_filesize')) ?> and <code>post_max_size</code> <?= $h(ini_get('post_max_size')) ?>). Larger or automated uploads: <code>scripts/endpoint_agent_publish.php</code>.</p>
        <form action="post.php" method="post" enctype="multipart/form-data" class="border rounded p-3 mb-3" id="bn_drop_form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <h6 class="mb-1"><i class="fas fa-cloud-upload-alt me-1" aria-hidden="true"></i>Publish the agent</h6>
            <p class="small text-muted mb-2">Drop the agent .exe here (both the x64 and the ARM64 file at once if you have them). The architecture is read from the file itself, the version from the file name, and the files become the ones new installers are built from.</p>
            <label class="border border-2 rounded p-3 text-center w-100 mb-2" id="bn_drop" for="bn_files" style="border-style:dashed !important;cursor:pointer">
                <span class="d-block"><i class="fas fa-file-upload me-1" aria-hidden="true"></i>Drag the .exe file(s) here, or <u>choose files</u></span>
                <span class="d-block small text-muted" id="bn_drop_list" aria-live="polite">Nothing chosen yet.</span>
            </label>
            <input type="file" class="visually-hidden" id="bn_files" name="agent_binaries[]" accept=".exe" multiple required>
            <div class="row g-2 align-items-end">
                <div class="col-sm-3"><label class="form-label small" for="bn_dv">Version <span class="text-muted">(blank: from the file name)</span></label><input class="form-control form-control-sm" id="bn_dv" name="version" placeholder="1.2.0" maxlength="40"></div>
                <div class="col-sm-4"><div class="form-check"><input type="checkbox" class="form-check-input" id="bn_dcur" name="activate" value="1" checked><label class="form-check-label small" for="bn_dcur">Use for new installers (make current)</label></div></div>
                <div class="col-sm-3"><label class="form-label small" for="bn_dring">Also offer as an update</label><select class="form-select form-select-sm" id="bn_dring" name="release_ring"><option value="">No</option><option value="pilot">Pilot ring</option><option value="stable">Stable ring</option></select></div>
                <div class="col-sm-2"><button class="btn btn-sm btn-primary w-100" name="upload_agent_binaries" value="1"><i class="fas fa-upload me-1" aria-hidden="true"></i>Publish</button></div>
            </div>
        </form>
        <details class="mb-3"><summary class="small">Advanced: upload one file with an explicit architecture and rollout</summary>
        <form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mt-1" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-lg-3"><label class="form-label small" for="bn_file">Agent executable (.exe)</label><input type="file" class="form-control form-control-sm" id="bn_file" name="agent_binary" accept=".exe" required></div>
            <div class="col-lg-1"><label class="form-label small" for="bn_v">Version</label><input class="form-control form-control-sm" id="bn_v" name="version" placeholder="1.2.0" maxlength="40" required></div>
            <div class="col-lg-2"><label class="form-label small" for="bn_a">Architecture</label><select class="form-select form-select-sm" id="bn_a" name="arch"><option value="amd64">Windows x64 (amd64)</option><option value="arm64">Windows ARM64</option></select></div>
            <div class="col-lg-2"><div class="form-check"><input type="checkbox" class="form-check-input" id="bn_cur" name="activate" value="1" checked><label class="form-check-label small" for="bn_cur">Make current for installers</label></div></div>
            <div class="col-lg-2"><label class="form-label small" for="bn_ring">Offer as update</label><select class="form-select form-select-sm" id="bn_ring" name="release_ring"><option value="">No</option><option value="pilot">Pilot ring</option><option value="stable">Stable ring</option></select></div>
            <div class="col-lg-1"><label class="form-label small" for="bn_pct">Rollout %</label><input class="form-control form-control-sm" id="bn_pct" name="rollout_pct" type="number" min="0" max="100" value="10"></div>
            <div class="col-lg-1"><button class="btn btn-sm btn-primary w-100" name="upload_agent_binary">Upload</button></div>
        </form></details>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Version</th><th>Arch</th><th>Size</th><th>SHA-256</th><th>Uploaded (UTC)</th><th>State</th><th></th></tr></thead><tbody>
            <?php foreach ($binaries as $b) { $fid = 'bn_' . (int) $b['binary_id']; ?>
                <tr>
                    <td><?= $h($b['version']) ?></td><td><?= $h($b['arch']) ?></td><td><?= $h(BinaryStore::human((int) $b['size_bytes'])) ?></td>
                    <td class="small font-monospace text-break"><?= $h($b['sha256']) ?></td><td><?= $h($dt($b['created_at'])) ?></td>
                    <td><?php if (!(int) $b['active']) { echo '<span class="badge bg-secondary">Inactive</span>'; } elseif ((int) $b['is_current']) { echo '<span class="badge bg-success">Current</span>'; } else { echo '<span class="badge bg-info text-dark">Available</span>'; } ?></td>
                    <td class="text-nowrap">
                        <form id="<?= $fid ?>" action="post.php" method="post" class="d-inline-flex gap-1 flex-wrap align-items-center"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="binary_id" value="<?= (int) $b['binary_id'] ?>">
                        <?php if ((int) $b['active']) { ?>
                            <?php if (!(int) $b['is_current']) { ?><button class="btn btn-xs btn-outline-primary" name="binary_action" value="make_current">Make current</button><?php } ?>
                            <select name="release_ring" class="form-select form-select-sm w-auto" aria-label="Ring"><option>pilot</option><option>stable</option></select>
                            <input type="number" name="rollout_pct" min="0" max="100" value="10" class="form-control form-control-sm" style="width:5rem" aria-label="Rollout percent">
                            <button class="btn btn-xs btn-outline-secondary" name="binary_action" value="offer_update">Offer as update</button>
                            <button class="btn btn-xs btn-outline-danger" name="binary_action" value="deactivate" data-ea-confirm="Deactivate this binary? It stops being used for installers and updates. The file is kept.">Deactivate</button>
                        <?php } else { ?><button class="btn btn-xs btn-outline-primary" name="binary_action" value="activate">Reactivate</button><?php } ?>
                        </form>
                    </td>
                </tr>
            <?php } if (!$binaries) { echo '<tr><td colspan="7" class="text-muted">No agent binary uploaded yet. Installers cannot be created until one is current.</td></tr>'; } ?>
        </tbody></table></div>
    </div>
</div>

<div class="card mb-3" id="deployment">
    <div class="card-header"><h4 class="card-title mb-0">Deployment: per-department installer</h4></div>
    <div class="card-body">
        <p class="small mb-2"><i class="fas fa-lightbulb me-1 text-warning" aria-hidden="true"></i>Quicker: <a href="/agent/rmm_fleet.php?installer=1">Endpoints &gt; Agent Fleet &gt; Add device</a> does the same in one dialog, for any department you can see.</p>
        <p class="small text-muted">Creates an enrollment token for the department and gives you an installer that already contains the server address, the token, the department and (if set) your CA certificate. Run it on a Windows PC as administrator and the agent installs and enrolls itself. Each click creates a new audited token.</p>
        <?php
        $dep_problems = [];
        if (!$cfg['enabled']) { $dep_problems[] = 'The endpoint agent service is switched off.'; }
        if ($cfg['service_base'] === null) { $dep_problems[] = 'The service URL is not an https:// address.'; }
        if (!$curBin['amd64'] && !$curBin['arm64']) { $dep_problems[] = 'No current agent binary is uploaded.'; }
        foreach ($dep_problems as $pr) { echo '<div class="alert alert-warning py-2 mb-2">' . $h($pr) . '</div>'; } ?>
        <form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-lg-3"><label class="form-label" for="dp_client">Department</label>
                <select class="form-select" id="dp_client" name="client_id" required><option value="">Choose...</option>
                    <?php foreach ($clients as $c) { echo '<option value="' . (int) $c['client_id'] . '">' . $h($c['client_name']) . '</option>'; } ?>
                </select></div>
            <div class="col-lg-2"><label class="form-label" for="dp_arch">Architecture</label><select class="form-select" id="dp_arch" name="arch"><option value="amd64">Windows x64</option><option value="arm64">Windows ARM64</option></select></div>
            <div class="col-lg-1"><label class="form-label" for="dp_loc">Location</label><input type="number" class="form-control" id="dp_loc" name="location_id" min="0" value="0" title="Location id, 0 for none"></div>
            <div class="col-lg-2"><label class="form-label" for="dp_ring">Update ring</label><select class="form-select" id="dp_ring" name="ring"><option value="stable">stable</option><option value="pilot">pilot</option></select></div>
            <div class="col-lg-1"><label class="form-label" for="dp_ttl">Hours</label><input type="number" class="form-control" id="dp_ttl" name="ttl_hours" min="1" max="<?= (int) $cfg['enroll_max_ttl_h'] ?>" value="<?= (int) min(72, $cfg['enroll_max_ttl_h']) ?>"></div>
            <div class="col-lg-1"><label class="form-label" for="dp_uses">Max uses</label><input type="number" class="form-control" id="dp_uses" name="max_uses" min="1" max="5000" value="25"></div>
            <div class="col-lg-2"><label class="form-label" for="dp_label">Label</label><input type="text" class="form-control" id="dp_label" name="label" maxlength="100"></div>
            <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                <button type="submit" name="download_installer" class="btn btn-primary"><i class="fas fa-download me-1"></i>Download installer</button>
                <button type="submit" name="show_deploy_commands" class="btn btn-outline-primary"><i class="fas fa-terminal me-1"></i>Show deployment commands</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-3" id="signing">
    <div class="card-header"><h4 class="card-title mb-0">Signing key</h4></div>
    <div class="card-body">
        <?php if ($cfg['signing_public_key'] === '') { ?>
            <p class="text-muted mb-0">Generated automatically the first time the service is switched on.</p>
        <?php } else { ?>
            <p class="mb-1">Key id <code><?= $h($cfg['signing_key_id']) ?></code>, created <?= $h($dt($cfg['signing_key_created_at'])) ?> UTC. The private key is stored encrypted.</p>
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
                $state = ['revoked' => ['Revoked', 'danger'], 'expired' => ['Expired', 'secondary'], 'used_up' => ['Used up', 'secondary'], 'active' => ['Active', 'success']][$t['state']]; ?>
                <tr><td><code><?= $h(substr($t['selector'], 0, 8)) ?>...</code></td><td><?= $h($t['label']) ?></td><td><?= $h($clientName[(int) $t['client_id']] ?? ('#' . (int) $t['client_id'])) ?></td>
                    <td><?= $h($t['ring']) ?></td><td><?= (int) $t['use_count'] ?> / <?= (int) $t['max_uses'] ?><?= $t['last_used_at'] ? '<div class="small text-muted">last ' . $h($dt($t['last_used_at'])) . '</div>' : '' ?></td>
                    <td><?= $h($dt($t['expires_at'])) ?></td><td><span class="badge bg-<?= $state[1] ?>"><?= $state[0] ?></span></td>
                    <td><?php if ($state[0] === 'Active') { ?><form action="post.php" method="post" data-ea-confirm="Revoke this enrollment token?"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="token_id" value="<?= (int) $t['token_id'] ?>"><button class="btn btn-xs btn-outline-danger" name="revoke_enroll_token">Revoke</button></form><?php } ?></td></tr>
            <?php } if (!$tokens) { echo '<tr><td colspan="8" class="text-muted">No enrollment tokens yet.</td></tr>'; } ?>
        </tbody></table></div>
        <?php if ($attempts) { ?>
        <h6 class="mt-3">Recent rejected enrollment attempts</h6>
        <ul class="small mb-0"><?php foreach ($attempts as $a) { echo '<li>' . $h($dt($a['attempted_at'])) . ' UTC, ' . $h($a['ip']) . ': ' . $h($a['reason']) . ($a['token_selector'] !== '' ? ' (token ' . $h(substr($a['token_selector'], 0, 8)) . '...)' : '') . '</li>'; } ?></ul>
        <?php } ?>
    </div>
</div>

<div class="card mb-3" id="pending">
    <div class="card-header"><h4 class="card-title mb-0">Waiting for approval <span class="badge bg-<?= $pending ? 'warning text-dark' : 'secondary' ?>"><?= count($pending) ?></span></h4></div>
    <div class="card-body">
        <?php if (!$pending) { echo '<p class="text-muted mb-0">No device is waiting. Devices that match no asset, match several, or match only by hostname appear here and are never linked or merged automatically.</p>'; } ?>
        <?php foreach ($pending as $d) {
            $cands = $d['candidates']; ?>
        <div class="border rounded p-3 mb-3">
            <div class="d-flex justify-content-between flex-wrap">
                <div><strong><?= $h($d['hostname']) ?></strong> <span class="text-muted small">device #<?= (int) $d['device_id'] ?>, serial <?= $h($d['serial'] ?: 'none reported') ?>, <?= $h($d['manufacturer']) ?> <?= $h($d['model']) ?>, <?= $h($clientName[(int) $d['client_id']] ?? '') ?></span></div>
                <span class="small text-muted">first seen <?= $h($dt($d['first_seen_at'])) ?> UTC</span>
            </div>
            <p class="small mb-2 mt-1"><i class="fas fa-info-circle me-1"></i><?= $h($d['match_reason_text']) ?></p>
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
            $gone = $d['revoked'] || $d['retired']; ?>
            <tr>
                <td><a href="/agent/rmm_agent_device.php?device_id=<?= (int) $d['device_id'] ?>"><?= $h($d['hostname']) ?></a><div class="small text-muted">#<?= (int) $d['device_id'] ?>, <?= $h($clientName[(int) $d['client_id']] ?? '') ?></div></td>
                <td><?php if ($d['retired']) { echo '<span class="badge bg-secondary">Retired</span>'; } elseif ($d['revoked']) { echo '<span class="badge bg-danger">Revoked</span>'; } else { ?><span class="badge bg-<?= $badge[$d['status']] ?>"><?= ucfirst($d['status']) ?></span><?php if ($d['link_state'] === 'pending_approval') { echo ' <span class="badge bg-warning text-dark">Pending</span>'; } } ?>
                    <?php if ($d['offline_since']) { echo '<div class="small text-muted">since ' . $h($dt($d['offline_since'])) . '</div>'; } ?></td>
                <td><?= $d['last_checkin_at'] ? $h($dt($d['last_checkin_at'])) : '<span class="text-muted">never</span>' ?></td>
                <td><?= $h($d['agent_version']) ?></td>
                <td><?= $d['asset_id'] ? '<a href="/agent/asset_details.php?asset_id=' . (int) $d['asset_id'] . '">' . $h($d['asset_name'] ?? ('#' . $d['asset_id'])) . '</a>' : '<span class="text-muted">none</span>' ?>
                    <?php if (($d['asset_name'] ?? null) !== null && strcasecmp($d['asset_name'], $d['hostname']) !== 0) { echo '<div class="small text-muted">hostname differs from asset name</div>'; } ?></td>
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
                <td><?= $h($r['version']) ?><?= $r['hosted'] ? '<div class="small text-muted">hosted, ' . $h($r['arch']) . '</div>' : '' ?></td><td><?= $h($r['ring']) ?></td><td><?= $h($r['min_version']) ?></td>
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
            <div class="col-lg-6"><label class="form-label" for="mc_key">Login token key <?= $cfg['mesh_login_key_set'] ? '<span class="badge bg-success">stored encrypted</span>' : '' ?></label><input type="password" class="form-control font-monospace" id="mc_key" name="mesh_login_key" autocomplete="new-password" placeholder="<?= $cfg['mesh_login_key_set'] ? 'Leave empty to keep the stored key' : 'Paste the hex key' ?>"><div class="form-text">Write-only. The consent prompt for attended access is enforced by the MeshCentral device group settings.</div></div>
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
document.querySelectorAll('[data-ea-copy-value],[data-ea-copy-target]').forEach(function (b) {
    b.addEventListener('click', function () {
        var t = b.getAttribute('data-ea-copy-target');
        var v = t ? document.getElementById(t).value : b.getAttribute('data-ea-copy-value');
        if (navigator.clipboard) { navigator.clipboard.writeText(v).then(function () { b.textContent = 'Copied'; }); }
        else if (t) { document.getElementById(t).select(); document.execCommand('copy'); b.textContent = 'Copied'; }
    });
});
(function () {   // drag and drop for "Publish the agent": the file list is shown, the architecture and version are worked out by the server
    var drop = document.getElementById('bn_drop'), input = document.getElementById('bn_files'), list = document.getElementById('bn_drop_list'), ver = document.getElementById('bn_dv');
    if (!drop || !input) { return; }
    function show() {
        var names = Array.prototype.map.call(input.files, function (f) { return f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)'; });
        list.textContent = names.length ? names.join(', ') : 'Nothing chosen yet.';
        var m = input.files.length ? /(\d{1,5}\.\d{1,5}\.\d{1,5}(?:-(?:rc|alpha|beta|pre|dev)[0-9A-Za-z.]{0,16})?)/i.exec(input.files[0].name) : null;
        ver.placeholder = m ? m[1] + ' (from the file name)' : '1.2.0';
    }
    ['dragenter', 'dragover'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.add('bg-body-secondary'); }); });
    ['dragleave', 'drop'].forEach(function (n) { drop.addEventListener(n, function (e) { e.preventDefault(); drop.classList.remove('bg-body-secondary'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
    input.addEventListener('change', show);
})();
document.querySelectorAll('input[data-ea-select]').forEach(function (i) { i.addEventListener('focus', function () { i.select(); }); });
</script>
<?php require_once "../includes/footer.php"; ?>
