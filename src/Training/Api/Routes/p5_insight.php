<?php

/*
 * Phase 5 Lane E training JSON routes (spec §4.1): item analysis (S7). Both GET, level 2 (the
 * report reveals the answer key). insight_items_csv is raw: it streams a CSV via Core\Csv::send
 * and exits. insight_revisions is L1 (compare versions), added in the same commit as its handler.
 */

use ITFlow\Training\Insight\InsightActions;

return [
    'insight_items'     => ['handler' => InsightActions::class . '::items',    'method' => 'GET', 'level' => 2],
    'insight_items_csv' => ['handler' => InsightActions::class . '::itemsCsv', 'method' => 'GET', 'level' => 2, 'raw' => true],
    'insight_revisions' => ['handler' => InsightActions::class . '::revisions', 'method' => 'GET', 'level' => 2],   // L1 compare versions
];
