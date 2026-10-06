<?php

namespace ITFlow\Workflow\Actions;

use ITFlow\Workflow\ActionGateway;

/** Opens a ticket in the person's department (client), through the same code every other automatic ticket uses. */
class CreateTicketAction extends AbstractAction
{
    public function type(): string
    {
        return 'create_ticket';
    }

    public function label(): string
    {
        return 'Create a ticket';
    }

    public function validate(array $config): array
    {
        $priority = in_array($config['priority'] ?? 'Low', ['Low', 'Medium', 'High'], true) ? $config['priority'] : 'Low';

        return [
            'subject' => $this->text($config, 'subject', 500, true, 'ticket subject'),
            'details' => $this->text($config, 'details', 5000, false, 'ticket details'),
            'priority' => $priority,
        ];
    }

    public function describe(array $config, array $ctx): string
    {
        return 'Create a ' . ($config['priority'] ?? 'Low') . ' priority ticket "' . Placeholders::render((string) $config['subject'], $ctx['vars']) . '" for ' . ($ctx['vars']['employee_name'] ?? 'the employee');
    }

    public function execute(array $config, array $ctx, ActionGateway $gateway): string
    {
        $clientId = (int) ($ctx['client_id'] ?? 0);
        if ($clientId <= 0) {
            throw new \RuntimeException('the person is not in a department, so there is nowhere to open the ticket');
        }
        $id = $gateway->createTicket(
            Placeholders::render((string) $config['subject'], $ctx['vars']),
            nl2br(Placeholders::render((string) ($config['details'] ?? ''), $ctx['vars'], 'html')),
            (string) ($config['priority'] ?? 'Low'),
            $clientId,
            'Workflow: ' . ($ctx['vars']['template_name'] ?? '')
        );
        if ($id <= 0) {
            throw new \RuntimeException('the ticket was not created');
        }

        return "created ticket #$id";
    }
}
