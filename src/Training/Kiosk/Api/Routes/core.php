<?php

/**
 * Kiosk core routes (P3 spec §4.2, lane K1). Shape: ['handler','method','auth'=>[…]] - see KioskRouter.
 */

use ITFlow\Training\Kiosk\Api\CoreActions;

return [
    'heartbeat'    => ['handler' => CoreActions::class . '::heartbeat', 'method' => 'POST', 'auth' => ['learner', 'trainer', 'checkin', 'handoff', 'video']],
    'end'          => ['handler' => CoreActions::class . '::end', 'method' => 'POST', 'auth' => ['device', 'learner', 'trainer', 'checkin', 'handoff', 'video']],
    'set_language' => ['handler' => CoreActions::class . '::setLanguage', 'method' => 'POST', 'auth' => ['device', 'learner', 'trainer', 'checkin', 'handoff']],
];
