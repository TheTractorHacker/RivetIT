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
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']); exit;
}
enforceUserPermission('module_rmm');

$action    = sanitizeInput($_POST['action'] ?? '');
$policy_id = intval($_POST['policy_id'] ?? 0);

// ---- Push policy to all matching agents in a given integration ----
if ($action === 'push_policy') {
    // Bulk cross-client deployment - the blanket module_rmm check above only
    // requires read access, raise to write level for this specific action.
    enforceUserPermission('module_rmm', 2);

    $integration_id = intval($_POST['integration_id'] ?? $config_rmm_default_integration_id);
    if (!$policy_id || !$integration_id) {
        echo json_encode(['success' => false, 'error' => 'Missing parameters']); exit;
    }

    $intg = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT type FROM rmm_integrations WHERE id=$integration_id AND enabled=1"
    ));
    if (!$intg || $intg['type'] !== 'tactical_rmm') {
        echo json_encode(['success' => false, 'error' => 'Check push only supported for Tactical RMM integrations']); exit;
    }

    $policy = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT * FROM rmm_check_policies WHERE id=$policy_id AND enabled=1"
    ));
    if (!$policy) {
        echo json_encode(['success' => false, 'error' => 'Policy not found']); exit;
    }

    $platform = $policy['platform'];

    // Build platform filter for os_name
    $os_filter = '';
    if ($platform === 'windows') {
        $os_filter = " AND LOWER(arl.os_name) LIKE '%windows%'";
    } elseif ($platform === 'linux') {
        $os_filter = " AND LOWER(arl.os_name) LIKE '%linux%'";
    } elseif ($platform === 'macos') {
        $os_filter = " AND (LOWER(arl.os_name) LIKE '%mac%' OR LOWER(arl.os_name) LIKE '%darwin%')";
    }

    // Get agents for this integration matching platform
    $sql_agents = mysqli_query($mysqli,
        "SELECT arl.id as link_id, arl.tactical_agent_id, arl.hostname, arl.os_name
         FROM asset_rmm_links arl
         WHERE arl.integration_id=$integration_id
           AND arl.tactical_agent_id IS NOT NULL
           AND arl.tactical_agent_id != ''
           $os_filter"
    );

    $pushed  = 0;
    $skipped = 0;
    $errors  = [];
    $params  = json_decode($policy['check_params'] ?? '{}', true) ?? [];

    try {
        $client = getRmmClient($integration_id);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]); exit;
    }

    while ($ag = mysqli_fetch_assoc($sql_agents)) {
        $link_id  = intval($ag['link_id']);
        $agent_id = $ag['tactical_agent_id'];

        // Skip if already deployed
        $exists = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT id FROM rmm_check_deployments WHERE policy_id=$policy_id AND link_id=$link_id"
        ));
        if ($exists) { $skipped++; continue; }

        // Build Tactical check payload
        $payload = buildTacticalCheckPayload($policy, $params);

        try {
            $result   = $client->createCheck($agent_id, $payload);
            $check_id = $result['id'] ?? $result['check_id'] ?? null;
        } catch (RuntimeException $e) {
            if (stripos($e->getMessage(), 'already exists') !== false) {
                // Check already exists on the agent (e.g. created manually) -- record it as deployed.
                $existing = $client->findCheck($agent_id, $payload);
                $check_id = $existing['id'] ?? null;
            } else {
                $errors[] = $ag['hostname'] . ': ' . $e->getMessage();
                continue;
            }
        }

        $cid_val = $check_id ? "'$check_id'" : 'NULL';
        mysqli_query($mysqli,
            "INSERT INTO rmm_check_deployments SET
             policy_id=$policy_id, link_id=$link_id,
             tactical_check_id=$cid_val, status='active'"
        );
        $pushed++;
    }

    logAction('RMM', 'Check Push',
        "$session_name pushed check policy '{$policy['name']}' — $pushed pushed, $skipped already deployed"
    );
    echo json_encode([
        'success' => true,
        'pushed'  => $pushed,
        'skipped' => $skipped,
        'errors'  => $errors,
    ]);
    exit;
}

// ---- Remove policy from all agents ----
if ($action === 'remove_policy') {
    $integration_id = intval($_POST['integration_id'] ?? $config_rmm_default_integration_id);
    if (!$policy_id) { echo json_encode(['success' => false, 'error' => 'Missing policy_id']); exit; }

    $intg = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT type FROM rmm_integrations WHERE id=$integration_id AND enabled=1"
    ));
    if ($intg && $intg['type'] === 'tactical_rmm') {
        $client = getRmmClient($integration_id);
        $deploys = mysqli_query($mysqli,
            "SELECT d.id, d.tactical_check_id FROM rmm_check_deployments d WHERE d.policy_id=$policy_id"
        );
        $removed = 0;
        while ($dep = mysqli_fetch_assoc($deploys)) {
            if ($dep['tactical_check_id']) {
                $client->deleteCheck(intval($dep['tactical_check_id']));
            }
            mysqli_query($mysqli, "DELETE FROM rmm_check_deployments WHERE id={$dep['id']}");
            $removed++;
        }
    } else {
        mysqli_query($mysqli, "DELETE FROM rmm_check_deployments WHERE policy_id=$policy_id");
        $removed = 0;
    }

    logAction('RMM', 'Check Remove', "$session_name removed check policy deployments");
    echo json_encode(['success' => true, 'removed' => $removed ?? 0]);
    exit;
}

