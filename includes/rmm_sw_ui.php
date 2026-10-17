<?php

/*
 * RMM software deployment pages (RivetCore 1.0.0-rc.21, Phase 5, sub-switch `software_deploy`, OFF by default): the deployment block of the Software tab of the asset page
 * (rivetRmmSwPanelSection()), the catalog (agent/rmm_software.php: packages, immutable versions, the allowed installer hosts), the assignments (agent/rmm_software_assignments.php) and
 * the deployed-versus-expected report with its CSV (agent/rmm_software_report.php, agent/rmm_software_report_csv.php). Form posts go to agent/post/rmm_software_deploy.php.
 *
 * ONE AUTHORIZATION PATH. Every read and every write is a call into RivetCore's technician API (`endpoint_devices/{id}/deployments...`, `endpoint_devices/software_packages...`,
 * `.../software_assignments`, `.../software_hosts`, `.../software_report`, `.../approvals`) as the signed-in user, through rivetRmmAutoApi(). Core's DeployActions decides who may read
 * (rmm.device.view + rmm.software.view), who may edit the catalog / ask for a detection / run a dry run (rmm.software.manage; the catalog also needs access to every department), who may
 * assign and run for real (rmm.software.deploy), who may touch the allowed host list (rmm.admin), the same 404 for another department's device as for a missing one, the two-person rule and
 * the audit. Nothing here builds a client filter or reads a deployment table of its own; hiding a button is cosmetic. The only direct reads are lookups limited to ids Core just returned:
 * the asset id of the devices on the page being shown, the user names on an approval, department / site / group / tag names for the labels.
 *
 * NOTHING THE AGENT RUNS COMES FROM THIS FILE. The forms carry ids (package, version, device, scope) and the choice install / remove / dry run; the address, the hash, the arguments and the
 * package name an agent receives are rebuilt by Core from the catalog row. The installer address of a catalog row is shown to a reader only as far as Core shows it.
 *
 * The view-model functions (rivetRmmSw*View) return arrays and are what the tests call; the renderers (rivetRmmSw*Page / *Html) return HTML strings.
 */

require_once __DIR__ . '/rmm_automation.php';

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Http\RmmRequest;

const RIVET_RMM_SW_HANDLER = 'rmm_software_deploy';
const RIVET_RMM_SW_PAGE = 50;
const RIVET_RMM_SW_STATES = ['compliant' => 'Compliant', 'scheduled' => 'Scheduled', 'pending' => 'Pending', 'failed' => 'Failed', 'missing' => 'Missing', 'present' => 'Still present', 'unknown' => 'Not detected yet'];
const RIVET_RMM_SW_GROUPS = ['package' => 'Package', 'client' => 'Department', 'ring' => 'Ring', 'device' => 'Device'];
const RIVET_RMM_SW_FORMATS = ['apt' => 'apt (Debian, Ubuntu)', 'dnf' => 'dnf (Rocky, Alma, Fedora)', 'msi' => 'msi (Windows installer)', 'exe' => 'exe (Windows program)'];
const RIVET_RMM_SW_OS = ['apt' => 'linux', 'dnf' => 'linux', 'msi' => 'windows', 'exe' => 'windows'];

// ------------------------------------------------------------------ the gate

/**
 * Role-level abilities for the software pages (a department of 0 asks "anywhere"; Core decides per department on every call).
 *
 * @return array{view:bool,manage:bool,deploy:bool,approve:bool,admin:bool}
 */
function rivetRmmSwPerm(\mysqli $mysqli, int $userId): array
{
    $authz = rivetRmmModule($mysqli)->authorizer();
    $view = $authz->allowed($userId, RmmAbility::DEVICE_VIEW, 0) && $authz->allowed($userId, RmmAbility::SOFTWARE_VIEW, 0);

    return [
        'view' => $view,
        'manage' => $view && $authz->allowed($userId, RmmAbility::SOFTWARE_MANAGE, 0),
        'deploy' => $view && $authz->allowed($userId, RmmAbility::SOFTWARE_DEPLOY, 0),
        'approve' => $view && $authz->allowed($userId, RmmAbility::JOB_APPROVE, 0),
        'admin' => $authz->allowed($userId, RmmAbility::ADMIN, 0),
    ];
}

/**
 * The first thing a software page does (after inc_all.php and enforceUserPermission('module_rmm')): module on, `software_deploy` switch on, and the ability to read software state.
 * Returns the page context, or null after rendering the denial (the caller then stops: `return;`). With the module or the switch off nothing asks the database anything.
 *
 * @return array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli}|null
 */
function rivetRmmSwPage(\mysqli $mysqli, int $userId, string $userName): ?array
{
    if (!rivetRmmEnabled($mysqli)) {
        itflow_render_denied('The RMM module is switched off. An administrator can turn it on under Administration > Endpoint agent.', 'RMM module off');

        return null;
    }
    if (!rivetRmmFeatureOn('software_deploy', $mysqli)) {
        itflow_render_denied('Software deployment is switched off. An administrator can turn it on under Administration > Endpoint agent > Software deployment.', 'Switched off');

        return null;
    }
    $perm = rivetRmmSwPerm($mysqli, $userId);
    if (!$perm['view']) {
        itflow_render_denied('Your role cannot view software deployment.', 'No access');

        return null;
    }
    $GLOBALS['rmm_auto_scripts'] = true;   // the confirm dialog and conditional fields of js/rmm_automation.js
    $GLOBALS['rmm_sw_scripts'] = true;     // js/rmm_software.js

    return ['uid' => $userId, 'name' => $userName, 'csrf' => (string) ($_SESSION['csrf_token'] ?? ''), 'perm' => $perm, 'mysqli' => $mysqli];
}

/** The internal call into the technician API as the signed-in user. @return array{status:int,ok:bool,data:array<string,mixed>,error:string,code:string} */
function rivetRmmSwApi(array $ctx, string $method, array $segments, array $query = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], $ctx['uid'], $ctx['name'], $method, $segments, $query, $body);
}

/**
 * The CSV of the report: the technician API answers `text/csv`, which the JSON wrapper above does not carry, so this calls the same API and returns the raw response.
 *
 * @param array<string,string> $query
 * @return array{status:int,type:string,body:string}
 */
function rivetRmmSwRaw(\mysqli $mysqli, int $userId, string $userName, array $segments, array $query): array
{
    $req = new RmmRequest('GET', 'endpoint_devices', array_map('strval', $segments), $query, [], function_exists('getIP') ? (string) getIP() : '127.0.0.1',
        isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null, true, null, null);
    $resp = rivetRmmModule($mysqli)->technicianApi()->handle($req, rivetRmmPrincipal($userId, $userName));

    return ['status' => $resp->status, 'type' => (string) ($resp->headers['Content-Type'] ?? ''), 'body' => (string) $resp->body];
}

// ------------------------------------------------------------------ small words

function rivetRmmSwStatePill(string $state): string
{
    $label = RIVET_RMM_SW_STATES[$state] ?? ucfirst($state);

    return match ($state) {
        'compliant' => rivetRmmUiPill('ok', $label),
        'scheduled', 'pending' => rivetRmmUiPill('info', $label),
        'failed' => rivetRmmUiPill('crit', $label),
        'missing', 'present' => rivetRmmUiPill('warn', $label),
        default => rivetRmmUiPill('off', $label),
    };
}

function rivetRmmSwResultPill(string $result): string
{
    return match ($result) {
        '' => '<span class="text-muted">none yet</span>',
        'installed' => rivetRmmUiPill('ok', 'Installed'),
        'uninstalled' => rivetRmmUiPill('ok', 'Removed'),
        'already_installed' => rivetRmmUiPill('ok', 'Already installed'),
        'not_installed' => rivetRmmUiPill('ok', 'Was not installed'),
        'needs_reboot' => rivetRmmUiPill('warn', 'Done, needs a restart'),
        'would_install' => rivetRmmUiPill('info', 'Would install'),
        'would_uninstall' => rivetRmmUiPill('info', 'Would remove'),
        'locked' => rivetRmmUiPill('crit', 'Package manager busy'),
        'hash_mismatch' => rivetRmmUiPill('crit', 'Hash did not match'),
        default => rivetRmmUiPill('crit', ucfirst(str_replace('_', ' ', $result))),
    };
}

function rivetRmmSwActionWord(string $a): string
{
    return $a === 'uninstall' ? 'Remove' : ($a === 'install' ? 'Install' : ucfirst($a));
}

function rivetRmmSwFormatWord(string $fmt, string $os = ''): string
{
    return RIVET_RMM_SW_FORMATS[$fmt] ?? ($fmt !== '' ? $fmt : ($os !== '' ? $os : ''));
}

function rivetRmmSwPager(int $page, int $total, int $per, callable $url): string
{
    $pages = max(1, (int) ceil($total / max(1, $per)));
    if ($pages <= 1) {
        return '';
    }
    $o = '<nav class="d-flex align-items-center justify-content-between mt-2 small" aria-label="Pages"><span class="text-muted">Page ' . $page . ' of ' . $pages . ' (' . (int) $total . ' in all)</span><span>';
    $o .= $page > 1 ? '<a class="btn btn-sm btn-outline-secondary me-1" href="' . rmmH($url($page - 1)) . '">Previous</a>' : '';
    $o .= $page < $pages ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($page + 1)) . '">Next</a>' : '';

    return $o . '</span></nav>';
}

/** device_id => asset_id for the devices Core just returned (lookup limited to those ids). @param list<array<string,mixed>> $items @return array<int,int> */
function rivetRmmSwAssetIds(\mysqli $mysqli, array $items): array
{
    $ids = array_values(array_unique(array_map(static fn (array $i): int => (int) ($i['device_id'] ?? 0), $items)));
    $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT device_id, asset_id FROM endpoint_agent_devices WHERE asset_id IS NOT NULL AND device_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['device_id']] = (int) $row['asset_id'];
    }

    return $out;
}

