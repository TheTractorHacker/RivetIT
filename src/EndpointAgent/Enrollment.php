<?php

namespace ITFlow\EndpointAgent;

/**
 * Enrollment tokens, device enrollment and the asset-identity rules.
 *
 * IDENTITY POLICY (documented in docs/ENDPOINT_AGENT.md):
 *  - A device is identified by its server-assigned device_id, which survives reconnects, agent updates, re-enrollment and reinstall.
 *  - Same install_id enrolling again                      -> same device, credential rotated.
 *  - New install_id but same machine_guid or same serial  -> reinstall of the same machine: same device, credential rotated.
 *  - machine_guid and serial both present and both different from the stored device while install_id matches -> 409 conflict.
 *  - Asset linking uses stable identifiers only: serial, then MAC (the existing RmmAssetMapper rules). A hostname match alone is
 *    only ever a suggestion, never a link. Several strong matches, disagreeing matches, a match outside the token's department,
 *    or an asset that another live device already owns are NOT merged: the device waits in pending_approval for an administrator.
 */
final class Enrollment
{
    public const TOKEN_PREFIX = 'rvte1';
    private const JUNK_SERIALS = ['', '0', 'none', 'n/a', 'na', 'unknown', 'default string', 'to be filled by o.e.m.', 'system serial number',
        'serial number', 'not specified', 'not applicable', 'xxxxxxxx', '123456789', '1234567890', 'default'];

    // ------------------------------------------------------------------ enrollment tokens

