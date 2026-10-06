<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;
use ITFlow\Workflow\StartWorkflowRule;

/** Event-rule action kept from before the engine; see BuiltinAction. */
final class StartWorkflowAction extends BuiltinAction
{
    public function key(): string
    {
        return StartWorkflowRule::ACTION;
    }

    public function label(): string
    {
        return StartWorkflowRule::LABEL;
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        return StartWorkflowRule::validateConfig($mysqli, ['template_id' => (int) ($input['template_id'] ?? 0)], $triggerEvent);
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if ($dry) {
            $id = (int) ($config['template_id'] ?? 0);
            $t = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT name FROM workflow_templates WHERE workflow_template_id = $id AND archived_at IS NULL AND is_active = 1"));
            if (!$t) {
                throw new \RuntimeException('the workflow template is gone or inactive');
            }

            return 'WOULD start the workflow "' . $t['name'] . '" for the person the event is about (once per person while one is open)';
        }

        return StartWorkflowRule::run($mysqli, $config, $context);
    }
}
