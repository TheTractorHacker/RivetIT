<?php

/*
 * "Install apps when the agent installs" (RivetCore 1.0.0-rc.26, migration 0033, sub-switch `software_deploy`): the edition-side UI on top of
 * RivetCore\Rmm\AutoInstall\AutoInstallActions. Additive to the Phase 5 software pages (includes/rmm_sw_ui.php):
 *
 *   - the device page: a section "Apps installed with the agent" (rivetRmmAutoInstPanelSection(), wired into rivetRmmUiTabs() as its own tab);
 *   - the catalog page (agent/rmm_software.php): per package, the auto-install scope/order/required form and the vendor parameter panel
 *     (rivetRmmAutoInstPackageCards(), appended to a package's edit view by rivetRmmSwCatalogPage()); fleet-wide, the hosted installer files
 *     and the starter templates (rivetRmmAutoInstFilesCard(), rivetRmmAutoInstTemplatesCard());
 *   - the installer page (the "Add device" modal and the full Add device page): tick-boxes for the catalog apps a technician chooses to install
 *     with the agent (rivetRmmAutoInstSelectableHtml(), filled by an AJAX call to agent/post/rmm_installer.php?action=selectable_apps, the same
 *     pattern the modal already uses for its department-dependent "locations" list).
 *
 * ONE AUTHORIZATION PATH, same as rmm_sw_ui.php: every read and write is a call into RivetCore's technician API as the signed-in user
 * (rivetRmmSwApi() / rivetRmmAutoApi(), `endpoint_devices/software_autoinstall...`, `.../software_params/{id}`, `.../software_files`,
 * `.../software_templates`, `.../{id}/autoinstall`), so the 403/404 rules, the department scope and the audit trail are Core's. A vendor
 * parameter VALUE is never read here: the API never returns one (RivetCore\Rmm\Deploy\PackageParams), and this file only ever shows "set" or
 * "not set", never a value, in a write-only box.
 *
 * Abilities are the same ones rmm_sw_ui.php already checks (rivetRmmSwPerm(): view = rmm.software.view, manage = rmm.software.manage,
 * deploy = rmm.software.deploy); this file adds no new permission helper.
 */

require_once __DIR__ . '/rmm_sw_ui.php';

const RIVET_RMM_AUTOINST_HANDLER = 'rmm_auto_install';

// ------------------------------------------------------------------ device page: "Apps installed with the agent"

/**
 * The view-model of the device tab: Core's per-app status for this device. Null when Core will not show this device to the viewer (another
 * department, or a device that does not exist) — the caller then draws nothing.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @return array<string,mixed>|null
 */
function rivetRmmAutoInstDeviceView(array $ctx, int $deviceId): ?array
{
    $r = rivetRmmSwApi($ctx, 'GET', [$deviceId, 'autoinstall']);
    if (!$r['ok']) {
        return in_array($r['status'], [403, 404], true) ? null : ['error' => $r['error'] !== '' ? $r['error'] : 'The auto-install state could not be read.'];
    }

    return ['error' => '', 'enabled' => !empty($r['data']['enabled']), 'counts' => (array) ($r['data']['counts'] ?? []), 'apps' => (array) ($r['data']['apps'] ?? []),
        'names' => rivetRmmSwUserNames($ctx['mysqli'], array_map(static fn (array $a): int => (int) ($a['selected_by'] ?? 0), (array) ($r['data']['apps'] ?? [])))];
}

/** status => pill. */
function rivetRmmAutoInstStatusPill(string $status): string
{
    return match ($status) {
        'queued' => rivetRmmUiPill('info', 'Queued'),
        'installing' => rivetRmmUiPill('info', 'Installing'),
        'installed' => rivetRmmUiPill('ok', 'Installed'),
        'failed' => rivetRmmUiPill('crit', 'Failed'),
        'skipped' => rivetRmmUiPill('warn', 'Skipped'),
        default => rivetRmmUiPill('off', ucfirst($status)),
    };
}

