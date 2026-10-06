<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** An in-app notification for one agent, or every agent when no user is chosen. */
class NotifyUserAction extends AbstractAction
{
    public function type(): string
    {
        return 'notify_user';
    }

    public function label(): string
    {
        return 'Notify an agent';
    }

    public function validate(array $config): array
    {
        return [
            'user_id' => max(0, (int) ($config['user_id'] ?? 0)),
            'message' => $this->text($config, 'message', 900, true, 'notification message'),
        ];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Notify ' . ((int) ($config['user_id'] ?? 0) > 0 ? 'user #' . (int) $config['user_id'] : 'every agent') . ': "' . Placeholders::render((string) $config['message'], $ctx['vars']) . '"';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $gateway->notifyUser((int) ($config['user_id'] ?? 0), 'Workflow', Placeholders::render((string) $config['message'], $ctx['vars']), 'workflow_run.php?run_id=' . (int) ($ctx['run_id'] ?? 0), (int) ($ctx['client_id'] ?? 0), (int) ($ctx['contact_id'] ?? 0));

        return 'notification created';
    }
}
