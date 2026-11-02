<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\SlackInteractive;

/**
 * Who signed this Slack request? Slack signs every call it makes to us (Events API, slash commands, interactivity) with the app's Signing Secret:
 * X-Slack-Signature = v0=HMAC-SHA256("v0:<timestamp>:<raw body>"), X-Slack-Request-Timestamp within 5 minutes, compared in constant time
 * (SlackInteractive::verify). Accepted secrets, in order:
 *   1. the app-level Signing Secret saved in Administration > Chat (settings.config_slack_signing_secret, encrypted);
 *   2. the secret of any enabled Slack webhook destination (the original "interactive buttons" setup keeps working unchanged).
 * Replay: an accepted signature is remembered for 10 minutes (slack_interactive_seen) and a second use is refused.
 */
final class SlackAuth
{
    /**
     * @return array{source:string,webhook:?array<string,mixed>}|null null = not a valid Slack request (answer 401, say nothing else)
     */
    public static function authenticate(\mysqli $db, string $timestamp, string $rawBody, string $signature, int $now): ?array
    {
        $secret = (new ChatSettings($db))->secret('config_slack_signing_secret');
        if ($secret !== '' && SlackInteractive::verify($secret, $timestamp, $rawBody, $signature, $now)) {
            return ['source' => 'app', 'webhook' => null];
        }
        $webhook = (new SlackInteractive($db))->destinationFor($timestamp, $rawBody, $signature, $now);

        return $webhook !== null ? ['source' => 'destination', 'webhook' => $webhook] : null;
    }
}