/**
 * The hook rivetRmmUiTabs() calls for the "Apps installed with the agent" tab. '' when the module or `software_deploy` is off, the viewer cannot
 * read software deployment, or Core will not show this device to the viewer.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmAutoInstPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (!rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('software_deploy', $mysqli)) {
        return '';
    }
    $uid = (int) $vm['user_id'];
    $perm = rivetRmmSwPerm($mysqli, $uid);
    if (!$perm['view']) {
        return '';
    }
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'csrf' => $csrf, 'perm' => $perm, 'mysqli' => $mysqli];
    $dv = rivetRmmAutoInstDeviceView($ctx, (int) $vm['device_id']);
    if ($dv === null) {
        return '';
    }

    return rivetRmmAutoInstDeviceHtml($vm, $dv);
}

/** @param array<string,mixed> $vm rivetRmmUiPanel() @param array<string,mixed> $dv rivetRmmAutoInstDeviceView() */
function rivetRmmAutoInstDeviceHtml(array $vm, array $dv): string
{
    $o = '<section id="rmm-autoinstall" aria-labelledby="rmm-autoinstall-h"><h6 id="rmm-autoinstall-h" class="mb-2"><i class="fas fa-download me-2" aria-hidden="true"></i>Apps installed with the agent</h6>';
    if ($dv['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($dv['error']) . '</div></section>';
    }
    if (!$dv['enabled']) {
        $o .= '<div class="alert alert-info py-2">Software deployment is switched off, so nothing is installed with the agent.</div></section>';

        return $o;
    }
    if ($dv['apps'] === []) {
        return $o . '<p class="small text-muted">No catalog app is planned to install with the agent on this device (none was ticked on its installer, and no policy app applied when it enrolled).</p></section>';
    }
    $counts = (array) $dv['counts'];
    $pills = '';
    foreach (['queued' => 'Queued', 'installing' => 'Installing', 'installed' => 'Installed', 'failed' => 'Failed', 'skipped' => 'Skipped'] as $k => $w) {
        if ((int) ($counts[$k] ?? 0) > 0) {
            $pills .= rivetRmmAutoInstStatusPill($k) . ' <span class="me-3">' . (int) $counts[$k] . '</span>';
        }
    }
    $o .= '<p class="small mb-2">' . $pills . '</p>';
    $rows = '';
    foreach ($dv['apps'] as $a) {
        $by = $a['selected_by'] !== null ? rmmH((string) ($dv['names'][(int) $a['selected_by']] ?? ('user #' . (int) $a['selected_by']))) : '<span class="text-muted">the scheduler</span>';
        $rows .= '<tr><td class="ps-3 fw-semibold rmm-wrap">' . rmmH((string) $a['name']) . ($a['required'] ? ' <span class="small text-muted">(required)</span>' : '') . '</td>'
            . '<td>' . rivetRmmAutoInstStatusPill((string) $a['status']) . '</td><td class="small rmm-wrap">' . rmmH((string) $a['reason']) . '</td>'
            . '<td>' . ((string) $a['source'] === 'chosen' ? 'Ticked on the installer' : 'Policy') . '</td><td class="small">' . $by . '</td><td>' . (int) $a['install_order'] . '</td>'
            . '<td class="small">' . (int) $a['attempts'] . '</td></tr>';
    }

    return $o . '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Apps installed with the agent on this device</caption>'
        . rivetRmmUiThead(['App', 'Status', 'Reason', 'Source', 'Chosen by', 'Order', 'Tries']) . '<tbody>' . $rows . '</tbody></table></div></section>';
}

// ------------------------------------------------------------------ catalog page: per-package auto-install policy and vendor parameters

/**
 * The per-package autoinstall overview entry (scope/order/required, declared parameter names and where set), from the same call the catalog page
 * already makes for the version list. Null when the package is not found or the viewer may not read it.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @return array<string,mixed>|null
 */
function rivetRmmAutoInstPackageView(array $ctx, int $packageId): ?array
{
    $r = rivetRmmSwApi($ctx, 'GET', ['software_autoinstall']);
    if (!$r['ok']) {
        return null;
    }
    foreach ((array) ($r['data']['packages'] ?? []) as $p) {
        if ((int) ($p['package_id'] ?? 0) === $packageId) {
            return $p;
        }
    }

    return null;
}

/** The "auto-install scope, order, required" form of a package's edit view. @param array<string,mixed> $vm rivetRmmSwCatalogView() plus 'auto' */
function rivetRmmAutoInstPolicyForm(array $vm): string
{
    $perm = $vm['perm'];
    $e = (array) $vm['edit'];
    $pid = (int) $e['package_id'];
    $self = '/agent/rmm_software.php?package_id=' . $pid;
    $auto = (array) ($vm['auto']['auto'] ?? ['scope' => 'off', 'os_filter' => '', 'install_order' => 100, 'required' => false, 'clients' => []]);
    $can = $perm['deploy'] && $vm['unrestricted'];
    $scope = (string) $auto['scope'];
    $body = '<p class="small text-muted px-3 pt-3 mb-2">A package set to anything other than "Off" installs by itself, right after the agent, on every <strong>new</strong> device that matches '
        . '(re-enrolling or reinstalling an existing device never triggers it). It is queued like an ordinary scheduled install: a dry run until the live-installs switch is on, unless a '
        . 'technician explicitly ticks it on that device\'s installer.</p>';
    if (!$can) {
        return rivetRmmUiCard('Install on new devices', 'magic', $body . '<p class="small text-muted p-3 mb-0 border-top">Currently: <strong>' . rmmH(rivetRmmAutoInstScopeWord($auto)) . '</strong>. '
            . ($perm['deploy'] ? 'This applies to every department, so only a user with access to every department can change it.' : 'Your role can read this but not change it.') . '</p>', '', '', true, 'autoinstall');
    }
    $sel = static fn (string $v) => $scope === $v ? ' selected' : '';
    $body .= '<form method="post" action="/agent/post/' . RIVET_RMM_AUTOINST_HANDLER . '.php" class="p-3 border-top" id="auto-form" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], $self . '#autoinstall')
        . '<input type="hidden" name="action" value="set_auto"><input type="hidden" name="package_id" value="' . $pid . '"><div class="row g-3">'
        . '<div class="col-md-4"><label class="form-label small" for="au_scope">Install on</label><select class="form-select form-select-sm" id="au_scope" name="scope">'
        . '<option value="off"' . $sel('off') . '>Off</option><option value="all"' . $sel('all') . '>Every new device</option><option value="os"' . $sel('os') . '>Every new device of one operating system</option>'
        . '<option value="clients"' . $sel('clients') . '>Chosen departments</option></select></div>'
        . '<div class="col-md-3" data-rmm-when="#au_scope=os"><label class="form-label small" for="au_os">Operating system</label><select class="form-select form-select-sm" id="au_os" name="os">'
        . '<option value="windows"' . ((string) $auto['os_filter'] === 'windows' ? ' selected' : '') . '>Windows</option><option value="linux"' . ((string) $auto['os_filter'] === 'linux' ? ' selected' : '') . '>Linux</option></select></div>'
        . '<div class="col-md-5" data-rmm-when="#au_scope=clients"><label class="form-label small" for="au_clients">Department ids, comma separated</label>'
        . '<input class="form-control form-control-sm rmm-mono" id="au_clients" name="clients_csv" value="' . rmmH(implode(',', array_map(static fn (array $c): string => (string) $c['client_id'], (array) $auto['clients']))) . '" placeholder="12,47">'
        . '<div class="form-text">The department\'s id (shown on its page\'s URL); at most 200.</div></div>'
        . '<div class="col-md-3"><label class="form-label small" for="au_order">Install order</label><input class="form-control form-control-sm" type="number" min="1" max="9999" id="au_order" name="install_order" value="' . (int) $auto['install_order'] . '">'
        . '<div class="form-text">Lower first (security before backup).</div></div>'
        . '<div class="col-md-3"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="au_req" name="required" value="1"' . (!empty($auto['required']) ? ' checked' : '') . '>'
        . '<label class="form-check-label small" for="au_req">Required</label></div><div class="form-text">A required app that fails stops the later ones on that device.</div></div>'
        . '</div><div class="mt-3"><button class="btn btn-sm btn-primary" type="submit">Save</button></div></form>';

    return rivetRmmUiCard('Install on new devices', 'magic', $body, '', '', true, 'autoinstall');
}

/** Plain words for a package's current auto-install scope. @param array<string,mixed> $auto */
function rivetRmmAutoInstScopeWord(array $auto): string
{
    return match ((string) ($auto['scope'] ?? 'off')) {
        'off' => 'Off',
        'all' => 'Every new device',
        'os' => 'Every new ' . ($auto['os_filter'] ?? '') . ' device',
        'clients' => count((array) ($auto['clients'] ?? [])) . ' chosen department(s)',
        default => 'Off',
    };
}

/** The write-only vendor parameter panel of a package's edit view. @param array<string,mixed> $vm rivetRmmSwCatalogView() plus 'auto' */
function rivetRmmAutoInstParamsPanel(array $vm): string
{
    $perm = $vm['perm'];
    $e = (array) $vm['edit'];
    $pid = (int) $e['package_id'];
    $self = '/agent/rmm_software.php?package_id=' . $pid;
    $declared = (array) ($vm['auto']['params']['declared'] ?? []);
    $set = (array) ($vm['auto']['params']['set'] ?? []);
    if ($declared === []) {
        return rivetRmmUiCard('Vendor parameters', 'key', '<p class="small text-muted p-3 mb-0">This package\'s versions have no <code>{{name}}</code> placeholder in their silent arguments, so there is nothing to set here.</p>', '', '', true, 'params');
    }
    $rows = '';
    foreach ($declared as $name) {
        $here = array_values(array_filter($set, static fn (array $s): bool => (string) $s['name'] === $name));
        $where = $here === [] ? '<span class="text-muted">not set</span>' : implode(', ', array_map(static fn (array $s): string => $s['scope'] . ($s['scope_id'] > 0 ? ' #' . $s['scope_id'] : ''), $here));
        $rows .= '<tr><td class="ps-3"><code class="rmm-mono">' . rmmH($name) . '</code></td><td class="small">' . $where . '</td></tr>';
    }
    $can = $perm['manage'];
    $form = '';
    if ($can) {
        $opts = '';
        foreach ($declared as $name) {
            $opts .= '<option value="' . rmmH($name) . '">' . rmmH($name) . '</option>';
        }
        $form = '<form method="post" action="/agent/post/' . RIVET_RMM_AUTOINST_HANDLER . '.php" class="p-3 border-top" id="param-form" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], $self . '#params')
            . '<input type="hidden" name="action" value="set_param"><input type="hidden" name="package_id" value="' . $pid . '"><h6 class="mb-2">Set or clear a value</h6>'
            . '<div class="row g-2 align-items-end"><div class="col-md-3"><label class="form-label small" for="pm_name">Name</label><select class="form-select form-select-sm" id="pm_name" name="name">' . $opts . '</select></div>'
            . '<div class="col-md-3"><label class="form-label small" for="pm_scope">Scope</label><select class="form-select form-select-sm" id="pm_scope" name="scope"><option value="global">Global (every department)</option>'
            . '<option value="client">One department</option></select></div>'
            . '<div class="col-md-2" data-rmm-when="#pm_scope=client"><label class="form-label small" for="pm_scope_id">Department id</label><input class="form-control form-control-sm" type="number" min="1" id="pm_scope_id" name="scope_id"></div>'
            . '<div class="col-md-4"><label class="form-label small" for="pm_value">Value</label><input class="form-control form-control-sm rmm-mono" type="password" autocomplete="off" id="pm_value" name="value" maxlength="128" placeholder="never shown again once saved">'
            . '<div class="form-text">1-128 characters, no spaces, quotes or shell characters. Sealed; never shown back, not even to an administrator.</div></div></div>'
            . '<div class="mt-2"><button class="btn btn-sm btn-primary" type="submit">Save value</button> '
            . '<button class="btn btn-sm btn-outline-danger" type="submit" formnovalidate name="clear_action" value="1" data-form-set="action=clear_param" data-form-unrequire="value">Clear it instead</button></div></form>';
    } else {
        $form = '<p class="small text-muted p-3 mb-0 border-top">Your role can see which names are declared and where each is set, but not set or clear a value.</p>';
    }

    return rivetRmmUiCard('Vendor parameters', 'key', '<p class="small text-muted px-3 pt-3 mb-2">A value (an account key, an organisation key, a server address) is sealed as soon as it is saved: nothing in this UI, the API or the audit '
        . 'trail ever shows it again, only whether a name is set and where. The most specific scope wins (location over department over global).</p>'
        . '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Vendor parameters</caption>' . rivetRmmUiThead(['Name', 'Set']) . '<tbody>' . $rows . '</tbody></table></div>' . $form, '', '', true, 'params');
}

// ------------------------------------------------------------------ catalog page: fleet-wide hosted files and starter templates

/** @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx */
function rivetRmmAutoInstFilesView(array $ctx): array
{
    $r = rivetRmmSwApi($ctx, 'GET', ['software_files']);

    return ['files' => $r['ok'] ? (array) ($r['data']['files'] ?? []) : [], 'max_bytes' => $r['ok'] ? (int) ($r['data']['max_bytes'] ?? 0) : 0, 'error' => $r['ok'] ? '' : $r['error']];
}

/** Hosted installer files (fleet-wide, for vendors whose download addresses are account-specific). @param array<string,mixed> $vm rivetRmmSwCatalogView() */
function rivetRmmAutoInstFilesCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software.php';
    $can = $perm['manage'] && $vm['unrestricted'];
    $fv = $vm['files_view'];
    $rows = '';
    foreach ($fv['files'] as $f) {
        $rows .= '<tr><td class="ps-3 rmm-wrap">' . rmmH((string) $f['filename']) . '</td><td class="small"><code class="rmm-mono">' . rmmH(substr((string) $f['sha256'], 0, 16)) . '...</code></td>'
            . '<td>' . rmmH(rivetRmmUiBytes((float) $f['size_bytes'])) . '</td><td>' . (int) $f['used_by_versions'] . '</td><td class="small">' . rivetRmmUiTime((string) $f['created_at']) . '</td>'
            . '<td class="rmm-table-actions">' . ($can ? rivetRmmAutoActionForm(RIVET_RMM_AUTOINST_HANDLER, 'delete_file', ['file_id' => (int) $f['file_id']], $csrf, $self . '#hosted-files', 'Delete', 'btn btn-sm btn-outline-danger',
                (int) $f['used_by_versions'] > 0 ? '' : 'Delete the hosted file "' . $f['filename'] . '"?') : '') . '</td></tr>';
    }
    $tbl = $rows === '' ? '<p class="small text-muted px-3 pt-3 mb-2">No installer is hosted here yet.</p>'
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Hosted installer files</caption>' . rivetRmmUiThead(['File', 'sha256', 'Size', 'Used by', 'Uploaded', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    $maxTxt = $fv['max_bytes'] > 0 ? rivetRmmUiBytes((float) $fv['max_bytes']) : '512 MiB';
    $form = $can ? '<form method="post" action="/agent/post/' . RIVET_RMM_AUTOINST_HANDLER . '.php" enctype="multipart/form-data" class="p-3 border-top" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self . '#hosted-files')
        . '<input type="hidden" name="action" value="upload_file"><div class="row g-2 align-items-end"><div class="col-md-8"><label class="form-label small" for="hf_file">Windows installer file (.exe or .msi), at most ' . rmmH($maxTxt) . '</label>'
        . '<input class="form-control form-control-sm" type="file" id="hf_file" name="installer_file" accept=".exe,.msi" required></div><div class="col-md-4"><button class="btn btn-sm btn-primary" type="submit">Upload</button></div></div>'
        . '<div class="form-text">Use for a vendor whose download address is account-specific. The server checks the MZ or MSI header and the size; its own refusal text is shown exactly as it reads.</div></form>'
        : '<p class="small text-muted p-3 mb-0 border-top">Only someone who manages the catalog, with access to every department, can upload or delete a hosted file.</p>';

    return rivetRmmUiCard('Hosted installer files', 'file-upload', '<p class="small text-muted px-3 pt-3 mb-2">Add a version that uses one of these from "Add a version" (choose the file instead of an installer address). A file is never opened as an archive, and is served to a device only through a one-time, per-job secret link that works for 24 hours at most.</p>' . $tbl . $form, '', '', true, 'hosted-files');
}

