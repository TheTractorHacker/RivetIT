<?php

namespace ITFlow\Mcp\OAuth;

/**
 * Fetches a client metadata document with the same SSRF guard the webhooks use: https only, the host must resolve only to
 * public addresses (RivetCore UrlPolicy, no admin "allowed internal networks" here), the connection is pinned to the vetted
 * addresses (no DNS rebinding), no redirects, no proxy, 5 second limit for the whole transfer, 64 KB cap, and only
 * application/json is accepted. Nothing from the response is trusted here; ClientMetadata::checkDocument judges it.
 *
 * Test rigs only (set in the environment of the PHP process, never by default):
 *   RIVETIT_WEBHOOK_ALLOW_PRIVATE=1   the existing webhook switch: skips the public-address test so a private mock server can be reached
 *   RIVETIT_CIMD_TEST_CA_FILE=<path>  trust this extra CA file (the mock server's self-signed certificate) instead of the system store
 */
final class CimdFetcher
{
    public const TIMEOUT_SECONDS = 5;

    /**
     * @return array{ok:true, body:string, cache_control:?string}
     *       |array{ok:false, kind:'unavailable'|'invalid', error:string}
     *   kind "invalid" = the server answered but not with an acceptable document; "unavailable" = no usable answer (network, timeout, 5xx, blocked address)
     */
    public static function fetch(string $url): array
    {
        $fail = static fn (string $kind, string $e): array => ['ok' => false, 'kind' => $kind, 'error' => $e];
        if (!function_exists('curl_init') || !class_exists(\RivetCore\Webhooks\UrlPolicy::class) || !method_exists(\RivetCore\Webhooks\WebhookDispatcher::class, 'pinnedUrl')) {
            return $fail('unavailable', 'fetching is unavailable (curl or RivetCore missing)');
        }
        if (!str_starts_with($url, 'https://')) {
            return $fail('invalid', 'not https');
        }
        $target = (new \RivetCore\Webhooks\UrlPolicy(getenv('RIVETIT_WEBHOOK_ALLOW_PRIVATE') === '1'))->vet($url);
        if ($target === null) {
            return $fail('unavailable', 'address not allowed (private, loopback, link-local or unresolvable)');
        }
        $ch = curl_init(\RivetCore\Webhooks\WebhookDispatcher::pinnedUrl($url, $target));
        if ($ch === false) {
            return $fail('unavailable', 'curl_init failed');
        }
        $resolve = self::pinList($target['host'], (int) $target['port'], $target['ips']);
        $body = '';
        $tooBig = false;
        $headers = [];
        $opt = [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: RivetIT-OAuth-Client-Metadata'],
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_CONTENT_DECODING => false,   // never inflate: the 64 KB cap counts what is on the wire, so a compressed bomb cannot expand past it
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body, &$tooBig): int {
                if (strlen($body) + strlen($chunk) > ClientMetadata::MAX_BYTES) {
                    $tooBig = true;

                    return 0;   // aborts the transfer
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ];
        $ca = getenv('RIVETIT_CIMD_TEST_CA_FILE');
        if (is_string($ca) && $ca !== '' && is_file($ca)) {
            $opt[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opt);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooBig) {
            return $fail('invalid', 'document larger than ' . ClientMetadata::MAX_BYTES . ' bytes');
        }
        if ($errno !== 0) {
            return $fail('unavailable', $errno === CURLE_OPERATION_TIMEDOUT ? 'timed out' : 'could not connect or TLS failed (curl ' . $errno . ')');
        }
        if ($status >= 300 && $status < 400) {
            return $fail('invalid', 'redirects are not followed');
        }
        if ($status !== 200) {
            return $fail($status >= 500 || $status === 429 ? 'unavailable' : 'invalid', 'HTTP status ' . $status);
        }
        $type = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
        if ($type !== 'application/json') {
            return $fail('invalid', 'content type is not application/json');
        }

        return ['ok' => true, 'body' => $body, 'cache_control' => $headers['cache-control'] ?? null];
    }

    /**
     * CURLOPT_RESOLVE entries for the already-vetted addresses. IPv4 only when the host has any: many servers have an IPv6 route
     * that does not work, and pinning both families made curl fail with "could not connect" (found live fetching
     * claude.ai from mwa). IPv6 is used only for a host that has no IPv4 address. Every address was vetted as public first.
     *
     * @param list<string> $ips
     * @return list<string>
     */
    public static function pinList(string $host, int $port, array $ips): array
    {
        $v4 = array_values(array_filter($ips, static fn(string $ip): bool => !str_contains($ip, ':')));
        $use = $v4 !== [] ? $v4 : array_values($ips);
        $out = [];
        foreach ($use as $ip) {
            $out[] = $host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        return $out;
    }
}
