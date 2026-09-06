<?php

namespace ITFlow\Metrics\Providers;

use ITFlow\Metrics\MetricsProviderInterface;
use ITFlow\Metrics\ProviderCapabilities;

/**
 * The provider for a vendor that supplies no performance data.
 *
 * Used today for level, action1 and sophos_central, and as the safe result when
 * an integration row cannot be turned into a working client at all (disabled,
 * missing, undecryptable key).
 *
 * This is deliberately a real, fully-functional provider rather than a null
 * check scattered through the collector. Every caller can do
 *
 *     $samples = $provider->collect($devices, $since);
 *
 * without asking whether the provider exists, and gets []. "Supplies nothing"
 * is a supported configuration, so it must not be an exception path — the whole
 * point of ProviderCapabilities::none() is that the fleet UI can render
 * "no metrics available from this integration" from data instead of from a
 * caught error.
 *
 * If construction failed upstream, the reason is carried in $reason and shows
 * up in both capabilities()->notes() and errors(), so an operator sees "API key
 * could not be decrypted" rather than a silent absence of charts.
 */
final class NullMetricsProvider implements MetricsProviderInterface
{
    private string $type;
    private int $integrationId;
    private string $reason;

    /**
     * @param string $type          rmm_integrations.type being stood in for
     * @param int    $integrationId rmm_integrations.id, or 0 when unknown
     * @param string $reason        why there is nothing here; shown to operators
     */
    public function __construct(string $type = 'unknown', int $integrationId = 0, string $reason = '')
    {
        $this->type          = $type !== '' ? $type : 'unknown';
        $this->integrationId = max(0, $integrationId);
        $this->reason        = trim($reason);
    }

    public function providerType(): string
    {
        return $this->type;
    }

    public function integrationId(): int
    {
        return $this->integrationId;
    }

    public function capabilities(): ProviderCapabilities
    {
        $notes = $this->reason !== ''
            ? $this->reason
            : "The {$this->type} integration exposes no device performance data.";
        return ProviderCapabilities::none($notes);
    }

    /**
     * Always []. Signature matches the interface exactly so the collector needs
     * no special case; the parameters are intentionally unused.
     *
     * @param array<int,array{asset_id:int,agent_id:string,hostname:string}> $devices
     * @return \ITFlow\Metrics\MetricSample[]
     */
    public function collect(array $devices, ?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    /**
     * A construction failure is a real error and is reported as one. A vendor
     * that simply has no metrics is not, and reports nothing.
     *
     * @return string[]
     */
    public function errors(): array
    {
        return $this->reason !== '' ? [$this->reason] : [];
    }

    /**
     * Always []. A null provider never collects, so it never fails at a device.
     * Its construction failure, when there is one, is provider-wide and is
     * reported through errors() alone.
     *
     * @return array<int,string>
     */
    public function deviceErrors(): array
    {
        return [];
    }
}
