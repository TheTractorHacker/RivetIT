<?php

namespace ITFlow\Redis;

/** One-line "only one copy of this cron job at a time" guard for CLI scripts. */
final class CronGuard
{
    /** Exit quietly if another copy holds the lock; otherwise release it when this script ends. */
    public static function acquireOrExit(string $job, int $ttlSeconds = 900): void
    {
        $lock = Lock::acquire('cron:' . $job, $ttlSeconds);
        if (!$lock->held()) {
            echo "$job is already running. Exiting.\n";
            exit(0);
        }
        register_shutdown_function(static fn() => $lock->release());
    }
}
