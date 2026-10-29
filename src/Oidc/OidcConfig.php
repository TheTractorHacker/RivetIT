<?php

namespace ITFlow\Oidc;

use ITFlow\Integration\IntegrationAuth;

/**
 * Settings of RivetIT's OpenID Connect provider (issue #55): the kill switch, the lifetimes and the per-client registration.
 *
 * The issuer is built from the configured base URL ($config_base_url, set at install) and nothing else - never from Host, Forwarded
 * or X-Forwarded-* request headers, which a client controls. A server whose base URL is missing or malformed has no issuer, and the
 * provider then refuses to run (enabled() is false).
 */
final class OidcConfig
{
    /** Scopes the provider understands. `openid` is required; `profile` releases the display name only, and only to a client that is allowed it. */
    public const SCOPES = ['openid', 'profile'];

    public const CODE_TTL = 60;                 // seconds, fixed (not configurable)
    public const PENDING_TTL = 600;             // seconds a sign-in request waits for the person to log in

    // Bounds for the administrator-set lifetimes (seconds, or minutes for the session age).
    public const ID_TOKEN_TTL_MIN = 60;
    public const ID_TOKEN_TTL_MAX = 600;
    public const ACCESS_TOKEN_TTL_MIN = 60;
    public const ACCESS_TOKEN_TTL_MAX = 600;
    public const MAX_AGE_MIN = 5;               // minutes
    public const MAX_AGE_MAX = 1440;            // 24 hours
    /** A retired signing key stays in the JWKS for the longest possible ID token lifetime plus this clock-skew allowance. */
    public const KEY_OVERLAP_SKEW = 300;

    /** @return array{enabled:bool, max_auth_age_minutes:int, id_token_ttl:int, access_token_ttl:int, table_ready:bool} */
    public static function load(\mysqli $db): array
    {
        $out = ['enabled' => false, 'allow_admins' => true, 'max_auth_age_minutes' => 480, 'id_token_ttl' => 300, 'access_token_ttl' => 300, 'table_ready' => false];
        try {
            $res = $db->query('SELECT ops_enabled, ops_max_auth_age_minutes, ops_id_token_ttl, ops_access_token_ttl FROM oidc_provider_settings WHERE ops_id = 1');
            $row = $res ? $res->fetch_assoc() : null;
        } catch (\Throwable) {
            return $out;   // before the database update: the provider is simply off
        }
        if (!$row) {
            return $out;
        }
        $out['table_ready'] = true;
        $out['enabled'] = (int) $row['ops_enabled'] === 1 && self::issuer() !== null;
        $out['max_auth_age_minutes'] = self::clamp((int) $row['ops_max_auth_age_minutes'], self::MAX_AGE_MIN, self::MAX_AGE_MAX);
        $out['id_token_ttl'] = self::clamp((int) $row['ops_id_token_ttl'], self::ID_TOKEN_TTL_MIN, self::ID_TOKEN_TTL_MAX);
        $out['access_token_ttl'] = self::clamp((int) $row['ops_access_token_ttl'], self::ACCESS_TOKEN_TTL_MIN, self::ACCESS_TOKEN_TTL_MAX);
        // DB 2.6.206. Read on its own so that new code on a database that has not been updated yet keeps the provider working (and administrators allowed).
        try {
            $r2 = $db->query('SELECT ops_allow_admins FROM oidc_provider_settings WHERE ops_id = 1');
            $row2 = $r2 ? $r2->fetch_assoc() : null;
            $out['allow_admins'] = $row2 === null || (int) $row2['ops_allow_admins'] === 1;
        } catch (\Throwable) {
            $out['allow_admins'] = true;
        }
        return $out;
    }

    public static function clamp(int $v, int $min, int $max): int
    {
        return max($min, min($max, $v));
    }

    /** https://<host from the configured base URL>, or null when the base URL is unusable. */
    public static function issuer(): ?string
    {
        $base = trim((string) ($GLOBALS['config_base_url'] ?? ''));
        if ($base === '' || preg_match('#^[A-Za-z0-9.-]+(:\d{1,5})?(/.*)?$#D', (string) preg_replace('#^https?://#i', '', $base)) !== 1) {
            return null;
        }
        return IntegrationAuth::issuer();
    }

