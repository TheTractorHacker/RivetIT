<?php
/*
 * Test-only facades under the old class names (ITFlow\EndpointAgent\*) over RivetCore\Rmm (rivet/rivet-core).
 *
 * The endpoint agent PHP moved into rivet-core 1.0.0-rc.4 and src/EndpointAgent was deleted. The acceptance suites tests/endpoint_agent_*.php were
 * written against the old static classes (to seed rows, read state and call pure helpers in-process while the HTTP side runs the real bridges);
 * these thin forwards keep those suites running unchanged and make them exercise the Core implementation in-process as well. Nothing in the
 * application loads this file. Loaded by tests/endpoint_agent_lib.php and tests/endpoint_agent_deploy_unit.php.
 */

namespace ITFlow\EndpointAgent;

use RivetCore\Rmm\Binaries\BinaryStore;
use RivetCore\Rmm\Crypto\CanonicalJson;
use RivetCore\Rmm\Installer\InstallerDownload;
use RivetCore\Rmm\Installer\InstallerService;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Mesh\MeshCookie;
use RivetCore\Rmm\Mesh\MeshService;
use RivetCore\Rmm\RmmModule;
use RivetCore\Rmm\RmmProtocol;

/** The module for the global connection (needs includes/rmm_bootstrap.php and a database; the pure helpers below do not). */
function rmm(): RmmModule
{
    require_once dirname(__DIR__, 2) . '/includes/rmm_bootstrap.php';

    return rivetRmmModule($GLOBALS['mysqli']);
}

final class Config
{
    public static function get(bool $fresh = false): array { return rmm()->settings()->get($fresh); }
    public static function set(array $values): void { rmm()->settings()->set($values); }
    public static function enable(): void { rmm()->settings()->enable(); }
    public static function checks(): array { return rmm()->settings()->checks(); }
    public static function signedChecks(): array { return rmm()->settings()->signedChecks(); }
}

final class Enrollment
{
    public static function createToken(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, int $userId): array { return rmm()->enrollment()->createToken($clientId, $locationId, $ring, $ttlHours, $maxUses, $label, $userId); }
    public static function revokeToken(int $tokenId, int $userId): bool { return rmm()->enrollment()->revokeToken($tokenId, $userId); }
    public static function resolvePending(int $deviceId, string $action, ?int $assetId, int $userId): array { return rmm()->enrollment()->resolvePending($deviceId, $action, $assetId, $userId); }
}

final class Devices
{
    public static function find(int $deviceId): ?array { return rmm()->devices()->find($deviceId); }
    public static function status(array $dev, ?array $cfg = null): array { return rmm()->devices()->status($dev, $cfg); }
    public static function setMeshNode(int $deviceId, ?string $nodeId, int $userId, string $source = 'manual'): bool { return rmm()->devices()->setMeshNode($deviceId, $nodeId, $userId, $source); }
    public static function revoke(int $deviceId, string $reason, int $userId): bool { return rmm()->deviceService()->revoke($deviceId, $reason, $userId); }
    public static function rotate(int $deviceId, int $userId): bool { return rmm()->deviceService()->rotate($deviceId, $userId); }
    public static function retire(int $deviceId, int $userId): bool { return rmm()->deviceService()->retire($deviceId, $userId); }
    public static function allowReenroll(int $deviceId, int $userId): bool { return rmm()->deviceService()->allowReenroll($deviceId, $userId); }
    public static function transfer(int $deviceId, int $clientId, int $locationId, int $userId): bool { return rmm()->deviceService()->transfer($deviceId, $clientId, $locationId, $userId); }
}

final class Checkin
{
    public static function handle(array $dev, array $body, string $ip = '127.0.0.1'): array { return rmm()->checkin()->handle($dev, $body, $ip); }
}

final class Jobs
{
    public static function uuid(): string { return JobService::uuid(); }
    public static function sweep(): int { return rmm()->jobs()->sweep(); }
    public static function sanitizeOutput(string $out, int $cap): array { return JobService::sanitizeOutput($out, $cap); }
}

final class Maintenance
{
    public static function run(): array { return rmm()->housekeeping()->run(); }
}

final class Updates
{
    public static function addRelease(string $version, string $url, string $sha256, string $minVersion, string $ring, int $pct, string $notes, int $userId): ?string
    {
        return rmm()->updates()->addRelease($version, $url, $sha256, $minVersion, $ring, $pct, $notes, $userId);
    }
}

final class Binaries
{
    public const ARCHS = RmmProtocol::ARCHS;
    public const VERSION_RE = RmmProtocol::BINARY_VERSION_RE;
    public const DEFAULT_MAX_BYTES = RmmProtocol::BINARY_DEFAULT_MAX_BYTES;

    public static function iniBytes(string $v): int { return BinaryStore::iniBytes($v); }
    public static function human(int $n): string { return BinaryStore::human($n); }
    public static function storageDir(): ?string { return rmm()->binaryStore()->storageDir(); }
    public static function current(string $arch): ?array { return rmm()->updates()->currentBinary($arch); }
    public static function publishRelease(int $binaryId, string $ring, int $pct, string $notes, int $userId): ?string { return rmm()->binaryStore()->publishRelease($binaryId, $ring, $pct, $notes, $userId); }

    /** Pure header validation: no database involved (the store's other collaborators are not touched by inspect()). */
    public static function inspect(string $path, string $arch, ?int $maxBytes = null): array|string
    {
        $store = (new \ReflectionClass(BinaryStore::class))->newInstanceWithoutConstructor();

        return $store->inspect($path, $arch, $maxBytes ?? RmmProtocol::BINARY_DEFAULT_MAX_BYTES);
    }
}

final class Installer
{
    public static function normalizeCa(string $text): array { return InstallerService::normalizeCa($text); }
    public static function psQuote(string $v): string { return InstallerService::psQuote($v); }
    public static function powershellSnippet(string $serverUrl, string $token, string $arch, string $department): string { return InstallerService::powershellSnippet($serverUrl, $token, $arch, $department); }
    public static function slug(string $name): string { return InstallerDownload::slug($name); }
    public static function filename(string $department, string $arch): string { return RmmProtocol::INSTALLER_NAME_PREFIX . InstallerDownload::slug($department) . '-' . ($arch === 'arm64' ? 'arm64' : 'x64') . '.exe'; }
}

final class Mesh
{
    public static function normalizeUrl(string $url): ?string { return MeshService::normalizeUrlWith($url, defined('EA_ALLOW_INSECURE_HTTP') && EA_ALLOW_INSECURE_HTTP === true); }
    public static function validNodeId(string $id): bool { return MeshService::validNodeId($id); }
    public static function newLoginKey(): string { return MeshCookie::newLoginKey(); }
    public static function decodeCookie(string $cookie, string $keyHex): ?array { return MeshCookie::decode($cookie, $keyHex); }
}

final class Signer
{
    public static function canonical(mixed $value): string { return CanonicalJson::encode($value); }
    public static function __callStatic(string $name, array $args): mixed { return \RivetCore\Rmm\Crypto\Signer::$name(...$args); }
}

class_alias(\RivetCore\Rmm\Crypto\Redactor::class, Redactor::class);
class_alias(\RivetCore\Rmm\Installer\InstallerStamp::class, InstallerStamp::class);
