<?php

/*
 * Training JSON routes of the publish lane: readiness, publishing, versions and drift (spec §6.2).
 * Publishing is level 3; everything an author needs while editing is level 2; the version list
 * is level 1 (readers preview published versions).
 */

use ITFlow\Training\Api\PublishActions;

return [
    'publish_check' => ['handler' => PublishActions::class . '::publishCheck', 'method' => 'GET',  'level' => 2],
    'publish'       => ['handler' => PublishActions::class . '::publish',      'method' => 'POST', 'level' => 3],
    'revision_list' => ['handler' => PublishActions::class . '::revisionList', 'method' => 'GET',  'level' => 1],
    'revision_diff' => ['handler' => PublishActions::class . '::revisionDiff', 'method' => 'GET',  'level' => 2],
    'course_drift'  => ['handler' => PublishActions::class . '::courseDrift',  'method' => 'GET',  'level' => 2],
];
