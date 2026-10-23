<?php

/*
 * RMM hypervisor visibility (RivetCore 1.0.0-rc.29, migration 0036): Proxmox VE and Hyper-V hosts, their VMs and storage. Off by default
 * (the `hypervisor` feature switch). Every read and write goes through rivetRmmAutoApi() (RivetCore's technician API as the signed-in user,
 * `endpoint_devices/hypervisor...`): Core decides who may see and do what (rmm.hypervisor.view / rmm.hypervisor.manage), this file only renders.
 *
 * Not verified against a real Proxmox or Hyper-V host (docs/rmm/HYPERVISORS.md section 9): the collector, the thresholds and this UI have only
 * ever been exercised against hand-seeded rows and a scratch database.
 */

require_once __DIR__ . '/rmm_automation.php';

const RIVET_RMM_HV_HANDLER = 'rmm_hypervisor';

/** @return array{ok:bool,status:int,data:array<string,mixed>,error:string,code:string} */
function rivetRmmHvCall(array $ctx, string $method, array $segments = [], ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], $method, array_merge(['hypervisor'], $segments), [], $body);
}

/** @return array{ok:bool,status:int,data:array<string,mixed>,error:string,code:string} */
function rivetRmmHvHostCall(array $ctx, string $method, int $deviceId, ?array $body = null): array
{
    return rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], $method, [$deviceId, 'hypervisor'], [], $body);
}

function rivetRmmHvH($v): string
{
    return nullable_htmlentities((string) $v);
}

/** Status pill kind for a VM/host state word. */
function rivetRmmHvStateKind(string $state): string
{
    return ['running' => 'ok', 'paused' => 'warn', 'suspended' => 'warn', 'transitioning' => 'warn', 'stopped' => 'off', 'error' => 'crit', 'unknown' => 'off'][$state] ?? 'off';
}

// ------------------------------------------------------------------ hosts list

/** @return array{error:string,ready:bool,enabled:bool,hosts:list<array<string,mixed>>,total:int,summary:array<string,int>,admin:bool} */
function rivetRmmHvHostsView(array $ctx, array $get): array
{
    $f = [];
    foreach (['q', 'platform', 'client_id'] as $k) {
        if (is_string($get[$k] ?? null) && trim((string) $get[$k]) !== '') {
            $f[$k] = trim((string) $get[$k]);
        }
    }
    if (($get['problems'] ?? '') === '1') {
        $f['problems'] = '1';
    }
    $vm = ['error' => '', 'ready' => false, 'enabled' => false, 'hosts' => [], 'total' => 0, 'summary' => ['hosts' => 0, 'vms' => 0, 'running' => 0, 'ignored' => 0, 'missing' => 0],
        'admin' => !empty($ctx['perm']['admin'])];
    // rivetRmmHvCall() has no query-string support (every other RMM admin page's *Call() helper is the same), so call rivetRmmAutoApi() directly here with the filters as the query.
    $r = rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], 'GET', ['hypervisor'], $f, null);
    if (!$r['ok']) {
        $vm['error'] = $r['error'] !== '' ? $r['error'] : 'The hypervisor hosts could not be read.';

        return $vm;
    }
    $vm['ready'] = true;
    $vm['enabled'] = !empty($r['data']['enabled']);
    $vm['hosts'] = array_values((array) ($r['data']['data'] ?? []));
    $vm['total'] = (int) ($r['data']['total'] ?? 0);
    $vm['summary'] = (array) ($r['data']['summary'] ?? $vm['summary']);

    return $vm;
}

