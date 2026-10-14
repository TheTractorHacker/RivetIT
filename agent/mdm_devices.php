<?php
require_once "includes/inc_all.php";
enforceUserPermission('module_client');

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceStatus;
use ITFlow\Integrations\ManageEngine\MdmFleet;
use ITFlow\Integrations\ManageEngine\MdmMappingSettings;

/*
 * Mobile devices (ManageEngine MDM): fleet overview, linked devices, the review list of devices that matched no asset (or several), the Mapping screen
 * (every device, per-row and bulk link / unlink / ignore, propose matches) and Users to contacts. The last three are closed to a
 * department-scoped non-admin (they span departments); their POST handler is agent/post/mdm_mapping.php.
 * Read-only toward ManageEngine. Permissions (decided, documented in docs/MANAGEENGINE_MDM.md): the module_client permission already
 * governs the Intune devices page and the department/asset data these rows point at, so it is reused rather than adding a module (level 1 views,
 * level 2 links / creates assets, level 3 and administrators configure). Department-scoped users only see their departments' devices.
 */

$mdm = MdmClientFactory::row($mysqli);
$restricted = ($client_access_string && !$session_is_admin);
$can_edit = lookupUserPermission('module_client') >= 2;
$map_ready = MdmMappingSettings::schemaReady($mysqli);
$view = in_array($_GET['view'] ?? '', ['unmatched', 'mapping', 'users'], true) && !$restricted ? $_GET['view'] : 'linked';
if (in_array($view, ['mapping', 'users'], true) && !$map_ready) {
    $view = 'linked';   // database update 2.6.169 not run yet
}
$requested_platform = (string) ($_GET['platform'] ?? '');
$filter_platform = '';   // set below, only to a platform name that really exists in the data
$filter_state = in_array($_GET['state'] ?? '', ['lost', 'noncompliant', 'compliant', 'stale'], true) ? $_GET['state'] : '';
$filter_q = trim((string) ($_GET['q'] ?? ''));
$filter_older = max(0, min(3650, intval($_GET['older'] ?? 0)));   // last contact older than N days
$filter_client_id = intval($_GET['client_id'] ?? 0);
$show_dismissed = !empty($_GET['dismissed']);
$csrf = $_SESSION['csrf_token'];

$iid = (int) ($mdm['id'] ?? 0);
$stale_days = max(1, (int) ($mdm['stale_days'] ?? 7));
$scope_sql = $restricted ? " AND a.asset_client_id IN ($client_access_string)" : '';
$noncompliant_sql = MdmFleet::NONCOMPLIANT;
$stale_sql = MdmFleet::staleSql($stale_days);

$fleet = ['total' => 0, 'compliant' => 0, 'noncompliant' => 0, 'stale' => 0, 'lost' => 0, 'unmatched' => 0, 'needs_mapping' => 0];
$by_platform = [];
$linked = [];
$unmatched = [];
$clients_list = [];
$mobile_assets = [];

