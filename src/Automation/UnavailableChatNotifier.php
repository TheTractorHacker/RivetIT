<?php

namespace ITFlow\Automation;

/** Placeholder until a real sender is registered. */
final class UnavailableChatNotifier implements ChatNotifier
{
    public function destinations(): array
    {
        return [];
    }

    public function send(string $destination, string $message, array $context): string
    {
        throw new \RuntimeException('no chat sender is installed (Slack / Teams senders are not registered); nothing was sent');
    }
}
