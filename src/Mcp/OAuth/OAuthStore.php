<?php

namespace ITFlow\Mcp\OAuth;

/**
 * Database access for the built-in OAuth server. Every secret (authorization code, access token, refresh token) is
 * stored as the raw SHA-256 of the value (BINARY(32)); the plain value only ever exists in the response that issues it.
 * All timestamps are the database's own clock (NOW()), so a code, token or consent expires the same way for every request.
 */
final class OAuthStore
{
    public function __construct(private \mysqli $db) {}

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret, true);
    }

    /** @return int affected rows (writes) */
    private function exec(string $sql, string $types = '', array $params = []): int
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();

        return (int) $n;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private function row(string $sql, string $types = '', array $params = []): ?array
    {
        return $this->rows($sql, $types, $params)[0] ?? null;
    }

    /* ------------------------------------------------------------------ clients */

    public function createClient(string $id, string $name, array $redirectUris, string $type, ?string $ip, ?int $byUser): void
    {
        $json = json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->exec('INSERT INTO mcp_oauth_clients (client_id, client_name, redirect_uris, registration_type, created_ip, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)', 'sssssi', [$id, $name, $json, $type, $ip, $byUser]);
    }

    public function client(string $id): ?array
    {
        $row = $this->row('SELECT * FROM mcp_oauth_clients WHERE client_id = ?', 's', [$id]);
        if ($row) {
            $row['redirect_uris'] = json_decode((string) $row['redirect_uris'], true) ?: [];
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function clients(): array
    {
        $rows = $this->rows("SELECT c.*,
                (SELECT COUNT(*) FROM mcp_oauth_grants g WHERE g.client_id = c.client_id AND g.revoked_at IS NULL AND g.expires_at > NOW()) AS active_grants
            FROM mcp_oauth_clients c ORDER BY c.created_at DESC LIMIT 500");
        foreach ($rows as &$r) {
            $r['redirect_uris'] = json_decode((string) $r['redirect_uris'], true) ?: [];
        }

        return $rows;
    }

    public function countClients(): int
    {
        return (int) ($this->row("SELECT COUNT(*) AS n FROM mcp_oauth_clients WHERE registration_type = 'dynamic'")['n'] ?? 0);
    }

    public function registrationsFromIp(string $ip, int $seconds): int
    {
        return (int) ($this->row('SELECT COUNT(*) AS n FROM mcp_oauth_clients WHERE created_ip = ? AND created_at > NOW() - INTERVAL ? SECOND',
            'si', [$ip, $seconds])['n'] ?? 0);
    }

    public function setClientDisabled(string $id, bool $disabled): void
    {
        $this->exec($disabled ? 'UPDATE mcp_oauth_clients SET disabled_at = NOW() WHERE client_id = ? AND disabled_at IS NULL'
            : 'UPDATE mcp_oauth_clients SET disabled_at = NULL WHERE client_id = ?', 's', [$id]);
    }

    /** Remove a client and everything issued to it. */
    public function deleteClient(string $id): void
    {
        $this->exec('DELETE t FROM mcp_oauth_tokens t JOIN mcp_oauth_grants g ON g.grant_id = t.grant_id WHERE g.client_id = ?', 's', [$id]);
        $this->exec('DELETE FROM mcp_oauth_grants WHERE client_id = ?', 's', [$id]);
        $this->exec('DELETE FROM mcp_oauth_codes WHERE client_id = ?', 's', [$id]);
        $this->exec('DELETE FROM mcp_oauth_clients WHERE client_id = ?', 's', [$id]);
    }

    public function touchClient(string $id): void
    {
        $this->exec('UPDATE mcp_oauth_clients SET last_used_at = NOW() WHERE client_id = ?', 's', [$id]);
    }

    /* ------------------------------------------------------------------ codes */

    public function insertCode(string $codeHash, string $clientId, int $userId, string $redirectUri, string $challenge, string $scope, string $resource): void
    {
        $ttl = OAuthConfig::CODE_TTL;
        $this->exec('INSERT INTO mcp_oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, resource, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)', 'ssissssi',
            [$codeHash, $clientId, $userId, $redirectUri, $challenge, $scope, $resource, $ttl]);
    }

    /**
     * Burn a code. The UPDATE is the single-use gate: of two concurrent redemptions only one changes the row.
     * @return array{status:'ok'|'unknown'|'expired'|'reused', row:?array}
     */
    public function redeemCode(string $codeHash): array
    {
        $won = $this->exec('UPDATE mcp_oauth_codes SET used_at = NOW() WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()', 's', [$codeHash]);
        $row = $this->row('SELECT * FROM mcp_oauth_codes WHERE code_hash = ?', 's', [$codeHash]);
        if (!$row) {
            return ['status' => 'unknown', 'row' => null];
        }
        if ($won === 1) {
            return ['status' => 'ok', 'row' => $row];
        }

        return ['status' => $row['used_at'] !== null ? 'reused' : 'expired', 'row' => $row];
    }

    /* ------------------------------------------------------------------ grants and tokens */

    public function createGrant(string $clientId, int $userId, string $scope, string $resource, string $originCodeHash): int
    {
        $days = OAuthConfig::GRANT_DAYS;
        $this->exec('INSERT INTO mcp_oauth_grants (client_id, user_id, scope, resource, origin_code_hash, expires_at)
            VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? DAY)', 'sisssi', [$clientId, $userId, $scope, $resource, $originCodeHash, $days]);

        return (int) $this->db->insert_id;
    }

    public function grant(int $id): ?array
    {
        return $this->row('SELECT * FROM mcp_oauth_grants WHERE grant_id = ?', 'i', [$id]);
    }

    public function grantIsLive(int $id): bool
    {
        return $this->row('SELECT grant_id FROM mcp_oauth_grants WHERE grant_id = ? AND revoked_at IS NULL AND expires_at > NOW()', 'i', [$id]) !== null;
    }

    public function grantByOriginCode(string $codeHash): ?array
    {
        return $this->row('SELECT * FROM mcp_oauth_grants WHERE origin_code_hash = ?', 's', [$codeHash]);
    }

    /** Revoke a consent and drop every token under it. Returns true when this call is the one that revoked it. */
    public function revokeGrant(int $id, string $reason): bool
    {
        $n = $this->exec('UPDATE mcp_oauth_grants SET revoked_at = NOW(), revoke_reason = ? WHERE grant_id = ? AND revoked_at IS NULL', 'si', [$reason, $id]);
        $this->exec('DELETE FROM mcp_oauth_tokens WHERE grant_id = ?', 'i', [$id]);

        return $n === 1;
    }

    /** Revoke every live consent of one user (account disabled or removed). */
    public function revokeGrantsOfUser(int $userId, string $reason): int
    {
        $ids = array_column($this->rows('SELECT grant_id FROM mcp_oauth_grants WHERE user_id = ? AND revoked_at IS NULL', 'i', [$userId]), 'grant_id');
        foreach ($ids as $id) {
            $this->revokeGrant((int) $id, $reason);
        }

        return count($ids);
    }

    public function insertToken(string $tokenHash, int $grantId, string $kind, int $ttlSeconds): void
    {
        $this->exec('INSERT INTO mcp_oauth_tokens (token_hash, grant_id, token_kind, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ? SECOND)',
            'sisi', [$tokenHash, $grantId, $kind, $ttlSeconds]);
    }

    public function token(string $tokenHash): ?array
    {
        return $this->row('SELECT * FROM mcp_oauth_tokens WHERE token_hash = ?', 's', [$tokenHash]);
    }

    /**
     * Burn a refresh token (single use). @return array{status:'ok'|'unknown'|'expired'|'reused', row:?array}
     */
    public function redeemRefresh(string $tokenHash): array
    {
        $won = $this->exec("UPDATE mcp_oauth_tokens SET used_at = NOW() WHERE token_hash = ? AND token_kind = 'refresh' AND used_at IS NULL AND expires_at > NOW()", 's', [$tokenHash]);
        $row = $this->row("SELECT * FROM mcp_oauth_tokens WHERE token_hash = ? AND token_kind = 'refresh'", 's', [$tokenHash]);
        if (!$row) {
            return ['status' => 'unknown', 'row' => null];
        }
        if ($won === 1) {
            return ['status' => 'ok', 'row' => $row];
        }

        return ['status' => $row['used_at'] !== null ? 'reused' : 'expired', 'row' => $row];
    }

    /**
     * The live access token behind a bearer value, with its consent, client and user. One query so a revoked consent, a
     * disabled client, an expired token or a disabled/removed/non-agent user all answer "no row" at the same moment.
     */
    public function liveAccessToken(string $tokenHash): ?array
    {
        return $this->row("SELECT t.expires_at AS token_expires_at, g.grant_id, g.user_id, g.client_id, g.scope, g.resource, g.last_used_at
            FROM mcp_oauth_tokens t
            JOIN mcp_oauth_grants g ON g.grant_id = t.grant_id
            JOIN mcp_oauth_clients c ON c.client_id = g.client_id
            JOIN users u ON u.user_id = g.user_id
            WHERE t.token_hash = ? AND t.token_kind = 'access' AND t.expires_at > NOW()
              AND g.revoked_at IS NULL AND g.expires_at > NOW() AND c.disabled_at IS NULL
              AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL", 's', [$tokenHash]);
    }

    /** Recorded at most once a minute per consent, so a busy client does not write on every call. */
    public function touchGrant(int $id): void
    {
        $this->exec('UPDATE mcp_oauth_grants SET last_used_at = NOW() WHERE grant_id = ? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 60 SECOND)', 'i', [$id]);
    }

    public function userIsActiveAgent(int $userId): bool
    {
        return $this->row('SELECT user_id FROM users WHERE user_id = ? AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL', 'i', [$userId]) !== null;
    }

    /* ------------------------------------------------------------------ listings and housekeeping */

    /** @return list<array<string,mixed>> live consents, newest first; $userId limits it to one person */
    public function activeGrants(?int $userId = null, int $limit = 200): array
    {
        $sql = "SELECT g.grant_id, g.client_id, g.user_id, g.scope, g.created_at, g.last_used_at, g.expires_at,
                c.client_name, c.redirect_uris, u.user_name, u.user_email
            FROM mcp_oauth_grants g
            JOIN mcp_oauth_clients c ON c.client_id = g.client_id
            LEFT JOIN users u ON u.user_id = g.user_id
            WHERE g.revoked_at IS NULL AND g.expires_at > NOW()";
        $types = '';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND g.user_id = ?';
            $types .= 'i';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY g.created_at DESC LIMIT ' . max(1, min(500, $limit));

        return $this->rows($sql, $types, $params);
    }

    /** Remove what can no longer be used. Cheap; run on a small share of requests. */
    public function purge(): void
    {
        $this->exec('DELETE FROM mcp_oauth_codes WHERE expires_at < NOW() - INTERVAL 1 DAY');
        $this->exec('DELETE FROM mcp_oauth_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY');
        $this->exec('DELETE FROM mcp_oauth_grants WHERE (revoked_at IS NOT NULL AND revoked_at < NOW() - INTERVAL 30 DAY) OR expires_at < NOW() - INTERVAL 30 DAY');
        $this->purgeUnusedClients(7 * 86400);
    }

    /** Self-registered clients nobody ever approved, once they are older than $seconds. */
    public function purgeUnusedClients(int $seconds): void
    {
        $this->exec("DELETE FROM mcp_oauth_clients WHERE registration_type = 'dynamic' AND created_at < NOW() - INTERVAL ? SECOND
            AND last_used_at IS NULL AND NOT EXISTS (SELECT 1 FROM mcp_oauth_grants g WHERE g.client_id = mcp_oauth_clients.client_id)", 'i', [$seconds]);
    }
}
