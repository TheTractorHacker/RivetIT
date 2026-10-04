<?php

namespace ITFlow\Redis;

/**
 * Fixed-window rate limiter on Redis. The counter and its TTL are set in one Lua call so a crash
 * cannot leave a counter that never expires. Fails open (allowed) if Redis is unavailable: this is
 * an abuse guard, not an access control.
 */
final class RateLimit
{
    private const HIT = "local c = redis.call('incr', KEYS[1]) if c == 1 then redis.call('expire', KEYS[1], ARGV[1]) end return {c, redis.call('ttl', KEYS[1])}";

    /** @return array{allowed:bool, remaining:int, retry_after:int} */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        $open = ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        $redis = function_exists('getRedisClient') ? getRedisClient() : null;
        if (!$redis) return $open;
        try {
            [$count, $ttl] = $redis->eval(self::HIT, 1, 'rivetit:rl:' . $bucket, max(1, $windowSeconds));
            $count = (int) $count;
            return [
                'allowed' => $count <= $limit,
                'remaining' => max(0, $limit - $count),
                'retry_after' => $count <= $limit ? 0 : max(1, (int) $ttl),
            ];
        } catch (\Throwable) {
            return $open;
        }
    }
}
