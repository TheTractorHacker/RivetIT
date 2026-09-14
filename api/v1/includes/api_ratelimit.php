<?php
defined('FROM_API') || die();

/**
 * Best-effort fixed-window rate limiter backed by Redis (Predis, via
 * getRedisClient() in includes/redis_functions.php).
 *
 * Increments a per-bucket counter and, on the first hit of a window, sets a
 * TTL equal to the window length. Returns true while the caller is at or under
 * $limit for the current window, false once it is exceeded.
 *
 * FAILS OPEN: if Redis is unavailable (getRedisClient() returns null) or any
 * Redis call throws, this returns true so the API keeps serving. Rate limiting
 * here is an abuse guard, never a hard dependency.
 *
 * @param string $bucket unique key suffix (per token, per IP, ...)
 * @param int    $limit  max requests allowed within the window
 * @param int    $window window length in seconds
 * @return bool true = allowed, false = over limit
 */
function api_rate_limit(string $bucket, int $limit, int $window): bool {
    // Trusted callers (config.php: CONST_API_RATE_LIMIT_ALLOWLIST) skip every
    // bucket entirely - not just IP-keyed ones - since the intent is "this
    // caller's traffic is never rate-limited," regardless of which endpoint
    // or bucket shape it happens to hit.
    if (defined('CONST_API_RATE_LIMIT_ALLOWLIST') && in_array(getIP(), CONST_API_RATE_LIMIT_ALLOWLIST, true)) {
        return true;
    }

    $redis = getRedisClient();
    if (!$redis) {
        return true; // fail open — Redis down
    }
    $key = 'api_rl:' . $bucket;
    try {
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $window);
        }
        return $count <= $limit;
    } catch (\Throwable $e) {
        return true; // fail open — Redis error
    }
}
