<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Offboarding: disables the person's Entra sign-in and revokes their sessions. Never deletes the account. */
class EntraDisableAccountAction extends AbstractEntraAction
{
    public function type(): string
    {
        return 'entra_disable_account';
    }

    public function label(): string
    {
        return "Entra: disable the employee's account and revoke sessions";
    }

    public function templateType(): string
    {
        return 'offboarding';
    }

    public function validate(array $config): array
    {
        return ['revoke_sessions' => array_key_exists('revoke_sessions', $config) ? (empty($config['revoke_sessions']) ? 0 : 1) : 1];
    }

    public function describe(array $config, array $ctx): string
    {
        $who = ($ctx['vars']['employee_email'] ?? '') !== '' ? $ctx['vars']['employee_email'] : ($ctx['vars']['employee_name'] ?? 'the employee');

        return 'Would send to Microsoft Graph: PATCH accountEnabled=false for ' . $who . (!empty($config['revoke_sessions']) || !array_key_exists('revoke_sessions', $config) ? ' and POST revokeSignInSessions' : '') . '. The account is never deleted. Needs "Allow RivetIT to change Entra accounts".';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $dir = $this->directory($gateway);

        return $dir->entraDisableAccount($this->upnFrom($config, $ctx), !array_key_exists('revoke_sessions', $config) || !empty($config['revoke_sessions']), (int) ($ctx['run_task_id'] ?? 0));
    }
}