    public static function saveSettings(\mysqli $db, bool $enabled, int $maxAgeMinutes, int $idTokenTtl, int $accessTokenTtl, int $userId, bool $allowAdmins = true): void
    {
        $e = $enabled ? 1 : 0;
        $a = self::clamp($maxAgeMinutes, self::MAX_AGE_MIN, self::MAX_AGE_MAX);
        $i = self::clamp($idTokenTtl, self::ID_TOKEN_TTL_MIN, self::ID_TOKEN_TTL_MAX);
        $t = self::clamp($accessTokenTtl, self::ACCESS_TOKEN_TTL_MIN, self::ACCESS_TOKEN_TTL_MAX);
        $st = $db->prepare('UPDATE oidc_provider_settings SET ops_enabled = ?, ops_max_auth_age_minutes = ?, ops_id_token_ttl = ?, ops_access_token_ttl = ?, ops_updated_by_user_id = ? WHERE ops_id = 1');
        $st->bind_param('iiiii', $e, $a, $i, $t, $userId);
        $st->execute();
        $st->close();
        try {
            $aa = $allowAdmins ? 1 : 0;
            $db->query("UPDATE oidc_provider_settings SET ops_allow_admins = $aa WHERE ops_id = 1");   // column arrives with DB 2.6.206
        } catch (\Throwable) {
        }
    }

    // ---- registered SSO clients ---------------------------------------------------------------------------------------------

