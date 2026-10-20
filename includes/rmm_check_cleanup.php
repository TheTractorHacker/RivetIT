<?php

/*
 * Per-OS check clean-up (RivetCore 1.0.0-rc.24). Two idempotent helpers shared by the DB updater (step 2.6.181) and the "Clean up checks that no longer apply" button in
 * Administration > Endpoint agent > Checks. Neither takes anything from the browser.
 */

require_once __DIR__ . '/rmm_bootstrap.php';
require_once __DIR__ . '/rmm_check_schedule.php';

/**
 * Give every stored service check that has no OS yet the OS of the Windows/Linux choice table, keeping everything else byte for byte.
 * Goes through RivetCore's settings validation, so a list it would refuse is left alone.
 *
 * @return int how many checks got an OS (0 when the schedule is the built-in list, empty or already upgraded)
 */
function rivetRmmUpgradeScheduleOs($mysqli): int
{
    $rmm = rivetRmmModule($mysqli);
    $stored = $rmm->settings()->get()['checks_json'] ?? null;
    if (!is_string($stored) || trim($stored) === '') {
        return 0;   // the built-in list is delivered; nothing stored to upgrade
    }
    $list = json_decode($stored, true);
    if (!is_array($list)) {
        return 0;
    }
    $new = rivetCsUpgradeChecks(array_values($list));
    $n = 0;
    foreach ($new as $i => $c) {
        if (($c['os'] ?? null) !== ($list[$i]['os'] ?? null)) {
            ++$n;
        }
    }
    if ($n === 0) {
        return 0;
    }
    if ($rmm->settings()->update(['checks_json' => json_encode($new)]) !== []) {
        return 0;   // RivetCore refused the list: nothing was written
    }

    return $n;
}

/**
 * Remove the check rows (and resolve their open alerts, no ticket) of every live device for checks the device is no longer given.
 *
 * @return array{devices:int,removed:int}
 */
function rivetRmmCleanupChecks($mysqli): array
{
    return rivetRmmModule($mysqli)->reconcileChecks();
}
