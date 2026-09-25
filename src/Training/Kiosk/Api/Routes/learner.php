<?php

/**
 * Kiosk learner routes (P3 spec §4.2, lane K3). Shape: ['handler','method','auth'=>[…]] - see KioskRouter.
 * `video` = the restricted token of kiosk/lesson_video.php (POST lesson_* / video_* only, its own run and lesson).
 */

use ITFlow\Training\Kiosk\Api\LearnerActions;

return [
    'run_start'       => ['handler' => LearnerActions::class . '::runStart', 'method' => 'POST', 'auth' => ['learner']],
    'lesson_open'     => ['handler' => LearnerActions::class . '::lessonOpen', 'method' => 'POST', 'auth' => ['learner', 'video']],
    'lesson_tick'     => ['handler' => LearnerActions::class . '::lessonTick', 'method' => 'POST', 'auth' => ['learner', 'video']],
    'lesson_complete' => ['handler' => LearnerActions::class . '::lessonComplete', 'method' => 'POST', 'auth' => ['learner', 'video']],
    'video_duration'  => ['handler' => LearnerActions::class . '::videoDuration', 'method' => 'POST', 'auth' => ['learner', 'video']],
    'lesson_error'    => ['handler' => LearnerActions::class . '::lessonError', 'method' => 'POST', 'auth' => ['learner', 'video']],
    'ack_sign'        => ['handler' => LearnerActions::class . '::ackSign', 'method' => 'POST', 'auth' => ['learner']],
    'exam_start'      => ['handler' => LearnerActions::class . '::examStart', 'method' => 'POST', 'auth' => ['learner']],
    'answer_save'     => ['handler' => LearnerActions::class . '::answerSave', 'method' => 'POST', 'auth' => ['learner']],
    'exam_submit'     => ['handler' => LearnerActions::class . '::examSubmit', 'method' => 'POST', 'auth' => ['learner']],
    'attest'          => ['handler' => LearnerActions::class . '::attest', 'method' => 'POST', 'auth' => ['learner']],
];
