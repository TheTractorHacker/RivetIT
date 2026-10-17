<?php
require_once "includes/inc_all_admin.php";

require_once dirname(__DIR__) . '/includes/rmm_bootstrap.php';
require_once dirname(__DIR__) . '/includes/rmm_mesh_cards.php';       // rc.12: the Mesh agent install settings (printed in the MeshCentral card) and the device's Mesh state
require_once dirname(__DIR__) . '/includes/rmm_installer.php';       // rivetRmmInstallerMesh(): is the optional MeshCentral agent tick-box offered
require_once dirname(__DIR__) . '/includes/rmm_scale_cards.php';   // RivetCore 1.0.0-rc.11 cards (remote tools, file transfer, logs, scale, partitioning, external store)

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
$msiTemplates = $read->msiTemplates(40);   // rc.16: [] when migration 0025 has not been applied
$msiCur = ['amd64' => null, 'arm64' => null];
foreach ($msiTemplates as $__t) { if ($__t['is_current'] && $__t['active'] && array_key_exists($__t['arch'], $msiCur) && $msiCur[$__t['arch']] === null) { $msiCur[$__t['arch']] = (string) $__t['version']; } }
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
    $depMsi = is_array($dep['commands']['msi'] ?? null) ? $dep['commands']['msi'] : null;   // rc.16: present only when the commands were made for format msi
    $depFile = $depMsi['filename'] ?? $dep['commands']['filename'] ?? $rmm->installerDownload()->filename($dep['department'], $dep['arch']);
    $snippet = (string) ($dep['commands']['powershell'] ?? '');
    ?>
