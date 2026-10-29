<?php

namespace ITFlow\Oidc;

use ITFlow\Integration\IntegrationAuth;

/**
 * The OpenID Connect provider's protocol logic (issue #55). HTTP concerns live in oidc/index.php; everything that decides lives here so the
 * tests can drive it without a web server.
 *
 * Authorization code flow with PKCE (S256 only) for confidential clients (integration clients holding the 'sso' scope):
 *   validateAuthorizeRequest()  parameters + client + exact redirect URI; errors are returned, never thrown
 *   createPending()/loadPending()/consumePending()  a request waits server-side while the person signs in (id = 256 random bits, stored hashed)
 *   issueCode()                 single use, 60 s, bound to client, redirect URI, PKCE challenge, nonce and the person
 *   exchangeCode()              token endpoint: burns the code on first presentation, checks every binding, then issues the ID token and an
 *                               opaque access token; a second presentation of a used code revokes the tokens issued from it
 *   userinfo()                  opaque access token (stored as sha256), re-checks the person on every call
 */
final class OidcProvider
{
    public const TOKEN_PREFIX = 'rvo_';

    public function __construct(private \mysqli $db, private array $cfg)
    {
    }

    // ---- helpers ------------------------------------------------------------------------------------------------------------

    public static function randomId(): string
    {
        return IntegrationAuth::b64uEncode(random_bytes(32));   // 43 characters
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** RFC 7636 S256: BASE64URL(SHA256(verifier)) */
    public static function pkceChallenge(string $verifier): string
    {
        return IntegrationAuth::b64uEncode(hash('sha256', $verifier, true));
    }

    // ---- authorization request --------------------------------------------------------------------------------------------------

    /**
     * @param array<string,string> $v the query parameters (duplicates already refused by the caller)
     * @return array{ok:true, client:array<string,mixed>, req:array<string,mixed>}
     *       |array{ok:false, fatal:string}                                                     the client or redirect URI cannot be trusted: a page, no redirect
     *       |array{ok:false, client:array<string,mixed>, redirect_uri:string, state:?string, error:string, reason:string}  safe to report to the client
     */
    public function validateAuthorizeRequest(array $v): array
    {
        $client = OidcConfig::ssoClient($this->db, (string) ($v['client_id'] ?? ''));
        if ($client === null) {
            return ['ok' => false, 'fatal' => 'unknown_client'];
        }
        $redirect = (string) ($v['redirect_uri'] ?? '');
        if (!OidcConfig::redirectRegistered($client, $redirect)) {
            return ['ok' => false, 'fatal' => 'redirect_mismatch'];
        }
        $state = $v['state'] ?? null;
        $stateOk = is_string($state) && preg_match('/^[\x21-\x7E]{8,512}$/D', $state) === 1;
        $err = static fn(string $error, string $reason) => ['ok' => false, 'client' => $client, 'redirect_uri' => $redirect,
            'state' => $stateOk ? $state : null, 'error' => $error, 'reason' => $reason];

        if (isset($v['request'])) {
            return $err('request_not_supported', 'request_param');
        }
        if (isset($v['request_uri'])) {
            return $err('request_uri_not_supported', 'request_uri_param');
        }
        if (($v['response_type'] ?? '') !== 'code') {
            return $err('unsupported_response_type', 'response_type');   // implicit (token, id_token) and hybrid are not offered
        }
        if (isset($v['response_mode']) && $v['response_mode'] !== 'query') {
            return $err('invalid_request', 'response_mode');
        }
        if (!$stateOk) {
            return $err('invalid_request', 'state_missing');
        }
        $nonce = $v['nonce'] ?? '';
        if (preg_match('/^[\x21-\x7E]{8,255}$/D', $nonce) !== 1) {
            return $err('invalid_request', 'nonce_missing');
        }
        $challenge = $v['code_challenge'] ?? '';
        if (($v['code_challenge_method'] ?? '') !== 'S256') {
            return $err('invalid_request', 'pkce_method');   // "plain" and a missing method are both refused
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1) {
            return $err('invalid_request', 'pkce_challenge');
        }
        $scopes = preg_split('/ +/', trim((string) ($v['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array('openid', $scopes, true) || array_diff($scopes, OidcConfig::SCOPES) !== [] || count($scopes) !== count(array_unique($scopes))) {
            return $err('invalid_scope', 'scope');
        }
        if (in_array('profile', $scopes, true) && (int) $client['ocfg_allow_profile'] !== 1) {
            return $err('invalid_scope', 'profile_not_allowed');
        }
        $prompt = $v['prompt'] ?? '';
        if (!in_array($prompt, ['', 'login', 'none'], true)) {
            return $err('invalid_request', 'prompt');
        }
        $maxAge = null;
        if (isset($v['max_age'])) {
            if (preg_match('/^\d{1,9}$/D', $v['max_age']) !== 1) {
                return $err('invalid_request', 'max_age');
            }
            $maxAge = (int) $v['max_age'];
        }
        sort($scopes);
        return ['ok' => true, 'client' => $client, 'req' => [
            'redirect_uri' => $redirect, 'scope' => implode(' ', $scopes), 'state' => $state, 'nonce' => $nonce, 'challenge' => $challenge,
            'force_login' => $prompt === 'login' ? 1 : 0, 'prompt_none' => $prompt === 'none', 'max_age' => $maxAge,
        ]];
    }

    /** Stores a validated request while the person signs in. Returns the id to keep in the browser cookie (never in a URL). */
    public function createPending(array $client, array $req): string
    {
        $id = self::randomId();
        $h = self::hash($id);
        $ttl = OidcConfig::PENDING_TTL;
        $cid = (int) $client['client_id'];
        $force = (int) $req['force_login'];
        $maxAge = $req['max_age'];
        $st = $this->db->prepare('INSERT INTO oidc_pending (opend_hash, opend_client_id, opend_redirect_uri, opend_scope, opend_state, opend_nonce, opend_challenge, opend_force_login, opend_max_age, opend_expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)');
        $st->bind_param('sisssssiii', $h, $cid, $req['redirect_uri'], $req['scope'], $req['state'], $req['nonce'], $req['challenge'], $force, $maxAge, $ttl);
        $st->execute();
        $st->close();
        $this->db->query('DELETE FROM oidc_pending WHERE opend_expires_at < NOW() - INTERVAL 1 DAY');
        return $id;
    }

    /** @return array<string,mixed>|null the unexpired, unconsumed pending request for this id */
    public function loadPending(string $id): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $id) !== 1) {
            return null;
        }
        $h = self::hash($id);
        $st = $this->db->prepare('SELECT *, UNIX_TIMESTAMP(opend_created_at) AS created_epoch FROM oidc_pending WHERE opend_hash = ? AND opend_consumed_at IS NULL AND opend_expires_at > NOW()');
        $st->bind_param('s', $h);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        return $row ?: null;
    }

    /** One-shot: true for exactly one caller. A back-button replay of the same request finds it consumed. */
    public function consumePending(string $id): bool
    {
        $h = self::hash($id);
        $st = $this->db->prepare('UPDATE oidc_pending SET opend_consumed_at = NOW() WHERE opend_hash = ? AND opend_consumed_at IS NULL AND opend_expires_at > NOW()');
        $st->bind_param('s', $h);
        $st->execute();
        $n = $st->affected_rows;
        $st->close();
        return $n === 1;
    }

    /** Re-checks a stored request's client and redirect URI against the registration as it is now (it may have been changed or revoked). */
    public function clientForPending(array $pending): ?array
    {
        $st = $this->db->prepare('SELECT client_uid FROM integration_clients WHERE client_id = ?');
        $cid = (int) $pending['opend_client_id'];
        $st->bind_param('i', $cid);
        $st->execute();
        $uid = $st->get_result()->fetch_assoc()['client_uid'] ?? '';
        $st->close();
        $client = OidcConfig::ssoClient($this->db, (string) $uid);
        if ($client === null || !OidcConfig::redirectRegistered($client, (string) $pending['opend_redirect_uri'])) {
            return null;
        }
        return $client;
    }

    /** Issues a one-time code for a pending request and the person; returns the code (shown once, stored as a hash). */
    public function issueCode(array $client, array $pending, int $contactId, int $userId, int $authTime, string $amr): string
    {
        $code = self::randomId();
        $h = self::hash($code);
        $ttl = OidcConfig::CODE_TTL;
        $cid = (int) $client['client_id'];
        $amr = substr($amr, 0, 60);
        $st = $this->db->prepare('INSERT INTO oidc_codes (ocode_hash, ocode_client_id, ocode_contact_id, ocode_user_id, ocode_redirect_uri, ocode_challenge, ocode_nonce, ocode_scope, ocode_auth_time, ocode_amr, ocode_expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)');
        $st->bind_param('siiissssisi', $h, $cid, $contactId, $userId, $pending['opend_redirect_uri'], $pending['opend_challenge'], $pending['opend_nonce'], $pending['opend_scope'], $authTime, $amr, $ttl);
        $st->execute();
        $st->close();
        $this->db->query('DELETE FROM oidc_codes WHERE ocode_expires_at < NOW() - INTERVAL 1 DAY');
        return $code;
    }

    /** The URL the browser is sent back to: redirect_uri + code/state (or error) + iss (RFC 9207). */
    public static function redirectUrl(string $redirectUri, array $params): string
    {
        $params['iss'] = (string) OidcConfig::issuer();
        $q = http_build_query(array_filter($params, static fn($x) => $x !== null && $x !== ''), '', '&', PHP_QUERY_RFC3986);
        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . $q;
    }

    // ---- token endpoint ---------------------------------------------------------------------------------------------------------

    /**
     * Exchanges an authorization code. $client is the AUTHENTICATED integration client row.
     * @return array{ok:true, response:array<string,mixed>}|array{ok:false, error:string, reason:string, contact_id:int}
     */
    public function exchangeCode(array $client, string $code, string $redirectUri, string $verifier): array
    {
        $fail = static fn(string $error, string $reason, int $contact = 0): array => ['ok' => false, 'error' => $error, 'reason' => $reason, 'contact_id' => $contact];
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $code) !== 1) {
            return $fail('invalid_grant', 'code_malformed');
        }
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier) !== 1) {
            return $fail('invalid_request', 'verifier_malformed');
        }
        $h = self::hash($code);
        $st = $this->db->prepare('SELECT *, (ocode_expires_at > NOW()) AS live FROM oidc_codes WHERE ocode_hash = ?');
        $st->bind_param('s', $h);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return $fail('invalid_grant', 'code_unknown');
        }
        // Burn the code first, atomically: whatever happens next, this code can never be presented again. Of two simultaneous requests
        // exactly one gets affected_rows = 1.
        $st = $this->db->prepare('UPDATE oidc_codes SET ocode_used_at = NOW() WHERE ocode_hash = ? AND ocode_used_at IS NULL');
        $st->bind_param('s', $h);
        $st->execute();
        $claimed = $st->affected_rows === 1;
        $st->close();
        $contactId = (int) $row['ocode_contact_id'];
        if (!$claimed) {
            // Replay: a used code was presented again. Everything issued from it is revoked (RFC 6749 section 4.1.2).
            $st = $this->db->prepare('UPDATE oidc_tokens SET otok_revoked_at = NOW() WHERE otok_code_hash = ? AND otok_revoked_at IS NULL');
            $st->bind_param('s', $h);
            $st->execute();
            $revoked = $st->affected_rows;
            $st->close();
            return $fail('invalid_grant', 'code_replay:revoked_' . $revoked, $contactId);
        }
        if ((int) $row['live'] !== 1) {
            return $fail('invalid_grant', 'code_expired', $contactId);
        }
        if ((int) $row['ocode_client_id'] !== (int) $client['client_id']) {
            return $fail('invalid_grant', 'code_client_mismatch', $contactId);
        }
        if (!hash_equals((string) $row['ocode_redirect_uri'], $redirectUri)) {
            return $fail('invalid_grant', 'redirect_uri_mismatch', $contactId);
        }
        if (!hash_equals((string) $row['ocode_challenge'], self::pkceChallenge($verifier))) {
            return $fail('invalid_grant', 'pkce_mismatch', $contactId);
        }
        // The person must still be allowed (provider on, account active) at the moment the tokens are minted, not just when the code was issued.
        $policy = QualityAccessPolicy::allows($contactId, $this->db);
        if (!$policy['ok']) {
            return $fail('invalid_grant', 'policy:' . $policy['reason'], $contactId);
        }
        $subject = QualityAccessPolicy::subject($this->db, $contactId);
        if ($subject === null) {
            return $fail('invalid_grant', 'contact_not_found', $contactId);
        }

        $now = time();
        $scopes = explode(' ', (string) $row['ocode_scope']);
        $claims = [
            'iss' => (string) OidcConfig::issuer(),
            'aud' => (string) $client['client_uid'],
            'sub' => $subject['sub'],
            'rivetit_id' => $subject['rivetit_id'],
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + (int) $this->cfg['id_token_ttl'],
            'auth_time' => (int) $row['ocode_auth_time'],
            'nonce' => (string) $row['ocode_nonce'],
            'amr' => array_values(array_filter(explode(',', (string) $row['ocode_amr']))),
            'jti' => bin2hex(random_bytes(12)),
        ];
        if (isset($subject['role'])) {
            $claims['rivetit_role'] = $subject['role'];   // present only for an administrator; department people carry no role claim
        }
        if (in_array('profile', $scopes, true) && (int) $client['ocfg_allow_profile'] === 1) {
            $claims['name'] = $subject['name'];
        }
        $access = self::TOKEN_PREFIX . self::randomId();
        $claims['at_hash'] = IntegrationAuth::b64uEncode(substr(hash('sha256', $access, true), 0, 16));
        $idToken = KeyStore::sign($this->db, $claims);

        $ah = self::hash($access);
        $ttl = (int) $this->cfg['access_token_ttl'];
        $cid = (int) $client['client_id'];
        $scope = (string) $row['ocode_scope'];
        $st = $this->db->prepare('INSERT INTO oidc_tokens (otok_hash, otok_code_hash, otok_client_id, otok_contact_id, otok_scope, otok_expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL ? SECOND)');
        $st->bind_param('ssiisi', $ah, $h, $cid, $contactId, $scope, $ttl);
        $st->execute();
        $st->close();
        $this->db->query('DELETE FROM oidc_tokens WHERE otok_expires_at < NOW() - INTERVAL 1 DAY');

        return ['ok' => true, 'contact_id' => $contactId, 'response' => [
            'access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'id_token' => $idToken, 'scope' => $scope,
        ]];
    }

    // ---- userinfo ---------------------------------------------------------------------------------------------------------------

    /**
     * @return array{ok:true, claims:array<string,string>}|array{ok:false, reason:string}
     */
    public function userinfo(string $bearer): array
    {
        if (!str_starts_with($bearer, self::TOKEN_PREFIX) || preg_match('/^rvo_[A-Za-z0-9_-]{43}$/D', $bearer) !== 1) {
            return ['ok' => false, 'reason' => 'token_malformed'];
        }
        $h = self::hash($bearer);
        $st = $this->db->prepare('SELECT t.otok_client_id, t.otok_contact_id, t.otok_scope, (t.otok_expires_at > NOW()) AS live, t.otok_revoked_at, c.client_uid
            FROM oidc_tokens t JOIN integration_clients c ON c.client_id = t.otok_client_id WHERE t.otok_hash = ?');
        $st->bind_param('s', $h);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) {
            return ['ok' => false, 'reason' => 'token_unknown'];
        }
        if ($row['otok_revoked_at'] !== null) {
            return ['ok' => false, 'reason' => 'token_revoked'];
        }
        if ((int) $row['live'] !== 1) {
            return ['ok' => false, 'reason' => 'token_expired'];
        }
        $client = OidcConfig::ssoClient($this->db, (string) $row['client_uid']);
        if ($client === null) {
            return ['ok' => false, 'reason' => 'client_inactive'];
        }
        $contactId = (int) $row['otok_contact_id'];
        $policy = QualityAccessPolicy::allows($contactId, $this->db);
        if (!$policy['ok']) {
            return ['ok' => false, 'reason' => 'policy:' . $policy['reason']];
        }
        $subject = QualityAccessPolicy::subject($this->db, $contactId);
        if ($subject === null) {
            return ['ok' => false, 'reason' => 'contact_not_found'];
        }
        $claims = ['sub' => $subject['sub'], 'rivetit_id' => $subject['rivetit_id']];
        if (isset($subject['role'])) {
            $claims['rivetit_role'] = $subject['role'];
        }
        if (in_array('profile', explode(' ', (string) $row['otok_scope']), true) && (int) $client['ocfg_allow_profile'] === 1) {
            $claims['name'] = $subject['name'];
        }
        return ['ok' => true, 'claims' => $claims, 'client_id' => (int) $row['otok_client_id'], 'contact_id' => $contactId];
    }

    /** The discovery document (OpenID Connect Discovery 1.0). Built only from the configured issuer. */
    public static function discovery(): array
    {
        $iss = (string) OidcConfig::issuer();
        return [
            'issuer' => $iss,
            'authorization_endpoint' => $iss . '/oidc/authorize',
            'token_endpoint' => $iss . '/oidc/token',
            'userinfo_endpoint' => $iss . '/oidc/userinfo',
            'jwks_uri' => $iss . '/oidc/jwks',
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => [KeyStore::ALG],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'scopes_supported' => OidcConfig::SCOPES,
            'claims_supported' => ['iss', 'aud', 'sub', 'rivetit_id', 'rivetit_role', 'iat', 'nbf', 'exp', 'auth_time', 'nonce', 'amr', 'jti', 'at_hash', 'name'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }
}
