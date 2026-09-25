<?php

/*
 * Training JSON routes owned by the achievements lane (P3 spec §4.3, K6). Every route is level 1
 * on the Router; award_manual checks module_training >= 2 itself and scopes the contact.
 */

use ITFlow\Training\Api\AwardActions;

return [
    'award_list'   => ['handler' => AwardActions::class . '::awardList',   'method' => 'GET',  'level' => 1],
    'award_manual' => ['handler' => AwardActions::class . '::awardManual', 'method' => 'POST', 'level' => 1],
];
