<?php

namespace ITFlow\Quality;

use ITFlow\Integration\ApiResponse;

/**
 * The client for the QMS API (contract: docs/QUALITY_CONTRACT.md, docs/integrations/qms-api-contract.md). RivetIT only ever talks to the QMS through this
 * class, and only through the QMS's authenticated HTTP API (never its database).
 *
 *  - Transport: HTTPS, vetted address pinned for the connection (QmsUrl), no redirects, connect and total timeouts from the settings, a 1 MiB cap on a
 *    response, correlation id on every request (X-Correlation-Id), versioned base path (/api/v1).
 *  - Machine credential: OAuth 2.0 client credentials (client_secret_basic) against the QMS token endpoint on the SAME origin; the access token is cached
 *    sealed in the database until shortly before it expires. Secrets and tokens are never logged and never appear in an exception message.
 *  - Per-user authorization: a request for a person's items (/me/... and /quality/...) MUST carry a UserContext; without one the call is refused here, before
 *    any HTTP. /integrations/... is for the system itself and must not carry one. A service token never stands in for a person.
 *  - Retries: bounded (3 attempts), exponential backoff with jitter, only for a transport failure, 408, 429 (Retry-After honoured, capped), 502, 503, 504, and
 *    only for a GET or a request that carries an Idempotency-Key. A 401 refreshes the token once. A time budget caps the whole call (the portal uses a short one).
 *  - Pagination: {"data":[...],"next_cursor":null|"..."}.
 */
final class QmsClient
{
    public const API_PREFIX = '/api/v1';
    public const MAX_RESPONSE_BYTES = 1048576;
    public const MAX_ATTEMPTS = 3;
    public const USER_AGENT = 'RivetIT-Quality/1';

    /** @var array<string,mixed> */
    private array $row;
    private string $base = '';
    /** @var ?array{host:string,port:int,ips:list<string>} */
    private ?array $target = null;
    private int $backoffBaseMs = 250;
    /** @var callable(int):void */
    private $sleeper;

    private function __construct(private readonly \mysqli $db, array $row)
    {
        $this->row = $row;
        $this->sleeper = static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /** The client for the saved settings, or null when the connection is not configured. */
    public static function fromDb(\mysqli $db): ?self
    {
        $row = QualityConfig::load($db);
        return QualityConfig::configured($row) ? new self($db, $row) : null;
    }

    /** For tests: shortens the backoff and replaces the sleeper. */
    public function withBackoff(int $baseMs, ?callable $sleeper = null): self
    {
        $this->backoffBaseMs = max(0, $baseMs);
        if ($sleeper !== null) {
            $this->sleeper = $sleeper;
        }
        return $this;
    }

    /** The configured base address (as saved; vetted again per call). */
    public function baseUrl(): string
    {
        return rtrim((string) $this->row['qs_qms_url'], '/');
    }

    /** The audience a user context is issued for. */
    public function audience(): string
    {
        return $this->baseUrl();
    }

    // ---- calls ------------------------------------------------------------------------------------------------------------------------

    /**
     * @param array{query?:array<string,scalar>, json?:array<mixed>, user?:?UserContext, idempotency_key?:?string, budget_s?:int, contact_id?:?int, log?:bool} $o
     * @return array{status:int, data:?array<mixed>, headers:array<string,string>, correlation_id:string, attempts:int, ms:int}
     * @throws QmsException
     */
    public function request(string $method, string $path, array $o = []): array
    {
        $method = strtoupper($method);
        $cid = ApiResponse::correlationId();
        $cid = preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $cid) === 1 ? $cid : bin2hex(random_bytes(8));
        $cid = substr($cid . '-q' . bin2hex(random_bytes(3)), 0, 64);   // one id per outbound call, traceable to the inbound request
        $log = ($o['log'] ?? true) === true;
        try {
            return $this->requestInner($method, $path, $o, $cid);
        } catch (QmsException $e) {
            if ($log) {
                QualityLog::record($this->db, 'client', $e->errCode, $method . ' ' . $this->logPath($path) . ': ' . $e->getMessage(), $e->correlationId !== '' ? $e->correlationId : $cid,
                    $e->http > 0 ? $e->http : null, isset($o['contact_id']) ? (int) $o['contact_id'] : null);
            }
            throw $e;
        }
    }

