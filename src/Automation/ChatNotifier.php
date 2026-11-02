<?php

namespace ITFlow\Automation;

/**
 * INTEGRATION POINT for chat (Slack / Microsoft Teams) messages sent by the "Send a chat message" rule action (NotifyChatAction).
 *
 * The action does not know how to talk to a chat service. It asks the notifier registered here: whoever builds the real senders (issue #36)
 * implements this interface and calls ChatNotifierRegistry::set() once at start-up (for example from includes/event_bus.php or a service
 * provider). Until then the registry holds UnavailableChatNotifier, which fails the run with a clear message, so a rule can be written and
 * tested (dry run) but sends nothing.
 *
 * A "destination" is a short key the administrator picks in the rule editor ("it-alerts", "oncall"). What it means (a channel, a webhook row,
 * a Teams team) is the notifier's business; destinations() lists the keys it knows so the editor can offer them.
 */
interface ChatNotifier
{
    /** @return array<string,string> destination key => label shown in the editor (empty = free text) */
    public function destinations(): array;

    /**
     * Sends one plain-text message. @param array<string,string> $context the flat event context (for a link back, a severity...)
     * @return string a short result for the run log (no secrets) @throws \RuntimeException when it cannot be sent
     */
    public function send(string $destination, string $message, array $context): string;
}
