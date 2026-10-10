<?php

namespace ITFlow\Mcp\OAuth;

/**
 * The OAuth 2.1 authorization server for Remote MCP: dynamic client registration (RFC 7591), authorization-code + PKCE
 * (S256 only), token issue with refresh-token rotation and reuse detection, revocation (RFC 7009). It is HTTP-free: the
 * endpoint scripts in /oauth/ parse the request and call these methods, which return [status, body] pairs or a decision.
 *
 * Public clients only (token_endpoint_auth_method "none"). No token ever leaves this class unless it is the one just
 * minted, and nothing here writes a code, token or verifier to a log, an audit row or an error message.
 */
final class OAuthService
{
    private OAuthStore $store;
    /** @var callable(string,?int,?string,mixed,string,?string,array):void */
    private $audit;

    /**
     * @param callable|null $audit (event, actorUserId, entityType, entityId, action, summary, metadata); defaults to the audit service
     */
    public function __construct(private \mysqli $db, private string $issuer, private string $resource, ?callable $audit = null)
    {
        $this->store = new OAuthStore($db);
        $this->audit = $audit ?? static function (string $event, ?int $actor, ?string $type, $id, string $action, ?string $summary, array $meta): void {
            try {
                \ITFlow\Audit\AuditService::record($event, $actor, $type, $id, $action, $summary, $meta);
            } catch (\Throwable $e) {
                error_log('MCP OAuth audit not recorded: ' . $e::class);
            }
        };
    }

    public function store(): OAuthStore
    {
        return $this->store;
    }

    private function audit(string $event, ?int $actor, ?string $type, $id, string $action, string $summary, array $meta = []): void
    {
        ($this->audit)($event, $actor, $type, $id, $action, $summary, $meta);
    }

    /* ------------------------------------------------------------------ helpers */

    private static function newSecret(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** https, or http on a loopback host (RFC 8252); no user info, no fragment, bounded length. */
    public static function redirectUriValid(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $uri)) {
            return false;
        }
        $p = parse_url($uri);
        if (!is_array($p) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || str_contains($uri, '#')) {
            return false;
        }
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        if ($scheme === 'https') {
            return true;
        }