    /** @return array{status:int, data:?array<mixed>, headers:array<string,string>, correlation_id:string, attempts:int, ms:int} */
    private function requestInner(string $method, string $path, array $o, string $cid): array
    {
        if (preg_match('~^/[A-Za-z0-9/_.%-]{1,200}$~D', $path) !== 1 || str_contains($path, '..') || str_contains($path, '//')) {
            throw new QmsException('Invalid API path.', QmsException::REJECTED, 0, $cid);
        }
        $personal = str_starts_with($path, '/me/') || str_starts_with($path, '/quality/');
        $user = $o['user'] ?? null;
        if ($personal && !$user instanceof UserContext) {
            throw new QmsException('A request for a person\'s Quality items needs that person\'s user context; a service token alone is never used.', QmsException::CONTEXT, 0, $cid);
        }
        if (!$personal && $user instanceof UserContext) {
            throw new QmsException('A system request must not carry a user context.', QmsException::CONTEXT, 0, $cid);
        }
        $idem = isset($o['idempotency_key']) && is_string($o['idempotency_key']) && $o['idempotency_key'] !== '' ? $o['idempotency_key'] : null;
        if ($idem !== null && preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $idem) !== 1) {
            throw new QmsException('Invalid idempotency key.', QmsException::REJECTED, 0, $cid);
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $idem === null) {
            throw new QmsException('A write to the QMS needs an idempotency key.', QmsException::REJECTED, 0, $cid);
        }
        $canRetry = $method === 'GET' || $idem !== null;
        $budget = max(1, min(120, (int) ($o['budget_s'] ?? 20)));
        $started = microtime(true);

