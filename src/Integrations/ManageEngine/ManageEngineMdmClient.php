<?php

namespace ITFlow\Integrations\ManageEngine;

use ITFlow\Integrations\ConnectionResult;

/**
 * Read-only client for the ManageEngine Mobile Device Manager Plus API, two modes:
 *
 *  - ON-PREMISES (self::onPrem()): base {ServerURL}/api/v1/mdm, the API key (Admin > Integrations > API Key Generation, valid 6 months) goes in the
 *    Authorization header ONLY, exactly as generated ("Authorization: {api_key}"). The key cannot be refreshed: a 401 means it is expired or revoked.
 *    The server URL is vetted by RivetCore's UrlPolicy (the admin's allowed internal networks apply) and the connection is pinned to the vetted addresses.
 *  - CLOUD (the constructor):
 *
 * Cloud authentication: a Zoho "self client" refresh token is exchanged at https://accounts.zoho.<dc>/oauth/v2/token for a one-hour access
 * token (grant_type=refresh_token), sent as "Authorization: Zoho-oauthtoken <token>". The host of every request comes from
 * DataCenters (fixed allowlist); the "paging.next" URL a response may carry is NEVER followed (paging uses limit/offset on our own host).
 *
 * MSP edition: setCustomer() adds the X-CUSTOMER header (seen in every MSP example request of the docs) and listCustomers() reads GET customers.
 *
 * Only GET requests exist here; there is no code path that locks, wipes or changes a device. Scopes needed (read only):
 *   MDMOnDemand.MDMInventory.READ   device list and device details
 *   MDMOnDemand.MDMDeviceMgmt.READ  device summary (last contact time, groups)
 *
 * Built from ManageEngine's published docs and tested against a local mock (tests/mock/manageengine_mdm_mock.php); NOT yet verified
 * against a live tenant. See docs/MANAGEENGINE_MDM.md for every assumption.
 */
final class ManageEngineMdmClient implements MdmClient
{
    /** The cached token is replaced this many seconds before it expires. */
    public const REFRESH_MARGIN = 300;
    /** A response larger than this is refused (the biggest real page is a few hundred KB). */
    public const MAX_RESPONSE_BYTES = 8388608;
    /** Docs: "paginated to 50 items by default"; the docs' own example uses limit=200 and states no cap. */
    public const DEFAULT_PAGE_SIZE = 100;

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;
    private MdmTokenStore $store;
    private string $mdmBase;
    private string $accountsBase;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private int $maxRetryAfter;
    private float $baseBackoff;
    private int $maxPages;
    private int $pageSize;
    private ?int $deadline;
    private string $mode = 'cloud';
    private string $apiKey = '';
    private ?string $customerId = null;
    private bool $verifyTls = true;
    private int $maxBytes;
    /** @var array{host:string,port:int,ips:list<string>}|null the vetted target an on-premises connection is pinned to */
    private ?array $pin = null;
    private bool $allowHttpLoopback = false;
    /** @var callable */
    private $sleep;
    /** @var callable */
    private $now;

    /** @var array{requests:int,retries:int,token_fetches:int,token_cache_hits:int,pages:int} */
    private array $stats = ['requests' => 0, 'retries' => 0, 'token_fetches' => 0, 'token_cache_hits' => 0, 'pages' => 0];