/** user_id => name for the ids on a page. @param list<int> $ids @return array<int,string> */
function rivetRmmSwUserNames(\mysqli $mysqli, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
    $out = [];
    if ($ids === []) {
        return $out;
    }
    $r = mysqli_query($mysqli, 'SELECT user_id, user_name FROM users WHERE user_id IN (' . implode(',', $ids) . ')');
    while ($r && ($row = mysqli_fetch_assoc($r))) {
        $out[(int) $row['user_id']] = (string) $row['user_name'];
    }

    return $out;
}

/** Hand the form back what was typed after a refusal (kept in the session for one page view). */
function rivetRmmSwTakeDraft(string $kind, int $id): ?array
{
    $d = $_SESSION['rmm_sw_draft'][$kind] ?? null;
    unset($_SESSION['rmm_sw_draft'][$kind]);

    return is_array($d) && (int) ($d['__id'] ?? -1) === $id ? $d : null;
}

/** What to keep of a refused form: plain strings only, capped. @param array<string,mixed> $post @return array<string,mixed> */
function rivetRmmSwDraft(array $post, int $id): array
{
    $d = ['__id' => $id];
    foreach ($post as $k => $v) {
        if (is_string($k) && is_string($v) && $k !== 'csrf_token' && strlen($k) <= 40) {
            $d[$k] = mb_substr($v, 0, 700);
        }
    }

    return $d;
}

function rivetRmmSwNavLinks(string $current): string
{
    $o = '';
    foreach ([['rmm_software.php', 'Software catalog', 'box-open'], ['rmm_software_assignments.php', 'Software assignments', 'boxes'], ['rmm_software_report.php', 'Software report', 'clipboard-check']] as [$file, $label, $icon]) {
        if ($file !== $current) {
            $o .= '<a class="btn btn-sm btn-outline-secondary" href="/agent/' . $file . '"><i class="fas fa-' . $icon . ' me-1" aria-hidden="true"></i>' . $label . '</a> ';
        }
    }

    return $o;
}

// ------------------------------------------------------------------ the deployment block of the Software tab

/**
 * The view-model of the block: Core's deployment status of the device, its latest history and (for someone who may start a run) the packages of the catalog. Null when Core will not show
 * this device to the viewer.
 *
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>|null
 */
function rivetRmmSwDeviceView(array $ctx, int $deviceId, array $get): ?array
{
    $r = rivetRmmSwApi($ctx, 'GET', [$deviceId, 'deployments']);
    if (!$r['ok']) {
        return in_array($r['status'], [403, 404], true) ? null : ['error' => $r['error'] !== '' ? $r['error'] : 'The deployment state could not be read.', 'device_id' => $deviceId];
    }
    $h = rivetRmmSwApi($ctx, 'GET', [$deviceId, 'deployments', 'history'], ['limit' => '20']);
    $catalog = [];
    if ($ctx['perm']['manage']) {
        $c = rivetRmmSwApi($ctx, 'GET', ['software_packages']);
        foreach ($c['ok'] ? (array) ($c['data']['packages'] ?? []) : [] as $p) {
            if (!empty($p['enabled'])) {
                $catalog[] = $p;
            }
        }
    }
    $pre = ctype_digit((string) ($get['sw_pkg'] ?? '')) ? (int) $get['sw_pkg'] : 0;
    if ($pre === 0) {
        // nothing chosen: offer the first package an assignment expects on this device (the catalog is sorted by name and may start with one for another operating system)
        foreach ((array) ($r['data']['packages'] ?? []) as $p) {
            if ($p['expected'] !== null) {
                $pre = (int) $p['package_id'];
                break;
            }
        }
    }
    $preAction = ($get['sw_action'] ?? '') === 'uninstall' ? 'uninstall' : 'install';

    return ['error' => '', 'device_id' => $deviceId, 'enabled' => !empty($r['data']['enabled']), 'counts' => (array) ($r['data']['counts'] ?? []), 'packages' => (array) ($r['data']['packages'] ?? []),
        'history' => $h['ok'] ? (array) ($h['data']['rows'] ?? []) : [], 'history_total' => $h['ok'] ? (int) ($h['data']['total'] ?? 0) : 0, 'catalog' => $catalog, 'pre_pkg' => $pre, 'pre_open' => ctype_digit((string) ($get['sw_pkg'] ?? '')), 'pre_action' => $preAction,
        'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'],
        'names' => rivetRmmSwUserNames($ctx['mysqli'], $h['ok'] ? array_map(static fn (array $u): int => (int) ($u['user_id'] ?? 0), (array) ($h['data']['rows'] ?? [])) : [])];
}

/** Why the block has nothing to act on, in words (the device must be linked and announce the capability). @return list<string> */
function rivetRmmSwTabNotes(array $vm, \mysqli $mysqli): array
{
    $notes = [];
    if (empty($vm['usable'])) {
        $notes[] = 'This device is not approved, or is revoked or retired, so no software job can be queued for it.';

        return $notes;
    }
    $res = mysqli_query($mysqli, 'SELECT capabilities_json FROM rmm_device_state WHERE device_id = ' . (int) $vm['device_id']);
    $row = $res ? mysqli_fetch_row($res) : null;
    $caps = $row && $row[0] !== null ? json_decode((string) $row[0], true) : null;
    if (!is_array($caps) || !in_array(\RivetCore\Rmm\Deploy\DeployProtocol::CAP_INSTALL, $caps, true)) {
        $notes[] = 'This device has not announced that it can install or remove software (<code>' . rmmH(\RivetCore\Rmm\Deploy\DeployProtocol::CAP_INSTALL) . '</code>). Its agent is older than the release that '
            . 'carries software deployment, or software deployment is withdrawn in its <code>config.json</code> (<code>disable_software</code>). Update the agent; until then no software job is offered to it.';
    }

    return $notes;
}

/**
 * The hook rivetRmmUiTabs() calls: the deployment block above the software list. '' when the module or the `software_deploy` switch is off, the viewer cannot read software deployment, or
 * Core will not show this device to the viewer.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()
 */
function rivetRmmSwPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
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
    $dv = rivetRmmSwDeviceView($ctx, (int) $vm['device_id'], $_GET);
    if ($dv === null) {
        return '';
    }
    $GLOBALS['rmm_sw_scripts'] = true;
    $GLOBALS['rmm_auto_scripts'] = true;

    return rivetRmmSwDeviceHtml($vm, $dv, $mysqli);
}

/** @param array<string,mixed> $vm rivetRmmUiPanel() @param array<string,mixed> $dv rivetRmmSwDeviceView() */
function rivetRmmSwDeviceHtml(array $vm, array $dv, ?\mysqli $mysqli = null): string
{
    $o = '<section id="rmm-deployment" class="mb-4" aria-labelledby="rmm-deployment-h"><h6 id="rmm-deployment-h" class="mb-2"><i class="fas fa-box-open me-2" aria-hidden="true"></i>Software deployment</h6>';
    if ($dv['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($dv['error']) . '</div></section>';
    }
    $perm = $dv['perm'];
    $csrf = (string) $dv['csrf'];
    $dev = (int) $vm['device_id'];
    $self = '/agent/asset_details.php?asset_id=' . (int) $vm['asset_id'] . '#rmm-software';
    foreach ($mysqli !== null ? rivetRmmSwTabNotes($vm, $mysqli) : [] as $n) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>' . $n . '</div>';
    }
    // ---- counts
    $counts = (array) $dv['counts'];
    $pills = '';
    foreach (RIVET_RMM_SW_STATES as $k => $w) {
        if ((int) ($counts[$k] ?? 0) > 0) {
            $pills .= rivetRmmSwStatePill($k) . ' <span class="me-3">' . (int) $counts[$k] . '</span>';
        }
    }
    $o .= '<p class="small mb-2">' . ($pills !== '' ? 'Expected on this device: ' . $pills : '<span class="text-muted">No package is assigned to this device.</span>') . '</p>';

    // ---- actions
    $acts = '';
    if ($perm['manage']) {
        $acts .= rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'detect', ['device_id' => $dev], $csrf, $self, '<i class="fas fa-search me-1" aria-hidden="true"></i>Detect now', 'btn btn-sm btn-outline-primary', '',
            'Ask the device which of the assigned packages it has (read only; nothing is installed)');
    }
    $o .= '<div class="d-flex flex-wrap align-items-center mb-3" style="gap:6px">' . ($acts !== '' ? $acts : '<span class="text-muted small">Your role can read software deployment but not change it.</span>')
        . ' <a class="btn btn-sm btn-outline-secondary" href="/agent/rmm_software_report.php"><i class="fas fa-clipboard-check me-1" aria-hidden="true"></i>Software report</a></div>';

    // ---- expected packages
    if ($dv['packages'] !== []) {
        $rows = '';
        foreach ($dv['packages'] as $p) {
            $pid = (int) $p['package_id'];
            $expected = $p['expected'] !== null;
            $quick = '';
            if ($perm['manage'] && $expected) {
                $quick .= rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'dry_run', ['device_id' => $dev, 'package_id' => $pid, 'package_action' => (string) $p['expected']], $csrf, $self, 'Dry run', 'btn btn-sm btn-outline-secondary', '',
                    'Show what ' . ($p['expected'] === 'uninstall' ? 'removing' : 'installing') . ' ' . $p['package'] . ' would do; nothing changes');
            }
            if ($perm['deploy']) {
                $quick .= ' <a class="btn btn-sm btn-outline-warning" href="' . rmmH('/agent/asset_details.php?' . http_build_query(['asset_id' => (int) $vm['asset_id'], 'sw_pkg' => $pid, 'sw_action' => 'install']) . '#rmm-software') . '">Install...</a>'
                    . ' <a class="btn btn-sm btn-outline-danger" href="' . rmmH('/agent/asset_details.php?' . http_build_query(['asset_id' => (int) $vm['asset_id'], 'sw_pkg' => $pid, 'sw_action' => 'uninstall']) . '#rmm-software') . '">Remove...</a>';
            }
            $rows .= '<tr><td class="ps-3"><div class="fw-semibold rmm-wrap">' . rmmH($p['package']) . '</div>' . ($p['format'] ? '<div class="small text-muted">' . rmmH((string) $p['format']) . '</div>' : '') . '</td>'
                . '<td>' . ($expected ? rmmH(rivetRmmSwActionWord((string) $p['expected'])) . ($p['expected_version'] !== null ? ' <span class="text-muted small">' . rmmH((string) $p['expected_version']) . '</span>' : '') : '<span class="text-muted">not expected</span>') . '</td>'
                . '<td>' . ($p['state'] !== null ? rivetRmmSwStatePill((string) $p['state']) : '<span class="text-muted">-</span>') . ($p['ring'] !== '' ? '<div class="small text-muted">ring ' . rmmH((string) $p['ring']) . '</div>' : '')
                . ($p['state'] === 'scheduled' && $p['expected_since'] !== null ? '<div class="small text-muted">due ' . rivetRmmUiTime((string) $p['expected_since']) . '</div>' : '') . '</td>'
                . '<td>' . ($p['installed'] === null ? '<span class="text-muted">not detected</span>' : ($p['installed'] ? 'installed' . ($p['installed_version'] !== '' ? ' <span class="small text-muted">' . rmmH((string) $p['installed_version']) . '</span>' : '') : 'not installed'))
                . ($p['detected_at'] !== null ? '<div class="small text-muted">seen ' . rivetRmmUiTime((string) $p['detected_at']) . '</div>' : '') . '</td>'
                . '<td>' . rivetRmmSwResultPill((string) $p['last_result']) . ($p['last_attempt_at'] !== null ? '<div class="small text-muted">' . rivetRmmUiTime((string) $p['last_attempt_at']) . ((int) $p['attempts'] > 1 ? ', ' . (int) $p['attempts'] . ' tries' : '') . '</div>' : '')
                . ($p['last_detail'] !== '' ? '<div class="small text-muted rmm-wrap">' . rmmH((string) $p['last_detail']) . '</div>' : '') . '</td>'
                . '<td class="rmm-table-actions">' . $quick . '</td></tr>';
        }
        $o .= '<div class="card card-dark mb-3"><div class="table-responsive"><table class="table table-sm mb-0 rmm-sw-table"><caption class="visually-hidden">Packages expected on this device</caption>'
            . rivetRmmUiThead(['Package', 'Expected', 'State', 'On the device', 'Last attempt', '']) . '<tbody>' . $rows . '</tbody></table></div></div>';
    }

    // ---- run something on this device
    if ($perm['manage']) {
        $o .= rivetRmmSwRunForm($vm, $dv, $self);
    }

    // ---- history
    if ($dv['history'] !== []) {
        $hr = '';
        foreach ($dv['history'] as $h) {
            $hr .= '<tr><td class="ps-3">' . rivetRmmUiTime((string) $h['at']) . '</td><td>' . ($h['mode'] === 'dry_run' ? 'Dry run, ' : '') . rmmH(strtolower(rivetRmmSwActionWord((string) $h['action']))) . '</td><td class="rmm-wrap">' . rmmH($h['package'] !== '' ? $h['package'] : '(deleted package)')
                . ($h['version'] !== '' ? ' <span class="small text-muted">' . rmmH((string) $h['version']) . '</span>' : '') . '</td><td>' . rivetRmmSwResultPill((string) $h['result']) . '</td><td class="small rmm-wrap">' . rmmH((string) $h['detail'])
                . '</td><td class="small">' . rmmH($h['user_id'] !== null ? ($dv['names'][(int) $h['user_id']] ?? ('user #' . (int) $h['user_id'])) : 'scheduler') . '</td></tr>';
        }
        $o .= '<details class="mb-2"><summary class="fw-semibold mb-2">Install history (latest ' . count($dv['history']) . ' of ' . (int) $dv['history_total'] . ')</summary><div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Software install history</caption>'
            . rivetRmmUiThead(['When', 'What', 'Package', 'Result', 'Detail', 'By']) . '<tbody>' . $hr . '</tbody></table></div></details>';
    }

    return $o . '</section>';
}