        $this->vet($cid);
        $url = $this->base . self::API_PREFIX . $path;
        if (!empty($o['query'])) {
            $url .= '?' . http_build_query($o['query'], '', '&', PHP_QUERY_RFC3986);
        }
        $body = isset($o['json']) ? json_encode($o['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null;

        $attempt = 0;
        $refreshed = false;
        $last = null;
        while (true) {
            $attempt++;
            $token = $this->accessToken($cid, false);
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token, 'X-Correlation-Id: ' . $cid, 'X-Rivet-Contract: 1', 'User-Agent: ' . self::USER_AGENT];
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
            }
            if ($idem !== null) {
                $headers[] = 'Idempotency-Key: ' . $idem;
            }
            if ($user instanceof UserContext) {
                $headers[] = 'X-Rivet-User-Context: ' . $user->jwt();
            }
            $res = $this->send($method, $url, $headers, $body, $this->remaining($started, $budget));
            $last = $res;

            if ($res['errno'] === 0 && $res['status'] === 401 && !$refreshed) {
                $refreshed = true;
                $this->accessToken($cid, true);   // the QMS may have rotated or revoked the token: ask for a new one once
                $attempt--;
                continue;
            }
            $retryAfter = null;
            $retry = false;
            if ($res['errno'] !== 0) {
                $retry = true;
            } elseif (in_array($res['status'], [408, 429, 502, 503, 504], true)) {
                $retry = true;
                if (isset($res['headers']['retry-after']) && ctype_digit($res['headers']['retry-after'])) {
                    $retryAfter = (int) $res['headers']['retry-after'] * 1000;
                }
            }
            if ($retry && $canRetry && $attempt < self::MAX_ATTEMPTS) {
                $delay = $retryAfter !== null ? min($retryAfter, 5000) : (int) ($this->backoffBaseMs * (2 ** ($attempt - 1)) + random_int(0, max(1, (int) ($this->backoffBaseMs / 2))));
                if ($this->remaining($started, $budget) * 1000 > $delay + 500) {
                    ($this->sleeper)($delay);
                    continue;
                }
            }
            break;
        }
        return $this->finish($last, $attempt, $cid, $started);
    }

    /** @return array{status:int, data:?array<mixed>, headers:array<string,string>, correlation_id:string, attempts:int, ms:int} */
    private function finish(array $res, int $attempts, string $cid, float $started): array
    {
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($res['errno'] !== 0) {
            $timeout = $res['errno'] === 28;
            throw new QmsException($timeout ? 'The QMS did not answer in time.' : 'Could not reach the QMS (' . $this->curlWhy($res['errno']) . ').',
                $timeout ? QmsException::TIMEOUT : QmsException::TRANSPORT, 0, $cid, true);
        }
        $status = $res['status'];
        $theirCid = $res['headers']['x-correlation-id'] ?? '';
        $theirCid = preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $theirCid) === 1 ? $theirCid : '';
        $data = null;
        if ($res['body'] !== '') {
            $j = json_decode($res['body'], true);
            $data = is_array($j) ? $j : null;
        }
        if ($status >= 200 && $status < 300) {
            if ($status !== 204 && $res['body'] !== '' && $data === null) {
                throw new QmsException('The QMS answered with something that is not JSON.', QmsException::BAD_RESPONSE, $status, $cid);
            }
            $this->touchOk();
            return ['status' => $status, 'data' => $data, 'headers' => $res['headers'], 'correlation_id' => $cid, 'attempts' => $attempts, 'ms' => $ms];
        }
        // The QMS's own error code is shown (a short token), never its message text.
        $theirCode = is_array($data['error'] ?? null) && is_string($data['error']['code'] ?? null) && preg_match('/^[a-z0-9_.-]{1,60}$/D', $data['error']['code']) === 1 ? ' (' . $data['error']['code'] . ')' : '';
        $why = "The QMS answered HTTP $status$theirCode" . ($theirCid !== '' ? ", its correlation id $theirCid" : '') . '.';
        throw match (true) {
            $status === 401 => new QmsException('The QMS rejected the access token. ' . $why, QmsException::AUTH, 401, $cid),
            $status === 403 => new QmsException('The QMS refused this request. ' . $why, QmsException::FORBIDDEN, 403, $cid),
            $status === 404 => new QmsException('Not found in the QMS. ' . $why, QmsException::NOT_FOUND, 404, $cid),
            $status === 429 => new QmsException('The QMS rate-limited this request. ' . $why, QmsException::RATE_LIMITED, 429, $cid, true),
            $status >= 500 => new QmsException($why, QmsException::SERVER, $status, $cid, true),
            default => new QmsException($why, QmsException::REJECTED, $status, $cid),
        };
    }

    /** All items of a paged list, following next_cursor, bounded by $maxPages. @return list<array<string,mixed>> */
    public function all(string $path, array $query, ?UserContext $user, int $maxPages = 10, array $o = []): array
    {
        $items = [];
        $cursor = null;
        for ($i = 0; $i < max(1, $maxPages); $i++) {
            $q = $query + ['limit' => 50];
            if ($cursor !== null) {
                $q['cursor'] = $cursor;
            }
            $r = $this->request('GET', $path, ['query' => $q, 'user' => $user] + $o);
            foreach ((array) ($r['data']['data'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $cursor = $r['data']['next_cursor'] ?? null;
            if (!is_string($cursor) || $cursor === '') {
                break;
            }
        }
        return $items;
    }

    /**
     * Connection test for the admin page: token, then GET /integrations/health. Never throws.
     *
     * @return array{ok:bool, message:string, ms:int, status:?int, health:?array<string,mixed>}
     */
    public function test(): array
    {
        $t = microtime(true);
        try {
            $r = $this->request('GET', '/integrations/health', ['budget_s' => 15, 'log' => false]);
            $h = is_array($r['data']) ? $r['data'] : [];
            $okField = $h['status'] ?? 'ok';
            $ok = in_array($okField, ['ok', 'healthy', 'up'], true);
            $ms = (int) round((microtime(true) - $t) * 1000);
            return ['ok' => $ok, 'message' => $ok ? 'Connected. The QMS reports itself healthy.' : 'Connected, but the QMS reports status "' . substr(preg_replace('/[^A-Za-z0-9 _.-]/', '', (string) $okField) ?? '', 0, 40) . '".',
                'ms' => $ms, 'status' => $r['status'], 'health' => $h];
        } catch (QmsException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'ms' => (int) round((microtime(true) - $t) * 1000), 'status' => $e->http ?: null, 'health' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'The test failed (' . get_class($e) . ').', 'ms' => (int) round((microtime(true) - $t) * 1000), 'status' => null, 'health' => null];
        }
    }

    /**
     * Sends one signed event to the QMS (no bearer token: the HMAC proves the sender), a single attempt. The outbox decides about retries.
     *
     * @return array{status:int, errno:int, error:string}
     */
    public function postSigned(string $path, string $body, string $secret, string $eventId, string $eventType): array
    {
        $cid = bin2hex(random_bytes(8));
        try {
            $this->vet($cid);
        } catch (QmsException $e) {
            return ['status' => 0, 'errno' => -1, 'error' => $e->getMessage()];
        }
        $t = time();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
        $res = $this->send('POST', $this->base . self::API_PREFIX . $path, ['Content-Type: application/json', 'Accept: application/json', 'X-Correlation-Id: ' . $cid, 'X-Rivet-Event-Id: ' . $eventId,
            'X-Rivet-Event-Type: ' . $eventType, 'X-Rivet-Signature-V2: ' . $sig, 'User-Agent: ' . self::USER_AGENT], $body, 15);
        return ['status' => $res['status'], 'errno' => $res['errno'], 'error' => $res['errno'] !== 0 ? ($res['errno'] === 28 ? 'timeout' : $this->curlWhy($res['errno'])) : ''];
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    private function vet(string $cid): void
    {
        $v = QmsUrl::vet($this->db, (string) $this->row['qs_qms_url']);
        if (!$v['ok']) {
            throw new QmsException($v['error'], QmsException::URL_REJECTED, 0, $cid);
        }
        $this->base = $v['base'];
        $this->target = $v['target'];
    }

    private function remaining(float $started, int $budget): int
    {
        return max(1, (int) ceil($budget - (microtime(true) - $started)));
    }

    private function accessToken(string $cid, bool $force): string
    {
        if (!$force) {
            $exp = (string) ($this->row['qs_token_expires_at'] ?? '');
            $enc = (string) ($this->row['qs_token_enc'] ?? '');
            if ($enc !== '' && $exp !== '' && strtotime($exp) !== false && strtotime($exp) > time() + 60) {
                $tok = decryptSetting($enc);
                if ($tok !== '') {
                    return $tok;
                }
            }
        }
        $secret = QualityConfig::secret($this->row, 'qs_client_secret_enc');
        $clientId = (string) $this->row['qs_client_id'];
        if ($secret === '' || $clientId === '') {
            throw new QmsException('The QMS client id or secret is missing or cannot be read.', QmsException::NOT_CONFIGURED, 0, $cid);
        }
        $tokenUrl = trim((string) ($this->row['qs_token_url'] ?? ''));
        if ($tokenUrl === '') {
            $tokenUrl = $this->base . self::API_PREFIX . '/oauth/token';
        } elseif (!QmsUrl::sameOrigin($this->base, $tokenUrl) || !QmsUrl::vet($this->db, $tokenUrl)['ok']) {
            throw new QmsException('The token address must be on the same host as the QMS address.', QmsException::URL_REJECTED, 0, $cid);
        }
        $form = http_build_query(['grant_type' => 'client_credentials', 'scope' => (string) $this->row['qs_scope']], '', '&', PHP_QUERY_RFC3986);
        $res = $this->send('POST', $tokenUrl, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json', 'X-Correlation-Id: ' . $cid, 'User-Agent: ' . self::USER_AGENT,
            'Authorization: Basic ' . base64_encode(rawurlencode($clientId) . ':' . rawurlencode($secret))], $form, 10);
        if ($res['errno'] !== 0) {
            throw new QmsException($res['errno'] === 28 ? 'The QMS token endpoint did not answer in time.' : 'Could not reach the QMS token endpoint (' . $this->curlWhy($res['errno']) . ').',
                $res['errno'] === 28 ? QmsException::TIMEOUT : QmsException::TRANSPORT, 0, $cid, true);
        }
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !is_array($j) || !is_string($j['access_token'] ?? null) || $j['access_token'] === '' || strlen($j['access_token']) > 4096) {
            $code = is_array($j) && is_string($j['error'] ?? null) && preg_match('/^[a-z_]{1,40}$/D', $j['error']) === 1 ? ' (' . $j['error'] . ')' : '';
            throw new QmsException("The QMS token endpoint refused the credentials: HTTP {$res['status']}$code.", $res['status'] >= 500 ? QmsException::SERVER : QmsException::AUTH, $res['status'], $cid, $res['status'] >= 500);
        }
        $ttl = QualityConfig::clamp((int) ($j['expires_in'] ?? 300), 30, 3600);
        $exp = date('Y-m-d H:i:s', time() + $ttl);
        $this->row['qs_token_enc'] = QualityConfig::sealSecret($j['access_token']);
        $this->row['qs_token_expires_at'] = $exp;
        QualityConfig::set($this->db, ['qs_token_enc' => $this->row['qs_token_enc'], 'qs_token_expires_at' => $exp]);
        return $j['access_token'];
    }

    /**
     * One HTTP exchange. The connection is pinned to the vetted addresses, redirects are not followed, the response is capped.
     *
     * @return array{status:int, errno:int, headers:array<string,string>, body:string}
     */
    private function send(string $method, string $url, array $headers, ?string $body, int $totalTimeout): array
    {
        $loop = defined('RIVETIT_QMS_TEST_LOOPBACK') && RIVETIT_QMS_TEST_LOOPBACK === true;
        $ch = curl_init($url);
        $respHeaders = [];
        $buf = '';
        $tooBig = false;
        $connect = QualityConfig::clamp((int) $this->row['qs_connect_timeout_s'], 1, 15);
        $total = min(QualityConfig::clamp((int) $this->row['qs_total_timeout_s'], 2, 60), max(1, $totalTimeout));
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $connect, CURLOPT_TIMEOUT => $total, CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => $loop ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$buf, &$tooBig): int {
                if (strlen($buf) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooBig = true;
                    return 0;   // abort the transfer: a response is never read past the cap
                }
                $buf .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        // DNS-rebinding guard: connect to exactly the addresses that were vetted.
        $parts = parse_url($url);
        if ($this->target !== null && is_array($parts) && !filter_var(trim((string) ($parts['host'] ?? ''), '[]'), FILTER_VALIDATE_IP)) {
            $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));
            $ips = array_map(static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $this->target['ips']);
            $opts[CURLOPT_RESOLVE] = [strtolower((string) $parts['host']) . ':' . $port . ':' . implode(',', $ips)];
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooBig) {
            return ['status' => $status, 'errno' => 63, 'headers' => $respHeaders, 'body' => ''];   // CURLE_FILESIZE_EXCEEDED
        }
        return ['status' => $status, 'errno' => $errno, 'headers' => $respHeaders, 'body' => $buf];
    }

    private function curlWhy(int $errno): string
    {
        return match ($errno) {
            6 => 'name not found', 7 => 'connection refused', 28 => 'timed out', 35, 51, 58, 60, 77, 83 => 'TLS problem', 63 => 'response too large', 56 => 'connection reset',
            default => 'network error ' . $errno,
        };
    }

    private function logPath(string $path): string
    {
        return preg_replace('~/(?:[0-9a-f]{8,}|[A-Za-z0-9_-]{16,}|\d{3,})(?=/|$)~', '/{id}', $path) ?? $path;
    }

    /** Remembers that a call worked (at most once a minute). */
    private function touchOk(): void
    {
        try {
            $last = (string) ($this->row['qs_last_ok_at'] ?? '');
            if ($last === '' || strtotime($last) < time() - 60) {
                $now = date('Y-m-d H:i:s');
                $this->row['qs_last_ok_at'] = $now;
                QualityConfig::set($this->db, ['qs_last_ok_at' => $now]);
            }
        } catch (\Throwable) {
            // bookkeeping only
        }
    }
}
