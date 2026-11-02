<?php

namespace ITFlow\Chat;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;

/**
 * Validation of the JWT the Bot Framework Service sends with every activity (the Authorization: Bearer header of POST /api/teams-bot).
 * Per the Bot Framework authentication rules the token must:
 *   - be RS256 signed by a key in the Bot Framework OpenID keyset (login.botframework.com); other algorithms are rejected (JWK keys carry their alg,
 *     so an HS256 token signed with a public key as the secret cannot pass);
 *   - have issuer https://api.botframework.com;
 *   - have audience = this bot's Microsoft App ID;
 *   - be inside its validity window (exp, nbf) with a 5 minute clock tolerance;
 *   - carry a `serviceurl` claim equal to the activity's serviceUrl (so a replayed token cannot point replies at another host);
 *   - be signed by a key endorsed for the activity's channel (the keyset lists `endorsements`, e.g. "msteams").
 * The keyset comes from $keys(bool $refresh): the first call uses the cached set, and when the token's kid is unknown ONE refresh is tried (keys rotate).
 *
 * UNVERIFIED LIVE: tested against a locally generated RSA key and a mocked JWKS endpoint (tests/chat_teams.php), not against the real Bot Framework.
 */
final class TeamsJwt
{
    public const ISSUER = 'https://api.botframework.com';
    public const LEEWAY_SECONDS = 300;

    /** @param callable(bool): (array|null) $keys returns the decoded JWKS document ({"keys":[...]}) */
    public function __construct(private $keys)
    {
    }

    /** @return array{ok:bool,error:?string} error is a short code for tests / logs, never shown to the caller */
    public function validate(string $authorizationHeader, string $appId, string $activityServiceUrl, string $channelId, ?int $now = null): array
    {
        $fail = static fn (string $e) => ['ok' => false, 'error' => $e];
        if ($appId === '' || !preg_match('/^Bearer ([A-Za-z0-9._-]{20,4000})$/', $authorizationHeader, $m)) {
            return $fail('no_bearer');
        }
        $jwt = $m[1];
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return $fail('malformed');
        }
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), false), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'RS256' || !is_string($header['kid'] ?? null) || $header['kid'] === '') {
            return $fail('bad_header');
        }
        $kid = $header['kid'];

        $jwks = ($this->keys)(false);
        $key = $this->findKey($jwks, $kid);
        if ($key === null) {
            $jwks = ($this->keys)(true); // unknown kid: the keys may have rotated, look once more
            $key = $this->findKey($jwks, $kid);
        }
        if ($key === null) {
            return $fail('unknown_key');
        }

        $previousLeeway = JWT::$leeway;
        $previousTs = JWT::$timestamp;
        try {
            JWT::$leeway = self::LEEWAY_SECONDS;
            JWT::$timestamp = $now;
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet(['keys' => [$key]], 'RS256'));
        } catch (\Throwable $e) {
            return $fail('invalid_token');
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTs;
        }
        if (!isset($claims['exp']) || !isset($claims['iss']) || ($claims['iss'] ?? '') !== self::ISSUER) {
            return $fail('bad_issuer');
        }
        $aud = $claims['aud'] ?? '';
        if (!(is_string($aud) ? hash_equals($appId, $aud) : (is_array($aud) && in_array($appId, $aud, true)))) {
            return $fail('bad_audience');
        }
        $claimUrl = (string) ($claims['serviceurl'] ?? '');
        if ($claimUrl === '' || rtrim($claimUrl, '/') !== rtrim($activityServiceUrl, '/')) {
            return $fail('bad_serviceurl');
        }
        $endorsements = $key['endorsements'] ?? null;
        if (is_array($endorsements) && $channelId !== '' && !in_array($channelId, $endorsements, true)) {
            return $fail('not_endorsed');
        }

        return ['ok' => true, 'error' => null];
    }

    /** @return array<string,mixed>|null the JWK with this kid */
    private function findKey(?array $jwks, string $kid): ?array
    {
        foreach ((array) ($jwks['keys'] ?? []) as $k) {
            if (is_array($k) && ($k['kid'] ?? null) === $kid && ($k['kty'] ?? '') === 'RSA') {
                return $k;
            }
        }

        return null;
    }
}
