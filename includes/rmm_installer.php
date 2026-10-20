<?php

/*
 * "Add device" / "Download installer": the easy way to hand a PC the RivetIT endpoint agent, from the Agent Fleet page, a department's page and the
 * Endpoints menu. One modal (rivetRmmInstallerModal), one endpoint (agent/post/rmm_installer.php), one script (js/rmm_installer.js).
 *
 * Nothing here decides anything about security. Who may issue an installer, the audit entry, the enrollment token, the stamped file and every
 * refusal are RivetCore\Rmm\Admin\RmmAdmin::downloadInstaller() / deploymentCommands() (rivet/rivet-core), the same code the Administration card
 * calls. This file only (1) asks whether to SHOW the control (module on, and rmm.token.manage or rmm.admin), (2) collects what the modal needs to
 * render (the user's departments, the current binaries, the service problems), and (3) renders it. With the module off, or for a user who may not
 * issue installers, rivetRmmInstallerContext() is null and nothing is rendered.
 */

require_once __DIR__ . '/rmm_bootstrap.php';
require_once __DIR__ . '/rmm_auto_install_ui.php';

use RivetCore\Rmm\Authz\RmmAbility;

/** The Administration anchor of the binaries card (where an administrator publishes the agent). */
const RIVET_RMM_BINARIES_URL = '/admin/settings_endpoint_agent.php#binaries';

/** The full "Add device / Build installer" page (RivetIT 26.10.52): .exe, .msi, the silent-install commands, the Linux command and the Linux packages. */
const RIVET_RMM_ADD_DEVICE_URL = '/agent/rmm_add_device.php';

/** The Administration anchor of the Agent updates card (where an administrator publishes a release from GitHub). */
const RIVET_RMM_AGENT_UPDATES_URL = '/admin/settings_endpoint_agent.php#agent-updates';

/** The Administration anchor of the MSI template card (RivetCore 1.0.0-rc.16). */
const RIVET_RMM_MSI_URL = '/admin/settings_endpoint_agent.php#msi-templates';

/**
 * May this user issue installers (for one department, or anywhere when $clientId is 0)? The module must be on. rmm.token.manage or rmm.admin;
 * the Core call repeats the per-department check, so this is only the decision to show or accept the control.
 */
function rivetRmmInstallerAllowed($mysqli, int $userId, int $clientId = 0): bool
{
    static $memo = [];
    $key = spl_object_id($mysqli) . '|' . $userId . '|' . $clientId;
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $memo[$key] = false;
    if ($userId <= 0 || !rivetRmmEnabled($mysqli)) {
        return false;
    }
    try {
        $authz = rivetRmmModule($mysqli)->authorizer();
        $memo[$key] = $authz->allowed($userId, RmmAbility::TOKEN_MANAGE, $clientId) || $authz->allowed($userId, RmmAbility::ADMIN, 0);
    } catch (\Throwable) {
        $memo[$key] = false;
    }

    return $memo[$key];
}

/**
 * Why this viewer can or cannot create installers, in words (RivetIT 26.10.54). The entry is never hidden: when the answer is no, the Endpoints pages print
 * `message` naming the missing permission or setting instead of silently leaving the button out.
 *
 * @return array{allowed:bool,code:string,admin:bool,message:string}  code: ok | module_off | no_right
 */
function rivetRmmInstallerGate($mysqli, int $userId): array
{
    if ($userId > 0 && rivetRmmInstallerAllowed($mysqli, $userId, 0)) {
        return ['allowed' => true, 'code' => 'ok', 'admin' => rivetRmmInstallerIsAdmin($mysqli, $userId), 'message' => ''];
    }
    $admin = $userId > 0 && rivetRmmInstallerIsAdmin($mysqli, $userId);
    if (!rivetRmmEnabled($mysqli)) {
        return ['allowed' => false, 'code' => 'module_off', 'admin' => $admin, 'message' => 'Installers cannot be created because the Endpoint agent (RMM) module is switched off.'
            . ($admin ? ' <a href="/admin/settings_endpoint_agent.php">Switch it on under Administration &gt; Endpoint agent</a>.' : ' An administrator can switch it on under Administration &gt; Endpoint agent.')];
    }

    return ['allowed' => false, 'code' => 'no_right', 'admin' => $admin, 'message' => 'You cannot create installers: your role does not have the &quot;RMM token manage&quot; permission (<code>rmm.token.manage</code>).'
        . ' Ask an administrator to add it to your role.'];
}