/** The "dry run / install / remove one package" form of the tab. @param array<string,mixed> $vm @param array<string,mixed> $dv */
function rivetRmmSwRunForm(array $vm, array $dv, string $self): string
{
    $perm = $dv['perm'];
    $dev = (int) $vm['device_id'];
    if ($dv['catalog'] === []) {
        return '<p class="small text-muted">The catalog has no package that is switched on. ' . ($perm['manage'] ? '<a href="/agent/rmm_software.php">Add one</a>.' : '') . '</p>';
    }
    $o = '<details class="card card-dark mb-3"' . (!empty($dv['pre_open']) ? ' open' : '') . '><summary class="card-header py-2 fw-semibold">Run a package on ' . rmmH($vm['hostname']) . '</summary><form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="card-body" id="sw-run-form" data-rmm-once="1">'
        . rivetRmmAutoHidden((string) $dv['csrf'], $self) . '<input type="hidden" name="device_id" value="' . $dev . '"><div class="row g-2 align-items-end">'
        . '<div class="col-md-5"><label class="form-label small mb-0" for="sw_pkg">Package</label><select class="form-select form-select-sm" id="sw_pkg" name="package_id" required>';
    foreach ($dv['catalog'] as $p) {
        $o .= '<option value="' . (int) $p['package_id'] . '"' . ((int) $p['package_id'] === (int) $dv['pre_pkg'] ? ' selected' : '') . '>' . rmmH($p['name']) . (!empty($p['two_person']) ? ' (needs a second person)' : '') . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label small mb-0" for="sw_action">What to do</label><select class="form-select form-select-sm" id="sw_action" name="package_action"><option value="install"'
        . ($dv['pre_action'] === 'install' ? ' selected' : '') . '>Install</option><option value="uninstall"' . ($dv['pre_action'] === 'uninstall' ? ' selected' : '') . '>Remove</option></select></div>'
        . '<div class="col-md-4"><button type="submit" name="action" value="dry_run" class="btn btn-sm btn-outline-secondary"><i class="fas fa-vial me-1" aria-hidden="true"></i>Dry run</button></div></div>';
    if ($perm['deploy']) {
        $o .= '<div class="border-top mt-3 pt-2"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="sw_conf" name="confirm" value="1"><label class="form-check-label small" for="sw_conf">I confirm this package may be installed or removed on '
            . rmmH($vm['hostname']) . ' now</label></div><button type="submit" name="action" value="run" class="btn btn-sm btn-warning" data-sw-needs-confirm="#sw_conf" data-rmm-confirm="Install or remove the chosen package on ' . rmmH($vm['hostname'])
            . ' now? This changes software on the machine. The agent runs only what the catalog pins (a hash-checked installer, or a package from the machine\'s own repositories) and never restarts the device. The Windows paths have not been run on a real machine: try a dry run first." data-rmm-confirm-label="Run now">'
            . '<i class="fas fa-download me-1" aria-hidden="true"></i>Run for real</button><span class="small text-muted ms-2">A dry run shows what would happen and changes nothing.</span></div>';
    } else {
        $o .= '<p class="small text-muted mt-2 mb-0">Your role can run a dry run but not install or remove for real.</p>';
    }

    return $o . '</form></details>';
}

// ------------------------------------------------------------------ the catalog

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmSwCatalogView(array $ctx, array $get): array
{
    $vm = ['error' => '', 'packages' => [], 'hosts' => [], 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'edit' => null, 'form' => null, 'draft' => null, 'version_draft' => null, 'enabled' => true, 'unrestricted' => false, 'edit_error' => ''];
    $r = rivetRmmSwApi($ctx, 'GET', ['software_packages']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The catalog could not be read.';

        return $vm;
    }
    $vm['packages'] = (array) ($r['data']['packages'] ?? []);
    $vm['enabled'] = !empty($r['data']['enabled']);
    $h = rivetRmmSwApi($ctx, 'GET', ['software_hosts']);
    $vm['hosts'] = $h['ok'] ? (array) ($h['data']['hosts'] ?? []) : [];
    $vm['unrestricted'] = rivetRmmModule($ctx['mysqli'])->authorizer()->visibleClientIds($ctx['uid']) === null;
    $pid = ctype_digit((string) ($get['package_id'] ?? '')) ? (int) $get['package_id'] : 0;
    if ($pid > 0) {
        $p = rivetRmmSwApi($ctx, 'GET', ['software_packages', $pid]);
        if ($p['ok']) {
            $vm['edit'] = $p['data'];
            $vm['form'] = 'edit';
            $vm['draft'] = rivetRmmSwTakeDraft('package', $pid);
            $vm['version_draft'] = rivetRmmSwTakeDraft('version', $pid);
        } else {
            $vm['edit_error'] = $p['status'] === 404 ? 'That package does not exist.' : ($p['error'] !== '' ? $p['error'] : 'The package could not be read.');
        }
    } elseif (!empty($get['new'])) {
        $vm['form'] = 'new';
        $vm['draft'] = rivetRmmSwTakeDraft('package', 0);
    }

    return $vm;
}

/** The catalog table. @param array<string,mixed> $vm */
function rivetRmmSwPackagesTable(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software.php';
    $can = $perm['manage'] && $vm['unrestricted'];
    if ($vm['packages'] === []) {
        return rivetRmmUiEmpty('fas fa-box-open', 'The catalog is empty', 'Add a package, then a version of it (a package name for apt or dnf, or a hash-pinned installer for Windows), then assign it. Nothing is installed by adding to the catalog.',
            $can ? '<a class="btn btn-primary" href="' . $self . '?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New package</a>' : '');
    }
    $rows = '';
    foreach ($vm['packages'] as $p) {
        $pid = (int) $p['package_id'];
        $rows .= '<tr><td class="ps-3 fw-semibold rmm-wrap"><a href="' . $self . '?package_id=' . $pid . '">' . rmmH($p['name']) . '</a>' . ($p['publisher'] !== '' ? '<div class="small text-muted fw-normal">' . rmmH($p['publisher']) . '</div>' : '')
            . ($p['description'] !== '' ? '<div class="small text-muted fw-normal">' . rmmH($p['description']) . '</div>' : '') . '</td><td>' . (!empty($p['enabled']) ? rivetRmmUiPill('ok', 'On') : rivetRmmUiPill('off', 'Off'))
            . '</td><td>' . (!empty($p['two_person']) ? rivetRmmUiPill('warn', 'Second person') : '<span class="text-muted">no</span>') . '</td><td>' . (int) $p['versions'] . '</td><td>' . (int) $p['assignments']
            . '</td><td class="rmm-table-actions"><a class="btn btn-sm btn-outline-primary" href="' . $self . '?package_id=' . $pid . '">' . ($can ? 'Edit' : 'View') . '</a> '
            . ($can ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'delete_package', ['package_id' => $pid], $csrf, $self, '<i class="fas fa-trash me-1" aria-hidden="true"></i>Delete', 'btn btn-sm btn-outline-danger',
                'Delete the package "' . $p['name'] . '" and its versions? A package that is still assigned cannot be deleted.') : '') . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Software catalog</caption>' . rivetRmmUiThead(['Package', 'State', 'Two-person', 'Versions in use', 'Assignments', '']) . '<tbody>' . $rows . '</tbody></table></div>';
}

/** The package form. @param array<string,mixed> $vm */
function rivetRmmSwPackageForm(array $vm): string
{
    $perm = $vm['perm'];
    $e = $vm['edit'];
    $d = $vm['draft'];
    $v = static fn (string $k, mixed $def = '') => $d !== null ? ($d[$k] ?? $def) : ($e[$k] ?? $def);
    $self = '/agent/rmm_software.php';
    $id = (int) ($e['package_id'] ?? 0);
    $two = $d !== null ? !empty($d['two_person']) : !empty($e['two_person']);
    $lockTwo = $e !== null && !empty($e['two_person']) && !$perm['admin'];
    $enabled = $d !== null ? !empty($d['enabled']) : ($e === null || !empty($e['enabled']));

    return '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" id="pkg-form" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], $self . ($id > 0 ? '?package_id=' . $id : '?new=1'))
        . '<input type="hidden" name="action" value="save_package"><input type="hidden" name="package_id" value="' . $id . '"><div class="row g-3">'
        . '<div class="col-md-5"><label class="form-label" for="pk_name">Name</label><input class="form-control" id="pk_name" name="name" maxlength="100" required value="' . rmmH((string) $v('name')) . '"></div>'
        . '<div class="col-md-4"><label class="form-label" for="pk_pub">Publisher</label><input class="form-control" id="pk_pub" name="publisher" maxlength="100" value="' . rmmH((string) $v('publisher')) . '"></div>'
        . '<div class="col-12"><label class="form-label" for="pk_desc">Description</label><input class="form-control" id="pk_desc" name="description" maxlength="300" value="' . rmmH((string) $v('description')) . '"></div>'
        . '<div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="pk_en" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '><label class="form-check-label" for="pk_en">Switched on</label></div>'
        . '<div class="form-text">A package that is off is not installed by anything and cannot be run by hand.</div></div>'
        . '<div class="col-md-8"><div class="form-check form-switch">' . ($lockTwo ? '' : '<input type="hidden" name="two_person_shown" value="1">')
        . '<input class="form-check-input" type="checkbox" id="pk_two" name="two_person" value="1"' . ($two ? ' checked' : '') . ($lockTwo ? ' disabled' : '') . '><label class="form-check-label" for="pk_two">Needs a second person</label></div>'
        . '<div class="form-text">Every assignment of this package, and every real run a person starts, waits until a different user approves it. '
        . ($lockTwo ? 'Only an administrator can switch this off.' : ($e !== null && !empty($e['two_person']) ? 'Switching it off needs the administrator grant.' : 'Anyone who manages the catalog can switch it on; only an administrator can switch it off again.')) . '</div></div></div>'
        . '<div class="mt-3"><button class="btn btn-primary" type="submit">Save package</button> <a class="btn btn-outline-secondary" href="' . $self . '">' . ($id > 0 ? 'Close' : 'Cancel') . '</a></div></form>';
}

/**
 * The versions of one package and the form that adds one. A version can never be edited (that is what makes its hash a pin); it can be retired.
 *
 * @param array<string,mixed> $vm
 */
function rivetRmmSwVersionsCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $e = (array) $vm['edit'];
    $pid = (int) $e['package_id'];
    $self = '/agent/rmm_software.php?package_id=' . $pid;
    $can = $perm['manage'] && $vm['unrestricted'];
    $rows = '';
    foreach ((array) ($e['version_list'] ?? []) as $x) {
        $vid = (int) $x['version_id'];
        $what = $x['fmt'] === 'apt' || $x['fmt'] === 'dnf'
            ? '<code class="rmm-mono">' . rmmH($x['pkg_name']) . '</code>' . (!empty($x['pin_version']) ? ' <span class="small text-muted">pinned to this version</span>' : ' <span class="small text-muted">the repository\'s newest</span>')
            : '<div class="small rmm-wrap">' . ($x['url'] !== '' ? '<code class="rmm-mono">' . rmmH($x['url']) . '</code>' : '') . '</div>'
                . ($x['product_code'] !== '' ? '<div class="small text-muted">product code <code class="rmm-mono">' . rmmH($x['product_code']) . '</code></div>' : '')
                . ($x['silent_args'] !== '' ? '<div class="small text-muted">arguments <code class="rmm-mono">' . rmmH($x['silent_args']) . '</code></div>' : '');
        $pin = $x['sha256'] !== '' ? '<div class="small"><span class="text-muted">sha256</span> <code class="rmm-mono rmm-wrap" title="' . rmmH($x['sha256']) . '">' . rmmH(substr((string) $x['sha256'], 0, 16)) . '...</code></div>'
            . '<div class="small text-muted">' . rmmH(rivetRmmUiBytes((float) $x['size_bytes'])) . '</div>' : '<span class="text-muted small">from the machine\'s own repositories</span>';
        $det = $x['detect_kind'] !== '' ? rmmH($x['detect_kind']) . ': <code class="rmm-mono">' . rmmH($x['detect_value']) . '</code>' . ($x['detect_min'] !== '' ? ' &ge; ' . rmmH($x['detect_min']) : '') : '<span class="text-muted">-</span>';
        $rows .= '<tr' . (!empty($x['retired']) ? ' class="text-muted"' : '') . '><td class="ps-3"><div class="fw-semibold rmm-wrap">' . rmmH($x['version']) . '</div><div class="small text-muted">' . rmmH(rivetRmmSwFormatWord((string) $x['fmt'], (string) $x['os']))
            . '</div>' . (!empty($x['retired']) ? rivetRmmUiPill('off', 'Retired') : '') . '</td><td>' . $what . '</td><td>' . $pin . '</td><td class="small rmm-wrap">' . $det . '</td><td class="rmm-table-actions">'
            . ($can ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'retire_version', ['package_id' => $pid, 'version_id' => $vid, 'retired' => !empty($x['retired']) ? '0' : '1'], $csrf, $self, !empty($x['retired']) ? 'Restore' : 'Retire',
                'btn btn-sm btn-outline-secondary', !empty($x['retired']) ? '' : 'Retire version ' . $x['version'] . '? It is never installed again, but can still be removed. Nothing is uninstalled by retiring.') : '') . '</td></tr>';
    }
    $tbl = $rows === '' ? '<p class="small text-muted px-3 pt-3 mb-2">This package has no version yet, so nothing can be installed from it.</p>'
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Versions of ' . rmmH($e['name']) . '</caption>' . rivetRmmUiThead(['Version', 'What is installed', 'Pin', 'Detected by', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    $form = $can ? rivetRmmSwVersionForm($vm, $pid, $self) : '<p class="small text-muted p-3 mb-0 border-top">' . ($perm['manage'] ? 'The catalog applies to every department, so only a user with access to every department can change it.' : 'Your role can read the catalog but not change it.') . '</p>';

    return rivetRmmUiCard('Versions of "' . rmmH($e['name']) . '"', 'layer-group', '<p class="small text-muted px-3 pt-3 mb-2">A version is created once and <strong>never edited</strong>: its address, hash, size and arguments are what the agent is told to run, so changing them would defeat the hash. '
        . 'To change what is installed, add a new version (and assign it). Retiring a version stops it being installed; it can still be removed.</p>' . $tbl . $form, '', '', true, 'versions');
}

