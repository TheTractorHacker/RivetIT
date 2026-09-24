<?php

/*
 * Training JSON routes owned by the reports lane (Phase 2 spec §4.2, Lane D). All GET, level 1;
 * every handler applies the fail-closed People\Scope. report_csv is raw: it streams a CSV via
 * Core\Csv::send and exits.
 */

use ITFlow\Training\Api\ReportActions;

return [
    'dash_summary'       => ['handler' => ReportActions::class . '::dashSummary', 'method' => 'GET', 'level' => 1],
    'report_cell_people' => ['handler' => ReportActions::class . '::cellPeople',  'method' => 'GET', 'level' => 1],
    'report_matrix'      => ['handler' => ReportActions::class . '::matrix',      'method' => 'GET', 'level' => 1],
    'report_overdue'     => ['handler' => ReportActions::class . '::overdue',     'method' => 'GET', 'level' => 1],
    'report_expiring'    => ['handler' => ReportActions::class . '::expiring',    'method' => 'GET', 'level' => 1],
    'report_course'      => ['handler' => ReportActions::class . '::course',      'method' => 'GET', 'level' => 1],
    'report_doc_acks'    => ['handler' => ReportActions::class . '::docAcks',     'method' => 'GET', 'level' => 1],
    'report_csv'         => ['handler' => ReportActions::class . '::csv',         'method' => 'GET', 'level' => 1, 'raw' => true],
];
