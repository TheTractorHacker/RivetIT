<?php

/*
 * "Add device / Build installer" (RivetIT 26.10.52): ONE page for every way to get the endpoint agent onto a machine. Pick the department and the platform, then
 *   - Windows: the stamped .exe, the stamped .msi (from the MSI template), and the silent-install commands for Intune / GPO / an RMM;
 *   - Linux: a one-line command that downloads install-linux.sh and the agent, checks both against the SHA-256 recorded on this server and installs, plus the .deb / .rpm / .tar.gz links.
 * Nothing here decides anything: the department scope, the enrollment token, the audit entry and every refusal are RivetCore\Rmm\Admin\RmmAdmin behind agent/post/rmm_installer.php (the same
 * endpoint as the Add device dialog). Each button creates its own audited enrollment token; the token is shown once. Script: js/rmm_add_device.js.
 */

require_once __DIR__ . '/rmm_installer.php';
require_once __DIR__ . '/rmm_agent_feed.php';

/**
 * @param array<string,mixed> $ctx rivetRmmInstallerContext()
 * @param array{version:?string,items:list<array<string,mixed>>} $linux rivetAgentFeedLinuxAssets()
 */
function rivetRmmAddDevicePage(array $ctx, array $linux): string
{
    $h = 'rivetRmmInstH';
    $b = $ctx['binaries'];
    $msi = $ctx['msi'];
    $admin = (bool) $ctx['admin'];
    $feedLink = $admin ? ' <a href="' . $h(RIVET_RMM_AGENT_UPDATES_URL) . '">Publish it under Administration &gt; Endpoint agent &gt; Agent updates</a>.' : ' Ask an administrator to publish it.';
    $chip = static fn (bool $on, string $label, string $detail): string => '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . $label . '</span>'
        . ($on ? '<span class="badge text-bg-success">' . $detail . '</span>' : '<span class="badge text-bg-secondary">not published</span>') . '</li>';

    $clientField = '';
    if ($ctx['fixed']) {
        $clientField = '<input type="hidden" name="client_id" id="ad-client" value="' . (int) $ctx['client_id'] . '"><div class="form-control-plaintext fw-bold">' . $h((string) reset($ctx['clients'])) . '</div>';
    } else {
        $opts = '<option value=""' . ($ctx['client_id'] === 0 ? ' selected' : '') . ' disabled>Choose a department...</option>';
        foreach ($ctx['clients'] as $id => $nm) {
            $opts .= '<option value="' . (int) $id . '"' . ($id === $ctx['client_id'] ? ' selected' : '') . '>' . $h($nm) . '</option>';
        }
        $clientField = '<select class="form-select" id="ad-client" name="client_id" required>' . $opts . '</select>';
    }
    $locOpts = '<option value="0">No specific location</option>';
    foreach ($ctx['locations'] as $l) {
        $locOpts .= '<option value="' . (int) $l['id'] . '">' . $h($l['name']) . '</option>';
    }
    $problems = '';
    foreach ($ctx['problems'] as $p) {
        $problems .= '<div class="alert alert-warning py-2 mb-2" role="alert">' . $h($p) . ($admin ? ' <a href="/admin/settings_endpoint_agent.php">Open Endpoint agent settings</a>.' : ' Ask an administrator to fix it.') . '</div>';
    }
    $plat = static fn (string $v, string $id, string $icon, string $label, bool $checked): string => '<input type="radio" class="btn-check" name="platform" id="' . $id . '" value="' . $v . '"' . ($checked ? ' checked' : '') . ' autocomplete="off">'
        . '<label class="btn btn-outline-primary" for="' . $id . '"><i class="' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</label>';

    $mesh = '';
    if (!empty($ctx['mesh']['show'])) {
        $mesh = '<div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="ad-mesh" name="install_mesh_agent" value="1" aria-describedby="ad-mesh-help"><label class="form-check-label" for="ad-mesh">Also install the MeshCentral agent (after the device is approved)</label></div>'
            . '<div class="form-text" id="ad-mesh-help">' . ($ctx['mesh']['ready'] ? ('The Mesh agent installs after you approve the device: once it is linked, ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' pushes it from your own Mesh server. If that fails, the ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' agent is still installed.') : $h('Not ready yet: ' . $ctx['mesh']['hint'])) . '</div></div>';
    }

    // RivetCore 1.0.0-rc.26: tick-boxes for the catalog apps to install with the agent. js/rmm_add_device.js refills this (action=selectable_apps) on every department/location/platform change.
    $apps = '<div class="col-12" id="ad-apps-col"><span class="form-label d-block">Install with the agent</span><div id="ad-apps-list">' . rivetRmmAutoInstSelectableHtml($ctx['apps'], 'ad') . '</div>'
        . ('<div class="form-text">These apps install right after the ' . htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' agent, on the devices this installer enrolls. The same ticks apply to every button below (.exe, .msi, the commands and the Linux command).</div></div>');

    $o = '<div class="card" id="ad-root" data-post-url="/agent/post/rmm_installer.php" data-csrf="' . $h($ctx['csrf']) . '" data-ttl-max="' . (int) $ctx['ttl_max'] . '" data-fixed="' . ($ctx['fixed'] ? '1' : '0') . '"'
        . ' data-have-exe-amd64="' . ($b['amd64'] !== null ? '1' : '0') . '" data-have-exe-arm64="' . ($b['arm64'] !== null ? '1' : '0') . '" data-have-msi-amd64="' . (($msi['amd64'] ?? null) !== null ? '1' : '0') . '" data-have-msi-arm64="' . (($msi['arm64'] ?? null) !== null ? '1' : '0') . '"'
        . ' data-service-ok="' . ($ctx['problems'] === [] ? '1' : '0') . '" data-admin="' . ($admin ? '1' : '0') . '" data-have-linux="' . ($linux['items'] !== [] || ($linux['hosted']['rows'] ?? []) !== [] ? '1' : '0') . '"'
        . ' data-have-linux-amd64="' . (($linux['hosted']['amd64'] ?? null) !== null || rivetLxRecordedFor($linux, 'amd64') ? '1' : '0') . '" data-have-linux-arm64="' . (($linux['hosted']['arm64'] ?? null) !== null || rivetLxRecordedFor($linux, 'arm64') ? '1' : '0') . '">'
        . '<div class="card-header py-3"><h3 class="card-title mb-0"><i class="fas fa-fw fa-download me-2" aria-hidden="true"></i>Add device / Build installer</h3></div><div class="card-body">'
        . '<p class="text-muted">Pick the department and the kind of machine. Everything you copy or download already knows this server and the department, so nothing is typed on the machine. '
        . 'Every button below creates its own enrollment token (audited, shown once); the Advanced section sets how long it works and for how many machines.</p>' . $problems
        . '<form id="ad-form" method="post" action="/agent/post/rmm_installer.php" autocomplete="off" novalidate><input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '">'
        . '<div class="row g-3">'
        . '<div class="col-md-5"><label class="form-label" for="ad-client">Department <span class="text-danger" aria-hidden="true">*</span></label>' . $clientField . '</div>'
        . '<div class="col-md-4' . ($ctx['locations'] === [] ? ' d-none' : '') . '" id="ad-location-col"><label class="form-label" for="ad-location">Location <span class="text-muted">(optional)</span></label><select class="form-select" id="ad-location" name="location_id">' . $locOpts . '</select></div>'
        . '<div class="col-md-3"><label class="form-label" for="ad-ring">Update ring</label><select class="form-select" id="ad-ring" name="ring"><option value="stable" selected>Stable</option><option value="pilot">Pilot</option></select></div>'
        . '<div class="col-md-4"><label class="form-label" for="ad-device-type">Device type</label><select class="form-select" id="ad-device-type" name="device_type">'
        . '<option value="auto" selected>Detect automatically</option><option value="Laptop">Laptop</option><option value="Desktop">Desktop</option><option value="Server">Server</option>'
        . '<option value="Virtual Machine">Virtual machine</option><option value="Hypervisor">Hypervisor</option></select></div>'
        . '<div class="col-12"><span class="form-label d-block" id="ad-platform-label">Platform</span><div class="btn-group flex-wrap" role="group" aria-labelledby="ad-platform-label">'
        . $plat('windows-amd64', 'ad-p-wx64', 'fab fa-windows', 'Windows x64', true) . $plat('windows-arm64', 'ad-p-warm', 'fab fa-windows', 'Windows ARM64', false)
        . $plat('linux-amd64', 'ad-p-lx64', 'fab fa-linux', 'Linux x64', false) . $plat('linux-arm64', 'ad-p-larm', 'fab fa-linux', 'Linux ARM64', false) . '</div></div>'
        . $mesh
        . $apps
        . '<div class="col-12"><details id="ad-adv"><summary class="small fw-bold">Advanced: how long the token works, how many machines</summary><div class="row g-3 pt-2">'
        . '<div class="col-md-4"><label class="form-label" for="ad-ttl">Token lifetime (hours)</label><input class="form-control" type="number" id="ad-ttl" name="ttl_hours" min="1" max="' . (int) $ctx['ttl_max'] . '" value="' . (int) min(24, $ctx['ttl_max']) . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="ad-uses">Machines it may enroll</label><input class="form-control" type="number" id="ad-uses" name="max_uses" min="1" max="5000" value="1"></div>'
        . '<div class="col-md-4"><label class="form-label" for="ad-label">Label <span class="text-muted">(optional)</span></label><input class="form-control" type="text" id="ad-label" name="label" maxlength="100"></div>'
        . '</div></details></div></div></form>'
        . '<div class="alert alert-danger mt-3 mb-0 d-none" id="ad-error" role="alert"></div>'
        . '<div class="alert alert-success mt-3 mb-0 d-none" id="ad-done" role="status"></div>'

        // ---- Windows
        . '<section class="mt-4" id="ad-windows" aria-labelledby="ad-windows-h"><h4 class="h5" id="ad-windows-h"><i class="fab fa-windows me-2" aria-hidden="true"></i>Windows</h4>'
        . '<div class="alert alert-warning d-none" id="ad-w-missing" role="alert"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><span id="ad-w-missing-text">No agent program is published for this architecture yet.</span>' . $feedLink . '</div>'
        . '<div class="row g-3"><div class="col-lg-6"><div class="border rounded p-3 h-100"><h5 class="h6">Installer (.exe)</h5><p class="small text-muted">The proven path. Double-click it on the PC and accept the administrator prompt; it installs and enrolls itself.</p>'
        . '<button type="button" class="btn btn-primary" id="ad-dl-exe"><i class="fas fa-download me-1" aria-hidden="true"></i>Download .exe installer</button></div></div>'
        . '<div class="col-lg-6"><div class="border rounded p-3 h-100"><h5 class="h6">MSI (.msi)</h5><p class="small text-muted">For Intune, Group Policy or any tool that installs .msi packages. A copy of the MSI template with this department\'s enrollment embedded. <strong>Unsigned and not yet verified on Windows:</strong> try it on one PC first.</p>'
        . '<button type="button" class="btn btn-outline-primary" id="ad-dl-msi"><i class="fas fa-download me-1" aria-hidden="true"></i>Download .msi installer</button>'
        . '<div class="small text-muted mt-2 d-none" id="ad-msi-missing">' . $h('No MSI template is published for this architecture (the build makes x64 only).') . $feedLink . '</div></div></div>'
        . '<div class="col-12"><div class="border rounded p-3"><h5 class="h6">Silent install commands (Intune, GPO, your RMM)</h5><p class="small text-muted">Creates a token and prints ready-to-paste commands. The commands download the stamped installer from this server with the token, so run them as SYSTEM or an administrator.</p>'
        . '<button type="button" class="btn btn-outline-primary" id="ad-w-cmds"><i class="fas fa-terminal me-1" aria-hidden="true"></i>Show silent-install commands</button>'
        . '<div class="mt-3 d-none" id="ad-w-out">'
        . rivetRmmAddDeviceBlock('ad-c-ps', 'PowerShell: .exe installer', 12)
        . '<div id="ad-w-msi-out" class="d-none">' . rivetRmmAddDeviceBlock('ad-c-msiexec', 'msiexec: the stamped .msi you downloaded', 2) . rivetRmmAddDeviceBlock('ad-c-msips', 'PowerShell: download the stamped .msi and install it', 14)
        . rivetRmmAddDeviceBlock('ad-c-props', 'msiexec: the unstamped template with the server and token as properties', 3) . rivetRmmAddDeviceBlock('ad-c-uninst', 'Uninstall the MSI', 4) . '</div>'
        . '<p class="small text-muted mb-0" id="ad-w-meta"></p></div></div></div></div></section>'

        // ---- Linux
        . '<section class="mt-4 d-none" id="ad-linux" aria-labelledby="ad-linux-h"><h4 class="h5" id="ad-linux-h"><i class="fab fa-linux me-2" aria-hidden="true"></i>Linux (systemd)</h4>'
        . '<div class="alert alert-warning d-none" id="ad-l-missing" role="alert"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i><span id="ad-l-missing-text">This server has no Linux install files for this CPU yet, so there is no command to give. Build or publish the agent first: Agent updates.</span>' . $feedLink . '</div>'
        . '<div class="border rounded p-3 mb-3"><h5 class="h6">One command, run as root</h5><p class="small text-muted" id="ad-l-help">Creates a token and prints a command that downloads <code>install-linux.sh</code> and the agent from this server, checks both against the SHA-256 values printed in the command, and installs. Nothing to upload or unpack; the machine only needs to reach this server (curl or wget).</p>'
        . '<button type="button" class="btn btn-primary" id="ad-l-go"><i class="fas fa-terminal me-1" aria-hidden="true"></i>Create install command</button>'
        . '<div class="mt-3 d-none" id="ad-l-out">' . rivetRmmAddDeviceBlock('ad-l-cmd', 'Run this as root on the Linux machine', 16) . '<p class="small text-muted mb-0" id="ad-l-meta"></p></div></div>'
        . '<div class="border rounded p-3"><h5 class="h6">Packages and files <span class="text-muted fw-normal" id="ad-l-ver"></span></h5>'
        . rivetRmmAddDeviceLinuxPackages($linux)
        . '</div></section>'

        // ---- what the server has
        . '<section class="mt-4" aria-labelledby="ad-have-h"><h4 class="h6" id="ad-have-h">What this server can build right now</h4><ul class="list-group list-group-flush border rounded small" id="ad-have">'
        . $chip($b['amd64'] !== null, 'Windows x64 agent (.exe installer)', $h((string) $b['amd64'])) . $chip($b['arm64'] !== null, 'Windows ARM64 agent (.exe installer)', $h((string) $b['arm64']))
        . $chip(($msi['amd64'] ?? null) !== null, 'Windows x64 MSI template', $h((string) ($msi['amd64'] ?? ''))) . $chip($linux['version'] !== null || ($linux['hosted']['rows'] ?? []) !== [], 'Linux install script, agent and packages', $h((string) ($linux['hosted']['amd64'] ?? $linux['hosted']['arm64'] ?? $linux['version'])))
        . '</ul>' . ($admin ? '<p class="small mt-2 mb-0">Need a newer version? <a href="' . $h(RIVET_RMM_AGENT_UPDATES_URL) . '">Agent updates</a> lists the releases on GitHub and publishes one with a click.</p>' : '')
        . '</section></div></div>';

    return $o;
}

