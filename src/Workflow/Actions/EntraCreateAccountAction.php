<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/**
 * Onboarding: creates the person's Entra account (disabled unless the task says enabled) with a random temporary password and
 * "must change at first sign-in". The password is shown once, only to the technician the task is assigned to (or whoever started
 * the workflow when it has no assignee); it is never mailed, logged or put in the audit trail. An existing UPN is left alone.
 */
class EntraCreateAccountAction extends AbstractEntraAction
{
    public function type(): string
    {
        return 'entra_create_account';
    }

    public function label(): string
    {
        return 'Entra: create the employee\'s account (temporary password shown to the technician)';
    }

    public function templateType(): string
    {
        return 'onboarding';
    }

    public function validate(array $config): array
    {
        $upn = $this->text($config, 'upn', 200, false, 'user principal name') ?: '{{employee_email}}';
        $name = $this->text($config, 'display_name', 256, false, 'display name') ?: '{{employee_name}}';

        return ['upn' => $upn, 'display_name' => $name, 'enabled' => empty($config['enabled']) ? 0 : 1, 'groups' => implode("\n", $this->groupsFrom($config, false))];
    }

    public function describe(array $config, array $ctx): string
    {
        $vars = $ctx['vars'] ?? [];
        $upn = Placeholders::render((string) ($config['upn'] ?? '{{employee_email}}'), $vars);
        $name = Placeholders::render((string) ($config['display_name'] ?? '{{employee_name}}'), $vars);
        $groups = $this->groupsFrom($config, false);

        return "Would send to Microsoft Graph: create user $upn (display name \"$name\", " . (!empty($config['enabled']) ? 'enabled' : 'disabled') . ', random temporary password, must change it at first sign-in)'
            . ($groups ? ' and add it to ' . count($groups) . ' group(s)' : '') . '. Skipped if that UPN already exists. The password is shown once to the assigned technician only. Needs "Allow RivetIT to change Entra accounts".';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $dir = $this->directory($gateway);
        $vars = $ctx['vars'] ?? [];
        $name = $this->cleanName(Placeholders::render((string) ($config['display_name'] ?? '{{employee_name}}'), $vars));
        if ($name === '') {
            throw new \RuntimeException('the person has no name to use as the Entra display name');
        }

        return $dir->entraCreateAccount([
            'upn' => $this->upnFrom($config, $ctx),
            'display_name' => $name,
            'enabled' => !empty($config['enabled']),
            'groups' => $this->groupsFrom($config, false),
        ], (int) ($ctx['run_task_id'] ?? 0));
    }
}
