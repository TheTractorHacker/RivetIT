<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/**
 * Emits an event on the event bus. Webhooks that subscribed to the event (Administration > Webhooks, with their own URL, signing
 * secret and SSRF checks) get a queued, signed, retried delivery; event rules can match it too. A task never carries a URL itself.
 */
class SendWebhookAction extends AbstractAction
{
    public const DEFAULT_EVENT = 'workflow.task_webhook';

    public function type(): string
    {
        return 'send_webhook';
    }

    public function label(): string
    {
        return 'Send a webhook event';
    }

    public function validate(array $config): array
    {
        $event = trim((string) ($config['event'] ?? '')) ?: self::DEFAULT_EVENT;
        if (!preg_match('/^workflow\.[a-z0-9_.]{1,100}$/', $event)) {
            throw new \InvalidArgumentException('The event name must start with "workflow." and use lower-case letters, digits, dots and underscores.');
        }

        return ['event' => $event];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Emit the event ' . ($config['event'] ?? self::DEFAULT_EVENT) . ' for ' . ($ctx['vars']['employee_name'] ?? 'the employee') . ' (webhooks subscribed to it get a queued, signed delivery)';
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $event = (string) ($config['event'] ?? self::DEFAULT_EVENT);
        $gateway->emitEvent($event, [
            'run_id' => (int) ($ctx['run_id'] ?? 0),
            'run_task_id' => (int) ($ctx['run_task_id'] ?? 0),
            'task_title' => $ctx['vars']['task_title'] ?? '',
            'template_name' => $ctx['vars']['template_name'] ?? '',
            'contact_id' => (int) ($ctx['contact_id'] ?? 0),
            'client_id' => (int) ($ctx['client_id'] ?? 0),
            'employee_name' => $ctx['vars']['employee_name'] ?? '',
            'employee_email' => $ctx['vars']['employee_email'] ?? '',
        ]);

        return "event $event emitted";
    }
}
