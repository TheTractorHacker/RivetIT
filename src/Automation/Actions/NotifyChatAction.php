<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Automation\ChatNotifierRegistry;
use ITFlow\Automation\Template;
use ITFlow\Workflow\ActionGateway;

/**
 * Sends a chat message (Slack / Teams) through the registered ChatNotifier (see src/Automation/ChatNotifier.php for the integration point).
 * The destination is fixed when the rule is saved; the message is templated with {field} placeholders (control characters flattened).
 * UNVERIFIED LIVE: no real sender ships with this action; the tests prove the path with a recording notifier.
 */
final class NotifyChatAction extends AbstractAction
{
    public function key(): string
    {
        return 'notify_chat';
    }

    public function label(): string
    {
        return 'Send a chat message';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        $dest = strtolower(trim((string) ($input['destination'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,59}$/', $dest)) {
            throw new \InvalidArgumentException('Enter the chat destination (letters, numbers, dot, dash or underscore, up to 60 characters).');
        }
        $known = ChatNotifierRegistry::get()->destinations();
        if ($known && !isset($known[$dest])) {
            throw new \InvalidArgumentException('That chat destination is not configured. Choose one of: ' . implode(', ', array_keys($known)) . '.');
        }

        return ['destination' => $dest, 'message' => $this->text($input, 'message', 1000, true, 'chat message')];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        $message = mb_substr(Template::render((string) ($config['message'] ?? ''), $context, 'text'), 0, 1000);
        $dest = (string) ($config['destination'] ?? '');
        if ($dry) {
            return 'WOULD send to chat destination "' . $dest . '": "' . mb_substr($message, 0, 200) . '"';
        }

        return ChatNotifierRegistry::get()->send($dest, $message, $context);
    }
}