/** A read-only text block with a Copy button. */
function rivetRmmAddDeviceBlock(string $id, string $title, int $rows): string
{
    $h = 'rivetRmmInstH';

    return '<div class="mb-3"><label class="form-label small fw-semibold" for="' . $id . '">' . $h($title) . '</label>'
        . '<textarea class="form-control font-monospace small" id="' . $id . '" rows="' . $rows . '" readonly spellcheck="false"></textarea>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary mt-1" data-ad-copy="' . $id . '"><i class="fas fa-copy me-1" aria-hidden="true"></i><span>Copy</span></button></div>';
}

/**
 * The table of recorded Linux files, one block per architecture (the page shows the block of the chosen architecture).
 *
 * @param array{version:?string,items:list<array<string,mixed>>} $linux
 */
function rivetRmmAddDeviceLinuxPackages(array $linux): string
{
    $h = 'rivetRmmInstH';
    $hosted = '';
    if (($linux['hosted']['rows'] ?? []) !== []) {
        $hosted = '<div class="small fw-semibold mb-1">Hosted on this server (the install command downloads these)</div><div class="table-responsive"><table class="table table-sm align-middle mb-3"><caption class="visually-hidden">Linux files hosted on this server</caption>'
            . '<thead><tr><th scope="col">File</th><th scope="col">Version</th><th scope="col">Size</th><th scope="col">SHA-256</th></tr></thead><tbody>';
        foreach ($linux['hosted']['rows'] as $r) {
            $hosted .= '<tr><td class="font-monospace small">' . $h($r['name']) . '</td><td>' . $h($r['version']) . '</td><td class="text-nowrap">' . $h(\RivetCore\Rmm\Binaries\BinaryStore::human((int) $r['size'])) . '</td><td><code class="small text-break">' . $h($r['sha256']) . '</code></td></tr>';
        }
        $hosted .= '</tbody></table></div>';
    }
    if ($linux['items'] === []) {
        if ($hosted !== '') {
            return $hosted;
        }
        return '<p class="small text-muted mb-0">Nothing recorded yet. After an administrator publishes a release under Agent updates, the install script, the agent, the tarball, the .deb and the .rpm are listed here with their SHA-256.</p>';
    }
    $install = ['deb' => 'sudo apt install ./%s', 'rpm' => 'sudo dnf install ./%s', 'tarball' => 'tar xzf %s'];
    $o = '';
    foreach (['amd64' => 'x64 (amd64)', 'arm64' => 'ARM64 (aarch64)'] as $arch => $name) {
        $pk = rivetAgentFeedLinuxPackages($linux, $arch);
        $o .= '<div data-ad-pkgs="' . $arch . '" class="' . ($arch === 'amd64' ? '' : 'd-none') . '"><div class="small fw-semibold mb-1">' . $h($name) . '</div><div class="table-responsive"><table class="table table-sm align-middle mb-3"><caption class="visually-hidden">Linux files for ' . $h($name) . '</caption>'
            . '<thead><tr><th scope="col">File</th><th scope="col">Size</th><th scope="col">SHA-256</th><th scope="col"></th></tr></thead><tbody>';
        foreach ($pk as $p) {
            $url = rivetAgentFeedUrlIsSafe($p['url']) ? $p['url'] : '';
            $o .= '<tr><td>' . $h($p['label']) . '<div class="small text-muted">' . ($url !== '' ? '<a href="' . $h($url) . '" rel="noopener noreferrer" target="_blank">' . $h($p['filename']) . '</a>' : $h($p['filename'])) . '</div>'
                . (isset($install[$p['kind']]) ? '<div class="small font-monospace">' . $h(sprintf($install[$p['kind']], $p['filename'])) . '</div>' : '') . '</td>'
                . '<td class="text-nowrap">' . $h(\RivetCore\Rmm\Binaries\BinaryStore::human($p['size'])) . '</td>'
                . '<td><code class="small text-break">' . $h($p['sha256']) . '</code></td>'
                . '<td class="text-nowrap">' . ($url !== '' ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-ad-copy-value="' . $h($url) . '"><i class="fas fa-link me-1" aria-hidden="true"></i><span>Copy link</span></button>' : '') . '</td></tr>';
        }
        $o .= ($pk === [] ? '<tr><td colspan="4" class="text-muted">No file recorded for this architecture.</td></tr>' : '') . '</tbody></table></div></div>';
    }

    return $hosted . $o . '<p class="small text-muted mb-0">A package is installed from the downloaded file. Compare its SHA-256 first: <code>sha256sum -c</code>, or <code>sha256sum &lt;file&gt;</code> against the value above.</p>';
}

/** Does the list of recorded (GitHub) Linux files hold both the script and the agent for this CPU? */
function rivetLxRecordedFor(array $linux, string $arch): bool
{
    $k = [];
    foreach ($linux['items'] as $i) {
        if (($i['arch'] === $arch || $i['arch'] === '') && in_array($i['kind'], ['script', 'binary'], true)) {
            $k[$i['kind']] = true;
        }
    }

    return count($k) === 2;
}
