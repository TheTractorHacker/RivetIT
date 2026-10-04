<?php

namespace ITFlow\Redis;

use Predis\Client;

/**
 * Where RivetIT finds Redis, and the admin tools around it. The connection comes from, in order: the
 * RIVETIT_REDIS_* environment variables, the values saved in Administration > Redis, then the built-in
 * default (127.0.0.1:6380). Redis stays optional and never holds the only copy of anything.
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

    /** @return array{host:string, port:int, password:?string, db:int, from_env:array<string,bool>, schema_ready:bool, stored_host:string, stored_port:int, stored_db:int, has_stored_password:bool} */
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
        $env = static fn(string $k) => ($v = getenv($k)) === false || $v === '' ? null : $v;
        $storedHost = trim((string) ($row['config_redis_host'] ?? ''));
        $storedPort = (int) ($row['config_redis_port'] ?? 0);
        $storedDb = (int) ($row['config_redis_db'] ?? 0);
        $storedPassEnc = (string) ($row['config_redis_password'] ?? '');

        $storedPass = null;
        if ($storedPassEnc !== '' && function_exists('decryptSetting')) {
            try { $storedPass = decryptSetting($storedPassEnc) ?: null; } catch (\Throwable) { $storedPass = null; }
        }

        return [
            'host' => $env('RIVETIT_REDIS_HOST') ?? ($storedHost !== '' ? $storedHost : self::DEFAULT_HOST),
            'port' => (int) ($env('RIVETIT_REDIS_PORT') ?? ($storedPort > 0 ? $storedPort : self::DEFAULT_PORT)),
            'password' => $env('RIVETIT_REDIS_PASSWORD') ?? $storedPass,
            'db' => (int) ($env('RIVETIT_REDIS_DB') ?? $storedDb),
            'from_env' => [
                'host' => $env('RIVETIT_REDIS_HOST') !== null, 'port' => $env('RIVETIT_REDIS_PORT') !== null,
                'password' => $env('RIVETIT_REDIS_PASSWORD') !== null, 'db' => $env('RIVETIT_REDIS_DB') !== null,
            ],
            'schema_ready' => $schema,
            'stored_host' => $storedHost, 'stored_port' => $storedPort, 'stored_db' => $storedDb,
            'has_stored_password' => $storedPassEnc !== '',
        ];
    }

    /** @return ?string an error message, or null when the values are acceptable */
    public static function validate(string $host, int $port, int $db, string $password): ?string
    {
        if ($host === '' || strlen($host) > 253 || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$|^\[?[0-9A-Fa-f:]+\]?$/', $host)) return 'Enter a host name or IP address.';
        if ($port < 1 || $port > 65535) return 'The port must be between 1 and 65535.';
        if ($db < 0 || $db > 15) return 'The database number must be between 0 and 15.';
        if (strlen($password) > 500) return 'The password is too long.';
        return null;
    }

    public static function client(array $p, float $timeout = 1.0): Client
    {
        $parameters = ['scheme' => 'tcp', 'host' => $p['host'], 'port' => $p['port'], 'database' => $p['db'], 'timeout' => $timeout];
        if (!empty($p['password'])) $parameters['password'] = $p['password'];
        return new Client($parameters);
    }

    /** @return array{ok:bool, message:string} */
    public static function test(array $p): array
    {
        try {
            $c = self::client($p);
            $c->connect();
            $pong = (string) $c->ping();
            return $pong === 'PONG' ? ['ok' => true, 'message' => 'Connected.'] : ['ok' => false, 'message' => 'Unexpected reply from the server.'];
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            $hint = stripos($m, 'NOAUTH') !== false || stripos($m, 'WRONGPASS') !== false || stripos($m, 'invalid password') !== false
                ? 'The server wants a password, or the password is wrong.' : 'Could not connect. Check the host and port, and that Redis is running.';
            return ['ok' => false, 'message' => $hint];
        }
    }

    /** Flatten INFO sections, tolerating Predis returning either capitalised or lower-case section names. */
    public static function stats(Client $c): array
    {
        $info = $c->info();
        $flat = [];
        foreach ($info as $section => $values) {
            if (is_array($values)) foreach ($values as $k => $v) $flat[$k] = $v;
        }
        $hits = (int) ($flat['keyspace_hits'] ?? 0);
        $miss = (int) ($flat['keyspace_misses'] ?? 0);
        $keys = 0;
        foreach ($flat as $k => $v) {
            if (preg_match('/^db\d+$/', (string) $k)) $keys += is_array($v) ? (int) ($v['keys'] ?? 0) : (preg_match('/keys=(\d+)/', (string) $v, $m) ? (int) $m[1] : 0);
        }
        $policy = (string) ($flat['maxmemory_policy'] ?? '');
        return [
            'version' => (string) ($flat['redis_version'] ?? '?'),
            'uptime_seconds' => (int) ($flat['uptime_in_seconds'] ?? 0),
            'clients' => (int) ($flat['connected_clients'] ?? 0),
            'memory_used' => (string) ($flat['used_memory_human'] ?? '?'),
            'maxmemory' => (int) ($flat['maxmemory'] ?? 0),
            'policy' => $policy,
            'ops_per_sec' => (int) ($flat['instantaneous_ops_per_sec'] ?? 0),
            'hit_rate' => ($hits + $miss) > 0 ? round($hits / ($hits + $miss) * 100) : null,
            'keys' => $keys,
            'aof' => (string) ($flat['aof_enabled'] ?? '0') === '1',
            'last_save' => (int) ($flat['rdb_last_save_time'] ?? 0),
        ];
    }

    /** Count keys per clearable group (bounded SCAN so a huge keyspace cannot stall the page). @return array<string,int> */
    public static function groupCounts(Client $c, int $cap = 5000): array
    {
        $out = [];
        foreach (self::CLEARABLE as $group => $def) {
            $n = 0;
            foreach ($def['patterns'] as $pattern) {
                foreach (new \Predis\Collection\Iterator\Keyspace($c, $pattern, 500) as $_) {
                    if (++$n >= $cap) break;
                }
            }
            $out[$group] = $n;
        }
        return $out;
    }

    /** Delete one allowlisted group of keys. Returns how many were removed. */
    public static function clear(Client $c, string $group): int
    {
        if (!isset(self::CLEARABLE[$group])) throw new \InvalidArgumentException('Unknown group.');
        $removed = 0;
        foreach (self::CLEARABLE[$group]['patterns'] as $pattern) {
            $batch = [];
            foreach (new \Predis\Collection\Iterator\Keyspace($c, $pattern, 500) as $key) {
                $batch[] = $key;
                if (count($batch) >= 200) { $removed += (int) $c->del($batch); $batch = []; }
            }
            if ($batch) $removed += (int) $c->del($batch);
        }
        return $removed;
    }

    /** @return array{ok:bool, persisted:bool, message:string} */
    public static function setMemory(Client $c, int $megabytes, string $policy): array
    {
        if ($megabytes < 64 || $megabytes > 65536) return ['ok' => false, 'persisted' => false, 'message' => 'Choose a limit between 64 MB and 65536 MB.'];
        if (!in_array($policy, self::POLICIES, true)) return ['ok' => false, 'persisted' => false, 'message' => 'Unknown eviction policy.'];
        try {
            $c->config('SET', 'maxmemory', (string) ($megabytes * 1048576));
            $c->config('SET', 'maxmemory-policy', $policy);
        } catch (\Throwable) {
            return ['ok' => false, 'persisted' => false, 'message' => 'Redis refused the change (CONFIG may be disabled).'];
        }
        try {
            $c->config('REWRITE');
            return ['ok' => true, 'persisted' => true, 'message' => 'Applied and saved to the Redis config file.'];
        } catch (\Throwable) {
            return ['ok' => true, 'persisted' => false, 'message' => 'Applied now, but Redis could not save it to its config file, so a restart will undo it. Add these lines to redis.conf to keep it: maxmemory ' . $megabytes . 'mb and maxmemory-policy ' . $policy . '.'];
        }
    }
}
