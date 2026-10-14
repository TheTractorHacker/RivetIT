<?php

/*
 * RivetIT - POST handler for the ManageEngine MDM mapping screens (agent/mdm_devices.php?view=mapping and ?view=users) and the asset panel's
 * "set contact" button. Nothing here talks to ManageEngine and nothing here writes an asset's own fields except the assigned contact, and
 * that only when the owner switched "Assign contact from MDM user" on (Integrations > Device Sync > Mapping options).
 *
 * EVERY action (single and bulk) needs module_client level 2 and is closed to a department-scoped non-admin, exactly like the review-list
 * actions in mdm_device.php: these screens span departments (and the unmatched list belongs to none). Asset-side access is checked again
 * for each asset a row touches (MdmDeviceMapping gets the access rule), and contact-side access for each contact chosen.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

use ITFlow\Integrations\ManageEngine\MdmClientFactory;
use ITFlow\Integrations\ManageEngine\MdmDeviceMapping;
use ITFlow\Integrations\ManageEngine\MdmMappingSettings;
use ITFlow\Integrations\ManageEngine\MdmUserContactMapper;

if (!function_exists('mdmRequireUnscopedUser')) {
    function mdmRequireUnscopedUser(): void
    {
        global $client_access_string, $session_is_admin;
        if ($client_access_string && !$session_is_admin) {
            flash_alert('You do not have access to the MDM review list.', 'error');
            redirect();
        }
    }
}

/** Whether the signed-in person may touch records of this department (client id 0 = no department). Same rule as enforceClientAccess(), without the redirect. */
if (!function_exists('mdmCanAccessClient')) {
    function mdmCanAccessClient(int $clientId): bool
    {
        global $session_is_admin, $client_access_array;
        if ($clientId < 0) {
            return false;
        }
        if ($session_is_admin || $clientId === 0 || empty($client_access_array)) {
            return true;
        }

        return in_array($clientId, array_map('intval', $client_access_array), true);
    }
}

/** The shared preamble of every mapping action. Returns the integration row (or redirects). */
if (!function_exists('mdmMappingGate')) {
    function mdmMappingGate(): array
    {
        validateCSRFToken($_POST['csrf_token'] ?? null);
        enforceUserPermission('module_client', 2);
        mdmRequireUnscopedUser();
        $mdm = MdmClientFactory::row($GLOBALS['mysqli']);
        if (!$mdm || !MdmMappingSettings::schemaReady($GLOBALS['mysqli'])) {
            flash_alert('The ManageEngine MDM integration is not set up (or the database update is pending).', 'error');
            redirect();
        }

        return $mdm;
    }
}

if (isset($_POST['mdm_map_bulk']) || isset($_POST['mdm_map_one'])) {

    $mdm = mdmMappingGate();

    if (isset($_POST['mdm_map_one'])) {
        // One row's own button: "<token>|<action>". The row checkboxes are ignored.
        [$one_token, $action] = array_pad(explode('|', (string) $_POST['mdm_map_one'], 2), 2, '');
        $tokens = [$one_token];
    } else {
        $action = (string) $_POST['mdm_map_bulk'];
        $tokens = is_array($_POST['rows'] ?? null) ? array_map('strval', $_POST['rows']) : [];
    }
    if (!in_array($action, ['link', 'unlink', 'ignore', 'restore'], true) || !$tokens) {
        flash_alert('Select at least one device first.', 'error');
        redirect();
    }
    $asset_for = [];
    foreach ((is_array($_POST['asset_for'] ?? null) ? $_POST['asset_for'] : []) as $tok => $aid) {
        $asset_for[(string) $tok] = intval($aid);
    }
    $replace = isset($_POST['replace']) && $_POST['replace'] === '1';

    $audit = static function (string $act, string $text, int $client_id, int $asset_id) use ($session_name): void {
        logAction("MDM Device", $act, "$session_name $text", $client_id, $asset_id);
    };
    $result = (new MdmDeviceMapping($mysqli, (int) $mdm['id'], 'mdmCanAccessClient', $audit))->run($action, $tokens, $asset_for, $replace);

    $verb = ['link' => 'linked', 'unlink' => 'unlinked', 'ignore' => 'ignored', 'restore' => 'restored'][$action];
    $msg = "<strong>{$result['ok']}</strong> device(s) $verb" . ($result['failed'] ? ", <strong>{$result['failed']}</strong> not changed" : '') . '.';
    $reasons = [];
    foreach ($result['results'] as $r) {
        if (!$r['ok'] && count($reasons) < 5) {
            $reasons[] = nullable_htmlentities($r['message']);
        }
    }
    if ($reasons) {
        $msg .= '<br>' . implode('<br>', $reasons);
    }
    if (count($tokens) > 1) {
        logAction("MDM Device", "Bulk Edit", "$session_name ran bulk MDM device action '$action': {$result['ok']} changed, {$result['failed']} not changed");
    }
    flash_alert($msg, $result['failed'] && !$result['ok'] ? 'error' : ($result['failed'] ? 'warning' : 'success'));
    redirect();
}

