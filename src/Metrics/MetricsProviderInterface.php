<?php

namespace ITFlow\Metrics;

/**
 * The contract every RMM vendor adapter implements.
 *
 * A provider is a READ-ONLY view of a vendor. It may not create, update or
 * delete an `assets` row or an `asset_rmm_links` row under any circumstance —
 * RmmAssetMapper (includes/class_rmm_asset_mapper.php) owns those exclusively,
 * and two writers of the same identity mapping is how duplicate assets get
 * born. A provider reads the link table to learn which agent id belongs to
 * which asset, and stops there.
 *
 * A provider also does not touch device_metric_samples. It returns value
 * objects; persistence, instance-id resolution and rollups belong to the
 * writer. The only table a provider is permitted to write is
 * device_metric_collection_state.vendor_cursor_json, and only its own
 * namespaced key inside that JSON blob.
 */
interface MetricsProviderInterface
{
    /**
     * The rmm_integrations.type this provider adapts, e.g. 'tactical_rmm'.
     * Used for logging and for the admin capability matrix.
     */
    public function providerType(): string;

    /** rmm_integrations.id this provider is bound to. */
    public function integrationId(): int;

    /**
     * What this provider can supply, as data. Never throws. An integration with
     * no performance data returns ProviderCapabilities::none() — a supported
     * state, not an error.
     */
    public function capabilities(): ProviderCapabilities;

    /**
     * Pull samples for the given devices.
     *
     * Implementations MUST:
     *  - return only samples strictly newer than $since when $since is given,
     *    so a cycle costs work proportional to new data, not to retention;
     *  - stamp each sample with the moment the value was actually observed by
     *    the vendor, never with now() when a real observation time exists;
     *  - contain per-device failures (record them in errors(), skip that
     *    device, keep going) rather than aborting the whole collection;
     *  - never throw for an ordinary vendor error, an empty fleet, or an
     *    integration that supplies nothing.
     *
     * @param array<int,array{asset_id:int,agent_id:string,hostname:string}> $devices
     * @param \DateTimeImmutable|null $since UTC high-water mark; null means
     *        "first run, take whatever history you can reach"
     *
     * @return MetricSample[] may be empty; order is not significant
     */
    public function collect(array $devices, ?\DateTimeImmutable $since = null): array;

    /**
     * Human-readable failures from the most recent collect() call, newest last.
     * Includes provider-wide failures that belong to no single device, so this
     * is what a human reads in the cron log.
     *
     * @return string[]
     */
    public function errors(): array;

    /**
     * The subset of errors() that can be blamed on one specific asset, keyed by
     * asset id, from the most recent collect() call.
     *
     * This exists because errors() is a flat list of prose and the collector
     * cannot parse an asset out of it. Without the attribution, a device the
     * vendor could not reach records no failure at all: it produced no samples,
     * so there is nothing to write a success from either, and its
     * device_metric_collection_state row keeps a stale last_collected_at with a
     * NULL last_error. The Performance tab then tells the user "no samples have
     * been recorded yet" when the truthful answer is "the collector could not
     * resolve the RMM host" - the tab has a branch for exactly that message and
     * it would otherwise be unreachable.
     *
     * One entry per asset: the FIRST error wins, because a device that fails DNS
     * fails every subsequent call for the same reason and the first message is
     * the root cause rather than a downstream symptom.
     *
     * An asset appearing here is not by itself a failure - a provider may hit a
     * partial error and still return samples for that device. The collector
     * records a failure only for an asset that is listed here AND produced no
     * samples at all.
     *
     * @return array<int,string> asset id => message
     */
    public function deviceErrors(): array;
}
