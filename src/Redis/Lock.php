<?php

namespace ITFlow\Redis;

/**
 * Redis mutex (SET NX PX + compare-and-delete release) so two processes - a cron run and a manual
 * "Sync now", or two workers - do not run the same job at once.
 *
 * FAILS OPEN: when Redis is unreachable acquire() reports the lock as held (degraded() is true) so
 * jobs keep running exactly as they did before Redis locks existed. Redis is a coordination aid,
 * never a hard dependency. Callers that must not run without mutual exclusion should check
 * degraded() themselves.
 */
final class Lock
{
    private const RELEASE = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
    private const EXTEND = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) else return 0 end";

    private function __construct(
        private string $key,
        private string $token,
        private bool $held,
        private bool $degraded
    ) {}

    public static function acquire(string $name, int $ttlSeconds): self
    {
        $key = 'rivetit:lock:' . $name;
        $token = bin2hex(random_bytes(16));
        $redis = function_exists('getRedisClient') ? getRedisClient() : null;
        if (!$redis) {
            return new self($key, $token, true, true);
        }
        try {
            $ok = $redis->set($key, $token, 'EX', max(1, $ttlSeconds), 'NX');
            return new self($key, $token, $ok !== null && (string) $ok === 'OK', false);
        } catch (\Throwable) {
            return new self($key, $token, true, true);
        }
    }

    /** True when this caller may proceed (it owns the lock, or Redis is unavailable). */
    public function held(): bool { return $this->held; }

    /** True when Redis could not be reached, so no mutual exclusion is actually in force. */
    public function degraded(): bool { return $this->degraded; }

    /** Push the expiry out for a job that outlives its initial TTL. */
    public function extend(int $ttlSeconds): bool
    {
        if (!$this->held || $this->degraded) return $this->held;
        try {
            return (int) getRedisClient()?->eval(self::EXTEND, 1, $this->key, $this->token, max(1, $ttlSeconds) * 1000) === 1;
        } catch (\Throwable) { return false; }
    }

    public function release(): void
    {
        if (!$this->held || $this->degraded) return;
        $this->held = false;
        try { getRedisClient()?->eval(self::RELEASE, 1, $this->key, $this->token); }
        catch (\Throwable) { /* the TTL will clear it */ }
    }

    /** Run $fn under the lock; returns [ran, value]. ran=false means another holder had it. */
    public static function run(string $name, int $ttlSeconds, callable $fn): array
    {
        $lock = self::acquire($name, $ttlSeconds);
        if (!$lock->held()) return [false, null];
        try { return [true, $fn($lock)]; }
        finally { $lock->release(); }
    }
}
