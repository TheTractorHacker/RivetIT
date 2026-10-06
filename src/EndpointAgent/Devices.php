<?php

namespace ITFlow\EndpointAgent;

/** Device lifecycle: authentication, online/offline/stale status, and the administrator operations (revoke, rotate, retire, transfer). */
final class Devices
{
    /**
     * Authenticate a device bearer token. 401 codes: invalid_token (unknown or rotated), revoked, expired.
     *
     * @return array<string,mixed> the device row
     */
    public static function authenticate(?string $authHeader): array
    {
        $token = null;
        if ($authHeader !== null && preg_match('/^Bearer\s+([A-Za-z0-9]{64})$/', $authHeader, $m)) {
            $token = $m[1];
        }
        $hash = $token === null ? '' : hash('sha256', $token);
        $dev = $hash === '' ? null : Db::one('SELECT * FROM endpoint_agent_devices WHERE token_hash = ?', [$hash]);
        // Constant-time confirmation of what the index lookup found.
        if (!$dev || !hash_equals((string) $dev['token_hash'], $hash)) {
            throw new ApiError(401, 'invalid_token', 'Invalid device credential.');
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            throw new ApiError(401, 'revoked', 'This device credential was revoked.');
        }
        if ($dev['token_expires_at'] !== null && strtotime($dev['token_expires_at'] . ' UTC') <= time()) {
            throw new ApiError(401, 'expired', 'This device credential has expired. Re-enroll the agent.');
        }
        if (!Config::enabled()) {
            throw new ApiError(403, 'forbidden', 'The endpoint agent service is disabled.');
        }
        return $dev;
    }

    /** @return array{state:string,last_checkin_at:?string,offline_since:?string,age_s:?int} state is online | offline | stale | never */
    public static function status(array $dev, ?array $cfg = null): array
    {
        $cfg = $cfg ?? Config::get();
        $last = $dev['last_checkin_at'];
        if ($last === null) {
            return ['state' => 'never', 'last_checkin_at' => null, 'offline_since' => null, 'age_s' => null];
        }
        $age = max(0, time() - (int) strtotime($last . ' UTC'));
        $state = $age <= (int) $cfg['offline_after_s'] ? 'online' : ($age <= (int) $cfg['stale_after_s'] ? 'offline' : 'stale');
        return ['state' => $state, 'last_checkin_at' => Db::iso($last), 'offline_since' => $state === 'online' ? null : Db::iso(gmdate('Y-m-d H:i:s', strtotime($last . ' UTC') + (int) $cfg['offline_after_s'])), 'age_s' => $age];
    }

    public static function find(int $deviceId): ?array
    {
        return Db::one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
    }

    public static function revoke(int $deviceId, string $reason, int $userId): bool
    {
        $dev = self::find($deviceId);
        if (!$dev || $dev['revoked_at'] !== null) {
            return false;
        }
        // The credential hash stays so the device is told "revoked" (not just "unknown") on its next call.
        Db::run('UPDATE endpoint_agent_devices SET revoked_at = ?, revoked_reason = ? WHERE device_id = ?', [Db::utcNow(), mb_substr($reason, 0, 100), $deviceId]);
        Jobs::cancelAllQueued($deviceId, 'device_revoked');
        Enrollment::audit('Device Revoked', "Device $deviceId revoked by user $userId: " . mb_substr($reason, 0, 100), (int) $dev['client_id'], (int) $dev['asset_id']);
        return true;
    }

    /** Clear a revocation so the device may enroll again with a fresh enrollment token. */
    public static function allowReenroll(int $deviceId, int $userId): bool
    {
        $dev = self::find($deviceId);
        if (!$dev || ($dev['revoked_at'] === null && $dev['retired_at'] === null)) {
            return false;
        }
        Db::run("UPDATE endpoint_agent_devices SET revoked_at = NULL, revoked_reason = NULL, retired_at = NULL, link_state = IF(link_state = 'rejected', 'pending_approval', link_state), token_hash = '' WHERE device_id = ?", [$deviceId]);
        Enrollment::audit('Device Re-enrollment Allowed', "Device $deviceId may re-enroll (user $userId)", (int) $dev['client_id'], (int) $dev['asset_id']);
        return true;
    }

