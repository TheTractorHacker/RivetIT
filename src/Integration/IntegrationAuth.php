<?php

namespace ITFlow\Integration;

/**
 * Machine credentials for the LMS API (integration clients, issue #52).
 *
 * A client authenticates with client_id + client_secret at POST /api/v1/oauth/token (grant client_credentials) and receives a signed,
 * 10 minute access token (HS256, claims iss/aud/sub/scope/jti/exp). Every request re-validates the token against the database:
 * signature, issuer, audience, expiry, the jti row, the client (active, not expired, IP allow-list), the issuing secret (still active)
 * and the scope - revoking a client or secret therefore takes effect at the next request. An integration token is NOT a user session and
 * is never accepted outside /api/v1/lms (api/v1/index.php enforces that before any other routing).
 *
 * Secrets are random 256-bit values shown once and stored with password_hash(); a client holds at most two active secrets so a rotation
 * has an overlap window. Nothing here ever logs or returns a secret or the signing key.
 */
final class IntegrationAuth
{
    public const SCOPES = ['lms:read', 'lms:assign', 'lms:completions:read', 'lms:evidence:read', 'directory:read', 'quality:events'];
    public const TOKEN_TTL = 600;
    public const MAX_ACTIVE_SECRETS = 2;
    /** The encryptSetting() context of settings.config_integration_signing_key. Frozen (changing it makes the stored key unreadable); ColumnRegistry uses it to re-wrap the column on a key rotation. */
    public const KEY_CONTEXT = 'integration_token_key';

    // ---- tokens ---------------------------------------------------------------------------------------------------

    public static function issuer(): string
    {
        $host = (string) preg_replace('#^https?://#i', '', trim((string) ($GLOBALS['config_base_url'] ?? '')));
        return 'https://' . rtrim($host, '/');
    }

    public static function audience(): string
    {
        return self::issuer() . '/api/v1';
    }

