<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use ITFlow\Metrics\MetricIngestService;
use ITFlow\Metrics\MetricSample;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Feeds check-in samples into RivetIT's metrics subsystem (device_metric_samples) exactly as the old check-in code did: each sample
 * becomes a MetricSample (out-of-range values are rejected there, never clamped) and goes through MetricIngestService.
 */
final class EndpointMetricSink implements RmmMetricSinkInterface
{
    public function __construct(private \mysqli $mysqli)
    {
    }

    public function ingest(array $samples, int $integrationId): void
    {
        $out = [];
        foreach ($samples as $s) {
            $m = MetricSample::tryOf((int) $s['asset_id'], (string) $s['key'], $s['instance'], $s['value'], $s['at'], $s['label']);
            if ($m !== null) {
                $out[] = $m;
            }
        }
        if ($out !== []) {
            (new MetricIngestService($this->mysqli))->ingest($out, $integrationId);
        }
    }
}
