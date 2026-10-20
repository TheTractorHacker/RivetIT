<?php
/*
 * ManageEngine MDM Overview for the asset page.
 *
 * WHAT THIS IS
 * ------------
 * Required by agent/asset_details.php from inside the "ManageEngine MDM" block, right where the old one-line compact card used to sit. It
 * replaces that card with a fuller read-only Overview (hero, tiles, sections) and folds the old card's own information into it (nothing the
 * old card showed is dropped), while staying READ-ONLY throughout: this is a cached record of the last sync, never a live agent, so there is
 * no Run script, no Reboot and no Metrics action anywhere on this page, unlike the RivetIT RMM panel above it.
 *
 * DATA CONTRACT
 * -------------
 * Every field rendered here is a real column of `asset_mdm_links` that the sync actually writes (src/Integrations/ManageEngine/MdmDeviceMapper::FIELDS),
 * plus the handful of integration-level columns the caller already joined in (stale_days, mdm_enabled, mdm_edition, mdm_server_url, mdm_data_center) and
 * the link row's own id/timestamps (registered_at, last_contact_at, last_sync, match_method, customer_id, customer_name). Nothing here is invented:
 *   - there is no "enrollment profile" column, so Management shows the real `enrollment_status` (enrolled / removed / unenrolled) instead.
 *   - there is no phone number or carrier column, so Network holds only the one real network-ish identifier this sync stores (IMEI, masked
 *     the same way as the Hardware ids); it is not padded out with fields ManageEngine's API/this sync never gives RivetIT.
 *
 * MASKING
 * -------
 * Serial, UDID and IMEI are shown as the last 4 characters only, the rest replaced with the bullet "•" this app already uses for a masked
 * value (agent/modals/credential/credential_view.php's password mask); there was no existing *identifier*-masking helper to reuse, so
 * mdmMaskId() below is the one used everywhere in this file for that purpose.
 *
 * Expects, from the caller's scope (same contract as agent/includes/asset/metrics_tab.php): $mysqli, $asset_id, $asset_name, $mdm_link,
 * $mdm_st, $mdm_free, $mdm_stale_days, $mdm_os_icon, $mdm_console, $mdm_assign_offer.
 */

if (!function_exists('mdmMaskId')) {
    /** The last 4 characters only; everything before them becomes "•". Too short to usefully mask (4 chars or fewer) is shown as-is. */
    function mdmMaskId(?string $v): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        $len = mb_strlen($v);

        return $len <= 4 ? $v : str_repeat('•', $len - 4) . mb_substr($v, -4);
    }
}

/** A tile for the stat row (reuses the app's existing .ifm-card / .it-stat-card, loaded globally by css/itflow_metrics.css and css/itflow_design.css). */
$mdmTile = static function (string $icon, string $tint, string $value, string $label): string {
    return '<div class="ifm-card"><div class="it-stat-card"><div class="it-stat-icon it-tint-' . nullable_htmlentities($tint) . '"><i class="fas fa-' . nullable_htmlentities($icon) . '" aria-hidden="true"></i></div>'
        . '<div class="it-stat-body"><div class="it-stat-value">' . $value . '</div><div class="it-stat-label">' . nullable_htmlentities($label) . '</div></div></div></div>';
};
/** A definition-list row; $v is pre-escaped HTML (the caller decides how). */
$mdmRow = static function (string $k, string $v): string {
    return '<tr><th scope="row" class="text-muted fw-normal pe-3" style="width:40%;white-space:nowrap">' . nullable_htmlentities($k) . '</th><td>' . $v . '</td></tr>';
};
$mdmDl = static function (array $rows) use ($mdmRow): string {
    $body = '';
    foreach ($rows as [$k, $v]) {
        $body .= $mdmRow($k, $v);
    }

    return '<table class="table table-sm table-borderless mb-0"><tbody>' . $body . '</tbody></table>';
};
$mdmOrNone = static function (?string $v): string {
    return $v === null || trim($v) === '' ? '<span class="text-muted">not reported</span>' : nullable_htmlentities($v);
};
$mdmYesNo = static function (?string $v, string $yes, string $no): string {
    if ($v === null || $v === '') {
        return '<span class="text-muted">not reported</span>';
    }

    return (int) $v === 1 ? $yes : $no;
};

