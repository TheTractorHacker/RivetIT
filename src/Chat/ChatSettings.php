<?php

namespace ITFlow\Chat;

/**
 * The chat switches and secrets from the single settings row. Every switch is OFF unless an administrator turned it on
 * (Administration > Chat). Secrets are stored with encryptSetting() and are decrypted only here, on demand.
 */
final class ChatSettings
{
    private const COLUMNS = [
        'config_slack_link_by_email', 'config_slack_bot_token', 'config_slack_team_id', 'config_slack_signing_secret',
        'config_chat_two_way_enabled', 'config_chat_commands_enabled', 'config_chat_actions_enabled', 'config_chat_virtual_agent_enabled',
        'config_chat_mirror_internal', 'config_chat_thread_reply_type', 'config_chat_slack_channel_id', 'config_chat_default_client_id',
        'config_teams_bot_enabled', 'config_teams_app_id', 'config_teams_app_secret', 'config_teams_tenant_id', 'config_teams_link_by_email',
    ];

    /** @var array<string,mixed> */
    private array $row;

    public function __construct(private \mysqli $db)
    {
        $this->row = [];
        $res = @mysqli_query($db, 'SELECT ' . implode(', ', self::COLUMNS) . ' FROM settings WHERE company_id = 1');
        if ($res) {
            $this->row = mysqli_fetch_assoc($res) ?: [];
        }
    }

    public function flag(string $column): bool
    {
        return (int) ($this->row[$column] ?? 0) === 1;
    }

    public function text(string $column): string
    {
        return (string) ($this->row[$column] ?? '');
    }

    public function int(string $column): int
    {
        return (int) ($this->row[$column] ?? 0);
    }

    /** A secret column, decrypted ('' when unset). */
    public function secret(string $column): string
    {
        $v = (string) ($this->row[$column] ?? '');
        if ($v === '') {
            return '';
        }

        return function_exists('decryptSetting') ? (string) decryptSetting($v) : $v;
    }

    /** The still-encrypted value (what SlackInteractive::agentForSlackUser expects). */
    public function rawSecret(string $column): string
    {
        return (string) ($this->row[$column] ?? '');
    }

    public function twoWay(): bool { return $this->flag('config_chat_two_way_enabled'); }
    public function commands(): bool { return $this->flag('config_chat_commands_enabled'); }
    public function actions(): bool { return $this->flag('config_chat_actions_enabled'); }
    public function virtualAgent(): bool { return $this->flag('config_chat_virtual_agent_enabled'); }
    public function teamsBot(): bool { return $this->flag('config_teams_bot_enabled'); }

    /** 'Internal' or 'Public' (the ticket_reply_type a chat reply is stored as). */
    public function threadReplyType(): string
    {
        return $this->text('config_chat_thread_reply_type') === 'public' ? 'Public' : 'Internal';
    }

    /** The public base URL of this install (https://host), '' when unknown. */
    public static function baseUrl(): string
    {
        global $config_base_url;
        $base = trim((string) ($config_base_url ?? ''));
        if ($base === '') {
            return '';
        }

        return preg_match('#^https?://#i', $base) ? rtrim($base, '/') : 'https://' . rtrim($base, '/');
    }
}