/** Is this user an RMM administrator (rmm.admin)? Only used to decide whether a message may link into Administration. */
function rivetRmmInstallerIsAdmin($mysqli, int $userId): bool
{
    try {
        return rivetRmmModule($mysqli)->authorizer()->allowed($userId, RmmAbility::ADMIN, 0);
    } catch (\Throwable) {
        return false;
    }
}

/**
 * The primary "Create installer" button for the header of the RMM dashboard and the Agent fleet page. It is always drawn: without the right it is a muted button
 * that jumps to the explanation printed under the header (rivetRmmInstallerNotice()).
 *
 * @param array{allowed:bool} $gate rivetRmmInstallerGate()
 */
function rivetRmmInstallerCreateButton(array $gate): string
{
    if ($gate['allowed']) {
        return '<a href="' . RIVET_RMM_ADD_DEVICE_URL . '" class="btn btn-primary btn-sm" id="rmm-build-installer"><i class="fas fa-tools me-1" aria-hidden="true"></i>Create installer</a>';
    }

    return '<a href="#rmm-installer-notice" class="btn btn-outline-secondary btn-sm" id="rmm-build-installer" data-installer-blocked="' . rivetRmmInstH($gate['code']) . '"><i class="fas fa-lock me-1" aria-hidden="true"></i>Create installer</a>';
}

/**
 * The line(s) under the header: why the button does not work for this viewer, or (when it does) what the server cannot build yet because nothing is published.
 * Empty when there is nothing to say.
 *
 * @param array{allowed:bool,code:string,admin:bool,message:string} $gate rivetRmmInstallerGate()
 * @param array<string,mixed>|null $ctx rivetRmmInstallerContext() when the caller already has it (saves the queries)
 */
function rivetRmmInstallerNotice(array $gate, ?array $ctx): string
{
    if (!$gate['allowed']) {
        return '<div class="alert alert-warning py-2 mb-3" role="alert" id="rmm-installer-notice" data-installer-code="' . rivetRmmInstH($gate['code']) . '"><i class="fas fa-lock me-1" aria-hidden="true"></i>' . $gate['message'] . '</div>';
    }
    if ($ctx === null) {
        return '';
    }
    $b = $ctx['binaries'];
    $link = !empty($ctx['admin']) ? ' <a href="' . RIVET_RMM_AGENT_UPDATES_URL . '">Publish one under Administration &gt; Endpoint agent &gt; Agent updates</a>.' : ' Ask an administrator to publish one.';
    if ($b['amd64'] === null && $b['arm64'] === null) {
        return '<div class="alert alert-warning py-2 mb-3" role="alert" id="rmm-installer-notice" data-installer-code="no_agent"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
            . 'No Windows agent program is published on this server yet, so a Windows installer cannot be built.' . $link . '</div>';
    }
    if (($ctx['msi']['amd64'] ?? null) === null && ($ctx['msi']['arm64'] ?? null) === null) {
        return '<div class="small text-muted mb-3" id="rmm-installer-notice" data-installer-code="no_msi"><i class="fas fa-info-circle me-1" aria-hidden="true"></i>'
            . 'No MSI template is published, so only the .exe installer is available (Intune and Group Policy want the .msi).'
            . (!empty($ctx['admin']) ? ' <a href="' . RIVET_RMM_AGENT_UPDATES_URL . '">Agent updates</a> publishes it.' : ' Ask an administrator.') . '</div>';
    }

    return '';
}

