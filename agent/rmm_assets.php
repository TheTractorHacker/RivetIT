<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_rmm');
require_once "../includes/list_ui.php";
require_once "../includes/rmm_ui_kit.php";

/*
 * RMM Assets: the devices vendor RMM integrations (Tactical RMM, Level, Sophos Central ...) have linked to assets. Everything here is a read of
 * asset_rmm_links joined to assets/clients; the filters below only narrow that read. Filters (all GET, all optional):
 *   q                search hostname, logged-in user, OS, manufacturer, model, department
 *   integration_id, status, client_id (-1 = no department), seen (1h 24h 7d 30d | stale7 stale30), os, remote, archived
 *   health[]         reboot, patches, maintenance, disk, cpu, ram (disk/cpu/ram = 85% or more)
 *   make, model      manufacturer / model contains
 *   sort, order, per_page, page
 */

$f_q       = trim((string) ($_GET['q'] ?? ''));
$f_q       = function_exists('mb_substr') ? mb_substr($f_q, 0, 100) : substr($f_q, 0, 100);
$f_status  = in_array($_GET['status'] ?? '', ['online', 'offline', 'unknown'], true) ? $_GET['status'] : '';
$f_client  = intval($_GET['client_id'] ?? 0);
$f_intg    = intval($_GET['integration_id'] ?? 0);
$filter_intg_id = $f_intg;   // the sync script below still uses the old name
$f_seen    = in_array($_GET['seen'] ?? '', ['1h', '24h', '7d', '30d', 'stale7', 'stale30'], true) ? $_GET['seen'] : '';
$f_os      = in_array($_GET['os'] ?? '', ['windows', 'server', 'linux', 'macos', 'other'], true) ? $_GET['os'] : '';
$f_remote  = in_array($_GET['remote'] ?? '', ['tactical', 'mesh', 'none'], true) ? $_GET['remote'] : '';
$f_arch    = in_array($_GET['archived'] ?? '', ['no', 'only'], true) ? $_GET['archived'] : '';
$f_make    = trim((string) ($_GET['make'] ?? ''));
$f_make    = function_exists('mb_substr') ? mb_substr($f_make, 0, 100) : substr($f_make, 0, 100);
$f_model   = trim((string) ($_GET['model'] ?? ''));
$f_model   = function_exists('mb_substr') ? mb_substr($f_model, 0, 100) : substr($f_model, 0, 100);
$health_choices = ['reboot' => 'Needs a reboot', 'patches' => 'Patches pending', 'maintenance' => 'In maintenance mode', 'disk' => 'Disk 85% or more full', 'cpu' => 'CPU 85% or more', 'ram' => 'Memory 85% or more'];
$f_health  = array_values(array_intersect(array_map('strval', (array) ($_GET['health'] ?? [])), array_keys($health_choices)));
$sort_cols = ['status' => 'Status', 'hostname' => 'Hostname', 'client' => 'Department', 'os' => 'OS', 'user' => 'Logged in user', 'seen' => 'Last seen', 'disk' => 'Disk used'];
$f_sort    = isset($sort_cols[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'status';
$f_order   = ($_GET['order'] ?? '') === 'desc' ? 'desc' : 'asc';
$per_page  = in_array(intval($_GET['per_page'] ?? 50), [25, 50, 100, 250], true) ? intval($_GET['per_page'] ?? 50) : 50;
$page      = max(1, intval($_GET['page'] ?? 1));

$esc  = static fn ($v): string => mysqli_real_escape_string($mysqli, (string) $v);
$like = static fn ($v): string => "'%" . $esc(strtr((string) $v, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_'])) . "%'";

$where = '1=1';
if ($f_status)  { $where .= " AND arl.rmm_status='" . $esc($f_status) . "'"; }
if ($f_client > 0) { $where .= " AND a.asset_client_id=$f_client"; }
if ($f_client === -1) { $where .= " AND (a.asset_client_id IS NULL OR a.asset_client_id=0)"; }
if ($f_intg)    { $where .= " AND arl.integration_id=$f_intg"; }
if ($f_q !== '') {
    $lq = $like($f_q);
    $where .= " AND (arl.hostname LIKE $lq OR arl.logged_in_user LIKE $lq OR arl.os_name LIKE $lq OR arl.manufacturer LIKE $lq OR arl.model LIKE $lq OR c.client_name LIKE $lq)";
}
if ($f_make !== '')  { $where .= " AND arl.manufacturer LIKE " . $like($f_make); }
if ($f_model !== '') { $where .= " AND arl.model LIKE " . $like($f_model); }
switch ($f_seen) {
    case '1h':      $where .= " AND arl.last_seen >= NOW() - INTERVAL 1 HOUR"; break;
    case '24h':     $where .= " AND arl.last_seen >= NOW() - INTERVAL 24 HOUR"; break;
    case '7d':      $where .= " AND arl.last_seen >= NOW() - INTERVAL 7 DAY"; break;
    case '30d':     $where .= " AND arl.last_seen >= NOW() - INTERVAL 30 DAY"; break;
    case 'stale7':  $where .= " AND (arl.last_seen IS NULL OR arl.last_seen < NOW() - INTERVAL 7 DAY)"; break;
    case 'stale30': $where .= " AND (arl.last_seen IS NULL OR arl.last_seen < NOW() - INTERVAL 30 DAY)"; break;
}
$os_server = "arl.os_name LIKE '%Server%'";
$os_linux  = "(arl.os_name LIKE '%Linux%' OR arl.os_name LIKE '%Ubuntu%' OR arl.os_name LIKE '%Debian%' OR arl.os_name LIKE '%CentOS%' OR arl.os_name LIKE '%Red Hat%' OR arl.os_name LIKE '%Rocky%' OR arl.os_name LIKE '%Alma%' OR arl.os_name LIKE '%SUSE%')";
$os_mac    = "(arl.os_name LIKE '%macOS%' OR arl.os_name LIKE '%Mac OS%' OR arl.os_name LIKE '%Darwin%')";
$os_win    = "arl.os_name LIKE '%Windows%'";
switch ($f_os) {
    case 'windows': $where .= " AND $os_win AND NOT ($os_server)"; break;
    case 'server':  $where .= " AND $os_server"; break;
    case 'linux':   $where .= " AND $os_linux"; break;
    case 'macos':   $where .= " AND $os_mac"; break;
    case 'other':   $where .= " AND NOT ($os_win OR $os_server OR $os_linux OR $os_mac)"; break;
}
if ($f_remote === 'tactical') { $where .= " AND arl.tactical_agent_id IS NOT NULL AND arl.tactical_agent_id <> ''"; }
if ($f_remote === 'mesh')     { $where .= " AND arl.mesh_node_id IS NOT NULL AND arl.mesh_node_id <> ''"; }
if ($f_remote === 'none')     { $where .= " AND (arl.tactical_agent_id IS NULL OR arl.tactical_agent_id = '') AND (arl.mesh_node_id IS NULL OR arl.mesh_node_id = '')"; }
if ($f_arch === 'no')   { $where .= " AND a.asset_archived_at IS NULL"; }
if ($f_arch === 'only') { $where .= " AND a.asset_archived_at IS NOT NULL"; }
foreach ($f_health as $h) {
    $where .= ['reboot' => " AND arl.rmm_needs_reboot=1", 'patches' => " AND arl.rmm_patches_pending=1", 'maintenance' => " AND arl.rmm_maintenance_mode=1",
        'disk' => " AND arl.rmm_disk_percent>=85", 'cpu' => " AND arl.rmm_cpu_percent>=85", 'ram' => " AND arl.rmm_ram_percent>=85"][$h];
}
// Client-restricted techs should only see assets for clients they have access to
if ($client_access_string && !$session_is_admin) { $where .= " AND a.asset_client_id IN ($client_access_string)"; }

$sql_links = mysqli_query($mysqli,
    "SELECT arl.*, a.asset_id, a.asset_name, a.asset_type, a.asset_client_id, a.asset_archived_at,
            c.client_name, i.type AS integration_type
     FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     LEFT JOIN clients c ON c.client_id = a.asset_client_id
     LEFT JOIN rmm_integrations i ON i.id = arl.integration_id
     WHERE $where"
);

// A single physical asset can be linked to more than one RMM integration at
// once (e.g. both Tactical RMM and Level.io tracking the same device). This
// query is per-link, not per-asset, so without deduping it that device would
// be listed twice with no explanation. Collapse to one row per asset_id,
// preferring the Tactical RMM link when config_rmm_prefer_tactical is on.
$rmm_link_rows = [];
while ($row = mysqli_fetch_assoc($sql_links)) {
    $aid = intval($row['asset_id']);
    if (!isset($rmm_link_rows[$aid])) {
        $rmm_link_rows[$aid] = $row;
    } elseif ($config_rmm_prefer_tactical
        && $row['integration_type'] === 'tactical_rmm'
        && $rmm_link_rows[$aid]['integration_type'] !== 'tactical_rmm') {
        $rmm_link_rows[$aid] = $row;
    }
}
$rmm_link_rows = array_values($rmm_link_rows);
$status_rank = ['online' => 0, 'offline' => 1, 'unknown' => 2];
$sort_key = static function (array $r) use ($f_sort, $status_rank) {
    switch ($f_sort) {
        case 'hostname': return strtolower((string) $r['hostname']);
        case 'client':   return strtolower((string) $r['client_name']);
        case 'os':       return strtolower((string) $r['os_name']);
        case 'user':     return strtolower((string) $r['logged_in_user']);
        case 'seen':     return (string) $r['last_seen'];
        case 'disk':     return $r['rmm_disk_percent'] === null ? -1 : (int) $r['rmm_disk_percent'];
        default:         return $status_rank[$r['rmm_status'] ?? 'unknown'] ?? 3;
    }
};
usort($rmm_link_rows, function ($a, $b) use ($sort_key, $f_order) {
    $cmp = $sort_key($a) <=> $sort_key($b);
    if ($cmp === 0) { $cmp = strcasecmp((string) $a['hostname'], (string) $b['hostname']); }
    return $f_order === 'desc' ? -$cmp : $cmp;
});
$total_rows = count($rmm_link_rows);
$pages = max(1, (int) ceil($total_rows / $per_page));
$page = min($page, $pages);
$page_rows = array_slice($rmm_link_rows, ($page - 1) * $per_page, $per_page);

// Counts for the stat tiles (the integration choice narrows them; the other filters do not, so the tiles stay a map of the whole fleet)
$cnt = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT
       COALESCE(SUM(rmm_status='online'),0) as online,
       COALESCE(SUM(rmm_status='offline'),0) as offline,
       COALESCE(SUM(rmm_status='unknown'),0) as unknown,
       COALESCE(SUM(rmm_needs_reboot=1),0) as reboot,
       COALESCE(SUM(rmm_patches_pending=1),0) as patches,
       COUNT(*) as total
     FROM asset_rmm_links WHERE integration_id=" . ($f_intg ?: 'integration_id')
));

$sql_integrations = mysqli_query($mysqli, "SELECT id, name FROM rmm_integrations WHERE enabled=1 ORDER BY name");
$integrations = [];
while ($i = mysqli_fetch_assoc($sql_integrations)) { $integrations[] = $i; }
$sql_clients = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . ($client_access_string && !$session_is_admin ? " AND client_id IN ($client_access_string)" : '') . " ORDER BY client_name ASC");
$clients_list = [];
while ($c = mysqli_fetch_assoc($sql_clients)) $clients_list[] = $c;

// Button label should reflect whichever integration is actually selected
// (Tactical, Sophos, etc.) rather than a hardcoded provider name.
$sync_target_name = 'RMM';
if ($filter_intg_id) {
    $sync_target_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM rmm_integrations WHERE id=$filter_intg_id"));
    if ($sync_target_row) { $sync_target_name = $sync_target_row['name']; }
}
$sync_target_name_js = json_encode($sync_target_name, JSON_HEX_TAG);
$can_rmm_remote_connect = lookupUserPermission('module_rmm_remote_connect') >= 1;

$base_q = array_filter(['q' => $f_q, 'integration_id' => $f_intg ?: null, 'status' => $f_status, 'client_id' => $f_client ?: null, 'seen' => $f_seen, 'os' => $f_os, 'remote' => $f_remote,
    'archived' => $f_arch, 'make' => $f_make, 'model' => $f_model, 'health' => $f_health ?: null, 'per_page' => $per_page !== 50 ? $per_page : null], static fn ($v) => $v !== '' && $v !== null);
$self = static fn (array $over = []): string => '?' . http_build_query(array_filter(array_merge($base_q, ['sort' => $f_sort !== 'status' ? $f_sort : null, 'order' => $f_order === 'desc' ? 'desc' : null], $over), static fn ($v) => $v !== '' && $v !== null));
$tile_q = static fn (array $over): string => '?' . http_build_query(array_filter(array_merge(array_diff_key($base_q, ['status' => 1, 'health' => 1]), $over), static fn ($v) => $v !== '' && $v !== null));
$advanced_active = $f_os !== '' || $f_remote !== '' || $f_arch !== '' || $f_make !== '' || $f_model !== '' || $f_health !== [] || $per_page !== 50;
$active_count = count(array_filter([$f_q !== '', $f_status !== '', $f_client !== 0, $f_intg !== 0, $f_seen !== '', $f_os !== '', $f_remote !== '', $f_arch !== '', $f_make !== '', $f_model !== ''])) + count($f_health);
$client_name_of = static function (int $id) use ($clients_list): string { foreach ($clients_list as $cl) { if ((int) $cl['client_id'] === $id) { return (string) $cl['client_name']; } } return '#' . $id; };
$intg_name_of = static function (int $id) use ($integrations): string { foreach ($integrations as $i) { if ((int) $i['id'] === $id) { return (string) $i['name']; } } return '#' . $id; };
$seen_labels = ['1h' => 'in the last hour', '24h' => 'in the last 24 hours', '7d' => 'in the last 7 days', '30d' => 'in the last 30 days', 'stale7' => 'not for 7+ days', 'stale30' => 'not for 30+ days'];
$os_labels = ['windows' => 'Windows', 'server' => 'Server OS', 'linux' => 'Linux', 'macos' => 'macOS', 'other' => 'Other / appliance'];
$remote_labels = ['tactical' => 'Has a Tactical agent', 'mesh' => 'Has a MeshCentral node', 'none' => 'No remote agent'];
$chips = [];
if ($f_q !== '')      { $chips[] = rivet_filter_chip('Search: <strong>' . nullable_htmlentities($f_q) . '</strong>', rivet_url_without('q')); }
if ($f_intg)          { $chips[] = rivet_filter_chip('Integration: <strong>' . nullable_htmlentities($intg_name_of($f_intg)) . '</strong>', rivet_url_without('integration_id')); }
if ($f_status !== '') { $chips[] = rivet_filter_chip('Status: <strong>' . ucfirst($f_status) . '</strong>', rivet_url_without('status')); }
if ($f_client)        { $chips[] = rivet_filter_chip('Department: <strong>' . ($f_client === -1 ? 'none assigned' : nullable_htmlentities($client_name_of($f_client))) . '</strong>', rivet_url_without('client_id')); }
if ($f_seen !== '')   { $chips[] = rivet_filter_chip('Seen: <strong>' . $seen_labels[$f_seen] . '</strong>', rivet_url_without('seen')); }
if ($f_os !== '')     { $chips[] = rivet_filter_chip('OS: <strong>' . $os_labels[$f_os] . '</strong>', rivet_url_without('os')); }
if ($f_remote !== '') { $chips[] = rivet_filter_chip('Remote: <strong>' . $remote_labels[$f_remote] . '</strong>', rivet_url_without('remote')); }
if ($f_arch !== '')   { $chips[] = rivet_filter_chip('Assets: <strong>' . ($f_arch === 'no' ? 'not archived' : 'archived only') . '</strong>', rivet_url_without('archived')); }
if ($f_make !== '')   { $chips[] = rivet_filter_chip('Manufacturer: <strong>' . nullable_htmlentities($f_make) . '</strong>', rivet_url_without('make')); }
if ($f_model !== '')  { $chips[] = rivet_filter_chip('Model: <strong>' . nullable_htmlentities($f_model) . '</strong>', rivet_url_without('model')); }
foreach ($f_health as $h) { $chips[] = rivet_filter_chip('Health: <strong>' . $health_choices[$h] . '</strong>', rivet_url_without('health', $h)); }
$th = static function (string $key, string $label) use ($f_sort, $f_order, $self): string {
    $active = $f_sort === $key;
    $next = $active && $f_order === 'asc' ? 'desc' : 'asc';
    $icon = !$active ? 'fa-sort' : ($f_order === 'desc' ? 'fa-sort-down' : 'fa-sort-up');
    return '<th scope="col" aria-sort="' . ($active ? ($f_order === 'desc' ? 'descending' : 'ascending') : 'none') . '"><a class="sort-link' . ($active ? ' is-active' : '') . '" href="' . htmlspecialchars($self(['sort' => $key === 'status' ? null : $key, 'order' => $next === 'desc' ? 'desc' : null, 'page' => null])) . '" title="Sort by ' . htmlspecialchars($label) . '">' . htmlspecialchars($label) . ' <i class="fas ' . $icon . ' sort-icon" aria-hidden="true"></i></a></th>';
};
?>

    <style nonce="<?= htmlspecialchars($csp_nonce ?? '', ENT_QUOTES) ?>">
        #rmm-assets-table td { vertical-align: middle; }
        #rmm-assets-table { min-width: 1080px; }
            #rmm-assets-table .rmm-os { min-width: 11rem; }
        #rmm-assets-table .rmm-host { white-space: nowrap; }
        #rmm-assets-table .rmm-user { overflow-wrap: normal; word-break: normal; }
        #rmm-assets-table .rmm-actions { white-space: nowrap; text-align: left; width: 1%; }
        #rmm-assets-table thead th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
        #rmm-assets-table .rmm-health { min-width: 8rem; }
        #rmm-assets-table .rmm-health .rmmx-meter { margin-top: .2rem; }
        .rmm-assets-kpis .rmmx-kpis { grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); }
        #rmm-assets-table thead a { text-decoration: none; }
        .rmm-assets-filter { position: relative; }
        .rmm-assets-filter .rmm-adv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: .75rem; padding-top: .75rem; border-top: 1px solid var(--if-border, #dfe5e8); margin-top: .75rem; }
        .rmm-assets-filter .rmm-adv-health { padding-top: 1rem; margin: 0; }
        .rmm-assets-filter .rmm-adv-health legend { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; color: var(--bs-secondary-color, #5d6f76); margin-bottom: .35rem; float: none; width: auto; }
    </style>
<?= rmmxHead('fa-desktop', 'RMM Assets', 'Devices your RMM integrations have linked to assets. Filter by status, department, operating system, how recently they were seen and their health.',
    (lookupUserPermission('module_rmm_sync') >= 1 && $f_intg ? '<button class="btn btn-success btn-sm js-trigger-sync" id="syncBtn"><i class="fas fa-sync me-1"></i>Sync from ' . nullable_htmlentities($sync_target_name) . '</button>' : '')
    . '<a href="/admin/settings_integrations.php?tab=rmm" class="btn btn-outline-secondary btn-sm"><i class="fas fa-cog me-1"></i>Settings</a>') ?>

<div class="rmm-assets-kpis"><?= rmmxKpiGrid([
    ['icon' => 'fa-check-circle', 'label' => 'Online', 'value' => (int) $cnt['online'], 'sub' => 'reporting now', 'tone' => 'ok', 'href' => $tile_q(['status' => 'online']), 'active' => $f_status === 'online'],
    ['icon' => 'fa-times-circle', 'label' => 'Offline', 'value' => (int) $cnt['offline'], 'sub' => 'not reporting', 'tone' => 'danger', 'href' => $tile_q(['status' => 'offline']), 'active' => $f_status === 'offline'],
    ['icon' => 'fa-question-circle', 'label' => 'Unknown', 'value' => (int) $cnt['unknown'], 'sub' => 'status not known', 'tone' => 'neutral', 'href' => $tile_q(['status' => 'unknown']), 'active' => $f_status === 'unknown'],
    ['icon' => 'fa-sync-alt', 'label' => 'Need a reboot', 'value' => (int) $cnt['reboot'], 'sub' => 'restart pending', 'tone' => 'warn', 'href' => $tile_q(['health' => ['reboot']]), 'active' => $f_health === ['reboot']],
    ['icon' => 'fa-shield-alt', 'label' => 'Patches pending', 'value' => (int) $cnt['patches'], 'sub' => 'updates waiting', 'tone' => 'warn', 'href' => $tile_q(['health' => ['patches']]), 'active' => $f_health === ['patches']],
    ['icon' => 'fa-desktop', 'label' => 'Total managed', 'value' => (int) $cnt['total'], 'sub' => 'show all', 'tone' => 'info', 'href' => '?'],
]) ?></div>

<!-- Sync status bar -->
<div id="syncStatus" class="alert alert-info d-none mb-3">
    <i class="fas fa-spinner fa-spin me-2"></i><span id="syncStatusText">Syncing...</span>
</div>

<!-- Filters -->
<form method="get" class="rmmx-toolbar rmm-assets-filter" role="search" aria-label="Filter RMM assets">
    <div class="rmmx-field rmmx-grow"><label for="ra-q">Search</label>
        <input class="form-control" type="search" id="ra-q" name="q" value="<?= nullable_htmlentities($f_q) ?>" maxlength="100" placeholder="Hostname, user, OS, model or department"></div>
    <div class="rmmx-field"><label for="ra-intg">Integration</label>
        <select class="form-select auto-submit-select" id="ra-intg" name="integration_id" data-searchable="0"><option value="">All integrations</option>
            <?php foreach ($integrations as $i): ?><option value="<?= intval($i['id']) ?>"<?= $f_intg == $i['id'] ? ' selected' : '' ?>><?= nullable_htmlentities($i['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="rmmx-field"><label for="ra-status">Status</label>
        <select class="form-select auto-submit-select" id="ra-status" name="status" data-searchable="0"><option value="">Any status</option>
            <?php foreach (['online' => 'Online', 'offline' => 'Offline', 'unknown' => 'Unknown'] as $v => $l): ?><option value="<?= $v ?>"<?= $f_status === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></div>
    <div class="rmmx-field"><label for="ra-client">Department</label>
        <select class="form-select auto-submit-select" id="ra-client" name="client_id" data-searchable="0"><option value="">Any department</option><option value="-1"<?= $f_client === -1 ? ' selected' : '' ?>>No department assigned</option>
            <?php foreach ($clients_list as $cl): ?><option value="<?= intval($cl['client_id']) ?>"<?= $f_client === intval($cl['client_id']) ? ' selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="rmmx-field"><label for="ra-seen">Last seen</label>
        <select class="form-select auto-submit-select" id="ra-seen" name="seen" data-searchable="0"><option value="">Any time</option>
            <?php foreach (['1h' => 'In the last hour', '24h' => 'In the last 24 hours', '7d' => 'In the last 7 days', '30d' => 'In the last 30 days', 'stale7' => 'Not seen for 7+ days', 'stale30' => 'Not seen for 30+ days'] as $v => $l): ?><option value="<?= $v ?>"<?= $f_seen === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
        </select></div>
    <div class="rmmx-actions">
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#ra-advanced" aria-expanded="<?= $advanced_active ? 'true' : 'false' ?>" aria-controls="ra-advanced"><i class="fas fa-sliders-h" aria-hidden="true"></i>Advanced<?= $advanced_active ? ' <span class="badge text-bg-primary ms-1">on</span>' : '' ?></button>
        <button class="btn btn-primary" type="submit"><i class="fas fa-search" aria-hidden="true"></i>Apply</button>
    </div>

    <div class="collapse<?= $advanced_active ? ' show' : '' ?> w-100" id="ra-advanced">
        <div class="rmm-adv-grid">
            <div class="rmmx-field"><label for="ra-os">Operating system</label>
                <select class="form-select" id="ra-os" name="os" data-searchable="0"><option value="">Any</option>
                    <?php foreach ($os_labels as $v => $l): ?><option value="<?= $v ?>"<?= $f_os === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                </select></div>
            <div class="rmmx-field"><label for="ra-make">Manufacturer contains</label><input class="form-control" id="ra-make" name="make" maxlength="100" value="<?= nullable_htmlentities($f_make) ?>" placeholder="e.g. Dell"></div>
            <div class="rmmx-field"><label for="ra-model">Model contains</label><input class="form-control" id="ra-model" name="model" maxlength="100" value="<?= nullable_htmlentities($f_model) ?>" placeholder="e.g. Latitude"></div>
            <div class="rmmx-field"><label for="ra-remote">Remote access</label>
                <select class="form-select" id="ra-remote" name="remote" data-searchable="0"><option value="">Any</option>
                    <?php foreach ($remote_labels as $v => $l): ?><option value="<?= $v ?>"<?= $f_remote === $v ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
                </select></div>
            <div class="rmmx-field"><label for="ra-arch">Asset record</label>
                <select class="form-select" id="ra-arch" name="archived" data-searchable="0"><option value="">Active and archived</option><option value="no"<?= $f_arch === 'no' ? ' selected' : '' ?>>Not archived</option><option value="only"<?= $f_arch === 'only' ? ' selected' : '' ?>>Archived only</option></select></div>
            <div class="rmmx-field"><label for="ra-per">Rows per page</label>
                <select class="form-select" id="ra-per" name="per_page" data-searchable="0"><?php foreach ([25, 50, 100, 250] as $n): ?><option value="<?= $n ?>"<?= $per_page === $n ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
        </div>
        <fieldset class="rmm-adv-health"><legend>Health (show only devices that...)</legend>
            <?php foreach ($health_choices as $v => $l): ?>
                <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="health[]" value="<?= $v ?>"<?= in_array($v, $f_health, true) ? ' checked' : '' ?>><span class="form-check-label"><?= $l ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <?php if ($f_sort !== 'status'): ?><input type="hidden" name="sort" value="<?= nullable_htmlentities($f_sort) ?>"><?php endif; ?>
        <?php if ($f_order === 'desc'): ?><input type="hidden" name="order" value="desc"><?php endif; ?>
    </div>
</form>

<div class="filter-summary d-flex flex-wrap align-items-center gap-2 mb-2" aria-live="polite">
    <span class="text-secondary small"><strong><?= intval($total_rows) ?></strong> <?= $total_rows === 1 ? 'device' : 'devices' ?><?= $chips ? ' match' : '' ?></span>
    <?= implode('', $chips) ?>
    <?php if (count($chips) > 0): ?><a class="small ms-1" href="?">Clear all</a><?php endif; ?>
</div>

<!-- Bulk actions bar: hidden until at least one row is ticked (js/bulk_actions.js toggles #bulkActionButton by its id) -->
<div class="card card-dark mb-2" id="bulkActionButton" hidden>
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="small text-muted"><span id="selectedCount">0</span> selected</span>
        <?php if (lookupUserPermission('module_rmm_sync') >= 1): ?>
        <select class="form-control form-control-sm w-auto" id="bulkAssignClient" aria-label="Department to assign">
            <option value="">Assign department...</option>
            <?php foreach ($clients_list as $cl): ?>
            <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-sm btn-primary" id="bulkAssignGo" disabled>Apply to selected</button>
        <?php endif; ?>
    </div>
</div>

<!-- Asset table -->
<div class="card card-dark">
    <div class="card-body p-0 table-responsive">
        <?php if ($total_rows === 0): ?>
            <div class="text-center text-muted py-5">
                <i class="fas fa-desktop fa-3x mb-3"></i>
                <?php if ($chips): ?>
                <p>No device matches these filters. <a href="?">Clear all filters</a></p>
                <?php else: ?>
                <p>No RMM assets found. <a href="#" class="js-trigger-sync">Sync from <?= nullable_htmlentities($sync_target_name) ?></a> to import devices.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <table class="table table-hover table-sm mb-0" id="rmm-assets-table">
            <thead class="text-muted border-bottom">
                <tr>
                    <th class="ps-3 checkbox-column"><div class="form-check"><input class="form-check-input" id="selectAllCheckbox" type="checkbox" aria-label="Select all devices on this page"></div></th>
                    <?= $th('status', 'Status') ?>
                    <?= $th('hostname', 'Hostname') ?>
                    <?= $th('client', 'Department') ?>
                    <?= $th('os', 'OS / user') ?>
                    <?= $th('disk', 'Health') ?>
                    <?= $th('seen', 'Last seen') ?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($page_rows as $row):
                $status    = $row['rmm_status'];
                $pill      = $status === 'online' ? ['ok', 'Online'] : ($status === 'offline' ? ['crit', 'Offline'] : ['off', 'Unknown']);
                $disk      = $row['rmm_disk_percent'] === null ? null : max(0, min(100, (int) $row['rmm_disk_percent']));
                $badges    = '';
                if ((int) ($row['rmm_needs_reboot'] ?? 0) === 1) { $badges .= '<span class="badge text-bg-warning me-1" title="A restart is pending">Reboot</span>'; }
                if ((int) ($row['rmm_patches_pending'] ?? 0) === 1) { $badges .= '<span class="badge text-bg-warning me-1" title="Updates are waiting to install">Patches</span>'; }
                if ((int) ($row['rmm_maintenance_mode'] ?? 0) === 1) { $badges .= '<span class="badge text-bg-info me-1" title="In maintenance mode: alerts are paused">Maintenance</span>'; }
            ?>
            <tr>
                <td class="ps-3 checkbox-column">
                    <div class="form-check"><input class="form-check-input bulk-select" type="checkbox" name="asset_ids[]" value="<?= intval($row['asset_id']) ?>" aria-label="Select <?= nullable_htmlentities($row['hostname']) ?>"></div>
                </td>
                <td><?= '<span class="rmmx-pill rmmx-pill-' . $pill[0] . '">' . $pill[1] . '</span>' ?></td>
                <td class="rmm-host">
                    <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="fw-bold">
                        <?= nullable_htmlentities($row['hostname']) ?>
                    </a>
                    <?php if ($row['manufacturer'] || $row['model']): ?><div class="text-muted small"><?= nullable_htmlentities(trim($row['manufacturer'] . ' ' . $row['model'])) ?></div><?php endif; ?>
                </td>
                <td>
                    <?php if ($row['asset_client_id']): ?>
                        <a href="/agent/client_overview.php?client_id=<?= intval($row['asset_client_id']) ?>">
                            <?= nullable_htmlentities($row['client_name']) ?>
                        </a>
                    <?php elseif (lookupUserPermission('module_rmm_sync') >= 1): ?>
                        <select class="form-control form-control-sm assign-client-select" style="width:auto;display:inline-block" data-asset-id="<?= intval($row['asset_id']) ?>" aria-label="Assign a department to <?= nullable_htmlentities($row['hostname']) ?>">
                            <option value="">Assign department...</option>
                            <?php foreach ($clients_list as $cl): ?>
                            <option value="<?= intval($cl['client_id']) ?>"><?= nullable_htmlentities($cl['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="rmm-os">
                    <div class="small"><?= nullable_htmlentities($row['os_name']) ?: '<span class="text-muted">—</span>' ?></div>
                    <?php if ($row['logged_in_user']): ?><div class="text-muted small rmm-user"><i class="fas fa-user fa-xs me-1" aria-hidden="true"></i><?= str_replace('\\', '\\<wbr>', nullable_htmlentities($row['logged_in_user'])) ?></div><?php endif; ?>
                </td>
                <td class="rmm-health">
                    <?php if ($disk !== null): ?>
                        <div class="rmmx-ratio" title="Disk <?= $disk ?>% used"><span class="small">Disk <?= $disk ?>%</span><span class="rmmx-meter" aria-hidden="true"><span style="width:<?= $disk ?>%<?= $disk >= 90 ? ';background:#c2272d' : ($disk >= 75 ? ';background:#d9822b' : '') ?>"></span></span></div>
                    <?php endif; ?>
                    <?php if ($badges !== ''): ?><div class="mt-1"><?= $badges ?></div><?php elseif ($disk === null): ?><span class="text-muted small">—</span><?php endif; ?>
                </td>
                <td class="text-muted small text-nowrap" title="<?= $row['last_sync'] ? 'Last sync ' . nullable_htmlentities(rivetFormatTime($row['last_sync'], 'local', true, 'Y-m-d H:i')) : '' ?>">
                    <?= $row['last_seen'] ? nullable_htmlentities(rivetFormatTime($row['last_seen'], 'local', true, 'Y-m-d H:i')) : '—' ?>
                </td>
                <td class="rmm-actions pe-2">
                    <div class="btn-group">
                        <a href="/agent/asset_details.php?client_id=<?= intval($row['asset_client_id']) ?>&asset_id=<?= intval($row['asset_id']) ?>" class="btn btn-sm btn-info" title="Open this asset" aria-label="Open <?= nullable_htmlentities($row['hostname']) ?>">
                            <i class="fas fa-arrow-right" aria-hidden="true"></i><span class="d-none d-xxl-inline ms-1">Open</span>
                        </a>
                        <?php if ($can_rmm_remote_connect && !empty($row['tactical_agent_id'])): ?>
                            <button type="button" class="btn btn-success btn-sm" data-rmm-action="connect" data-link-id="<?= intval($row['id']) ?>" data-rmm-type="tactical" title="Remote connect">
                                <i class="fas fa-desktop me-1" aria-hidden="true"></i>Connect
                            </button>
                            <?php if (!empty($row['mesh_node_id'])): ?>
                            <button type="button" class="btn btn-success btn-sm dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" data-boundary="window" aria-label="More ways to connect"></button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="#" data-rmm-action="connect" data-link-id="<?= intval($row['id']) ?>" data-rmm-type="mesh">
                                    <i class="fas fa-tv me-2"></i>MeshCentral Remote Desktop
                                </a>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <button class="btn btn-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-boundary="window" aria-label="More actions"><i class="fas fa-ellipsis-h"></i></button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/asset/asset_edit.php?id=<?= intval($row['asset_id']) ?>">
                                <i class="fas fa-fw fa-edit me-2"></i>Edit
                            </a>
                            <a class="dropdown-item ajax-modal" href="#" data-modal-url="modals/asset/asset_copy.php?id=<?= intval($row['asset_id']) ?>">
                                <i class="fas fa-fw fa-copy me-2"></i>Copy
                            </a>
                            <?php if ($session_user_role > 2): ?>
                                <?php if ($row['asset_archived_at']): ?>
                                <a class="dropdown-item text-info" href="post.php?restore_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-redo me-2"></i>Restore
                                </a>
                                <a class="dropdown-item text-danger text-bold confirm-link" data-confirm="Permanently delete <?= nullable_htmlentities($row['hostname']) ?>?" href="post.php?delete_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-trash me-2"></i>Delete
                                </a>
                                <?php else: ?>
                                <a class="dropdown-item text-danger confirm-link" data-confirm="Archive <?= nullable_htmlentities($row['hostname']) ?>?" href="post.php?archive_asset=<?= intval($row['asset_id']) ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>">
                                    <i class="fas fa-fw fa-archive me-2"></i>Archive
                                </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
    <nav class="d-flex align-items-center justify-content-between p-3 border-top" aria-label="Device pages">
        <span class="small text-muted">Page <?= $page ?> of <?= $pages ?> &middot; <?= $total_rows ?> devices</span>
        <span class="btn-group">
            <a class="btn btn-sm btn-outline-secondary<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= htmlspecialchars($self(['page' => $page - 1])) ?>" rel="prev"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
            <a class="btn btn-sm btn-outline-secondary<?= $page >= $pages ? ' disabled' : '' ?>" href="<?= htmlspecialchars($self(['page' => $page + 1])) ?>" rel="next"<?= $page >= $pages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
        </span>
    </nav>
    <?php endif; ?>
</div>

<script src="../js/bulk_actions.js"></script>

<?php if ($can_rmm_remote_connect): ?>
<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
function rmmConnect(linkId, type) {
    fetch('/agent/post/rmm_remote.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION["csrf_token"] ?>&link_id=' + linkId + '&type=' + type
    }).then(r => r.json()).then(d => {
        if (d.success && d.url) window.open(d.url, '_blank', 'noopener,noreferrer');
        else RivetDialog.alert('Connect failed: ' + (d.error || 'Unknown error'));
    });
}

// Delegated wiring (CSP forbids inline onclick= attributes)
document.addEventListener('click', function (e) {
    const el = e.target.closest('[data-rmm-action="connect"]');
    if (!el) return;
    e.preventDefault();
    rmmConnect(parseInt(el.dataset.linkId, 10), el.dataset.rmmType);
});
</script>
<?php endif; ?>

<script nonce="<?= htmlspecialchars($csp_nonce ?? '') ?>">
// Delegated wiring (CSP forbids inline onclick= attributes).
document.addEventListener('click', function (e) {
    if (e.target.closest('.js-trigger-sync')) { e.preventDefault(); triggerSync(); }
});

function triggerSync() {
    const btn = document.getElementById('syncBtn');
    const bar = document.getElementById('syncStatus');
    const txt = document.getElementById('syncStatusText');
    const syncTargetName = <?= $sync_target_name_js ?>;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Syncing...'; }
    bar.classList.remove('d-none');
    txt.textContent = `Syncing assets from ${syncTargetName}...`;

    fetch('/agent/post/rmm_sync.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=sync&integration_id=<?= $filter_intg_id ?>'
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            txt.textContent = `Sync complete: ${d.created} created, ${d.updated} updated, ${d.matched} matched, ${d.skipped} skipped.`;
            bar.classList.replace('alert-info', 'alert-success');
            setTimeout(() => location.reload(), 2000);
        } else {
            txt.textContent = 'Sync failed: ' + (d.error || 'Unknown error');
            bar.classList.replace('alert-info', 'alert-danger');
            if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
        }
    })
    .catch(() => {
        txt.textContent = 'Network error during sync.';
        bar.classList.replace('alert-info', 'alert-danger');
        if (btn) { btn.disabled = false; btn.innerHTML = `<i class="fas fa-sync me-1"></i>Sync from ${syncTargetName}`; }
    });
}

document.querySelectorAll('.assign-client-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
        const assetId = this.dataset.assetId;
        const clientId = this.value;
        if (!clientId) return;
        this.disabled = true;
        fetch('/agent/post/rmm_sync.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=assign_client&asset_id=' + encodeURIComponent(assetId) + '&client_id=' + encodeURIComponent(clientId)
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                location.reload();
            } else {
                RivetDialog.alert('Failed to assign department: ' + (d.error || 'Unknown error'));
                this.disabled = false;
            }
        })
        .catch(() => {
            RivetDialog.alert('Network error assigning department.');
            this.disabled = false;
        });
    });
});