    public static function b64uEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): ?string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $s) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $raw === false ? null : $raw;
    }

    /** True for anything shaped like a JWT (three dot-separated base64url segments). api_tokens values are plain hex and never match. */
    public static function looksLikeToken(string $bearer): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{10,600}\.[A-Za-z0-9_-]{10,2000}\.[A-Za-z0-9_-]{20,200}$/D', $bearer) === 1;
    }

    /** The HMAC key (hex), generated on first use and stored through encryptSetting(). Never logged. */
    public static function signingKey(\mysqli $db): string
    {
        $row = self::one($db, 'SELECT config_integration_signing_key AS k FROM settings WHERE company_id = 1', '', []);
        $stored = (string) ($row['k'] ?? '');
        if ($stored !== '') {
            $key = decryptSetting($stored, self::KEY_CONTEXT);
            if ($key !== '') {
                return $key;
            }
        }
        $key = bin2hex(random_bytes(32));
        $enc = encryptSetting($key, self::KEY_CONTEXT);
        // Only fills an empty slot, so two first requests racing agree on one key.
        $st = $db->prepare("UPDATE settings SET config_integration_signing_key = ? WHERE company_id = 1 AND (config_integration_signing_key IS NULL OR config_integration_signing_key = '')");
        $st->bind_param('s', $enc);
        $st->execute();
        $st->close();
        $row = self::one($db, 'SELECT config_integration_signing_key AS k FROM settings WHERE company_id = 1', '', []);
        $key = decryptSetting((string) ($row['k'] ?? ''), self::KEY_CONTEXT);
        if ($key === '') {
            throw new \RuntimeException('Integration signing key unavailable');
        }
        return $key;
    }

    public static function sign(array $claims, string $key): string
    {
        $h = self::b64uEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64uEncode(json_encode($claims));
        return $h . '.' . $p . '.' . self::b64uEncode(hash_hmac('sha256', "$h.$p", $key, true));
    }

    /** Claims of a token with a valid HS256 signature, or null. The algorithm is pinned: "none" and every other alg are refused. */
    public static function verifySignature(string $jwt, string $key): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $header = self::b64uDecode($parts[0]);
        $header = $header === null ? null : json_decode($header, true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $sig = self::b64uDecode($parts[2]);
        if ($sig === null || !hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $key, true), $sig)) {
            return null;
        }
        $payload = self::b64uDecode($parts[1]);
        $claims = $payload === null ? null : json_decode($payload, true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * Issues a token for an authenticated client and records its jti.
     * @param list<string> $scopes already intersected with the client's grant
     * @return array{access_token:string, token_type:string, expires_in:int, scope:string}
     */
    public static function issueToken(\mysqli $db, array $client, int $secretId, array $scopes, string $ip): array
    {
        $now = time();
        $jti = bin2hex(random_bytes(16));
        $scope = implode(' ', $scopes);
        $token = self::sign([
            'iss' => self::issuer(),
            'aud' => self::audience(),
            'sub' => (string) $client['client_uid'],
            'scope' => $scope,
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL,
        ], self::signingKey($db));
        $ttl = self::TOKEN_TTL;
        $cid = (int) $client['client_id'];
        $ip = substr($ip, 0, 45);
        $st = $db->prepare('INSERT INTO integration_tokens (token_jti, token_client_id, token_secret_id, token_scope, token_ip, token_expires_at)
            VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)');
        $st->bind_param('siissi', $jti, $cid, $secretId, $scope, $ip, $ttl);
        $st->execute();
        $st->close();
        // Housekeeping: expired jti rows and idempotency records are useless after a day.
        $db->query('DELETE FROM integration_tokens WHERE token_expires_at < NOW() - INTERVAL 1 DAY');
        $db->query('DELETE FROM api_idempotency WHERE idem_expires_at < NOW()');
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => $scope];
    }

    /**
     * Validates a Bearer value for a request. Returns ['ok' => true, 'client' => row, 'scopes' => list] or ['ok' => false, 'reason' => string].
     * The reason is for the audit log only; callers answer every failure with the same 401.
     */
    public static function validateBearer(\mysqli $db, string $bearer, string $ip): array
    {
        $fail = static fn(string $why): array => ['ok' => false, 'reason' => $why];
        $claims = self::verifySignature($bearer, self::signingKey($db));
        if ($claims === null) {
            return $fail('bad_signature');
        }
        if (($claims['iss'] ?? null) !== self::issuer()) {
            return $fail('bad_issuer');
        }
        if (($claims['aud'] ?? null) !== self::audience()) {
            return $fail('bad_audience');
        }
        if (!isset($claims['exp']) || !is_int($claims['exp']) || $claims['exp'] <= time()) {
            return $fail('expired');
        }
        $jti = (string) ($claims['jti'] ?? '');
        $sub = (string) ($claims['sub'] ?? '');
        if (preg_match('/^[0-9a-f]{32}$/D', $jti) !== 1 || $sub === '') {
            return $fail('bad_claims');
        }
        $row = self::one($db, 'SELECT c.*, t.token_secret_id, t.token_scope,
                (t.token_expires_at > NOW()) AS tok_live,
                (c.client_status = \'active\' AND (c.client_expires_at IS NULL OR c.client_expires_at > NOW())) AS client_live,
                (SELECT COUNT(*) FROM integration_client_secrets s WHERE s.secret_id = t.token_secret_id AND s.secret_status = \'active\'
                    AND (s.secret_expires_at IS NULL OR s.secret_expires_at > NOW())) AS secret_live
            FROM integration_tokens t JOIN integration_clients c ON c.client_id = t.token_client_id
            WHERE t.token_jti = ?', 's', [$jti]);
        if ($row === null || !hash_equals((string) $row['client_uid'], $sub)) {
            return $fail('unknown_token');
        }
        if ((int) $row['tok_live'] !== 1) {
            return $fail('expired');
        }
        if ((int) $row['client_live'] !== 1) {
            return $fail('client_inactive');
        }
        if ((int) $row['secret_live'] !== 1) {
            return $fail('secret_revoked');
        }
        if (!self::ipAllowed((string) ($row['client_allowed_ips'] ?? ''), $ip)) {
            return $fail('ip_not_allowed');
        }
        // Effective scopes: what the token carries AND what the client is still granted (narrowing a client's scopes bites immediately).
        $granted = self::parseScopes((string) $row['client_scopes']);
        $carried = self::parseScopes((string) ($claims['scope'] ?? ''));
        $scopes = array_values(array_intersect($carried, $granted));
        $cid = (int) $row['client_id'];
        $db->query("UPDATE integration_clients SET client_last_used_at = NOW() WHERE client_id = $cid AND (client_last_used_at IS NULL OR client_last_used_at < NOW() - INTERVAL 60 SECOND)");
        return ['ok' => true, 'client' => $row, 'scopes' => $scopes];
    }

    // ---- scopes / ip ---------------------------------------------------------------------------------------------------

    /** @return list<string> only known scopes, de-duplicated; unknown entries are dropped (deny by default, no wildcard) */
    public static function parseScopes(string $s): array
    {
        $out = [];
        foreach (preg_split('/[\s,]+/', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $one) {
            if (in_array($one, self::SCOPES, true)) {
                $out[$one] = $one;
            }
        }
        return array_values($out);
    }

    public static function ipAllowed(string $allowlist, string $ip): bool
    {
        require_once dirname(__DIR__, 2) . '/includes/api_key_security.php';
        return rivetitApiKeyIpAllowed($allowlist, $ip);
    }

    // ---- client / secret management (admin page) ----------------------------------------------------------------------

    public static function newSecret(): string
    {
        return 'rvs_' . self::b64uEncode(random_bytes(32));
    }

    /**
     * Department scope is a required, explicit choice (no default): $allDepartments = true, or the clients.client_id list of departments
     * ($allDepartments = false with [] is a client that sees nothing). See {@see IntegrationScope}.
     *
     * @param list<string> $scopes
     * @param list<int> $departmentIds
     * @return array{client_id:int, client_uid:string, secret:string} the secret is returned once
     */
    public static function createClient(\mysqli $db, string $name, ?string $description, array $scopes, string $allowedIps, ?string $expiresAt, int $userId, bool $allDepartments, array $departmentIds): array
    {
        $uid = 'ic_' . bin2hex(random_bytes(10));
        $scope = implode(' ', self::parseScopes(implode(' ', $scopes)));
        $ips = trim($allowedIps) === '' ? null : $allowedIps;
        $st = $db->prepare('INSERT INTO integration_clients (client_uid, client_name, client_description, client_scopes, client_allowed_ips, client_expires_at, client_created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->bind_param('ssssssi', $uid, $name, $description, $scope, $ips, $expiresAt, $userId);
        $st->execute();
        $cid = (int) $db->insert_id;
        $st->close();
        IntegrationScope::save($db, $cid, $allDepartments, $departmentIds);
        $secret = self::addSecret($db, $cid);
        return ['client_id' => $cid, 'client_uid' => $uid, 'secret' => $secret];
    }

    /** Adds an active secret (caller checked the cap). Returns the plaintext once. */
    public static function addSecret(\mysqli $db, int $clientId): string
    {
        $secret = self::newSecret();
        $hash = password_hash($secret, PASSWORD_DEFAULT);
        $st = $db->prepare('INSERT INTO integration_client_secrets (secret_client_id, secret_hash) VALUES (?, ?)');
        $st->bind_param('is', $clientId, $hash);
        $st->execute();
        $st->close();
        return $secret;
    }

    public static function activeSecretCount(\mysqli $db, int $clientId): int
    {
        $r = self::one($db, "SELECT COUNT(*) AS n FROM integration_client_secrets WHERE secret_client_id = ? AND secret_status = 'active'
            AND (secret_expires_at IS NULL OR secret_expires_at > NOW())", 'i', [$clientId]);
        return (int) ($r['n'] ?? 0);
    }

    /**
     * Rotation with overlap: adds a second secret and lets the current ones expire after $overlapHours.
     * Refuses when the client already holds two active secrets. Returns the new plaintext once, or null when refused.
     */
    public static function rotateSecret(\mysqli $db, int $clientId, int $overlapHours): ?string
    {
        if (self::activeSecretCount($db, $clientId) >= self::MAX_ACTIVE_SECRETS) {
            return null;
        }
        $hours = max(1, min(24 * 30, $overlapHours));
        $st = $db->prepare("UPDATE integration_client_secrets SET secret_expires_at = NOW() + INTERVAL ? HOUR
            WHERE secret_client_id = ? AND secret_status = 'active' AND secret_expires_at IS NULL");
        $st->bind_param('ii', $hours, $clientId);
        $st->execute();
        $st->close();
        return self::addSecret($db, $clientId);
    }

    public static function revokeSecret(\mysqli $db, int $clientId, int $secretId): bool
    {
        $st = $db->prepare("UPDATE integration_client_secrets SET secret_status = 'revoked', secret_revoked_at = NOW()
            WHERE secret_id = ? AND secret_client_id = ? AND secret_status = 'active'");
        $st->bind_param('ii', $secretId, $clientId);
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        return $n === 1;
    }

    public static function revokeClient(\mysqli $db, int $clientId): void
    {
        $st = $db->prepare("UPDATE integration_clients SET client_status = 'revoked', client_revoked_at = NOW() WHERE client_id = ? AND client_status = 'active'");
        $st->bind_param('i', $clientId);
        $st->execute();
        $st->close();
        $st = $db->prepare("UPDATE integration_client_secrets SET secret_status = 'revoked', secret_revoked_at = NOW() WHERE secret_client_id = ? AND secret_status = 'active'");
        $st->bind_param('i', $clientId);
        $st->execute();
        $st->close();
        $st = $db->prepare('DELETE FROM integration_tokens WHERE token_client_id = ?');
        $st->bind_param('i', $clientId);
        $st->execute();
        $st->close();
    }

    // ---- client authentication at the token endpoint ---------------------------------------------------------------------

    /**
     * Authenticates client_id + secret. Returns [clientRow, secretId] or null. Unknown ids, wrong secrets, revoked/expired clients,
     * revoked/expired secrets and disallowed IPs are indistinguishable to the caller (and cost the same hashing time).
     */
    public static function authenticate(\mysqli $db, string $clientUid, string $secret, string $ip): ?array
    {
        static $dummy = null;
        $dummy ??= password_hash('dummy-secret-for-timing', PASSWORD_DEFAULT);
        $client = preg_match('/^ic_[0-9a-f]{20}$/D', $clientUid) === 1
            ? self::one($db, "SELECT * FROM integration_clients WHERE client_uid = ?", 's', [$clientUid]) : null;
        $secrets = $client === null ? [] : self::all($db, "SELECT secret_id, secret_hash FROM integration_client_secrets
            WHERE secret_client_id = ? AND secret_status = 'active' AND (secret_expires_at IS NULL OR secret_expires_at > NOW())", 'i', [(int) $client['client_id']]);
        $matched = null;
        foreach ($secrets ?: [['secret_id' => 0, 'secret_hash' => $dummy]] as $s) {
            if (password_verify($secret, (string) $s['secret_hash']) && (int) $s['secret_id'] > 0) {
                $matched = (int) $s['secret_id'];
            }
        }
        if ($client === null || $matched === null) {
            return null;
        }
        $live = self::one($db, "SELECT (client_status = 'active' AND (client_expires_at IS NULL OR client_expires_at > NOW())) AS ok FROM integration_clients WHERE client_id = ?", 'i', [(int) $client['client_id']]);
        if ((int) ($live['ok'] ?? 0) !== 1 || !self::ipAllowed((string) ($client['client_allowed_ips'] ?? ''), $ip)) {
            return null;
        }
        $db->query('UPDATE integration_client_secrets SET secret_last_used_at = NOW() WHERE secret_id = ' . $matched);
        return [$client, $matched];
    }

    /** Failed token requests from this IP (optionally for one client) in the last 10 minutes; the throttle reads the audit log. */
    public static function recentFailures(\mysqli $db, string $ip, ?int $clientId): int
    {
        $sql = "SELECT COUNT(*) AS n FROM logs WHERE log_type = 'Integration Token' AND log_action = 'Failed' AND log_ip = ?
            AND log_created_at > (NOW() - INTERVAL 10 MINUTE)";
        $types = 's';
        $p = [substr($ip, 0, 45)];
        if ($clientId !== null) {
            $sql .= ' AND log_entity_id = ?';
            $types .= 'i';
            $p[] = $clientId;
        }
        return (int) (self::one($db, $sql, $types, $p)['n'] ?? 0);
    }

    /** One audit line (logs table plus the structured audit trail when available). Never put a secret or token in $description. */
    public static function audit(\mysqli $db, string $type, string $action, string $description, int $clientId = 0, string $ip = ''): void
    {
        $desc = substr($description, 0, 1000);
        $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        $ip = substr($ip, 0, 45);
        try {
            $st = $db->prepare('INSERT INTO logs SET log_type = ?, log_action = ?, log_description = ?, log_ip = ?, log_user_agent = ?, log_entity_id = ?');
            $st->bind_param('sssssi', $type, $action, $desc, $ip, $ua, $clientId);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {
            error_log('Integration audit failed: ' . $e->getMessage());
        }
        try {
            \ITFlow\Audit\AuditService::record('integration_client.' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $action)), null, 'integration_client', $clientId > 0 ? $clientId : null, $action, $desc);
        } catch (\Throwable $e) {
            // The logs row above is the record of last resort.
        }
    }

    // ---- tiny prepared-statement helpers (this class has no other dependencies) ---------------------------------------------

    /** @return array<string,mixed>|null */
    private static function one(\mysqli $db, string $sql, string $types, array $params): ?array
    {
        return self::all($db, $sql, $types, $params)[0] ?? null;
    }

    /** @return list<array<string,mixed>> */
    private static function all(\mysqli $db, string $sql, string $types, array $params): array
    {
        $st = $db->prepare($sql);
        if ($types !== '') {
            $st->bind_param($types, ...$params);
        }
        $st->execute();
        $res = $st->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $st->close();
        return $rows;
    }
}
