<?php

namespace ITFlow\Integrations\Microsoft;

use ITFlow\Integrations\ConnectionResult;
use ITFlow\Integrations\ExternalUser;

/**
 * Microsoft Graph client using the app-only client-credentials OAuth2 flow
 * (Section 9.1) - suitable for an unattended background sync, not
 * per-user delegated access (that's Section 9.3's separate SSO flow).
 *
 * Tested against a local mock Graph server (tests/mock/graph_mock.php); NOT yet verified against a real tenant. Requires Graph
 * application permissions User.Read.All (and Device.Read.All /
 * DeviceManagementManagedDevices.Read.All once Phase 7/Intune uses this)
 * granted with admin consent.
 */
class GraphClient
{
    public const DEFAULT_GRAPH_BASE = 'https://graph.microsoft.com/v1.0';
    public const DEFAULT_AUTHORITY = 'https://login.microsoftonline.com';
    /** The cached token is replaced this many seconds before Entra says it expires. */
    public const REFRESH_MARGIN = 300;

    public const PERM_USERS = 'User.Read.All';
    public const PERM_DEVICES = 'DeviceManagementManagedDevices.Read.All';
    public const PERM_ORG = 'Organization.Read.All';
    /** Only needed for the account actions in lifecycle workflows (see docs/ENTRA_INTUNE_SETUP.md); off unless the admin enables them. */
    public const PERM_USERS_WRITE = 'User.ReadWrite.All';
    public const PERM_GROUPS_WRITE = 'Group.ReadWrite.All';

    private const USER_SELECT = 'id,displayName,mail,userPrincipalName,accountEnabled';

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    private GraphTokenCache $cache;

    /** @var array{requests:int,retries:int,token_fetches:int,token_cache_hits:int,pages:int} */
    private array $stats = ['requests' => 0, 'retries' => 0, 'token_fetches' => 0, 'token_cache_hits' => 0, 'pages' => 0];

    private string $graphBase;
    private string $authority;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private int $maxRetryAfter;
    private float $baseBackoff;
    private int $maxPages;
    private ?int $deadline;
    private bool $allowWrites;
    /** @var callable */
    private $sleep;
    /** @var callable */
    private $now;