function rivetRmmHvHostsPage(array $vm, array $ctx): string
{
    $h = 'rivetRmmHvH';
    $o = rivetRmmAutoHeader('Hypervisors', 'server', '', 'Proxmox VE and Hyper-V hosts that an endpoint agent reports on: their VMs and storage.');
    if ($vm['error'] !== '') {
        return $o . '<div class="alert alert-danger" role="alert">' . $h($vm['error']) . '</div>';
    }
    if (!$vm['enabled']) {
        return $o . '<div class="rmmx-info" role="note"><i class="fas fa-info-circle" aria-hidden="true"></i><div><strong>Hypervisor visibility is off.</strong> It is off by default and nothing is stored until '
            . ($vm['admin'] ? 'you switch it on under <a href="/admin/settings_endpoint_agent.php">Administration &gt; Endpoint agent &gt; Hypervisors</a>' : 'an administrator switches it on')
            . ', roll out an agent that announces "hypervisor", then try one host first.</div></div>'
            . '<div class="rmmx-card">' . rmmxEmpty('fa-server', 'No hypervisor data yet', 'Hosts appear here after their first report.') . '</div>';
    }
    $hosts = $vm['hosts'];
    $problem = static fn (array $x): bool => !empty($x['stale']) || ($x['error'] ?? '') !== '' || (int) ($x['storages_unhealthy'] ?? 0) > 0 || (($x['cluster']['quorate'] ?? null) === false);
    $withProblems = 0;
    $clusters = [];
    $stopped = 0;
    foreach ($hosts as $x) {
        $withProblems += $problem($x) && empty($x['ignored']) ? 1 : 0;
        if (!empty($x['cluster']['name'])) { $clusters[$x['cluster']['name']] = true; }
        $stopped += max(0, (int) $x['vm_count'] - (int) $x['vms_running'] - (int) ($x['vms_missing'] ?? 0));
    }
    $s = $vm['summary'];
    $qs = static fn (array $q): string => '?' . http_build_query($q);
    $o .= rmmxKpiGrid([
        ['icon' => 'fa-server', 'label' => 'Hosts', 'value' => (int) $s['hosts'], 'sub' => count($clusters) . ' cluster' . (count($clusters) === 1 ? '' : 's') . ', ' . ((int) $s['hosts'] - array_sum(array_map(static fn ($x) => !empty($x['cluster']['name']) ? 1 : 0, $hosts))) . ' standalone', 'tone' => 'info', 'href' => $qs([]), 'title' => 'Show every host'],
        ['icon' => 'fa-cubes', 'label' => 'Virtual machines', 'value' => (int) $s['vms'], 'sub' => (int) $s['running'] . ' running, ' . $stopped . ' stopped', 'tone' => 'neutral', 'title' => 'All VMs on the hosts below'],
        ['icon' => 'fa-play-circle', 'label' => 'Running', 'value' => (int) $s['running'], 'sub' => (int) $s['vms'] > 0 ? round(100 * (int) $s['running'] / max(1, (int) $s['vms'])) . '% of VMs' : '', 'tone' => 'ok', 'title' => 'VMs currently running'],
        ['icon' => 'fa-exclamation-triangle', 'label' => 'Hosts with problems', 'value' => $withProblems, 'sub' => 'Stale report, error or unhealthy storage', 'tone' => 'warn', 'href' => $qs(['problems' => 1]), 'active' => ($_GET['problems'] ?? '') === '1', 'title' => 'Show only hosts with a problem'],
        ['icon' => 'fa-eye-slash', 'label' => 'Ignored', 'value' => (int) $s['ignored'], 'sub' => 'Hidden from alerts', 'tone' => 'neutral', 'title' => 'Hosts an administrator chose to ignore'],
    ]);
    $platform = is_string($_GET['platform'] ?? null) ? $_GET['platform'] : '';
    $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
    $o .= '<form method="get" class="rmmx-toolbar" role="search" aria-label="Filter hosts"><div class="rmmx-field rmmx-grow"><label for="hv-q">Search</label><input class="form-control" type="search" id="hv-q" name="q" value="' . $h($q) . '" placeholder="Host name"></div>'
        . '<div class="rmmx-field"><label for="hv-platform">Platform</label><select class="form-select" id="hv-platform" name="platform" data-searchable="0"><option value="">Any platform</option><option value="proxmox"' . ($platform === 'proxmox' ? ' selected' : '') . '>Proxmox VE</option><option value="hyperv"' . ($platform === 'hyperv' ? ' selected' : '') . '>Hyper-V</option></select></div>'
        . '<div class="rmmx-field"><label for="hv-problems">Status</label><select class="form-select" id="hv-problems" name="problems" data-searchable="0"><option value="">All hosts</option><option value="1"' . (($_GET['problems'] ?? '') === '1' ? ' selected' : '') . '>Problems only</option></select></div>'
        . '<div class="rmmx-actions"><button class="btn btn-primary" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>Apply</button><a class="btn btn-outline-secondary" href="?">Reset</a></div></form>';
    $chips = [];
    if ($q !== '') { $chips['Search: ' . $q] = $qs(array_filter(['platform' => $platform, 'problems' => $_GET['problems'] ?? ''])); }
    if ($platform !== '') { $chips['Platform: ' . ($platform === 'proxmox' ? 'Proxmox VE' : 'Hyper-V')] = $qs(array_filter(['q' => $q, 'problems' => $_GET['problems'] ?? ''])); }
    if (($_GET['problems'] ?? '') === '1') { $chips['Problems only'] = $qs(array_filter(['q' => $q, 'platform' => $platform])); }
    $o .= rmmxChips($chips, '?', count($hosts) . ' host' . (count($hosts) === 1 ? '' : 's') . ($chips ? ' match' : ''));
    if ($hosts === []) {
        return $o . '<div class="rmmx-card">' . rmmxEmpty('fa-server', $chips ? 'No host matches these filters' : 'No hypervisor host has reported yet', $chips ? 'Try clearing a filter.' : 'A Proxmox VE or Hyper-V host with an agent that announces "hypervisor" appears here after its first report.', $chips ? '<a class="btn btn-outline-secondary" href="?">Clear filters</a>' : '') . '</div>';
    }
    $rows = '';
    foreach ($hosts as $hst) {
        $isProb = $problem($hst) && empty($hst['ignored']);
        $icon = $hst['platform'] === 'proxmox' ? 'fa-server' : 'fa-windows';
        $iconCls = $hst['platform'] === 'proxmox' ? 'fas' : 'fab';
        $cluster = $hst['cluster'] !== null
            ? ($hst['cluster']['quorate'] === false ? rivetRmmUiPill('crit', 'No quorum') . ' <span class="rmmx-tag">' . $h($hst['cluster']['name']) . '</span>' : '<span class="rmmx-tag" title="Cluster">' . $h($hst['cluster']['name']) . '</span>')
            : '<span class="text-muted small">standalone</span>';
        $run = (int) $hst['vms_running']; $cnt = (int) $hst['vm_count'];
        $pct = $cnt > 0 ? (int) round(100 * $run / $cnt) : 0;
        $vms = '<div class="rmmx-ratio" title="' . $run . ' of ' . $cnt . ' VMs running"><span class="rmmx-num">' . $run . ' / ' . $cnt . '</span><span class="rmmx-meter" aria-hidden="true"><span style="width:' . $pct . '%"></span></span></div>'
            . ($hst['vms_missing'] > 0 ? '<div class="small text-muted">' . (int) $hst['vms_missing'] . ' missing</div>' : '');
        $storage = $hst['storages_unhealthy'] > 0 ? rivetRmmUiPill('warn', (int) $hst['storages_unhealthy'] . ' of ' . (int) $hst['storages'] . ' unhealthy') : rivetRmmUiPill('ok', (int) $hst['storages'] . ' storage ok');
        $stat = '';
        if (!empty($hst['ignored'])) { $stat = rivetRmmUiPill('off', 'Ignored'); }
        elseif ($hst['error'] !== '') { $stat = '<span class="rmmx-pill rmmx-pill-crit" title="' . $h($hst['error']) . '"><i class="fas fa-times-circle" aria-hidden="true"></i>Collector error</span>'; }
        elseif ($hst['stale']) { $stat = rivetRmmUiPill('warn', 'Report is stale'); }
        else { $stat = rivetRmmUiPill('ok', 'Healthy'); }
        $err = $hst['error'] !== '' ? '<div class="rmmx-errline" title="' . $h($hst['error']) . '">' . $h(mb_strimwidth($hst['error'], 0, 90, '…')) . '</div>' : '';
        $ts = $hst['last_collected_at'];
        $rows .= '<tr class="' . ($isProb ? 'rmmx-sev-warning' : '') . (!empty($hst['ignored']) ? ' is-ignored' : '') . '" data-name="' . $h(mb_strtolower($hst['hostname'])) . '" data-platform="' . $h($hst['platform']) . '" data-vms="' . $cnt . '" data-run="' . $run . '" data-report="' . (int) strtotime((string) $ts) . '" data-ignored="' . (!empty($hst['ignored']) ? 1 : 0) . '">'
            . '<td class="rmmx-title"><a class="fw-semibold" href="?host_id=' . (int) $hst['device_id'] . '"><i class="' . $iconCls . ' ' . $icon . ' me-2 text-secondary" aria-hidden="true"></i>' . $h($hst['hostname']) . '</a>'
            . ($hst['asset_id'] !== null ? ' <a class="small text-muted ms-1" href="/agent/asset_details.php?client_id=' . (int) $hst['client_id'] . '&asset_id=' . (int) $hst['asset_id'] . '" title="Open device" aria-label="Open the device ' . $h($hst['hostname']) . '"><i class="fas fa-external-link-alt" aria-hidden="true"></i></a>' : '') . $err . '</td>'
            . '<td>' . $stat . '</td>'
            . '<td><span class="rmmx-os"><i class="' . $iconCls . ' ' . $icon . '" aria-hidden="true"></i><span>' . $h($hst['platform'] === 'proxmox' ? 'Proxmox VE' : 'Hyper-V') . '</span></span><div class="small text-muted">' . $h($hst['version']) . '</div></td>'
            . '<td>' . $cluster . '</td><td>' . $vms . '</td><td>' . $storage . '</td>'
            . '<td class="rmmx-when">' . rivetRmmUiTime($ts) . '</td></tr>';
    }
    $th = static fn (string $key, string $label): string => '<th scope="col" aria-sort="none"><button type="button" class="rmmx-sort" data-sort="' . $key . '">' . $label . '<i class="fas fa-sort" aria-hidden="true"></i></button></th>';

    return $o . '<div class="rmmx-card"><div class="rmmx-scroll"><table class="rmmx-table" id="hv-hosts"><caption class="visually-hidden">Hypervisor hosts</caption><thead><tr>'
        . $th('name', 'Host') . '<th scope="col">Status</th>' . $th('platform', 'Platform') . '<th scope="col">Cluster</th>' . $th('run', 'VMs running') . '<th scope="col">Storage</th>' . $th('report', 'Last report') . '</tr></thead><tbody>' . $rows . '</tbody></table></div></div>'
        . '<script src="/js/rmm_table_sort.js?v=' . (int) @filemtime(dirname(__DIR__) . '/js/rmm_table_sort.js') . '"></script>';
}

