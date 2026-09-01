<?php
if (defined('FROM_POST_HANDLER')) return;
/*
 * Trigger script execution via Tactical RMM API
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

enforceUserPermission('module_rmm_scripts', 2);

$script_id = intval($_POST['script_id'] ?? 0);
$link_id   = intval($_POST['link_id'] ?? 0);

if (!$script_id || !$link_id) {
    echo json_encode(['success' => false, 'error' => 'Missing script_id or link_id']);
    exit;
}

$script = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM rmm_scripts WHERE id=$script_id AND enabled=1"));
if (!$script) {
    echo json_encode(['success' => false, 'error' => 'Script not found or disabled']);
    exit;
}

$link = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT arl.*, a.asset_client_id FROM asset_rmm_links arl
     JOIN assets a ON a.asset_id = arl.asset_id
     WHERE arl.id=$link_id"
));
if (!$link) {
    echo json_encode(['success' => false, 'error' => 'Asset RMM link not found']);
    exit;
}

if ($link['asset_client_id']) { enforceClientAccess(intval($link['asset_client_id'])); }

// Create run record in pending state
$asset_id = intval($link['asset_id']);
mysqli_query($mysqli, "INSERT INTO rmm_script_runs SET
    script_id=$script_id,
    asset_id=$asset_id,
    user_id=$session_user_id,
    status='pending'
");
$run_id = intval(mysqli_insert_id($mysqli));

logAction('RMM', 'Script Run',
    "$session_name ran script {$script['name']} on asset ID $asset_id",
    intval($link['asset_client_id']),
    $asset_id
);

// If script has a Tactical ID, fire it via API
if (!empty($script['tactical_script_id'])) {
    try {
        $client = getRmmClient(intval($link['integration_id']));
        mysqli_query($mysqli, "UPDATE rmm_script_runs SET status='running' WHERE id=$run_id");
        $result = $client->runScript($link['tactical_agent_id'], intval($script['tactical_script_id']));
        $job_id = mysqli_real_escape_string($mysqli, $result['job_id'] ?? $result['id'] ?? '');
        mysqli_query($mysqli, "UPDATE rmm_script_runs SET tactical_job_id='$job_id' WHERE id=$run_id");
        echo json_encode(['success' => true, 'run_id' => $run_id, 'job_id' => $job_id]);
    } catch (RuntimeException $e) {
        $err = mysqli_real_escape_string($mysqli, $e->getMessage());
        mysqli_query($mysqli, "UPDATE rmm_script_runs SET status='failed', error_message='$err', finished_at=NOW() WHERE id=$run_id");
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    // Script body only — mark as pending (future: direct exec not supported)
    mysqli_query($mysqli, "UPDATE rmm_script_runs SET status='pending',
        error_message='Script has no Tactical RMM ID — sync scripts first' WHERE id=$run_id");
    echo json_encode(['success' => true, 'run_id' => $run_id, 'warning' => 'Script queued but has no Tactical ID. Sync scripts from Tactical to enable execution.']);
}
