<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\Actions\ActionInterface as WorkflowAction;
use ITFlow\Workflow\Actions\EntraAddToGroupsAction;

/** Entra: add the person's existing account to groups. See EntraRuleAction. */
final class EntraAddToGroupsRuleAction extends EntraRuleAction
{
    public function key(): string
    {
        return 'entra_add_to_groups';
    }

    public function label(): string
    {
        return 'Entra: add the person to groups';
    }

    protected function inner(): WorkflowAction
    {
        return new EntraAddToGroupsAction();
    }

    protected function innerInput(array $input): array
    {
        return ['groups' => is_array($input['groups'] ?? null) ? implode("\n", array_map('strval', $input['groups'])) : (string) ($input['groups'] ?? '')];
    }
}
