<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmMetricReaderInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricReaderConformanceTestCase;

/** EndpointMetricSink as the module's metric READER (latest, peak, series) over device_metric_samples and the hour rollups. */
final class EndpointMetricReaderConformanceTest extends RmmMetricReaderConformanceTestCase
{
    protected function store(): RmmMetricSinkInterface&RmmMetricReaderInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointMetricSink(EndpointKit::mysqli());
    }
}
