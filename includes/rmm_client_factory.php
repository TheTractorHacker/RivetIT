<?php
/*
 * getRmmClient(integration_id) — returns the correct RMM client instance.
 *
 * Currently supports:
 *   tactical_rmm   → TacticalRmmClient
 *   level          → LevelRmmClient
 *   action1        → Action1RmmClient
 *   sophos_central → SophosCentralRmmClient (firewalls only)
 *
 * Callers require this file; they don't need to know which class to use.
 */

function getRmmClient(int $integration_id): object {
    global $mysqli;
    $id  = intval($integration_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli,
        "SELECT type FROM rmm_integrations WHERE id=$id LIMIT 1"
    ));
    if (!$row) {
        throw new RuntimeException("RMM integration $id not found");
    }
    switch ($row['type']) {
        case 'level':
            require_once __DIR__ . '/class_level_rmm.php';
            return new LevelRmmClient($id);
        case 'action1':
            require_once __DIR__ . '/class_action1_rmm.php';
            return new Action1RmmClient($id);
        case 'sophos_central':
            require_once __DIR__ . '/class_sophos_central_rmm.php';
            return new SophosCentralRmmClient($id);
        case 'tactical_rmm':
        default:
            require_once __DIR__ . '/class_tactical_rmm.php';
            return new TacticalRmmClient($id);
    }
}
