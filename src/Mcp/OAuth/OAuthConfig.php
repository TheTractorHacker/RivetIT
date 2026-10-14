<?php

namespace ITFlow\Mcp\OAuth;

use ITFlow\Mcp\McpConfig;

/**
 * Settings and fixed values of the built-in OAuth 2.1 authorization server (docs/REMOTE_MCP.md).
 *
 * The two switches live in the `mcp_oauth_config` key/value table (the `settings` row is at its size limit):
 *   builtin_enabled       '1' = RivetIT is its own authorization server for Remote MCP (default off)
 *   registration_enabled  '1' = MCP clients may register themselves (RFC 7591, default on once built-in is on)
 *   cimd_mode             'off' (default) | 'hosts' (only cimd_hosts) | 'any' (any https host): OAuth Client ID Metadata Documents
 *   cimd_hosts            comma-separated hosts (host or host:port) trusted in 'hosts' mode; unset means claude.ai
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
    public const CIMD_MODES = ['off', 'hosts', 'any'];
    public const CIMD_DEFAULT_HOSTS = ['claude.ai'];
    public const REGISTER_PER_IP_HOUR = 10;       // successful registrations per address per hour (database count)
    public const REGISTER_DEDUPE_SECONDS = 600;        // the same address registering the same app again within this time gets its existing client
    public const REGISTER_REQUESTS_PER_IP_HOUR = 30;   // registration requests of any outcome per address per hour (Redis)

    public static function issuer(string $baseUrl): string
    {
        return 'https://' . $baseUrl;
    }

    public static function resource(string $baseUrl): string
    {
        return self::issuer($baseUrl) . '/mcp';
    }

    /** A time the OAuth tables store (always UTC) as a local time string, for display and for timeAgo(), which expects local time. */
    public static function localTime(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $utc;
        }
    }

    /** A trusted-host entry as stored: lower-case host with an optional :port, or null when it is not one. */
    public static function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:[0-9]{1,5})?$/D', $host) && strlen($host) <= 100 ? $host : null;
    }

    /** @return list<string> */
    public static function parseHosts(string $stored): array
    {
        $out = [];
        foreach (explode(',', $stored) as $h) {
            $n = self::normalizeHost($h);
            if ($n !== null) {
                $out[$n] = $n;
            }
        }

        return array_values($out);
    }

    /** @return array{mode:string, hosts:list<string>} the metadata-document trust policy (mode defaults to off, hosts to claude.ai) */
    public static function cimd(\mysqli $db): array
    {
        $mode = 'off';
        $hosts = self::CIMD_DEFAULT_HOSTS;
        try {
            $res = $db->query("SELECT setting_key, setting_value FROM mcp_oauth_config WHERE setting_key IN ('cimd_mode', 'cimd_hosts')");
            foreach ($res ? $res->fetch_all(MYSQLI_ASSOC) : [] as $row) {
                if ($row['setting_key'] === 'cimd_mode' && in_array($row['setting_value'], self::CIMD_MODES, true)) {
                    $mode = $row['setting_value'];
                } elseif ($row['setting_key'] === 'cimd_hosts') {
                    $hosts = self::parseHosts((string) $row['setting_value']);
                }
            }
        } catch (\Throwable) { /* table missing: off */ }

        return ['mode' => $mode, 'hosts' => $hosts];
    }

    /** @param list<string> $hosts */
    public static function setCimd(\mysqli $db, string $mode, array $hosts): void
    {
        self::set($db, 'cimd_mode', in_array($mode, self::CIMD_MODES, true) ? $mode : 'off');
        self::set($db, 'cimd_hosts', implode(',', $hosts));
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