/** The "add a version" form. The fields shown depend on the format; fields that do not apply are not posted (Core refuses them). @param array<string,mixed> $vm */
function rivetRmmSwVersionForm(array $vm, int $pid, string $self): string
{
    $d = $vm['version_draft'];
    $v = static fn (string $k, string $def = ''): string => $d !== null ? (string) ($d[$k] ?? $def) : $def;
    $fmt = $v('fmt', 'apt');
    $fmtOpts = '';
    foreach (RIVET_RMM_SW_FORMATS as $k => $w) {
        $fmtOpts .= '<option value="' . $k . '"' . ($fmt === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $hosts = array_map(static fn (array $h): string => (string) $h['host'], (array) $vm['hosts']);
    $hostNote = $hosts === [] ? '<div class="alert alert-warning py-2 mb-3" data-rmm-when="#vf_fmt=msi,exe">No installer host is allowed yet, so a Windows installer cannot be added. An administrator lists the hosts installers may be downloaded from (below).</div>'
        : '<p class="small text-muted mb-2" data-rmm-when="#vf_fmt=msi,exe">The address must be https and on an allowed host: ' . rmmH(implode(', ', $hosts)) . '.</p>';

    return '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="p-3 border-top" id="ver-form" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], $self . '#versions')
        . '<input type="hidden" name="action" value="add_version"><input type="hidden" name="package_id" value="' . $pid . '"><h6 class="mb-2">Add a version</h6>' . $hostNote . '<div class="row g-3">'
        . '<div class="col-md-3"><label class="form-label small" for="vf_fmt">Format</label><select class="form-select form-select-sm" id="vf_fmt" name="fmt">' . $fmtOpts . '</select></div>'
        . '<div class="col-md-3"><label class="form-label small" for="vf_ver">Version</label><input class="form-control form-control-sm" id="vf_ver" name="version" maxlength="64" required value="' . rmmH($v('version')) . '" placeholder="2.4.1">'
        . '<div class="form-text">Letters, digits and . + ~ : _ - or a space.</div></div>'
        . '<div class="col-md-4" data-rmm-when="#vf_fmt=apt,dnf"><label class="form-label small" for="vf_pkg">Package name</label><input class="form-control form-control-sm rmm-mono" id="vf_pkg" name="pkg_name" maxlength="100" value="' . rmmH($v('pkg_name')) . '" placeholder="htop">'
        . '<div class="form-text">Lower case. The machine\'s own repositories and their signature checks supply the files.</div></div>'
        . '<div class="col-md-2" data-rmm-when="#vf_fmt=apt,dnf"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="vf_pin" name="pin_version" value="1"' . ($v('pin_version') === '1' ? ' checked' : '') . '><label class="form-check-label small" for="vf_pin">Install exactly this version</label></div></div>'
        . '<div class="col-md-8" data-rmm-when="#vf_fmt=msi,exe"><label class="form-label small" for="vf_url">Installer address (https)</label><input class="form-control form-control-sm rmm-mono" id="vf_url" name="url" maxlength="600" value="' . rmmH($v('url')) . '" placeholder="https://downloads.example.com/app-2.4.1.msi">'
        . '<div class="form-text">No query string, port, user information or IP address.</div></div>'
        . '<div class="col-md-8" data-rmm-when="#vf_fmt=msi,exe"><label class="form-label small" for="vf_sha">SHA-256 of the installer</label><input class="form-control form-control-sm rmm-mono" id="vf_sha" name="sha256" maxlength="64" pattern="[0-9a-fA-F]{64}" value="' . rmmH($v('sha256')) . '"><div class="form-text">64 hex digits. The agent runs the file only if its hash equals this.</div></div>'
        . '<div class="col-md-4" data-rmm-when="#vf_fmt=msi,exe"><label class="form-label small" for="vf_size">Size in bytes</label><input class="form-control form-control-sm" type="number" min="1" max="1073741824" id="vf_size" name="size_bytes" value="' . rmmH($v('size_bytes')) . '"><div class="form-text">Exact, at most 1 GiB.</div></div>'
        . '<div class="col-md-6" data-rmm-when="#vf_fmt=msi"><label class="form-label small" for="vf_code">MSI product code</label><input class="form-control form-control-sm rmm-mono" id="vf_code" name="product_code" maxlength="38" value="' . rmmH($v('product_code')) . '" placeholder="{12345678-ABCD-1234-ABCD-1234567890AB}"><div class="form-text">How the agent detects and removes it.</div></div>'
        . '<div class="col-md-6" data-rmm-when="#vf_fmt=exe"><label class="form-label small" for="vf_args">Silent arguments</label><input class="form-control form-control-sm rmm-mono" id="vf_args" name="silent_args" maxlength="200" value="' . rmmH($v('silent_args')) . '" placeholder="/S /norestart"><div class="form-text">Letters, digits, spaces and / \\ - _ = . : , + only; no quotes.</div></div>'
        . '<div class="col-md-3" data-rmm-when="#vf_fmt=exe"><label class="form-label small" for="vf_dk">Detected by</label><select class="form-select form-select-sm" id="vf_dk" name="detect_kind"><option value="uninstall_name"' . ($v('detect_kind') === 'product_code' ? '' : ' selected') . '>Program name (Uninstall list)</option><option value="product_code"' . ($v('detect_kind') === 'product_code' ? ' selected' : '') . '>Product code</option></select></div>'
        . '<div class="col-md-5" data-rmm-when="#vf_fmt=exe"><label class="form-label small" for="vf_dv">Name or code</label><input class="form-control form-control-sm" id="vf_dv" name="detect_value" maxlength="200" value="' . rmmH($v('detect_value')) . '"></div>'
        . '<div class="col-md-4" data-rmm-when="#vf_fmt=msi,exe"><label class="form-label small" for="vf_min">Installed version at least (optional)</label><input class="form-control form-control-sm" id="vf_min" name="detect_min" maxlength="64" value="' . rmmH($v('detect_min')) . '" placeholder="2.4.1"></div>'
        . '</div><div class="mt-3"><button class="btn btn-sm btn-primary" type="submit">Add version</button> <span class="small text-muted ms-2">An exe is install-only; removing it is not supported.</span></div></form>';
}

/** The allowed installer hosts. @param array<string,mixed> $vm */
function rivetRmmSwHostsCard(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software.php';
    $can = $perm['admin'] && $vm['unrestricted'];
    $rows = '';
    foreach ($vm['hosts'] as $h) {
        $rows .= '<tr><td class="ps-3"><code class="rmm-mono">' . rmmH($h['host']) . '</code></td><td class="small rmm-wrap">' . rmmH($h['note']) . '</td><td class="small">' . rivetRmmUiTime((string) $h['created_at']) . '</td><td class="rmm-table-actions">'
            . ($can ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'remove_host', ['host' => $h['host']], $csrf, $self . '#hosts', 'Remove', 'btn btn-sm btn-outline-danger',
                'Stop allowing downloads from ' . $h['host'] . '? Versions that download from it are refused until it is allowed again.') : '') . '</td></tr>';
    }
    $tbl = $rows === '' ? '<p class="small text-muted px-3 pt-3 mb-2">No host is allowed, so no Windows installer can be added or downloaded. Packages for apt and dnf need no host.</p>'
        : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Hosts installers may be downloaded from</caption>' . rivetRmmUiThead(['Host', 'Note', 'Added', '']) . '<tbody>' . $rows . '</tbody></table></div>';
    $form = $can ? '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="p-3 border-top" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self . '#hosts') . '<input type="hidden" name="action" value="add_host">'
        . '<div class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label small" for="ho_host">Host name</label><input class="form-control form-control-sm rmm-mono" id="ho_host" name="host" maxlength="253" required placeholder="downloads.example.com"><div class="form-text">The exact name: example.com does not allow cdn.example.com.</div></div>'
        . '<div class="col-md-5"><label class="form-label small" for="ho_note">Note (optional)</label><input class="form-control form-control-sm" id="ho_note" name="note" maxlength="120"></div>'
        . '<div class="col-md-2"><button class="btn btn-sm btn-primary" type="submit">Allow host</button></div></div></form>'
        : '<p class="small text-muted p-3 mb-0 border-top">' . ($perm['admin'] ? 'The host list applies to every department, so it needs access to every department.' : 'Only an administrator can change the list of hosts installers may be downloaded from.') . '</p>';

    return rivetRmmUiCard('Allowed installer hosts', 'globe', '<p class="small text-muted px-3 pt-3 mb-2">A Windows installer is downloaded only from a host on this list, over https, and run only if its SHA-256 equals the pin. Taking a host off the list stops deployments that already use it.</p>' . $tbl . $form, '', '', true, 'hosts');
}

/** @param array<string,mixed> $vm rivetRmmSwCatalogView() */
function rivetRmmSwCatalogPage(array $vm): string
{
    $perm = $vm['perm'];
    $can = $perm['manage'] && $vm['unrestricted'];
    $new = $can && $vm['form'] === null ? '<a class="btn btn-sm btn-primary" href="/agent/rmm_software.php?new=1"><i class="fas fa-plus me-1" aria-hidden="true"></i>New package</a> ' : '';
    $o = rivetRmmAutoHeader('Software catalog', 'box-open', $new . rivetRmmSwNavLinks('rmm_software.php'),
        'The packages you can deploy. A version is immutable and carries its own pin: a package name from the machine\'s own repositories (apt, dnf), or a Windows installer with its https address, SHA-256 and size. Nothing is installed by adding to the catalog; an assignment or a run does that.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning">Software deployment was switched off while this page was open. Nothing below can be changed until it is switched on again.</div>';
    }
    if (!$perm['manage']) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-eye me-2" aria-hidden="true"></i>Your role can read the catalog but not change it.</div>';
    } elseif (!$vm['unrestricted']) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-info-circle me-2" aria-hidden="true"></i>The catalog applies to every department, so only a user with access to every department can change it.</div>';
    }
    if ($vm['edit_error'] !== '') {
        $o .= '<div class="alert alert-warning" role="alert">' . rmmH($vm['edit_error']) . '</div>';
    }
    if ($vm['form'] !== null && $can) {
        $o .= rivetRmmUiCard($vm['form'] === 'new' ? 'New package' : 'Change package', 'edit', rivetRmmSwPackageForm($vm), '', '', false, 'pkg-edit');
    } elseif ($vm['form'] === 'edit' && $vm['edit'] !== null) {
        $o .= rivetRmmUiCard(rmmH($vm['edit']['name']), 'box-open', '<p class="mb-1">' . rmmH($vm['edit']['description']) . '</p><p class="small text-muted mb-0">' . (!empty($vm['edit']['two_person']) ? 'Needs a second person.' : 'No second person needed.') . '</p>', '', '', false);
    }
    if ($vm['form'] === 'edit' && $vm['edit'] !== null) {
        $o .= rivetRmmSwVersionsCard($vm);
    }
    $o .= rivetRmmUiCard('Packages', 'box-open', rivetRmmSwPackagesTable($vm), '', '<span class="small text-muted">' . count($vm['packages']) . ' package' . (count($vm['packages']) === 1 ? '' : 's') . '</span>', true, 'packages')
        . rivetRmmSwHostsCard($vm);

    return $o . rivetRmmAutoConfirmModal();
}