// ------------------------------------------------------------------ one host

/** @return array{error:string,host:?array<string,mixed>} */
function rivetRmmHvHostView(array $ctx, int $deviceId, array $get): array
{
    $f = [];
    foreach (['q', 'state', 'type', 'template', 'ignored', 'missing', 'limit', 'offset'] as $k) {
        if (is_string($get[$k] ?? null) && trim((string) $get[$k]) !== '') {
            $f[$k] = trim((string) $get[$k]);
        }
    }
    $r = rivetRmmAutoApi($ctx['mysqli'], (int) $ctx['uid'], (string) $ctx['name'], 'GET', [$deviceId, 'hypervisor'], $f, null);
    if (!$r['ok']) {
        return ['error' => $r['error'] !== '' ? $r['error'] : 'This host has not reported, or you may not see it.', 'host' => null];
    }

    return ['error' => '', 'host' => (array) $r['data']];
}

function rivetRmmHvHostPage(array $vm, array $ctx): string
{
    $h = 'rivetRmmHvH';
    if ($vm['error'] !== '') {
        return rivetRmmAutoHeader('Hypervisor host', 'server') . '<div class="alert alert-warning" role="alert">' . $h($vm['error']) . ' <a href="rmm_hypervisors.php">Back to hosts</a></div>';
    }
    $hst = $vm['host'];
    $csrf = (string) $ctx['csrf'];
    $can = !empty($ctx['perm']['admin']) || !empty($ctx['perm']['manage']);
    $acts = $can ? rivetRmmAutoActionForm(RIVET_RMM_HV_HANDLER, $hst['ignored'] ? 'unignore_host' : 'ignore_host', ['device_id' => $hst['device_id']], $csrf, '/agent/rmm_hypervisors.php?host_id=' . (int) $hst['device_id'],
        $hst['ignored'] ? '<i class="fas fa-eye me-1" aria-hidden="true"></i>Stop ignoring' : '<i class="fas fa-eye-slash me-1" aria-hidden="true"></i>Ignore this host', 'btn btn-sm btn-outline-secondary',
        $hst['ignored'] ? '' : 'Stop storing what "' . $hst['hostname'] . '" reports? Its VMs and storage are kept but no longer updated.') : '';
    $o = rivetRmmAutoHeader($hst['hostname'], 'server', $acts, ($hst['platform'] === 'proxmox' ? 'Proxmox VE' : 'Hyper-V') . ' ' . $hst['version'] . '.'
        . ($hst['cluster'] !== null ? ' Cluster "' . $hst['cluster']['name'] . '"' . ($hst['cluster']['quorate'] === false ? ', no quorum.' : ', quorate.') : ' Standalone.'));
    $o .= '<p class="small"><a href="rmm_hypervisors.php">&larr; All hosts</a>'
        . ($hst['asset_id'] !== null ? ' &middot; <a href="/agent/asset_details.php?client_id=' . (int) $hst['client_id'] . '&asset_id=' . (int) $hst['asset_id'] . '"><i class="fas fa-external-link-alt me-1" aria-hidden="true"></i>Open the asset</a>' : '') . '</p>';

    // Storage
    $srows = '';
    foreach ($hst['storage'] as $s) {
        $srows .= '<tr><td class="ps-3">' . $h($s['name']) . ($s['shared'] ? ' <span class="badge bg-secondary-lt text-secondary">shared</span>' : '') . '</td><td>' . $h($s['type']) . '</td>'
            . '<td>' . ($s['used_pct'] === null ? '<span class="text-muted small">unsized</span>' : $s['used_pct'] . '%') . '</td>'
            . '<td>' . rivetRmmUiPill(['ok' => 'ok', 'warn' => 'warn', 'error' => 'crit'][$s['health']] ?? 'off', ucfirst($s['health'])) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Storage', 'hdd', $hst['storage'] === [] ? rivetRmmUiEmpty('fas fa-hdd', 'No storage reported') :
        '<div class="table-responsive"><table class="table table-sm mb-0">' . rivetRmmUiThead(['Name', 'Type', 'Used', 'Health']) . '<tbody>' . $srows . '</tbody></table></div>', '', '', true);

    // VM filters + table
    $o .= '<form method="get" class="row g-2 align-items-end mb-2 mt-3"><input type="hidden" name="host_id" value="' . (int) $hst['device_id'] . '">'
        . '<div class="col-auto"><label class="form-label small mb-1" for="hv-vm-q">Search VMs</label><input class="form-control form-control-sm" id="hv-vm-q" name="q" value="' . $h($_GET['q'] ?? '') . '"></div>'
        . '<div class="col-auto"><label class="form-label small mb-1" for="hv-vm-state">State</label><select class="form-select form-select-sm" id="hv-vm-state" name="state"><option value="">Any</option>'
        . implode('', array_map(static fn (string $s): string => '<option value="' . $s . '"' . (($_GET['state'] ?? '') === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>', ['running', 'stopped', 'paused', 'suspended', 'transitioning', 'error', 'unknown']))
        . '</select></div>'
        . '<div class="col-auto"><button class="btn btn-sm btn-outline-secondary" type="submit">Filter</button></div></form>';
    $vrows = '';
    foreach ($hst['vms'] as $v) {
        $vrows .= '<tr' . ($v['ignored'] ? ' class="rmm-dim"' : '') . '><td class="ps-3"><a href="?host_id=' . (int) $hst['device_id'] . '&vm_id=' . (int) $v['vm_id'] . '">' . $h($v['name']) . '</a>'
            . ($v['template'] ? ' <span class="badge bg-secondary-lt text-secondary">template</span>' : '') . ($v['ignored'] ? ' ' . rivetRmmUiPill('off', 'Ignored') : '') . '</td>'
            . '<td>' . $h(strtoupper($v['type'])) . '</td>'
            . '<td>' . rivetRmmUiPill(rivetRmmHvStateKind($v['state']), ucfirst($v['state'])) . '</td>'
            . '<td>' . ($v['cpu_pct'] === null ? '<span class="text-muted small">&mdash;</span>' : round($v['cpu_pct'], 1) . '%') . '</td>'
            . '<td>' . ($v['mem_bytes'] === null ? '<span class="text-muted small">&mdash;</span>' : rivetRmmUiBytes((float) $v['mem_bytes'])) . '</td>'
            . '<td>' . ($v['last_backup'] === null ? '<span class="text-muted small">none</span>' : rivetRmmUiPill($v['last_backup']['result'] === 'ok' ? 'ok' : 'warn', ucfirst($v['last_backup']['result']))) . '</td>'
            . '<td class="small text-muted">' . $h($v['node']) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('Virtual machines', 'desktop', $hst['vms'] === [] ? rivetRmmUiEmpty('fas fa-desktop', 'No VM matches') :
        '<div class="table-responsive"><table class="table table-sm table-hover mb-0">' . rivetRmmUiThead(['VM', 'Type', 'State', 'CPU', 'Memory', 'Last backup', 'Node']) . '<tbody>' . $vrows . '</tbody></table></div>', '', '', true);

    return $o;
}

// ------------------------------------------------------------------ one VM

/** @return array{error:string,vm:?array<string,mixed>} */
function rivetRmmHvVmView(array $ctx, int $vmId): array
{
    $r = rivetRmmHvCall($ctx, 'GET', ['vms', $vmId]);
    if (!$r['ok']) {
        return ['error' => $r['error'] !== '' ? $r['error'] : 'That VM was not found, or you may not see it.', 'vm' => null];
    }

    return ['error' => '', 'vm' => (array) $r['data']];
}

/**
 * Asset hints for a VM (RivetCore's HypervisorLinker, "suggest only": matches by hostname/short-name, never by MAC - the
 * collectors never read VM network configuration at all, so a MAC match never actually fires from this data source). Nothing
 * is linked or created; this is purely "you might mean this existing asset" for a technician to act on themselves.
 *
 * @return list<array{asset_id:int,asset_name:string,client_id:int,matched_by:string,confidence:string,same_client:bool}>
 */
function rivetRmmHvVmAssetSuggestions(array $ctx, int $vmId): array
{
    $r = rivetRmmHvCall($ctx, 'GET', ['vms', $vmId, 'asset-suggestions']);

    return $r['ok'] && is_array($r['data']['data'] ?? null) ? (array) $r['data']['data'] : [];
}

function rivetRmmHvVmPage(array $vm, array $ctx): string
{
    $h = 'rivetRmmHvH';
    if ($vm['error'] !== '') {
        return rivetRmmAutoHeader('VM', 'desktop') . '<div class="alert alert-warning" role="alert">' . $h($vm['error']) . '</div>';
    }
    $v = $vm['vm'];
    $csrf = (string) $ctx['csrf'];
    $can = !empty($ctx['perm']['admin']) || !empty($ctx['perm']['manage']);
    $back = '/agent/rmm_hypervisors.php?host_id=' . (int) $v['device_id'];
    $acts = $can ? rivetRmmAutoActionForm(RIVET_RMM_HV_HANDLER, $v['ignored'] ? 'unignore_vm' : 'ignore_vm', ['vm_id' => $v['vm_id']], $csrf, $back . '&vm_id=' . (int) $v['vm_id'],
        $v['ignored'] ? '<i class="fas fa-eye me-1" aria-hidden="true"></i>Stop ignoring' : '<i class="fas fa-eye-slash me-1" aria-hidden="true"></i>Ignore this VM', 'btn btn-sm btn-outline-secondary',
        $v['ignored'] ? '' : 'Ignore "' . $v['name'] . '"? Its alerts are silenced; the row is kept.') : '';
    $o = rivetRmmAutoHeader($v['name'], 'desktop', $acts, strtoupper($v['type']) . ' on ' . $v['host'] . ', node ' . $v['node'] . '. ' . ucfirst($v['state']) . '.');
    $o .= '<p class="small"><a href="' . $h($back) . '">&larr; ' . $h($v['host']) . '</a></p>';

    $facts = '';
    $row = static fn (string $k, string $val): string => '<dt class="col-5 col-md-4 text-muted fw-normal">' . $k . '</dt><dd class="col-7 col-md-8 mb-1">' . $val . '</dd>';
    $facts .= $row('State', rivetRmmUiPill(rivetRmmHvStateKind($v['state']), ucfirst($v['state'])) . ($v['state_changed_at'] ? ' <span class="small text-muted">since ' . $h(rivetRmmUiTime($v['state_changed_at'])) . '</span>' : ''));
    $facts .= $row('CPU', $v['cpus'] . ' vCPU' . ($v['cpu_pct'] !== null ? ', ' . round($v['cpu_pct'], 1) . '% used' : ''));
    $facts .= $row('Memory', $v['mem_bytes'] !== null ? rivetRmmUiBytes((float) $v['mem_bytes']) . ($v['mem_max_bytes'] !== null ? ' / ' . rivetRmmUiBytes((float) $v['mem_max_bytes']) : '') : '<span class="text-muted">not reported</span>');
    $facts .= $row('Disk', $v['disk_bytes'] !== null ? rivetRmmUiBytes((float) $v['disk_bytes']) . ($v['disk_used_bytes'] !== null ? ', ' . rivetRmmUiBytes((float) $v['disk_used_bytes']) . ' used' : '') : '<span class="text-muted">not reported</span>');
    $facts .= $row('Uptime', $v['uptime_s'] !== null ? rivetRmmUiDuration((int) $v['uptime_s']) : '<span class="text-muted">not reported</span>');
    if ($v['tags'] !== []) {
        $facts .= $row('Tags', implode(' ', array_map(static fn (string $t): string => '<span class="badge bg-secondary-lt text-secondary me-1">' . $h($t) . '</span>', $v['tags'])));
    }
    $facts .= $row('Snapshots', $v['snapshots'] === null ? '<span class="text-muted">not reported</span>' : (int) $v['snapshots']['count'] . ($v['snapshots']['oldest_at'] ? ', oldest ' . $h(rivetRmmUiTime($v['snapshots']['oldest_at'])) : ''));
    $facts .= $row('Last backup', $v['last_backup'] === null ? '<span class="text-muted">none</span>' : rivetRmmUiPill($v['last_backup']['result'] === 'ok' ? 'ok' : 'warn', ucfirst($v['last_backup']['result'])) . ' ' . $h(rivetRmmUiTime($v['last_backup']['at'])));
    $facts .= $row('Guest agent', $v['guest_agent'] === null ? '<span class="text-muted">not reported</span>' : ($v['guest_agent'] ? 'Running' : 'Not detected'));
    if ($v['ignored']) {
        $facts .= $row('Ignored', $h($v['ignored_reason'] !== '' ? $v['ignored_reason'] : 'no reason given'));
    }
    $o .= rivetRmmUiCard('Facts', 'info-circle', '<dl class="row small mb-0 px-3 pt-2">' . $facts . '</dl>', '', '', true);

    // Asset hints (HypervisorLinker, "suggest only"): a name/hostname match against this app's own assets, nothing linked or
    // created automatically. The collectors never read VM network config, so a match is always by name, never a MAC.
    $suggestions = rivetRmmHvVmAssetSuggestions($ctx, (int) $v['vm_id']);
    if ($suggestions !== []) {
        $srows = '';
        foreach ($suggestions as $s) {
            $confCls = ['high' => 'ok', 'medium' => 'warn', 'low' => 'off'][$s['confidence']] ?? 'off';
            $srows .= '<tr><td class="ps-3"><a href="/agent/asset_details.php?client_id=' . (int) $s['client_id'] . '&asset_id=' . (int) $s['asset_id'] . '">' . $h($s['asset_name']) . '</a>'
                . (empty($s['same_client']) ? ' <span class="small text-muted">(a different client)</span>' : '') . '</td>'
                . '<td>' . rivetRmmUiPill($confCls, ucfirst((string) $s['confidence'])) . '</td>'
                . '<td class="small text-muted">matched by ' . $h($s['matched_by']) . '</td></tr>';
        }
        $o .= rivetRmmUiCard('This might be an existing asset', 'link', '<p class="small text-muted px-3 pt-2 mb-1">A name match only - nothing is linked automatically. Open the asset to confirm, or ignore this if it is not the same device.</p>'
            . '<div class="table-responsive"><table class="table table-sm mb-0">' . rivetRmmUiThead(['Asset', 'Confidence', '']) . '<tbody>' . $srows . '</tbody></table></div>', '', '', true);
    }

    if ($v['alerts'] !== []) {
        $arows = '';
        foreach ($v['alerts'] as $a) {
            $arows .= '<tr><td class="ps-3">' . rivetRmmUiPill($a['status'] === 'fail' ? 'crit' : 'warn', ucfirst($a['status'])) . '</td><td class="rmm-wrap">' . $h($a['detail']) . '</td><td class="small text-muted">' . $h(rivetRmmUiTime($a['since'])) . '</td></tr>';
        }
        $o .= rivetRmmUiCard('Open alerts', 'bell', '<div class="table-responsive"><table class="table table-sm mb-0">' . rivetRmmUiThead(['Status', 'Detail', 'Since']) . '<tbody>' . $arows . '</tbody></table></div>', '', '', true);
    }

    $hrows = '';
    foreach ($v['history'] as $hist) {
        $hrows .= '<tr><td class="small text-muted">' . $h(rivetRmmUiTime($hist['at'])) . '</td><td>' . $h($hist['from'] === '' ? '(new)' : ucfirst($hist['from'])) . ' &rarr; ' . $h(ucfirst($hist['to'])) . '</td></tr>';
    }
    $o .= rivetRmmUiCard('State history', 'history', $v['history'] === [] ? rivetRmmUiEmpty('fas fa-history', 'No state change recorded yet') :
        '<div class="table-responsive"><table class="table table-sm mb-0">' . rivetRmmUiThead(['When', 'Change']) . '<tbody>' . $hrows . '</tbody></table></div>', '', '', true);

    return $o;
}

// ------------------------------------------------------------------ the per-device panel (asset page / RMM device page)

/**
 * The compact card for a device's own page (agent/asset_details.php, agent/rmm_agent_device.php via includes/rmm_ui_render.php's shared
 * rivetRmmUiPanel()), same convention as every other RMM tab section (rivetRmmPatPanelSection(), rivetRmmSwPanelSection(), ...): '' when
 * the hypervisor feature is off, the viewer may not see it, or (the common case) this particular device simply isn't a reporting
 * hypervisor host, so the tab is never drawn. A summary and a link to the full host page (/agent/rmm_hypervisors.php?host_id=) — the VM
 * table, filters and storage detail are not duplicated here, same as the Software tab links out to the fuller catalog.
 *
 * @param array<string,mixed> $vm rivetRmmUiPanel()'s view-model (device_id, user_id, features)
 */
function rivetRmmHvPanelSection(array $vm, \mysqli $mysqli, string $csrf): string
{
    if (empty($vm['features']['hypervisor'])) {
        return '';
    }
    $uid = (int) ($vm['user_id'] ?? 0);
    $deviceId = (int) $vm['device_id'];
    $ctx = ['uid' => $uid, 'name' => (string) ($GLOBALS['session_name'] ?? ''), 'csrf' => $csrf, 'perm' => rivetRmmAutoPerm($mysqli, $uid), 'mysqli' => $mysqli];
    $hv = rivetRmmHvHostView($ctx, $deviceId, ['limit' => '1']);   // the device page never needs more than the host's own summary fields
    if ($hv['error'] !== '' || $hv['host'] === null) {
        return '';   // not a hypervisor host, the feature is off, or the viewer lacks rmm.hypervisor.view: no tab either way
    }
    $h = 'rivetRmmHvH';
    $host = $hv['host'];
    return '<p class="small text-muted mb-2">This device reports as a ' . $h($host['platform'] === 'proxmox' ? 'Proxmox VE' : 'Hyper-V') . ' hypervisor host, version ' . $h($host['version']) . ', '
        . (int) $host['vm_count'] . ' VM' . ((int) $host['vm_count'] === 1 ? '' : 's') . ' (' . (int) $host['vms_running'] . ' running)'
        . ($host['cluster'] !== null ? ', cluster "' . $h($host['cluster']['name']) . '"' . ($host['cluster']['quorate'] === false ? ' (no quorum)' : '') : '') . '.</p>'
        . '<a class="btn btn-sm btn-primary" href="/agent/rmm_hypervisors.php?host_id=' . $deviceId . '"><i class="fas fa-server me-1" aria-hidden="true"></i>Open the host page</a>';
}