    /** Invalidate the current credential. The agent gets 401 invalid_token and must re-enroll (a new enrollment token is needed). */
    public static function rotate(int $deviceId, int $userId): bool
    {
        $dev = self::find($deviceId);
        if (!$dev) {
            return false;
        }
        Db::run("UPDATE endpoint_agent_devices SET token_hash = '', token_issued_at = NULL, token_expires_at = NULL WHERE device_id = ?", [$deviceId]);
        Enrollment::audit('Device Credential Rotated', "Device $deviceId credential invalidated by user $userId; re-enrollment required", (int) $dev['client_id'], (int) $dev['asset_id']);
        return true;
    }

    /**
     * Retirement: the credential is revoked, queued jobs are cancelled, monitoring stops (the RMM link is removed and open alerts resolved).
     * The asset is kept. A remote-access agent installed separately (MeshCentral) is NOT touched, see docs/ENDPOINT_AGENT.md.
     */
    public static function retire(int $deviceId, int $userId): bool
    {
        $dev = self::find($deviceId);
        if (!$dev || $dev['retired_at'] !== null) {
            return false;
        }
        Db::run("UPDATE endpoint_agent_devices SET retired_at = ?, revoked_at = COALESCE(revoked_at, ?), revoked_reason = COALESCE(revoked_reason, 'retired') WHERE device_id = ?",
            [Db::utcNow(), Db::utcNow(), $deviceId]);
        Jobs::cancelAllQueued($deviceId, 'device_retired');
        Checks::resolveAllOpen($deviceId, 'device retired');
        Link::drop($deviceId);
        Enrollment::audit('Device Retired', "Device $deviceId retired by user $userId", (int) $dev['client_id'], (int) $dev['asset_id']);
        return true;
    }

    /** Move a device (and its asset) to another department. Open alerts follow it. */
    public static function transfer(int $deviceId, int $clientId, int $locationId, int $userId): bool
    {
        $dev = self::find($deviceId);
        if (!$dev || Db::val('SELECT client_id FROM clients WHERE client_id = ?', [$clientId]) === null) {
            return false;
        }
        Db::run('UPDATE endpoint_agent_devices SET client_id = ?, location_id = ? WHERE device_id = ?', [$clientId, $locationId, $deviceId]);
        Db::run('UPDATE endpoint_agent_jobs SET client_id = ? WHERE device_id = ?', [$clientId, $deviceId]);
        if ($dev['asset_id']) {
            Db::run('UPDATE assets SET asset_client_id = ?, asset_location_id = ? WHERE asset_id = ?', [$clientId, $locationId, $dev['asset_id']]);
            Db::run('UPDATE rmm_alerts SET client_id = ? WHERE asset_id = ? AND integration_id = ?', [$clientId, $dev['asset_id'], Config::integrationId()]);
        }
        Enrollment::audit('Device Transferred', "Device $deviceId moved from department {$dev['client_id']} to $clientId by user $userId", $clientId, (int) $dev['asset_id']);
        return true;
    }

    public static function setRing(int $deviceId, string $ring): bool
    {
        return in_array($ring, ['pilot', 'stable'], true) && Db::run('UPDATE endpoint_agent_devices SET ring = ? WHERE device_id = ?', [$ring, $deviceId]) >= 0;
    }

    /** Set (manual) or clear the MeshCentral node association. Separate from the asset name by design. */
    public static function setMeshNode(int $deviceId, ?string $nodeId, int $userId, string $source = 'manual'): bool
    {
        if ($nodeId === null || $nodeId === '') {
            Db::run('DELETE FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);
            return true;
        }
        if (!Mesh::validNodeId($nodeId)) {
            return false;
        }
        Db::run('INSERT INTO endpoint_agent_mesh_nodes (device_id, mesh_node_id, source, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mesh_node_id = VALUES(mesh_node_id), source = VALUES(source), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$deviceId, $nodeId, $source, Db::utcNow(), $userId]);
        return true;
    }
}