/**
 * Turn the package form into the body Core takes. `two_person` is sent only when the form could change it (an administrator, or a package that is not yet two-person), so a catalog manager
 * saving a description never lowers the flag by accident.
 *
 * @param array<string,mixed> $post @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmSwPackageBody(array $post): array
{
    $str = static fn (string $k, int $max = 300): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $name = $str('name', 100);
    if ($name === '') {
        return [null, 'The package needs a name.'];
    }
    $body = ['name' => $name, 'publisher' => $str('publisher', 100), 'description' => $str('description', 300), 'enabled' => $str('enabled', 1) === '1'];
    if ($str('two_person_shown', 1) === '1') {
        $body['two_person'] = $str('two_person', 1) === '1';
    }

    return [$body, ''];
}

/**
 * Turn the version form into the body Core takes: only the fields that apply to the chosen format (Core refuses the others, even empty). The address, hash and arguments are typed by an
 * administrator and validated by Core; they are never taken from anywhere else.
 *
 * @param array<string,mixed> $post @return array{0:?array<string,mixed>,1:string}
 */
function rivetRmmSwVersionBody(array $post): array
{
    $str = static fn (string $k, int $max = 700): string => is_string($post[$k] ?? null) ? mb_substr(trim($post[$k]), 0, $max) : '';
    $fmt = $str('fmt', 5);
    if (!isset(RIVET_RMM_SW_OS[$fmt])) {
        return [null, 'Choose a format.'];
    }
    $body = ['version' => $str('version', 64), 'os' => RIVET_RMM_SW_OS[$fmt], 'fmt' => $fmt];
    if (RIVET_RMM_SW_OS[$fmt] === 'linux') {
        $body['pkg_name'] = $str('pkg_name', 100);
        $body['pin_version'] = $str('pin_version', 1) === '1';

        return [$body, ''];
    }
    $size = $str('size_bytes', 12);
    if ($size === '' || preg_match('/^\d{1,10}$/', $size) !== 1) {
        return [null, 'The size is the exact number of bytes of the installer (a whole number).'];
    }
    $body['url'] = $str('url', 700);
    $body['sha256'] = $str('sha256', 64);
    $body['size_bytes'] = (int) $size;
    if ($fmt === 'msi') {
        $body['product_code'] = $str('product_code', 40);
    } else {
        $body['silent_args'] = $str('silent_args', 200);
        $body['detect_kind'] = $str('detect_kind', 20);
        $body['detect_value'] = $str('detect_value', 200);
    }
    if ($str('detect_min', 64) !== '') {
        $body['detect_min'] = $str('detect_min', 64);
    }

    return [$body, ''];
}

