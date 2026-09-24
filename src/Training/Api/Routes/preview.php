<?php

/*
 * Training JSON routes of the preview player's quiz (spec §6.2). Starting is level 1 (a draft
 * source needs level 2, checked by the service); grading is level 2.
 */

use ITFlow\Training\Api\PreviewActions;

return [
    'preview_quiz_start'  => ['handler' => PreviewActions::class . '::start',  'method' => 'POST', 'level' => 1],
    'preview_quiz_submit' => ['handler' => PreviewActions::class . '::submit', 'method' => 'POST', 'level' => 2],
];
