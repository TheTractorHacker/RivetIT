<?php

use Psr\SimpleCache\CacheInterface;

/** Public OAuth discovery/JWKS cache. Redis outages are treated as cache misses. */
final class RedisMetadataCache implements CacheInterface
{
    private function key(string $key): string { return 'mcp_metadata:' . hash('sha256', $key); }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = getRedisClient()?->get($this->key($key));
            return is_string($value) ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : $default;
        } catch (Throwable) { return $default; }
    }

    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool
    {
        $seconds = $ttl instanceof DateInterval ? (new DateTimeImmutable())->add($ttl)->getTimestamp() - time() : ($ttl ?? 3600);
        if ($seconds < 1) return $this->delete($key);
        try {
            $redis = getRedisClient();
            if (!$redis) return false;
            $redis->setex($this->key($key), $seconds, json_encode($value, JSON_THROW_ON_ERROR));
            return true;
        } catch (Throwable) { return false; }
    }

    public function delete(string $key): bool
    {
        try { return getRedisClient()?->del([$this->key($key)]) !== null; }
        catch (Throwable) { return false; }
    }

    public function clear(): bool
    {
        // Clearing the shared Redis database would affect unrelated app data.
        return false;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];
        foreach ($keys as $key) $values[$key] = $this->get($key, $default);
        return $values;
    }

    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) $ok = $this->set($key, $value, $ttl) && $ok;
        return $ok;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) $ok = $this->delete($key) && $ok;
        return $ok;
    }

    public function has(string $key): bool { return $this->get($key) !== null; }
}