// ------------------------------------------------------------------ assignments

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmSwAssignmentsView(array $ctx, array $get): array
{
    $vm = ['error' => '', 'assignments' => [], 'packages' => [], 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'uid' => $ctx['uid'], 'scope_data' => rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']), 'enabled' => true,
        'rings' => [], 'pending' => [], 'pending_error' => '', 'names' => [], 'pick' => null, 'unrestricted' => false];
    $r = rivetRmmSwApi($ctx, 'GET', ['software_assignments']);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The assignments could not be read.';

        return $vm;
    }
    $vm['assignments'] = (array) ($r['data']['assignments'] ?? []);
    $c = rivetRmmSwApi($ctx, 'GET', ['software_packages']);
    $vm['packages'] = $c['ok'] ? (array) ($c['data']['packages'] ?? []) : [];
    $vm['enabled'] = $c['ok'] ? !empty($c['data']['enabled']) : true;
    $vm['unrestricted'] = rivetRmmModule($ctx['mysqli'])->authorizer()->visibleClientIds($ctx['uid']) === null;
    $rg = rivetRmmSwApi($ctx, 'GET', ['patches', 'rings']);
    $vm['rings'] = $rg['ok'] ? (array) ($rg['data']['rings'] ?? $rg['data']['data'] ?? []) : [];
    $pid = ctype_digit((string) ($get['package_id'] ?? '')) ? (int) $get['package_id'] : 0;
    if ($pid > 0) {
        $p = rivetRmmSwApi($ctx, 'GET', ['software_packages', $pid]);
        $vm['pick'] = $p['ok'] ? $p['data'] : null;
    }
    $p = rivetRmmSwApi($ctx, 'GET', ['approvals'], ['kind' => 'software', 'state' => 'pending_approval', 'limit' => '50']);
    if ($p['ok']) {
        $vm['pending'] = (array) ($p['data']['data'] ?? []);
        $vm['names'] = rivetRmmSwUserNames($ctx['mysqli'], array_map(static fn (array $a): int => (int) $a['requested_by'], $vm['pending']));
    } else {
        $vm['pending_error'] = $p['error'];
    }

    return $vm;
}

/** @param array<string,mixed> $vm rivetRmmSwAssignmentsView() */
function rivetRmmSwAssignmentsTable(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software_assignments.php';
    if ($vm['assignments'] === []) {
        return rivetRmmUiEmpty('fas fa-boxes', 'Nothing is assigned', 'With no assignment, nothing is installed anywhere. Assign a package from the catalog to a scope below.');
    }
    $rows = '';
    foreach ($vm['assignments'] as $a) {
        $aid = (int) $a['assignment_id'];
        $label = rivetRmmAutoScopeLabel((string) $a['scope_type'], (int) $a['scope_id'], $vm['scope_data']);
        $state = !empty($a['waiting_for_approval']) ? rivetRmmUiPill('warn', 'Waiting for a second person') : (!empty($a['enabled']) ? rivetRmmUiPill('ok', 'Live') : rivetRmmUiPill('off', 'Off'));
        $rows .= '<tr><td class="ps-3 fw-semibold rmm-wrap">' . rmmH($a['package']) . '</td><td>' . rmmH(rivetRmmSwActionWord((string) $a['action'])) . '</td><td>' . ($a['version_id'] !== null ? 'version #' . (int) $a['version_id'] : '<span class="text-muted">newest that fits</span>')
            . '</td><td class="rmm-wrap">' . rmmH($label) . '</td><td>' . $state . ($a['activated_at'] !== null ? '<div class="small text-muted">since ' . rivetRmmUiTime((string) $a['activated_at']) . '</div>' : '')
            . '</td><td class="rmm-table-actions">' . ($perm['deploy'] ? rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'unassign', ['assignment_id' => $aid], $csrf, $self, '<i class="fas fa-times me-1" aria-hidden="true"></i>Remove', 'btn btn-sm btn-outline-danger',
                'Remove this assignment of ' . $a['package'] . ' from ' . $label . '? Nothing is uninstalled: software already installed stays installed.') : '') . '</td></tr>';
    }

    return '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Software assignments</caption>' . rivetRmmUiThead(['Package', 'Action', 'Version', 'Applies to', 'State', '']) . '<tbody>' . $rows . '</tbody></table></div>';
}

/** The ring explanation: a device's ring is the ring of its patch policy; its delay is added before a package is due. @param array<string,mixed> $vm */
function rivetRmmSwRingNote(array $vm): string
{
    $rings = '';
    foreach ($vm['rings'] as $g) {
        $rings .= '<span class="me-3"><code class="rmm-mono">' . rmmH((string) ($g['name'] ?? '')) . '</code> +' . (int) ($g['delay_days'] ?? 0) . ' d</span>';
    }

    return '<p class="small text-muted mb-2">Rings spread a rollout over time. A device\'s ring is the ring of its effective patch policy (Patch policies); the ring\'s delay in days is added before a package is due on it, and until then the report shows the device as '
        . '<em>scheduled</em>. A device with no ring waits for nothing.' . ($rings !== '' ? ' Rings now: ' . $rings : '') . '</p>';
}

/** @param array<string,mixed> $vm rivetRmmSwAssignmentsView() */
function rivetRmmSwAssignForm(array $vm): string
{
    $self = '/agent/rmm_software_assignments.php';
    $pick = $vm['pick'];
    $opts = '';
    foreach ($vm['packages'] as $p) {
        if (!empty($p['enabled'])) {
            $opts .= '<option value="' . (int) $p['package_id'] . '"' . ($pick !== null && (int) $pick['package_id'] === (int) $p['package_id'] ? ' selected' : '') . '>' . rmmH($p['name']) . '</option>';
        }
    }
    if ($opts === '') {
        return '<p class="small text-muted mb-0">The catalog has no package that is switched on. <a href="/agent/rmm_software.php">Open the catalog</a>.</p>';
    }
    $o = '<form method="get" action="' . $self . '" class="row g-2 align-items-end mb-3"><div class="col-md-6"><label class="form-label" for="as_pick">Package</label><select class="form-select" id="as_pick" name="package_id">' . $opts . '</select></div>'
        . '<div class="col-md-3"><button class="btn btn-outline-primary" type="submit">Use this package</button></div></form>';
    if ($pick === null) {
        return $o . '<p class="small text-muted mb-0">Choose a package, then say where it applies.</p>';
    }
    $versions = '<option value="">Newest version that fits the device (recommended)</option>';
    foreach ((array) ($pick['version_list'] ?? []) as $x) {
        if (empty($x['retired'])) {
            $versions .= '<option value="' . (int) $x['version_id'] . '">' . rmmH($x['version']) . ' (' . rmmH(rivetRmmSwFormatWord((string) $x['fmt'], (string) $x['os'])) . ')</option>';
        }
    }

    return $o . '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" data-rmm-once="1">' . rivetRmmAutoHidden((string) $vm['csrf'], $self . '?package_id=' . (int) $pick['package_id'])
        . '<input type="hidden" name="action" value="assign"><input type="hidden" name="package_id" value="' . (int) $pick['package_id'] . '">'
        . '<p class="mb-2"><strong>' . rmmH($pick['name']) . '</strong>' . (!empty($pick['two_person']) ? ' ' . rivetRmmUiPill('warn', 'a second person must approve every assignment') : '') . '</p>'
        . '<div class="row g-3 align-items-end"><div class="col-md-3"><label class="form-label" for="as_action">What to do</label><select class="form-select" id="as_action" name="package_action"><option value="install">Install it</option><option value="uninstall">Remove it</option></select></div>'
        . '<div class="col-md-5"><label class="form-label" for="as_ver">Version</label><select class="form-select" id="as_ver" name="version_id">' . $versions . '</select></div></div>'
        . '<div class="row g-3 align-items-end mt-1"><div class="col-md-5">' . rivetRmmAutoScopePicker('asg', ['global', 'client', 'site', 'group', 'tag', 'device'], $vm['unrestricted'] ? 'global' : 'client', 0, $vm['scope_data'], 'Applies to') . '</div>'
        . '<div class="col-md-4"><button class="btn btn-primary" type="submit"><i class="fas fa-link me-1" aria-hidden="true"></i>Assign</button></div></div>'
        . '<p class="small text-muted mt-2 mb-0">The most specific scope wins for a device (device, then tag, group, site, department, everything). ' . ($vm['unrestricted'] ? '' : 'You can assign to your own departments and their devices; everyone, a site, a group or a tag needs access to every department. ')
        . 'Assigning does not install anything at once: the scheduler looks at each device, asks what it has, and only after a dry run and (once live installs are switched on under Administration) a real install. Removing an assignment uninstalls nothing.</p></form>';
}

