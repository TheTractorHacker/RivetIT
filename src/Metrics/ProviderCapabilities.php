<?php

namespace ITFlow\Metrics;

/**
 * What a provider can actually supply, declared as data.
 *
 * This exists so "supplies nothing" is a first-class, fully-functional state
 * rather than an error path. Action1 and Sophos Central expose zero performance
 * data today; a fleet page asking "can this integration give me CPU history?"
 * must get a plain `false`, not an exception, a null dereference, or a chart of
 * zeroes. Empty capabilities are normal, and none() is the way to say so.
 *
 * Two distinct claims are recorded, because conflating them is what produces
 * fake charts:
 *
 *   metricKeys()     - the provider can produce a value for this key at all.
 *   timeSeriesKeys() - the provider can produce a genuine series of historical
 *                      points for this key, each stamped with the moment it was
 *                      actually observed. A metric that is only ever a single
 *                      current reading scraped from a stale vendor cache belongs
 *                      in metricKeys() and NOT in timeSeriesKeys(); plotting it
 *                      as a line manufactures resolution that does not exist.
 *
 * Unknown keys are dropped at construction against MetricRegistry, so a typo in
 * a provider fails loudly at the capability boundary instead of producing rows
 * that never join to device_metric_defs.
 */
final class ProviderCapabilities
{
    /** @var string[] registry keys, unique, in registry order */
    private array $metricKeys;

    /** @var string[] subset of $metricKeys that are true historical series */
    private array $timeSeriesKeys;

    /** Finest interval the vendor can actually resolve, in seconds; null if unknown/not applicable. */
    private ?int $nativeIntervalSeconds;

    /** Human-readable explanation, shown in admin UI when capabilities are thin or empty. */
    private string $notes;

    /**
     * @param string[] $metricKeys     registry keys this provider can supply
     * @param string[] $timeSeriesKeys keys with real history; anything not also in
     *                                 $metricKeys is ignored
     */
    public function __construct(
        array $metricKeys = [],
        array $timeSeriesKeys = [],
        ?int $nativeIntervalSeconds = null,
        string $notes = ''
    ) {
        $this->metricKeys = self::filterToRegistry($metricKeys);

        // A time-series claim is only meaningful for a key we also claim to supply.
        $series = self::filterToRegistry($timeSeriesKeys);
        $this->timeSeriesKeys = array_values(array_intersect($series, $this->metricKeys));

        $this->nativeIntervalSeconds = ($nativeIntervalSeconds !== null && $nativeIntervalSeconds > 0)
            ? $nativeIntervalSeconds
            : null;
        $this->notes = trim($notes);
    }

    /**
     * The explicit "this integration supplies no performance data" state.
     * Not an error, not a degraded mode — a supported configuration.
     */
    public static function none(string $notes = ''): self
    {
        return new self([], [], null, $notes);
    }

    /** @param string[] $metricKeys */
    public static function of(array $metricKeys, string $notes = ''): self
    {
        return new self($metricKeys, [], null, $notes);
    }

    /** Every key of a registry tier, e.g. everything TIER_VENDOR promises. */
    public static function forTier(string $tier, string $notes = ''): self
    {
        return new self(MetricRegistry::keysForTier($tier), [], null, $notes);
    }

    /** @param string[] $keys @return string[] */
    private static function filterToRegistry(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k)) {
                continue;
            }
            if (MetricRegistry::exists($k) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /** @return string[] */
    public function metricKeys(): array
    {
        return $this->metricKeys;
    }

    /** @return string[] */
    public function timeSeriesKeys(): array
    {
        return $this->timeSeriesKeys;
    }

    public function supports(string $metricKey): bool
    {
        return in_array($metricKey, $this->metricKeys, true);
    }

    /** True only for keys with genuine per-observation history behind them. */
    public function isTimeSeries(string $metricKey): bool
    {
        return in_array($metricKey, $this->timeSeriesKeys, true);
    }

    public function isEmpty(): bool
    {
        return $this->metricKeys === [];
    }

    public function count(): int
    {
        return count($this->metricKeys);
    }

    public function nativeIntervalSeconds(): ?int
    {
        return $this->nativeIntervalSeconds;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    /** Keys this provider supplies that belong to the given registry tier. @return string[] */
    public function keysInTier(string $tier): array
    {
        return array_values(array_intersect($this->metricKeys, MetricRegistry::keysForTier($tier)));
    }

    /** Keys the caller wants that this provider can actually deliver. @param string[] $wanted @return string[] */
    public function intersect(array $wanted): array
    {
        return array_values(array_intersect($this->metricKeys, self::filterToRegistry($wanted)));
    }

    /**
     * Union with another declaration — used when one asset is linked to two
     * integrations and the UI needs the combined picture.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            array_merge($this->metricKeys, $other->metricKeys),
            array_merge($this->timeSeriesKeys, $other->timeSeriesKeys),
            $this->pickFinerInterval($other->nativeIntervalSeconds),
            trim($this->notes . ($this->notes !== '' && $other->notes !== '' ? ' ' : '') . $other->notes)
        );
    }

    private function pickFinerInterval(?int $other): ?int
    {
        if ($this->nativeIntervalSeconds === null) {
            return $other;
        }
        if ($other === null) {
            return $this->nativeIntervalSeconds;
        }
        return min($this->nativeIntervalSeconds, $other);
    }

    /** @return array{metric_keys:string[],time_series_keys:string[],native_interval_seconds:?int,notes:string,empty:bool} */
    public function toArray(): array
    {
        return [
            'metric_keys'             => $this->metricKeys,
            'time_series_keys'        => $this->timeSeriesKeys,
            'native_interval_seconds' => $this->nativeIntervalSeconds,
            'notes'                   => $this->notes,
            'empty'                   => $this->isEmpty(),
        ];
    }
}