/** @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx */
function rivetRmmAutoInstTemplatesView(array $ctx): array
{
    $r = rivetRmmSwApi($ctx, 'GET', ['software_templates']);

    return ['templates' => $r['ok'] ? (array) ($r['data']['templates'] ?? []) : [], 'provides' => $r['ok'] ? (array) ($r['data']['you_provide'] ?? []) : [], 'error' => $r['ok'] ? '' : $r['error']];
}

/** Starter templates: data only, every one UNVERIFIED (section 14.6). @param array<string,mixed> $vm rivetRmmSwCatalogView() */
function rivetRmmAutoInstTemplatesCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software.php';
    $can = $perm['manage'] && $vm['unrestricted'];
    $tv = $vm['templates_view'];
    $body = '<div class="alert alert-warning py-2 mx-3 mt-3" role="alert"><i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i><strong>Every template below is UNVERIFIED: never run on a real PC.</strong> '
        . 'A template carries no binary, no download address, no hash and no vendor key; the switch names shown are the author\'s best recollection of the vendor\'s documentation and may be wrong. '
        . 'Check them against the vendor\'s current documentation, run a dry run, then one pilot machine, before relying on one.</div>';
    $rows = '';
    foreach ($tv['templates'] as $t) {
        $rows .= '<tr><td class="ps-3 fw-semibold">' . rmmH((string) $t['name']) . '<div class="small text-muted fw-normal">' . rmmH((string) $t['publisher']) . ' - ' . rmmH((string) $t['fmt']) . ' (' . rmmH((string) $t['os']) . ')</div></td>'
            . '<td class="small rmm-wrap"><code class="rmm-mono">' . rmmH((string) ($t['silent_args'] ?? '')) . '</code></td>'
            . '<td class="small rmm-wrap">' . implode('<br>', array_map(static fn (string $n): string => '<i class="fas fa-exclamation-circle text-warning me-1" aria-hidden="true"></i>' . rmmH($n), $t['notes'] ?? [])) . '</td>'
            . '<td class="rmm-table-actions">' . ($can ? '<a class="btn btn-sm btn-outline-primary" href="' . $self . '?new_from_template=' . rmmH((string) $t['key']) . '">Use this template</a>' : '') . '</td></tr>';
    }
    $tbl = '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Starter templates (unverified)</caption>' . rivetRmmUiThead(['Template', 'Suggested arguments', 'Notes', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    $form = '';
    if ($can && !empty($vm['template_key'])) {
        $t = null;
        foreach ($tv['templates'] as $x) {
            if ((string) $x['key'] === (string) $vm['template_key']) {
                $t = $x;
                break;
            }
        }
        if ($t !== null) {
            $form = '<form method="post" action="/agent/post/' . RIVET_RMM_AUTOINST_HANDLER . '.php" class="p-3 border-top" id="tpl-form" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self)
                . '<input type="hidden" name="action" value="from_template"><input type="hidden" name="template_key" value="' . rmmH((string) $t['key']) . '">'
                . '<h6 class="mb-2">New package or version from "' . rmmH((string) $t['name']) . '" (unverified)</h6>'
                . '<div class="row g-2"><div class="col-md-4"><label class="form-label small" for="tf_pkg">Add to an existing package id (optional)</label><input class="form-control form-control-sm" type="number" min="1" id="tf_pkg" name="package_id"><div class="form-text">Leave empty to create a new package named "' . rmmH((string) $t['name']) . '".</div></div>'
                . '<div class="col-md-4"><label class="form-label small" for="tf_ver">Version</label><input class="form-control form-control-sm" id="tf_ver" name="version" maxlength="64" required></div>'
                . '<div class="col-md-4"><label class="form-label small" for="tf_sha">SHA-256</label><input class="form-control form-control-sm rmm-mono" id="tf_sha" name="sha256" maxlength="64" pattern="[0-9a-fA-F]{64}"></div>'
                . '<div class="col-md-4"><label class="form-label small" for="tf_url">Installer address (https) or leave empty to use a hosted file id</label><input class="form-control form-control-sm rmm-mono" id="tf_url" name="url" maxlength="600"></div>'
                . '<div class="col-md-4"><label class="form-label small" for="tf_hosted">Hosted file id (instead of an address)</label><input class="form-control form-control-sm" type="number" min="1" id="tf_hosted" name="hosted_file_id"></div>'
                . '<div class="col-md-4"><label class="form-label small" for="tf_size">Size in bytes</label><input class="form-control form-control-sm" type="number" min="1" id="tf_size" name="size_bytes"></div>'
                . '<div class="col-md-6"><label class="form-label small" for="tf_args">Silent arguments (override, optional)</label><input class="form-control form-control-sm rmm-mono" id="tf_args" name="silent_args" maxlength="200" value="' . rmmH((string) ($t['silent_args'] ?? '')) . '"></div>'
                . '</div><div class="mt-2"><button class="btn btn-sm btn-primary" type="submit">Create (unverified)</button> <a class="btn btn-sm btn-outline-secondary" href="' . $self . '">Cancel</a></div></form>';
        }
    }

    return rivetRmmUiCard('Starter templates', 'magic', $body . $tbl . $form, '', '', true, 'templates');
}

// ------------------------------------------------------------------ installer page: tick the apps that install with the agent

/**
 * The installer page's helper (section 14.1/14.4): `AutoInstallActions::selectable()` as plain arrays, for the "Add device" modal and the full
 * Add device page. Used both for the initial server render (when the department is already fixed) and by the AJAX refill on a department/location
 * change (agent/post/rmm_installer.php?action=selectable_apps), so the two must agree on shape.
 *
 * @return array{enabled:bool,apps:list<array<string,mixed>>}
 */
function rivetRmmAutoInstSelectable(\mysqli $mysqli, int $userId, string $userName, int $clientId, int $locationId, string $os): array
{
    if ($clientId <= 0 || !rivetRmmEnabled($mysqli) || !rivetRmmFeatureOn('software_deploy', $mysqli)) {
        return ['enabled' => false, 'apps' => []];
    }
    $r = rivetRmmAutoApi($mysqli, $userId, $userName, 'GET', ['software_autoinstall', 'selectable'], ['client_id' => (string) $clientId, 'location_id' => (string) $locationId, 'os' => $os]);

    return ['enabled' => $r['ok'] && !empty($r['data']['enabled']), 'apps' => $r['ok'] ? (array) ($r['data']['apps'] ?? []) : []];
}

/**
 * The tick-box list itself (one checkbox per selectable app). `$idPrefix` keeps the modal's and the full page's element ids apart.
 *
 * @param array{enabled:bool,apps:list<array<string,mixed>>} $sel rivetRmmAutoInstSelectable()
 */
function rivetRmmAutoInstSelectableHtml(array $sel, string $idPrefix): string
{
    if (!$sel['enabled']) {
        return '<p class="small text-muted mb-0" data-rmm-autoinst-empty>Software deployment is switched off, so no app can be ticked to install with the agent.</p>';
    }
    if ($sel['apps'] === []) {
        return '<p class="small text-muted mb-0" data-rmm-autoinst-empty>The catalog has no app for this department and operating system yet.</p>';
    }
    $o = '';
    foreach ($sel['apps'] as $a) {
        $pid = (int) $a['package_id'];
        $missing = (array) ($a['missing_params'] ?? []);
        $blocked = $missing !== [];
        $id = $idPrefix . '-app-' . $pid;
        $label = rmmH((string) $a['name']) . ' <span class="text-muted">' . rmmH((string) $a['version']) . '</span>' . (!empty($a['required']) ? ' <span class="text-muted small">(required)</span>' : '')
            . (!empty($a['two_person']) ? ' <span class="badge text-bg-warning ms-1">needs a second person</span>' : '');
        $help = $blocked ? '<div class="form-text text-danger">Missing vendor parameter(s) for this department: ' . rmmH(implode(', ', $missing)) . '. Set them in the software catalog first.</div>' : '';
        $o .= '<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="app_ids[]" value="' . $pid . '" id="' . rmmH($id) . '"' . (!empty($a['auto']) && !$blocked ? ' checked' : '') . ($blocked ? ' disabled' : '') . '>'
            . '<label class="form-check-label small" for="' . rmmH($id) . '">' . $label . '</label>' . $help . '</div>';
    }

    return $o;
}
