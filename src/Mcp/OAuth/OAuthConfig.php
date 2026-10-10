<?php

namespace ITFlow\Mcp\OAuth;

use ITFlow\Mcp\McpConfig;

/**
 * Settings and fixed values of the built-in OAuth 2.1 authorization server (docs/REMOTE_MCP.md).
 *
 * The two switches live in the `mcp_oauth_config` key/value table (the `settings` row is at its size limit):
 *   builtin_enabled       '1' = RivetIT is its own authorization server for Remote MCP (default off)
 *   registration_enabled  '1' = MCP clients may register themselves (RFC 7591, default on once built-in is on)
 * The built-in server is only ever active while the Remote MCP module switch is on and RIVETIT_MCP_ENABLED is not 0.
 * The issuer and the MCP resource URL are derived from $config_base_url, never from a request header.
 */
final class OAuthConfig
{
    public const SCOPE = 'mcp:read';
    public const ACCESS_TTL = 900;            // seconds; access tokens live 15 minutes at most
    public const CODE_TTL = 60;               // seconds; authorization codes
    public const REFRESH_TTL = 2592000;       // seconds; 30 days, renewed by every rotation
    public const GRANT_DAYS = 180;            // absolute life of a consent; after that the user approves again
    public const MAX_CLIENTS = 500;           // dynamically registered clients kept at once
    public const MAX_REDIRECT_URIS = 5;
    public const REGISTER_PER_IP_HOUR = 10;       // successful registrations per address per hour (database count)
    public const REGISTER_REQUESTS_PER_IP_HOUR = 30;   // registration requests of any outcome per address per hour (Redis)

    public static function issuer(string $baseUrl): string
    {
        return 'https://' . $baseUrl;
    }

    public static function resource(string $baseUrl): string
    {
        return self::issuer($baseUrl) . '/mcp';
    }

    /** @return array{schema_ready:bool, builtin:bool, registration:bool, active:bool} */
    public static function load(\mysqli $db): array
    {
        $values = [];
        $ready = false;
        try {
            $res = $db->query('SELECT setting_key, setting_value FROM mcp_oauth_config');
            if ($res) {
                $ready = true;
                foreach ($res->fetch_all(MYSQLI_ASSOC) as $row) {
                    $values[$row['setting_key']] = $row['setting_value'];
                }
            }
        } catch (\Throwable) { /* table missing: before the database update */ }

        $builtin = ($values['builtin_enabled'] ?? '0') === '1';
        // Registration is on unless an administrator switched it off.
        $registration = ($values['registration_enabled'] ?? '1') === '1';
        $module = false;
        try {
            $module = (bool) McpConfig::load($db)['enabled'];
        } catch (\Throwable) { /* treated as off */ }

        return ['schema_ready' => $ready, 'builtin' => $builtin, 'registration' => $registration,
            'active' => $ready && $builtin && $module];
    }

    public static function set(\mysqli $db, string $key, string $value): void
    {
        $stmt = $db->prepare('INSERT INTO mcp_oauth_config (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
}
