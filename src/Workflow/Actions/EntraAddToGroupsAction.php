<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Adds the person's existing Entra account to groups (by group Object ID). Already a member is fine. */
class EntraAddToGroupsAction extends AbstractEntraAction
{
    public function type(): string
    {
        return 'entra_add_to_groups';
    }

    public function label(): string
    {
        return 'Entra: add the employee to groups';
    }

    public function templateType(): string
    {
        return 'onboarding';
    }

    public function validate(array $config): array
    {
        return ['upn' => $this->text($config, 'upn', 200, false, 'user principal name') ?: '{{employee_email}}', 'groups' => implode("\n", $this->groupsFrom($config, true))];
    }

    public function describe(array $config, array $ctx): string
    {
        $groups = $this->groupsFrom($config, false);

        return 'Would send to Microsoft Graph: add ' . Placeholders::render((string) ($config['upn'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . ' to ' . count($groups) . ' group(s) (' . implode(', ', $groups) . '). Needs "Allow RivetIT to change Entra accounts".';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $dir = $this->directory($gateway);

        return $dir->entraAddToGroups($this->upnFrom($config, $ctx), $this->groupsFrom($config, true), (int) ($ctx['run_task_id'] ?? 0));
    }
}
