<?php

namespace ITFlow\Automation\Actions;

use ITFlow\Workflow\ActionGateway;

/** Event-rule action kept from before the engine; see BuiltinAction. */
final class SendWebhookAction extends BuiltinAction
{
    public function key(): string
    {
        return 'send_webhook';
    }

    public function label(): string
    {
        return 'Send a webhook';
    }

    public function validate(\mysqli $mysqli, array $input, string $triggerEvent): array
    {
        require_once dirname(__DIR__, 3) . '/includes/event_bus.php';
        $url = $this->text($input, 'url', 500, true, 'webhook URL');
        if (!\rivetWebhookUrlIsSafe($url)) {
            throw new \InvalidArgumentException('The webhook URL must be an http(s) address that resolves to a public address.');
        }

        return ['url' => $url, 'secret' => $this->text($input, 'secret', 200, false, '')];
    }

    public function execute(\mysqli $mysqli, ActionGateway $gateway, array $config, array $context, array $rule, bool $dry): string
    {
        if ($dry) {
            require_once dirname(__DIR__, 3) . '/includes/event_bus.php';
            $host = (string) parse_url((string) $config['url'], PHP_URL_HOST);
            if (\rivetWebhookResolveTarget((string) $config['url']) === null) {
                throw new \RuntimeException('the webhook URL does not resolve to a public address');
            }

            return "WOULD POST the event to $host" . ((string) ($config['secret'] ?? '') !== '' ? ' (signed)' : '');
        }

        return $this->viaHandler($mysqli, $config, $context, $rule); // the handler re-checks the address (SSRF guard) at call time
    }
}
