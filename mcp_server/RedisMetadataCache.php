<?php

/** Public OAuth discovery/JWKS cache. Redis outages are treated as cache misses (RivetCore\Mcp\RedisMetadataCache). */
final class RedisMetadataCache extends \RivetCore\Mcp\RedisMetadataCache
{
    public function __construct()
    {
        parent::__construct(new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider());
    }
}
