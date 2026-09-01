<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * RMM Unlink handler
 * Removes the link between an ITFlow asset and an RMM agent without
 * touching the ITFlow asset itself.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/check_login.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_global_settings.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/load_user_session.php';

header('Content-Type: application/json');

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

enforceUserPermission('module_rmm_sync');

$link_id = intval($_POST['link_id'] ?? 0);

if (!$link_id) {
    echo json_encode(['success' => false, 'error' => 'Missing link_id']);
    exit;
}

$link = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT arl.*, a.asset_client_id, a.asset_name FROM asset_rmm_links arl
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

mysqli_query($mysqli, "DELETE FROM asset_rmm_links WHERE id=$link_id");

logAction('RMM', 'Asset Unlinked',
    "$session_name removed RMM link for asset \"{$link['asset_name']}\" (asset kept in ITFlow)",
    $client_id, $asset_id
);

echo json_encode(['success' => true]);
