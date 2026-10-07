<?php

namespace ITFlow\EndpointAgent;

/**
 * The agent update manifest: staged rollout by ring and percentage, min_version compatibility, never a downgrade, and a
 * per-device "skip the version that just failed" rule fed by the agent's reported update result.
 *
 * The manifest is signed with the instance Ed25519 key over the lowercase hex SHA-256 of the package (ASCII), so an agent that
 * only trusts the key pinned at enrollment can verify a package it downloaded from any URL.
 */
final class Updates
{
    /** @return array<string,string>|null */
    public static function manifestFor(array $dev): ?array
    {
        $best = self::offeredRelease($dev);
        if ($best === null) {
            return null;
        }
        $url = $best['url'];
        if ($best['binary_id'] !== null) {
            // A hosted release: the URL follows the configured service URL (it is served by api/v1/agent_update). No https service
            // URL means nothing safe to offer, so the device is simply not offered it.
            $url = Binaries::updateUrl((string) $best['arch'], (string) $best['version']);
            if ($url === null) {
                return null;
            }
        }
        [$sec] = Config::signingKey();
        return ['version' => $best['version'], 'url' => $url, 'sha256' => $best['sha256'], 'signature' => Signer::sign($best['sha256'], $sec), 'min_version' => $best['min_version']];
    }

    /** The release row this device is currently offered (same rules as the manifest), or null. */
    public static function offeredRelease(array $dev): ?array
    {
        $current = (string) $dev['agent_version'];
        $failed = self::failedVersions($dev);
        $rings = $dev['ring'] === 'pilot' ? ['pilot', 'stable'] : ['stable'];
        $best = null;
        foreach (Db::all('SELECT * FROM endpoint_agent_releases WHERE active = 1 ORDER BY release_id') as $r) {
            if (!in_array($r['ring'], $rings, true)) {
                continue;
            }
            if ($r['arch'] !== '' && $r['arch'] !== (string) $dev['arch']) {
                continue;   // a per-architecture (hosted) release is only for devices of that architecture
            }
            if (version_compare($r['version'], $current, '<=')) {
                continue;   // never offer the same or an older version
            }
            if (version_compare($current, $r['min_version'], '<')) {
                continue;   // too old to jump straight to this one; an intermediate release must come first
            }
            if (in_array($r['version'], $failed, true)) {
                continue;
            }
            if (!self::inRollout((int) $dev['device_id'], $r)) {
                continue;
            }
            if ($best === null || version_compare($r['version'], $best['version'], '>')) {
                $best = $r;
            }
        }
        return $best;
    }

    /** Deterministic per (device, version) bucket 0-99, so raising the percentage only ever adds devices. */
    public static function inRollout(int $deviceId, array $release): bool
    {
        $pct = (int) $release['rollout_pct'];
        if ($pct <= 0) {
            return false;
        }
        return $pct >= 100 || (crc32($deviceId . '|' . $release['version']) % 100) < $pct;
    }

    private static function failedVersions(array $dev): array
    {
        $st = $dev['update_state_json'] ? json_decode((string) $dev['update_state_json'], true) : null;
        return is_array($st['failed_versions'] ?? null) ? $st['failed_versions'] : [];
    }

    /**
     * The agent's own report of its last update attempt (optional check-in field "update_result":
     * {"version":"1.2.3","state":"ok|failed|rolled_back","detail":"..."}). A failed or rolled-back version is not offered to that
     * device again until an administrator clears it (or a newer release exists).
     */
    public static function recordResult(array $dev, $res): void
    {
        if (!is_array($res) || !is_string($res['version'] ?? null) || !in_array($res['state'] ?? '', ['ok', 'failed', 'rolled_back'], true)
            || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/', $res['version'])) {
            return;
        }
        $st = $dev['update_state_json'] ? (json_decode((string) $dev['update_state_json'], true) ?: []) : [];
        $failed = is_array($st['failed_versions'] ?? null) ? $st['failed_versions'] : [];
        if ($res['state'] !== 'ok' && !in_array($res['version'], $failed, true)) {
            $failed[] = $res['version'];
        }
        $st = ['failed_versions' => array_slice($failed, -20), 'last' => ['version' => $res['version'], 'state' => $res['state'],
            'detail' => Enrollment::cleanText($res['detail'] ?? '', 300), 'at' => Db::utcNow()]];
        Db::run('UPDATE endpoint_agent_devices SET update_state_json = ? WHERE device_id = ?', [json_encode($st), $dev['device_id']]);
        if ($res['state'] !== 'ok') {
            Enrollment::audit('Agent Update Failed', "Device {$dev['device_id']} reported update to {$res['version']} as {$res['state']}", (int) $dev['client_id'], (int) $dev['asset_id']);
        }
    }

    public static function clearFailures(int $deviceId): void
    {
        Db::run('UPDATE endpoint_agent_devices SET update_state_json = NULL WHERE device_id = ?', [$deviceId]);
    }

    public static function addRelease(string $version, string $url, string $sha256, string $minVersion, string $ring, int $pct, string $notes, int $userId): ?string
    {
        $v = '/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/';
        if (!preg_match($v, $version) || !preg_match($v, $minVersion)) {
            return 'Versions must look like 1.2.3.';
        }
        if (!preg_match('/^[0-9a-f]{64}$/', strtolower($sha256))) {
            return 'sha256 must be 64 hex characters.';
        }
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($url) > 500) {
            return 'The package URL must be an https address.';
        }
        $svc = parse_url((string) Config::get()['service_url'], PHP_URL_HOST) ?: (string) ($GLOBALS['config_base_url'] ?? '');
        $svc = strtolower(preg_replace('/:\d+$/', '', (string) $svc));
        if ($svc !== '' && strtolower($p['host']) !== $svc) {
            return 'The package must be served from the RivetIT host (' . $svc . ').';
        }
        if (!in_array($ring, ['pilot', 'stable'], true)) {
            return 'Unknown ring.';
        }
        Db::run('INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE url = VALUES(url), sha256 = VALUES(sha256), min_version = VALUES(min_version), rollout_pct = VALUES(rollout_pct), notes = VALUES(notes), active = 1',
            [$version, $url, strtolower($sha256), $minVersion, $ring, max(0, min(100, $pct)), mb_substr($notes, 0, 500), $userId, Db::utcNow()]);
        Enrollment::audit('Agent Release Published', "Release $version ($ring, $pct%) published by user $userId", 0, 0);
        return null;
    }
}