    /**
     * @param array{mdm_base?:string,accounts_base?:string,timeout?:int,connect_timeout?:int,max_retries?:int,max_retry_after?:int,base_backoff?:float,max_pages?:int,page_size?:int,deadline?:?int,sleep?:callable,now?:callable} $options
     *   mdm_base / accounts_base exist so tests can point at a local mock; production code never passes them (the factory takes them
     *   only from config.php constants, never from the web UI).
     */
    public function __construct(
        private readonly string $dataCenter,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $refreshToken,
        ?MdmTokenStore $store = null,
        array $options = []
    ) {
        $this->store = $store ?? new InMemoryMdmTokenStore();
        $this->mdmBase = rtrim((string) ($options['mdm_base'] ?? DataCenters::mdmBase($dataCenter)), '/');
        $this->accountsBase = rtrim((string) ($options['accounts_base'] ?? DataCenters::accountsBase($dataCenter)), '/');
        $this->timeout = max(1, (int) ($options['timeout'] ?? 30));
        $this->connectTimeout = max(1, (int) ($options['connect_timeout'] ?? 10));
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 4));
        $this->maxRetryAfter = max(1, (int) ($options['max_retry_after'] ?? 60));
        $this->baseBackoff = max(0.0, (float) ($options['base_backoff'] ?? 1.0));
        $this->maxPages = max(1, (int) ($options['max_pages'] ?? 200));
        $this->pageSize = max(1, min(500, (int) ($options['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $this->allowHttpLoopback = !empty($options['allow_http_loopback']) || (defined('RIVETIT_MDM_ALLOW_HTTP_LOOPBACK') && RIVETIT_MDM_ALLOW_HTTP_LOOPBACK === true);
        $this->maxBytes = max(1024, (int) ($options['max_bytes'] ?? self::MAX_RESPONSE_BYTES));
        if (($options['mode'] ?? '') === 'onprem') {
            $this->mode = 'onprem';
            $this->apiKey = (string) ($options['api_key'] ?? '');
            $this->verifyTls = ($options['verify_tls'] ?? true) !== false;
            $this->pin = $options['pin'] ?? null;
        }
        $this->deadline = isset($options['deadline']) ? (int) $options['deadline'] : null;
        $this->sleep = $options['sleep'] ?? static function (float $seconds): void { usleep((int) round($seconds * 1000000)); };
        $this->now = $options['now'] ?? 'time';
    }

    /** @return array{requests:int,retries:int,token_fetches:int,token_cache_hits:int,pages:int} */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * An on-premises client. $serverUrl is the base the admin entered ({ServerURL}); the caller (MdmClientFactory) has already vetted it and
     * passes the pinned target in $options['pin'].
     *
     * @param array<string,mixed> $options same options as the constructor, plus verify_tls (bool) and pin
     */
    public static function onPrem(string $serverUrl, string $apiKey, array $options = []): self
    {
        return new self('us', '', '', '', null, ['mdm_base' => $serverUrl, 'accounts_base' => 'https://invalid.invalid', 'mode' => 'onprem', 'api_key' => $apiKey] + $options);
    }

    public function isOnPrem(): bool
    {
        return $this->mode === 'onprem';
    }

    public function setCustomer(?string $customerId): void
    {
        if ($customerId !== null && !preg_match('/^[0-9]{1,30}$/', $customerId)) {
            throw new MdmException('Unexpected customer id format from ManageEngine.', MdmException::BAD_RESPONSE);
        }
        $this->customerId = $customerId;
    }

    /**
     * MSP edition. The docs list "Get Customer list" as GET /customers (scope MDMOnDemand.MDMAdmin.READ) with a `customers` array of
     * customer_id / customer_name; the path prefix is assumed to be the same /api/v1/mdm as the device endpoints (UNVERIFIED).
     *
     * @return list<array{customer_id:string,customer_name:string}>
     */
    public function listCustomers(): array
    {
        $saved = $this->customerId;
        $this->customerId = null;   // the customer list is account-wide
        try {
            $data = $this->getJson('/api/v1/mdm/customers');
        } finally {
            $this->customerId = $saved;
        }
        $out = [];
        foreach (is_array($data['customers'] ?? null) ? $data['customers'] : [] as $c) {
            if (!is_array($c)) {
                continue;
            }
            $id = (string) ($c['customer_id'] ?? '');
            if (preg_match('/^[0-9]{1,30}$/', $id)) {
                $out[] = ['customer_id' => $id, 'customer_name' => trim(strip_tags((string) ($c['customer_name'] ?? '')))];
            }
        }

        return $out;
    }

    /**
     * Proves the credentials (token refresh), the inventory scope (a one-row device list) and the device-management scope (the
     * summary of the first device, when there is one).
     */
    public function testConnection(): ConnectionResult
    {
        try {
            $this->token();
            $data = $this->getJson('/api/v1/mdm/devices', ['limit' => 1, 'offset' => 0]);
        } catch (MdmException $e) {
            return new ConnectionResult(false, $e->getMessage(), ['error_code' => $e->errorCode]);
        }
        $devices = self::deviceRows($data);
        $details = ['inventory' => 'ok', 'device_management' => 'not_checked', 'total' => self::totalCount($data)];
        if ($devices !== []) {
            $first = (string) ($devices[0]['device_id'] ?? '');
            if ($first !== '') {
                try {
                    $this->getDeviceSummary($first);
                    $details['device_management'] = 'ok';
                } catch (MdmException $e) {
                    $details['device_management'] = $e->errorCode;
                    if ($e->errorCode === MdmException::SCOPE_MISSING) {
                        return new ConnectionResult(false, 'Connected, but the token cannot read device summaries (last contact time and groups). ' . ($this->mode === 'onprem' ? 'Generate the API key with permission for device summaries too.' : 'Generate the self-client code with BOTH scopes: ' . self::SCOPE_INVENTORY . ',' . self::SCOPE_DEVICE_MGMT), ['error_code' => $e->errorCode] + $details);
                    }
                }
            }
        }

        return new ConnectionResult(true, null, $details);
    }

    /**
     * Every device, page by page (limit/offset; the next offset is the number of devices collected so far, so a server that caps
     * `limit` below ours, for example at its default of 50, is still read completely). Stops on a page with no new device id (a
     * server that ignores offset would otherwise loop forever), on a short page that does not say more follow, when the
     * response says there is no next page, or at the page cap.
     *
     * @return list<array<string,mixed>>
     */
    public function listAllDevices(): array
    {
        $all = [];
        $seen = [];
        for ($page = 0; $page < $this->maxPages; $page++) {
            $data = $this->getJson('/api/v1/mdm/devices', ['limit' => $this->pageSize, 'offset' => count($all)]);
            $this->stats['pages']++;
            $rows = self::deviceRows($data);
            $new = 0;
            foreach ($rows as $row) {
                $id = (string) ($row['device_id'] ?? '');
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $all[] = $row;
                $new++;
            }
            if ($new === 0) {
                return $all;
            }
            $total = self::totalCount($data);
            if ($total !== null && count($all) >= $total && count($rows) < $this->pageSize) {
                return $all;
            }
            // The "paging" object says whether more follow (its "next" link itself is never requested). With no such object a
            // short page is the last one.
            if (isset($data['paging']) && is_array($data['paging'])) {
                if (empty($data['paging']['next'])) {
                    return $all;
                }
            } elseif (count($rows) < $this->pageSize && ($total === null || count($all) >= $total)) {
                return $all;
            }
        }
        throw new MdmException('ManageEngine returned more than ' . $this->maxPages . ' pages of devices; stopping.', MdmException::BAD_RESPONSE);
    }

    /** @return array<string,mixed> the device-details object (battery, storage, supervised, security ...) */
    public function getDevice(string $deviceId): array
    {
        $data = $this->getJson('/api/v1/mdm/devices/' . self::safeId($deviceId));

        return isset($data['device']) && is_array($data['device']) ? $data['device'] : $data;
    }

    /** @return array<string,mixed> the device-summary object (last_contact_time, group[]) */
    public function getDeviceSummary(string $deviceId): array
    {
        $data = $this->getJson('/api/v1/mdm/devices/' . self::safeId($deviceId) . '/summary');

        return isset($data['summary']) && is_array($data['summary']) && !isset($data['last_contact_time']) ? $data['summary'] : $data;
    }

    /** @return list<array<string,mixed>> */
    public static function deviceRows(array $data): array
    {
        $rows = $data['devices'] ?? [];

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    public static function totalCount(array $data): ?int
    {
        $t = $data['metadata']['total_record_count'] ?? null;

        return is_numeric($t) ? (int) $t : null;
    }

    private static function safeId(string $id): string
    {
        if (!preg_match('/^[0-9]{1,30}$/', $id)) {
            throw new MdmException('Unexpected device id format from ManageEngine.', MdmException::BAD_RESPONSE);
        }

        return $id;
    }

    // ------------------------------------------------------------------------------------------------------------------------

    private function token(bool $forceRefresh = false): string
    {
        if ($this->mode === 'onprem') {
            return $this->apiKey;   // no exchange: the key itself is the credential
        }
        $now = (int) ($this->now)();
        if (!$forceRefresh) {
            if ($this->accessToken !== null && $this->tokenExpiresAt - $now > self::REFRESH_MARGIN) {
                return $this->accessToken;
            }
            $cached = $this->store->get();
            if ($cached !== null && $cached['expires_at'] - $now > self::REFRESH_MARGIN) {
                $this->accessToken = $cached['token'];
                $this->tokenExpiresAt = $cached['expires_at'];
                $this->stats['token_cache_hits']++;

                return $this->accessToken;
            }
        }

        $url = $this->accountsBase . '/oauth/v2/token';
        $form = http_build_query([
            'refresh_token' => $this->refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
        $this->stats['token_fetches']++;
        $tries = 0;
        while (true) {
            [$status, $headers, $body] = $this->send('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], $form);
            if (!in_array($status, [429, 503, 504], true)) {
                break;
            }
            $retryAfter = self::parseRetryAfter($headers['retry-after'] ?? null, $now);
            $wait = $retryAfter !== null ? min($retryAfter, $this->maxRetryAfter) : min($this->maxRetryAfter, $this->baseBackoff * (2 ** $tries));
            if ($tries >= $this->maxRetries || ($this->deadline !== null && $now + $wait >= $this->deadline)) {
                throw new MdmException('Zoho sign-in is throttling requests (HTTP ' . $status . '); try again later.', MdmException::THROTTLED, $status, $retryAfter);
            }
            ($this->sleep)((float) $wait);
            $tries++;
            $this->stats['retries']++;
        }

        $data = json_decode($body, true);
        if (is_array($data) && empty($data['access_token'])) {
            // Zoho reports most token errors as HTTP 200 with {"error": "..."}; its throttle is a text message in the same shape.
            $err = strtolower((string) ($data['error'] ?? '') . ' ' . (string) ($data['error_description'] ?? ''));
            if (str_contains($err, 'too many requests') || str_contains($err, 'try again after some time')) {
                throw new MdmException('Zoho is limiting token requests for this client (at most 10 access tokens per 10 minutes). Wait a few minutes and try again.', MdmException::THROTTLED, $status);
            }
        }
        if ($status !== 200 || !is_array($data) || empty($data['access_token'])) {
            $desc = is_array($data) ? (string) ($data['error'] ?? $data['error_description'] ?? "HTTP $status") : "HTTP $status";
            $desc = preg_replace('/[^A-Za-z0-9 _.,:;()\/-]/', '', trim((string) strtok($desc, "\r\n"))) ?? '';
            throw new MdmException('Zoho sign-in failed (' . substr($desc, 0, 120) . '). Check the data center, client id, client secret and refresh token (the token may have been revoked).', MdmException::AUTH_FAILED, $status);
        }

        $this->accessToken = (string) $data['access_token'];
        $this->tokenExpiresAt = $now + max(60, (int) ($data['expires_in'] ?? $data['expires_in_sec'] ?? 3600));
        $this->store->put($this->accessToken, $this->tokenExpiresAt);

        return $this->accessToken;
    }

    /**
     * One-time setup helper: exchanges the single-use code from the Zoho API console ("Generate Code" tab of a Self Client) for the
     * long-lived refresh token, so nobody has to run curl. The code is short lived and works once. Returns the refresh token; the
     * caller stores it encrypted and never shows it. Errors are worded for a person: a used or expired code is by far the common one.
     */
    public function exchangeAuthorizationCode(string $code): string
    {
        $form = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
        ]);
        [$status, , $body] = $this->send('POST', $this->accountsBase . '/oauth/v2/token', ['Content-Type: application/x-www-form-urlencoded'], $form);
        $data = json_decode($body, true);
        if ($status === 200 && is_array($data) && !empty($data['refresh_token']) && is_string($data['refresh_token'])) {
            return $data['refresh_token'];
        }
        $reason = is_array($data) ? (string) ($data['error'] ?? $data['error_description'] ?? "HTTP $status") : "HTTP $status";
        $reason = substr(preg_replace('/[^A-Za-z0-9 _.,:;()\/-]/', '', trim((string) strtok($reason, "\r\n"))) ?? '', 0, 80);
        if ($status === 200 && is_array($data) && !empty($data['access_token']) && empty($data['refresh_token'])) {
            $reason = 'no refresh token came back; this code was probably already used. Generate a new code';
        }
        throw new MdmException('Zoho did not accept the code (' . $reason . '). Codes work once and expire within minutes: generate a new one in the Zoho API console and paste it straight away. Also check the data center, client id and client secret.', MdmException::AUTH_FAILED, $status);
    }

    private function forgetToken(): void
    {
        $this->accessToken = null;
        $this->tokenExpiresAt = 0;
        $this->store->clear();
    }

    /** @return array<string,mixed> */
    private function getJson(string $path, array $query = []): array
    {
        $url = $this->mdmBase . $path . ($query ? '?' . http_build_query($query) : '');
        $retries = 0;
        $refreshed = false;
        while (true) {
            $token = $this->token();
            $hdr = [$this->mode === 'onprem' ? 'Authorization: ' . $token : 'Authorization: Zoho-oauthtoken ' . $token];
            if ($this->customerId !== null) {
                $hdr[] = 'X-CUSTOMER: ' . $this->customerId;
            }
            [$status, $headers, $body] = $this->send('GET', $url, $hdr);

            if ($status >= 200 && $status < 300) {
                $data = json_decode($body, true, 512, JSON_BIGINT_AS_STRING);
                if (!is_array($data)) {
                    throw new MdmException('ManageEngine returned a response that is not JSON.', MdmException::BAD_RESPONSE, $status);
                }

                return $data;
            }
            if ($status === 401 && !$refreshed && $this->mode !== 'onprem') {
                $refreshed = true;
                $this->forgetToken();
                continue;
            }
            if (in_array($status, [429, 503, 504], true)) {
                $retryAfter = self::parseRetryAfter($headers['retry-after'] ?? null, (int) ($this->now)());
                if ($retries >= $this->maxRetries) {
                    throw new MdmException('ManageEngine is throttling requests (HTTP ' . $status . ') and did not recover after ' . $this->maxRetries . ' retries; try again later.', MdmException::THROTTLED, $status, $retryAfter);
                }
                $wait = $retryAfter !== null ? min($retryAfter, $this->maxRetryAfter) : min($this->maxRetryAfter, $this->baseBackoff * (2 ** $retries));
                if ($this->deadline !== null && (int) ($this->now)() + $wait >= $this->deadline) {
                    throw new MdmException('ManageEngine is throttling requests (HTTP ' . $status . ') and the sync time limit would be exceeded by waiting; try again later.', MdmException::THROTTLED, $status, $retryAfter);
                }
                ($this->sleep)((float) $wait);
                $retries++;
                $this->stats['retries']++;
                continue;
            }
            if ($status >= 500 && $retries < 1) {
                // COM0004 "Internal server error, retry later": one retry, then report it.
                $retries++;
                $this->stats['retries']++;
                ($this->sleep)(min($this->maxRetryAfter, max(0.0, $this->baseBackoff)));
                continue;
            }

            throw $this->classify($status, $body);
        }
    }

    private function classify(int $status, string $body): MdmException
    {
        $data = json_decode($body, true);
        $code = is_array($data) ? (string) ($data['error_code'] ?? '') : '';
        $desc = is_array($data) ? (string) ($data['error_description'] ?? '') : '';
        $short = preg_replace('/[^A-Za-z0-9 _.,:;()\/-]/', '', trim((string) strtok($desc !== '' ? $desc : substr($body, 0, 160), "\r\n"))) ?? '';
        $short = substr($short, 0, 160);
        $codeText = $code !== '' ? " ($code)" : '';

        if ($status === 401 && $this->mode === 'onprem') {
            return new MdmException('The MDM server rejected the API key (HTTP 401' . $codeText . '): it is wrong, was revoked, or has expired (keys are valid for 6 months; generate a new one under Admin > Integrations > API Key Generation). ' . $short, MdmException::AUTH_FAILED, 401);
        }
        if ($status === 401) {
            return new MdmException('ManageEngine rejected the access token (HTTP 401' . $codeText . '): ' . $short, MdmException::AUTH_FAILED, 401);
        }
        if ($status === 403) {
            return new MdmException('ManageEngine refused the request (HTTP 403' . $codeText . '): ' . ($this->mode === 'onprem' ? 'the API key lacks the permission for this call, or the key owner may not use the API.' : 'the token lacks a scope, or the account is not allowed to use the API. Needed: ' . self::SCOPE_INVENTORY . ' and ' . self::SCOPE_DEVICE_MGMT . '.') . ' Said: ' . $short, MdmException::SCOPE_MISSING, 403);
        }
        if ($status >= 500) {
            return new MdmException("ManageEngine returned HTTP $status$codeText: " . $short, MdmException::SERVER_ERROR, $status);
        }

        return new MdmException("ManageEngine API returned HTTP $status$codeText: " . $short, MdmException::OTHER, $status);
    }

    /**
     * https only. Plain http is accepted for a LOOPBACK host and only when the explicit test constant RIVETIT_MDM_ALLOW_HTTP_LOOPBACK (config.php of a
     * test rig, never set in production) or the allow_http_loopback option is on.
     */
    public static function assertTransport(string $url, bool $allowHttpLoopback): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && $allowHttpLoopback && in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return;
        }
        throw new MdmException('ManageEngine connections must use https.', MdmException::OTHER);
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

    /** @return array{0:int,1:array<string,string>,2:string} [status, lower-cased headers, body] */
    private function send(string $method, string $url, array $headers, ?string $form = null): array
    {
        if ($this->deadline !== null && (int) ($this->now)() >= $this->deadline) {
            throw new MdmException('The sync time limit was reached before ManageEngine finished responding.', MdmException::TIME_LIMIT);
        }

        self::assertTransport($url, $this->allowHttpLoopback);
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
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => $this->mode === 'onprem' ? $this->verifyTls : true,
            CURLOPT_SSL_VERIFYHOST => ($this->mode === 'onprem' && !$this->verifyTls) ? 0 : 2,
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
        if ($this->pin !== null && $this->pin['ips'] !== []) {
            // DNS-rebinding guard: connect to exactly the addresses UrlPolicy vetted.
            $opts[CURLOPT_RESOLVE] = [$this->pin['host'] . ':' . $this->pin['port'] . ':' . implode(',', array_map(static fn (string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $this->pin['ips']))];
        }
        // Response size cap: stop reading as soon as the body is larger than the limit.
        $received = '';
        $tooBig = false;
        $max = $this->maxBytes;
        unset($opts[CURLOPT_RETURNTRANSFER]);
        $opts[CURLOPT_WRITEFUNCTION] = static function ($c, string $chunk) use (&$received, &$tooBig, $max): int {
            if (strlen($received) + strlen($chunk) > $max) {
                $tooBig = true;

                return 0;   // abort the transfer
            }
            $received .= $chunk;

            return strlen($chunk);
        };
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $body = $ok === false ? false : $received;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            throw new MdmException('ManageEngine returned a response larger than ' . intdiv($this->maxBytes, 1048576) . ' MB; refusing to read it.', MdmException::BAD_RESPONSE, $status ?: null);
        }
        if ($body === false) {
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new MdmException(($timedOut ? 'Timed out waiting for ManageEngine' : 'Could not reach ManageEngine') . ' (' . ($err !== '' ? $err : "curl error $errno") . ').', MdmException::UNREACHABLE);
        }

        return [$status, $respHeaders, (string) $body];
    }
}