/**
 * Locations (sites) of one department for the optional location select.
 *
 * @return list<array{id:int,name:string}>
 */
function rivetRmmInstallerLocations(\mysqli $mysqli, int $clientId): array
{
    $out = [];
    if ($clientId <= 0) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT location_id, location_name FROM locations WHERE location_client_id = ' . $clientId . ' AND location_archived_at IS NULL ORDER BY location_name LIMIT 500');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[] = ['id' => (int) $row['location_id'], 'name' => (string) $row['location_name']];
    }

    return $out;
}

/**
 * The optional "Also install the MeshCentral agent" tick-box (RivetCore 1.0.0-rc.12). It is offered only while MeshCentral is switched on and has an https address
 * (the settings an administrator made on purpose); everything else (the device group id, the https check) is Core's call when the box is ticked, and its refusal text
 * is shown as it is. `ready` is Core's own answer now, used only to tell the person up front what is still missing.
 *
 * @param array<string,mixed> $cfg RmmReadModel::settingsSummary()
 * @return array{show:bool,ready:bool,hint:string}
 */
function rivetRmmInstallerMesh(\RivetCore\Rmm\RmmModule $rmm, array $cfg): array
{
    $off = ['show' => false, 'ready' => false, 'hint' => ''];
    if ((int) ($cfg['mesh_enabled'] ?? 0) !== 1 || trim((string) ($cfg['mesh_url'] ?? '')) === '') {
        return $off;
    }
    try {
        $why = $rmm->meshAgents()->installRefusal();   // reads the 0023 tables: a database that has not been updated yet simply does not offer the box
    } catch (\Throwable) {
        return $off;
    }

    return ['show' => true, 'ready' => $why === null, 'hint' => $why ?? ''];
}

/**
 * The version of the current, active MSI template per architecture (RivetCore 1.0.0-rc.16), or null where none is published. An install that has not applied
 * migration 0025 has no such table: Core answers an empty list, so the format selector simply has no MSI to offer.
 *
 * @return array{amd64:?string,arm64:?string}
 */
function rivetRmmInstallerMsi(\RivetCore\Rmm\Read\RmmReadModel $read): array
{
    $out = ['amd64' => null, 'arm64' => null];
    try {
        foreach ($read->msiTemplates(200) as $t) {
            if ($t['is_current'] && $t['active'] && array_key_exists($t['arch'], $out) && $out[$t['arch']] === null) {
                $out[$t['arch']] = (string) $t['version'];
            }
        }
    } catch (\Throwable) {
        // no templates
    }

    return $out;
}

/**
 * What the modal needs, or null when it must not render at all (module off / not allowed).
 *
 * @param int|null $fixedClientId the department's own page: the select is replaced by that department
 * @param int      $preselect     the department to select on a page with the full list (0 = none, unless the user has exactly one)
 * @return array{clients:array<int,string>,client_id:int,fixed:bool,locations:list<array{id:int,name:string}>,binaries:array{amd64:?string,arm64:?string},msi:array{amd64:?string,arm64:?string},problems:list<string>,ttl_max:int,csrf:string,admin:bool,mesh:array{show:bool,ready:bool,hint:string}}|null
 */
