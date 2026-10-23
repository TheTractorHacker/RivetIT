<?php
/*
 * ManageEngine MDM devices > Users to contacts: the device users ManageEngine reports (name and e-mail), the contact each one maps to,
 * and a "Needs mapping" list where a person picks the contact (search-as-you-type, narrowed to the department of the user's device when it
 * has one) or marks the user ignored. Included by agent/mdm_devices.php for an unrestricted user only; posts to agent/post/mdm_mapping.php.
 * Variables from the including page: $mysqli, $mdm, $iid, $csrf, $can_edit.
 */

use ITFlow\Integrations\ManageEngine\MdmMappingSettings;
use ITFlow\Integrations\ManageEngine\MdmUserContactMapper;

$u_filter = in_array($_GET['ustatus'] ?? '', ['mapped', 'ignored', 'all'], true) ? $_GET['ustatus'] : 'needs';
$u_q = trim((string) ($_GET['uq'] ?? ''));
$u_settings = MdmMappingSettings::load($mysqli, $iid);

$w = 'm.integration_id = ?';
$types = 'i';
$params = [$iid];
if ($u_filter === 'needs') {
    $w .= ' AND m.contact_id IS NULL AND m.ignored = 0';
} elseif ($u_filter === 'mapped') {
    $w .= ' AND m.contact_id IS NOT NULL AND m.ignored = 0';
} elseif ($u_filter === 'ignored') {
    $w .= ' AND m.ignored = 1';
}
if ($u_q !== '') {
    $w .= ' AND (m.user_name LIKE ? OR m.user_email LIKE ?)';
    $types .= 'ss';
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $u_q) . '%';
    array_push($params, $like, $like);
}
$stmt = mysqli_prepare($mysqli, "SELECT m.*, c.contact_name, c.contact_email, c.contact_archived_at, cl.client_name
    FROM mdm_user_contact_map m LEFT JOIN contacts c ON c.contact_id = m.contact_id LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
    WHERE $w ORDER BY m.user_name ASC, m.user_email ASC LIMIT 500");
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$u_rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$u_counts = ['needs' => 0, 'mapped' => 0, 'ignored' => 0];
$cr = mysqli_query($mysqli, "SELECT SUM(contact_id IS NULL AND ignored = 0) AS needs, SUM(contact_id IS NOT NULL AND ignored = 0) AS mapped, SUM(ignored = 1) AS ignored FROM mdm_user_contact_map WHERE integration_id = $iid");
if ($cr && ($x = mysqli_fetch_assoc($cr))) {
    $u_counts = array_map('intval', $x);
}

// Devices per user (name and department), for the list and to narrow the contact picker.
$dev_by_user = [];
$res = mysqli_query($mysqli, "SELECT l.device_name, l.user_name, l.user_email, a.asset_client_id FROM asset_mdm_links l LEFT JOIN assets a ON a.asset_id = l.asset_id WHERE l.integration_id = $iid AND (a.asset_archived_at IS NULL OR a.asset_id IS NULL)");
while ($res && ($d = mysqli_fetch_assoc($res))) {
    $k = MdmUserContactMapper::userKey($d['user_email'], $d['user_name']);
    $dev_by_user[$k]['devices'][] = $d['device_name'] ?: 'device';
    if ((int) $d['asset_client_id'] > 0) {
        $dev_by_user[$k]['clients'][(int) $d['asset_client_id']] = true;
    }
}
?>

<div class="card card-dark mb-2"><div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center mdm-toolbar">
    <form method="get" class="d-flex flex-wrap gap-2 align-items-center me-auto">
        <input type="hidden" name="view" value="users">
        <input type="search" name="uq" value="<?= nullable_htmlentities($u_q) ?>" class="form-control mdm-search" placeholder="Search user name or e-mail" aria-label="Search user name or e-mail">
        <select name="ustatus" class="form-select w-auto mdm-status" aria-label="Show">
            <option value="needs" <?= $u_filter === 'needs' ? 'selected' : '' ?>>Needs mapping (<?= $u_counts['needs'] ?>)</option>
            <option value="mapped" <?= $u_filter === 'mapped' ? 'selected' : '' ?>>Mapped (<?= $u_counts['mapped'] ?>)</option>
            <option value="ignored" <?= $u_filter === 'ignored' ? 'selected' : '' ?>>Ignored (<?= $u_counts['ignored'] ?>)</option>
            <option value="all" <?= $u_filter === 'all' ? 'selected' : '' ?>>All</option>
        </select>
        <button class="btn btn-outline-secondary" type="submit"><i class="fas fa-search me-1"></i>Filter</button>
    </form>
    <?php if ($can_edit) { ?>
    <form action="post.php" method="post" class="d-flex flex-wrap gap-2">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button type="submit" name="refresh_mdm_users" class="btn btn-outline-secondary" title="Re-read the device users and map exact e-mail matches"><i class="fas fa-sync me-1"></i>Rescan users</button>
        <?php if ($u_settings['assign_contact']) { ?>
            <button type="submit" name="assign_mdm_contacts" value="preview" class="btn btn-outline-secondary"><i class="fas fa-eye me-1"></i>Preview</button>
            <button type="submit" name="assign_mdm_contacts" value="apply" class="btn btn-primary"><i class="fas fa-user-check me-1"></i>Assign contacts now</button>
        <?php } ?>
    </form>
    <?php } ?>
</div></div>

<div class="alert alert-info d-flex gap-3 align-items-start mdm-explainer" role="note">
    <i class="fas fa-info-circle mt-1" aria-hidden="true"></i>
    <div>
        <div class="fw-bold mb-1">How users are matched to contacts
            <?php if ($u_settings['assign_contact']) { ?><span class="badge text-bg-success ms-2">Automatic assignment ON</span><?php } else { ?><span class="badge text-bg-secondary ms-2">Automatic assignment OFF</span><?php } ?>
        </div>
        <p class="mb-1">ManageEngine reports each device's user as a name and an e-mail address. A user is matched to a contact when exactly one live contact has that e-mail (exact, ignoring case).</p>
        <p class="mb-0">
        <?php if ($u_settings['assign_contact']) { ?>
            A sync fills an empty Assigned To<?= $u_settings['assign_contact_overwrite'] ? ' and, because overwrite is on, replaces a different contact' : ' (a contact that is already set is kept)' ?>, only with a contact from the asset's department.
        <?php } else { ?>
            Mappings are only recorded; no asset is changed.
        <?php } ?>
        <?= $session_is_admin ? '<a href="/admin/settings_integrations.php?tab=devicesync">Mapping options</a>' : 'An administrator can change this under Mapping options.' ?>
        </p>
    </div>
</div>

<div class="card card-dark"><div class="card-body p-0">
<?php if (!$u_rows) { ?>
    <div class="text-center text-muted py-5"><i class="fas fa-user-check fa-3x mb-3"></i><p><?= $u_filter === 'needs' ? 'Every MDM user is mapped or ignored.' : 'Nothing here.' ?> Run a sync (or Rescan users) if you expect more.</p></div>
<?php } else { ?>
    <div class="table-responsive mdm-scroll"><table class="table table-sm mb-0 align-middle">
        <thead class="text-muted small border-bottom" style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
            <tr><th class="ps-3">MDM user</th><th>Devices</th><th>Contact</th><th class="mdm-action-col">Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($u_rows as $m) {
            $info = $dev_by_user[$m['user_key']] ?? ['devices' => [], 'clients' => []];
            $dept = count($info['clients'] ?? []) === 1 ? (int) array_key_first($info['clients']) : 0;
            $id = (int) $m['id']; ?>
            <tr>
                <td class="ps-3"><strong><?= nullable_htmlentities($m['user_name'] ?: '(no name)') ?></strong><div class="small text-muted"><?= nullable_htmlentities($m['user_email'] ?: 'no e-mail reported') ?></div></td>
                <td class="small text-muted"><?= nullable_htmlentities(implode(', ', array_slice($info['devices'] ?? [], 0, 3))) ?><?= count($info['devices'] ?? []) > 3 ? ' +' . (count($info['devices']) - 3) : '' ?></td>
                <td class="small">
                    <?php if ((int) $m['ignored'] === 1) { ?><span class="badge text-bg-secondary">Ignored</span>
                    <?php } elseif ($m['contact_id'] !== null) { ?>
                        <?php if ($m['contact_name'] === null || $m['contact_archived_at'] !== null) { ?><span class="badge text-bg-warning">Contact gone</span>
                        <?php } else { ?><a href="/agent/contact_details.php?contact_id=<?= (int) $m['contact_id'] ?>"><?= nullable_htmlentities($m['contact_name']) ?></a>
                            <div class="text-muted"><?= nullable_htmlentities((string) $m['client_name']) ?> &middot; <?= $m['source'] === 'auto' ? 'matched by e-mail' : 'chosen by a person' ?></div><?php } ?>
                    <?php } else { ?><span class="badge text-bg-warning">Needs mapping</span><?php } ?>
                </td>
                <td>
                <?php if (!$can_edit) { ?><span class="text-muted small">Needs edit access</span>
                <?php } elseif ($m['contact_id'] === null && (int) $m['ignored'] === 0 || $m['contact_name'] === null && (int) $m['ignored'] === 0) { ?>
                    <form action="post.php" method="post" class="d-flex gap-2 mdm-map-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="entry_id" value="<?= $id ?>">
                        <input type="text" class="form-control mdm-typeahead" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-label="Search for a contact to map" data-kind="contact" data-target="contact_for_<?= $id ?>" <?= $dept ? 'data-client-id="' . $dept . '"' : '' ?> placeholder="Search contacts<?= $dept ? ' (this department)' : '' ?>..." autocomplete="off">
                        <input type="hidden" id="contact_for_<?= $id ?>" name="contact_id" value="">
                        <button type="submit" name="map_mdm_user" value="1" class="btn btn-primary text-nowrap mdm-map" disabled title="Choose a contact first"><i class="fas fa-link me-1"></i>Map</button>
                        <button type="submit" name="ignore_mdm_user" value="1" class="btn btn-outline-secondary">Ignore</button>
                    </form>
                <?php } else { ?>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="entry_id" value="<?= $id ?>">
                        <button type="submit" name="unmap_mdm_user" value="1" class="btn btn-outline-secondary"><?= (int) $m['ignored'] === 1 ? 'Stop ignoring' : 'Clear mapping' ?></button>
                    </form>
                <?php } ?>
                </td>
            </tr>
        <?php } ?>
        </tbody>
    </table></div>
<?php } ?>
</div></div>
<script src="/agent/js/mdm_mapping.js?v=<?= filemtime(__DIR__ . '/../js/mdm_mapping.js') ?>"></script>
