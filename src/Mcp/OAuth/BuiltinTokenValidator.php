<?php

namespace ITFlow\Mcp\OAuth;

use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Validates the opaque access tokens RivetIT itself issued (the built-in authorization server). A token is accepted only if
 * its hash is stored, it has not expired, its consent is not revoked or expired, its client is not disabled and the consenting
 * agent is still an active agent. Those are one query, so any of them failing answers 401 invalid_token on the very next call.
 * On top of that the client must still be allowed by the CURRENT policy (ClientMetadata::policyAllows): a client that identifies
 * itself by a metadata document stops working the moment the administrator turns that off or removes its host from the trusted list,
 * not when its access token runs out. Self-registered and hand-added clients do not depend on the registration switch.
 *
 * The audience is not a claim to check here: the token was minted for exactly one resource (the MCP URL) and the row says so.
 * Roles and client restrictions are NOT cached in the token; the tools re-read them from the database on every call.
 */
final class BuiltinTokenValidator implements AuthorizationTokenValidatorInterface
{
    private OAuthStore $store;

    public function __construct(private \mysqli $db, private string $issuer, private string $resource)
    {
        $this->store = new OAuthStore($db);
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        if (!preg_match('/^rvt_at_[A-Za-z0-9_-]{43}$/D', $accessToken)) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is not valid.');
        }
        try {
            $row = $this->store->liveAccessToken(OAuthStore::hash($accessToken));
        } catch (\Throwable $e) {
            error_log('MCP OAuth token lookup failed: ' . $e::class);

            return AuthorizationResult::unauthorized('invalid_token', 'The access token could not be checked.');
        }
        if ($row === null || !hash_equals($this->resource, (string) $row['resource'])) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid, expired or revoked.');
        }
        try {
            $allowed = ClientMetadata::policyAllows((string) $row['registration_type'], $row['source_url'] ?? null, OAuthConfig::cimd($this->db));
        } catch (\Throwable $e) {
            error_log('MCP OAuth policy check failed: ' . $e::class);
            $allowed = false;   // cannot tell: fail closed
        }
        if (!$allowed) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid, expired or revoked.');
        }
        $scopes = array_values(array_filter(explode(' ', (string) $row['scope'])));
        if (!in_array(OAuthConfig::SCOPE, $scopes, true)) {
            return AuthorizationResult::forbidden('insufficient_scope', 'The token does not carry ' . OAuthConfig::SCOPE . '.', [OAuthConfig::SCOPE]);
        }
        try {
            $this->store->touchGrant((int) $row['grant_id']);
        } catch (\Throwable) { /* bookkeeping only */ }

        $userId = (int) $row['user_id'];
        // Display only; the database already decided. Seconds left come from the database clock, never from parsing a stored time here.
        $exp = time() + max(1, min((int) $row['ttl_left'], OAuthConfig::ACCESS_TTL));

        return AuthorizationResult::allow([
            'oauth.subject' => 'rivetit-user:' . $userId,
            'oauth.scopes' => $scopes,
            'oauth.claims' => ['iss' => $this->issuer, 'aud' => $this->resource, 'sub' => 'rivetit-user:' . $userId,
                'iat' => min(time(), $exp - 1), 'exp' => $exp, 'client_id' => (string) $row['client_id']],
            'oauth.builtin_user_id' => $userId,
        ]);
    }
}
