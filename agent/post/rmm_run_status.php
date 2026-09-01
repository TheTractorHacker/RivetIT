<?php
if (defined('FROM_POST_HANDLER')) return;

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

enforceUserPermission('module_rmm_scripts');

$run_id = intval($_POST['run_id'] ?? 0);
if (!$run_id) {
    echo json_encode(['success' => false, 'error' => 'Missing run_id']);
    exit;
}

$run = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT sr.*, a.asset_client_id, arl.tactical_agent_id, arl.integration_id
     FROM rmm_script_runs sr
     JOIN assets a ON a.asset_id = sr.asset_id
     LEFT JOIN asset_rmm_links arl ON arl.asset_id = sr.asset_id
     WHERE sr.id=$run_id LIMIT 1"
));
if (!$run) {
    echo json_encode(['success' => false, 'error' => 'Run not found']);
    exit;
}
if ($run['asset_client_id']) { enforceClientAccess(intval($run['asset_client_id'])); }

// If the run is still in flight, poll the RMM vendor for the real result and
// persist it so the run doesn't stay "running" forever.
if (in_array($run['status'], ['pending', 'running'], true)
    && !empty($run['tactical_agent_id']) && !empty($run['integration_id'])) {
    try {
        $client = getRmmClient(intval($run['integration_id']));
        if (method_exists($client, 'getScriptRunResult')) {
            $res = $client->getScriptRunResult(
                (string) $run['tactical_agent_id'],
                (string) ($run['tactical_job_id'] ?? '')
            );
            if (!empty($res['found'])) {
                $stdout  = (string) ($res['stdout'] ?? '');
                $stderr  = (string) ($res['stderr'] ?? '');
                $retcode = $res['retcode'] ?? null;
                $status  = (is_null($retcode) || intval($retcode) === 0) ? 'completed' : 'failed';

                $out_esc = mysqli_real_escape_string($mysqli, $stdout);
                if ($status === 'failed' && $stderr !== '') {
                    $err_esc = mysqli_real_escape_string($mysqli, $stderr);
                    mysqli_query($mysqli, "UPDATE rmm_script_runs SET status='failed', output='$out_esc', error_message='$err_esc', finished_at=NOW() WHERE id=$run_id");
                    $run['error_message'] = $stderr;
                } else {
                    mysqli_query($mysqli, "UPDATE rmm_script_runs SET status='$status', output='$out_esc', finished_at=NOW() WHERE id=$run_id");
                }
                $run['status'] = $status;
                $run['output'] = $stdout;
            }
        }
    } catch (RuntimeException $e) {
        // Transient vendor/API error — leave the run as-is so polling retries.
    }
}

echo json_encode([
    'success' => true,
    'status'  => $run['status'],
    'output'  => $run['output'],
    'error'   => $run['error_message'],
]);
