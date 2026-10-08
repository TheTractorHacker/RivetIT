<?php
defined('FROM_API') || die();

require_once __DIR__ . '/../../../includes/redis_guards.php';

/**
 * REST API request throttle. One implementation for the whole product: rivetRateLimit() (includes/redis_guards.php), the
 * Redis-backed fixed-window limiter in RivetCore, with the same key layout as the sign-in throttle.
 *
 * FAILS OPEN by default: if Redis is unavailable, switched off, or any Redis call throws, the request is allowed so the
 * API keeps serving. Rate limiting here is an abuse guard, never a hard dependency. Credential-guessing surfaces (the
 * login endpoint) pass $fail_closed = true so an attacker cannot lift the throttle by knocking Redis over: with Redis
 * down the call returns false (the caller answers 429).
 *
 * Callers answer an over-limit request with HTTP 429 and `Retry-After: api_rate_limit_retry_after()` (the seconds left in
 * the current window, not a fixed guess).
 *
 * @param string $bucket unique key suffix (per token, per IP, ...)
 * @param int    $limit  max requests allowed within the window
 * @param int    $window window length in seconds
 * @param bool   $fail_closed deny (return false) when Redis is unavailable
 * @return bool true = allowed, false = over limit
 */
function api_rate_limit(string $bucket, int $limit, int $window, bool $fail_closed = false): bool {
    $GLOBALS['api_rate_limit_retry_after'] = $window;

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

    $r = rivetRateLimit('api:' . $bucket, $limit, $window, $fail_closed); // fails open unless $fail_closed
    if (!$r['allowed']) {
        $GLOBALS['api_rate_limit_retry_after'] = max(1, (int) $r['retry_after']);
    }
    return (bool) $r['allowed'];
}

/** Seconds a caller refused by the last api_rate_limit() call should wait (for the Retry-After header). */
function api_rate_limit_retry_after(): int {
    return max(1, (int) ($GLOBALS['api_rate_limit_retry_after'] ?? 60));
}