/** @param array<string,mixed> $vm rivetRmmSwAssignmentsView() */
function rivetRmmSwAssignmentsPage(array $vm): string
{
    $perm = $vm['perm'];
    $csrf = (string) $vm['csrf'];
    $self = '/agent/rmm_software_assignments.php';
    $o = rivetRmmAutoHeader('Software assignments', 'boxes', rivetRmmSwNavLinks('rmm_software_assignments.php'),
        'Which package should be on which devices. The scheduler compares each device with its assignments and fills the difference, only where it is allowed and never faster than the limits under Administration.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning">Software deployment was switched off while this page was open.</div>';
    }
    if (!$perm['deploy']) {
        $o .= '<div class="alert alert-info py-2"><i class="fas fa-eye me-2" aria-hidden="true"></i>Your role can read assignments but not create or remove them.</div>';
    }
    // ---- waiting for a second person
    if ($vm['pending'] !== [] || $vm['pending_error'] !== '') {
        $rows = '';
        foreach ($vm['pending'] as $a) {
            $id = (int) $a['approval_id'];
            $mine = (int) $a['requested_by'] === (int) $vm['uid'];
            $by = $vm['names'][(int) $a['requested_by']] ?? ('user #' . (int) $a['requested_by']);
            if ($perm['approve'] && $perm['deploy'] && !$mine) {
                $act = '<form method="post" action="/agent/post/' . RIVET_RMM_SW_HANDLER . '.php" class="d-flex flex-wrap align-items-end" style="gap:6px" data-rmm-once="1">' . rivetRmmAutoHidden($csrf, $self) . '<input type="hidden" name="approval_id" value="' . $id . '">'
                    . '<div><label class="form-label small mb-0" for="sa_note_' . $id . '">Note (optional)</label><input class="form-control form-control-sm" id="sa_note_' . $id . '" name="note" maxlength="300"></div>'
                    . '<button type="submit" name="action" value="decide_approval" class="btn btn-sm btn-success" data-rmm-confirm="Approve this request? ' . rmmH($a['summary']) . '. ' . (($a['device_count'] ?? 0) > 0 ? 'The job is queued on the device.' : 'The assignment goes live and the scheduler starts working on the devices in its scope.') . '" data-rmm-confirm-label="Approve"><i class="fas fa-check me-1" aria-hidden="true"></i>Approve</button>'
                    . '<button type="submit" name="action" value="reject_approval" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1" aria-hidden="true"></i>Reject</button></form>';
            } elseif ($mine) {
                $act = '<span class="text-muted small">You cannot approve your own request.</span> ' . rivetRmmAutoActionForm(RIVET_RMM_SW_HANDLER, 'cancel_approval', ['approval_id' => $id], $csrf, $self, 'Cancel', 'btn btn-sm btn-outline-secondary', 'Cancel this request? Nothing is changed.');
            } else {
                $act = '<span class="text-muted small">Your role cannot decide this request.</span>';
            }
            $rows .= '<tr><td class="ps-3 rmm-wrap">' . rmmH($a['summary']) . '</td><td>' . rmmH($by) . '<div class="small text-muted">' . rivetRmmUiTime((string) $a['requested_at']) . '</div></td><td>' . rivetRmmAutoUtc((string) $a['expires_at']) . '</td><td>' . $act . '</td></tr>';
        }
        $body = $vm['pending_error'] !== '' ? '<div class="alert alert-danger m-3" role="alert">' . rmmH($vm['pending_error']) . '</div>'
            : '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Software requests waiting for a second person</caption>' . rivetRmmUiThead(['Request', 'Requested by', 'Expires', 'Decision']) . '<tbody>' . $rows . '</tbody></table></div>';
        $o .= rivetRmmUiCard('Waiting for a second person', 'user-check', $body, 'warning', '<span class="small text-muted">' . count($vm['pending']) . ' waiting</span> <a class="small ms-2" href="/agent/rmm_approvals.php?kind=software">All software requests</a>', true, 'pending');
    }
    $o .= rivetRmmUiCard('Assignments', 'boxes', rivetRmmSwAssignmentsTable($vm), '', '<span class="small text-muted">' . count($vm['assignments']) . '</span>', true, 'assignments');
    if ($perm['deploy']) {
        $o .= rivetRmmUiCard('New assignment', 'link', rivetRmmSwRingNote($vm) . rivetRmmSwAssignForm($vm), '', '', false, 'assign');
    } else {
        $o .= rivetRmmUiCard('Rings', 'sitemap', rivetRmmSwRingNote($vm), '', '', false);
    }

    return $o . rivetRmmAutoConfirmModal();
}

/** Turn the assign form into the body Core takes. @param array<string,mixed> $post @return array{0:?array<string,mixed>,1:string} */
function rivetRmmSwAssignBody(array $post): array
{
    $pkg = is_string($post['package_id'] ?? null) && preg_match('/^\d{1,9}$/', $post['package_id']) === 1 ? (int) $post['package_id'] : 0;
    if ($pkg <= 0) {
        return [null, 'Choose a package first.'];
    }
    $scope = rivetRmmAutoReadScope('asg', ['global', 'client', 'site', 'group', 'tag', 'device']);
    if ($scope['type'] !== 'global' && $scope['id'] <= 0) {
        return [null, 'Choose where the package applies.'];
    }
    $action = ($post['package_action'] ?? '') === 'uninstall' ? 'uninstall' : 'install';
    $body = ['package_id' => $pkg, 'scope_type' => $scope['type'], 'action' => $action];
    if ($scope['type'] !== 'global') {
        $body['scope_id'] = $scope['id'];
    }
    $ver = is_string($post['version_id'] ?? null) ? trim($post['version_id']) : '';
    if ($ver !== '') {
        if (preg_match('/^\d{1,9}$/', $ver) !== 1) {
            return [null, 'The version is not valid.'];
        }
        $body['version_id'] = (int) $ver;
    }

    return [$body, ''];
}

/** Turn the run form into the body Core takes. @param array<string,mixed> $post @return array<string,mixed> */
function rivetRmmSwRunBody(array $post, string $mode): array
{
    $pkg = is_string($post['package_id'] ?? null) && preg_match('/^\d{1,9}$/', $post['package_id']) === 1 ? (int) $post['package_id'] : 0;
    $body = ['package_id' => $pkg, 'action' => ($post['package_action'] ?? '') === 'uninstall' ? 'uninstall' : 'install', 'mode' => $mode];
    if ($mode === 'run') {
        $body['confirm'] = true;   // the explicit confirmation of the form; Core refuses without it and re-checks every ability
    }

    return $body;
}

// ------------------------------------------------------------------ the report

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmSwReportFilters(array $get): array
{
    $by = is_string($get['group_by'] ?? null) && isset(RIVET_RMM_SW_GROUPS[$get['group_by']]) ? $get['group_by'] : 'package';
    $client = ctype_digit((string) ($get['client_id'] ?? '')) ? (int) $get['client_id'] : 0;
    $pkg = ctype_digit((string) ($get['package_id'] ?? '')) ? (int) $get['package_id'] : 0;
    $ring = is_string($get['ring'] ?? null) && preg_match('/^[a-z][a-z0-9_-]{0,38}$/D', $get['ring']) === 1 ? $get['ring'] : '';
    $state = is_string($get['state'] ?? null) && isset(RIVET_RMM_SW_STATES[$get['state']]) ? $get['state'] : '';

    return ['group_by' => $by, 'client_id' => $client, 'package_id' => $pkg, 'ring' => $ring, 'state' => $state, 'page' => max(1, min(1000, (int) ($get['page'] ?? 1)))];
}

/** @param array<string,mixed> $f @return array<string,string> */
function rivetRmmSwReportQuery(array $f, bool $paged = true): array
{
    $q = ['group_by' => $f['group_by']];
    foreach (['client_id', 'package_id'] as $k) {
        if ($f[$k] > 0) {
            $q[$k] = (string) $f[$k];
        }
    }
    if ($f['ring'] !== '') {
        $q['ring'] = $f['ring'];
    }
    if ($f['state'] !== '' && $f['group_by'] === 'device') {
        $q['state'] = $f['state'];
    }
    if ($paged) {
        $q['limit'] = (string) RIVET_RMM_SW_PAGE;
        $q['offset'] = (string) (($f['page'] - 1) * RIVET_RMM_SW_PAGE);
    } else {
        $q['limit'] = '1000';
    }

    return $q;
}

/**
 * @param array{uid:int,name:string,csrf:string,perm:array<string,bool>,mysqli:\mysqli} $ctx
 * @param array<string,mixed> $get
 * @return array<string,mixed>
 */
function rivetRmmSwReportView(array $ctx, array $get): array
{
    $f = rivetRmmSwReportFilters($get);
    $scope = rivetRmmAutoScopeData($ctx['mysqli'], $ctx['uid']);
    $vm = ['filters' => $f, 'error' => '', 'data' => [], 'enabled' => true, 'perm' => $ctx['perm'], 'csrf' => $ctx['csrf'], 'clients' => $scope['clients'], 'packages' => [], 'rings' => [], 'asset_ids' => []];
    $r = rivetRmmSwApi($ctx, 'GET', ['software_report'], rivetRmmSwReportQuery($f));
    $c = rivetRmmSwApi($ctx, 'GET', ['software_packages']);
    $vm['packages'] = $c['ok'] ? (array) ($c['data']['packages'] ?? []) : [];
    $rg = rivetRmmSwApi($ctx, 'GET', ['patches', 'rings']);
    $vm['rings'] = $rg['ok'] ? array_map(static fn (array $g): string => (string) ($g['name'] ?? ''), (array) ($rg['data']['rings'] ?? $rg['data']['data'] ?? [])) : [];
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The report could not be read.';

        return $vm;
    }
    $vm['data'] = $r['data'];
    $vm['enabled'] = !empty($r['data']['enabled']);
    if ($f['group_by'] === 'device') {
        $vm['asset_ids'] = rivetRmmSwAssetIds($ctx['mysqli'], (array) ($r['data']['rows'] ?? []));
    }

    return $vm;
}

