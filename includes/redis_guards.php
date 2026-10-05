<?php

/*
 * Redis-backed guards, through RivetCore: one-at-a-time locks (cron scripts, long operations) and abuse rate limits (sign-in, API).
 * Every guard FAILS OPEN: if Redis is down or the module is off, the work simply goes ahead as it did before these guards existed.
 */

require_once __DIR__ . '/event_bus.php';

function rivetRedisKeyPrefix(): string
{
    return class_exists('\RivetMSP\Core\CoreBridge') ? 'rivetmsp:' : 'rivetit:';
}

function rivetRedisGuardsOn(): bool
{
    return class_exists(\RivetCore\Redis\LockManager::class) && rivetCoreModuleOn('core.redis.enabled');
}

function rivetRedisProvider(): \RivetCore\Redis\RedisClientProviderInterface
{
    $class = rivetCoreAdapterNs() . '\Redis\GlobalRedisClientProvider';

    return new $class();
}

function rivetLocks(): ?\RivetCore\Redis\LockManager
{
    static $m = false;
    if ($m === false) {
        $m = rivetRedisGuardsOn() ? new \RivetCore\Redis\LockManager(rivetRedisProvider(), rivetRedisKeyPrefix()) : null;
    }

    return $m;
}

/** Exit quietly if another copy of this cron job is already running; the lock is released when the script ends (or expires after $ttl). */
function rivetCronGuard(string $job, int $ttlSeconds = 900): void
{
    $locks = rivetLocks();
    if ($locks === null) {
        return;
    }
    $lock = $locks->acquire('cron:' . $job, $ttlSeconds);
    if (!$lock->held()) {
        echo "$job is already running. Exiting.\n";
        exit(0);
    }
    register_shutdown_function(static fn () => $lock->release());
}

/** Run $fn only if nobody else holds $name. @return array{0:bool,1:mixed} [ran, value] */
function rivetWithLock(string $name, int $ttlSeconds, callable $fn): array
{
    $locks = rivetLocks();
    if ($locks === null) {
        return [true, $fn()];
    }

    return $locks->run($name, $ttlSeconds, $fn);
}

/** @return array{allowed:bool, remaining:int, retry_after:int} always allowed when Redis is unavailable */
function rivetRateLimit(string $bucket, int $limit, int $windowSeconds): array
{
    static $limiter = false;
    if ($limiter === false) {
        $limiter = rivetRedisGuardsOn() && class_exists(\RivetCore\Redis\RateLimiter::class) ? new \RivetCore\Redis\RateLimiter(rivetRedisProvider(), rivetRedisKeyPrefix()) : null;
    }
    if ($limiter === null) {
        return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
    }
    try {
        return $limiter->hit($bucket, $limit, $windowSeconds);
    } catch (\Throwable $e) {
        return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
    }
}

/**
 * Sign-in throttle: at most 30 attempts per address and 10 per account every 5 minutes (Redis, through RivetCore; open if Redis is down).
 * A guard against password guessing, on top of the per-account lockout. @return int 0 when allowed, otherwise seconds to wait
 */
function rivetLoginThrottle(string $email, string $ip): int
{
    $byIp = rivetRateLimit('login:ip:' . $ip, 30, 300);
    if (!$byIp['allowed']) {
        return max(1, (int) $byIp['retry_after']);
    }
    $byAccount = rivetRateLimit('login:acct:' . hash('sha256', strtolower($email)), 10, 300);

    return $byAccount['allowed'] ? 0 : max(1, (int) $byAccount['retry_after']);
}
