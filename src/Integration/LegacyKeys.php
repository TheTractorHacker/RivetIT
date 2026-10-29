<?php

namespace ITFlow\Integration;

/**
 * The bridge from the legacy API keys (api_keys table, X-Api-Key header) to integration clients (DB 2.6.204).
 *
 * There is ONE machine-credential system: integration clients. Every legacy key is converted into an integration client
 * ({@see migrate()}), and the same key string keeps authenticating through the X-Api-Key header for a deprecation period
 * ({@see resolve()}): it resolves to the migrated client and from there goes through exactly the scope, department-scope, IP,
 * expiry, rate-limit and audit rules every client goes through. Nothing about a migrated key is evaluated by a second rule set.
 *
 * Storage:
 *   api_keys                  untouched (kept so a rollback is possible); api_keys.api_key_secret is the SHA-256 of the key string,
 *                             which is what the header value is verified against. No key material is copied or logged anywhere.
 *   integration_legacy_keys   one row per migrated key: api_key_id <-> integration client, plus usage (last used, count, last IP).
 *   integration_settings      the deprecation switch: legacy_header_enabled ('1' default, '0' = off) and legacy_header_sunset
 *                             (a YYYY-MM-DD removal date, '' = none). Past the date, or switched off, X-Api-Key answers 401.
 */
final class LegacyKeys
{
    public const SETTING_ENABLED = 'legacy_header_enabled';
    public const SETTING_SUNSET = 'legacy_header_sunset';

    public const MSG_DISABLED = 'The X-Api-Key header is no longer accepted by this server. Authenticate with your integration client: POST /api/v1/oauth/token (grant_type=client_credentials) with your Client ID and secret, then send "Authorization: Bearer <access_token>".';
    public const MSG_NOT_MIGRATED = 'API keys cannot be verified until the pending database update (DB 2.6.204) has been applied by an administrator (Administration > Update > Update Database).';
    public const MSG_NOTICE = 'The X-Api-Key header is deprecated. Switch to your integration client (Client ID + secret, POST /api/v1/oauth/token) before it is switched off.';

    // ---- deprecation switch ------------------------------------------------------------------------------------------------

