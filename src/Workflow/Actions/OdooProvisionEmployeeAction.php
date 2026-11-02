<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Creates the employee (hr.employee) in Odoo unless one with the same work email exists. Off unless "Allow lifecycle workflows to change Odoo" is on. */
class OdooProvisionEmployeeAction extends AbstractLifecycleAction
{
    public function type(): string
    {
        return 'odoo_provision_employee';
    }

    public function label(): string
    {
        return 'Odoo: create the employee';
    }

    public function templateTypes(): array
    {
        return ['onboarding'];
    }

    public function validate(array $config): array
    {
        return [];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Would create the hr.employee ' . ($ctx['vars']['employee_name'] ?? '') . ' <' . ($ctx['vars']['employee_email'] ?? '') . '> in Odoo unless it exists. Needs "Allow lifecycle workflows to change Odoo".';
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

        return $g->odooProvisionEmployee(['name' => (string) ($ctx['vars']['employee_name'] ?? ''), 'email' => $email, 'title' => (string) ($ctx['vars']['employee_title'] ?? '')], (int) ($ctx['run_task_id'] ?? 0));
    }
}
