<?php

namespace ITFlow\Training\Media;

/**
 * The only outbound HTTP client in the training code (spec §3.3, §8 "SSRF"). It exists so that
 * an author pasting a video link can never make this server fetch anything but a handful of
 * public video-provider endpoints.
 *
 *   - https only; the host must be EXACTLY one of HOSTS (no suffix matching, no userinfo, port 443)
 *   - the host is resolved (A and AAAA) and the request is refused if ANY address is not a
 *     public unicast address (FILTER_FLAG_GLOBAL_RANGE: private, loopback, link-local,
 *     CGNAT, documentation, IPv4-mapped IPv6 …) - one bad record among good ones is enough
 *   - the connection is then PINNED to a checked IPv4 address (CURLOPT_RESOLVE +
 *     CURL_IPRESOLVE_V4), so a DNS answer that changes between check and connect (rebinding)
 *     cannot redirect it
 *   - redirects are never followed (a 3xx is an error); proxies from the environment are
 *     ignored (CURLOPT_PROXY '' + NOPROXY '*'); TLS is verified; connect 3 s, total 6 s; the
 *     body is capped inside the write callback, so an endless response is cut off, not buffered
 *   - query strings are redacted (key=…) in every exception message: the YouTube Data API key
 *     travels in one
 *
 * URLs are always built by the callers from validated ids (VideoLink), never taken verbatim
 * from the author; resource links and KB/DOCX external images are never fetched at all.
 */
final class SafeHttp
{
    public const HOSTS = ['www.youtube.com', 'vimeo.com', 'i.ytimg.com', 'i.vimeocdn.com', 'www.googleapis.com'];

    public const CONNECT_TIMEOUT_MS = 3000;
    public const TOTAL_TIMEOUT_MS = 6000;
    private const USER_AGENT = 'ITFlow-Training/1.0';
    private const MAX_HEADER_BYTES = 16384;

    private static ?\Closure $resolver = null;
    private static ?\Closure $transport = null;

    /**
     * TEST SEAM ONLY - production code never calls this. $resolver(string $host): list<string>
     * replaces DNS; $transport(array $request): array{status:int, headers:array, body:string}
     * replaces the network AFTER every check has passed. Passing nulls restores the defaults.
     */
    public static function testHooks(?\Closure $resolver = null, ?\Closure $transport = null): void
    {
        self::$resolver = $resolver;
        self::$transport = $transport;
    }

    /**
     * @param array<string, string> $headers extra request headers (name => value)
     * @return array{status:int, body:string, content_type:?string, headers:array<string, string>}
     * @throws SafeHttpException
     */
    public static function get(string $url, int $maxBytes = 65536, array $headers = []): array
    {
        $req = self::prepare($url);
        $maxBytes = max(1, $maxBytes);
        if (self::$transport !== null) {
            $resp = (self::$transport)($req + ['max_bytes' => $maxBytes, 'headers' => $headers]);
            if (strlen((string) ($resp['body'] ?? '')) > $maxBytes) {
                throw new SafeHttpException('too_large', 'Response too large from ' . self::redact($url));
            }
            return self::finish($url, (int) ($resp['status'] ?? 0), array_change_key_case((array) ($resp['headers'] ?? [])), (string) ($resp['body'] ?? ''));
        }

        $state = ['body' => '', 'headers' => [], 'over' => false, 'hbytes' => 0];
        $ch = curl_init();
        self::apply($ch, self::curlOptions($req, $headers, self::TOTAL_TIMEOUT_MS) + self::callbacks($state, $maxBytes));
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($ok === false || $errno !== 0) {
            throw self::curlFailure($url, $errno, $state['over']);
        }
        return self::finish($url, $status, $state['headers'], $state['body']);
    }

