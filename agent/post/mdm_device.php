<?php

/*
 * RivetIT - POST handler for the ManageEngine MDM review list (agent/mdm_devices.php) and the asset panel.
 * Nothing here talks to ManageEngine: it only changes RivetIT's own link tables (and, for "Create asset", adds one asset the person asked for).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapper;

if (isset($_POST['link_mdm_unmatched'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $mdm = MdmClientFactory::row($mysqli);
    $unmatched_id = intval($_POST['unmatched_id'] ?? 0);
    $asset_id = intval($_POST['asset_id'] ?? 0);
    if (!$mdm || $unmatched_id < 1 || $asset_id < 1) {
        flash_alert('Choose an asset to link.', 'error');
        redirect();
    }
    $asset_client_id = intval(getFieldById('assets', $asset_id, 'asset_client_id'));
    enforceClientAccess($asset_client_id);

    $result = (new MdmDeviceMapper($mysqli, (int) $mdm['id']))->linkUnmatched($unmatched_id, $asset_id);
    if ($result['ok']) {
        logAction("MDM Device", "Link", "$session_name linked a ManageEngine MDM device to asset #$asset_id", $asset_client_id, $asset_id);
    }
    flash_alert(nullable_htmlentities($result['message']), $result['ok'] ? 'success' : 'error');
    redirect();
}

if (isset($_POST['create_mdm_asset'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $mdm = MdmClientFactory::row($mysqli);
    $unmatched_id = intval($_POST['unmatched_id'] ?? 0);
    $client_id = intval($_POST['client_id'] ?? 0);
    if (!$mdm || $unmatched_id < 1) {
        flash_alert('That device is no longer in the review list.', 'error');
        redirect();
    }
    enforceClientAccess($client_id);

    $result = (new MdmDeviceMapper($mysqli, (int) $mdm['id']))->createAssetFor($unmatched_id, $client_id);
    if ($result['ok']) {
        logAction("MDM Device", "Create", "$session_name created asset #{$result['asset_id']} from a ManageEngine MDM device", $client_id, $result['asset_id']);
    }
    flash_alert(nullable_htmlentities($result['message']), $result['ok'] ? 'success' : 'error');
    redirect();
}

if (isset($_POST['dismiss_mdm_unmatched']) || isset($_POST['restore_mdm_unmatched'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $mdm = MdmClientFactory::row($mysqli);
    $unmatched_id = intval($_POST['unmatched_id'] ?? 0);
    $dismiss = isset($_POST['dismiss_mdm_unmatched']) ? 1 : 0;
    if ($mdm && $unmatched_id > 0) {
        $integration_id = (int) $mdm['id'];
        $stmt = mysqli_prepare($mysqli, 'UPDATE mdm_unmatched_devices SET dismissed = ? WHERE id = ? AND integration_id = ?');
        mysqli_stmt_bind_param($stmt, 'iii', $dismiss, $unmatched_id, $integration_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logAction("MDM Device", "Edit", "$session_name " . ($dismiss ? 'ignored' : 'restored') . " an unmatched ManageEngine MDM device");
        flash_alert($dismiss ? 'Device ignored. It stays hidden until you restore it.' : 'Device is back in the review list.');
    }
    redirect();
}

if (isset($_POST['unlink_mdm_device'])) {

    validateCSRFToken($_POST['csrf_token']);
    enforceUserPermission('module_client', 2);

    $link_id = intval($_POST['link_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, 'SELECT l.id, l.asset_id, a.asset_client_id FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id WHERE l.id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $link_id);
    mysqli_stmt_execute($stmt);
    $link = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if ($link) {
        enforceClientAccess(intval($link['asset_client_id']));
        $stmt = mysqli_prepare($mysqli, 'DELETE FROM asset_mdm_links WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $link_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        logAction("MDM Device", "Unlink", "$session_name removed the ManageEngine MDM link from asset #{$link['asset_id']}", intval($link['asset_client_id']), intval($link['asset_id']));
        flash_alert('MDM link removed. The next sync links the device again if its serial still matches this asset.');
    }
    redirect();
}
