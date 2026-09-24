<?php

/*
 * Training JSON routes owned by the platform lane (spec §6.2). Each lane has its own file
 * here; Router::routes() merges them and refuses a name defined twice.
 *
 *   name => ['handler' => 'Class::staticMethod', 'method' => 'GET'|'POST', 'level' => 1..3,
 *            'kb' => true (also needs Knowledge Base access), 'raw' => true (streams its own output)]
 */

use ITFlow\Training\Api\Router;

return [
    'ping'        => ['handler' => Router::class . '::actionPing',       'method' => 'GET', 'level' => 1],
    'ledger_head' => ['handler' => Router::class . '::actionLedgerHead', 'method' => 'GET', 'level' => 3],
];
