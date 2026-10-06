<?php

namespace ITFlow\EndpointAgent;

/**
 * Technician-initiated actions, shared by the web handlers (agent/post/rmm_agent.php) and the REST API (api/v1/endpoint_devices.php).
 * Authorization is decided here, server-side, on every call - there is no UI-only gate.
 *
 * Every method returns ['ok'=>bool, 'http'=>int, 'code'=>string, 'message'=>string, ...].
 */
final class Actions
{
    private static function res(bool $ok, int $http, string $code, string $msg, array $extra = []): array
    {
        return array_merge(['ok' => $ok, 'http' => $http, 'code' => $code, 'message' => $msg], $extra);
    }

    /**
     * Role first (no module_rmm view access at all -> 403, which reveals nothing about any device), then department (outside the
     * caller's departments -> the same 404 as a missing device).
     *
     * @return array{0:?array,1:?array} [device, error result]
     */
    private static function deviceFor(int $userId, int $deviceId): array
    {
        $role = Authz::check($userId, Authz::VIEW, 0);
        if ($role !== null) {
            return [null, self::res(false, 403, 'forbidden', $role)];
        }
        $dev = Devices::find($deviceId);
        if (!$dev || Authz::check($userId, Authz::VIEW, (int) $dev['client_id']) !== null) {
            return [null, self::res(false, 404, 'not_found', 'Device not found.')];
        }
        return [$dev, null];
    }

    /** Device visible to this user, or null (the same answer for "missing" and "not your department": no existence oracle). */
    public static function visibleDevice(int $userId, int $deviceId): ?array
    {
        $dev = Devices::find($deviceId);
        if (!$dev || Authz::check($userId, Authz::VIEW, (int) $dev['client_id']) !== null) {
            return null;
        }
        return $dev;
    }

    /**
     * @param array{type?:string,script?:?string,script_id?:?int,params?:array,timeout_s?:?int,destructive?:bool,confirm?:bool} $in
     */
    public static function submitJob(int $userId, string $userName, int $deviceId, array $in): array
    {
        [$dev, $err] = self::deviceFor($userId, $deviceId);
        if ($err !== null) {
            return $err;
        }
        $type = (string) ($in['type'] ?? '');
        $script = isset($in['script']) && is_string($in['script']) ? $in['script'] : null;
        $savedId = isset($in['script_id']) ? (int) $in['script_id'] : 0;
        $destructive = !empty($in['destructive']);

        if ($type === 'powershell' && $savedId > 0) {
            $action = Authz::RUN_SAVED;
        } elseif ($type === 'powershell') {
            $action = Authz::RUN_SCRIPT;
        } elseif ($type === 'reboot') {
            $action = Authz::REBOOT;
        } elseif ($type === 'collect') {
            $action = Authz::RUN_SAVED;
        } else {
            return self::res(false, 422, 'invalid', 'Unknown job type.');
        }
        $denied = Authz::check($userId, $action, (int) $dev['client_id']);
        if ($denied !== null) {
            Enrollment::audit('Job Denied', "User $userId denied $type job on device $deviceId: $denied", (int) $dev['client_id'], (int) $dev['asset_id']);
            return self::res(false, 403, 'forbidden', $denied);
        }
        if ($dev['link_state'] !== 'linked') {
            return self::res(false, 409, 'conflict', 'Only a device linked to an asset can receive jobs. Approve it first.');
        }
        if ($savedId > 0) {
            $row = Db::one("SELECT script_body, script_type FROM rmm_scripts WHERE id = ? AND enabled = 1", [$savedId]);
            if (!$row || strtolower((string) $row['script_type']) !== 'powershell' || trim((string) $row['script_body']) === '') {
                return self::res(false, 422, 'invalid', 'That saved script is not a usable PowerShell script.');
            }
            $script = (string) $row['script_body'];
        }
        if (($type === 'reboot' || $destructive) && empty($in['confirm'])) {
            return self::res(false, 422, 'confirmation_required', 'This job is destructive. Confirm it explicitly.');
        }
        $params = isset($in['params']) && is_array($in['params']) ? $in['params'] : [];
        $r = Jobs::create($dev, $type, $script, $params, isset($in['timeout_s']) ? (int) $in['timeout_s'] : null, $destructive, $userId);
        if (!$r['ok']) {
            return self::res(false, 422, 'invalid', $r['error']);
        }
        Enrollment::audit('Job Submitted', "$userName submitted $type job {$r['job_id']} on device $deviceId" . (($type === 'reboot' || $destructive) ? ' (destructive)' : '')
            . ($type === 'powershell' ? ', script sha256 ' . substr(hash('sha256', (string) $script), 0, 16) : ''), (int) $dev['client_id'], (int) $dev['asset_id']);
        return self::res(true, 201, 'queued', 'Job queued.', ['job_id' => $r['job_id']]);
    }

    public static function cancelJob(int $userId, string $userName, int $deviceId, string $jobId): array
    {
        [$dev, $err] = self::deviceFor($userId, $deviceId);
        if ($err !== null) {
            return $err;
        }
        $denied = Authz::check($userId, Authz::RUN_SAVED, (int) $dev['client_id']);
        if ($denied !== null) {
            return self::res(false, 403, 'forbidden', $denied);
        }
        if (!Jobs::cancel($jobId, $userId)) {
            return self::res(false, 409, 'conflict', 'Only a queued job can be cancelled.');
        }
        Enrollment::audit('Job Cancelled', "$userName cancelled job $jobId on device $deviceId", (int) $dev['client_id'], (int) $dev['asset_id']);
        return self::res(true, 200, 'cancelled', 'Job cancelled.');
    }

    public static function launchRemote(int $userId, string $userName, int $deviceId, bool $force = false): array
    {
        [$dev, $err] = self::deviceFor($userId, $deviceId);
        if ($err !== null) {
            return $err;
        }
        $client = (int) $dev['client_id'];
        $asset = (int) $dev['asset_id'];
        $denied = Authz::check($userId, Authz::REMOTE, $client);
        if ($denied !== null) {
            Enrollment::audit('Remote Denied', "User $userId denied remote session on device $deviceId: $denied", $client, $asset);
            return self::res(false, 403, 'forbidden', $denied);
        }
        $r = Mesh::launch($dev, $userName, $userId, $force);
        if (!$r['ok']) {
            Enrollment::audit('Remote Failed', "$userName remote session on device $deviceId did not start: {$r['code']}", $client, $asset);
            $http = ['unmapped' => 404, 'device_offline' => 409, 'not_configured' => 409, 'device_retired' => 409, 'mesh_unavailable' => 503][$r['code']] ?? 409;
            return self::res(false, $http, $r['code'], $r['message']);
        }
        // The session id is the only identifier stored: the login token and URL are never written anywhere.
        Db::run("INSERT INTO rmm_remote_sessions (asset_id, client_id, user_id, connection_type, connection_url, source_ip, user_agent, created_at) VALUES (?, ?, ?, 'meshcentral', ?, ?, ?, NOW())",
            [$asset, $client, $userId, 'meshcentral:session:' . $r['session_id'], substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 100), substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)]);
        Enrollment::audit('Remote Session', "$userName opened MeshCentral session {$r['session_id']} on device $deviceId" . ($force ? ' (forced while offline)' : ''), $client, $asset);
        return self::res(true, 200, 'ok', 'Opening the remote session.', ['url' => $r['url'], 'session_id' => $r['session_id']]);
    }
}
