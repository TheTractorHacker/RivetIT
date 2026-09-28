<?php

namespace ITFlow\Metrics;

/**
 * One normalised metric reading, ready to be written to `device_metric_samples`.
 *
 * Immutable by construction: every field is a readonly-by-convention private
 * property set once in the constructor, and there are no mutators. Providers
 * build these; the writer consumes them. Nothing in between may edit one.
 *
 * TIMEZONE CONTRACT — READ THIS BEFORE TOUCHING $sampledAt
 * -------------------------------------------------------
 * `device_metric_samples.sampled_at` is UTC. That is a deliberate divergence
 * from the rest of RivetIT, which stores local time. It exists because samples
 * arrive from vendor APIs that speak UTC/ISO-8601 with offsets, and because a
 * DST fold would otherwise silently collide two distinct samples onto the same
 * composite primary key and destroy one of them. Every DateTimeImmutable that
 * enters this object is converted to UTC in the constructor, and
 * sampledAtSql() always emits UTC. Anything rendering these values for a human
 * must convert to local time at the display layer, not here.
 *
 * IDENTITY
 * --------
 * There is no fingerprint/hash field and there must never be one. Identity is
 * (asset_id, metric_id, instance_id, sampled_at) — the composite primary key of
 * `device_metric_samples`. Re-inserting the same reading dedupes server-side via
 * INSERT ... ON DUPLICATE KEY UPDATE. Adding a surrogate identity here would
 * create a second, disagreeing definition of "the same sample".
 *
 * INSTANCE KEYS
 * -------------
 * $instanceKey === null means host level, which the schema records as the
 * reserved sentinel instance_id = 0 (a row that deliberately does NOT exist in
 * device_metric_instances). A metric whose registry spec declares a dimension
 * MUST carry a non-empty instance key; a metric with no dimension MUST NOT.
 * of() enforces both directions rather than quietly normalising, because a
 * dimensioned sample that lands on instance 0 overwrites every sibling volume.
 */
final class MetricSample
{
    /** device_metric_instances.instance_key is VARCHAR(96). */
    public const INSTANCE_KEY_MAX_LEN = 96;

    /** device_metric_instances.instance_label is VARCHAR(128). */
    public const INSTANCE_LABEL_MAX_LEN = 128;

    private int $assetId;
    private string $metricKey;
    private ?string $instanceKey;
    private ?string $instanceLabel;
    private float $value;
    private \DateTimeImmutable $sampledAt;

