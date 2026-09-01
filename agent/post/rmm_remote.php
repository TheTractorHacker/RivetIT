<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * RMM Remote Connect handler
 * Constructs remote URL server-side, logs the session, returns redirect JSON.
 * The API key and URL are NEVER sent to the browser.
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

enforceUserPermission('module_rmm_remote_connect');

$link_id         = intval($_POST['link_id'] ?? 0);
$connection_type = sanitizeInput($_POST['type'] ?? 'tactical');

if (!$link_id) {
    echo json_encode(['success' => false, 'error' => 'Missing link_id']);
    exit;
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

// Enforce client-level access
$_GET['client_id'] = $client_id;
enforceClientAccess($client_id);

try {
    // Resolve the correct RMM client (Tactical, Level, …) via the factory so
    // remote-connect works for whichever provider the asset is linked to.
    $client = getRmmClient(intval($link['integration_id']));

    // Tactical honours an explicit 'mesh' request via a stored node id;
    // pass it through so the client can build a persistent MeshCentral URL.
    if ($connection_type === 'mesh' && !empty($link['mesh_node_id'])
        && $client instanceof TacticalRmmClient) {
        $url  = $client->buildMeshUrl($link['mesh_node_id']);
        $type = 'meshcentral';
    } else {
        $remote = $client->buildRemoteUrl($link['tactical_agent_id'], $connection_type);
        $url    = $remote['url'];
        $type   = $remote['type'];
    }

    // Log the session (redact one-time login tokens before storing)
    $ip       = mysqli_real_escape_string($mysqli, getIP());
    $ua       = mysqli_real_escape_string($mysqli, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 300));
    $url_redacted = preg_replace('/([?&]login=)[^&]+/', '$1[redacted]', $url);
    $url_log  = mysqli_real_escape_string($mysqli, $url_redacted);
    mysqli_query($mysqli,
        "INSERT INTO rmm_remote_sessions SET
         asset_id=$asset_id,
         client_id=$client_id,
         user_id=$session_user_id,
         connection_type='$type',
         connection_url='$url_log',
         source_ip='$ip',
         user_agent='$ua'"
    );

    logAction('RMM', 'Remote Connect',
        "$session_name initiated $type remote session on asset ID $asset_id",
        $client_id, $asset_id
    );

    echo json_encode(['success' => true, 'url' => $url]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
