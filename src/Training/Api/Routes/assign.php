<?php

/*
 * Training JSON routes owned by Phase 2 Lane B, Assign (spec §4.2): requirement rules, manual
 * assignment, the assignments list and supervisor actions, reconcile and person status.
 */

use ITFlow\Training\Api\AssignActions;

return [
    'rule_list'         => ['handler' => AssignActions::class . '::ruleList',         'method' => 'GET',  'level' => 1],
    'rule_get'          => ['handler' => AssignActions::class . '::ruleGet',          'method' => 'GET',  'level' => 1],
    'rule_preview'      => ['handler' => AssignActions::class . '::rulePreview',      'method' => 'POST', 'level' => 3],
    'rule_save'         => ['handler' => AssignActions::class . '::ruleSave',         'method' => 'POST', 'level' => 3],
    'rule_archive'      => ['handler' => AssignActions::class . '::ruleArchive',      'method' => 'POST', 'level' => 3],
    'assign_manual'     => ['handler' => AssignActions::class . '::assignManual',     'method' => 'POST', 'level' => 2],
    'assignment_list'   => ['handler' => AssignActions::class . '::assignmentList',   'method' => 'GET',  'level' => 1],
    'assignment_get'    => ['handler' => AssignActions::class . '::assignmentGet',    'method' => 'GET',  'level' => 1],
    'assignment_extend' => ['handler' => AssignActions::class . '::assignmentExtend', 'method' => 'POST', 'level' => 2],
    'assignment_waive'  => ['handler' => AssignActions::class . '::assignmentWaive',  'method' => 'POST', 'level' => 2],
    'reconcile_now'     => ['handler' => AssignActions::class . '::reconcileNow',     'method' => 'POST', 'level' => 2],
    'person_status'     => ['handler' => AssignActions::class . '::personStatus',     'method' => 'GET',  'level' => 1],
];
