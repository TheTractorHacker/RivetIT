<?php

namespace ITFlow\Chat;

/**
 * Builds the Slack app manifest for this install, so an administrator creates the Slack app with "From an app manifest" (paste the JSON) instead of
 * clicking through a dozen settings. Every URL is derived from the install's own base URL (https only). Pure: no I/O.
 *
 * Features are optional so a site that only wants notifications does not request scopes it never uses:
 *   commands   the /ticket slash command and the two shortcuts (create a ticket from a message / a global "New ticket")
 *   events     the Events API subscription (thread replies -> ticket replies, direct messages and mentions -> virtual agent)
 * Interactivity (buttons, modals) is always on: the existing Acknowledge / Assign buttons need it.
 */
final class SlackManifest
{
    /** @return array<string,mixed> */
    public static function build(string $baseUrl, string $appName, bool $commands = true, bool $events = true): array
    {
        $base = rtrim($baseUrl, '/');
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?(/[^\s]*)?$#i', $base)) {
            throw new \InvalidArgumentException('A public https base URL is required for the Slack manifest.');
        }
        $name = mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._-]/u', '', $appName) ?? '') ?: (defined('APP_NAME') ? APP_NAME : 'RivetIT'), 0, 35);
        $scopes = ['chat:write', 'incoming-webhook', 'users:read', 'users:read.email'];
        $features = ['bot_user' => ['display_name' => $name, 'always_online' => false]];
        $settings = ['interactivity' => ['is_enabled' => true, 'request_url' => $base . '/slack_interactive.php'], 'org_deploy_enabled' => false, 'socket_mode_enabled' => false, 'token_rotation_enabled' => false];

        if ($commands) {
            $scopes[] = 'commands';
            $features['slash_commands'] = [[
                'command' => '/ticket', 'url' => $base . '/slack_commands.php', 'description' => 'Create tickets and look them up',
                'usage_hint' => 'new <subject> | status <ticket> | help', 'should_escape' => false,
            ]];
            $features['shortcuts'] = [
                ['name' => 'Create ticket from message', 'type' => 'message', 'callback_id' => ChatBlocks::SHORTCUT_NEW, 'description' => 'Turn this message into a ticket'],
                ['name' => 'New ticket', 'type' => 'global', 'callback_id' => ChatBlocks::SHORTCUT_GLOBAL, 'description' => 'Create a ticket'],
            ];
        }
        if ($events) {
            array_push($scopes, 'app_mentions:read', 'channels:history', 'groups:history', 'im:history', 'im:write');
            $features['app_home'] = ['home_tab_enabled' => false, 'messages_tab_enabled' => true, 'messages_tab_read_only_enabled' => false];
            $settings['event_subscriptions'] = ['request_url' => $base . '/slack_events.php', 'bot_events' => ['app_mention', 'message.channels', 'message.groups', 'message.im']];
        } else {
            $scopes[] = 'im:write'; // approval prompts are direct messages
        }

        return [
            'display_information' => ['name' => $name, 'description' => 'Service desk in Slack: ticket notifications, replies, approvals and requests.', 'background_color' => '#0f766e'],
            'features' => $features,
            'oauth_config' => ['scopes' => ['bot' => array_values(array_unique($scopes))]],
            'settings' => $settings,
        ];
    }

    public static function json(string $baseUrl, string $appName, bool $commands = true, bool $events = true): string
    {
        return (string) json_encode(self::build($baseUrl, $appName, $commands, $events), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