<div class="card mb-3" id="deploy-commands">
    <div class="card-header"><h4 class="card-title mb-0">Deployment commands for <?= $h($dep['department']) ?> (<?= $dep['arch'] === 'arm64' ? 'ARM64' : 'Windows x64' ?><?= $depMsi ? ', MSI' : '' ?>)</h4></div>
    <div class="card-body">
        <p class="small text-muted">This token allows <?= (int) $dep['max_uses'] ?> enrollments and expires <?= $h($dep['expires_at']) ?> UTC. The commands below contain it: keep them out of tickets, chat and shared script shares.</p>
        <?php if ($depMsi) { ?>
        <div class="alert alert-warning small" role="note" id="deploy-msi-warning"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><strong>Not yet verified on Windows.</strong> The MSI installer is unsigned and has never been run on a real Windows PC: msiexec accepting a package with the payload appended, repair and upgrade behaviour and the custom actions are unverified. Follow the checklist <code>docs/rmm/MSI_VERIFY.md</code> (RivetCore) on one machine before a roll-out.</div>
        <h6>1. MSI: one PC, Intune, Group Policy or your RMM</h6>
        <p class="small mb-2">Use <strong>Download installer</strong> with Format <em>MSI</em> for this department. The stamped file <code><?= $h($depMsi['filename']) ?></code> already contains the server address, the token and the department. Double-click it, or install it silently (the log is written to <code>%TEMP%</code>; msiexec's exit code 0 or 3010 means installed):</p>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_msi_msiexec">Copy command</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_msi_msiexec" rows="2" readonly spellcheck="false"><?= $h((string) $depMsi['msiexec']) ?></textarea>
        <h6>2. MSI: PowerShell downloader (RMM, Intune platform script, GPO startup script)</h6>
        <p class="small mb-1">Downloads the stamped MSI from this server with the token (the same endpoint, <code>format = msi</code>), installs it with msiexec and deletes it. The script holds the token: keep it out of tickets and chat.</p>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_msi_ps">Copy script</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_msi_ps" rows="14" readonly spellcheck="false"><?= $h((string) $depMsi['powershell']) ?></textarea>
        <h6>3. MSI: property form (the unstamped template)</h6>
        <p class="small mb-1">For a tool that deploys the published template itself (<code>rivetit-agent-windows-amd64.msi</code>) and passes the server and the token as properties. Use <code>ENROLL_TOKEN_FILE</code> instead of <code>ENROLL_TOKEN</code> to keep the token off the command line; <code>CA_PEM_FILE</code> is only for a server certificate from a private CA.</p>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_msi_props">Copy command</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_msi_props" rows="3" readonly spellcheck="false"><?= $h((string) $depMsi['properties']) ?></textarea>
        <h6>4. MSI: uninstall and detection</h6>
        <p class="small mb-1">Uninstall (the product code changes with every version, so it is looked up by name; the MeshCentral agent stays unless <code>REMOVE_MESHAGENT=1</code> is added to the msiexec arguments):</p>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_msi_uninstall">Copy script</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_msi_uninstall" rows="4" readonly spellcheck="false"><?= $h((string) $depMsi['uninstall']) ?></textarea>
        <ul class="small mb-3">
            <li><strong>Detection rule (Intune, MSI product code or name):</strong> product name <code><?= $h($depMsi['detection']['product_name']) ?></code>, upgrade code <code><?= $h($depMsi['detection']['upgrade_code']) ?></code> (it never changes between versions, so an Intune MSI detection rule on the upgrade code survives an agent update). The file and service rules below also work.</li>
            <li><strong>Intune:</strong> add the stamped <code><?= $h($depMsi['filename']) ?></code> as a Line-of-business app (MSI), or wrap it as a Win32 app with install command <code><?= $h((string) $depMsi['msiexec']) ?></code>. The embedded token expires, so create the installer with a lifetime and use count that cover the rollout.</li>
            <li><strong>Group Policy:</strong> a software-installation GPO needs a share every computer can read, and the stamped file holds a token: prefer the PowerShell startup script above with a token of a small use count, or Intune.</li>
        </ul>
        <?php } else { ?>
        <h6>1. Interactive (one PC)</h6>
        <p class="small mb-3">Use <strong>Download installer</strong> in the Deployment card for this department, copy <code><?= $h($depFile) ?></code> to the PC and double-click it (accept the administrator prompt), or run <code><?= $h($depFile) ?> setup</code> from an elevated prompt. The file already contains the server address, the token and the department.</p>
        <h6>2. Silent install for an RMM, Intune platform script or GPO startup script</h6>
        <?php if ($snippet === '') { ?><p class="text-danger small">Set an https:// service URL first.</p><?php } else { ?>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_snippet">Copy script</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_snippet" rows="16" readonly spellcheck="false"><?= $h($snippet) ?></textarea>
        <?php } ?>
        <?php } ?>
        <?php if (!empty($dep['mesh']) && (string) ($dep['commands']['linux'] ?? '') !== '') { ?>
        <h6>2b. Linux, with the MeshCentral agent</h6>
        <p class="small mb-1">Run as root from the unpacked <code>rivetit-agent-linux-<?= $dep['arch'] === 'arm64' ? 'arm64' : 'amd64' ?>.tar.gz</code>. <code>--install-mesh-agent</code> makes the installer also fetch the MeshCentral agent from your Mesh server (and pin its certificate when you saved a pin). The command holds the Mesh device group id and the token: keep it out of tickets and chat. A Windows installer carries the same choice inside the file.</p>
        <div class="mb-1"><button type="button" class="btn btn-sm btn-outline-secondary" data-ea-copy-target="ea_snippet_linux">Copy command</button></div>
        <textarea class="form-control font-monospace small mb-3" id="ea_snippet_linux" rows="10" readonly spellcheck="false"><?= $h((string) $dep['commands']['linux']) ?></textarea>
        <?php } ?>
        <?php if (!$depMsi) { ?>
        <h6>3. Intune Win32 app and Group Policy</h6>
        <ul class="small mb-3">
            <li><strong>Intune Win32 app:</strong> wrap <code><?= $h($depFile) ?></code> with the Win32 Content Prep Tool (<code>IntuneWinAppUtil.exe -c folder -s <?= $h($depFile) ?> -o out</code>). Install command: <code><?= $h($depFile) ?> setup --silent</code>. Uninstall command: <code>"%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe" uninstall</code>. Install behavior: System. The embedded token expires, so create the installer with a lifetime and use count that cover the rollout.</li>
            <li><strong>Detection rule (either one):</strong> service <code>RivetITAgent</code> exists, or file <code>%ProgramFiles%\RivetIT\Agent\rivetit-agent.exe</code> exists. Custom script: <code>if ((Get-Service RivetITAgent -ErrorAction SilentlyContinue) -and (Test-Path "$env:ProgramFiles\RivetIT\Agent\rivetit-agent.exe")) { 'installed'; exit 0 } else { exit 1 }</code></li>
            <li><strong>Group Policy:</strong> Computer Configuration, Policies, Windows Settings, Scripts, Startup, PowerShell Scripts: add the script from step 2. Startup scripts run as SYSTEM before logon. SYSVOL is readable by every domain user, so use a token with a small use count, or prefer Intune or your RMM, which keep the script private.</li>
            <li>The setup is idempotent: a machine that already has the agent keeps its identity. Windows PCs that do not trust your server certificate need the issuing CA in their store (or set the CA certificate in the service settings, which the installer embeds for the agent).</li>
        </ul>
        <?php } ?>
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

<div class="card mb-3" id="inventory">
    <div class="card-header"><h4 class="card-title mb-0">Software inventory and history</h4></div>
    <div class="card-body">
        <p class="small text-muted">Agents of RivetCore 1.0.0-rc.9 or later can report the software installed on a device (registry uninstall entries on Windows; dpkg, rpm, snap and flatpak on Linux). Nothing is collected until this switch is on, and an agent only sends it after the server offers it, so the order of rollout is safe both ways. Switching it off makes agents stop within one check-in. The list shows on each device's asset page (Software tab) and feeds the fleet filters and the outdated-software report.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" id="ea_inv_sw" name="inventory_software" value="1" <?= !empty($cfg['features']['inventory_software']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="ea_inv_sw">Collect installed software (<code>inventory_software</code>)</label>
                <div class="form-text">Off by default. Takes effect only while the RMM module itself is on.</div>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-4"><label class="form-label" for="ea_chd">Check history retention (days)</label>
                    <input type="number" class="form-control" id="ea_chd" name="check_history_days" min="0" max="365" value="<?= (int) ($cfg['limits']['check_history_days'] ?? 7) ?>" aria-describedby="ea_chd_h">
                    <div class="form-text" id="ea_chd_h">How long each check keeps its status trend (the sparkline on the device page). 0 records none. Default 7.</div></div>
                <div class="col-sm-6 col-lg-4"><label class="form-label" for="ea_shd">Software change history retention (days)</label>
                    <input type="number" class="form-control" id="ea_shd" name="software_history_days" min="1" max="3650" value="<?= (int) ($cfg['limits']['software_history_days'] ?? 365) ?>" aria-describedby="ea_shd_h">
                    <div class="form-text" id="ea_shd_h">How long installs, upgrades and removals are kept. Default 365.</div></div>
            </div>
            <button type="submit" name="save_inventory_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Save software settings</button>
        </form>
    </div>
</div>

<?php
// RMM Phase 2 and 3 (RivetCore 1.0.0-rc.10): policies, script library with schedules and approvals, and alerting. Each sub-switch is off by default.
$autoStorm = (array) ($rmm->alertingActions()->settings(rivetRmmPrincipal((int) $session_user_id, (string) $session_name))->data['storm'] ?? []);
$autoSettings = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT config_rmm_approve_scripts_lvl3 AS a, config_rmm_escalation_contact AS c FROM settings WHERE company_id = 1')) ?: ['a' => 0, 'c' => ''];
$autoLimits = (array) $cfg['limits'];
?>
<div class="card mb-3" id="automation">
    <div class="card-header"><h4 class="card-title mb-0">Policies, scripts and alerting</h4></div>
    <div class="card-body">
        <p class="small text-muted">Three optional parts of the endpoint agent, each off until you switch it on. Roll out an agent of RivetCore 1.0.0-rc.10 or later first: older agents keep working, they simply do not use the new parts. Each switch shows an Endpoints menu entry for the technicians who may use it, and takes effect only while the RMM module itself is on. Switching one off stops it within one check-in and keeps everything you created.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <div class="form-check form-switch mb-2">
                            <input type="checkbox" class="form-check-input" id="ea_sw_policies" name="feature_policies" value="1" <?= !empty($cfg['features']['policies']) ? 'checked' : '' ?> aria-describedby="ea_sw_policies_h">
                            <label class="form-check-label fw-semibold" for="ea_sw_policies">Policies (<code>policies</code>)</label>
                        </div>
                        <p class="small text-muted mb-0" id="ea_sw_policies_h">Settings and check templates assigned to everything, a department, a site, a group, a tag or one device; the most specific wins. <strong>Cost:</strong> one extra database query per check-in (the same however many policies exist), plus re-signing the device's check list. No other work while it is off.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <div class="form-check form-switch mb-2">
                            <input type="checkbox" class="form-check-input" id="ea_sw_scripts" name="feature_scripts" value="1" <?= !empty($cfg['features']['scripts']) ? 'checked' : '' ?> aria-describedby="ea_sw_scripts_h">
                            <label class="form-check-label fw-semibold" for="ea_sw_scripts">Script library, schedules and approvals (<code>scripts</code>)</label>
                        </div>
                        <p class="small text-muted mb-0" id="ea_sw_scripts_h">Signed, versioned scripts with parameters, runs on a device, tag, group or department, schedules, a second person's approval and custom fields. <strong>Cost:</strong> the scheduler runs from the minute cron: about 4 database statements per device per scheduled run (a daily schedule on 5,000 devices adds about 5,000 job rows a day), and nothing runs between schedules. Scripts run as SYSTEM or root on the device.</p>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <div class="form-check form-switch mb-2">
                            <input type="checkbox" class="form-check-input" id="ea_sw_alerting" name="feature_alerting" value="1" <?= !empty($cfg['features']['alerting']) ? 'checked' : '' ?> aria-describedby="ea_sw_alerting_h">
                            <label class="form-check-label fw-semibold" for="ea_sw_alerting">Alerting (<code>alerting</code>)</label>
                        </div>
                        <p class="small text-muted mb-0" id="ea_sw_alerting_h">Warning and critical thresholds, flap detection, maintenance windows, device dependencies, alert storm control and escalation. <strong>Cost:</strong> a healthy check-in costs nothing extra; a check with thresholds or flap detection adds one batched read, and a write only when its state changes (about 2 to 5 extra statements per check-in at worst). Escalation is worked by the minute cron, never inside a check-in.</p>
                    </div>
                </div>
            </div>
            <h6 class="mt-4">Approvals</h6>
            <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="ea_apr3" name="approve_scripts_lvl3" value="1" <?= (int) $autoSettings['a'] === 1 ? 'checked' : '' ?> aria-describedby="ea_apr3_h">
                <label class="form-check-label" for="ea_apr3">Technicians with RMM scripts level 3 may approve runs</label>
                <div class="form-text" id="ea_apr3_h">Administrators can always approve. A request is never approved by the person who made it, so a second person is always needed. Off by default.</div>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_apb">Approval needed above (devices)</label>
                    <input type="number" class="form-control" id="ea_apb" name="approval_bulk_threshold" min="0" max="100000" value="<?= (int) ($autoLimits['approval_bulk_threshold'] ?? 0) ?>" aria-describedby="ea_apb_h">
                    <div class="form-text" id="ea_apb_h">A run or schedule on more devices than this needs a second person. 0 means never (a script marked "requires approval" always does).</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_ape">Approval request expires after (hours)</label>
                    <input type="number" class="form-control" id="ea_ape" name="approval_expiry_h" min="1" max="720" value="<?= (int) ($autoLimits['approval_expiry_h'] ?? 48) ?>">
                    <div class="form-text">Default 48.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_asb">Scheduled jobs per minute</label>
                    <input type="number" class="form-control" id="ea_asb" name="schedule_batch" min="10" max="5000" value="<?= (int) ($autoLimits['schedule_batch'] ?? 200) ?>" aria-describedby="ea_asb_h">
                    <div class="form-text" id="ea_asb_h">Most jobs one scheduler pass queues. 200 reaches 5,000 devices in about 25 minutes. Halved or paused automatically when the server is under load.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_abr">Most devices in one run</label>
                    <input type="number" class="form-control" id="ea_abr" name="bulk_run_max" min="1" max="100000" value="<?= (int) ($autoLimits['bulk_run_max'] ?? 1000) ?>">
                    <div class="form-text">A larger run is refused. Default 1000.</div></div>
            </div>
            <h6 class="mt-4">Alerting</h6>
            <div class="row g-3">
                <div class="col-lg-6"><label class="form-label" for="ea_esc">Escalation contact</label>
                    <textarea class="form-control" id="ea_esc" name="escalation_contact" rows="2" maxlength="600" aria-describedby="ea_esc_h"><?= $h((string) $autoSettings['c']) ?></textarea>
                    <div class="form-text" id="ea_esc_h">Email addresses or user ids, separated by commas. Told whenever an escalation step reaches nobody, and copied on every critical alert. Escalation steps themselves are set under Endpoints &rarr; Escalation policies. A critical alert that escalates also opens (or reuses) a ticket.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sc">Storm control: alerts per department</label>
                    <input type="number" class="form-control" id="ea_sc" name="storm_client_max" min="0" max="1000000" value="<?= (int) ($autoStorm['storm_client_max'] ?? 100) ?>" aria-describedby="ea_sc_h">
                    <div class="form-text" id="ea_sc_h">Most alerts one department may open in 10 minutes; the rest wait and one summary alert says so. 0 turns the limit off. Default 100.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sg">Storm control: alerts in total</label>
                    <input type="number" class="form-control" id="ea_sg" name="storm_global_max" min="0" max="1000000" value="<?= (int) ($autoStorm['storm_global_max'] ?? 500) ?>">
                    <div class="form-text">Across all departments in 10 minutes. Default 500.</div></div>
            </div>
            <input type="hidden" name="storm_global_window_s" value="<?= (int) ($autoStorm['storm_global_window_s'] ?? 600) ?>">
            <input type="hidden" name="storm_client_window_s" value="<?= (int) ($autoStorm['storm_client_window_s'] ?? 600) ?>">
            <button type="submit" name="save_automation_settings" class="btn btn-primary mt-3"><i class="fas fa-save me-1"></i>Save policies, scripts and alerting settings</button>
        </form>
    </div>
</div>

<?php
// RivetCore 1.0.0-rc.20 (RMM Phase 4, patch management): one sub-switch (`patching`, off by default) and its limits. The cost text is Core's own wording (docs/rmm/PATCHING.md section 9).
$patchOn = !empty($cfg['features']['patching']);
$patchLim = (array) $cfg['limits'];
$patchNum = static fn (string $key, int $def): int => (int) ($patchLim[$key] ?? $def);
$patchLive = $patchNum('patch_live_install', 0) === 1;
?>
<div class="card mb-3" id="patch-management">
    <div class="card-header"><h4 class="card-title mb-0">Patch management <?= $patchOn ? '<span class="badge text-bg-success ms-2">On</span>' : '<span class="badge text-bg-secondary ms-2">Off</span>' ?></h4></div>
    <div class="card-body">
        <p class="small text-muted">Detect first, install gated. A device reports which updates it is missing; a person (or a rule you write) approves them; an installation happens only for approved updates, only where a patch policy allows it, only inside the install window, and never faster than the limits below. The agent never restarts a machine by itself. Needs an agent of RivetCore 1.0.0-rc.20 or later (0.8.0); older agents are never offered a patch job. Switching it off stops it within one check-in and keeps everything you created.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="border rounded p-3 mb-3">
                <div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" id="ea_sw_patching" name="feature_patching" value="1" <?= $patchOn ? 'checked' : '' ?> aria-describedby="ea_sw_patching_h">
                    <label class="form-check-label fw-semibold" for="ea_sw_patching">Patch management (<code>patching</code>)</label>
                </div>
                <p class="small text-muted mb-0" id="ea_sw_patching_h"><strong>Cost:</strong> Patch management asks every device for its list of missing updates about once a day (a short read-only check, no download) and keeps that list and the install history in the database. It installs nothing by itself until you assign a patch policy that allows it, approve updates, and switch on live installs.</p>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_scan">Scan each device every (seconds)</label>
                    <input type="number" class="form-control" id="ea_pt_scan" name="patch_scan_interval_s" min="3600" max="604800" value="<?= $patchNum('patch_scan_interval_s', 86400) ?>">
                    <div class="form-text">3,600 to 604,800. Default 86,400 (a day); spread so a fleet never scans at once.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_over">Overdue after (days)</label>
                    <input type="number" class="form-control" id="ea_pt_over" name="patch_overdue_days" min="1" max="365" value="<?= $patchNum('patch_overdue_days', 14) ?>">
                    <div class="form-text">A critical or security update missing longer than this makes a device non-compliant. Default 14.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_max">Most installs at once (whole fleet)</label>
                    <input type="number" class="form-control" id="ea_pt_max" name="patch_max_installs" min="1" max="10000" value="<?= $patchNum('patch_max_installs', 25) ?>">
                    <div class="form-text">Default 25.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_maxc">Most installs at once (one department)</label>
                    <input type="number" class="form-control" id="ea_pt_maxc" name="patch_max_installs_per_client" min="1" max="1000" value="<?= $patchNum('patch_max_installs_per_client', 5) ?>">
                    <div class="form-text">Default 5.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_hist">Keep install history (days)</label>
                    <input type="number" class="form-control" id="ea_pt_hist" name="patch_history_days" min="30" max="3650" value="<?= $patchNum('patch_history_days', 365) ?>">
                    <div class="form-text">Default 365.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_pt_batch">Devices per scheduler step</label>
                    <input type="number" class="form-control" id="ea_pt_batch" name="patch_batch" min="10" max="5000" value="<?= $patchNum('patch_batch', 100) ?>">
                    <div class="form-text">Halved automatically when the server is busy. Default 100.</div></div>
            </div>
            <h6 class="mt-4">Live installs by schedule</h6>
            <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="ea_pt_live" name="patch_live_install" value="1" <?= $patchLive ? 'checked' : '' ?> aria-describedby="ea_pt_live_h">
                <label class="form-check-label" for="ea_pt_live">Let the scheduler install approved updates for real (<code>patch_live_install</code>)</label>
                <div class="form-text" id="ea_pt_live_h">Off (the default): the scheduler only queues a dry run (one a day per device) that lists what it would install and changes nothing. On: for devices whose patch policy has installs enabled, approved updates are installed inside the install window. A technician with the install grant can still start a manual install on one device either way. <strong>The Windows install path has never been run on a real machine</strong> (the Windows scan has; Linux apt is proven end to end): try a dry run first, then one machine in the pilot ring.</div>
            </div>
            <div class="form-check mb-3" id="ea_pt_ack_wrap">
                <input type="checkbox" class="form-check-input" id="ea_pt_ack" name="accept_patch_live" value="1">
                <label class="form-check-label" for="ea_pt_ack">I understand that live installs change software on devices, I have run a dry run first, and I accept the risk. (Needed only when switching live installs on.)</label>
            </div>
            <button type="submit" name="save_patch_settings" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save patch management settings</button>
            <?php if ($patchOn) { ?><a class="btn btn-outline-secondary ms-2" href="/agent/rmm_patches.php"><i class="fas fa-shield-alt me-1"></i>Open patch compliance</a><?php } ?>
        </form>
    </div>
</div>

<?php
// RivetCore 1.0.0-rc.21 (RMM Phase 5, software deployment): one sub-switch (`software_deploy`, off by default), its limits and a SEPARATE switch for live installs. The cost text is Core's own wording
// (docs/rmm/SOFTWARE_DEPLOY.md section 9).
$swOn = !empty($cfg['features']['software_deploy']);
$swLim = (array) $cfg['limits'];
$swNum = static fn (string $key, int $def): int => (int) ($swLim[$key] ?? $def);
$swLive = $swNum('software_live_install', 0) === 1;
?>
<div class="card mb-3" id="software-deployment">
    <div class="card-header"><h4 class="card-title mb-0">Software deployment <?= $swOn ? '<span class="badge text-bg-success ms-2">On</span>' : '<span class="badge text-bg-secondary ms-2">Off</span>' ?><?= $swOn && $swLive ? '<span class="badge text-bg-warning ms-2">Live installs on</span>' : '' ?></h4></div>
    <div class="card-body">
        <p class="small text-muted">Install what you assigned, check that it is there, show the difference. You keep a catalog of packages (each version pinned: a package name from the machine's own signed repositories, or a Windows installer with its https address, SHA-256 and size), allow the hosts installers may come from, and assign a package to a scope. The agent reports what each machine has; the scheduler fills the difference, only where it is allowed and never faster than the limits below. Needs an agent of RivetCore 1.0.0-rc.21 or later; older agents are never offered a software job. Switching it off stops it within one check-in and keeps everything you created.</p>
        <form action="post.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="border rounded p-3 mb-3">
                <div class="form-check form-switch mb-2">
                    <input type="checkbox" class="form-check-input" id="ea_sw_software" name="feature_software_deploy" value="1" <?= $swOn ? 'checked' : '' ?> aria-describedby="ea_sw_software_h">
                    <label class="form-check-label fw-semibold" for="ea_sw_software">Software deployment (<code>software_deploy</code>)</label>
                </div>
                <p class="small text-muted mb-0" id="ea_sw_software_h"><strong>Cost:</strong> Software deployment keeps a catalog of packages. Once you assign a package, every device in that scope is asked what it has about once a day (a short read-only check) and the answer is stored. Nothing is installed until you switch on live installs; until then you only see what would be installed.</p>
            </div>
            <div class="row g-3">
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sd_detect">Ask each device what it has every (seconds)</label>
                    <input type="number" class="form-control" id="ea_sd_detect" name="software_detect_interval_s" min="3600" max="604800" value="<?= $swNum('software_detect_interval_s', 86400) ?>">
                    <div class="form-text">3,600 to 604,800. Default 86,400 (a day); spread so a fleet never answers at once.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sd_max">Most installs at once (whole fleet)</label>
                    <input type="number" class="form-control" id="ea_sd_max" name="software_max_installs" min="1" max="10000" value="<?= $swNum('software_max_installs', 25) ?>">
                    <div class="form-text">Default 25.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sd_maxc">Most installs at once (one department)</label>
                    <input type="number" class="form-control" id="ea_sd_maxc" name="software_max_installs_per_client" min="1" max="1000" value="<?= $swNum('software_max_installs_per_client', 5) ?>">
                    <div class="form-text">Default 5.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sd_hist">Keep install history (days)</label>
                    <input type="number" class="form-control" id="ea_sd_hist" name="software_deploy_history_days" min="30" max="3650" value="<?= $swNum('software_deploy_history_days', 365) ?>">
                    <div class="form-text">Default 365.</div></div>
                <div class="col-sm-6 col-lg-3"><label class="form-label" for="ea_sd_batch">Devices per scheduler step</label>
                    <input type="number" class="form-control" id="ea_sd_batch" name="software_batch" min="10" max="5000" value="<?= $swNum('software_batch', 100) ?>">
                    <div class="form-text">Halved automatically when the server is busy. Default 100.</div></div>
            </div>
            <h6 class="mt-4">Live installs by schedule</h6>
            <div class="form-check form-switch mb-2">
                <input type="checkbox" class="form-check-input" id="ea_sd_live" name="software_live_install" value="1" <?= $swLive ? 'checked' : '' ?> aria-describedby="ea_sd_live_h">
                <label class="form-check-label" for="ea_sd_live">Let the scheduler install and remove for real (<code>software_live_install</code>)</label>
                <div class="form-text" id="ea_sd_live_h">Off (the default): the scheduler only queues a dry run (one a day per device and package) that shows what it would do and changes nothing. On: devices that are missing an assigned package get a real install, one rollout ring after another, inside the limits above and outside any "leave this device alone" window; a package that needs a second person still waits for one. A technician with the deploy grant can still start a manual install on one device either way. <strong>The Windows install paths (MSI, EXE, the hash-pinned download) have never been run on a real machine</strong> (Linux apt is proven end to end): try a dry run first, then one machine in the pilot ring, then a small package.</div>
            </div>
            <div class="form-check mb-3" id="ea_sd_ack_wrap">
                <input type="checkbox" class="form-check-input" id="ea_sd_ack" name="accept_software_live" value="1">
                <label class="form-check-label" for="ea_sd_ack">I understand that live installs change software on devices, I have run a dry run first, and I accept the risk. (Needed only when switching live installs on; the change is written to the audit log with my name.)</label>
            </div>
            <button type="submit" name="save_software_settings" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save software deployment settings</button>
            <?php if ($swOn) { ?><a class="btn btn-outline-secondary ms-2" href="/agent/rmm_software.php"><i class="fas fa-box-open me-1"></i>Open the software catalog</a><?php } ?>
        </form>
    </div>
</div>

<?php
// RivetCore 1.0.0-rc.11 (RMM Phases 6 and 8 and the scale work): the data for the cards below is read here, the cards are printed by rivetRmmUiScaleCards() (includes/rmm_scale_cards.php).
// Each part is off by default and is guarded so this page still loads on a database that has not been updated yet.
$rmm_scale_scripts = true;
$scaleCtx = ['csrf' => $csrf, 'module_on' => $moduleOn, 'features' => (array) $cfg['features'], 'msg' => [], 'old' => []];
foreach (['remote-tools', 'file-transfer', 'log-collection', 'scale', 'partitioning', 'ext-store'] as $__card) {
    [$scaleCtx['msg'][$__card], $scaleCtx['old'][$__card]] = rivetRmmScaleTake($__card);
}
$scaleCtx['rs'] = $rmm->remoteSettings()->summary();
$__lvl3 = @mysqli_fetch_row(@mysqli_query($mysqli, 'SELECT config_rmm_remote_manage_lvl3 FROM settings WHERE company_id = 1'));
$scaleCtx['lvl3'] = (int) ($__lvl3[0] ?? 0) === 1;
$scaleCtx['caps'] = ['total' => null, 'services' => 0, 'processes' => 0, 'logs' => 0];
try {
    $__cap = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS n, COALESCE(SUM(s.capabilities_json LIKE '%\"remote:services\"%'), 0) AS a, COALESCE(SUM(s.capabilities_json LIKE '%\"remote:processes\"%'), 0) AS b,
        COALESCE(SUM(s.capabilities_json LIKE '%\"logs:collect\"%'), 0) AS c FROM endpoint_agent_devices d LEFT JOIN rmm_device_state s ON s.device_id = d.device_id WHERE d.revoked_at IS NULL AND d.retired_at IS NULL"));
    if ($__cap) {
        $scaleCtx['caps'] = ['total' => (int) $__cap['n'], 'services' => (int) $__cap['a'], 'processes' => (int) $__cap['b'], 'logs' => (int) $__cap['c']];
    }
} catch (\Throwable) {
    // table not there yet
}
$scaleCtx['transfer'] = rivetRmmTransferDirStatus((int) $scaleCtx['rs']['limits']['transfer_store_max_bytes']);
$__lim = $rmm->settings()->limits();
$scaleCtx['scale'] = ['limits' => $__lim, 'ingest_mode' => (string) ($rmm->settings()->get()['ingest_mode'] ?? 'sync'), 'cache_shared' => $rmm->cache()->shared(),
    'bucket_suggestion' => rivetRmmScaleBucketSuggestion($mysqli, (int) ($rmm->settings()->get()['check_in_interval_s'] ?? 300))];
// Partitioning: the last probe (kept by Core), the partitions that exist now, and the size of RivetIT's own metric tables (information_schema estimates).
$__probe = null;
$__probeAt = null;
try {
    $__ps = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT detail, updated_at FROM rmm_storage_state WHERE name = 'partition_probe'"));
    $__d = $__ps && is_string($__ps['detail']) ? json_decode($__ps['detail'], true) : null;
    if (is_array($__d)) {
        $__probe = $__d;
        $__probeAt = str_replace('T', ' ', rtrim((string) ($__d['at'] ?? $__ps['updated_at']), 'Z'));
    }
} catch (\Throwable) {
    $__probe = null;
}
$__own = ['device_metric_samples' => 0, 'device_metric_rollups' => 0];
try {
    $__r = mysqli_query($mysqli, "SELECT TABLE_NAME AS t, COALESCE(TABLE_ROWS, 0) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('device_metric_samples', 'device_metric_rollups')");
    while ($__r && ($__row = mysqli_fetch_assoc($__r))) {
        $__own[$__row['t']] = (int) $__row['n'];
    }
} catch (\Throwable) {
}
$scaleCtx['partition'] = ['words' => $__probe === null ? null : rivetRmmScaleProbeWords($__probe), 'probe_at' => $__probeAt, 'own_rows' => $__own, 'history_partitions' => []];
try {
    $scaleCtx['partition']['history_partitions'] = $rmm->partitions()->partitions('endpoint_agent_check_history');
} catch (\Throwable) {
}
$__flags = rivetRmmExtStoreFlags($mysqli);
$__buf = ['batches' => 0, 'points' => 0, 'oldest' => null, 'max_attempts' => 0, 'last_error' => null];
if ($__flags['enabled']) {
    try {
        $__b = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT COUNT(*) AS n, COALESCE(SUM(points), 0) AS p, MIN(created_at) AS o, COALESCE(MAX(attempts), 0) AS a FROM rmm_metric_outbox'));
        $__e = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT last_error FROM rmm_metric_outbox WHERE last_error IS NOT NULL AND last_error <> '' ORDER BY attempts DESC, outbox_id DESC LIMIT 1"));
        $__buf = ['batches' => (int) ($__b['n'] ?? 0), 'points' => (int) ($__b['p'] ?? 0), 'oldest' => $__b['o'] ?? null, 'max_attempts' => (int) ($__b['a'] ?? 0), 'last_error' => $__e['last_error'] ?? null];
    } catch (\Throwable) {
    }
}
$scaleCtx['ext'] = ['flags' => $__flags, 'buffer' => $__buf];
echo rivetRmmUiScaleCards($scaleCtx);
?>

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

<div class="card mb-3" id="msi-templates">
    <div class="card-header"><h4 class="card-title mb-0">MSI template</h4></div>
    <div class="card-body">
        <div class="alert alert-danger small" role="alert" id="msi-unverified"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><strong>UNVERIFIED on Windows.</strong> The MSI installer has never been run on a real Windows PC. Whether <code>msiexec</code> accepts a package with the enrollment payload appended after the end of the file, what happens on repair and upgrade, and the whole custom-action flow are not proven. The template is also <strong>unsigned</strong>: SmartScreen or an application-control policy may warn or block it, and signing a template and then stamping it would break the signature of the stamped copy. Work through the checklist <code>docs/rmm/MSI_VERIFY.md</code> in RivetCore (<a href="https://github.com/TheTractorHacker/rivet-core/blob/v1.0.0-rc.16/docs/rmm/MSI_VERIFY.md" rel="noopener noreferrer" target="_blank">open it<span class="visually-hidden"> (opens in a new tab)</span></a>) on one PC of each kind before relying on it. The <strong>.exe installer stays the default and the proven path</strong>.</div>
        <p class="small text-muted">An MSI installer is a copy of a template published here with the department's enrollment payload appended; the stored template is never changed. Upload <code>rivetit-agent-windows-amd64.msi</code> from the agent release (or build it with <code>make msi</code> in RivetCore, see <code>docs/rmm/AGENT_BUILD.md</code>). <strong>Only x64 (amd64) is built</strong>: the build tool cannot make an ARM64 package, so ARM64 PCs use the .exe; an ARM64 package you built yourself is accepted.</p>
        <p class="small text-muted mb-2">The server checks that the file is a Windows Installer package (an OLE compound file), that it is between <?= $h(BinaryStore::human(\RivetCore\Rmm\Binaries\MsiInspector::MIN_BYTES)) ?> and <?= $h(BinaryStore::human($rmm->binaryStore()->maxBytes())) ?>, that it is not already an installer with a payload, and reads the platform from the package's summary information (<code>x64</code> or <code>Arm64</code>; <code>Intel</code> is refused; if the summary cannot be read the file is accepted on your word and recorded as <em>declared</em>). It is stored outside the web-served area. Largest accepted upload now: <strong><?= $h(BinaryStore::human(min($uploadLimit, $rmm->binaryStore()->maxBytes()))) ?></strong> (the lower of the cap, PHP <code>upload_max_filesize</code> <?= $h(ini_get('upload_max_filesize')) ?> and <code>post_max_size</code> <?= $h(ini_get('post_max_size')) ?>). Larger or automated uploads: <code>sudo -u www-data php scripts/endpoint_agent_publish.php rivetit-agent-windows-amd64.msi --msi --version 1.2.0 --arch amd64 --activate</code>.</p>
        <form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-3" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-lg-4"><label class="form-label small" for="msi_file">MSI template (.msi)</label><input type="file" class="form-control form-control-sm" id="msi_file" name="msi_template" accept=".msi" required></div>
            <div class="col-lg-2"><label class="form-label small" for="msi_v">Version</label><input class="form-control form-control-sm" id="msi_v" name="version" placeholder="1.2.0" maxlength="40" required></div>
            <div class="col-lg-2"><label class="form-label small" for="msi_a">Architecture</label><select class="form-select form-select-sm" id="msi_a" name="arch"><option value="amd64">x64 (amd64)</option><option value="arm64">ARM64 (your own build)</option></select></div>
            <div class="col-lg-3"><div class="form-check"><input type="checkbox" class="form-check-input" id="msi_cur" name="activate" value="1" checked><label class="form-check-label small" for="msi_cur">Use for new MSI installers (make current)</label></div></div>
            <div class="col-lg-1"><button class="btn btn-sm btn-primary w-100" name="upload_msi_template" value="1">Upload</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">Published MSI templates</caption>
            <thead><tr><th scope="col">Version</th><th scope="col">Arch</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col">Platform read from</th><th scope="col">Uploaded (UTC)</th><th scope="col">State</th><th scope="col"></th></tr></thead><tbody>
            <?php foreach ($msiTemplates as $t) { ?>
                <tr>
                    <td><?= $h($t['version']) ?></td><td><?= $h($t['arch']) ?></td><td><?= $h(BinaryStore::human((int) $t['size_bytes'])) ?></td>
                    <td class="small font-monospace text-break"><?= $h($t['sha256']) ?></td>
                    <td class="small"><?= $t['arch_source'] === 'summary' ? 'the package' : '<span class="badge bg-warning text-dark">declared by the uploader</span>' ?></td>
                    <td><?= $h($dt($t['created_at'])) ?></td>
                    <td><?php if (!$t['active']) { echo '<span class="badge bg-secondary">Inactive</span>'; } elseif ($t['is_current']) { echo '<span class="badge bg-success">Current</span>'; } else { echo '<span class="badge bg-info text-dark">Available</span>'; } ?></td>
                    <td class="text-nowrap">
                        <form action="post.php" method="post" class="d-inline-flex gap-1 flex-wrap align-items-center"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="template_id" value="<?= (int) $t['template_id'] ?>">
                        <?php if ($t['active']) { ?>
                            <?php if (!$t['is_current']) { ?><button class="btn btn-xs btn-outline-primary" name="msi_template_action" value="make_current">Make current</button><?php } ?>
                            <button class="btn btn-xs btn-outline-danger" name="msi_template_action" value="deactivate" data-ea-confirm="Deactivate this MSI template? It stops being used for MSI installers. The file is kept.">Deactivate</button>
                        <?php } else { ?><button class="btn btn-xs btn-outline-primary" name="msi_template_action" value="activate">Reactivate</button><?php } ?>
                        </form>
                    </td>
                </tr>
            <?php } if (!$msiTemplates) { echo '<tr><td colspan="8" class="text-muted">No MSI template published yet. MSI installers cannot be created until one is current; the .exe installer does not need one.</td></tr>'; } ?>
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
            <div class="col-lg-2"><label class="form-label" for="dp_format">Format</label><select class="form-select" id="dp_format" name="format" aria-describedby="dp_format_help" data-msi-amd64="<?= $msiCur['amd64'] !== null ? '1' : '0' ?>" data-msi-arm64="<?= $msiCur['arm64'] !== null ? '1' : '0' ?>"><option value="exe" selected>Installer (.exe)</option><option value="msi">MSI (.msi)</option></select></div>
            <div class="col-lg-1"><label class="form-label" for="dp_loc">Location</label><input type="number" class="form-control" id="dp_loc" name="location_id" min="0" value="0" title="Location id, 0 for none"></div>
            <div class="col-lg-2"><label class="form-label" for="dp_ring">Update ring</label><select class="form-select" id="dp_ring" name="ring"><option value="stable">stable</option><option value="pilot">pilot</option></select></div>
            <div class="col-lg-1"><label class="form-label" for="dp_ttl">Hours</label><input type="number" class="form-control" id="dp_ttl" name="ttl_hours" min="1" max="<?= (int) $cfg['enroll_max_ttl_h'] ?>" value="<?= (int) min(72, $cfg['enroll_max_ttl_h']) ?>"></div>
            <div class="col-lg-1"><label class="form-label" for="dp_uses">Max uses</label><input type="number" class="form-control" id="dp_uses" name="max_uses" min="1" max="5000" value="25"></div>
            <div class="col-lg-2"><label class="form-label" for="dp_label">Label</label><input type="text" class="form-control" id="dp_label" name="label" maxlength="100"></div>
            <?php $depMesh = rivetRmmInstallerMesh($rmm, $cfg); if ($depMesh['show']) { ?>
            <div class="col-12" id="dp_mesh_col"><div class="form-check"><input class="form-check-input" type="checkbox" id="dp_mesh" name="install_mesh_agent" value="1" aria-describedby="dp_mesh_help"><label class="form-check-label" for="dp_mesh">Also install the MeshCentral agent</label></div>
                <div class="form-text" id="dp_mesh_help"><?= $depMesh['ready'] ? 'After the RivetIT agent is installed, the installer downloads the MeshCentral agent from your own Mesh server and installs it. If the Mesh part fails, the RivetIT agent is still installed. The commands for Linux then carry the Mesh address and device group id: treat them as secret.' : 'Not ready yet: ' . $h($depMesh['hint']) . ' <a href="#mesh">Open the MeshCentral settings below</a>.' ?></div></div>
            <?php } ?>
            <div class="col-12 small text-muted" id="dp_format_help" aria-live="polite">The .exe is the default. The MSI is a stamped copy of the published MSI template for Intune, Group Policy or any tool that installs .msi packages. <?= $msiCur['amd64'] === null ? 'No MSI template is published for x64 yet (<a href="#msi-templates">upload one under MSI template</a>).' : 'Current x64 template: ' . $h($msiCur['amd64']) . '.' ?></div>
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

<?php
$meshInstall = rivetRmmMeshInstallView($rmm);
$meshProbe = isset($_SESSION['ea_mesh_pin_probe']) && is_array($_SESSION['ea_mesh_pin_probe']) ? $_SESSION['ea_mesh_pin_probe'] : null;   // the last "Read it from the server", shown once; never saved by the reading
$meshKeep = isset($_SESSION['ea_mesh_keep']) && is_array($_SESSION['ea_mesh_keep']) ? $_SESSION['ea_mesh_keep'] : [];                      // what was typed when Core refused it
unset($_SESSION['ea_mesh_pin_probe'], $_SESSION['ea_mesh_keep']);
?>
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
            <?= rivetRmmMeshHideFields($meshInstall, $meshKeep) ?>
            <?= rivetRmmMeshInstallFields($meshInstall, $meshProbe, $meshKeep) ?>
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
document.querySelectorAll('[data-ea-use-pin]').forEach(function (b) {
    b.addEventListener('click', function () {
        var t = document.getElementById(b.getAttribute('data-ea-use-pin-target'));
        if (t) { t.value = b.getAttribute('data-ea-use-pin'); t.focus(); }
    });
});
(function () {   // Deployment: the MSI format is offered only for an architecture that has a current template (ARM64 normally has none)
    var fmt = document.getElementById('dp_format'), arch = document.getElementById('dp_arch'), help = document.getElementById('dp_format_help');
    if (!fmt || !arch) { return; }
    var base = help ? help.innerHTML : '';
    function sync() {
        var ok = fmt.getAttribute('data-msi-' + arch.value) === '1', opt = fmt.querySelector('option[value="msi"]');
        opt.disabled = !ok;
        if (!ok && fmt.value === 'msi') { fmt.value = 'exe'; }
        if (help) {
            help.innerHTML = base;
            if (!ok) {
                var n = document.createElement('div');
                n.className = 'text-warning-emphasis';
                n.textContent = arch.value === 'arm64' ? 'MSI is not available for ARM64: no ARM64 MSI template is published (the build makes x64 only). Use the .exe.' : 'MSI is not available: no current MSI template is published for x64.';
                help.appendChild(n);
            }
        }
    }
    arch.addEventListener('change', sync); sync();
})();
document.querySelectorAll('input[data-ea-select]').forEach(function (i) { i.addEventListener('focus', function () { i.select(); }); });
</script>
<?php require_once "../includes/footer.php"; ?>
