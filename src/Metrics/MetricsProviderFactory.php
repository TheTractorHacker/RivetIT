<?php

namespace ITFlow\Metrics;

use ITFlow\Metrics\Providers\NullMetricsProvider;
use ITFlow\Metrics\Providers\TacticalMetricsProvider;

/**
 * Turns an rmm_integrations row into a metrics provider.
 *
 * This composes the existing getRmmClient() from includes/rmm_client_factory.php
 * rather than reimplementing vendor HTTP. There is exactly one place in this
 * codebase that knows how to build an authenticated vendor client, how to
 * decrypt an api_key_enc and which class each integration type maps to, and
 * this is not it. Duplicating that logic would mean a second thing to fix the
 * next time a vendor rotates an auth scheme.
 *
 * NOTE ON GLOBALS: getRmmClient() and the client constructors both read
 * `global $mysqli`. A CLI collector must therefore have required the app's
 * database bootstrap (and vendor/autoload.php, which nothing outside
 * includes/redis_functions.php loads for you) before calling this factory. Pass
 * the same mysqli handle in as $mysqli so providers can reach
 * device_metric_collection_state without reaching for a global themselves.
 *
 * Anything that cannot be turned into a working client comes back as a
 * NullMetricsProvider carrying the reason, so the collector loop never has to
 * branch on failure and an operator still gets a legible explanation.
 */
final class MetricsProviderFactory
{
    /** rmm_integrations.type values that have a real metrics implementation today. */
    public const SUPPORTED_TYPES = ['tactical_rmm'];

    /**
     * Types that are known and deliberately unsupported, with the reason shown
     * in the admin capability matrix. These are not failures — Action1 and
     * Sophos Central genuinely publish no device performance data, and Level's
     * support is not built yet.
     */
    private const UNSUPPORTED_REASONS = [
        'level'          => 'Level.io metrics collection is not implemented yet; only asset inventory is synced.',
        'action1'        => 'Action1 exposes no device performance metrics through its API.',
        'sophos_central' => 'Sophos Central manages firewalls here and exposes no device performance metrics.',
    ];

    /** Static-only. */
    private function __construct()
    {
    }

    /**
     * Build a provider from an rmm_integrations row.
     *
     * @param array<string,mixed> $integration at minimum ['id' => int, 'type' => string];
     *                                         'enabled' is honoured when present
     */
    public static function forIntegration(array $integration, ?\mysqli $mysqli = null): MetricsProviderInterface
    {
        $id   = isset($integration['id']) ? (int) $integration['id'] : 0;
        $type = isset($integration['type']) ? strtolower(trim((string) $integration['type'])) : '';

        if ($id <= 0) {
            return new NullMetricsProvider($type !== '' ? $type : 'unknown', 0, 'Integration row has no id.');
        }
        if (array_key_exists('enabled', $integration) && empty($integration['enabled'])) {
            return new NullMetricsProvider($type, $id, "Integration $id is disabled.");
        }
        if (isset(self::UNSUPPORTED_REASONS[$type])) {
            return new NullMetricsProvider($type, $id, self::UNSUPPORTED_REASONS[$type]);
        }
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return new NullMetricsProvider(
                $type !== '' ? $type : 'unknown',
                $id,
                "No metrics provider is registered for integration type '" . ($type !== '' ? $type : 'unknown') . "'."
            );
        }

        // Build the vendor client through the one factory that knows how.
        // getRmmClient() throws when the integration is missing or disabled, and
        // the Tactical constructor throws when the API key will not decrypt.
        try {
            $client = self::buildRmmClient($id);
        } catch (\Throwable $e) {
            return new NullMetricsProvider($type, $id, 'RMM client unavailable: ' . $e->getMessage());
        }

        if (!($client instanceof \TacticalRmmClient)) {
            return new NullMetricsProvider(
                $type,
                $id,
                "Integration $id is typed tactical_rmm but produced " . get_class($client) . '.'
            );
        }