// ---- Save policy (add/edit) ----
if ($action === 'save_policy') {
    $name        = sanitizeInput($_POST['name'] ?? '');
    $platform    = sanitizeInput($_POST['platform'] ?? 'any');
    $check_type  = sanitizeInput($_POST['check_type'] ?? '');
    $warn        = intval($_POST['warning_threshold'] ?? 0) ?: 'NULL';
    $crit        = intval($_POST['critical_threshold'] ?? 0) ?: 'NULL';
    $interval    = intval($_POST['check_interval'] ?? 120);
    $description = sanitizeInput($_POST['description'] ?? '');
    $params      = sanitizeInput($_POST['check_params'] ?? '{}');
    $enabled     = isset($_POST['enabled']) ? 1 : 0;
    $edit_id     = intval($_POST['edit_id'] ?? 0);

    if (!$name || !$check_type) {
        echo json_encode(['success' => false, 'error' => 'Name and check type required']); exit;
    }

    $name_e = mysqli_real_escape_string($mysqli, $name);
    $plat_e = mysqli_real_escape_string($mysqli, $platform);
    $type_e = mysqli_real_escape_string($mysqli, $check_type);
    $desc_e = mysqli_real_escape_string($mysqli, $description);
    $par_e  = mysqli_real_escape_string($mysqli, $params);
    $warn_v = is_numeric($warn) ? intval($warn) : 'NULL';
    $crit_v = is_numeric($crit) ? intval($crit) : 'NULL';

    if ($edit_id > 0) {
        mysqli_query($mysqli,
            "UPDATE rmm_check_policies SET name='$name_e', platform='$plat_e', check_type='$type_e',
             warning_threshold=$warn_v, critical_threshold=$crit_v, check_interval=$interval,
             check_params='$par_e', description='$desc_e', enabled=$enabled
             WHERE id=$edit_id"
        );
        echo json_encode(['success' => true, 'msg' => 'Policy updated']);
    } else {
        mysqli_query($mysqli,
            "INSERT INTO rmm_check_policies SET name='$name_e', platform='$plat_e', check_type='$type_e',
             warning_threshold=$warn_v, critical_threshold=$crit_v, check_interval=$interval,
             check_params='$par_e', description='$desc_e', enabled=$enabled, created_by=$session_user_id"
        );
        echo json_encode(['success' => true, 'id' => intval(mysqli_insert_id($mysqli))]);
    }
    exit;
}

// ---- Delete policy ----
if ($action === 'delete_policy') {
    if (!$policy_id) { echo json_encode(['success' => false, 'error' => 'Missing policy_id']); exit; }
    mysqli_query($mysqli, "DELETE FROM rmm_check_deployments WHERE policy_id=$policy_id");
    mysqli_query($mysqli, "DELETE FROM rmm_check_policies WHERE id=$policy_id");
    logAction('RMM', 'Check Delete', "$session_name deleted check policy ID $policy_id");
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Unknown action']);

// -----------------------------------------------------------------------
// Build Tactical RMM check payload from policy
// -----------------------------------------------------------------------
function buildTacticalCheckPayload(array $policy, array $params): array {
    $base = [
        'check_type'      => $policy['check_type'],
        'run_interval'    => intval($policy['check_interval']),
        'email_alert'     => false,
        'text_alert'      => false,
        'dashboard_alert' => true,
        'fails_b4_alert'  => 3,
    ];

    switch ($policy['check_type']) {
        case 'diskspace':
            $disk = strtoupper(rtrim($params['disk'] ?? 'C', ':'));
            $base['disk'] = $disk . ':'; // model field max_length=2, e.g. "C:"
            // Tactical's diskspace thresholds are "% free space remaining" (check fails
            // when free% < threshold), but ITFlow policies store "% used" -- invert.
            $base['error_threshold']   = 100 - intval($policy['critical_threshold'] ?? 90);
            $base['warning_threshold'] = 100 - intval($policy['warning_threshold']  ?? 80);
            break;

        case 'cpuload':
            $base['error_threshold']   = intval($policy['critical_threshold'] ?? 95);
            $base['warning_threshold'] = intval($policy['warning_threshold']  ?? 80);
            break;

        case 'memory':
            $base['error_threshold']   = intval($policy['critical_threshold'] ?? 95);
            $base['warning_threshold'] = intval($policy['warning_threshold']  ?? 85);
            break;

        case 'ping':
            $base['ip']             = $params['ip'] ?? '8.8.8.8';
            $base['fails_b4_alert'] = intval($params['failures'] ?? 5);
            break;

        case 'winsvc':
            $base['svc_name']              = $params['svc_name'] ?? '';
            $base['svc_display_name']      = $params['svc_name'] ?? '';
            $base['pass_if_start_pending'] = false;
            $base['restart_if_stopped']    = !empty($params['restart_if_stopped']);
            break;

        case 'eventlog':
            $base['log_name']                  = $params['log_name'] ?? 'Application';
            $base['event_id']                  = intval($params['event_id'] ?? 0);
            $base['event_id_is_wildcard']      = empty($params['event_id']);
            $base['event_type']                = $params['event_type'] ?? 'ERROR';
            $base['fail_when']                 = 'contains';
            $base['number_of_events_b4_alert'] = intval($params['fail_count'] ?? 1);
            $base['search_last_days']          = intval($params['search_last_days'] ?? 1);
            break;
    }
    return $base;
}
