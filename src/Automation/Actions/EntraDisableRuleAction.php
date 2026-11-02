<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\Actions\ActionInterface as WorkflowAction;
use ITFlow\Workflow\Actions\EntraDisableAccountAction;

/** Entra: disable the person's account and revoke sessions (never deletes). See EntraRuleAction. */
final class EntraDisableRuleAction extends EntraRuleAction
{
    public function key(): string
    {
        return 'entra_disable_account';
    }

    public function label(): string
    {
        return "Entra: disable the person's account";
    }

    protected function inner(): WorkflowAction
    {
        return new EntraDisableAccountAction();
    }

    protected function innerInput(array $input): array
    {
        return ['revoke_sessions' => !empty($input['revoke_sessions']) ? 1 : 0];
    }
}
