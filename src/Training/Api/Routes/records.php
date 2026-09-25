<?php

/*
 * Training JSON routes owned by the records lane (Phase 2 spec §4.2 "Routes/records.php",
 * Lane C). Merged by Router::routes(); a name defined twice anywhere is a load-time error.
 *
 * Evidence scans do not go through here: the multipart upload is agent/training_evidence_upload.php
 * (it returns a user-bound attach token) and the bytes are streamed by agent/training_evidence.php
 * after Records\EvidenceStore authorizes them (spec §4.3).
 */

use ITFlow\Training\Api\RecordActions;

return [
    'completion_list'   => ['handler' => RecordActions::class . '::completionList',   'method' => 'GET',  'level' => 1],
    'completion_get'    => ['handler' => RecordActions::class . '::completionGet',    'method' => 'GET',  'level' => 1],
    'completion_record' => ['handler' => RecordActions::class . '::completionRecord', 'method' => 'POST', 'level' => 2],
    'completion_void'   => ['handler' => RecordActions::class . '::completionVoid',   'method' => 'POST', 'level' => 3],
    'course_components' => ['handler' => RecordActions::class . '::courseComponents', 'method' => 'GET',  'level' => 1],
    'session_list'      => ['handler' => RecordActions::class . '::sessionList',      'method' => 'GET',  'level' => 1],
    'session_get'       => ['handler' => RecordActions::class . '::sessionGet',       'method' => 'GET',  'level' => 1],
    'session_save'      => ['handler' => RecordActions::class . '::sessionSave',      'method' => 'POST', 'level' => 2],
    'session_finalize'  => ['handler' => RecordActions::class . '::sessionFinalize',  'method' => 'POST', 'level' => 2],
    'session_cancel'    => ['handler' => RecordActions::class . '::sessionCancel',    'method' => 'POST', 'level' => 2],
    'evaluation_record' => ['handler' => RecordActions::class . '::evaluationRecord', 'method' => 'POST', 'level' => 2],
    'trainer_list'      => ['handler' => RecordActions::class . '::trainerList',      'method' => 'GET',  'level' => 1],
    'trainer_save'      => ['handler' => RecordActions::class . '::trainerSave',      'method' => 'POST', 'level' => 3],
];
