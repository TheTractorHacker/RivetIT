<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * RMM live-action handler — reboot, run command, patch scan/install.
 *
 * Mirrors rmm_remote.php: CSRF check, per-asset client access enforcement, and
 * server-side routing through getRmmClient() so the vendor API key never
 * reaches the browser. Every action is written to rmm_remote_sessions (as an
 * audit row) and the application log.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/rmm_client_factory.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action  = sanitizeInput($_POST['action'] ?? '');
$link_id = intval($_POST['link_id'] ?? 0);

$valid_actions = ['reboot', 'run_command', 'patch_scan', 'patch_install'];
if (!$link_id || !in_array($action, $valid_actions, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Reboot / command are hands-on device control — gate with the remote-connect
// permission. Patch scan/install are maintenance — gate with RMM write access.
if ($action === 'reboot' || $action === 'run_command') {
    enforceUserPermission('module_rmm_remote_connect');
} else {
    enforceUserPermission('module_rmm', 2);
}

$link = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT arl.*, a.asset_client_id FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     WHERE arl.id=$link_id"
));

if (!$link) {
    echo json_encode(['success' => false, 'error' => 'RMM link not found']);
    exit;
}

$asset_id  = intval($link['asset_id']);
$client_id = intval($link['asset_client_id']);

// Enforce client-level access exactly like rmm_remote.php
$_GET['client_id'] = $client_id;
enforceClientAccess($client_id);

$agent_id = $link['tactical_agent_id'];
if (empty($agent_id)) {
    echo json_encode(['success' => false, 'error' => 'This asset has no linked RMM agent']);
    exit;
}

// Small helper: audit an action into rmm_remote_sessions + app log.
$logSession = function (string $type, string $detail) use ($mysqli, $asset_id, $client_id, $session_user_id, $session_name) {
    $ip     = mysqli_real_escape_string($mysqli, getIP());
    $ua     = mysqli_real_escape_string($mysqli, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300));
    $type_e = mysqli_real_escape_string($mysqli, substr($type, 0, 50));
    $det_e  = mysqli_real_escape_string($mysqli, substr($detail, 0, 1000));
    mysqli_query($mysqli,
        "INSERT INTO rmm_remote_sessions SET
         asset_id=$asset_id,
         client_id=" . ($client_id ?: 'NULL') . ",
         user_id=$session_user_id,
         connection_type='$type_e',
         connection_url='$det_e',
         source_ip='$ip',
         user_agent='$ua'"
    );
    logAction('RMM', 'Device Action', "$session_name: $detail on asset ID $asset_id", $client_id, $asset_id);
};

try {
    $client = getRmmClient(intval($link['integration_id']));

    if ($action === 'reboot') {
        $client->reboot($agent_id);
        $logSession('reboot', 'Reboot requested');
        echo json_encode(['success' => true, 'message' => 'Reboot requested. The device will restart shortly.']);
        exit;
    }

    if ($action === 'run_command') {
        $cmd = trim((string) ($_POST['command'] ?? ''));
        // Shell/timeout are not sanitizeInput()'d because the command itself
        // may contain characters sanitize would mangle; they're passed straight
        // to the vendor API over an authenticated server-side call.
        $shell   = in_array($_POST['shell'] ?? 'cmd', ['cmd', 'powershell'], true) ? $_POST['shell'] : 'cmd';
        $timeout = max(5, min(300, intval($_POST['timeout'] ?? 30)));
        if ($cmd === '') {
            echo json_encode(['success' => false, 'error' => 'Command is empty']);
            exit;
        }
        $result = $client->runCommand($agent_id, $cmd, $shell, $timeout);
        // Tactical returns command stdout as a plain string via ['message'].
        $output = '';
        if (isset($result['message'])) {
            $output = is_array($result['message']) ? json_encode($result['message']) : (string) $result['message'];
        } elseif (is_array($result)) {
            $output = json_encode($result);
        }
        $logSession('command', 'Ran ' . $shell . ' command: ' . substr($cmd, 0, 200));
        echo json_encode(['success' => true, 'output' => $output]);
        exit;
    }

    if ($action === 'patch_scan') {
        if (!method_exists($client, 'scanPatches')) {
            echo json_encode(['success' => false, 'error' => 'Patch scanning is not supported by this RMM integration']);
            exit;
        }
        $client->scanPatches($agent_id);
        $logSession('patch_scan', 'Patch scan queued');
        echo json_encode(['success' => true, 'message' => 'Patch scan queued. Re-check patches shortly.']);
        exit;
    }

    if ($action === 'patch_install') {
        if (!method_exists($client, 'installPatches')) {
            echo json_encode(['success' => false, 'error' => 'Patch installation is not supported by this RMM integration']);
            exit;
        }
        $client->installPatches($agent_id);
        $logSession('patch_install', 'Pending patch install queued');
        echo json_encode(['success' => true, 'message' => 'Install of pending updates queued. This may take a while and can reboot the device.']);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
