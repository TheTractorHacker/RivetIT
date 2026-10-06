<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\StartWorkflowRule;
use RivetCore\Automation\AutomationExecutor;

/**
 * The four actions event rules had before the engine existed (create a ticket, send a webhook, notify, start a workflow). Their real
 * behaviour is unchanged: they run through the same handlers in includes/event_bus.php with the same plain {field} interpolation.
 * What is new here is validation in one place and a dry run that only describes what would happen.
 */
abstract class BuiltinAction extends AbstractAction
{
    /** @return array<string,mixed> config with {field} values filled in, exactly as the Core executor does */
    protected function filled(array $config, array $context): array
    {
        return AutomationExecutor::interpolate($config, $context);
    }

    protected function viaHandler(\mysqli $mysqli, array $config, array $context, array $rule): string
    {
        require_once dirname(__DIR__, 3) . '/includes/event_bus.php';
        $handlers = \rivetAutomationActionHandlers($mysqli, (string) $rule['name']);

        return (string) ($handlers[$this->key()]($this->filled($config, $context), $context) ?? 'done');
    }
}
