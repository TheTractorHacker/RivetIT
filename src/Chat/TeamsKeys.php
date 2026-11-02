<?php

namespace ITFlow\Chat;

/**
 * The Bot Framework signing keys (JWKS), fetched through ChatHttp (pinned, public hosts only) and cached in chat_kv for a day. A forced refresh
 * (an unknown kid) is rate limited to once every 5 minutes so a stream of forged tokens cannot make RivetIT hammer Microsoft.
 * The metadata URL can be moved to a local mock for tests with RIVETIT_TEAMS_OPENID_URL (config.php only, never the UI).
 */
final class TeamsKeys
{
    private const TTL_SECONDS = 86400;
    private const REFRESH_MIN_SECONDS = 300;

    public static function metadataUrl(): string
    {
        return defined('RIVETIT_TEAMS_OPENID_URL') ? (string) RIVETIT_TEAMS_OPENID_URL : 'https://login.botframework.com/v1/.well-known/openidconfiguration';
    }

    /** The provider TeamsJwt expects. */
    public static function provider(\mysqli $db): callable
    {
        return static function (bool $refresh) use ($db): ?array {
            $row = mysqli_fetch_assoc(mysqli_query($db, "SELECT kv_value, UNIX_TIMESTAMP(kv_updated_at) AS ts FROM chat_kv WHERE kv_key = 'teams_jwks'"));
            $cached = $row ? json_decode((string) $row['kv_value'], true) : null;
            $age = $row ? time() - (int) $row['ts'] : PHP_INT_MAX;
            if (is_array($cached) && !$refresh && $age < self::TTL_SECONDS) {
                return $cached;
            }
            if (is_array($cached) && $refresh && $age < self::REFRESH_MIN_SECONDS) {
                return $cached;
            }
            $fresh = self::fetch();
            if ($fresh === null) {
                return is_array($cached) ? $cached : null;
            }
            $json = json_encode($fresh);
            $stmt = mysqli_prepare($db, "INSERT INTO chat_kv (kv_key, kv_value) VALUES ('teams_jwks', ?) ON DUPLICATE KEY UPDATE kv_value = VALUES(kv_value), kv_updated_at = NOW()");
            mysqli_stmt_bind_param($stmt, 's', $json);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            return $fresh;
        };
    }

    /** @return array<string,mixed>|null */
    public static function fetch(?string $metadataUrl = null): ?array
    {
        $meta = ChatHttp::request('GET', $metadataUrl ?? self::metadataUrl());
        $doc = is_string($meta['body']) ? json_decode($meta['body'], true) : null;
        $uri = is_array($doc) ? (string) ($doc['jwks_uri'] ?? '') : '';
        if ($meta['status'] !== 200 || $uri === '') {
            return null;
        }
        $h = strtolower((string) parse_url($uri, PHP_URL_HOST));
        $local = defined('RIVETIT_CHAT_ALLOW_LOCAL_HTTP') && RIVETIT_CHAT_ALLOW_LOCAL_HTTP === true && in_array($h, ['127.0.0.1', 'localhost'], true);
        if (!$local && $h !== 'botframework.com' && !str_ends_with($h, '.botframework.com')) {
            return null; // the keyset document may only point at Microsoft's Bot Framework host
        }
        $keys = ChatHttp::request('GET', $uri);
        $jwks = is_string($keys['body']) ? json_decode($keys['body'], true) : null;

        return $keys['status'] === 200 && is_array($jwks) && !empty($jwks['keys']) ? $jwks : null;
    }
}
