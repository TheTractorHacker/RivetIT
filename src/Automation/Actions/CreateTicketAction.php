<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Event-rule action kept from before the engine; see BuiltinAction. */
final class CreateTicketAction extends BuiltinAction
{
    public function key(): string
    {
        return 'create_ticket';
    }

    public function label(): string
    {
        return 'Create a ticket';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $priority = in_array($input['priority'] ?? '', self::PRIORITIES, true) ? $input['priority'] : 'Low';

        return ['subject' => $this->text($input, 'subject', 500, true, 'subject for the ticket that will be created'), 'details' => $this->text($input, 'details', 5000, false, ''), 'priority' => $priority, 'client_id' => max(0, (int) ($input['client_id'] ?? 0))];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if ($dry) {
            $c = $this->filled($config, $context);

            return 'WOULD create a ' . $c['priority'] . ' priority ticket "' . mb_substr((string) $c['subject'], 0, 200) . '"' . ((int) ($c['client_id'] ?? 0) ? ' for department #' . (int) $c['client_id'] : '');
        }

        return $this->viaHandler($mysqli, $config, $context, $rule);
    }
}
