<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmEventsInterface;
use RivetCore\Testing\RmmEventsConformanceTestCase;

/**
 * EndpointEvents puts rmm.* events on RivetIT's event bus. The bus keeps nothing of its own, so the conformance case checks that every event id is
 * accepted without an exception; the delivery itself (a queued webhook per subscription) is proved in tests/rmm_ui.php over the real check-in.
 */
final class EndpointRmmEventsConformanceTest extends RmmEventsConformanceTestCase
{
    protected function events(): RmmEventsInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointEvents(EndpointKit::mysqli());
    }
}