    /**
     * Parallel GETs under one time budget (used by the publish checks).
     *
     * @param array<string, array{url:string, max_bytes?:int, headers?:array<string, string>}> $requests
     * @return array<string, array{status:int, body:string, content_type:?string, headers:array<string, string>}|array{error:string, message:string}>
     */
    public static function getMulti(array $requests, int $totalBudgetMs): array
    {
        $deadline = microtime(true) + max(100, $totalBudgetMs) / 1000;
        $out = [];
        $handles = [];
        $states = [];
        $mh = self::$transport === null ? curl_multi_init() : null;

        foreach ($requests as $key => $r) {
            $key = (string) $key;
            try {
                if (microtime(true) >= $deadline) {
                    throw new SafeHttpException('timeout', 'Time budget used up');
                }
                if (self::$transport !== null) {
                    $out[$key] = self::get((string) $r['url'], (int) ($r['max_bytes'] ?? 65536), (array) ($r['headers'] ?? []));
                    continue;
                }
                $req = self::prepare((string) $r['url']);
                $remaining = (int) max(200, min(self::TOTAL_TIMEOUT_MS, ($deadline - microtime(true)) * 1000));
                $states[$key] = ['body' => '', 'headers' => [], 'over' => false, 'hbytes' => 0, 'url' => (string) $r['url']];
                $ch = curl_init();
                self::apply($ch, self::curlOptions($req, (array) ($r['headers'] ?? []), $remaining)
                    + self::callbacks($states[$key], max(1, (int) ($r['max_bytes'] ?? 65536))));
                curl_multi_add_handle($mh, $ch);
                $handles[$key] = $ch;
            } catch (SafeHttpException $e) {
                $out[$key] = ['error' => $e->reason, 'message' => $e->getMessage()];
            }
        }

        if ($mh !== null) {
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.2);
                }
            } while ($running > 0 && $status === CURLM_OK && microtime(true) < $deadline);

            $done = [];
            while (($info = curl_multi_info_read($mh)) !== false) {
                $done[(int) spl_object_id($info['handle'])] = (int) $info['result'];
            }
            foreach ($handles as $key => $ch) {
                $st = $states[$key];
                $res = $done[spl_object_id($ch)] ?? null;
                try {
                    if ($res === null) {
                        throw new SafeHttpException('timeout', 'Timed out: ' . self::redact($st['url']));
                    }
                    if ($res !== CURLE_OK) {
                        throw self::curlFailure($st['url'], $res, $st['over']);
                    }
                    $out[$key] = self::finish($st['url'], (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $st['headers'], $st['body']);
                } catch (SafeHttpException $e) {
                    $out[$key] = ['error' => $e->reason, 'message' => $e->getMessage()];
                }
                curl_multi_remove_handle($mh, $ch);
            }
        }
        return $out;
    }

    /** Replaces every query-string value with an ellipsis (keys stay, so the log is still useful). */
    public static function redact(string $url): string
    {
        return preg_replace('/([?&][^=&#]*)=[^&#]*/', '$1=…', $url) ?? '[url]';
    }

    /**
     * Validates the URL and resolves + checks the host. Public so the refusals can be tested
     * without any network.
     *
     * @return array{url:string, host:string, ip:string}
     * @throws SafeHttpException
     */
    public static function prepare(string $url): array
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new SafeHttpException('bad_url', 'Invalid URL');
        }
        $p = parse_url($url);
        if ($p === false || !isset($p['scheme'], $p['host'])) {
            throw new SafeHttpException('bad_url', 'Invalid URL: ' . self::redact($url));
        }
        if (strtolower($p['scheme']) !== 'https') {
            throw new SafeHttpException('scheme', 'Only https is allowed: ' . self::redact($url));
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new SafeHttpException('bad_url', 'Credentials in URLs are not allowed');
        }
        $host = strtolower($p['host']);
        if (!in_array($host, self::HOSTS, true)) {
            throw new SafeHttpException('host_not_allowed', 'Host not allowed: ' . $host);
        }
        if (isset($p['port']) && (int) $p['port'] !== 443) {
            throw new SafeHttpException('bad_url', 'Only port 443 is allowed');
        }

        $ips = self::$resolver !== null ? (self::$resolver)($host) : self::resolve($host);
        $ips = array_values(array_unique(array_filter(array_map('strval', (array) $ips), static fn($s) => $s !== '')));
        if ($ips === []) {
            throw new SafeHttpException('dns', 'Could not resolve ' . $host);
        }
        $v4 = null;
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new SafeHttpException('private_address', "Refused: $host resolves to a non-public address");
            }
            if ($v4 === null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $v4 = $ip;
            }
        }
        if ($v4 === null) {
            throw new SafeHttpException('dns', "No IPv4 address for $host");
        }
        return ['url' => $url, 'host' => $host, 'ip' => $v4];
    }

    /**
     * The fixed cURL policy (no callbacks). Public for the test that asserts it.
     *
     * @param array{url:string, host:string, ip:string} $req
     */
    public static function curlOptions(array $req, array $headers, int $timeoutMs): array
    {
        $hdr = ['Accept: application/json, image/*;q=0.9, */*;q=0.1'];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1 || strlen($value) > 1024) {
                throw new SafeHttpException('bad_url', 'Invalid request header');
            }
            $hdr[] = $name . ': ' . $value;
        }
        return [
            CURLOPT_URL => $req['url'],
            CURLOPT_RESOLVE => [$req['host'] . ':443:' . $req['ip']],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_PROTOCOLS_STR => 'https',
            CURLOPT_REDIR_PROTOCOLS_STR => 'https',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_MS, max(200, $timeoutMs)),
            CURLOPT_TIMEOUT_MS => min(self::TOTAL_TIMEOUT_MS, max(200, $timeoutMs)),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $hdr,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => false,
        ];
    }

    // ---------------------------------------------------------------------------------------

    /** Sets every option or refuses to send: a silently ignored option would weaken the policy. */
    private static function apply(\CurlHandle $ch, array $options): void
    {
        foreach ($options as $opt => $value) {
            if (!curl_setopt($ch, $opt, $value)) {
                throw new SafeHttpException('network', 'HTTP client option ' . $opt . ' is not supported on this server');
            }
        }
    }

    /** Write/header callbacks that enforce the caps while the response streams in. */
    private static function callbacks(array &$state, int $maxBytes): array
    {
        return [
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$state, $maxBytes): int {
                if (strlen($state['body']) + strlen($chunk) > $maxBytes) {
                    $state['over'] = true;
                    return 0;   // aborts the transfer (CURLE_WRITE_ERROR)
                }
                $state['body'] .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$state): int {
                $state['hbytes'] += strlen($line);
                if ($state['hbytes'] > self::MAX_HEADER_BYTES) {
                    $state['over'] = true;
                    return 0;
                }
                if (preg_match('#^HTTP/#i', $line) === 1) {
                    $state['headers'] = [];   // a new response block (e.g. after 100 Continue)
                } elseif (str_contains($line, ':')) {
                    [$n, $v] = explode(':', $line, 2);
                    $state['headers'][strtolower(trim($n))] = trim($v);
                }
                return strlen($line);
            },
        ];
    }

    private static function finish(string $url, int $status, array $headers, string $body): array
    {
        if ($status >= 300 && $status < 400) {
            throw new SafeHttpException('redirect', "Redirect refused ($status) from " . self::redact($url), $status);
        }
        if ($status < 100) {
            throw new SafeHttpException('network', 'No HTTP response from ' . self::redact($url));
        }
        $ctype = isset($headers['content-type']) ? strtolower(trim(explode(';', (string) $headers['content-type'])[0])) : null;
        return ['status' => $status, 'body' => $body, 'content_type' => $ctype, 'headers' => $headers];
    }

    private static function curlFailure(string $url, int $errno, bool $overCap): SafeHttpException
    {
        $u = self::redact($url);
        if ($overCap || $errno === CURLE_WRITE_ERROR) {
            return new SafeHttpException('too_large', "Response too large from $u");
        }
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => new SafeHttpException('timeout', "Timed out: $u"),
            CURLE_SSL_CONNECT_ERROR, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CACERT_BADFILE, 51 /* CURLE_PEER_FAILED_VERIFICATION */ => new SafeHttpException('tls', "TLS failure: $u"),
            default => new SafeHttpException('network', "Network error ($errno): $u"),
        };
    }

    /** @return list<string> every A and AAAA address of $host */
    private static function resolve(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (($r['type'] ?? '') === 'A' && isset($r['ip'])) {
                    $ips[] = (string) $r['ip'];
                } elseif (($r['type'] ?? '') === 'AAAA' && isset($r['ipv6'])) {
                    $ips[] = (string) $r['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
        }
        return $ips;
    }
}
