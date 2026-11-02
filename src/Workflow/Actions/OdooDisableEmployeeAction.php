<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Archives the employee in Odoo (active = false). Never deletes. Off unless "Allow lifecycle workflows to change Odoo" is on. */
class OdooDisableEmployeeAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'odoo_disable_employee';
    }

    public function label(): string
    {
        return 'Odoo: archive the employee';
    }

    public function templateTypes(): array
    {
        return ['offboarding'];
    }

    public function validate(array $config): array
    {
        return [];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Would archive (never delete) the hr.employee with work email ' . ($ctx['vars']['employee_email'] ?? '') . ' in Odoo. Needs "Allow lifecycle workflows to change Odoo".';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $g = $this->lifecycle($gateway);
        if (!$g->odooActionsAllowed()) {
            throw new \RuntimeException("'Allow lifecycle workflows to change Odoo' is off (Administration > Employee Workflow Templates); nothing was sent to Odoo. Do this step by hand, or turn the setting on and run it again.");
        }
        $email = trim((string) ($ctx['vars']['employee_email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('the person has no usable email address for Odoo');
        }

        return $g->odooDisableEmployee($email, (int) ($ctx['run_task_id'] ?? 0));
    }
}