if (isset($_POST['map_mdm_user']) || isset($_POST['ignore_mdm_user']) || isset($_POST['unmap_mdm_user'])) {

    $mdm = mdmMappingGate();
    $users = new MdmUserContactMapper($mysqli, (int) $mdm['id']);
    $entry = $users->entry(intval($_POST['entry_id'] ?? 0));
    if (!$entry) {
        flash_alert('That MDM user is no longer listed. Reload the page.', 'error');
        redirect();
    }

    if (isset($_POST['map_mdm_user'])) {
        $contact_id = intval($_POST['contact_id'] ?? 0);
        $check = $contact_id > 0 ? $users->validateChoice((string) $entry['user_key'], $contact_id) : ['ok' => false, 'message' => 'Choose a contact first.', 'client_id' => 0];
        if (!$check['ok']) {
            flash_alert(nullable_htmlentities($check['message']), 'error');
            redirect();
        }
        enforceClientAccess($check['client_id']);
        $users->setChoice((int) $entry['id'], $contact_id, false, intval($session_user_id));
        logAction("MDM Device", "Edit", "$session_name mapped an MDM user to contact #$contact_id", $check['client_id'], $contact_id);
        flash_alert('MDM user mapped to the contact.');
    } elseif (isset($_POST['ignore_mdm_user'])) {
        $users->setChoice((int) $entry['id'], null, true, intval($session_user_id));
        logAction("MDM Device", "Edit", "$session_name set an MDM user to be ignored for contact mapping");
        flash_alert('MDM user ignored. No contact will be set from this user.');
    } else {
        $users->setChoice((int) $entry['id'], null, false, intval($session_user_id));
        logAction("MDM Device", "Edit", "$session_name cleared an MDM user's contact mapping");
        flash_alert('Mapping cleared. The user is back in "Needs mapping".');
    }
    redirect();
}

/*
 * Assign contacts from MDM users, for every linked device (preview or apply). Only does anything while the owner has switched
 * "Assign contact from MDM user" on; fills an EMPTY Assigned To unless "overwrite" is also on.
 */
if (isset($_POST['assign_mdm_contacts'])) {

    $mdm = mdmMappingGate();
    $settings = MdmMappingSettings::load($mysqli, (int) $mdm['id']);
    if (!$settings['assign_contact']) {
        flash_alert('"Assign contact from MDM user" is switched off (Integrations > Device Sync > Mapping options). Nothing was changed.', 'error');
        redirect();
    }
    $dry = ($_POST['assign_mdm_contacts'] ?? '') === 'preview';
    $users = new MdmUserContactMapper($mysqli, (int) $mdm['id']);
    $users->refresh();
    $c = $users->assignAll($settings, intval($session_user_id), $dry);
    $text = ($dry ? 'Preview, nothing changed: ' : '') . ($c['assign'] ?? 0) . ' asset contact(s) ' . ($dry ? 'would be set' : 'set')
        . ', ' . ($c['kept_existing'] ?? 0) . ' already have a different contact, ' . ($c['no_mapping'] ?? 0) . ' MDM user(s) not mapped yet, '
        . ($c['other_department'] ?? 0) . ' in another department than the contact.';
    if (!$dry) {
        logAction("MDM Device", "Bulk Edit", "$session_name assigned asset contacts from MDM users: " . ($c['assign'] ?? 0) . ' set');
    }
    flash_alert($text);
    redirect();
}

/* Rescan the device users (own table only): note new users and auto-map exact e-mail matches. */
if (isset($_POST['refresh_mdm_users'])) {

    $mdm = mdmMappingGate();
    $r = (new MdmUserContactMapper($mysqli, (int) $mdm['id']))->refresh();
    logAction("MDM Device", "Edit", "$session_name rescanned ManageEngine MDM users for contact mapping");
    flash_alert("{$r['users']} MDM user(s) found, {$r['new']} new, {$r['auto_mapped']} mapped to a contact by e-mail.");
    redirect();
}

/* The asset panel's "Set contact" button: one linked asset. */
if (isset($_POST['assign_mdm_contact_asset'])) {

    $mdm = mdmMappingGate();
    $link_id = intval($_POST['link_id'] ?? 0);
    $stmt = mysqli_prepare($mysqli, 'SELECT l.id, l.asset_id, a.asset_client_id FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id WHERE l.id = ? AND l.integration_id = ?');
    $mdm_integration_id = (int) $mdm['id'];
    mysqli_stmt_bind_param($stmt, 'ii', $link_id, $mdm_integration_id);
    mysqli_stmt_execute($stmt);
    $link = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$link) {
        flash_alert('That device link no longer exists.', 'error');
        redirect();
    }
    enforceClientAccess(intval($link['asset_client_id']));
    $settings = MdmMappingSettings::load($mysqli, $mdm_integration_id);
    $e = (new MdmUserContactMapper($mysqli, $mdm_integration_id))->assignForLink($link_id, $settings, intval($session_user_id));
    $labels = [
        'assign' => 'Assigned contact set from the MDM user.', 'unchanged' => 'The asset already has that contact.', 'disabled' => '"Assign contact from MDM user" is switched off. Nothing was changed.',
        'no_user' => 'This device has no user.', 'no_mapping' => 'The device user is not mapped to a contact yet.', 'ignored' => 'The device user is set to be ignored.',
        'contact_gone' => 'The mapped contact no longer exists or is archived.', 'other_department' => 'The mapped contact is in another department than this asset.',
        'kept_existing' => 'The asset already has a different assigned contact and "overwrite" is off.', 'no_asset' => 'That asset is archived or missing.',
    ];
    if ($e['status'] === 'assign') {
        logAction("Asset", "Edit", "$session_name set the assigned contact of asset #{$link['asset_id']} from its ManageEngine MDM user", intval($link['asset_client_id']), intval($link['asset_id']));
    }
    flash_alert($labels[$e['status']] ?? 'Nothing was changed.', $e['status'] === 'assign' || $e['status'] === 'unchanged' ? 'success' : 'warning');
    redirect();
}
