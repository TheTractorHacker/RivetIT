<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

use PHPUnit\Framework\TestCase;

if (!KitSupport::has(\RivetCore\Testing\RedisClientProviderConformanceTestCase::class)) {
    /** Placeholder until the pinned RivetCore carries the conformance kit. */
    final class RedisProviderConformanceTest extends TestCase
    {
        public function testKitIsAvailable(): void
        {
            $this->markTestSkipped(KitSupport::MISSING);
        }
    }

    return;
}

use RivetCore\Redis\RedisClientProviderInterface;
use RivetCore\Testing\RedisClientProviderConformanceTestCase;

/** GlobalRedisClientProvider over the throwaway Redis (RIVETCORE_TEST_REDIS_PORT), and over a port nobody listens on. */
final class RedisProviderConformanceTest extends RedisClientProviderConformanceTestCase
{
    protected function provider(): RedisClientProviderInterface
    {
        $this->pointAt((int) getenv('RIVETCORE_TEST_REDIS_PORT'));

        return new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider();
    }

    protected function unreachableProvider(): RedisClientProviderInterface
    {
        $this->pointAt(self::closedPort());

        return new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider();
    }

    /** getRedisClient() resolves RIVETIT_REDIS_* (environment) first; no env file, no saved settings, no password. */
    private function pointAt(int $port): void
    {
        putenv('RIVETIT_REDIS_HOST=127.0.0.1');
        putenv('RIVETIT_REDIS_PORT=' . $port);
        putenv('RIVETIT_REDIS_PASSWORD');
        putenv('RIVETIT_REDIS_DB=0');
        putenv('RIVETIT_REDIS_ENV_FILE=/nonexistent');
        $GLOBALS['mysqli'] = null;
        require_once dirname(__DIR__, 2) . '/includes/redis_functions.php';
    }
}