    /**
     * @return array{enabled:bool, sunset:?string, accepted:bool, reason:string}
     *   accepted is what the router uses: the switch is on AND the removal date (if any) has not been reached. A database that has not
     *   been migrated yet answers accepted = false (fail closed).
     */
    public static function policy(\mysqli $db): array
    {
        try {
            $rows = [];
            $res = $db->query("SELECT setting_key, setting_value FROM integration_settings WHERE setting_key IN ('" . self::SETTING_ENABLED . "', '" . self::SETTING_SUNSET . "')");
            while ($res && ($r = $res->fetch_assoc())) {
                $rows[(string) $r['setting_key']] = (string) $r['setting_value'];
            }
        } catch (\Throwable $e) {
            return ['enabled' => false, 'sunset' => null, 'accepted' => false, 'reason' => 'not_migrated'];
        }
        $enabled = ($rows[self::SETTING_ENABLED] ?? '1') !== '0';
        $sunset = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rows[self::SETTING_SUNSET] ?? '') === 1 ? $rows[self::SETTING_SUNSET] : null;
        $past = $sunset !== null && $sunset <= date('Y-m-d');
        return [
            'enabled' => $enabled,
            'sunset' => $sunset,
            'accepted' => $enabled && !$past,
            'reason' => !$enabled ? 'switched_off' : ($past ? 'sunset_reached' : 'accepted'),
        ];
    }

    public static function setPolicy(\mysqli $db, bool $enabled, ?string $sunset): void
    {
        if ($sunset !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sunset) !== 1 || \DateTimeImmutable::createFromFormat('!Y-m-d', $sunset) === false)) {
            throw new \InvalidArgumentException('The removal date must be YYYY-MM-DD.');
        }
        $st = $db->prepare('INSERT INTO integration_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        foreach ([[self::SETTING_ENABLED, $enabled ? '1' : '0'], [self::SETTING_SUNSET, $sunset ?? '']] as [$k, $v]) {
            $st->bind_param('ss', $k, $v);
            $st->execute();
        }
        $st->close();
    }

    // ---- migration (DB 2.6.204; idempotent) --------------------------------------------------------------------------------

    /**
     * Converts every api_keys row that has no integration client yet. Safe to run any number of times: a key that is already linked is
     * skipped, nothing is deleted or changed in api_keys. Returns the number converted now and the number already converted.
     *
     * @return array{migrated:int, already:int}
     */
    public static function migrate(\mysqli $db): array
    {
        $out = ['migrated' => 0, 'already' => 0];
        $res = $db->query('SELECT k.*, l.lk_client_id FROM api_keys k LEFT JOIN integration_legacy_keys l ON l.lk_api_key_id = k.api_key_id ORDER BY k.api_key_id');
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as $row) {
            if ($row['lk_client_id'] !== null) {
                $out['already']++;
                continue;
            }
            if (self::migrateRow($db, $row) > 0) {
                $out['migrated']++;
            }
        }
        return $out;
    }

    /** Converts one api_keys row (an array as selected from api_keys). Returns the new integration client id, or 0 when it was already linked. */
    public static function migrateRow(\mysqli $db, array $key): int
    {
        require_once dirname(__DIR__, 2) . '/includes/api_key_security.php';
        $keyId = (int) $key['api_key_id'];
        $chk = $db->prepare('SELECT lk_client_id FROM integration_legacy_keys WHERE lk_api_key_id = ?');
        $chk->bind_param('i', $keyId);
        $chk->execute();
        $exists = $chk->get_result()->fetch_assoc();
        $chk->close();
        if ($exists) {
            return 0;
        }

        $scopes = implode(' ', ApiScopes::fromLegacy((string) ($key['api_key_permission'] ?? ''), !empty($key['api_key_allow_delete'])));
        // Department scope, exactly as the legacy router resolved it (rivetitApiKeyClientIds): null = all, [] = nothing (fail closed), list = those.
        $departments = rivetitApiKeyClientIds($db, $keyId, (int) ($key['api_key_client_id'] ?? 0));
        $all = $departments === null;
        // api_key_expire is a DATE compared with NOW(), i.e. the key stopped working at 00:00 of that day. Keep that instant. An unreadable
        // date (0000-00-00) becomes "already expired" rather than "never".
        $expire = (string) ($key['api_key_expire'] ?? '');
        $expiresAt = preg_match('/^\d{4}-\d{2}-\d{2}/', $expire) === 1 && !str_starts_with($expire, '0000') ? substr($expire, 0, 10) . ' 00:00:00' : '1970-01-02 00:00:00';
        $ips = trim((string) ($key['api_key_allowed_ips'] ?? ''));
        $ips = $ips === '' ? null : $ips;
        $name = mb_substr(trim((string) ($key['api_key_name'] ?? '')) !== '' ? (string) $key['api_key_name'] : 'API key #' . $keyId, 0, 150);
        $desc = 'Migrated from legacy API key #' . $keyId . '. It still authenticates through the deprecated X-Api-Key header; create a secret for this client and switch to the token endpoint.';
        $created = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($key['api_key_created_at'] ?? '')) === 1 ? (string) $key['api_key_created_at'] : date('Y-m-d H:i:s');
        $uid = 'ic_' . bin2hex(random_bytes(10));

        $db->begin_transaction();
        try {
            $st = $db->prepare("INSERT INTO integration_clients (client_uid, client_name, client_description, client_scopes, client_allowed_ips, client_all_departments, client_status, client_expires_at, client_created_by_user_id, client_created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'active', ?, 0, ?)");
            $allInt = $all ? 1 : 0;
            $st->bind_param('sssssiss', $uid, $name, $desc, $scopes, $ips, $allInt, $expiresAt, $created);
            $st->execute();
            $cid = (int) $db->insert_id;
            $st->close();
            if (!$all) {
                $st = $db->prepare('INSERT IGNORE INTO integration_client_departments (client_id, department_id) VALUES (?, ?)');
                foreach (array_values(array_unique($departments)) as $d) {
                    if ($d > 0) {
                        $st->bind_param('ii', $cid, $d);
                        $st->execute();
                    }
                }
                $st->close();
            }
            $st = $db->prepare('INSERT INTO integration_legacy_keys (lk_api_key_id, lk_client_id) VALUES (?, ?)');
            $st->bind_param('ii', $keyId, $cid);
            $st->execute();
            $st->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $cid;
    }

    // ---- the X-Api-Key compat input -------------------------------------------------------------------------------------------

    /**
     * Resolves an X-Api-Key value to its migrated integration client.
     *
     * @return array{ok:true, client:array<string,mixed>, scopes:list<string>, key_id:int}|array{ok:false, status:int, reason:string, message:string}
     *   reason is for the audit log only. Unknown, expired, revoked and not-yet-migrated keys all answer the same 401.
     */
    public static function resolve(\mysqli $db, string $rawKey, string $ip): array
    {
        $fail = static fn(int $status, string $reason, string $message = 'Unauthorized'): array => ['ok' => false, 'status' => $status, 'reason' => $reason, 'message' => $message];
        $policy = self::policy($db);
        if (!$policy['accepted']) {
            return $fail(401, 'header_' . $policy['reason'], $policy['reason'] === 'not_migrated' ? self::MSG_NOT_MIGRATED : self::MSG_DISABLED);
        }
        if ($rawKey === '' || strlen($rawKey) > 512) {
            return $fail(401, 'bad_key');
        }
        $hash = hash('sha256', $rawKey);
        try {
            $key = self::keyRowByHash($db, $hash);
            if ($key === null) {
                return $fail(401, 'unknown_key');
            }
            if (!isset($key['lk_client_id'])) {
                // A key row the migration has not seen (created after it ran, or the database was rolled back and forward): convert it now.
                self::migrateRow($db, $key);
                $key = self::keyRowByHash($db, $hash);
            }
        } catch (\Throwable $e) {
            error_log('Legacy key lookup: ' . get_class($e) . ': ' . $e->getMessage());
            return $fail(401, 'lookup_failed');
        }
        if ($key === null || !isset($key['lk_client_id']) || !hash_equals((string) $key['api_key_secret'], $hash)) {
            return $fail(401, 'unknown_key');
        }
        $cid = (int) $key['lk_client_id'];
        $st = $db->prepare("SELECT c.*, (c.client_status = 'active' AND (c.client_expires_at IS NULL OR c.client_expires_at > NOW())) AS client_live
            FROM integration_clients c WHERE c.client_id = ?");
        $st->bind_param('i', $cid);
        $st->execute();
        $client = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$client || (int) $client['client_live'] !== 1) {
            return $fail(401, 'client_inactive');
        }
        if (!IntegrationAuth::ipAllowed((string) ($client['client_allowed_ips'] ?? ''), $ip)) {
            return $fail(403, 'ip_not_allowed', 'This API key is not permitted from your IP address');
        }
        $scopes = array_values(array_intersect(IntegrationAuth::parseScopes((string) $client['client_scopes']), IntegrationAuth::apiScopes()));
        return ['ok' => true, 'client' => $client, 'scopes' => $scopes, 'key_id' => (int) $key['api_key_id']];
    }

    /** @return array<string,mixed>|null the api_keys row (with lk_client_id when linked) whose SHA-256 is $hash */
    private static function keyRowByHash(\mysqli $db, string $hash): ?array
    {
        $st = $db->prepare('SELECT k.*, l.lk_client_id FROM api_keys k LEFT JOIN integration_legacy_keys l ON l.lk_api_key_id = k.api_key_id WHERE k.api_key_secret = ? LIMIT 1');
        $st->bind_param('s', $hash);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    /** Counts one request made with the deprecated header (last used, count, last IP) and writes the audit line. Never records the key. */
    public static function recordUse(\mysqli $db, int $keyId, array $client, string $ip, string $method, string $path): void
    {
        $cid = (int) $client['client_id'];
        $ipv = substr($ip, 0, 45);
        try {
            $st = $db->prepare('UPDATE integration_legacy_keys SET lk_use_count = lk_use_count + 1, lk_last_used_at = NOW(), lk_last_ip = ? WHERE lk_api_key_id = ?');
            $st->bind_param('si', $ipv, $keyId);
            $st->execute();
            $st->close();
            $db->query("UPDATE integration_clients SET client_last_used_at = NOW() WHERE client_id = $cid AND (client_last_used_at IS NULL OR client_last_used_at < NOW() - INTERVAL 60 SECOND)");
        } catch (\Throwable $e) {
            error_log('Legacy key usage: ' . $e->getMessage());
        }
        IntegrationAuth::audit($db, 'Integration API', 'Legacy Key Use', 'Request authenticated with the deprecated X-Api-Key header: client ' . $client['client_uid'] . ' (legacy key #' . $keyId . ') ' . $method . ' ' . substr($path, 0, 200), $cid, $ip);
    }
}
