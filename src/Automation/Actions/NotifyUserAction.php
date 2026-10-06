<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Event-rule action kept from before the engine; see BuiltinAction. */
final class NotifyUserAction extends BuiltinAction
{
    public function key(): string
    {
        return 'notify_user';
    }

    public function label(): string
    {
        return 'Notify a user';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        return ['message' => $this->text($input, 'message', 1000, true, 'notification message'), 'user_id' => max(0, (int) ($input['user_id'] ?? 0))];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if ($dry) {
            return 'WOULD notify all technicians: "' . mb_substr((string) $this->filled($config, $context)['message'], 0, 200) . '"';
        }

        return $this->viaHandler($mysqli, $config, $context, $rule);
    }
}
