<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Redis;

use RivetCore\Redis\RedisClientProviderInterface;

/**
 * Gives RivetCore the app's shared Redis connection: getRedisClient() in includes/redis_functions.php resolves
 * RIVETIT_REDIS_* > Administration > Redis > default, and returns null when Redis is down (Core fails open).
 */
final class GlobalRedisClientProvider implements RedisClientProviderInterface
{
    public function client(): ?\Predis\Client
    {
        return function_exists('getRedisClient') ? getRedisClient() : null;
    }
}
