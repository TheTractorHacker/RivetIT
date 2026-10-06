<?php

namespace ITFlow\Redis;

use Predis\Client;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;

/**
 * Where RivetIT finds Redis, and the admin tools around it. The connection comes from, in order: the
 * RIVETIT_REDIS_* environment variables, or the same KEY=VALUE lines in /etc/rivetit/redis.env (HOST, PORT, DB, PASSWORD, USERNAME, TLS, TLS_VERIFY, TLS_CA_FILE,
 * TLS_CERT_FILE, TLS_KEY_FILE), the values saved in Administration > Redis (the password encrypted), then the built-in
 * default (127.0.0.1:6380). Each setting is decided on its own: an environment variable wins over the saved value. Redis stays optional and never holds the only copy of anything.
 */
final class RedisSettings
{
    public const DEFAULT_HOST = '127.0.0.1';
    public const DEFAULT_PORT = 6380;

    /** Key patterns the admin page may clear, and nothing else. Pub/sub channels hold no keys. */
    public const CLEARABLE = [
        'rate_limits' => ['label' => 'Rate-limit counters', 'patterns' => ['rivetit:rl:*', 'api_rl:*']],
        'mcp_cache' => ['label' => 'MCP sign-in discovery cache', 'patterns' => ['mcp_metadata:*']],
        'locks' => ['label' => 'Job locks', 'patterns' => ['rivetit:lock:*']],
    ];

    public const POLICIES = ['allkeys-lru', 'volatile-lru', 'allkeys-lfu', 'volatile-lfu', 'noeviction'];

    /** @return array{host:string, port:int, password:?string, db:int, username:?string, tls:bool, tls_verify:bool, tls_ca_file:?string, tls_cert_file:?string, tls_key_file:?string, from_env:array<string,bool>, stored:array<string,mixed>, schema_ready:bool, stored_host:string, stored_port:int, stored_db:int, has_stored_password:bool} */
    public static function resolve(?\mysqli $db = null): array
    {
        $row = [];
        $schema = false;
        if ($db) {
            try {
                $res = $db->query('SELECT * FROM settings WHERE company_id = 1');
                $row = $res ? ($res->fetch_assoc() ?: []) : [];
                $schema = array_key_exists('config_redis_host', $row);
            } catch (\Throwable) { /* before the migration, or no database: defaults apply */ }
        }
        $file = self::fileValues();
        // The process environment first, then the server-wide file (/etc/rivetit/redis.env): both are "set by the server".
        $env = static fn(string $k) => ($v = getenv($k)) === false || $v === '' ? ($file[$k] ?? null) : $v;
        $storedHost = trim((string) ($row['config_redis_host'] ?? ''));
        $storedPort = (int) ($row['config_redis_port'] ?? 0);
        $storedDb = (int) ($row['config_redis_db'] ?? 0);
        $storedPassEnc = (string) ($row['config_redis_password'] ?? '');

        $storedPass = null;
        if ($storedPassEnc !== '' && function_exists('decryptSetting')) {
            try { $storedPass = decryptSetting($storedPassEnc) ?: null; } catch (\Throwable) { $storedPass = null; }
        }

        $bool = static fn(string $k, bool $default) => ($v = $env($k)) === null ? null : filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
        $storedUser = trim((string) ($row['config_redis_username'] ?? ''));
        $storedTls = (bool) ($row['config_redis_tls'] ?? 0);
        // The verify column defaults to 1; a missing column (before the migration) also means "verify".
        $storedVerify = !array_key_exists('config_redis_tls_verify', $row) || (bool) $row['config_redis_tls_verify'];
        $storedCa = trim((string) ($row['config_redis_tls_ca_file'] ?? ''));
        $storedCert = trim((string) ($row['config_redis_tls_cert_file'] ?? ''));
        $storedKey = trim((string) ($row['config_redis_tls_key_file'] ?? ''));

        return [
            'host' => $env('RIVETIT_REDIS_HOST') ?? ($storedHost !== '' ? $storedHost : self::DEFAULT_HOST),
            'port' => (int) ($env('RIVETIT_REDIS_PORT') ?? ($storedPort > 0 ? $storedPort : self::DEFAULT_PORT)),
            'password' => $env('RIVETIT_REDIS_PASSWORD') ?? $storedPass,
            'db' => (int) ($env('RIVETIT_REDIS_DB') ?? $storedDb),
            'username' => $env('RIVETIT_REDIS_USERNAME') ?? ($storedUser !== '' ? $storedUser : null),
            'tls' => $bool('RIVETIT_REDIS_TLS', false) ?? $storedTls,
            'tls_verify' => $bool('RIVETIT_REDIS_TLS_VERIFY', true) ?? $storedVerify,
            'tls_ca_file' => $env('RIVETIT_REDIS_TLS_CA_FILE') ?? ($storedCa !== '' ? $storedCa : null),
            'tls_cert_file' => $env('RIVETIT_REDIS_TLS_CERT_FILE') ?? ($storedCert !== '' ? $storedCert : null),
            'tls_key_file' => $env('RIVETIT_REDIS_TLS_KEY_FILE') ?? ($storedKey !== '' ? $storedKey : null),
            'from_env' => [
                'host' => $env('RIVETIT_REDIS_HOST') !== null, 'port' => $env('RIVETIT_REDIS_PORT') !== null,
                'password' => $env('RIVETIT_REDIS_PASSWORD') !== null, 'db' => $env('RIVETIT_REDIS_DB') !== null,
                'username' => $env('RIVETIT_REDIS_USERNAME') !== null, 'tls' => $env('RIVETIT_REDIS_TLS') !== null,
                'tls_verify' => $env('RIVETIT_REDIS_TLS_VERIFY') !== null, 'tls_ca_file' => $env('RIVETIT_REDIS_TLS_CA_FILE') !== null,
                'tls_cert_file' => $env('RIVETIT_REDIS_TLS_CERT_FILE') !== null, 'tls_key_file' => $env('RIVETIT_REDIS_TLS_KEY_FILE') !== null,
            ],
            // What is saved in the settings table, whatever the environment says (the form edits these).
            'stored' => [
                'username' => $storedUser, 'tls' => $storedTls, 'tls_verify' => $storedVerify,
                'tls_ca_file' => $storedCa, 'tls_cert_file' => $storedCert, 'tls_key_file' => $storedKey,
            ],
            'schema_ready' => $schema,
            'stored_host' => $storedHost, 'stored_port' => $storedPort, 'stored_db' => $storedDb,
            'has_stored_password' => $storedPassEnc !== '',
        ];
    }

