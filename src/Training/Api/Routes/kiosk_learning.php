<?php

/*
 * Training JSON routes for kiosk runs on the agent side (P3 spec §4.3, lane K3): the
 * "Locked & blocked courses" list and unlock. The Router requires module_training >= 1; the
 * handlers require level 2 and scope every run to the agent's departments.
 */

use ITFlow\Training\Api\RunAdminActions;

return [
    'run_locked_list' => ['handler' => RunAdminActions::class . '::runLockedList', 'method' => 'GET',  'level' => 1],
    'run_unlock'      => ['handler' => RunAdminActions::class . '::runUnlock',     'method' => 'POST', 'level' => 1],
];