if ($mdm) {
    $fleet = MdmFleet::counts($mysqli, $iid, $stale_days, $scope_sql, !$restricted);
    $res = mysqli_query($mysqli,
        "SELECT COALESCE(NULLIF(l.platform, ''), 'unknown') AS p, COUNT(*) AS c FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id
         WHERE l.integration_id = $iid AND a.asset_archived_at IS NULL $scope_sql GROUP BY p ORDER BY c DESC"
    );
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $by_platform[$r['p']] = (int) $r['c'];
    }

    foreach (array_keys($by_platform) as $known_platform) {
        if (strcasecmp($known_platform, $requested_platform) === 0) {
            $filter_platform = (string) $known_platform;
        }
    }

    if ($view === 'linked') {
        $where = "l.integration_id = $iid AND a.asset_archived_at IS NULL $scope_sql";
        if ($filter_platform !== '') {
            $where .= " AND LOWER(COALESCE(NULLIF(l.platform, ''), 'unknown')) = '" . mysqli_real_escape_string($mysqli, strtolower($filter_platform)) . "'";
        }
        if ($filter_state === 'lost') {
            $where .= ' AND l.is_lost_mode = 1';
        } elseif ($filter_state === 'noncompliant') {
            $where .= " AND $noncompliant_sql";
        } elseif ($filter_state === 'compliant') {
            $where .= " AND NOT $noncompliant_sql AND l.passcode_compliant = 1";
        } elseif ($filter_state === 'stale') {
            $where .= " AND $stale_sql";
        }
        if ($filter_older > 0) {
            $where .= " AND l.last_contact_at IS NOT NULL AND l.last_contact_at < DATE_SUB(NOW(), INTERVAL $filter_older DAY)";
        }
        if ($filter_q !== '') {
            $like = "'%" . mysqli_real_escape_string($mysqli, addcslashes($filter_q, '%_\\')) . "%'";
            $where .= " AND (l.device_name LIKE $like OR l.serial_number LIKE $like OR l.imei LIKE $like OR l.udid LIKE $like OR l.user_name LIKE $like OR l.user_email LIKE $like OR a.asset_name LIKE $like OR l.model LIKE $like OR l.product_name LIKE $like)";
        }
        if ($filter_client_id) {
            $where .= " AND a.asset_client_id = $filter_client_id";
        }
        $res = mysqli_query($mysqli,
            "SELECT l.*, a.asset_name, a.asset_client_id, c.client_name FROM asset_mdm_links l
             JOIN assets a ON a.asset_id = l.asset_id LEFT JOIN clients c ON c.client_id = a.asset_client_id
             WHERE $where ORDER BY l.device_name ASC LIMIT 1000"
        );
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $linked[] = $r;
        }
    } elseif ($view === 'unmatched') {
        $res = mysqli_query($mysqli, "SELECT * FROM mdm_unmatched_devices WHERE integration_id = $iid AND dismissed = " . ($show_dismissed ? 1 : 0) . ' ORDER BY device_name ASC LIMIT 1000');
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $unmatched[] = $r;
        }
        if ($can_edit) {
            $res = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_serial, asset_type FROM assets a WHERE asset_archived_at IS NULL AND asset_type IN ('Tablet','Phone','Mobile Phone','Mobile')
                AND NOT EXISTS (SELECT 1 FROM asset_mdm_links l WHERE l.asset_id = a.asset_id AND l.integration_id = $iid) ORDER BY asset_name ASC LIMIT 500");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $mobile_assets[] = $r;
            }
        }
    }
    $res = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . ($restricted ? " AND client_id IN ($client_access_string)" : '') . ' ORDER BY client_name ASC');
    while ($res && ($r = mysqli_fetch_assoc($res))) {
        $clients_list[] = $r;
    }
}

$reason_label = ['no_match' => 'No asset matches this device', 'ambiguous' => 'More than one possible asset', 'needs_mapping' => 'Its customer / account has no department yet (Client mapping)', 'unlinked' => 'Unlinked by a person'];
$icon_for = static function (string $platform): string {
    $p = strtolower($platform);

    return str_contains($p, 'android') ? 'fa-android' : (str_contains($p, 'ios') || str_contains($p, 'ipad') ? 'fa-apple' : (str_contains($p, 'win') ? 'fa-windows' : 'fa-mobile-alt'));
};
?>

<div class="d-flex align-items-center mb-3">
    <h4 class="mb-0 me-auto"><i class="fas fa-tablet-alt me-2"></i>Mobile devices <small class="text-muted">ManageEngine MDM</small></h4>
    <?php if ($session_is_admin) { ?>
        <a href="/admin/settings_integrations.php?tab=devicesync" class="btn btn-secondary btn-sm"><i class="fas fa-cog me-1"></i>Settings</a>
    <?php } ?>
</div>

