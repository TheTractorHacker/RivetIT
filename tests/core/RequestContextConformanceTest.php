<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

use PHPUnit\Framework\TestCase;

if (!KitSupport::has(\RivetCore\Testing\RequestContextConformanceTestCase::class)) {
    /** Placeholder until the pinned RivetCore carries the conformance kit. */
    final class RequestContextConformanceTest extends TestCase
    {
        public function testKitIsAvailable(): void
        {
            $this->markTestSkipped(KitSupport::MISSING);
        }
    }

    return;
}

use RivetCore\Contracts\RequestContextInterface;
use RivetCore\Testing\RequestContextConformanceTestCase;

/** RivetIT's ServerRequestContext honours the RequestContextInterface contract (never trusts a client X-Request-ID). */
final class RequestContextConformanceTest extends RequestContextConformanceTestCase
{
    protected function context(): RequestContextInterface
    {
        return new \ITFlow\Core\Adapter\Http\ServerRequestContext();
    }
}
