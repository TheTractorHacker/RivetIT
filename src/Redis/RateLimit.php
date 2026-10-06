<?php

namespace ITFlow\Redis;

use ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider;
use RivetCore\Redis\RateLimiter;

/**
 * Compatibility shim over RivetCore's RateLimiter; same static API and key layout (`rivetit:rl:<bucket>`).
 * Fails open (allowed) if Redis - or the rivet-core package - is unavailable.
 *
 * @deprecated since 26.10.26 use \RivetCore\Redis\RateLimiter (new RateLimiter(new GlobalRedisClientProvider(), 'rivetit:')). Kept for all of 1.x, removed in 2.0 (docs/DEPRECATIONS.md).
 */
final class RateLimit
{
    /** @return array{allowed:bool, remaining:int, retry_after:int} */
    public static function hit(string $bucket, int $limit, int $windowSeconds): array
    {
        if (!class_exists(RateLimiter::class)) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }
        static $limiter = null;
        $limiter ??= new RateLimiter(new GlobalRedisClientProvider(), 'rivetit:');

        return $limiter->hit($bucket, $limit, $windowSeconds);
    }
}
