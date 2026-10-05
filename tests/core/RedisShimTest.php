<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The legacy ITFlow\Redis static API still works, still uses the rivetit: key layout, and still fails open. */
final class RedisShimTest extends TestCase
{
    protected function setUp(): void
    {
        $port = getenv('RIVETCORE_TEST_REDIS_PORT');
        if (!$port) {
            $this->markTestSkipped('RIVETCORE_TEST_REDIS_PORT not set (throwaway Redis required).');
        }
        putenv('RIVETIT_REDIS_HOST=127.0.0.1');
        putenv('RIVETIT_REDIS_PORT=' . $port);
        require_once dirname(__DIR__, 2) . '/includes/redis_functions.php';
        getRedisClient()->flushdb();
    }

    public function testStaticLockIsExclusiveWithLegacyKey(): void
    {
        $a = \ITFlow\Redis\Lock::acquire('shim-job', 30);
        $b = \ITFlow\Redis\Lock::acquire('shim-job', 30);
        $this->assertTrue($a->held());
        $this->assertFalse($b->held());
        $this->assertSame(1, (int) getRedisClient()->exists('rivetit:lock:shim-job'));
        $a->release();
        [$ran, $v] = \ITFlow\Redis\Lock::run('shim-run', 30, fn () => 7);
        $this->assertTrue($ran);
        $this->assertSame(7, $v);
    }

    public function testStaticRateLimitWithLegacyKey(): void
    {
        $this->assertTrue(\ITFlow\Redis\RateLimit::hit('shim', 1, 60)['allowed']);
        $this->assertFalse(\ITFlow\Redis\RateLimit::hit('shim', 1, 60)['allowed']);
        $this->assertSame(1, (int) getRedisClient()->exists('rivetit:rl:shim'));
    }

    public function testRedisSettingsDelegatesStillWork(): void
    {
        $c = getRedisClient();
        $c->set('rivetit:rl:z', '1');
        $this->assertSame(1, \ITFlow\Redis\RedisSettings::groupCounts($c)['rate_limits']);
        $this->assertSame(1, \ITFlow\Redis\RedisSettings::clear($c, 'rate_limits'));
        $this->assertNull(\ITFlow\Redis\RedisSettings::validate('127.0.0.1', 6379, 0, ''));
    }
}