function rivetRmmSwKpi(string $label, string $value, string $kind = '', string $hint = ''): string
{
    return '<div class="col-6 col-md-4 col-xl-3"><div class="card card-dark h-100"' . ($kind !== '' ? ' style="border-top:3px solid var(--tblr-' . rmmH($kind) . ')"' : '') . '><div class="card-body py-2"><div class="rmm-sw-kpi">' . $value . '</div><div class="small text-muted">' . rmmH($label) . '</div>'
        . ($hint !== '' ? '<div class="small text-muted">' . rmmH($hint) . '</div>' : '') . '</div></div></div>';
}

/** @param array<string,mixed> $vm rivetRmmSwReportView() */
function rivetRmmSwReportPage(array $vm): string
{
    $f = $vm['filters'];
    $csvQ = rivetRmmSwReportQuery($f, false);
    $o = rivetRmmAutoHeader('Software report', 'clipboard-check', '<a class="btn btn-sm btn-outline-primary" href="/agent/rmm_software_report_csv.php?' . rmmH(http_build_query($csvQ)) . '"><i class="fas fa-file-csv me-1" aria-hidden="true"></i>Download CSV</a> '
        . rivetRmmSwNavLinks('rmm_software_report.php'), 'Deployed against expected, by package, department, ring or device. Only what an assignment expects is counted. A cell that starts with = + - @ in the CSV is prefixed with an apostrophe so a spreadsheet never runs it.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . rmmH($vm['error']) . '</div>';
    }
    $d = $vm['data'];
    $o .= '<form method="get" action="/agent/rmm_software_report.php" class="card card-dark mb-3"><div class="card-body"><div class="row g-2 align-items-end">'
        . '<div class="col-md-2"><label class="form-label" for="sr_by">Group by</label><select class="form-select" id="sr_by" name="group_by">';
    foreach (RIVET_RMM_SW_GROUPS as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($f['group_by'] === $k ? ' selected' : '') . '>' . $w . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="sr_client">Department</label><select class="form-select" id="sr_client" name="client_id"><option value="">All I can see</option>';
    foreach ($vm['clients'] as $id => $name) {
        $o .= '<option value="' . (int) $id . '"' . ($f['client_id'] === (int) $id ? ' selected' : '') . '>' . rmmH($name) . '</option>';
    }
    $o .= '</select></div><div class="col-md-3"><label class="form-label" for="sr_pkg">Package</label><select class="form-select" id="sr_pkg" name="package_id"><option value="">Any package</option>';
    foreach ($vm['packages'] as $p) {
        $o .= '<option value="' . (int) $p['package_id'] . '"' . ($f['package_id'] === (int) $p['package_id'] ? ' selected' : '') . '>' . rmmH($p['name']) . '</option>';
    }
    $o .= '</select></div><div class="col-md-2"><label class="form-label" for="sr_ring">Ring</label>';
    if ($vm['rings'] !== []) {
        $o .= '<select class="form-select" id="sr_ring" name="ring"><option value="">Any ring</option>';
        foreach ($vm['rings'] as $g) {
            $o .= '<option value="' . rmmH($g) . '"' . ($f['ring'] === $g ? ' selected' : '') . '>' . rmmH($g) . '</option>';
        }
        $o .= '</select>';
    } else {
        $o .= '<input class="form-control" id="sr_ring" name="ring" maxlength="39" pattern="[a-z][a-z0-9_\-]{0,38}" value="' . rmmH($f['ring']) . '">';
    }
    $o .= '</div><div class="col-md-2" data-rmm-when="#sr_by=device"><label class="form-label" for="sr_state">State</label><select class="form-select" id="sr_state" name="state"><option value="">Any state</option>';
    foreach (RIVET_RMM_SW_STATES as $k => $w) {
        $o .= '<option value="' . $k . '"' . ($f['state'] === $k ? ' selected' : '') . '>' . rmmH($w) . '</option>';
    }
    $o .= '</select></div><div class="col-auto"><button class="btn btn-outline-primary" type="submit">Apply</button></div></div></div></form>';
    if (!$vm['enabled']) {
        $o .= '<div class="alert alert-warning">Software deployment was switched off while this page was open. The figures below are the last ones kept.</div>';
    }
    $rows = (array) ($d['rows'] ?? []);
    $by = $f['group_by'];
    $tot = $d['totals'] ?? null;
    if (is_array($tot)) {
        $pct = $tot['percent_compliant'];
        $o .= '<div class="row g-2 mb-3">' . rivetRmmSwKpi('Expected', number_format((int) $tot['expected']), '', 'package and device pairs')
            . rivetRmmSwKpi('Compliant', $pct === null ? 'no data' : rmmH((string) $pct) . ' %', $pct === null ? '' : ($pct >= 95 ? 'success' : ($pct >= 80 ? 'warning' : 'danger')), number_format((int) $tot['compliant']) . ' pairs')
            . rivetRmmSwKpi('Failed', number_format((int) $tot['failed']), (int) $tot['failed'] > 0 ? 'danger' : '')
            . rivetRmmSwKpi('Missing or still present', number_format((int) $tot['missing'] + (int) $tot['present']), (int) $tot['missing'] + (int) $tot['present'] > 0 ? 'warning' : '')
            . rivetRmmSwKpi('Pending', number_format((int) $tot['pending']), '', 'a job is waiting or running')
            . rivetRmmSwKpi('Scheduled', number_format((int) $tot['scheduled']), '', 'not due yet (ring delay)')
            . rivetRmmSwKpi('Not detected yet', number_format((int) $tot['unknown']), (int) $tot['unknown'] > 0 ? 'warning' : '')
            . '</div>';
    }
    if ($rows === []) {
        return $o . rivetRmmUiCard('Deployed against expected', 'clipboard-check', rivetRmmUiEmpty('fas fa-clipboard-check', 'Nothing is expected', 'A device appears here once an assignment reaches it and the scheduler has looked at it.'), '', '', true);
    }
    $body = '';
    if ($by === 'device') {
        foreach ($rows as $r) {
            $aid = $vm['asset_ids'][(int) $r['device_id']] ?? 0;
            $host = rmmH($r['hostname']);
            $body .= '<tr><td class="ps-3">' . ($aid > 0 ? '<a href="/agent/asset_details.php?asset_id=' . $aid . '#rmm-software">' . $host . '</a>' : $host) . '</td><td class="rmm-wrap">' . rmmH($r['package']) . '</td><td>' . rmmH(rivetRmmSwActionWord((string) $r['expected']))
                . ($r['expected_version'] !== null ? ' <span class="small text-muted">' . rmmH((string) $r['expected_version']) . '</span>' : '') . '</td><td>' . rivetRmmSwStatePill((string) $r['state']) . '</td><td>' . ($r['installed_version'] !== '' ? rmmH((string) $r['installed_version']) : '<span class="text-muted">-</span>')
                . '</td><td>' . ($r['ring'] !== '' ? rmmH((string) $r['ring']) : '<span class="text-muted">-</span>') . '</td><td>' . rivetRmmSwResultPill((string) $r['last_result']) . '</td></tr>';
        }
        $tbl = '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Deployed against expected, one row per device and package</caption>'
            . rivetRmmUiThead(['Device', 'Package', 'Expected', 'State', 'Installed', 'Ring', 'Last result']) . '<tbody>' . $body . '</tbody></table></div>';
        $url = static fn (int $p): string => '/agent/rmm_software_report.php?' . http_build_query(array_filter(['group_by' => 'device', 'client_id' => $f['client_id'], 'package_id' => $f['package_id'], 'ring' => $f['ring'], 'state' => $f['state'], 'page' => $p > 1 ? $p : ''], static fn ($v): bool => $v !== '' && $v !== 0));
        $pager = '';
        if ($f['page'] > 1 || count($rows) >= RIVET_RMM_SW_PAGE) {
            $pager = '<nav class="d-flex justify-content-between p-3 small" aria-label="Pages"><span>' . ($f['page'] > 1 ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($f['page'] - 1)) . '">Previous</a>' : '') . '</span><span>'
                . (count($rows) >= RIVET_RMM_SW_PAGE ? '<a class="btn btn-sm btn-outline-secondary" href="' . rmmH($url($f['page'] + 1)) . '">Next</a>' : '') . '</span></nav>';
        }

        return $o . rivetRmmUiCard('Deployed against expected, by device', 'clipboard-check', $tbl . $pager, '', '', true);
    }
    foreach ($rows as $r) {
        $label = $by === 'client' ? (string) ($vm['clients'][(int) $r['key']] ?? ('Department #' . (int) $r['key'])) : (string) $r['label'];
        $pct = $r['percent_compliant'];
        $body .= '<tr><td class="ps-3 fw-semibold rmm-wrap">' . rmmH($label) . '</td><td>' . (int) $r['expected'] . '</td><td>' . ($pct === null ? '<span class="text-muted">-</span>' : rivetRmmUiPill($pct >= 95 ? 'ok' : ($pct >= 80 ? 'warn' : 'crit'), $pct . ' %'))
            . '</td><td>' . (int) $r['compliant'] . '</td><td>' . ((int) $r['failed'] > 0 ? rivetRmmUiPill('crit', (string) (int) $r['failed']) : '0') . '</td><td>' . (int) $r['missing'] . '</td><td>' . (int) $r['present'] . '</td><td>' . (int) $r['pending']
            . '</td><td>' . (int) $r['scheduled'] . '</td><td>' . (int) $r['unknown'] . '</td></tr>';
    }
    $tbl = '<div class="table-responsive"><table class="table table-sm mb-0"><caption class="visually-hidden">Deployed against expected by ' . rmmH(strtolower(RIVET_RMM_SW_GROUPS[$by])) . '</caption>'
        . rivetRmmUiThead([RIVET_RMM_SW_GROUPS[$by], 'Expected', 'Compliant %', 'Compliant', 'Failed', 'Missing', 'Still present', 'Pending', 'Scheduled', 'Not detected']) . '<tbody>' . $body . '</tbody></table></div>';

    return $o . rivetRmmUiCard('Deployed against expected, by ' . strtolower(RIVET_RMM_SW_GROUPS[$by]), 'clipboard-check', $tbl, '', '', true);
}