    /**
     * The integration client with this client_uid when it may use the provider: active, not expired, holds the 'sso' scope and has a
     * registration with at least one redirect URI. Otherwise null (callers treat every cause alike).
     * @return array<string,mixed>|null the integration_clients row plus redirect_uris (list), login_url, allow_profile
     */
    public static function ssoClient(\mysqli $db, string $clientUid): ?array
    {
        if (preg_match('/^ic_[0-9a-f]{20}$/D', $clientUid) !== 1) {
            return null;
        }
        $st = $db->prepare("SELECT c.*, g.ocfg_redirect_uris, g.ocfg_login_url, g.ocfg_allow_profile
            FROM integration_clients c JOIN oidc_client_config g ON g.ocfg_client_id = c.client_id
            WHERE c.client_uid = ? AND c.client_status = 'active' AND (c.client_expires_at IS NULL OR c.client_expires_at > NOW())");
        $st->bind_param('s', $clientUid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row || !in_array(IntegrationAuth::SSO_SCOPE, IntegrationAuth::parseScopes((string) $row['client_scopes']), true)) {
            return null;
        }
        $row['redirect_uris'] = self::parseRedirectUris((string) $row['ocfg_redirect_uris']);
        if ($row['redirect_uris'] === []) {
            return null;
        }
        return $row;
    }

    /** Exact string comparison against the registered list; nothing is normalised and there are no wildcards or prefixes. */
    public static function redirectRegistered(array $client, string $redirect): bool
    {
        $ok = false;
        foreach ($client['redirect_uris'] as $registered) {
            if ($redirect !== '' && hash_equals((string) $registered, $redirect)) {
                $ok = true;
            }
        }
        return $ok;
    }

    /** @return list<string> one URI per line, each already valid (see validateRedirectUri) */
    public static function parseRedirectUris(string $stored): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $stored, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && self::validateRedirectUri($line) === null) {
                $out[$line] = $line;
            }
        }
        return array_values($out);
    }

    /**
     * Why a redirect URI may not be registered, or null when it is acceptable: absolute https (http only for a loopback host, for local
     * development), a host, no userinfo, no fragment, no wildcard, printable ASCII, at most 400 characters.
     */
    public static function validateRedirectUri(string $uri): ?string
    {
        if ($uri === '' || strlen($uri) > 400 || preg_match('/^[\x21-\x7E]+$/D', $uri) !== 1) {
            return 'Use a plain ASCII address of at most 400 characters.';
        }
        if (str_contains($uri, '*') || str_contains($uri, '#') || str_contains($uri, '\\')) {
            return 'Wildcards, fragments and backslashes are not allowed.';
        }
        $p = parse_url($uri);
        if ($p === false || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return 'Enter a full address with a host and no user name or password.';
        }
        $scheme = strtolower((string) $p['scheme']);
        $loopback = in_array(strtolower((string) $p['host']), ['localhost', '127.0.0.1', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            return 'The address must start with https:// (http:// is only accepted for localhost).';
        }
        return null;
    }

    public static function saveClient(\mysqli $db, int $integrationClientId, string $redirectUris, ?string $loginUrl, bool $allowProfile): void
    {
        $login = $loginUrl === '' ? null : $loginUrl;
        $prof = $allowProfile ? 1 : 0;
        $st = $db->prepare('INSERT INTO oidc_client_config (ocfg_client_id, ocfg_redirect_uris, ocfg_login_url, ocfg_allow_profile) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE ocfg_redirect_uris = VALUES(ocfg_redirect_uris), ocfg_login_url = VALUES(ocfg_login_url), ocfg_allow_profile = VALUES(ocfg_allow_profile)');
        $st->bind_param('issi', $integrationClientId, $redirectUris, $login, $prof);
        $st->execute();
        $st->close();
    }

    /**
     * The address the portal's "Open Quality" button opens, or null. Shown only when the provider is on and an SSO client with a login URL
     * exists. The URL was validated when it was saved; it is re-checked here so a bad row can never reach a page.
     */
    public static function portalLoginUrl(\mysqli $db): ?string
    {
        if (!self::load($db)['enabled']) {
            return null;
        }
        try {
            $res = $db->query("SELECT g.ocfg_login_url, c.client_scopes FROM oidc_client_config g JOIN integration_clients c ON c.client_id = g.ocfg_client_id
                WHERE c.client_status = 'active' AND (c.client_expires_at IS NULL OR c.client_expires_at > NOW())
                  AND g.ocfg_login_url IS NOT NULL AND g.ocfg_login_url <> '' AND g.ocfg_redirect_uris IS NOT NULL AND g.ocfg_redirect_uris <> ''
                ORDER BY g.ocfg_client_id LIMIT 20");
        } catch (\Throwable) {
            return null;
        }
        while ($res && ($row = $res->fetch_assoc())) {
            if (in_array(IntegrationAuth::SSO_SCOPE, IntegrationAuth::parseScopes((string) $row['client_scopes']), true) && self::validateLoginUrl((string) $row['ocfg_login_url']) === null) {
                return (string) $row['ocfg_login_url'];
            }
        }
        return null;
    }

    /**
     * Why a login-initiation URL may not be saved, or null. https only (a browser navigates to it, nothing is fetched server side), no
     * userinfo or fragment, and the existing UrlPolicy (RivetCore) must accept the host: public addresses plus the administrator's allowed
     * internal networks (Administration > Internal network access); loopback, link-local and metadata addresses never.
     */
    public static function validateLoginUrl(string $url, ?\mysqli $db = null): ?string
    {
        if (strlen($url) > 500 || preg_match('/^[\x21-\x7E]+$/D', $url) !== 1 || str_contains($url, '#') || str_contains($url, '\\')) {
            return 'Use a plain ASCII https:// address of at most 500 characters, without a fragment.';
        }
        $p = parse_url($url);
        if ($p === false || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            return 'The login address must be a full https:// address with no user name or password.';
        }
        if ($db !== null) {
            // Save-time check against the shared policy (DNS resolution happens here, so it is not repeated on every portal page view).
            if (!function_exists('rivetWebhookUrlPolicy')) {
                require_once dirname(__DIR__, 2) . '/includes/event_bus.php';
            }
            if (rivetWebhookUrlPolicy($db)->vet($url) === null) {
                return 'That address is not allowed (' . rivetWebhookRuleText($db) . '). Private networks are added under Internal network access.';
            }
        }
        return null;
    }
}
