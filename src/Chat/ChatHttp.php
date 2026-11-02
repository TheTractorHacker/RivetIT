<?php

namespace ITFlow\Chat;

use ITFlow\Webhooks\ChatDelivery;

/**
 * The one outbound HTTP client for chat features (Slack Web API, the Teams Bot Framework connector and token endpoints, the Bot Framework
 * signing-key document). It is SSRF-safe the same way chat webhooks are: the URL is vetted by ChatDelivery::vetUrl (https only, every resolved
 * address public, no userinfo), the connection is pinned to the vetted addresses through RivetCore's WebhookDispatcher::pinnedUrl + CURLOPT_RESOLVE
 * (no DNS rebinding), redirects are never followed, TLS is verified, environment proxies are ignored, and the response is size-capped.
 * For tests only, RIVETIT_CHAT_ALLOW_LOCAL_HTTP = true allows plain http to loopback (see ChatDelivery::vetUrl).
 */
final class ChatHttp
{
    public const MAX_RESPONSE_BYTES = 1000000;

    /**
     * @param array<int,string> $headers raw header lines
     * @return array{status:?int,body:?string,error:?string}
     */
    public static function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 8): array
    {
        $vet = ChatDelivery::vetUrl($url);
        if (!$vet['ok']) {
            return ['status' => null, 'body' => null, 'error' => 'url_rejected'];
        }
        if (!method_exists(\RivetCore\Webhooks\WebhookDispatcher::class, 'pinnedUrl')) {
            return ['status' => null, 'body' => null, 'error' => 'pin_unavailable'];
        }
        $ch = curl_init(\RivetCore\Webhooks\WebhookDispatcher::pinnedUrl($url, $vet));
        if ($ch === false) {
            return ['status' => null, 'body' => null, 'error' => 'curl_init_failed'];
        }
        $resolve = [];
        foreach ($vet['ips'] as $ip) {
            $resolve[] = $vet['host'] . ':' . $vet['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        $out = '';
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_merge($headers, ['User-Agent: RivetIT-Chat']),
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$out): int {
                if (strlen($out) < self::MAX_RESPONSE_BYTES) {
                    $out .= substr($chunk, 0, self::MAX_RESPONSE_BYTES - strlen($out));
                }

                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $errno !== 0) {
            return ['status' => null, 'body' => null, 'error' => 'curl_' . $errno]; // never the URL or the curl text (it can echo a secret path)
        }

        return ['status' => $status, 'body' => $out, 'error' => null];
    }

    /** POST JSON; @return array{status:?int,data:?array,error:?string} */
    public static function postJson(string $url, array $payload, array $headers = []): array
    {
        $r = self::request('POST', $url, array_merge(['Content-Type: application/json; charset=utf-8'], $headers), (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $data = is_string($r['body']) ? json_decode($r['body'], true) : null;

        return ['status' => $r['status'], 'data' => is_array($data) ? $data : null, 'error' => $r['error']];
    }
}