$mdmPlatformName = trim((string) ($mdm_link['platform'] ?? '') . ' ' . (string) ($mdm_link['os_version'] ?? ''));
$mdmOwnership = $mdm_link['owned_by'] ? ucfirst((string) $mdm_link['owned_by']) . '-owned' : null;
$mdmAssignedUser = $mdm_link['user_name'] ?: $mdm_link['user_email'];
?>
<div class="card card-dark mb-2" style="border-left:5px solid <?= $mdm_st['class'] === 'danger' ? '#dc3545' : ($mdm_st['class'] === 'warning' ? '#ffc107' : '#0d6efd') ?>; border-radius:4px;">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center flex-wrap" style="gap:8px">
            <div class="mr-auto me-auto">
                <strong class="h5 mb-0"><i class="fas fa-tablet-alt me-1" aria-hidden="true"></i>ManageEngine MDM: <?= nullable_htmlentities($mdm_link['device_name'] ?: $asset_name) ?></strong>
                <span class="badge text-bg-<?= $mdm_st['class'] ?> ms-2"><?= nullable_htmlentities($mdm_st['label']) ?></span>
                <?php if ((int) ($mdm_link['is_supervised'] ?? 0) === 1) { ?><span class="badge text-bg-info ms-1">Supervised</span><?php } ?>
                <?php if ($mdm_link['passcode_compliant'] !== null) { ?><span class="badge text-bg-<?= (int) $mdm_link['passcode_compliant'] ? 'success' : 'danger' ?> ms-1"><i class="fas fa-lock me-1" aria-hidden="true"></i>Passcode <?= (int) $mdm_link['passcode_compliant'] ? 'compliant' : 'not compliant' ?></span><?php } ?>
                <?php if ((int) ($mdm_link['is_lost_mode'] ?? 0) === 1) { ?><span class="badge text-bg-danger ms-1">Lost mode on</span><?php } ?>
                <?php if ($mdm_st['stale']) { ?><span class="badge text-bg-warning ms-1"><i class="fas fa-clock me-1" aria-hidden="true"></i>Not seen <?= (int) $mdm_stale_days ?>+ days</span><?php } ?>
                <div class="text-muted small mt-1">
                    <?php if ($mdm_link['model']) { ?><?= nullable_htmlentities($mdm_link['model']) ?><?php } elseif ($mdm_link['product_name']) { ?><?= nullable_htmlentities($mdm_link['product_name']) ?><?php } ?>
                    <?php if ($mdmPlatformName !== '') { ?>&bull;&nbsp;<i class="fab <?= $mdm_os_icon ?> me-1" aria-hidden="true"></i><?= nullable_htmlentities($mdmPlatformName) ?><?php } ?>
                    <?php if ($mdmOwnership) { ?>&bull;&nbsp;<i class="fas fa-briefcase mx-1" aria-hidden="true"></i><?= nullable_htmlentities($mdmOwnership) ?><?php } ?>
                    <?php if ($mdmAssignedUser) { ?>&bull;&nbsp;<i class="fas fa-user mx-1" aria-hidden="true"></i><?= nullable_htmlentities($mdmAssignedUser) ?><?php } ?>
                    <?php if ($mdm_link['group_names']) { ?>&bull;&nbsp;<i class="fas fa-users mx-1" aria-hidden="true"></i><?= nullable_htmlentities($mdm_link['group_names']) ?><?php } ?>
                </div>
            </div>
            <div>
                <?php if ($mdm_console !== '' && preg_match('#^https://#i', $mdm_console)) { ?>
                <a href="<?= nullable_htmlentities($mdm_console) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" title="Open the MDM console"><i class="fas fa-external-link-alt me-1" aria-hidden="true"></i>Console</a>
                <?php } ?>
                <a href="/agent/mdm_devices.php" class="btn btn-sm btn-outline-secondary">Mobile devices</a>
                <?php if ($mdm_assign_offer['status'] === 'assign') { ?>
                <form action="post.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="link_id" value="<?= (int) $mdm_link['id'] ?>">
                    <button type="submit" name="assign_mdm_contact_asset" class="btn btn-sm btn-outline-primary" title="The device user is mapped to a contact. Set Assigned To from it."><i class="fas fa-user-check me-1" aria-hidden="true"></i>Set contact from MDM user</button>
                </form>
                <?php } ?>
                <?php if (lookupUserPermission('module_client') >= 2) { ?>
                <form action="post.php" method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="link_id" value="<?= (int) $mdm_link['id'] ?>">
                    <button type="submit" name="unlink_mdm_device" class="btn btn-sm btn-outline-danger confirm-link" title="Remove link"><i class="fas fa-unlink" aria-hidden="true"></i></button>
                </form>
                <?php } ?>
            </div>
        </div>
    </div>
    <div class="card-body border-top py-2">
        <div class="ifm-cards">
            <?= $mdmTile('battery-half', $mdm_link['battery_level'] !== null && (int) $mdm_link['battery_level'] <= 20 ? 'danger' : 'success',
                $mdm_link['battery_level'] !== null ? (int) $mdm_link['battery_level'] . '%' : '<span class="text-muted">no data</span>', 'Battery') ?>
            <?= $mdmTile('hdd', $mdm_free !== null && $mdm_free <= 10 ? 'danger' : 'info', $mdm_free !== null ? $mdm_free . '%' : '<span class="text-muted">no data</span>', 'Storage free') ?>
            <?= $mdmTile('clock', $mdm_st['stale'] ? 'warning' : 'success', $mdm_link['last_contact_at'] ? nullable_htmlentities(substr((string) $mdm_link['last_contact_at'], 0, 16)) : '<span class="text-muted">never</span>', 'Last contact') ?>
            <?= $mdmTile('calendar-check', 'secondary', $mdm_link['registered_at'] ? nullable_htmlentities(substr((string) $mdm_link['registered_at'], 0, 10)) : '<span class="text-muted">not reported</span>', 'Enrolled') ?>
            <?= $mdmTile('sync', 'secondary', $mdm_link['last_sync'] ? nullable_htmlentities(substr((string) $mdm_link['last_sync'], 0, 16)) : '<span class="text-muted">never</span>', 'Last synced') ?>
        </div>
    </div>
    <div class="card-body border-top py-2">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="ifm-toolbar-label mb-1">Hardware</div>
                <?= $mdmDl([
                    ['Model', $mdmOrNone($mdm_link['model'] ?: $mdm_link['product_name'])],
                    ['Serial number', '<span class="ifm-mono">' . $mdmOrNone(mdmMaskId($mdm_link['serial_number'])) . '</span>'],
                    ['UDID', '<span class="ifm-mono">' . $mdmOrNone(mdmMaskId($mdm_link['udid'])) . '</span>'],
                ]) ?>
            </div>
            <div class="col-md-6">
                <div class="ifm-toolbar-label mb-1">Operating system</div>
                <?= $mdmDl([
                    ['Platform', $mdmOrNone($mdm_link['platform'])],
                    ['Version', $mdmOrNone($mdm_link['os_version'])],
                ]) ?>
            </div>
            <div class="col-md-6">
                <div class="ifm-toolbar-label mb-1">Network</div>
                <?= $mdmDl([
                    ['IMEI', '<span class="ifm-mono">' . $mdmOrNone(mdmMaskId($mdm_link['imei'])) . '</span>'],
                ]) ?>
            </div>
            <div class="col-md-6">
                <div class="ifm-toolbar-label mb-1">Security</div>
                <?= $mdmDl([
                    ['Passcode', $mdmYesNo($mdm_link['passcode_compliant'] !== null ? (string) $mdm_link['passcode_compliant'] : null, '<span class="badge text-bg-success">Compliant</span>', '<span class="badge text-bg-danger">Not compliant</span>')],
                    ['Lost mode', $mdmYesNo($mdm_link['is_lost_mode'] !== null ? (string) $mdm_link['is_lost_mode'] : null, '<span class="badge text-bg-danger">On</span>', 'Off')],
                    ['Supervised', $mdmYesNo($mdm_link['is_supervised'] !== null ? (string) $mdm_link['is_supervised'] : null, 'Yes', 'No')],
                    ['Rooted / jailbroken', $mdmYesNo($mdm_link['is_rooted'] !== null ? (string) $mdm_link['is_rooted'] : null, '<span class="badge text-bg-danger">Yes</span>', 'No')],
                ]) ?>
            </div>
            <div class="col-md-6">
                <div class="ifm-toolbar-label mb-1">Management</div>
                <?= $mdmDl([
                    ['MDM edition', $mdmOrNone($mdm_link['mdm_edition'] ?? null)],
                    ['Server / data center', $mdmOrNone($mdm_link['mdm_server_url'] ?: ($mdm_link['mdm_data_center'] ?? null))],
                    ['Integration', (int) ($mdm_link['mdm_enabled'] ?? 0) === 1 ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>'],
                    ['Enrollment status', $mdmOrNone($mdm_link['enrollment_status'] ?? null)],
                    ['Matched by', $mdmOrNone($mdm_link['match_method'] ?? null)],
                    ['Customer (MDM)', $mdmOrNone($mdm_link['customer_name'] ?? null)],
                ]) ?>
            </div>
        </div>
    </div>
</div>
