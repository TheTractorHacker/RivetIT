<?php
/*
 * Deleting an RMM check policy must also take the checks it pushed off the agents (issue #29 leftover).
 *
 * rivetRmmRemovePolicyChecks() walks the policy's rmm_check_deployments rows, asks the RMM to delete each pushed check, and removes the
 * deployment row only when the RMM confirmed (or there was never a remote check to remove). A deployment the RMM refused or could not be
 * reached for is kept with status 'remove_failed' so nothing is silently orphaned; the caller then keeps the policy and tells the user
 * (they can retry, or delete anyway, which logs the left-over check ids in the audit log).
 *
 * $clientFor is a callable (int $integration_id): object with deleteCheck(int): bool - getRmmClient() in production, a stub in tests.
 * Returns ['removed' => int, 'failed' => [['hostname' => string, 'check_id' => string, 'error' => string], ...]].
 */
function rivetRmmRemovePolicyChecks(mysqli $db, int $policy_id, callable $clientFor): array
{
    $removed = 0;
    $failed = [];
    $clients = [];

    $res = mysqli_query($db, "SELECT d.id, d.tactical_check_id, l.integration_id, l.hostname, i.type AS integration_type
        FROM rmm_check_deployments d
        LEFT JOIN asset_rmm_links l ON l.id = d.link_id
        LEFT JOIN rmm_integrations i ON i.id = l.integration_id
        WHERE d.policy_id = $policy_id ORDER BY d.id");
    $rows = [];
    while ($res && ($r = mysqli_fetch_assoc($res))) { $rows[] = $r; }

    foreach ($rows as $r) {
        $dep_id = (int) $r['id'];
        $check_id = trim((string) ($r['tactical_check_id'] ?? ''));
        $host = (string) ($r['hostname'] ?? 'unknown device');

        // No remote check was recorded (the push found none), so there is nothing to remove from the agent.
        if ($check_id === '') {
            mysqli_query($db, "DELETE FROM rmm_check_deployments WHERE id = $dep_id");
            $removed++;
            continue;
        }
        $integration_id = (int) ($r['integration_id'] ?? 0);
        if (!$integration_id || ($r['integration_type'] ?? '') !== 'tactical_rmm') {
            // The device link or integration is gone, so the check cannot be reached any more.
            $failed[] = ['hostname' => $host, 'check_id' => $check_id, 'error' => 'RMM integration or device link no longer exists'];
            mysqli_query($db, "UPDATE rmm_check_deployments SET status = 'remove_failed' WHERE id = $dep_id");
            continue;
        }
        try {
            if (!isset($clients[$integration_id])) { $clients[$integration_id] = $clientFor($integration_id); }
            $ok = (bool) $clients[$integration_id]->deleteCheck((int) $check_id);
            $err = 'the RMM did not confirm the removal';
        } catch (Throwable $e) {
            $ok = false;
            $err = $e->getMessage();
        }
        if ($ok) {
            mysqli_query($db, "DELETE FROM rmm_check_deployments WHERE id = $dep_id");
            $removed++;
        } else {
            $failed[] = ['hostname' => $host, 'check_id' => $check_id, 'error' => $err];
            mysqli_query($db, "UPDATE rmm_check_deployments SET status = 'remove_failed' WHERE id = $dep_id");
        }
    }
    return ['removed' => $removed, 'failed' => $failed];
}