    /** @return array{token_id:int,token:string} the plaintext token is shown once and never stored */
    public static function createToken(int $clientId, int $locationId, string $ring, int $ttlHours, int $maxUses, string $label, int $userId): array
    {
        $cfg = Config::get();
        $ttlHours = max(1, min($ttlHours, (int) $cfg['enroll_max_ttl_h']));
        $maxUses = max(1, min($maxUses, 5000));
        $ring = in_array($ring, ['pilot', 'stable'], true) ? $ring : 'stable';
        $selector = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(20));
        $id = Db::insert('INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$selector, hash('sha256', $secret), mb_substr($label, 0, 100), $clientId, $locationId, $ring,
                gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600), $maxUses, $userId, Db::utcNow()]);
        return ['token_id' => $id, 'token' => self::TOKEN_PREFIX . '.' . $selector . '.' . $secret];
    }

    public static function revokeToken(int $tokenId, int $userId): bool
    {
        return Db::run('UPDATE endpoint_agent_enrollment_tokens SET revoked_at = ?, revoked_by = ? WHERE token_id = ? AND revoked_at IS NULL',
            [Db::utcNow(), $userId, $tokenId]) > 0;
    }

    // ------------------------------------------------------------------ rate limiting (DB backed, so it works without Redis)

    private const RATE_WINDOW_S = 600;
    private const RATE_MAX_FAILURES = 10;
    private const RATE_MAX_ATTEMPTS = 60;

    private static function ipHash(string $ip): string
    {
        return hash('sha256', 'ea-enroll|' . $ip);
    }

    private static function checkRate(string $ip): void
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::RATE_WINDOW_S);
        $row = Db::one('SELECT COUNT(*) AS total, COALESCE(SUM(success = 0), 0) AS failures FROM endpoint_agent_enroll_attempts WHERE ip_hash = ? AND attempted_at > ?',
            [self::ipHash($ip), $since]);
        if ($row && ((int) $row['failures'] >= self::RATE_MAX_FAILURES || (int) $row['total'] >= self::RATE_MAX_ATTEMPTS)) {
            throw new ApiError(429, 'rate_limited', 'Too many enrollment attempts. Try again later.', ['Retry-After' => (string) self::RATE_WINDOW_S]);
        }
    }

    private static function recordAttempt(string $ip, bool $ok, string $reason, string $selector): void
    {
        Db::run('INSERT INTO endpoint_agent_enroll_attempts (ip_hash, ip_text, success, reason, token_selector, attempted_at) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ipHash($ip), substr($ip, 0, 64), $ok ? 1 : 0, $reason, substr($selector, 0, 12), Db::utcNow()]);
        if (!$ok) {
            self::audit('Enrollment Failed', 'Enrollment attempt rejected (' . $reason . ') from ' . $ip . ($selector !== '' ? ' using token ' . substr($selector, 0, 12) : ''), 0, 0);
        }
    }

    // ------------------------------------------------------------------ the endpoint

    /** @return array<string,mixed> the 201 body */
    public static function enroll(array $body, string $ip): array
    {
        self::checkRate($ip);

        $tokenStr = $body['enrollment_token'] ?? null;
        $dev = $body['device'] ?? null;
        if (!is_string($tokenStr) || !is_array($dev)) {
            self::recordAttempt($ip, false, 'malformed', '');
            throw new ApiError(422, 'invalid', 'enrollment_token and device are required');
        }
        $token = self::authenticateToken($tokenStr, $ip);
        try {
            $d = self::validateDevice($dev);
        } catch (ApiError $e) {
            self::recordAttempt($ip, false, 'invalid_device', $token['token_selector']);
            throw $e;
        }

        global $mysqli;
        $mysqli->begin_transaction();
        try {
            $used = Db::run('UPDATE endpoint_agent_enrollment_tokens SET use_count = use_count + 1, last_used_at = ?
                WHERE token_id = ? AND use_count < max_uses AND revoked_at IS NULL AND expires_at > ?', [Db::utcNow(), $token['token_id'], Db::utcNow()]);
            if ($used !== 1) {
                $mysqli->rollback();
                self::recordAttempt($ip, false, 'exhausted', $token['token_selector']);
                throw new ApiError(403, 'forbidden', 'This enrollment token has no uses left.');
            }
            $result = self::placeDevice($d, $token, $ip);
            $mysqli->commit();
        } catch (ApiError $e) {
            @$mysqli->rollback();
            if ($e->errCode !== 'forbidden' || strpos($e->getMessage(), 'no uses left') === false) {
                self::recordAttempt($ip, false, $e->errCode, $token['token_selector']);
            }
            throw $e;
        } catch (\Throwable $e) {
            @$mysqli->rollback();
            throw $e;
        }

        self::recordAttempt($ip, true, $result['event'], $token['token_selector']);
        self::audit('Enrolled', 'Device ' . $result['device_id'] . ' (' . $d['hostname'] . ') ' . $result['event'] . ' with token ' . $token['token_selector'] . ', state ' . $result['status'],
            (int) $token['client_id'], (int) ($result['matched_asset_id'] ?? 0));

        [, $pub, $kid] = Config::signingKey();
        $cfg = Config::get();
        return [
            'device_id' => $result['device_id'],
            'device_token' => $result['device_token'],
            'check_in_interval_s' => (int) $cfg['check_in_interval_s'],
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => $result['status'],
            'matched_asset_id' => $result['matched_asset_id'],
            'signing_public_key' => $pub,
            'signing_key_id' => $kid,
            'config' => ['checks' => Config::signedChecks(), 'collect_interval_s' => (int) $cfg['collect_interval_s']],
        ];
    }

    /** @return array<string,mixed> the token row; every failure is a generic-enough 401 and is recorded */
    private static function authenticateToken(string $tokenStr, string $ip): array
    {
        $parts = explode('.', $tokenStr);
        $selector = '';
        $row = null;
        $secretOk = false;
        if (count($parts) === 3 && $parts[0] === self::TOKEN_PREFIX && preg_match('/^[0-9a-f]{12}$/', $parts[1]) && preg_match('/^[0-9a-f]{40}$/', $parts[2])) {
            $selector = $parts[1];
            $row = Db::one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = ?', [$selector]);
        }
        // Always compare, even when nothing matched, so a miss costs the same as a near miss.
        $stored = $row['token_hash'] ?? str_repeat('0', 64);
        $given = hash('sha256', $parts[2] ?? '');
        $secretOk = hash_equals($stored, $given);
        if (!$row || !$secretOk) {
            self::recordAttempt($ip, false, 'invalid_token', $selector);
            throw new ApiError(401, 'invalid_token', 'Invalid enrollment token.');
        }
        if ($row['revoked_at'] !== null) {
            self::recordAttempt($ip, false, 'revoked', $selector);
            throw new ApiError(401, 'revoked', 'This enrollment token was revoked.');
        }
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            self::recordAttempt($ip, false, 'expired', $selector);
            throw new ApiError(401, 'expired', 'This enrollment token has expired.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ validation

    public static function normalizeMac(string $mac): ?string
    {
        $m = strtolower(str_replace('-', ':', trim($mac)));
        if (!preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $m) || $m === '00:00:00:00:00:00') {
            return null;
        }
        return $m;
    }

    public static function cleanSerial(?string $s): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);
        return in_array(strtolower($s), self::JUNK_SERIALS, true) ? null : $s;
    }

    public static function cleanText($v, int $max): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v) ?? '';
        $v = trim($v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    /** @return array<string,mixed> */
    public static function validateDevice(array $d): array
    {
        $bad = static fn(string $f) => new ApiError(422, 'invalid', "device.$f is invalid");
        $install = $d['install_id'] ?? null;
        if (!is_string($install) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $install)) {
            throw $bad('install_id');
        }
        $guid = $d['machine_guid'] ?? null;
        if ($guid !== null && (!is_string($guid) || !preg_match('/^[A-Za-z0-9{}-]{1,64}$/', $guid))) {
            throw $bad('machine_guid');
        }
        $host = self::cleanText($d['hostname'] ?? null, 200);
        if ($host === null) {
            throw $bad('hostname');
        }
        // Windows only. EA_ALLOW_NON_WINDOWS (never set in production) lets the agent's Linux test build enroll in the integration harness.
        $osOk = ($d['os'] ?? '') === 'windows' || (defined('EA_ALLOW_NON_WINDOWS') && EA_ALLOW_NON_WINDOWS === true && ($d['os'] ?? '') === 'linux');
        if (!$osOk) {
            throw $bad('os');
        }
        if (!in_array($d['arch'] ?? '', ['amd64', 'arm64'], true)) {
            throw $bad('arch');
        }
        $ver = $d['agent_version'] ?? null;
        if (!is_string($ver) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/', $ver)) {
            throw $bad('agent_version');
        }
        $macsIn = $d['mac_addresses'] ?? [];
        if (!is_array($macsIn) || count($macsIn) > 32) {
            throw $bad('mac_addresses');
        }
        $macs = [];
        foreach ($macsIn as $m) {
            $n = is_string($m) ? self::normalizeMac($m) : null;
            if ($n !== null) {
                $macs[$n] = $n;
            }
        }
        return [
            'install_id' => strtolower($install),
            'machine_guid' => $guid === null ? null : strtolower($guid),
            'hostname' => $host,
            'os_version' => self::cleanText($d['os_version'] ?? '', 200) ?? '',
            'arch' => $d['arch'],
            'serial' => self::cleanSerial(self::cleanText($d['serial'] ?? null, 100)),
            'manufacturer' => self::cleanText($d['manufacturer'] ?? null, 200),
            'model' => self::cleanText($d['model'] ?? null, 200),
            'macs' => array_values($macs),
            'agent_version' => $ver,
        ];
    }

    // ------------------------------------------------------------------ placement

    /** @return array{device_id:int,device_token:string,status:string,matched_asset_id:?int,event:string} */
    private static function placeDevice(array $d, array $token, string $ip): array
    {
        $now = Db::utcNow();
        $newToken = bin2hex(random_bytes(32));
        $hash = hash('sha256', $newToken);
        $expires = gmdate('Y-m-d H:i:s', time() + 365 * 86400);
        $macsJson = json_encode($d['macs']);

        // 1. Same install_id: re-enrollment.
        $dev = Db::one('SELECT * FROM endpoint_agent_devices WHERE install_id = ? FOR UPDATE', [$d['install_id']]);
        $event = 're-enrolled';
        if ($dev) {
            self::refuseIfBlocked($dev);
            if (self::identityConflict($dev, $d)) {
                throw new ApiError(409, 'conflict', 'This install id is already bound to a different machine.');
            }
        } else {
            // 2. Reinstall of a known machine: same machine_guid, else same serial.
            $dev = self::findReinstall($d);
            if ($dev) {
                self::refuseIfBlocked($dev);
                $event = 'reinstalled';
            }
        }

        if ($dev) {
            Db::run('UPDATE endpoint_agent_devices SET install_id = ?, machine_guid = COALESCE(?, machine_guid), hostname = ?, os_version = ?, arch = ?,
                serial = COALESCE(?, serial), manufacturer = COALESCE(?, manufacturer), model = COALESCE(?, model), mac_addresses = ?, agent_version = ?,
                token_hash = ?, token_issued_at = ?, token_expires_at = ?, enroll_count = enroll_count + 1, enrolled_via_token_id = ?, last_ip = ?, last_seq = 0
                WHERE device_id = ?',
                [$d['install_id'], $d['machine_guid'], $d['hostname'], $d['os_version'], $d['arch'], $d['serial'], $d['manufacturer'], $d['model'], $macsJson,
                    $d['agent_version'], $hash, $now, $expires, $token['token_id'], $ip, $dev['device_id']]);
            // A reinstalled agent restarts its sequence numbers; the idempotency window restarts with it.
            Db::run('DELETE FROM endpoint_agent_checkins WHERE device_id = ?', [$dev['device_id']]);
            $deviceId = (int) $dev['device_id'];
            $dev = Db::one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
            if ($dev['link_state'] === 'linked' && $dev['asset_id']) {
                $asset = Db::one('SELECT asset_archived_at FROM assets WHERE asset_id = ?', [$dev['asset_id']]);
                if (!$asset || $asset['asset_archived_at'] !== null) {
                    // The asset was retired or deleted while the device was away: needs a human decision, not a silent relink.
                    Db::run("UPDATE endpoint_agent_devices SET link_state = 'pending_approval', match_reason = 'asset_retired' WHERE device_id = ?", [$deviceId]);
                    $dev['link_state'] = 'pending_approval';
                }
            }
            $status = $dev['link_state'] === 'linked' ? 'linked' : 'pending_approval';
            return ['device_id' => $deviceId, 'device_token' => $newToken, 'status' => $status,
                'matched_asset_id' => $dev['link_state'] === 'linked' ? (int) $dev['asset_id'] : null, 'event' => $event];
        }

        // 3. A brand-new device: decide its asset.
        $match = self::matchAsset($d, (int) $token['client_id']);
        $assetId = null;
        $state = 'pending_approval';
        $status = $match['status'];
        $reason = $match['reason'];
        if ($match['asset_id'] !== null) {
            $assetId = $match['asset_id'];
            $state = 'linked';
            $status = 'linked';
        } elseif ($match['status'] === 'pending_approval' && $match['reason'] === 'no_match' && Config::get()['unmatched_policy'] === 'auto_create') {
            $assetId = self::createAsset($d, (int) $token['client_id'], (int) $token['location_id']);
            $state = 'linked';
            $status = 'linked';
            $reason = 'auto_created';
        }
        $deviceId = Db::insert('INSERT INTO endpoint_agent_devices (install_id, machine_guid, hostname, os, os_version, arch, serial, manufacturer, model, mac_addresses,
            agent_version, asset_id, client_id, location_id, ring, link_state, match_reason, match_candidates_json, token_hash, token_issued_at, token_expires_at,
            enrolled_via_token_id, first_seen_at, last_ip, created_at) VALUES (?, ?, ?, \'windows\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['install_id'], $d['machine_guid'], $d['hostname'], $d['os_version'], $d['arch'], $d['serial'], $d['manufacturer'], $d['model'], $macsJson,
                $d['agent_version'], $assetId, (int) $token['client_id'], (int) $token['location_id'], $token['ring'], $state, $reason,
                $match['candidates'] ? json_encode($match['candidates']) : null, $hash, $now, $expires, $token['token_id'], $now, $ip, $now]);
        if ($assetId !== null) {
            Link::ensure($deviceId, $assetId);
        }
        return ['device_id' => $deviceId, 'device_token' => $newToken, 'status' => $status, 'matched_asset_id' => $assetId, 'event' => 'enrolled'];
    }

    private static function refuseIfBlocked(array $dev): void
    {
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            throw new ApiError(403, 'forbidden', 'This device was ' . ($dev['revoked_at'] !== null ? 'revoked' : 'retired')
                . '. An administrator must allow re-enrollment first.');
        }
    }

    private static function identityConflict(array $stored, array $d): bool
    {
        if ($stored['machine_guid'] !== null && $d['machine_guid'] !== null && $stored['machine_guid'] !== $d['machine_guid']) {
            return true;
        }
        if ($stored['serial'] !== null && $d['serial'] !== null && strcasecmp($stored['serial'], $d['serial']) !== 0
            && ($stored['machine_guid'] === null || $d['machine_guid'] === null)) {
            return true;
        }
        return false;
    }

    private static function findReinstall(array $d): ?array
    {
        if ($d['machine_guid'] !== null) {
            $row = Db::one('SELECT * FROM endpoint_agent_devices WHERE machine_guid = ? ORDER BY device_id LIMIT 1 FOR UPDATE', [$d['machine_guid']]);
            if ($row) {
                return $row;
            }
        }
        if ($d['serial'] !== null) {
            // Windows reinstalls generate a new MachineGuid but keep the BIOS serial; a different non-null guid on the stored row
            // is still the same box, since the serial matches.
            $row = Db::one('SELECT * FROM endpoint_agent_devices WHERE serial = ? ORDER BY device_id LIMIT 1 FOR UPDATE', [$d['serial']]);
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ asset matching

    /**
     * @return array{asset_id:?int,status:string,reason:string,candidates:list<array<string,mixed>>}
     *         asset_id is set only for ONE unambiguous, in-scope, unowned strong match.
     */
    public static function matchAsset(array $d, int $clientId): array
    {
        $found = [];   // asset_id => ['asset'=>row, 'by'=>[...]]
        if ($d['serial'] !== null) {
            foreach (Db::all('SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE asset_serial = ? AND asset_archived_at IS NULL LIMIT 10', [$d['serial']]) as $a) {
                $found[$a['asset_id']]['asset'] = $a;
                $found[$a['asset_id']]['by'][] = 'serial';
            }
        }
        if ($d['macs']) {
            $in = implode(',', array_fill(0, count($d['macs']), '?'));
            foreach (Db::all("SELECT DISTINCT a.asset_id, a.asset_name, a.asset_client_id, a.asset_serial FROM assets a JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id
                WHERE LOWER(REPLACE(ai.interface_mac, '-', ':')) IN ($in) AND a.asset_archived_at IS NULL LIMIT 20", $d['macs']) as $a) {
                $found[$a['asset_id']]['asset'] = $a;
                $found[$a['asset_id']]['by'][] = 'mac';
            }
        }
        $cands = [];
        foreach ($found as $aid => $f) {
            $owner = Db::one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id = ? AND link_state = 'linked' AND revoked_at IS NULL AND retired_at IS NULL LIMIT 1", [$aid]);
            $cands[] = ['asset_id' => (int) $aid, 'asset_name' => $f['asset']['asset_name'], 'client_id' => (int) $f['asset']['asset_client_id'],
                'serial' => $f['asset']['asset_serial'], 'matched_by' => array_values(array_unique($f['by'])),
                'in_scope' => (int) $f['asset']['asset_client_id'] === $clientId, 'owned_by_device_id' => $owner ? (int) $owner['device_id'] : null];
        }
        if ($cands) {
            $inScope = array_values(array_filter($cands, static fn($c) => $c['in_scope']));
            if (count($cands) === 1 && $inScope && $inScope[0]['owned_by_device_id'] === null) {
                return ['asset_id' => $inScope[0]['asset_id'], 'status' => 'linked', 'reason' => 'matched_' . implode('_', $inScope[0]['matched_by']), 'candidates' => $cands];
            }
            if (count($cands) > 1) {
                return ['asset_id' => null, 'status' => 'ambiguous', 'reason' => 'ambiguous', 'candidates' => $cands];
            }
            if (!$inScope) {
                return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'scope_mismatch', 'candidates' => $cands];
            }
            return ['asset_id' => null, 'status' => 'ambiguous', 'reason' => 'asset_already_linked', 'candidates' => $cands];
        }
        // Hostname alone is a hint, never a link.
        $hints = [];
        foreach (Db::all('SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE LOWER(asset_name) = LOWER(?) AND asset_archived_at IS NULL LIMIT 10', [$d['hostname']]) as $a) {
            $hints[] = ['asset_id' => (int) $a['asset_id'], 'asset_name' => $a['asset_name'], 'client_id' => (int) $a['asset_client_id'], 'serial' => $a['asset_serial'],
                'matched_by' => ['hostname'], 'in_scope' => (int) $a['asset_client_id'] === $clientId, 'owned_by_device_id' => null];
        }
        if ($hints) {
            return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'hostname_only', 'candidates' => $hints];
        }
        return ['asset_id' => null, 'status' => 'pending_approval', 'reason' => 'no_match', 'candidates' => []];
    }

    public static function createAsset(array $d, int $clientId, int $locationId): int
    {
        $isServer = stripos((string) $d['os_version'], 'server') !== false;
        $id = Db::insert("INSERT INTO assets (asset_type, asset_name, asset_make, asset_model, asset_serial, asset_os, asset_status, asset_client_id, asset_location_id, asset_created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?, NOW())",
            [$isServer ? 'Server' : 'Laptop', $d['hostname'], (string) ($d['manufacturer'] ?? ''), $d['model'], $d['serial'], 'Windows ' . $d['os_version'], $clientId, $locationId]);
        return $id;
    }

    // ------------------------------------------------------------------ admin decisions on pending devices

    /**
     * @param string $action link | create_asset | reject
     * @return array{ok:bool,message:string}
     */
    public static function resolvePending(int $deviceId, string $action, ?int $assetId, int $userId): array
    {
        $dev = Db::one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        if (!$dev || $dev['link_state'] !== 'pending_approval') {
            return ['ok' => false, 'message' => 'That device is not waiting for approval.'];
        }
        if ($action === 'reject') {
            Db::run("UPDATE endpoint_agent_devices SET revoked_at = ?, revoked_reason = 'rejected at approval', link_state = 'rejected', token_hash = '' WHERE device_id = ?", [Db::utcNow(), $deviceId]);
            self::audit('Rejected', "Pending device $deviceId rejected by user $userId", (int) $dev['client_id'], 0);
            return ['ok' => true, 'message' => 'Device rejected and its credential revoked.'];
        }
        if ($action === 'create_asset') {
            $asset = self::createAsset(self::deviceRowToD($dev), (int) $dev['client_id'], (int) $dev['location_id']);
        } elseif ($action === 'link' && $assetId) {
            $row = Db::one('SELECT asset_id, asset_client_id, asset_archived_at FROM assets WHERE asset_id = ?', [$assetId]);
            if (!$row || $row['asset_archived_at'] !== null) {
                return ['ok' => false, 'message' => 'That asset does not exist or is archived.'];
            }
            $owner = Db::one("SELECT device_id FROM endpoint_agent_devices WHERE asset_id = ? AND link_state = 'linked' AND device_id <> ? AND revoked_at IS NULL AND retired_at IS NULL", [$assetId, $deviceId]);
            if ($owner) {
                return ['ok' => false, 'message' => 'That asset already belongs to device ' . (int) $owner['device_id'] . '. Retire that device first.'];
            }
            $asset = (int) $assetId;
            if ((int) $row['asset_client_id'] !== (int) $dev['client_id']) {
                // An explicit administrator choice may cross departments; the device follows the asset's department.
                Db::run('UPDATE endpoint_agent_devices SET client_id = ? WHERE device_id = ?', [(int) $row['asset_client_id'], $deviceId]);
            }
        } else {
            return ['ok' => false, 'message' => 'Unknown action.'];
        }
        Db::run("UPDATE endpoint_agent_devices SET asset_id = ?, link_state = 'linked', match_reason = ? WHERE device_id = ?", [$asset, 'approved_by_' . $userId, $deviceId]);
        Link::ensure($deviceId, $asset);
        self::audit('Approved', "Device $deviceId linked to asset $asset by user $userId ($action)", (int) $dev['client_id'], $asset);
        return ['ok' => true, 'message' => 'Device linked to asset #' . $asset . '.'];
    }

    private static function deviceRowToD(array $dev): array
    {
        return ['hostname' => $dev['hostname'], 'os_version' => $dev['os_version'], 'manufacturer' => $dev['manufacturer'], 'model' => $dev['model'], 'serial' => $dev['serial']];
    }

    public static function audit(string $action, string $description, int $clientId, int $entityId): void
    {
        if (function_exists('logAction')) {
            logAction('Endpoint Agent', $action, $description, $clientId, $entityId);
        }
    }
}