function rivetRmmInstallerContext(\mysqli $mysqli, int $userId, ?int $fixedClientId = null, int $preselect = 0): ?array
{
    $scope = $fixedClientId !== null ? (int) $fixedClientId : 0;
    if (!rivetRmmInstallerAllowed($mysqli, $userId, $scope)) {
        return null;
    }
    try {
        $rmm = rivetRmmModule($mysqli);
        $authz = $rmm->authorizer();
        $read = $rmm->readModel();
        $cfg = $read->settingsSummary();
        $cur = $read->currentBinaries();
        $msi = rivetRmmInstallerMsi($read);
        $isAdmin = $authz->allowed($userId, RmmAbility::ADMIN, 0);
        $visible = $authz->visibleClientIds($userId);   // null = every department
    } catch (\Throwable) {
        return null;
    }

    $clients = [];
    if ($fixedClientId !== null) {
        $names = rivetRmmUiClientNamesForInstaller($mysqli, [(int) $fixedClientId]);
        if (!isset($names[(int) $fixedClientId]) || ($visible !== null && !in_array((int) $fixedClientId, $visible, true))) {
            return null;
        }
        $clients = $names;
    } else {
        $where = 'client_archived_at IS NULL';
        if ($visible !== null) {
            if ($visible === []) {
                return null;
            }
            $where .= ' AND client_id IN (' . implode(',', array_map('intval', $visible)) . ')';
        }
        $r = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE $where ORDER BY client_name LIMIT 2000");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $clients[(int) $row['client_id']] = (string) $row['client_name'];
        }
    }

    $selected = $fixedClientId !== null ? (int) $fixedClientId : ($preselect > 0 && isset($clients[$preselect]) ? $preselect : (count($clients) === 1 ? (int) array_key_first($clients) : 0));

    $problems = [];
    if (!$cfg['enabled']) {
        $problems[] = 'The endpoint agent service is switched off.';
    }
    if ($cfg['service_base'] === null) {
        $problems[] = 'The service URL is not an https:// address.';
    }

    return [
        'clients' => $clients,
        'client_id' => $selected,
        'fixed' => $fixedClientId !== null,
        'locations' => rivetRmmInstallerLocations($mysqli, $selected),
        'msi' => $msi,
        'binaries' => ['amd64' => is_array($cur['amd64']) ? (string) ($cur['amd64']['version'] ?? '') : null, 'arm64' => is_array($cur['arm64']) ? (string) ($cur['arm64']['version'] ?? '') : null],
        'problems' => $problems,
        'ttl_max' => max(1, (int) ($cfg['enroll_max_ttl_h'] ?? 720)),
        'csrf' => (string) ($_SESSION['csrf_token'] ?? ''),
        'admin' => $isAdmin,
        'mesh' => rivetRmmInstallerMesh($rmm, $cfg),
        // RivetCore 1.0.0-rc.26 (install apps with the agent): the tick-boxes of the installer page, for the selected department/location/Windows. Empty unless a
        // department is already chosen (the modal's own client select refills this by AJAX, agent/post/rmm_installer.php?action=selectable_apps, on every change).
        'apps' => $selected > 0 ? rivetRmmAutoInstSelectable($mysqli, $userId, (string) ($GLOBALS['session_name'] ?? ''), $selected, 0, 'windows') : ['enabled' => false, 'apps' => []],
    ];
}

/** @param list<int> $ids @return array<int,string> */
function rivetRmmUiClientNamesForInstaller(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL AND client_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['client_id']] = (string) $row['client_name'];
    }

    return $out;
}