    private function __construct(
        int $assetId,
        string $metricKey,
        ?string $instanceKey,
        ?string $instanceLabel,
        float $value,
        \DateTimeImmutable $sampledAt
    ) {
        $this->assetId       = $assetId;
        $this->metricKey     = $metricKey;
        $this->instanceKey   = $instanceKey;
        $this->instanceLabel = $instanceLabel;
        $this->value         = $value;
        // Normalise to UTC once, here, so no consumer has to remember to.
        $this->sampledAt = $sampledAt->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * Build a validated sample.
     *
     * @param int                $assetId     assets.id (never created or mutated by metrics code)
     * @param string             $metricKey   a key that exists in MetricRegistry
     * @param string|null        $instanceKey null for host level, else the dimension instance
     * @param float|int|string   $value       numeric; validated against the registry range
     * @param \DateTimeImmutable $sampledAt   any timezone; stored as UTC
     * @param string|null        $instanceLabel optional human label for the instance
     *        (device_metric_instances.instance_label); ignored at host level
     *
     * @throws \InvalidArgumentException when the key is unknown, the value is
     *         out of range/non-numeric, or the instance key disagrees with the
     *         metric's declared dimension.
     */
    public static function of(
        int $assetId,
        string $metricKey,
        ?string $instanceKey,
        $value,
        \DateTimeImmutable $sampledAt,
        ?string $instanceLabel = null
    ): self {
        if ($assetId <= 0) {
            throw new \InvalidArgumentException('MetricSample: asset id must be a positive integer');
        }

        $spec = MetricRegistry::spec($metricKey);
        if ($spec === null) {
            throw new \InvalidArgumentException("MetricSample: unknown metric key '$metricKey'");
        }

        // Reject rather than clamp — see MetricRegistry::isValidValue().
        if (!MetricRegistry::isValidValue($metricKey, $value)) {
            $shown = is_scalar($value) ? (string) $value : gettype($value);
            throw new \InvalidArgumentException(
                "MetricSample: value '$shown' is not valid for metric '$metricKey'"
            );
        }

        $instanceKey = self::normaliseInstanceKey($instanceKey);

        if ($spec['dim'] === null && $instanceKey !== null) {
            throw new \InvalidArgumentException(
                "MetricSample: metric '$metricKey' is host level and takes no instance key"
            );
        }
        if ($spec['dim'] !== null && $instanceKey === null) {
            throw new \InvalidArgumentException(
                "MetricSample: metric '$metricKey' has dimension '{$spec['dim']}' and requires an instance key"
            );
        }

        // A label without an instance to hang it on is meaningless.
        $instanceLabel = ($instanceKey === null) ? null : self::normaliseLabel($instanceLabel);

        return new self($assetId, $metricKey, $instanceKey, $instanceLabel, (float) $value, $sampledAt);
    }

    /**
     * Same as of(), but returns null instead of throwing.
     *
     * Collection loops use this: one malformed reading out of a vendor payload
     * must drop that reading, not abort the whole agent's collection.
     *
     * @param float|int|string|null $value
     */
    public static function tryOf(
        int $assetId,
        string $metricKey,
        ?string $instanceKey,
        $value,
        \DateTimeImmutable $sampledAt,
        ?string $instanceLabel = null
    ): ?self {
        try {
            return self::of($assetId, $metricKey, $instanceKey, $value, $sampledAt, $instanceLabel);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /** Trim, collapse whitespace, cap to the column width; '' becomes null. */
    private static function normaliseInstanceKey(?string $instanceKey): ?string
    {
        if ($instanceKey === null) {
            return null;
        }
        $k = trim(preg_replace('/\s+/', ' ', $instanceKey) ?? '');
        if ($k === '') {
            return null;
        }
        if (strlen($k) > self::INSTANCE_KEY_MAX_LEN) {
            $k = substr($k, 0, self::INSTANCE_KEY_MAX_LEN);
        }
        return $k;
    }

    /** Trim and cap to the column width; '' becomes null. */
    private static function normaliseLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }
        $l = trim(preg_replace('/\s+/', ' ', $label) ?? '');
        if ($l === '') {
            return null;
        }
        if (mb_strlen($l) > self::INSTANCE_LABEL_MAX_LEN) {
            $l = mb_substr($l, 0, self::INSTANCE_LABEL_MAX_LEN);
        }
        return $l;
    }

    public function assetId(): int
    {
        return $this->assetId;
    }

    public function metricKey(): string
    {
        return $this->metricKey;
    }

    /** null means host level → the reserved instance_id = 0 sentinel. */
    public function instanceKey(): ?string
    {
        return $this->instanceKey;
    }

    /** Display name for the instance, or null to let the UI fall back to the key. */
    public function instanceLabel(): ?string
    {
        return $this->instanceLabel;
    }

    public function isHostLevel(): bool
    {
        return $this->instanceKey === null;
    }

    /** The registry dimension this sample belongs to, or null at host level. */
    public function dimension(): ?string
    {
        $spec = MetricRegistry::spec($this->metricKey);
        return $spec === null ? null : $spec['dim'];
    }

    public function value(): float
    {
        return $this->value;
    }

    /** Always UTC. */
    public function sampledAt(): \DateTimeImmutable
    {
        return $this->sampledAt;
    }

    /** 'Y-m-d H:i:s' in UTC — the exact literal device_metric_samples.sampled_at expects. */
    public function sampledAtSql(): string
    {
        return $this->sampledAt->format('Y-m-d H:i:s');
    }

    /** @return array{asset_id:int,metric_key:string,instance_key:?string,instance_label:?string,value:float,sampled_at_utc:string} */
    public function toArray(): array
    {
        return [
            'asset_id'       => $this->assetId,
            'metric_key'     => $this->metricKey,
            'instance_key'   => $this->instanceKey,
            'instance_label' => $this->instanceLabel,
            'value'          => $this->value,
            'sampled_at_utc' => $this->sampledAtSql(),
        ];
    }
}