    /**
     * @param array{allow_writes?:bool,timeout?:int,connect_timeout?:int,max_retries?:int,max_retry_after?:int,base_backoff?:float,max_pages?:int,deadline?:?int,sleep?:callable,now?:callable} $options
     *   deadline: unix time after which no further request or wait is started (a hard time limit for a sync run).
     *   sleep/now: injectable so tests never really sleep.
     *   $graphBase / $authority are injectable (tests point them at a local mock); the defaults are Microsoft's endpoints.
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        ?GraphTokenCache $tokenCache = null,
        string $graphBase = self::DEFAULT_GRAPH_BASE,
        string $authority = self::DEFAULT_AUTHORITY,
        array $options = []
    ) {
        $this->cache = $tokenCache ?? new InMemoryGraphTokenCache();
        $this->graphBase = rtrim($graphBase, '/');
        $this->authority = rtrim($authority, '/');
        $this->timeout = max(1, (int) ($options['timeout'] ?? 30));
        $this->connectTimeout = max(1, (int) ($options['connect_timeout'] ?? 10));
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 4));
        $this->maxRetryAfter = max(1, (int) ($options['max_retry_after'] ?? 60));
        $this->baseBackoff = max(0.0, (float) ($options['base_backoff'] ?? 1.0));
        $this->maxPages = max(1, (int) ($options['max_pages'] ?? 500));
        $this->deadline = isset($options['deadline']) ? (int) $options['deadline'] : null;
        // Writes to Entra are refused unless the caller says the 'Allow RivetIT to change Entra accounts' setting is on.
        $this->allowWrites = !empty($options['allow_writes']);
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void { usleep((int) round($seconds * 1000000)); };
        $this->now = $options['now'] ?? 'time';
    }

    /** @return array{requests:int,retries:int,token_fetches:int,token_cache_hits:int,pages:int} */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * Proves the tenant / client ID / secret (token request), lists the application permissions granted to the app (the
     * token's `roles` claim), and probes each feature in $features ('users', 'devices') with a one-row call, so a missing
     * admin consent is reported by name. Does not require Organization.Read.All.
     *
     * @param string[] $features
     */
    public function testConnection(array $features = []): ConnectionResult
    {
        try {
            $this->token();
        } catch (GraphException $e) {
            return new ConnectionResult(false, $e->getMessage(), ['error_code' => $e->errorCode]);
        }

        $roles = self::tokenRoles((string) $this->accessToken);
        $details = ['roles' => $roles, 'probes' => []];
        $failures = [];
        $probes = ['users' => ['/users?$select=id&$top=1', self::PERM_USERS], 'devices' => ['/deviceManagement/managedDevices?$select=id&$top=1', self::PERM_DEVICES]];
        foreach ($features as $feature) {
            if (!isset($probes[$feature])) {
                continue;
            }
            try {
                $this->request('GET', $probes[$feature][0], $probes[$feature][1]);
                $details['probes'][$feature] = 'ok';
            } catch (GraphException $e) {
                $details['probes'][$feature] = $e->errorCode;
                $failures[] = $e;
            }
        }

        // Informational: the organisation name, when the app is allowed to read it.
        try {
            [, $body] = $this->request('GET', '/organization?$select=displayName,id', self::PERM_ORG);
            $details['organization'] = json_decode($body, true)['value'][0]['displayName'] ?? null;
        } catch (GraphException) {
            $details['organization'] = null;
        }

        if ($failures) {
            $details['error_code'] = $failures[0]->errorCode;

            return new ConnectionResult(false, implode(' ', array_map(static fn (GraphException $f) => $f->getMessage(), $failures)), $details);
        }

        return new ConnectionResult(true, null, $details);
    }

    /**
     * @return ExternalUser[]
     */
    public function listUsers(?string $nextLink = null): array
    {
        $path = $nextLink ?? '/users?$select=' . self::USER_SELECT . '&$top=100';
        [, $body] = $this->request('GET', $path, self::PERM_USERS, true);

        $data = $this->decode($body);
        $users = [];
        foreach ($data['value'] ?? [] as $u) {
            $users[] = new ExternalUser(
                externalId: $u['id'],
                displayName: $u['displayName'] ?? null,
                email: $u['mail'] ?? $u['userPrincipalName'] ?? null,
                enabled: (bool) ($u['accountEnabled'] ?? false),
                raw: $u
            );
        }

        return $users;
    }

    public function getUser(string $externalId): ?ExternalUser
    {
        try {
            [, $body] = $this->request('GET', '/users/' . rawurlencode($externalId) . '?$select=' . self::USER_SELECT, self::PERM_USERS);
        } catch (GraphException $e) {
            if ($e->httpStatus === 404) {
                return null;
            }
            throw $e;
        }

        $u = $this->decode($body);

        return new ExternalUser(
            externalId: $u['id'],
            displayName: $u['displayName'] ?? null,
            email: $u['mail'] ?? $u['userPrincipalName'] ?? null,
            enabled: (bool) ($u['accountEnabled'] ?? false),
            raw: $u
        );
    }

    /**
     * Fetches every user in the tenant, fully paginated, with the extra
     * profile fields Microsoft directory sync needs (job title, phone
     * numbers, department) that listUsers()'s slimmer $select above
     * doesn't request. Returns raw Graph user dicts, not ExternalUser
     * objects - matches OdooClient::listEmployees()'s raw-array
     * convention, which MicrosoftDirectoryMapper is written against.
     *
     * PERMISSION SCOPE: needs the Graph application permission
     * User.Read.All granted with admin consent on this app registration -
     * a DIFFERENT permission from DeviceManagementManagedDevices.Read.All,
     * which listAllManagedDevices() below needs for Intune device sync.
     *
     * @return array[]
     */
    public function getUsers(): array
    {
        return $this->getAllPages('/users?$select=id,displayName,mail,userPrincipalName,accountEnabled,jobTitle,mobilePhone,businessPhones,department&$top=100', self::PERM_USERS);
    }

    /**
     * Fetches every Intune-managed device from Graph, fully paginated.
     * Returns raw Graph device dicts (not wrapped in a value object) since
     * the Intune asset mapper needs many device-specific fields.
     *
     * @return array[]
     */
    public function listAllManagedDevices(): array
    {
        return $this->getAllPages('/deviceManagement/managedDevices?$select=id,deviceName,serialNumber,operatingSystem,osVersion,manufacturer,model,complianceState,managementAgent,lastSyncDateTime,azureADDeviceId,userPrincipalName,enrolledDateTime,isEncrypted&$top=100', self::PERM_DEVICES);
    }

    // ----- account writes (off unless the admin enabled 'Allow RivetIT to change Entra accounts') -------------------------
    // Every method below goes through writeRequest(), which refuses to send anything while writes are not allowed. None of them
    // deletes anything. Request bodies (which can carry a temporary password) are never logged or put in an exception message.

    public function writesAllowed(): bool
    {
        return $this->allowWrites;
    }

    /**
     * Create a user. $spec: userPrincipalName, displayName, mailNickname, accountEnabled (bool), password (temporary; the user must
     * change it at first sign-in). Needs User.ReadWrite.All. @return array{id:string,userPrincipalName:string}
     */
    public function createUser(array $spec): array
    {
        $body = json_encode([
            'accountEnabled' => (bool) ($spec['accountEnabled'] ?? false),
            'displayName' => (string) $spec['displayName'],
            'mailNickname' => (string) $spec['mailNickname'],
            'userPrincipalName' => (string) $spec['userPrincipalName'],
            'passwordProfile' => ['forceChangePasswordNextSignIn' => true, 'password' => (string) $spec['password']],
        ], JSON_UNESCAPED_UNICODE);
        [, $resp] = $this->writeRequest('POST', '/users', self::PERM_USERS_WRITE, $body);
        $u = $this->decode($resp);
        if (empty($u['id'])) {
            throw new GraphException('Microsoft Graph did not return the new user.', GraphException::BAD_RESPONSE);
        }

        return ['id' => (string) $u['id'], 'userPrincipalName' => (string) ($u['userPrincipalName'] ?? $spec['userPrincipalName'])];
    }

    /** Sign-in on or off. Never deletes the account. */
    public function setAccountEnabled(string $userId, bool $enabled): void
    {
        $this->writeRequest('PATCH', '/users/' . rawurlencode($userId), self::PERM_USERS_WRITE, json_encode(['accountEnabled' => $enabled]));
    }

    /** Invalidates the user's refresh tokens and session cookies, so existing sign-ins stop working. */
    public function revokeSignInSessions(string $userId): void
    {
        $this->writeRequest('POST', '/users/' . rawurlencode($userId) . '/revokeSignInSessions', self::PERM_USERS_WRITE, '{}');
    }

    /** Adds the user to a group. Already a member counts as success. Needs Group.ReadWrite.All. @return bool true when added now */
    public function addGroupMember(string $groupId, string $userId): bool
    {
        $body = json_encode(['@odata.id' => $this->graphBase . '/directoryObjects/' . rawurlencode($userId)]);
        try {
            $this->writeRequest('POST', '/groups/' . rawurlencode($groupId) . '/members/$ref', self::PERM_GROUPS_WRITE, $body);
        } catch (GraphException $e) {
            if ($e->httpStatus === 400 && stripos($e->getMessage(), 'already exist') !== false) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Removes the user from a group. Not a member counts as success. @return bool true when removed now */
    public function removeGroupMember(string $groupId, string $userId): bool
    {
        try {
            $this->writeRequest('DELETE', '/groups/' . rawurlencode($groupId) . '/members/' . rawurlencode($userId) . '/$ref', self::PERM_GROUPS_WRITE);
        } catch (GraphException $e) {
            if ($e->httpStatus === 404) {
                return false;
            }
            throw $e;
        }

        return true;
    }

    /** Find one user by UPN, then by mail. Null when none; throws when the mail address matches more than one user. */
    public function findUserByEmail(string $email): ?ExternalUser
    {
        $user = $this->getUser($email);
        if ($user !== null) {
            return $user;
        }
        $filter = rawurlencode("mail eq '" . str_replace("'", "''", $email) . "'");
        [, $body] = $this->request('GET', '/users?$select=' . self::USER_SELECT . '&$filter=' . $filter . '&$top=2', self::PERM_USERS);
        $rows = $this->decode($body)['value'] ?? [];
        if (count($rows) > 1) {
            throw new GraphException('More than one Entra user has that email address; not changing any of them.', GraphException::OTHER);
        }
        if (!$rows) {
            return null;
        }
        $u = $rows[0];

        return new ExternalUser(externalId: $u['id'], displayName: $u['displayName'] ?? null, email: $u['mail'] ?? $u['userPrincipalName'] ?? null, enabled: (bool) ($u['accountEnabled'] ?? false), raw: $u);
    }

    private function writeRequest(string $method, string $path, string $permission, ?string $json = null): array
    {
        if (!$this->allowWrites) {
            throw new GraphException("RivetIT is not allowed to change Entra accounts: turn on 'Allow RivetIT to change Entra accounts' (Administration > Integrations > Directory Sync) first. Nothing was sent.", GraphException::WRITES_DISABLED);
        }

        return $this->request($method, $path, $permission, false, $json);
    }

    /**
     * Follows @odata.nextLink until it is absent. Bounded: at most $maxPages pages, a repeated link is a loop, and a link
     * that points anywhere other than the Graph host is refused (the bearer token must never be sent elsewhere).
     *
     * @return array[]
     */
    private function getAllPages(string $path, string $permission): array
    {
        $items = [];
        $seen = [];
        $pages = 0;
        while ($path !== null) {
            if (++$pages > $this->maxPages) {
                throw new GraphException("Stopped after {$this->maxPages} pages of results (page cap) - the tenant returned more data than expected.", GraphException::BAD_RESPONSE);
            }
            if (isset($seen[$path])) {
                throw new GraphException('Microsoft Graph returned a pagination loop (the same page link twice); sync stopped.', GraphException::BAD_RESPONSE);
            }
            $seen[$path] = true;

            [, $body] = $this->request('GET', $path, $permission, true);
            $this->stats['pages']++;
            $data = $this->decode($body);
            foreach ($data['value'] ?? [] as $row) {
                $items[] = $row;
            }
            $next = $data['@odata.nextLink'] ?? null;
            $path = is_string($next) && $next !== '' ? $next : null;
        }

        return $items;
    }

    // ----- token ---------------------------------------------------------------------------------------------------

    /** A usable bearer token: memory, then the shared cache, then Entra. Replaced REFRESH_MARGIN seconds before expiry. */
    private function token(bool $forceRefresh = false): string
    {
        $now = (int) ($this->now)();
        if (!$forceRefresh) {
            if ($this->accessToken !== null && $this->tokenExpiresAt - $now > self::REFRESH_MARGIN) {
                return $this->accessToken;
            }
            $cached = $this->cache->get();
            if ($cached !== null && $cached['expires_at'] - $now > self::REFRESH_MARGIN) {
                $this->accessToken = $cached['token'];
                $this->tokenExpiresAt = $cached['expires_at'];
                $this->stats['token_cache_hits']++;

                return $this->accessToken;
            }
        }

        $url = $this->authority . '/' . rawurlencode($this->tenantId) . '/oauth2/v2.0/token';
        $form = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        $this->stats['token_fetches']++;
        $tries = 0;
        while (true) {
            [$status, $respHeaders, $body] = $this->send('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], $form);
            if (!in_array($status, [429, 503, 504], true)) {
                break;
            }
            $retryAfter = self::parseRetryAfter($respHeaders['retry-after'] ?? null, $now);
            $wait = $retryAfter !== null ? min($retryAfter, $this->maxRetryAfter) : min($this->maxRetryAfter, $this->baseBackoff * (2 ** $tries));
            if ($tries >= $this->maxRetries || ($this->deadline !== null && $now + $wait >= $this->deadline)) {
                throw new GraphException('Microsoft sign-in is throttling requests (HTTP ' . $status . '); try again later.', GraphException::THROTTLED, $status, $retryAfter);
            }
            ($this->sleep)((float) $wait);
            $tries++;
            $this->stats['retries']++;
        }

        $data = json_decode($body, true);
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            $desc = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? "HTTP $status") : "HTTP $status";
            $desc = trim(strtok($desc, "\r\n"));
            throw new GraphException('Microsoft sign-in failed (' . $desc . '). Check the tenant ID, client ID and client secret (the secret may have expired).', GraphException::AUTH_FAILED, $status);
        }

        $this->accessToken = (string) $data['access_token'];
        $this->tokenExpiresAt = $now + max(60, (int) ($data['expires_in'] ?? 3600));
        $this->cache->put($this->accessToken, $this->tokenExpiresAt);

        return $this->accessToken;
    }

    private function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = 0;
        $this->cache->clear();
    }

    /** The application permissions Entra put in the token (`roles` claim), or null when the token is not a readable JWT. */
    public static function tokenRoles(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $json = base64_decode(strtr($parts[1], '-_', '+/'), false);
        $claims = $json !== false ? json_decode($json, true) : null;

        return is_array($claims) ? array_values(array_map('strval', (array) ($claims['roles'] ?? []))) : null;
    }

    // ----- requests ------------------------------------------------------------------------------------------------

    /**
     * One authenticated Graph call with retry: 429/503/504 wait (Retry-After, else exponential backoff) up to maxRetries
     * times; a 401 refreshes the token once.
     *
     * @return array{0:int,1:string} [http status, body] for 2xx only; everything else throws GraphException
     */
    private function request(string $method, string $path, string $permission, bool $absoluteIfFullUrl = false, ?string $json = null): array
    {
        $url = $path;
        if (!($absoluteIfFullUrl && preg_match('#^https?://#i', $path))) {
            $url = $this->graphBase . $path;
        } elseif (!$this->sameOrigin($path)) {
            throw new GraphException('Microsoft Graph returned a page link to a different host; refusing to send the access token there.', GraphException::BAD_RESPONSE);
        }

        $retries = 0;
        $refreshed = false;
        while (true) {
            $token = $this->token();
            [$status, $headers, $body] = $this->send($method, $url, ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'], $json);

            if ($status >= 200 && $status < 300) {
                return [$status, $body];
            }
            if ($status === 401 && !$refreshed) {
                $refreshed = true;
                $this->forgetToken();
                continue;
            }
            if (in_array($status, [429, 503, 504], true)) {
                $retryAfter = self::parseRetryAfter($headers['retry-after'] ?? null, (int) ($this->now)());
                if ($retries >= $this->maxRetries) {
                    throw new GraphException('Microsoft Graph is throttling requests (HTTP ' . $status . ') and did not recover after ' . $this->maxRetries . ' retries; try again later.' . ($retryAfter !== null ? " Microsoft asked to wait {$retryAfter}s." : ''), GraphException::THROTTLED, $status, $retryAfter);
                }
                $wait = $retryAfter !== null ? min($retryAfter, $this->maxRetryAfter) : min($this->maxRetryAfter, $this->baseBackoff * (2 ** $retries));
                if ($this->deadline !== null && (int) ($this->now)() + $wait >= $this->deadline) {
                    throw new GraphException('Microsoft Graph is throttling requests (HTTP ' . $status . ') and the sync time limit would be exceeded by waiting; try again later.', GraphException::THROTTLED, $status, $retryAfter);
                }
                ($this->sleep)((float) $wait);
                $retries++;
                $this->stats['retries']++;
                continue;
            }

            throw $this->classify($status, $body, $permission);
        }
    }

    private function classify(int $status, string $body, string $permission): GraphException
    {
        $data = json_decode($body, true);
        $code = is_array($data) ? (string) ($data['error']['code'] ?? '') : '';
        $msg = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        $short = trim(strtok($msg !== '' ? $msg : substr($body, 0, 200), "\r\n"));

        if ($status === 401) {
            return new GraphException('Microsoft Graph rejected the access token (HTTP 401): ' . $short, GraphException::AUTH_FAILED, 401);
        }
        if ($status === 403) {
            return new GraphException("Admin consent missing for $permission: the app registration needs the Microsoft Graph application permission $permission, with admin consent granted (Entra ID > App registrations > API permissions > Grant admin consent). Graph said: " . $short, GraphException::CONSENT_MISSING, 403);
        }
        if (($status === 400 || $status === 404) && (stripos($msg, 'not applicable to target tenant') !== false || stripos($msg, 'license') !== false)) {
            return new GraphException('This tenant does not support that request (for Intune: no Intune license or Intune not set up). Graph said: ' . $short, GraphException::NOT_APPLICABLE, $status);
        }
        if ($status >= 500) {
            return new GraphException("Microsoft Graph returned HTTP $status: " . $short, GraphException::SERVER_ERROR, $status);
        }

        return new GraphException("Graph API returned HTTP $status" . ($code !== '' ? " ($code)" : '') . ': ' . $short, GraphException::OTHER, $status);
    }

    private function decode(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new GraphException('Microsoft Graph returned a response that is not JSON.', GraphException::BAD_RESPONSE);
        }

        return $data;
    }

    private function sameOrigin(string $url): bool
    {
        $a = parse_url($url);
        $b = parse_url($this->graphBase);

        return $a && $b && strtolower($a['scheme'] ?? '') === strtolower($b['scheme'] ?? '')
            && strtolower($a['host'] ?? '') === strtolower($b['host'] ?? '')
            && ($a['port'] ?? null) === ($b['port'] ?? null)
            && !isset($a['user']);
    }

    /** Retry-After is delay-seconds or an HTTP date. Returns whole seconds (>= 0) or null. */
    public static function parseRetryAfter(?string $value, int $now): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $ts = strtotime($value);

        return $ts === false ? null : max(0, $ts - $now);
    }

    /**
     * One HTTP round trip, with timeouts and a deadline check. Network failures are classified and thrown.
     *
     * @return array{0:int,1:array<string,string>,2:string} [status, lower-cased headers, body]
     */
    private function send(string $method, string $url, array $headers, ?string $form = null): array
    {
        if ($this->deadline !== null && (int) ($this->now)() >= $this->deadline) {
            throw new GraphException('The sync time limit was reached before Microsoft Graph finished responding.', GraphException::TIME_LIMIT);
        }

        $this->stats['requests']++;
        $respHeaders = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = explode(':', $line, 2);
                if (count($p) === 2) {
                    $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                }

                return strlen($line);
            },
        ];
        if ($form !== null) {
            $opts[CURLOPT_POSTFIELDS] = $form;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new GraphException(($timedOut ? 'Timed out waiting for Microsoft' : 'Could not reach Microsoft') . ' (' . ($err !== '' ? $err : "curl error $errno") . ').', GraphException::UNREACHABLE);
        }

        return [$status, $respHeaders, (string) $body];
    }
}