        return $scheme === 'http' && in_array(strtolower($p['host']), ['127.0.0.1', '[::1]', 'localhost'], true);
    }

    /** "host" or "host:port" of a redirect URI, for the consent screen. */
    public static function redirectHost(string $uri): string
    {
        $p = parse_url($uri) ?: [];

        return strtolower((string) ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** Parse a query string or form body, keeping repeated names visible (RFC 6749: parameters must not repeat). */
    public static function parseParams(string $raw): array
    {
        $values = [];
        $dups = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $name = urldecode($kv[0]);
            $value = urldecode($kv[1] ?? '');
            if (array_key_exists($name, $values)) {
                $dups[$name] = true;
                continue;
            }
            $values[$name] = $value;
        }

        return ['values' => $values, 'duplicates' => array_keys($dups)];
    }

    private static function validUtf8(string $s): bool
    {
        return mb_check_encoding($s, 'UTF-8') && !preg_match('/[\x00-\x1f\x7f]/', $s);
    }

    /** @return list<string>|null the supported scopes asked for (default mcp:read), or null when none of them is supported */
    private static function grantedScopes(?string $requested): ?array
    {
        $asked = $requested === null || trim($requested) === '' ? [OAuthConfig::SCOPE] : preg_split('/ +/', trim($requested));
        // offline_access is how some clients ask for a refresh token; RivetIT always issues one, so it is simply ignored.
        $supported = array_values(array_unique(array_filter($asked, static fn ($s) => $s === OAuthConfig::SCOPE)));
        $unknown = array_diff($asked, [OAuthConfig::SCOPE, 'offline_access']);

        return $supported !== [] ? $supported : ($unknown === [] ? [OAuthConfig::SCOPE] : null);
    }

    /* ------------------------------------------------------------------ discovery */

    /** RFC 8414 authorization server metadata. */
    public function metadata(bool $registrationEnabled): array
    {
        $meta = [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer . '/oauth/authorize.php',
            'token_endpoint' => $this->issuer . '/oauth/token.php',
            'revocation_endpoint' => $this->issuer . '/oauth/revoke.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [OAuthConfig::SCOPE],
            'authorization_response_iss_parameter_supported' => true,
        ];
        if ($registrationEnabled) {
            $meta['registration_endpoint'] = $this->issuer . '/oauth/register.php';
        }

        return $meta;
    }

    /* ------------------------------------------------------------------ registration (RFC 7591) */

    /** @return array{0:int,1:array} [HTTP status, JSON body] */
    public function register(array $meta, string $ip): array
    {
        $err = static fn (int $status, string $code, string $desc): array => [$status, ['error' => $code, 'error_description' => $desc]];

        $uris = $meta['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || !array_is_list($uris) || count($uris) > OAuthConfig::MAX_REDIRECT_URIS) {
            return $err(400, 'invalid_redirect_uri', 'redirect_uris must be a list of 1 to ' . OAuthConfig::MAX_REDIRECT_URIS . ' addresses.');
        }
        foreach ($uris as $u) {
            if (!is_string($u) || !self::redirectUriValid($u)) {
                return $err(400, 'invalid_redirect_uri', 'Every redirect URI must be https, or http on localhost, 127.0.0.1 or [::1], without a fragment.');
            }
        }
        $uris = array_values(array_unique($uris));

        $method = $meta['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            return $err(400, 'invalid_client_metadata', 'Only public clients are supported (token_endpoint_auth_method "none").');
        }
        $grantTypes = $meta['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grantTypes) || array_diff($grantTypes, ['authorization_code', 'refresh_token']) !== [] || !in_array('authorization_code', $grantTypes, true)) {
            return $err(400, 'invalid_client_metadata', 'grant_types may only contain authorization_code and refresh_token.');
        }
        $responseTypes = $meta['response_types'] ?? ['code'];
        if (!is_array($responseTypes) || array_diff($responseTypes, ['code']) !== []) {
            return $err(400, 'invalid_client_metadata', 'response_types may only contain code.');
        }
        $name = $meta['client_name'] ?? 'Unnamed application';
        if (!is_string($name) || !self::validUtf8($name)) {
            return $err(400, 'invalid_client_metadata', 'client_name must be plain text.');
        }
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '') {
            $name = 'Unnamed application';
        }

        if ($this->store->countClients() >= OAuthConfig::MAX_CLIENTS) {
            // Full: make room by dropping what nobody has used for an hour, so a flood of junk cannot lock real apps out.
            $this->store->purgeUnusedClients(3600);
        }
        if ($this->store->countClients() >= OAuthConfig::MAX_CLIENTS
            || $this->store->registrationsFromIp($ip, 3600) >= OAuthConfig::REGISTER_PER_IP_HOUR) {
            return $err(429, 'temporarily_unavailable', 'Too many registrations. Try again later or ask an administrator to register the client.');
        }

        $id = self::newSecret('rvtc_');
        $this->store->createClient($id, $name, $uris, 'dynamic', $ip, null);
        $this->audit('mcp.oauth_client_registered', null, 'mcp_oauth_client', $id, 'create',
            'MCP client "' . mb_substr($name, 0, 60) . '" registered itself', ['redirect_hosts' => array_map([self::class, 'redirectHost'], $uris)]);

        return [201, [
            'client_id' => $id,
            'client_id_issued_at' => time(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => array_values($grantTypes),
            'response_types' => ['code'],
            'scope' => OAuthConfig::SCOPE,
        ]];
    }

    /** Administrator pre-registration. @return array{0:bool,1:string} [ok, client id or error message] */
    public function registerManual(string $name, array $uris, ?string $wantedId, int $adminUserId): array
    {
        $name = mb_substr(trim($name), 0, 100);
        if ($name === '' || !self::validUtf8($name)) {
            return [false, 'Enter a name for the client.'];
        }
        $uris = array_values(array_unique(array_filter(array_map('trim', $uris), static fn ($u) => $u !== '')));
        if ($uris === [] || count($uris) > OAuthConfig::MAX_REDIRECT_URIS) {
            return [false, 'Enter 1 to ' . OAuthConfig::MAX_REDIRECT_URIS . ' redirect addresses.'];
        }
        foreach ($uris as $u) {
            if (!self::redirectUriValid($u)) {
                return [false, 'Redirect addresses must be https, or http on localhost, 127.0.0.1 or [::1], without a fragment.'];
            }
        }
        $id = $wantedId !== null && $wantedId !== '' ? $wantedId : self::newSecret('rvtc_');
        if (!preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $id)) {
            return [false, 'A client ID is 8 to 64 letters, digits, dots, dashes or underscores.'];
        }
        if ($this->store->client($id)) {
            return [false, 'That client ID is already registered.'];
        }
        $this->store->createClient($id, $name, $uris, 'manual', null, $adminUserId);
        $this->audit('mcp.oauth_client_registered', $adminUserId, 'mcp_oauth_client', $id, 'create',
            'MCP client "' . mb_substr($name, 0, 60) . '" pre-registered by an administrator', ['redirect_hosts' => array_map([self::class, 'redirectHost'], $uris)]);

        return [true, $id];
    }

    /* ------------------------------------------------------------------ authorization request */

    /**
     * Validate an authorization request (GET query or consent form). Never trusts the client until the client id and the
     * redirect URI both match a registration; until then a failure is {fatal: message} and nothing may redirect.
     *
     * @param array{values:array<string,string>,duplicates:list<string>} $params from parseParams()
     * @return array{ok:true, client:array, redirect_uri:string, scope:string, state:?string, challenge:string, resource:string}
     *       |array{ok:false, fatal:string}
     *       |array{ok:false, redirect_uri:string, state:?string, error:string, description:string}
     */
    public function validateAuthorizationRequest(array $params): array
    {
        $v = $params['values'];
        $fatal = static fn (string $m): array => ['ok' => false, 'fatal' => $m];

        $clientId = $v['client_id'] ?? '';
        $client = $clientId !== '' && strlen($clientId) <= 64 ? $this->store->client($clientId) : null;
        if (!$client || $client['disabled_at'] !== null) {
            return $fatal('This application is not registered with RivetIT, or has been disabled.');
        }
        $redirect = $v['redirect_uri'] ?? '';
        // Exact string match against the registration; nothing is normalised, no prefix or port wildcard.
        $matched = false;
        foreach ($client['redirect_uris'] as $registered) {
            if (is_string($registered) && $redirect !== '' && hash_equals($registered, $redirect)) {
                $matched = true;
            }
        }
        if (!$matched) {
            return $fatal('The return address in this request does not match the one the application registered.');
        }

        $state = $v['state'] ?? null;
        $stateBad = $state !== null && (strlen($state) > 1024 || !self::validUtf8($state));
        if ($stateBad) {
            $state = null;   // never echo a value we just rejected
        }
        $fail = static fn (string $error, string $desc): array => ['ok' => false, 'redirect_uri' => $redirect, 'state' => $state, 'error' => $error, 'description' => $desc];

        if ($stateBad) {
            return $fail('invalid_request', 'The state value is too long or not plain text.');
        }
        if ($params['duplicates'] !== []) {
            return $fail('invalid_request', 'A parameter was sent more than once.');
        }
        if (($v['response_type'] ?? '') !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = $v['code_challenge'] ?? '';
        if ($challenge === '' || ($v['code_challenge_method'] ?? '') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
            return $fail('invalid_request', 'PKCE is required: send a code_challenge and code_challenge_method=S256.');
        }
        $resource = $v['resource'] ?? $this->resource;
        if (!hash_equals($this->resource, $resource)) {
            return $fail('invalid_target', 'The resource must be ' . $this->resource . '.');
        }
        $scopes = self::grantedScopes($v['scope'] ?? null);
        if ($scopes === null) {
            return $fail('invalid_scope', 'The only scope RivetIT offers is ' . OAuthConfig::SCOPE . '.');
        }

        return ['ok' => true, 'client' => $client, 'redirect_uri' => $redirect, 'scope' => implode(' ', $scopes),
            'state' => $state, 'challenge' => $challenge, 'resource' => $resource];
    }

    /** Build the redirect back to the client, always carrying state (when sent) and iss (RFC 9207). */
    public function redirectUrl(string $redirectUri, array $params, ?string $state): string
    {
        $params['iss'] = $this->issuer;
        if ($state !== null) {
            $params['state'] = $state;
        }
        $sep = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** The signed-in user approved: mint a one-time code and return the URL to send the browser to. */
    public function approve(array $request, int $userId): string
    {
        $code = self::newSecret('rvt_ac_');
        $this->store->insertCode(OAuthStore::hash($code), $request['client']['client_id'], $userId, $request['redirect_uri'],
            $request['challenge'], $request['scope'], $request['resource']);
        $this->audit('mcp.oauth_consent_granted', $userId, 'mcp_oauth_client', $request['client']['client_id'], 'approve',
            'User approved MCP client "' . mb_substr($request['client']['client_name'], 0, 60) . '"', ['scope' => $request['scope']]);

        return $this->redirectUrl($request['redirect_uri'], ['code' => $code], $request['state']);
    }

    public function deny(array $request, int $userId): string
    {
        $this->audit('mcp.oauth_consent_denied', $userId, 'mcp_oauth_client', $request['client']['client_id'], 'deny',
            'User declined MCP client "' . mb_substr($request['client']['client_name'], 0, 60) . '"');

        return $this->redirectUrl($request['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user declined.'], $request['state']);
    }

    /* ------------------------------------------------------------------ token endpoint */

    private static function tokenError(string $error, string $desc, int $status = 400): array
    {
        return [$status, ['error' => $error, 'error_description' => $desc]];
    }

    /**
     * @param array<string,string> $p form parameters of the token request
     * @return array{0:int,1:array}
     */
    public function token(array $p): array
    {
        $clientId = $p['client_id'] ?? '';
        if (isset($p['client_secret']) || isset($p['client_assertion']) || $clientId === '' || strlen($clientId) > 64) {
            return self::tokenError('invalid_client', 'This server only has public clients: send client_id and no secret.', 401);
        }
        $client = $this->store->client($clientId);
        if (!$client || $client['disabled_at'] !== null) {
            return self::tokenError('invalid_client', 'Unknown or disabled client.', 401);
        }
        if (isset($p['resource']) && !hash_equals($this->resource, $p['resource'])) {
            return self::tokenError('invalid_target', 'The resource must be ' . $this->resource . '.');
        }

        return match ($p['grant_type'] ?? '') {
            'authorization_code' => $this->exchangeCode($p, $client),
            'refresh_token' => $this->refresh($p, $client),
            default => self::tokenError('unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    private function exchangeCode(array $p, array $client): array
    {
        $code = $p['code'] ?? '';
        $verifier = $p['code_verifier'] ?? '';
        $redirect = $p['redirect_uri'] ?? '';
        if ($code === '' || strlen($code) > 200 || $redirect === '') {
            return self::tokenError('invalid_request', 'code and redirect_uri are required.');
        }
        if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $verifier)) {
            return self::tokenError('invalid_request', 'code_verifier is required (PKCE).');
        }
        $hash = OAuthStore::hash($code);
        $redeemed = $this->store->redeemCode($hash);
        if ($redeemed['status'] === 'reused') {
            // A second use of a code: whoever holds the tokens from the first use may be the thief, so end that consent.
            $grant = $this->store->grantByOriginCode($hash);
            if ($grant && $this->store->revokeGrant((int) $grant['grant_id'], 'code_reuse')) {
                $this->audit('mcp.oauth_reuse_detected', (int) $grant['user_id'], 'mcp_oauth_grant', $grant['grant_id'], 'revoke',
                    'An authorization code was used twice; the consent was revoked', ['kind' => 'code', 'client_id' => $grant['client_id']]);
            }
        }
        if ($redeemed['status'] !== 'ok') {
            return self::tokenError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }
        $row = $redeemed['row'];
        // Bound to this client, this redirect URI and the PKCE verifier. Every mismatch answers the same way.
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $bound = hash_equals((string) $row['client_id'], $client['client_id'])
            && hash_equals((string) $row['redirect_uri'], $redirect)
            && hash_equals((string) $row['code_challenge'], $challenge);
        if (!$bound || !$this->store->userIsActiveAgent((int) $row['user_id'])) {
            return self::tokenError('invalid_grant', 'The authorization code is invalid, expired or already used.');
        }

        $grantId = $this->store->createGrant($client['client_id'], (int) $row['user_id'], $row['scope'], $row['resource'], $hash);
        $this->store->touchClient($client['client_id']);
        $this->audit('mcp.oauth_token_issued', (int) $row['user_id'], 'mcp_oauth_grant', $grantId, 'issue',
            'MCP access granted to "' . mb_substr($client['client_name'], 0, 60) . '"', ['client_id' => $client['client_id'], 'scope' => $row['scope']]);
        $this->maybePurge();

        return [200, $this->issue($grantId, $row['scope'])];
    }

    private function refresh(array $p, array $client): array
    {
        $presented = $p['refresh_token'] ?? '';
        if ($presented === '' || strlen($presented) > 200) {
            return self::tokenError('invalid_request', 'refresh_token is required.');
        }
        $hash = OAuthStore::hash($presented);
        $known = $this->store->token($hash);
        $grant = $known && $known['token_kind'] === 'refresh' ? $this->store->grant((int) $known['grant_id']) : null;
        // Not a refresh token we issued, or issued to a different client: nothing changes, nothing is revealed.
        if (!$grant || !hash_equals((string) $grant['client_id'], $client['client_id'])) {
            return self::tokenError('invalid_grant', 'The refresh token is invalid, expired or revoked.');
        }

        $redeemed = $this->store->redeemRefresh($hash);
        if ($redeemed['status'] === 'reused') {
            if ($this->store->revokeGrant((int) $grant['grant_id'], 'refresh_reuse')) {
                $this->audit('mcp.oauth_reuse_detected', (int) $grant['user_id'], 'mcp_oauth_grant', $grant['grant_id'], 'revoke',
                    'A refresh token was used twice; the consent was revoked', ['kind' => 'refresh', 'client_id' => $grant['client_id']]);
            }
        }
        if ($redeemed['status'] !== 'ok') {
            return self::tokenError('invalid_grant', 'The refresh token is invalid, expired or revoked.');
        }

        if (!$this->store->grantIsLive((int) $grant['grant_id']) || !$this->store->userIsActiveAgent((int) $grant['user_id'])) {
            return self::tokenError('invalid_grant', 'The refresh token is invalid, expired or revoked.');
        }
        $scope = $grant['scope'];
        if (isset($p['scope']) && trim($p['scope']) !== '') {
            $asked = preg_split('/ +/', trim($p['scope']));
            if (array_diff($asked, explode(' ', $scope)) !== []) {
                return self::tokenError('invalid_scope', 'A refresh cannot widen the scope.');
            }
            $scope = implode(' ', array_values(array_unique($asked)));
        }
        $this->store->touchClient($client['client_id']);
        $this->audit('mcp.oauth_token_refreshed', (int) $grant['user_id'], 'mcp_oauth_grant', $grant['grant_id'], 'refresh',
            'MCP access refreshed for "' . mb_substr($client['client_name'], 0, 60) . '"', ['client_id' => $client['client_id']]);

        return [200, $this->issue((int) $grant['grant_id'], $scope)];
    }

    /** Mint a fresh access token and refresh token for a consent. The plain values are returned once and never stored. */
    private function issue(int $grantId, string $scope): array
    {
        $access = self::newSecret('rvt_at_');
        $refresh = self::newSecret('rvt_rt_');
        $this->store->insertToken(OAuthStore::hash($access), $grantId, 'access', OAuthConfig::ACCESS_TTL);
        $this->store->insertToken(OAuthStore::hash($refresh), $grantId, 'refresh', OAuthConfig::REFRESH_TTL);

        return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => OAuthConfig::ACCESS_TTL,
            'refresh_token' => $refresh, 'scope' => $scope];
    }

    private function maybePurge(): void
    {
        if (random_int(1, 25) === 1) {
            try {
                $this->store->purge();
            } catch (\Throwable $e) {
                error_log('MCP OAuth purge failed: ' . $e::class);
            }
        }
    }

    /* ------------------------------------------------------------------ revocation (RFC 7009) */

    /**
     * Revoking either kind of token ends the whole consent (the person said "stop", not "stop this one token").
     * Unknown, expired or someone else's tokens answer success without doing anything, so the endpoint reveals nothing.
     *
     * @return array{0:int,1:array}
     */
    public function revoke(array $p): array
    {
        $token = $p['token'] ?? '';
        $clientId = $p['client_id'] ?? '';
        if ($token === '' || strlen($token) > 200) {
            return self::tokenError('invalid_request', 'token is required.');
        }
        if (isset($p['client_secret']) || $clientId === '' || strlen($clientId) > 64) {
            return self::tokenError('invalid_client', 'This server only has public clients: send client_id and no secret.', 401);
        }
        $row = $this->store->token(OAuthStore::hash($token));
        $grant = $row ? $this->store->grant((int) $row['grant_id']) : null;
        if ($grant && hash_equals((string) $grant['client_id'], $clientId) && $this->store->revokeGrant((int) $grant['grant_id'], 'revoked_by_client')) {
            $this->audit('mcp.oauth_grant_revoked', (int) $grant['user_id'], 'mcp_oauth_grant', $grant['grant_id'], 'revoke',
                'The MCP client revoked its own access', ['client_id' => $clientId]);
        }

        return [200, []];
    }

    /* ------------------------------------------------------------------ people and administrators */

    /** A person ends one of their own connections. */
    public function revokeForUser(int $grantId, int $userId): bool
    {
        $grant = $this->store->grant($grantId);
        if (!$grant || (int) $grant['user_id'] !== $userId) {
            return false;
        }
        $done = $this->store->revokeGrant($grantId, 'revoked_by_user');
        if ($done) {
            $this->audit('mcp.oauth_grant_revoked', $userId, 'mcp_oauth_grant', $grantId, 'revoke', 'User revoked an MCP connection', ['client_id' => $grant['client_id']]);
        }

        return $done;
    }

    public function revokeByAdmin(int $grantId, int $adminId): bool
    {
        $grant = $this->store->grant($grantId);
        if (!$grant) {
            return false;
        }
        $done = $this->store->revokeGrant($grantId, 'revoked_by_admin');
        if ($done) {
            $this->audit('mcp.oauth_grant_revoked', $adminId, 'mcp_oauth_grant', $grantId, 'revoke',
                'Administrator revoked an MCP connection of user #' . (int) $grant['user_id'], ['client_id' => $grant['client_id'], 'user_id' => (int) $grant['user_id']]);
        }

        return $done;
    }
}
