<?php

namespace ITFlow\Automation;

use ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter;

/**
 * Minimal automation rules engine (master plan Section 34) for NON-ticket events - the existing
 * ticket_automation_rules engine (admin/ticket_automation.php) covers ticket triggers and is untouched.
 * Evaluation-only: it tells a caller which enabled rules fire; it does not execute them.
 *
 * Compatibility shim over RivetCore\Automation\AutomationRuleEvaluator.
 *
 * @deprecated since 26.10.26 use \RivetCore\Automation\AutomationRuleEvaluator (construct it with a \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
class AutomationRuleEvaluator
{
    private \RivetCore\Automation\AutomationRuleEvaluator $core;

    public function __construct(\mysqli $mysqli)
    {
        $this->core = new \RivetCore\Automation\AutomationRuleEvaluator(new MysqliDatabaseAdapter($mysqli));
    }

    /** @return array<int,array<string,mixed>> */
    public function findMatchingRules(string $eventType, array $eventData): array
    {
        return $this->core->findMatchingRules($eventType, $eventData);
    }

    public function conditionsMatch(?string $conditionJson, array $eventData): bool
    {
        return $this->core->conditionsMatch($conditionJson, $eventData);
    }
}
