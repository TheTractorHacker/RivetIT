<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Gives the RMM module (RivetCore 1.0.0-rc.11: the read-path cache and the per-client token buckets) the app's shared Redis connection, from ANY entry point.
 *
 * ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider answers null when includes/redis_functions.php is not loaded, which is true of a CLI script or a test that never
 * loaded it; a process that then wrote an invalidation to nowhere would leave the web workers serving a stale list (releases, fleet counters) for the cache's
 * whole stale window. This provider loads the file itself, so every process that builds the module sees the same Redis or none at all. getRedisClient() resolves
 * RIVETIT_REDIS_* > Administration > Redis > default once per process and answers null when Redis is down (Core then fails open: no bucket limits, no cache).
 */
final class EndpointRedisProvider implements RedisClientProviderInterface
{
    public function client(): ?\Predis\Client
    {
        if (!function_exists('getRedisClient')) {
            require_once dirname(__DIR__, 4) . '/includes/redis_functions.php';
        }

        return function_exists('getRedisClient') ? getRedisClient() : null;
    }
}