function rivetRmmInstH($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * The control that opens the modal.
 *
 * @param string $variant 'button' (a filled primary button), 'item' (a dropdown item)
 */
function rivetRmmInstallerTrigger(string $variant = 'button', int $clientId = 0, string $label = 'Download installer', string $class = 'btn btn-primary'): string
{
    $data = 'data-bs-toggle="modal" data-bs-target="#rmmInstallerModal"' . ($clientId > 0 ? ' data-rmm-installer-client="' . $clientId . '"' : '');
    if ($variant === 'item') {
        return '<a class="dropdown-item" href="#" ' . $data . ' data-rmm-installer-open><i class="fas fa-fw fa-download me-2" aria-hidden="true"></i>' . rivetRmmInstH($label) . '</a>';
    }

    return '<button type="button" class="' . rivetRmmInstH($class) . '" ' . $data . ' data-rmm-installer-open><i class="fas fa-download me-1" aria-hidden="true"></i>' . rivetRmmInstH($label) . '</button>';
}

/** The modal. Rendered once per page; the script (js/rmm_installer.js) drives it. */
function rivetRmmInstallerModal(array $ctx): string
{
    $h = 'rivetRmmInstH';
    $b = $ctx['binaries'];
    $lead = static fn (string $t): string => '<div class="form-text">' . $t . '</div>';

    // client
    if ($ctx['fixed']) {
        $name = (string) reset($ctx['clients']);
        $clientField = '<label class="form-label" for="rmm-inst-client">Department</label><input type="hidden" name="client_id" id="rmm-inst-client" value="' . (int) $ctx['client_id'] . '">'
            . '<div class="form-control-plaintext fw-bold">' . $h($name) . '</div>';
    } else {
        $opts = '<option value=""' . ($ctx['client_id'] === 0 ? ' selected' : '') . ' disabled>Choose a department...</option>';
        foreach ($ctx['clients'] as $id => $nm) {
            $opts .= '<option value="' . (int) $id . '"' . ($id === $ctx['client_id'] ? ' selected' : '') . '>' . $h($nm) . '</option>';
        }
        $clientField = '<label class="form-label" for="rmm-inst-client">Department <span class="text-danger" aria-hidden="true">*</span></label><select class="form-select" id="rmm-inst-client" name="client_id" required>' . $opts . '</select>';
    }
    $locOpts = '<option value="0">No specific location</option>';
    foreach ($ctx['locations'] as $l) {
        $locOpts .= '<option value="' . (int) $l['id'] . '">' . $h($l['name']) . '</option>';
    }

    $problem = '';
    foreach ($ctx['problems'] as $p) {
        $problem .= '<div class="alert alert-warning py-2 mb-2" role="alert">' . $h($p) . ($ctx['admin'] ? ' <a href="/admin/settings_endpoint_agent.php">Open Endpoint agent settings</a>.' : ' Ask an administrator to fix it.') . '</div>';
    }

    // The optional MeshCentral agent (rc.12): off by default, rendered only while MeshCentral is on and has an address. The help text is plain; a refusal from Core is shown in the error box.
    $meshField = '';
    if (!empty($ctx['mesh']['show'])) {
        $meshHint = $ctx['mesh']['ready']
            ? $h('The Mesh agent installs after you approve the device: once it is linked, RivetIT pushes it from your own Mesh server and device group, so Remote works without pasting a node id. A Mesh failure never affects the RivetIT agent.')
            : $h('Not ready yet: ' . $ctx['mesh']['hint']) . ($ctx['admin'] ? ' <a href="/admin/settings_endpoint_agent.php#mesh">Open the MeshCentral settings</a>.' : ' Ask an administrator.');
        $meshField = '<div class="col-12" id="rmm-inst-mesh-col"><div class="form-check"><input class="form-check-input" type="checkbox" id="rmm-inst-mesh" name="install_mesh_agent" value="1" aria-describedby="rmm-inst-mesh-help">'
            . '<label class="form-check-label" for="rmm-inst-mesh">Also install the MeshCentral agent (after the device is approved)</label></div>'
            . '<div class="form-text" id="rmm-inst-mesh-help" data-ready="' . ($ctx['mesh']['ready'] ? '1' : '0') . '">' . $meshHint . '</div></div>';
    }

    // RivetCore 1.0.0-rc.26: tick-boxes for the catalog apps to install with the agent (pre-ticked by policy, blocked when a vendor parameter has no value). Refilled by AJAX
    // (js/rmm_installer.js, action=selectable_apps) whenever the department, location or OS changes; the checked ids ride in the form as app_ids[] like any other field.
    $appsField = '<div class="col-12' . ($ctx['apps']['apps'] === [] && $ctx['apps']['enabled'] === false ? ' d-none' : '') . '" id="rmm-inst-apps-col"><span class="form-label d-block">Install with the agent</span>'
        . '<div id="rmm-inst-apps-list">' . rivetRmmAutoInstSelectableHtml($ctx['apps'], 'rmm-inst') . '</div>'
        . '<div class="form-text" id="rmm-inst-apps-note">These apps install right after the RivetIT agent, on the devices this installer enrolls.</div></div>';

    $msiWarn = '<div class="alert alert-warning mb-3 d-none" id="rmm-inst-msi-warn" role="note"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
        . '<strong>Not yet verified on Windows.</strong> The MSI installer is unsigned (SmartScreen may warn) and has not been run on a real Windows PC yet: try it on one PC before a roll-out. The .exe installer is the proven path.</div>';
    $none = '<div class="alert alert-warning mb-3 d-none" id="rmm-inst-nobinary" role="alert"><i class="fas fa-exclamation-triangle me-1" aria-hidden="true"></i>'
        . '<span id="rmm-inst-nobinary-text">No agent program is published for this architecture yet, so an installer cannot be built.</span> '
        . ($ctx['admin'] ? '<a href="' . $h(RIVET_RMM_BINARIES_URL) . '" id="rmm-inst-nobinary-link">Publish it under Administration &gt; Endpoint agent &gt; Agent binaries</a>.' : 'Ask an administrator to publish it.') . '</div>';

    $arch = static fn (string $v, string $id, string $label, bool $checked): string => '<input type="radio" class="btn-check" name="arch" id="' . $id . '" value="' . $v . '"' . ($checked ? ' checked' : '') . ' autocomplete="off">'
        . '<label class="btn btn-outline-primary btn-sm" for="' . $id . '">' . $label . '</label>';

    $fmt = static fn (string $v, string $id, string $label, bool $checked): string => '<input type="radio" class="btn-check" name="format" id="' . $id . '" value="' . $v . '"' . ($checked ? ' checked' : '') . ' autocomplete="off" aria-describedby="rmm-inst-format-help">'
        . '<label class="btn btn-outline-primary btn-sm" for="' . $id . '">' . $label . '</label>';

    $steps = '<ol class="mb-2 ps-3" id="rmm-inst-steps-list"><li>Copy the downloaded file to the PC.</li><li>Double-click it and accept the Windows administrator prompt.</li>'
        . '<li>It installs and enrolls itself. The PC shows up in Agent Fleet within a few minutes.</li></ol>'
        . '<p class="mb-0 small text-muted">For a deployment tool (Intune, GPO, your RMM) run <code>' . '<span id="rmm-inst-silent-exe"><span id="rmm-inst-silent-name">RivetIT-Agent-Setup-department-x64.exe</span> setup --silent</span><span id="rmm-inst-silent-msi" class="d-none">msiexec /i "<span id="rmm-inst-silent-name-msi">RivetIT-Agent-department-x64.msi</span>" /qn /norestart</span>' . '</code> as SYSTEM or an administrator. Or choose <em>Several PCs</em> and use the same file on each.</p>';

    return '<div class="modal fade" id="rmmInstallerModal" tabindex="-1" aria-labelledby="rmmInstallerTitle" aria-hidden="true"'
        . ' data-post-url="/agent/post/rmm_installer.php" data-csrf="' . $h($ctx['csrf']) . '" data-ttl-max="' . (int) $ctx['ttl_max'] . '" data-fixed="' . ($ctx['fixed'] ? '1' : '0') . '"'
        . ' data-have-amd64="' . ($b['amd64'] !== null ? '1' : '0') . '" data-have-arm64="' . ($b['arm64'] !== null ? '1' : '0') . '" data-have-msi-amd64="' . (($ctx['msi']['amd64'] ?? null) !== null ? '1' : '0') . '" data-have-msi-arm64="' . (($ctx['msi']['arm64'] ?? null) !== null ? '1' : '0')
        . '" data-admin="' . ($ctx['admin'] ? '1' : '0') . '" data-service-ok="' . ($ctx['problems'] === [] ? '1' : '0') . '">'
        . '<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable"><div class="modal-content">'
        . '<form id="rmm-inst-form" method="post" action="/agent/post/rmm_installer.php" autocomplete="off">'
        . '<input type="hidden" name="csrf_token" value="' . $h($ctx['csrf']) . '"><input type="hidden" name="action" id="rmm-inst-action" value="download">'
        . '<div class="modal-header"><h5 class="modal-title" id="rmmInstallerTitle"><i class="fas fa-download me-2" aria-hidden="true"></i>Add device</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">'
        . '<p class="text-muted small mb-3">Pick the department, then download an installer that already knows your server and the department. Nothing to type on the PC. <a href="' . $h(RIVET_RMM_ADD_DEVICE_URL) . '" id="rmm-inst-full-link">Need the MSI commands or the Linux packages? Open the full Add device page.</a> '
        . '<a href="' . $h(RIVET_RMM_ADD_DEVICE_URL) . '" class="btn btn-sm btn-outline-primary ms-1" id="rmm-inst-create-link"><i class="fas fa-tools me-1" aria-hidden="true"></i>Create installer</a></p>'
        . $problem . $none . $msiWarn
        . '<ul class="nav nav-pills mb-3" role="tablist" aria-label="Operating system">'
        . '<li class="nav-item" role="presentation"><button type="button" class="nav-link active" id="rmm-inst-tab-windows" role="tab" aria-selected="true" aria-controls="rmm-inst-panel" data-os="windows"><i class="fab fa-windows me-1" aria-hidden="true"></i>Windows</button></li>'
        . '<li class="nav-item" role="presentation"><button type="button" class="nav-link" id="rmm-inst-tab-linux" role="tab" aria-selected="false" aria-controls="rmm-inst-panel" data-os="linux" tabindex="-1"><i class="fab fa-linux me-1" aria-hidden="true"></i>Linux</button></li></ul>'
        . '<div id="rmm-inst-panel" role="tabpanel" aria-labelledby="rmm-inst-tab-windows">'
        . '<div class="row g-3">'
        . '<div class="col-md-6">' . $clientField . '</div>'
        . '<div class="col-md-6' . ($ctx['locations'] === [] ? ' d-none' : '') . '" id="rmm-inst-location-col"><label class="form-label" for="rmm-inst-location">Location <span class="text-muted">(optional)</span></label><select class="form-select" id="rmm-inst-location" name="location_id">' . $locOpts . '</select></div>'
        . '<div class="col-md-6"><span class="form-label d-block" id="rmm-inst-arch-label">Architecture</span><div class="btn-group" role="group" aria-labelledby="rmm-inst-arch-label">'
        . $arch('amd64', 'rmm-inst-arch-amd64', 'x64 (most PCs)', true) . $arch('arm64', 'rmm-inst-arch-arm64', 'ARM64', false) . '</div></div>'
        . '<div class="col-md-6" id="rmm-inst-format-col"><span class="form-label d-block" id="rmm-inst-format-label">Format</span><div class="btn-group" role="group" aria-labelledby="rmm-inst-format-label">'
        . $fmt('exe', 'rmm-inst-format-exe', 'Installer (.exe)', true) . $fmt('msi', 'rmm-inst-format-msi', 'MSI (.msi)', false) . '</div>'
        . '<div class="form-text" id="rmm-inst-format-help" aria-live="polite">The .exe is the default. Use the MSI for Intune, Group Policy or any tool that installs .msi packages.</div>'
        . ($ctx['admin'] ? '<a class="small d-none" id="rmm-inst-format-link" href="' . $h(RIVET_RMM_MSI_URL) . '">Upload an MSI template under Administration &gt; Endpoint agent</a>' : '') . '</div>'
        . '<div class="col-md-6"><label class="form-label" for="rmm-inst-ring">Update ring</label><select class="form-select" id="rmm-inst-ring" name="ring"><option value="stable" selected>Stable</option><option value="pilot">Pilot</option></select></div>'
        . '<div class="col-md-6"><label class="form-label" for="rmm-inst-device-type">Device type</label><select class="form-select" id="rmm-inst-device-type" name="device_type">'
        . '<option value="auto" selected>Detect automatically</option><option value="Laptop">Laptop</option><option value="Desktop">Desktop</option><option value="Server">Server</option>'
        . '<option value="Virtual Machine">Virtual machine</option><option value="Hypervisor">Hypervisor</option></select>'
        . $lead('What the installer enrolls shows up as. Leave on "Detect automatically" unless you know better.') . '</div>'
        . '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="rmm-inst-multi"><label class="form-check-label" for="rmm-inst-multi">I am installing on several PCs with this one file</label></div>'
        . $lead('Off: the file works for one PC. On: it works for up to <span id="rmm-inst-multi-n">25</span> PCs until it expires.') . '</div>'
        . $meshField
        . $appsField
        . '</div>'
        . '<details class="mt-3" id="rmm-inst-adv"><summary class="small fw-bold">Advanced</summary><div class="row g-3 mt-0 pt-2">'
        . '<div class="col-md-4"><label class="form-label" for="rmm-inst-ttl">Token lifetime (hours)</label><input class="form-control" type="number" id="rmm-inst-ttl" name="ttl_hours" min="1" max="' . (int) $ctx['ttl_max'] . '" value="' . (int) min(24, $ctx['ttl_max']) . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="rmm-inst-uses">PCs it may enroll</label><input class="form-control" type="number" id="rmm-inst-uses" name="max_uses" min="1" max="5000" value="1"></div>'
        . '<div class="col-md-4"><label class="form-label" for="rmm-inst-label">Label <span class="text-muted">(optional)</span></label><input class="form-control" type="text" id="rmm-inst-label" name="label" maxlength="100"></div>'
        . '</div></details>'
        . '<div class="alert alert-danger mt-3 mb-0 d-none" id="rmm-inst-error" role="alert"></div>'
        . '<div class="mt-3" id="rmm-inst-windows-next"><h6 class="mb-1">What happens next</h6><div class="small" id="rmm-inst-steps">' . $steps . '</div>'
        . '<div class="alert alert-success mt-3 mb-0 d-none" id="rmm-inst-done" role="status"></div></div>'
        . '<div class="mt-3 d-none" id="rmm-inst-linux-out"><h6 class="mb-1">Run this as root on the Linux machine</h6>'
        . '<p class="small text-muted mb-2" id="rmm-inst-linux-intro">Run these lines on the machine (<span id="rmm-inst-linux-arch" class="visually-hidden">amd64</span>). The token is a secret and is shown only once: do not paste it into tickets or chat.</p>'
        . '<p class="small text-muted mb-2 d-none" id="rmm-inst-linux-self">Nothing to upload or unpack: this downloads <code>install-linux.sh</code> and the agent <span id="rmm-inst-linux-selfver"></span> from this server, checks both against the SHA-256 values printed in the command, and installs. The token is a secret and is shown only once: do not paste it into tickets or chat.</p>'
        . '<div class="position-relative"><pre class="border rounded p-2 small mb-1" style="max-height:16rem;overflow:auto;white-space:pre-wrap;word-break:break-all" id="rmm-inst-linux-cmd" tabindex="0"></pre></div>'
        . '<button type="button" class="btn btn-outline-secondary btn-sm" id="rmm-inst-copy"><i class="fas fa-copy me-1" aria-hidden="true"></i><span>Copy command</span></button> '
        . '<span class="small text-muted" id="rmm-inst-linux-meta"></span></div>'
        . '</div></div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" class="btn btn-primary" id="rmm-inst-go"><i class="fas fa-download me-1" aria-hidden="true"></i><span id="rmm-inst-go-label">Download installer</span></button></div>'
        . '</form></div></div></div>';
}
