<?php
// GET /api/v1/agent_update?arch=amd64|arm64&version=1.2.3   (device auth)
//   -> 200 application/octet-stream: the UNSTAMPED agent executable of the hosted release this device is currently offered.
// The device id comes from the credential. Only the release the update manifest offers THIS device right now is served, only for its
// own architecture, and only when the stored file still matches the manifest's SHA-256. Anything else is a generic 404.
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';

use ITFlow\EndpointAgent\Binaries;
use ITFlow\EndpointAgent\Db;
use ITFlow\EndpointAgent\Devices;
use ITFlow\EndpointAgent\Enrollment;
use ITFlow\EndpointAgent\Updates;

ea_guard(static function () {
    ea_require_tls();
    ea_audit_context();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        ea_error(405, 'method_not_allowed', 'Use GET.', ['Allow' => 'GET']);
    }
    $dev = Devices::authenticate(ea_bearer());
    $deviceId = (int) $dev['device_id'];
    ea_device_rate_limit($deviceId, 'update', 20, 60);
    $arch = $_GET['arch'] ?? null;
    $version = $_GET['version'] ?? null;
    if (!is_string($arch) || !isset(Binaries::ARCHS[$arch]) || !is_string($version) || !preg_match(Binaries::VERSION_RE, $version)) {
        ea_error(422, 'invalid', 'arch (amd64 or arm64) and version are required.');
    }
    $rel = Updates::offeredRelease($dev);
    $bin = null;
    if ($rel !== null && $rel['binary_id'] !== null && $rel['version'] === $version && (string) $dev['arch'] === $arch) {
        $bin = Db::one('SELECT * FROM endpoint_agent_binaries WHERE binary_id = ? AND active = 1 AND arch = ? AND version = ?', [(int) $rel['binary_id'], $arch, $version]);
    }
    if ($bin === null || !hash_equals((string) $rel['sha256'], (string) $bin['sha256'])) {
        ea_error(404, 'not_found', 'Not found.');
    }
    Enrollment::audit('Agent Update Downloaded', "Device $deviceId downloaded hosted agent $version ($arch)", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));
    $e = Binaries::stream($bin, 'rivetit-agent-' . $arch . '.exe');
    ea_error(503, 'unavailable', $e);
});
