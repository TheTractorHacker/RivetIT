<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Offboarding: turns on the leaver's mailbox automatic reply so senders are told who to contact. Needs MailboxSettings.ReadWrite. */
class EntraSetAutoReplyAction extends AbstractEntraAction
{
    public function type(): string
    {
        return 'entra_set_auto_reply';
    }

    public function label(): string
    {
        return 'Entra: set the mailbox automatic reply';
    }

    public function templateType(): string
    {
        return 'offboarding';
    }

    public function validate(array $config): array
    {
        $msg = $this->text($config, 'message', 1000, true, 'automatic reply message');

        return ['upn' => $this->text($config, 'upn', 200, false, 'user principal name') ?: '{{employee_email}}', 'message' => $msg];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Would send to Microsoft Graph: turn on the automatic reply for ' . Placeholders::render((string) ($config['upn'] ?? '{{employee_email}}'), $ctx['vars'] ?? []) . ('. Needs "Allow ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' to change Entra accounts".');
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $dir = $this->directory($gateway);
        $msg = Placeholders::render((string) ($config['message'] ?? ''), $ctx['vars'] ?? [], 'html');

        return $dir->entraSetAutoReply($this->upnFrom($config, $ctx), $msg, (int) ($ctx['run_task_id'] ?? 0), (string) ($ctx['vars']['department'] ?? ''));
    }
}