// Bulk "Assign department to selected": loops the same per-asset endpoint the single-row select above uses (it has no bulk form of
// its own), one request per ticked row, then reloads once. getBulkCheckboxes() is js/bulk_actions.js's helper.
(function () {
    var sel = document.getElementById('bulkAssignClient');
    var go = document.getElementById('bulkAssignGo');
    if (!sel || !go) { return; }
    sel.addEventListener('change', function () { go.disabled = sel.value === ''; });
    go.addEventListener('click', function () {
        var clientId = sel.value;
        var ids = getBulkCheckboxes().filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
        if (!clientId || ids.length === 0) { return; }
        go.disabled = true;
        go.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Assigning...';
        Promise.all(ids.map(function (assetId) {
            return fetch('/agent/post/rmm_sync.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'csrf_token=<?= $_SESSION['csrf_token'] ?>&action=assign_client&asset_id=' + encodeURIComponent(assetId) + '&client_id=' + encodeURIComponent(clientId)
            }).then(function (r) { return r.json(); });
        })).then(function (results) {
            var failedRes = results.filter(function (d) { return !d.success; });
            var failed = failedRes.length;
            if (failed > 0) {
                RivetDialog.alert(failed + ' of ' + ids.length + ' could not be assigned. ' + (failedRes[0].error ? 'Reason: ' + failedRes[0].error + '.' : ''));
                go.disabled = false;
                go.textContent = 'Apply to selected';
            } else {
                location.reload();
            }
        }).catch(function () {
            RivetDialog.alert('Network error assigning department.');
            go.disabled = false;
            go.textContent = 'Apply to selected';
        });
    });
})();
</script>

<?php require_once "../includes/footer.php"; ?>
