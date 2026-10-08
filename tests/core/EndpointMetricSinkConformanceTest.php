<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;

/** EndpointMetricSink into device_metric_samples through MetricIngestService (out-of-range values are rejected there, never clamped). */
final class EndpointMetricSinkConformanceTest extends RmmMetricSinkConformanceTestCase
{
    protected function sink(): RmmMetricSinkInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointMetricSink(EndpointKit::mysqli());
    }

    protected function stored(int $assetId): ?array
    {
        return array_map(static fn (array $r): array => ['key' => (string) $r['metric_key'], 'value' => (float) $r['value']], EndpointKit::db()->fetchAll(
            'SELECT d.metric_key, s.metric_value AS value FROM device_metric_samples s JOIN device_metric_defs d ON d.metric_id = s.metric_id WHERE s.asset_id = ?', [$assetId]));
    }
}
