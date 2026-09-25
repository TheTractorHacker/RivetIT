<?php

/**
 * Trainer-mode kiosk routes (P3 spec §4.2 K5 rows; lane K5). Shape: ['handler','method','auth'=>[…]] - see KioskRouter.
 * The hand-off role reaches ONLY the evaluate_* screens and handoff_cancel; the check-in role only its own session.
 */

use ITFlow\Training\Kiosk\Api\TrainerActions;

return [
    'trainer_courses'     => ['handler' => TrainerActions::class . '::courses', 'method' => 'GET', 'auth' => ['trainer']],
    'session_start'       => ['handler' => TrainerActions::class . '::sessionStart', 'method' => 'POST', 'auth' => ['trainer']],
    'session_get'         => ['handler' => TrainerActions::class . '::sessionGet', 'method' => 'GET', 'auth' => ['trainer', 'checkin']],
    'checkin_enter'       => ['handler' => TrainerActions::class . '::checkinEnter', 'method' => 'POST', 'auth' => ['trainer']],
    'checkin_attendee'    => ['handler' => TrainerActions::class . '::checkinAttendee', 'method' => 'POST', 'auth' => ['checkin']],
    'checkin_exit'        => ['handler' => TrainerActions::class . '::checkinExit', 'method' => 'POST', 'auth' => ['checkin']],
    'session_finalize'    => ['handler' => TrainerActions::class . '::sessionFinalize', 'method' => 'POST', 'auth' => ['trainer']],
    'attendee_attest'     => ['handler' => TrainerActions::class . '::attendeeAttest', 'method' => 'POST', 'auth' => ['trainer']],
    'attendee_mark'       => ['handler' => TrainerActions::class . '::attendeeMark', 'method' => 'POST', 'auth' => ['trainer']],
    'attendee_remove'     => ['handler' => TrainerActions::class . '::attendeeRemove', 'method' => 'POST', 'auth' => ['trainer']],
    'session_cancel'      => ['handler' => TrainerActions::class . '::sessionCancel', 'method' => 'POST', 'auth' => ['trainer']],
    'evaluate_candidates' => ['handler' => TrainerActions::class . '::evaluateCandidates', 'method' => 'GET', 'auth' => ['trainer']],
    'evaluate_handoff'    => ['handler' => TrainerActions::class . '::evaluateHandoff', 'method' => 'POST', 'auth' => ['trainer']],
    'evaluate_state'      => ['handler' => TrainerActions::class . '::evaluateState', 'method' => 'GET', 'auth' => ['handoff']],
    'evaluate_evaluatee'  => ['handler' => TrainerActions::class . '::evaluateEvaluatee', 'method' => 'POST', 'auth' => ['handoff']],
    'evaluate_submit'     => ['handler' => TrainerActions::class . '::evaluateSubmit', 'method' => 'POST', 'auth' => ['handoff']],
    'handoff_cancel'      => ['handler' => TrainerActions::class . '::handoffCancel', 'method' => 'POST', 'auth' => ['handoff']],
];
