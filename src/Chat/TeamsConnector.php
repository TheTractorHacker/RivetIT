<?php

namespace ITFlow\Chat;

/**
 * Outbound half of the Teams bot: get a Bot Framework access token (client credentials for the Azure Bot registration) and send activities to
 * the Bot Connector service URL the incoming activity named.
 *
 * UNVERIFIED LIVE: proven against local mocks only (tests/chat_teams.php); it has not talked to a real Azure Bot registration.
 *
 * SSRF: the service URL comes from a request, so it is only used when the JWT's `serviceurl` claim matched it (TeamsJwt) AND it is an https URL on
 * a known Microsoft Bot Connector host AND ChatHttp's public-address vetting passes. Tokens are cached in chat_kv until shortly before expiry
 * and are never logged.
 */
final class TeamsConnector
{
    private const SERVICE_HOST_SUFFIXES = ['botframework.com', 'trafficmanager.net', 'teams.microsoft.com', 'teams.microsoft.us', 'microsoft.com'];

    public function __construct(private \mysqli $db, private ChatSettings $settings)
    {
    }

    public static function tokenUrl(): string
    {
        return defined('RIVETIT_TEAMS_TOKEN_URL') ? (string) RIVETIT_TEAMS_TOKEN_URL : 'https://login.microsoftonline.com/botframework.com/oauth2/v2.0/token';
    }

    public static function serviceUrlAllowed(string $serviceUrl): bool
    {
        $p = parse_url($serviceUrl);
        if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return false;
        }
        $host = strtolower($p['host']);
        if (defined('RIVETIT_CHAT_ALLOW_LOCAL_HTTP') && RIVETIT_CHAT_ALLOW_LOCAL_HTTP === true && in_array($host, ['127.0.0.1', 'localhost'], true) && ($p['scheme'] ?? '') === 'http') {
            return true;
        }
        if (strtolower((string) ($p['scheme'] ?? '')) !== 'https') {
            return false;
        }
        foreach (self::SERVICE_HOST_SUFFIXES as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return string|null a bearer token, null when the registration is incomplete or Azure refused */
    public function token(): ?string
    {
        $appId = $this->settings->text('config_teams_app_id');
        $secret = $this->settings->secret('config_teams_app_secret');
        if ($appId === '' || $secret === '') {
            return null;
        }
        $row = mysqli_fetch_row(mysqli_query($this->db, "SELECT kv_value FROM chat_kv WHERE kv_key = 'teams_token'"));
        $cached = $row ? json_decode((string) $row[0], true) : null;
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 120 && ($cached['app'] ?? '') === $appId && is_string($cached['token'] ?? null)) {
            return $cached['token'];
        }
        $r = ChatHttp::request('POST', self::tokenUrl(), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'client_credentials', 'client_id' => $appId, 'client_secret' => $secret, 'scope' => 'https://api.botframework.com/.default',
        ]));
        $data = is_string($r['body']) ? json_decode($r['body'], true) : null;
        if ($r['status'] !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null)) {
            return null;
        }
        $exp = time() + max(60, (int) ($data['expires_in'] ?? 3600));
        $json = json_encode(['token' => $data['access_token'], 'exp' => $exp, 'app' => $appId]);
        $stmt = mysqli_prepare($this->db, "INSERT INTO chat_kv (kv_key, kv_value) VALUES ('teams_token', ?) ON DUPLICATE KEY UPDATE kv_value = VALUES(kv_value), kv_updated_at = NOW()");
        mysqli_stmt_bind_param($stmt, 's', $json);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        return $data['access_token'];
    }

    /** Send one activity into a conversation. @return array{ok:bool,error:?string,id:?string} */
    public function send(string $serviceUrl, string $conversationId, array $activity): array
    {
        if (!self::serviceUrlAllowed($serviceUrl) || $conversationId === '' || strlen($conversationId) > 256) {
            return ['ok' => false, 'error' => 'service_url_not_allowed', 'id' => null];
        }
        $token = $this->token();
        if ($token === null) {
            return ['ok' => false, 'error' => 'no_token', 'id' => null];
        }
        $url = rtrim($serviceUrl, '/') . '/v3/conversations/' . rawurlencode($conversationId) . '/activities';
        $r = ChatHttp::postJson($url, $activity, ['Authorization: Bearer ' . $token]);

        return ($r['status'] !== null && $r['status'] >= 200 && $r['status'] < 300)
            ? ['ok' => true, 'error' => null, 'id' => is_string($r['data']['id'] ?? null) ? $r['data']['id'] : null]
            : ['ok' => false, 'error' => $r['error'] ?? 'http_' . (int) $r['status'], 'id' => null];
    }

    /** The directory email of one member of a conversation (for the optional email fallback). */
    public function memberEmail(string $serviceUrl, string $conversationId, string $memberId): ?string
    {
        if (!self::serviceUrlAllowed($serviceUrl) || $conversationId === '') {
            return null;
        }
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        $r = ChatHttp::request('GET', rtrim($serviceUrl, '/') . '/v3/conversations/' . rawurlencode($conversationId) . '/members/' . rawurlencode($memberId), ['Authorization: Bearer ' . $token]);
        $data = is_string($r['body']) ? json_decode($r['body'], true) : null;
        if ($r['status'] !== 200 || !is_array($data)) {
            return null;
        }
        // The member must be the one asked about (objectId), so a roster answer for somebody else is never used.
        if (strtolower((string) ($data['objectId'] ?? '')) !== strtolower($memberId)) {
            return null;
        }
        $e = (string) ($data['email'] ?? $data['userPrincipalName'] ?? '');

        return $e !== '' ? $e : null;
    }
}
