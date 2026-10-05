<?php

namespace ITFlow\Redis;

use ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider;
use RivetCore\Redis\LockManager;

/**
 * Compatibility shim over RivetCore's LockManager; keeps the original static API and key layout
 * (`rivetit:lock:<name>`). FAILS OPEN, as before: if Redis is down - or the rivet-core package is not
 * installed yet (the moment between `git pull` and `composer install`) - the caller may proceed.
 */
final class Lock
{
    /** @return \RivetCore\Redis\Lock|object with held(), degraded(), extend(), release() */
    public static function acquire(string $name, int $ttlSeconds)
    {
        if (!class_exists(LockManager::class)) {
            return new class {
                public function held(): bool { return true; }
                public function degraded(): bool { return true; }
                public function extend(int $ttlSeconds): bool { return true; }
                public function release(): void {}
            };
        }

        return self::manager()->acquire($name, $ttlSeconds);
    }

    /** Run $fn under the lock; returns [ran, value]. ran=false means another holder had it. */
    public static function run(string $name, int $ttlSeconds, callable $fn): array
    {
        $lock = self::acquire($name, $ttlSeconds);
        if (!$lock->held()) return [false, null];
        try { return [true, $fn($lock)]; }
        finally { $lock->release(); }
    }

    private static function manager(): LockManager
    {
        static $m = null;

        return $m ??= new LockManager(new GlobalRedisClientProvider(), 'rivetit:');
    }
}
