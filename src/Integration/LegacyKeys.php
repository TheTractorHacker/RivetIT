<?php

namespace ITFlow\Integration;

/**
 * One-time upgrade bridge from the old API keys (api_keys table) to API keys as integration clients (DB 2.6.204).
 *
 * There is ONE machine credential: a Client ID + secret exchanged for a short-lived token (POST /api/v1/oauth/token). The old X-Api-Key
 * header is NOT authenticated any more (api/v1/index.php answers 401 and points at the token endpoint); nothing here resolves a key string.
 *
 * What is left is the conversion run by the 2.6.204 database update: every api_keys row becomes an ordinary client with the same scopes,
 * department scope, IP allow-list and expiry, and NO secret (an administrator creates one on Administration > API Keys). api_keys and
 * integration_legacy_keys are kept untouched as rollback data; nothing reads them at request time.
 */
final class LegacyKeys
{
    public const MSG_REMOVED = 'The X-Api-Key header is no longer accepted. Authenticate with an API key (Client ID + secret): POST /api/v1/oauth/token with grant_type=client_credentials, then send Authorization: Bearer <access token>.';

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
        $desc = 'Migrated from legacy API key #' . $keyId . '. The old X-Api-Key header no longer works: create a secret for this client and use the token endpoint.';
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
}
