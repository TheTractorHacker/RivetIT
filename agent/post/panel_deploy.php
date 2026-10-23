<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * RivetPanel deploy actions: mint/revoke an enrollment token, revoke a panel device, toggle "allow tickets from client-level
 * devices". Standalone endpoint (agent/panel_deploy.php's forms post straight here), same shape as agent/post/rmm_unlink.php.
 *
 * Every action re-derives client_id from the TOKEN/DEVICE ROW (looked up by its own id, scoped to kind = panel) before calling
 * enforceClientAccess() - a posted/URL client_id is used only to know where to redirect back to, never for the access decision.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/panel_bootstrap.php';

enforceUserPermission('module_support', 1);

$panel = rivetPanelModule($mysqli);

function panel_deploy_back(int $clientId): void
{
    redirect('/agent/panel_deploy.php?client_id=' . $clientId);
}

if (isset($_POST['create_token'])) {
    validateCSRFToken($_POST['csrf_token']);
    $client_id = intval($_POST['client_id'] ?? 0);
    enforceUserPermission('module_support', 2);
    enforceClientAccess($client_id);

    $contact_id = isset($_POST['contact_id']) && $_POST['contact_id'] !== '' ? intval($_POST['contact_id']) : null;
    $label = (string) ($_POST['label'] ?? '');
    $ttl_hours = max(1, min(72, intval($_POST['ttl_hours'] ?? 24)));

    try {
        $result = $panel->enrollment()->createToken($client_id, $contact_id, $ttl_hours, $label, (int) $session_user_id);
        $_SESSION['panel_new_token'] = $result['token'];
        flash_alert('Enrollment token created. Copy it now - it will not be shown again.', 'success');
    } catch (\InvalidArgumentException $e) {
        flash_alert($e->getMessage(), 'error');
    }
    panel_deploy_back($client_id);
}

if (isset($_POST['set_client_level'])) {
    validateCSRFToken($_POST['csrf_token']);
    $client_id = intval($_POST['client_id'] ?? 0);
    enforceUserPermission('module_support', 2);
    enforceClientAccess($client_id);

    $allow = !empty($_POST['allow_client_level']);
    $panel->settings()->setAllowClientLevelTickets($client_id, $allow, (int) $session_user_id);
    flash_alert('Updated: tickets from client-level panel devices are now ' . ($allow ? 'allowed' : 'not allowed') . '.', 'success');
    panel_deploy_back($client_id);
}

if (isset($_GET['revoke_token'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $token_id = intval($_GET['revoke_token']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM endpoint_agent_enrollment_tokens WHERE token_id = $token_id AND kind = 'panel'"));
    if (!$row) {
        flash_alert('Token not found.', 'error');
        panel_deploy_back(intval($_GET['client_id'] ?? 0));
    }
    $client_id = intval($row['client_id']);
    enforceClientAccess($client_id);

    $ok = $panel->enrollment()->revokeToken($token_id, (int) $session_user_id);
    flash_alert($ok ? 'Token revoked.' : 'Token could not be revoked (already used or revoked).', $ok ? 'success' : 'warning');
    panel_deploy_back($client_id);
}

if (isset($_GET['revoke_device'])) {
    validateCSRFToken($_GET['csrf_token']);
    enforceUserPermission('module_support', 2);

    $device_id = intval($_GET['revoke_device']);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_id FROM endpoint_agent_devices WHERE device_id = $device_id AND kind = 'panel'"));
    if (!$row) {
        flash_alert('Device not found.', 'error');
        panel_deploy_back(intval($_GET['client_id'] ?? 0));
    }
    $client_id = intval($row['client_id']);
    enforceClientAccess($client_id);

    $ok = $panel->admin()->revokeDevice($device_id, 'Revoked from RivetPanel deploy page by ' . $session_name, (int) $session_user_id);
    flash_alert($ok ? 'Device revoked.' : 'Device could not be revoked (already revoked).', $ok ? 'success' : 'warning');
    panel_deploy_back($client_id);
}

flash_alert('Unknown action.', 'error');
redirect();
