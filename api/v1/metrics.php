<?php
/*
 * Device metrics - read path.
 *
 * Naming note: this subsystem is "Metrics", never "telemetry". `config_telemetry`
 * already exists in this codebase and means anonymous phone-home usage reporting
 * (admin/settings_telemetry.php), so the two must never share URL space.
 *
 * Routes (all GET; the push/ingest route is a separate handler and is not here):
 *   GET /api/v1/metrics/catalog
 *       The registry itself - keys, display names, units, kinds, precision, tier.
 *
 *   GET /api/v1/metrics/devices/{asset_id}?metric=cpu.utilization&from=&to=&resolution=auto
 *       One metric, one series per instance (per volume, per core, per NIC...).
 *
 *   GET /api/v1/metrics/devices/{asset_id}/batch?metrics=cpu.utilization,memory.utilization&from=&to=
 *       Several metrics in one round trip, sharing one window and one grid, so a
 *       device page issues one request instead of one per chart.
 *
 *   GET /api/v1/metrics/devices/{asset_id}/capabilities
 *       Every registry key with a status for this device (available / stale /
 *       unavailable) so the UI can hide cards the device cannot produce, and can
 *       tell "unsupported" apart from "nothing collected yet".
 *
 * TIME: every timestamp in and out of these endpoints is UTC. device_metric_samples
 * .sampled_at is stored in UTC - a deliberate divergence from the app's local-time
 * convention (see MetricIngestService) - so responses carry an explicit `Z` and a
 * bare `from=2026-09-01 00:00:00` is read as UTC, not as server-local time. The
 * display layer converts; this layer does not.
 *
 * PERMISSIONS: gated on module_rmm read (level 1) and scoped with
 * api_client_scope_sql() against assets.asset_client_id, so a client-scoped user
 * cannot read a device outside their scope. An out-of-scope asset returns 404
 * rather than 403, matching assets.php, so the endpoint does not confirm that an
 * asset id exists.
 */

defined('FROM_API') || die();
require_once __DIR__ . '/includes/api_permissions.php';

// Only includes/redis_functions.php pulls in the Composer autoloader today, and
// index.php does require it - but this handler must not silently fatal if that
// ordering ever changes, so the autoloader is ensured explicitly.
if (!class_exists('ITFlow\\Metrics\\MetricRegistry')) {
    $metrics_autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_readable($metrics_autoload)) {
        require_once $metrics_autoload;
    }
}
if (!class_exists('ITFlow\\Metrics\\MetricQueryService')) {
    api_error(500, 'Metrics subsystem is not installed');
}

if ($method !== 'GET') {
    api_error(405, 'Method not allowed');
}

api_require_module_permission($mysqli, intval($api_user_id), 'module_rmm', 1);

/*
 * index.php's shared parser collapses /metrics/devices/5/batch to
 * ($resource='metrics', $sub='devices', $id=5) and drops the 4th segment
 * entirely, so the sub-action is read from $segments directly rather than from
 * $sub. $segments is set by the front controller; the fallback keeps this file
 * from fataling if it is ever required from somewhere else.
 */
$m_segments = (isset($segments) && is_array($segments)) ? array_values($segments) : [];
$m_section  = isset($m_segments[1]) ? (string) $m_segments[1] : '';
$m_action   = isset($m_segments[3]) ? (string) $m_segments[3] : '';

$service = new \ITFlow\Metrics\MetricQueryService(
    $mysqli,
    \ITFlow\Metrics\MetricQueryService::clampMaxPoints(
        isset($_GET['max_points']) ? intval($_GET['max_points']) : \ITFlow\Metrics\MetricQueryService::DEFAULT_MAX_POINTS
    )
);

// ---------------------------------------------------------------- /metrics/catalog

if ($m_section === 'catalog') {
    $catalog = $service->catalog();
    api_response(200, [
        'data'  => $catalog,
        'total' => count($catalog),
    ]);
}

// ---------------------------------------------------------------- /metrics/devices/...

if ($m_section !== 'devices') {
    api_error(404, 'Not found');
}

$m_asset_id = isset($m_segments[2]) && ctype_digit((string) $m_segments[2]) ? intval($m_segments[2]) : 0;
if ($m_asset_id < 1) {
    api_error(400, 'A numeric asset id is required');
}

// Client scope + existence in one query. Out-of-scope looks identical to missing.
$m_scope = api_client_scope_sql('a.asset_client_id');
$m_asset = mysqli_fetch_assoc(mysqli_query($mysqli,
    "SELECT a.asset_id, a.asset_name, a.asset_client_id
     FROM assets a
     WHERE a.asset_id = $m_asset_id
       AND a.asset_archived_at IS NULL
       AND $m_scope
     LIMIT 1"
));
if (!$m_asset) {
    api_error(404, 'Asset not found');
}

$m_asset_meta = [
    'asset_id'   => intval($m_asset['asset_id']),
    'asset_name' => $m_asset['asset_name'],
    'client_id'  => intval($m_asset['asset_client_id']),
];

// ------------------------------------------- /metrics/devices/{id}/capabilities