<?php if (!$mdm || !(int) $mdm['enabled']) { ?>
    <div class="card card-dark"><div class="card-body text-center text-muted py-5">
        <i class="fas fa-tablet-alt fa-3x mb-3"></i>
        <p>The ManageEngine MDM integration is not switched on.<?= $session_is_admin ? ' Set it up in <a href="/admin/settings_integrations.php?tab=devicesync">Admin &rarr; Integrations &rarr; Device Sync</a>.' : ' Ask an administrator to set it up.' ?></p>
    </div></div>
<?php } else { ?>

<!-- Fleet overview -->
<div class="row mb-3">
    <div class="col-6 col-md-3"><div class="small-box bg-info"><div class="inner"><h3><?= $fleet['total'] ?></h3><p>Linked devices</p></div>
        <div class="icon"><i class="fas fa-tablet-alt"></i></div><a href="?" class="small-box-footer">Show all <i class="fas fa-arrow-circle-right"></i></a></div></div>
    <div class="col-6 col-md-3"><div class="small-box bg-danger"><div class="inner"><h3><?= $fleet['noncompliant'] + $fleet['lost'] ?></h3><p>Not compliant or lost (<?= $fleet['lost'] ?> lost)</p></div>
        <div class="icon"><i class="fas fa-exclamation-triangle"></i></div><a href="?state=noncompliant" class="small-box-footer">Not compliant <i class="fas fa-arrow-circle-right"></i></a></div></div>
    <div class="col-6 col-md-3"><div class="small-box bg-warning"><div class="inner"><h3><?= $fleet['stale'] ?></h3><p>Not seen in <?= $stale_days ?>+ days</p></div>
        <div class="icon"><i class="fas fa-clock"></i></div><a href="?state=stale" class="small-box-footer">Filter <i class="fas fa-arrow-circle-right"></i></a></div></div>
    <?php if (!$restricted) { ?>
    <div class="col-6 col-md-3"><div class="small-box bg-secondary"><div class="inner"><h3><?= $fleet['unmatched'] ?></h3><p>Unmatched (need review)</p></div>
        <div class="icon"><i class="fas fa-question-circle"></i></div><a href="?view=unmatched" class="small-box-footer">Review <i class="fas fa-arrow-circle-right"></i></a></div></div>
    <?php } ?>
</div>

<?php if ($by_platform) { ?>
<div class="mb-3">
    <?php foreach ($by_platform as $p => $c) { ?>
        <a class="badge text-bg-<?= strtolower($filter_platform) === strtolower($p) ? 'primary' : 'light' ?> border me-1 p-2" href="?platform=<?= urlencode($p) ?>"><i class="fab <?= $icon_for($p) ?> me-1"></i><?= nullable_htmlentities($p) ?> &middot; <?= $c ?></a>
    <?php } ?>
</div>
<?php } ?>

<ul class="nav nav-tabs mb-2">
    <li class="nav-item"><a class="nav-link <?= $view === 'linked' ? 'active' : '' ?>" href="?">Linked devices</a></li>
    <?php if (!$restricted) { ?><li class="nav-item"><a class="nav-link <?= $view === 'unmatched' ? 'active' : '' ?>" href="?view=unmatched">Needs review (<?= $fleet['unmatched'] ?>)</a></li><?php } ?>
    <?php if (!$restricted && $map_ready) { ?>
        <li class="nav-item"><a class="nav-link <?= $view === 'mapping' ? 'active' : '' ?>" href="?view=mapping"><i class="fas fa-exchange-alt me-1"></i>Mapping</a></li>
        <li class="nav-item"><a class="nav-link <?= $view === 'users' ? 'active' : '' ?>" href="?view=users"><i class="fas fa-user-check me-1"></i>Users to contacts</a></li>
    <?php } ?>
</ul>

<?php if ($view === 'mapping') { require __DIR__ . '/includes/mdm_mapping_view.php'; } elseif ($view === 'users') { require __DIR__ . '/includes/mdm_users_view.php'; } elseif ($view === 'linked') { ?>
<div class="card card-dark mb-2"><div class="card-body py-2">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center">
        <select name="client_id" class="form-select form-select-sm w-auto auto-submit-select">
            <option value="">All departments</option>
            <?php foreach ($clients_list as $cl) { ?>
                <option value="<?= (int) $cl['client_id'] ?>" <?= $filter_client_id === (int) $cl['client_id'] ? 'selected' : '' ?>><?= nullable_htmlentities($cl['client_name']) ?></option>
            <?php } ?>
        </select>
        <select name="state" class="form-select form-select-sm w-auto auto-submit-select">
            <option value="">Any state</option>
            <option value="compliant" <?= $filter_state === 'compliant' ? 'selected' : '' ?>>Compliant</option>
            <option value="noncompliant" <?= $filter_state === 'noncompliant' ? 'selected' : '' ?>>Not compliant</option>
            <option value="lost" <?= $filter_state === 'lost' ? 'selected' : '' ?>>Lost mode</option>
            <option value="stale" <?= $filter_state === 'stale' ? 'selected' : '' ?>>Not seen in <?= $stale_days ?>+ days</option>
        </select>
        <input type="number" name="older" class="form-control form-control-sm w-auto" style="width:9rem !important" min="0" max="3650" value="<?= $filter_older ?: '' ?>" placeholder="No contact for N days" aria-label="Last contact older than this many days">
        <input type="search" name="q" class="form-control form-control-sm w-auto" value="<?= nullable_htmlentities($filter_q) ?>" placeholder="Search name, serial, IMEI, user" aria-label="Search">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i>Filter</button>
        <input type="hidden" name="platform" value="<?= htmlspecialchars($filter_platform, ENT_QUOTES) ?>">
        <?php if ($filter_platform || $filter_state || $filter_client_id || $filter_q !== '' || $filter_older) { ?><a href="?" class="btn btn-secondary btn-sm">Clear filters</a><?php } ?>
    </form>
</div></div>

<div class="card card-dark"><div class="card-body p-0">
<?php if (!$linked) { ?>
    <div class="text-center text-muted py-5"><i class="fas fa-tablet-alt fa-3x mb-3"></i><p>No linked devices<?= $filter_platform || $filter_state || $filter_client_id || $filter_q !== '' || $filter_older ? ' match these filters' : ' yet. Run a sync from the settings page' ?>.</p></div>
<?php } else { ?>
    <div class="table-responsive"><table class="table table-hover table-sm mb-0">
        <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
            <tr><th class="ps-3">Status</th><th>Device</th><th>Department</th><th>OS</th><th>User</th><th>Battery</th><th>Last contact</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($linked as $row) { $st = MdmDeviceStatus::classify($row, $stale_days); ?>
            <tr>
                <td class="ps-3"><span class="badge text-bg-<?= $st['class'] ?>"><?= nullable_htmlentities($st['label']) ?></span></td>
                <td>
                    <a class="fw-bold" href="/agent/asset_details.php?client_id=<?= (int) $row['asset_client_id'] ?>&asset_id=<?= (int) $row['asset_id'] ?>"><?= nullable_htmlentities($row['device_name'] ?: $row['asset_name']) ?></a>
                    <div class="small text-muted"><?= nullable_htmlentities($row['model']) ?><?= $row['serial_number'] ? ' &middot; SN ' . nullable_htmlentities($row['serial_number']) : '' ?></div>
                </td>
                <td><?= $row['asset_client_id'] ? '<a href="/agent/client_overview.php?client_id=' . (int) $row['asset_client_id'] . '">' . nullable_htmlentities($row['client_name']) . '</a>' : '<span class="text-muted">&mdash;</span>' ?></td>
                <td class="text-muted small"><i class="fab <?= $icon_for((string) $row['platform']) ?> me-1"></i><?= nullable_htmlentities(trim($row['platform'] . ' ' . $row['os_version'])) ?: '&mdash;' ?></td>
                <td class="text-muted small"><?= nullable_htmlentities($row['user_name'] ?: $row['user_email']) ?: '&mdash;' ?></td>
                <td class="text-muted small"><?= $row['battery_level'] !== null ? (int) $row['battery_level'] . '%' : '&mdash;' ?></td>
                <td class="text-muted small"><?= $row['last_contact_at'] ? nullable_htmlentities($row['last_contact_at']) : '&mdash;' ?></td>
                <td class="text-end pe-2"><a href="/agent/asset_details.php?client_id=<?= (int) $row['asset_client_id'] ?>&asset_id=<?= (int) $row['asset_id'] ?>" class="btn btn-xs btn-info" title="View asset"><i class="fas fa-tachometer-alt"></i></a></td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
<?php } ?>
</div></div>

<?php } else { ?>
<div class="d-flex mb-2">
    <p class="text-muted small me-auto mb-0">These devices exist in ManageEngine but matched no asset by serial number, IMEI, UDID or name, matched more than one, or belong to a customer / account that has no department yet (<a href="/admin/settings_integrations.php?tab=rmm#client-mapping">Client mapping</a>). Pick what to do with each.</p>
    <a class="btn btn-sm btn-outline-secondary" href="?view=unmatched<?= $show_dismissed ? '' : '&dismissed=1' ?>"><?= $show_dismissed ? 'Show active' : 'Show ignored' ?></a>
</div>
<div class="card card-dark"><div class="card-body p-0">
<?php if (!$unmatched) { ?>
    <div class="text-center text-muted py-5"><i class="fas fa-check-circle fa-3x mb-3"></i><p>Nothing to review.</p></div>
<?php } else { ?>
    <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
        <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
            <tr><th class="ps-3">Device</th><th>Why</th><th>User</th><th>Last contact</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($unmatched as $u) { $cands = array_filter(array_map('intval', explode(',', (string) $u['candidate_asset_ids']))); ?>
            <tr>
                <td class="ps-3"><i class="fab <?= $icon_for((string) $u['platform']) ?> me-1"></i><strong><?= nullable_htmlentities($u['device_name'] ?: '(no name)') ?></strong>
                    <div class="small text-muted"><?= nullable_htmlentities(trim($u['platform'] . ' ' . $u['os_version'])) ?> &middot; <?= nullable_htmlentities($u['model']) ?><br>
                    SN <?= nullable_htmlentities($u['serial_number'] ?: '-') ?><?= $u['imei'] ? ' &middot; IMEI ' . nullable_htmlentities($u['imei']) : '' ?></div></td>
                <td class="small"><?= nullable_htmlentities($reason_label[$u['reason']] ?? $u['reason']) ?><?= $cands ? '<br><span class="text-muted">Candidates: asset ' . implode(', ', array_map('intval', $cands)) . '</span>' : '' ?></td>
                <td class="small text-muted"><?= nullable_htmlentities($u['user_name'] ?: $u['user_email']) ?: '&mdash;' ?></td>
                <td class="small text-muted"><?= $u['last_contact_at'] ? nullable_htmlentities($u['last_contact_at']) : '&mdash;' ?></td>
                <td style="min-width:340px">
                <?php if ($can_edit && !$u['dismissed']) { ?>
                    <form action="post.php" method="post" class="d-flex gap-1 mb-1">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="unmatched_id" value="<?= (int) $u['id'] ?>">
                        <select name="asset_id" class="form-select form-select-sm" required>
                            <option value="">Link to existing asset...</option>
                            <?php foreach ($mobile_assets as $ma) { ?>
                                <option value="<?= (int) $ma['asset_id'] ?>" <?= in_array((int) $ma['asset_id'], $cands, true) ? 'selected' : '' ?>><?= nullable_htmlentities($ma['asset_name'] . ($ma['asset_serial'] ? ' (SN ' . $ma['asset_serial'] . ')' : '') . ' #' . $ma['asset_id']) ?></option>
                            <?php } ?>
                        </select>
                        <button type="submit" name="link_mdm_unmatched" class="btn btn-sm btn-primary text-nowrap"><i class="fas fa-link me-1"></i>Link</button>
                    </form>
                    <form action="post.php" method="post" class="d-flex gap-1 mb-1">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="unmatched_id" value="<?= (int) $u['id'] ?>">
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="0">No department</option>
                            <?php foreach ($clients_list as $cl) { ?><option value="<?= (int) $cl['client_id'] ?>"><?= nullable_htmlentities($cl['client_name']) ?></option><?php } ?>
                        </select>
                        <button type="submit" name="create_mdm_asset" class="btn btn-sm btn-success text-nowrap"><i class="fas fa-plus me-1"></i>Create asset</button>
                    </form>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="unmatched_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" name="dismiss_mdm_unmatched" class="btn btn-sm btn-outline-secondary">Ignore</button>
                    </form>
                <?php } elseif ($can_edit) { ?>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="unmatched_id" value="<?= (int) $u['id'] ?>">
                        <button type="submit" name="restore_mdm_unmatched" class="btn btn-sm btn-outline-secondary">Restore to review</button>
                    </form>
                <?php } else { ?><span class="text-muted small">Needs edit access</span><?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
<?php } ?>
</div></div>
<?php } ?>

<?php } ?>

<?php require_once "../includes/footer.php"; ?>
