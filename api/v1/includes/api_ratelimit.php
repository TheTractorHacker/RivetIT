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
 * FAILS OPEN by default: if Redis is unavailable (getRedisClient() returns null)
 * or any Redis call throws, this returns true so the API keeps serving. Rate
 * limiting here is an abuse guard, never a hard dependency. Credential-guessing
 * surfaces (the login endpoint) pass $fail_closed = true so an attacker cannot
 * lift the throttle by knocking Redis over: with Redis down the call returns
 * false (the caller answers 429).
 *
 * @param string $bucket unique key suffix (per token, per IP, ...)
 * @param int    $limit  max requests allowed within the window
 * @param int    $window window length in seconds
 * @param bool   $fail_closed deny (return false) when Redis is unavailable
 * @return bool true = allowed, false = over limit
 */
function api_rate_limit(string $bucket, int $limit, int $window, bool $fail_closed = false): bool {
    // Trusted callers (config.php: CONST_API_RATE_LIMIT_ALLOWLIST) skip every
    // bucket entirely - not just IP-keyed ones - since the intent is "this
    // caller's traffic is never rate-limited," regardless of which endpoint
    // or bucket shape it happens to hit.
    //
    // Deliberately checks $_SERVER['REMOTE_ADDR'] directly, NOT getIP(): if
    // this deployment ever sets CONST_GET_IP_METHOD to trust
    // X-Forwarded-For/CF-Connecting-IP (plausible - this box sits behind a
    // reverse-proxy chain with no nginx real_ip config yet), getIP() starts
    // trusting a client-supplied header. A bypass gate must never do that -
    // anyone could then send `X-Forwarded-For: 10.1.0.13` and skip rate
    // limiting on auth/crash-report/csat/device-metrics entirely. REMOTE_ADDR
    // is always the literal TCP peer, regardless of that setting.
    $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';
    if (defined('CONST_API_RATE_LIMIT_ALLOWLIST') && $remote_addr !== '' && in_array($remote_addr, CONST_API_RATE_LIMIT_ALLOWLIST, true)) {
        return true;
    }

    $redis = getRedisClient();
    if (!$redis) {
        return !$fail_closed; // fail open (default) / closed (login endpoint) — Redis down
    }
    $key = 'api_rl:' . $bucket;
    try {
        $count = (int) $redis->incr($key);
        if ($count === 1) {
            $redis->expire($key, $window);
        }
        return $count <= $limit;
    } catch (\Throwable $e) {
        return !$fail_closed; // fail open (default) / closed (login endpoint) — Redis error
    }
}
