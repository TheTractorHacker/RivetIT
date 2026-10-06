<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Disables (archives) the person's client-portal login. Nothing is deleted, and restoring the contact restores the login. */
class DisableContactLoginAction extends AbstractAction
{
    public function type(): string
    {
        return 'disable_contact_login';
    }

    public function label(): string
    {
        return "Disable the employee's portal login";
    }

    public function validate(array $config): array
    {
        return [];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Disable (archive) the portal login of ' . ($ctx['vars']['employee_name'] ?? 'the employee') . '; nothing is deleted';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        return $gateway->disablePortalLogin((int) ($ctx['contact_id'] ?? 0));
    }
}
