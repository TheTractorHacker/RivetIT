<?php

/*
 * Training JSON routes: reset and un-waive assignments (Assignments page, assignment history, transcript).
 * Reset progress and un-waive need level 2; "Reset (take again)" voids the record, so it needs level 3 like
 * completion_void. Every handler also checks the caller's People scope (404).
 */

use ITFlow\Training\Api\AssignResetActions;

return [
    'assignment_reset_preview' => ['handler' => AssignResetActions::class . '::preview', 'method' => 'GET',  'level' => 2],
    'assignment_reset'         => ['handler' => AssignResetActions::class . '::reset',   'method' => 'POST', 'level' => 2],
    'assignment_retake'        => ['handler' => AssignResetActions::class . '::retake',  'method' => 'POST', 'level' => 3],
    'assignment_unwaive'       => ['handler' => AssignResetActions::class . '::unwaive', 'method' => 'POST', 'level' => 2],
];
