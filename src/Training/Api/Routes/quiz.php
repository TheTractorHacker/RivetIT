<?php

/*
 * Training JSON routes of the quiz lane: Question Library, questions, import/export, quizzes and
 * draw rules (spec §6.2). Router::routes() merges this with the other lanes' files.
 */

use ITFlow\Training\Api\QuizActions;

return [
    'bank_tree'              => ['handler' => QuizActions::class . '::bankTree',             'method' => 'GET',  'level' => 2],
    'bank_create'            => ['handler' => QuizActions::class . '::bankCreate',           'method' => 'POST', 'level' => 2],
    'bank_update'            => ['handler' => QuizActions::class . '::bankUpdate',           'method' => 'POST', 'level' => 2],
    'bank_archive'           => ['handler' => QuizActions::class . '::bankArchive',          'method' => 'POST', 'level' => 2],
    'question_list'          => ['handler' => QuizActions::class . '::questionList',         'method' => 'GET',  'level' => 2],
    'question_create'        => ['handler' => QuizActions::class . '::questionCreate',       'method' => 'POST', 'level' => 2],
    'question_update'        => ['handler' => QuizActions::class . '::questionUpdate',       'method' => 'POST', 'level' => 2],
    'question_delete'        => ['handler' => QuizActions::class . '::questionDelete',       'method' => 'POST', 'level' => 2],
    'question_restore'       => ['handler' => QuizActions::class . '::questionRestore',      'method' => 'POST', 'level' => 2],
    'question_duplicate'     => ['handler' => QuizActions::class . '::questionDuplicate',    'method' => 'POST', 'level' => 2],
    'questions_reorder'      => ['handler' => QuizActions::class . '::questionsReorder',     'method' => 'POST', 'level' => 2],
    'questions_move'         => ['handler' => QuizActions::class . '::questionsMove',        'method' => 'POST', 'level' => 2],
    'question_paste_preview' => ['handler' => QuizActions::class . '::questionPastePreview', 'method' => 'POST', 'level' => 2],
    'import_commit'          => ['handler' => QuizActions::class . '::importCommit',         'method' => 'POST', 'level' => 2],
    'question_csv_template'  => ['handler' => QuizActions::class . '::questionCsvTemplate',  'method' => 'GET',  'level' => 2, 'raw' => true],
    'bank_export_csv'        => ['handler' => QuizActions::class . '::bankExportCsv',        'method' => 'GET',  'level' => 3, 'raw' => true],
    'quiz_get'               => ['handler' => QuizActions::class . '::quizGet',              'method' => 'GET',  'level' => 2],
    'quiz_attach'            => ['handler' => QuizActions::class . '::quizAttach',           'method' => 'POST', 'level' => 2],
    'quiz_detach'            => ['handler' => QuizActions::class . '::quizDetach',           'method' => 'POST', 'level' => 2],
    'quiz_update'            => ['handler' => QuizActions::class . '::quizUpdate',           'method' => 'POST', 'level' => 2],
    'quiz_rule_add'          => ['handler' => QuizActions::class . '::quizRuleAdd',          'method' => 'POST', 'level' => 2],
    'quiz_rule_update'       => ['handler' => QuizActions::class . '::quizRuleUpdate',       'method' => 'POST', 'level' => 2],
    'quiz_rule_delete'       => ['handler' => QuizActions::class . '::quizRuleDelete',       'method' => 'POST', 'level' => 2],
    'quiz_rules_reorder'     => ['handler' => QuizActions::class . '::quizRulesReorder',     'method' => 'POST', 'level' => 2],
    'quiz_pool_stats'        => ['handler' => QuizActions::class . '::quizPoolStats',        'method' => 'GET',  'level' => 2],
];