    /** The file the installer or an administrator may write, so web, cron and CLI all see the same server-wide settings. */
    public const ENV_FILE = '/etc/rivetit/redis.env';

    /**
     * RIVETIT_REDIS_* values from the server-wide file: plain KEY=VALUE lines (# comments, optional quotes). PHP-FPM clears the
     * environment of its workers and cron gives scripts almost none, so a file is the one place all of them can read. Missing or
     * unreadable file: no values. The path can be changed with RIVETIT_REDIS_ENV_FILE (tests, unusual layouts). The password
     * in it is never logged or shown.
     *
     * @return array<string,string>
     */
    public static function fileValues(): array
    {
        $path = ($p = getenv('RIVETIT_REDIS_ENV_FILE')) !== false && $p !== '' ? $p : self::ENV_FILE;
        static $cache = [];
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return [];
        }
        if (isset($cache[$path]) && $cache[$path][0] === $mtime) {
            return $cache[$path][1];
        }
        $values = [];
        foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !preg_match('/^(?:export\s+)?(RIVETIT_REDIS_[A-Z_]+)\s*=\s*(.*)$/', $line, $m) || $m[1] === 'RIVETIT_REDIS_ENV_FILE') {
                continue;
            }
            $v = trim($m[2]);
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if ($v !== '') {
                $values[$m[1]] = $v;
            }
        }
        $cache[$path] = [$mtime, $values];

        return $values;
    }

    /** @return ?string an error message, or null when the values are acceptable */
    public static function validate(string $host, int $port, int $db, string $password): ?string
    {
        return RedisAdmin::validate($host, $port, $db, $password);
    }

    /**
     * Full check of a connection (host, port, db, password, ACL username, TLS and the certificate files, which must exist and be readable).
     *
     * @param array<string,mixed> $p a resolve()-shaped array
     * @return ?string an error message, or null when the values are acceptable
     */
    public static function validateParams(array $p, bool $checkFiles = true): ?string
    {
        return RedisConnectionConfig::fromArray($p)->validate($checkFiles);
    }

    public static function client(array $p, float $timeout = 1.0): Client
    {
        return self::admin()->client($p, $timeout);
    }

    /** @return array{ok:bool, message:string} */
    public static function test(array $p): array
    {
        return self::admin()->test($p);
    }

    public static function stats(Client $c): array
    {
        return self::admin()->stats($c);
    }

    /** Count keys per clearable group. @return array<string,int> */
    public static function groupCounts(Client $c, int $cap = 5000): array
    {
        return self::admin()->groupCounts($c, $cap);
    }

    /** Delete one allowlisted group of keys. Returns how many were removed. */
    public static function clear(Client $c, string $group): int
    {
        return self::admin()->clear($c, $group);
    }

    /** @return array{ok:bool, persisted:bool, message:string} */
    public static function setMemory(Client $c, int $megabytes, string $policy): array
    {
        return self::admin()->setMemory($c, $megabytes, $policy);
    }

    private static function admin(): RedisAdmin
    {
        return new RedisAdmin(self::CLEARABLE);
    }
}