        return new TacticalMetricsProvider($client, $id, $mysqli ?? self::globalMysqli());
    }

    /**
     * Build a provider for an integration id, loading the row first.
     *
     * @throws \RuntimeException never — an unknown id yields a NullMetricsProvider
     */
    public static function forIntegrationId(int $integrationId, \mysqli $mysqli): MetricsProviderInterface
    {
        $row = self::loadIntegration($integrationId, $mysqli);
        if ($row === null) {
            return new NullMetricsProvider('unknown', $integrationId, "Integration $integrationId not found.");
        }
        return self::forIntegration($row, $mysqli);
    }

    /**
     * Every enabled integration, as providers, keyed by rmm_integrations.id.
     *
     * Integrations with nothing to offer are included as NullMetricsProvider so
     * the caller can render a complete, honest capability matrix instead of a
     * list with silent gaps.
     *
     * @return array<int,MetricsProviderInterface>
     */
    public static function allEnabled(\mysqli $mysqli): array
    {
        $providers = [];
        $result = $mysqli->query(
            "SELECT `id`, `type`, `enabled` FROM `rmm_integrations` WHERE `enabled` = 1 ORDER BY `id` ASC"
        );
        if ($result === false) {
            return $providers;
        }
        while ($row = $result->fetch_assoc()) {
            $providers[(int) $row['id']] = self::forIntegration($row, $mysqli);
        }
        $result->free();
        return $providers;
    }

    /**
     * Only the enabled integrations that can actually supply metrics.
     *
     * @return array<int,MetricsProviderInterface>
     */
    public static function collectableEnabled(\mysqli $mysqli): array
    {
        $out = [];
        foreach (self::allEnabled($mysqli) as $id => $provider) {
            if (!$provider->capabilities()->isEmpty()) {
                $out[$id] = $provider;
            }
        }
        return $out;
    }

    /**
     * Declared capability for an integration type, without building a client or
     * touching the network. For settings screens that need to explain what an
     * integration will and will not chart before it is even saved.
     */
    public static function capabilitiesForType(string $type): ProviderCapabilities
    {
        $type = strtolower(trim($type));
        if ($type === TacticalMetricsProvider::PROVIDER_TYPE) {
            // Capabilities are static data; the client is never contacted.
            return TacticalMetricsProvider::declaredCapabilities();
        }
        if (isset(self::UNSUPPORTED_REASONS[$type])) {
            return ProviderCapabilities::none(self::UNSUPPORTED_REASONS[$type]);
        }
        return ProviderCapabilities::none("Unknown integration type '$type'.");
    }

    /** @return array<string,mixed>|null */
    private static function loadIntegration(int $integrationId, \mysqli $mysqli): ?array
    {
        $stmt = $mysqli->prepare(
            "SELECT `id`, `type`, `enabled` FROM `rmm_integrations` WHERE `id` = ? LIMIT 1"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $integrationId);
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $res = $stmt->get_result();
        $row = ($res !== false) ? $res->fetch_assoc() : null;
        $stmt->close();
        return is_array($row) ? $row : null;
    }

    /**
     * Load includes/rmm_client_factory.php on demand and delegate.
     *
     * The include lives outside the PSR-4 tree, so it is required explicitly
     * here — a CLI entry point cannot be assumed to have pulled in the app's
     * include graph.
     */
    private static function buildRmmClient(int $integrationId): object
    {
        if (!function_exists('getRmmClient')) {
            $path = dirname(__DIR__, 2) . '/includes/rmm_client_factory.php';
            if (!is_file($path)) {
                throw new \RuntimeException("rmm_client_factory.php not found at $path");
            }
            require_once $path;
        }
        if (!function_exists('getRmmClient')) {
            throw new \RuntimeException('getRmmClient() was not defined by rmm_client_factory.php');
        }
        return getRmmClient($integrationId);
    }

    /**
     * The app's mysqli handle, when a caller did not pass one. Providers use it
     * only for device_metric_collection_state.vendor_cursor_json; a null handle
     * simply disables the check-list cache rather than breaking collection.
     */
    private static function globalMysqli(): ?\mysqli
    {
        return isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof \mysqli
            ? $GLOBALS['mysqli']
            : null;
    }
}