if ($m_action === 'capabilities') {
    try {
        $caps = $service->capabilities($m_asset_id);
    } catch (\RuntimeException $e) {
        api_error(500, 'Unable to read metric capabilities');
    }
    $caps['asset_name'] = $m_asset_meta['asset_name'];
    $caps['client_id']  = $m_asset_meta['client_id'];
    // config_enable_device_metrics gates COLLECTION, not reading history. It is
    // reported so the UI can explain an empty device rather than only show one.
    // Reported as null when the global settings loader does not expose it yet.
    $caps['collection_enabled'] = isset($config_enable_device_metrics)
        ? (intval($config_enable_device_metrics) === 1)
        : null;

    api_response(200, [
        'data'  => $caps,
        'total' => intval($caps['metric_count'] ?? 0),
    ]);
}

// -------------------------------------------------- window + resolution inputs

$m_now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

$m_to_raw = isset($_GET['to']) ? (string) $_GET['to'] : '';
$m_to = $m_to_raw === ''
    ? $m_now
    : \ITFlow\Metrics\MetricQueryService::parseTime($m_to_raw, $m_now);
if ($m_to === null) {
    api_error(400, 'Could not parse "to". Use an ISO-8601 UTC timestamp, epoch seconds, "now", or an offset like "-24h".');
}

// A relative "from" is measured back from "to", not from now. With
// ?from=-1h&to=2026-09-05T12:00:00Z the caller plainly means the hour ending at
// noon; resolving the offset against wall-clock now instead would place "from"
// after "to" and reject a perfectly reasonable request. With no "to" the two
// references are the same instant anyway, so the common ?from=-24h is unchanged.
$m_from_raw = isset($_GET['from']) ? (string) $_GET['from'] : '';
$m_from = $m_from_raw === ''
    ? $m_to->modify('-24 hours')          // default window: the last 24 hours
    : \ITFlow\Metrics\MetricQueryService::parseTime($m_from_raw, $m_to);
if ($m_from === null) {
    api_error(400, 'Could not parse "from". Use an ISO-8601 UTC timestamp, epoch seconds, "now", or an offset like "-24h".');
}

if ($m_from->getTimestamp() >= $m_to->getTimestamp()) {
    api_error(400, '"from" must be earlier than "to"');
}

$m_resolution = isset($_GET['resolution']) ? strtolower(trim((string) $_GET['resolution'])) : '';
if ($m_resolution === '') {
    $m_resolution = \ITFlow\Metrics\MetricQueryService::RES_AUTO;
}
if ($m_resolution !== \ITFlow\Metrics\MetricQueryService::RES_AUTO
    && !\ITFlow\Metrics\MetricQueryService::isConcreteResolution($m_resolution)) {
    api_error(400, 'Unknown resolution. Use auto, raw, hour or day.');
}

// ---------------------------------------------------- /metrics/devices/{id}/batch

if ($m_action === 'batch') {
    $m_metrics_param = isset($_GET['metrics']) ? (string) $_GET['metrics'] : '';
    $m_keys = array_values(array_filter(array_map('trim', explode(',', $m_metrics_param)), static function ($k) {
        return $k !== '';
    }));
    if ($m_keys === []) {
        api_error(400, 'The "metrics" parameter is required: a comma-separated list of metric keys');
    }

    try {
        $result = $service->queryBatch($m_asset_id, $m_keys, $m_from, $m_to, $m_resolution);
    } catch (\InvalidArgumentException $e) {
        api_error(400, $e->getMessage());
    } catch (\OverflowException $e) {
        // Deliberately a hard rejection, not a truncated payload: a chart drawn
        // from silently-clipped data looks like an outage that never happened.
        api_error(400, $e->getMessage());
    } catch (\RuntimeException $e) {
        api_error(500, 'Unable to read metrics');
    }

    $result['asset_name'] = $m_asset_meta['asset_name'];
    $result['client_id']  = $m_asset_meta['client_id'];

    api_response(200, [
        'data'  => $result,
        'total' => count($result['metrics']),
    ]);
}

if ($m_action !== '') {
    api_error(404, 'Not found');
}

// ---------------------------------------------------------- /metrics/devices/{id}

$m_metric_key = isset($_GET['metric']) ? trim((string) $_GET['metric']) : '';
if ($m_metric_key === '') {
    api_error(400, 'The "metric" parameter is required. GET /api/v1/metrics/catalog lists the valid keys.');
}

try {
    $result = $service->queryMetric($m_asset_id, $m_metric_key, $m_from, $m_to, $m_resolution);
} catch (\InvalidArgumentException $e) {
    api_error(400, $e->getMessage());
} catch (\OverflowException $e) {
    api_error(400, $e->getMessage());
} catch (\RuntimeException $e) {
    api_error(500, 'Unable to read metrics');
}

$result['asset_name'] = $m_asset_meta['asset_name'];
$result['client_id']  = $m_asset_meta['client_id'];

api_response(200, [
    'data'  => $result,
    'total' => count($result['metric']['series']),
]);
