<?php

namespace ITFlow\Webhooks;

/**
 * Delivery of one event to a Slack or Teams destination (a row of `webhooks` whose webhook_type is slack or teams).
 * It plugs into the existing queue: the 'webhook.deliver' job handler (includes/event_bus.php) and the legacy
 * webhook_queue loop in cron/cron.php call deliverRow() for chat destinations instead of the generic signed POST;
 * there is no second queue. Every attempt is logged to webhook_deliveries like any other webhook.
 *
 * The destination URL is a secret (anyone holding it can post to the channel): it is stored encrypted, never shown
 * back, and never written to logs or error messages (see redact()).
 *
 * Network safety: https only, the host must resolve exclusively to public addresses, the connection is pinned to the
 * vetted addresses (stops DNS rebinding), redirects are never followed, TLS is verified, proxies from the environment
 * are ignored, and the response body is capped. For tests only, define RIVETIT_CHAT_ALLOW_LOCAL_HTTP as true to allow
 * plain http to a loopback address.
 */
final class ChatDelivery
{
    public const TIMEOUT_SECONDS = 10;
    public const MAX_RESPONSE_BYTES = 4096;
    public const MAX_URL_LENGTH = 1000;

    /** @return array{ok:bool,error:?string,ips:string[],host:string,port:int} */
    public static function vetUrl(string $url): array
    {
        $fail = static fn (string $e) => ['ok' => false, 'error' => $e, 'ips' => [], 'host' => '', 'port' => 0];

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return $fail('URL is too long.');
        }
        if (str_contains($url, '\\') || preg_match('/[\x00-\x20]/', $url)) {
            return $fail('URL contains invalid characters.');
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return $fail('URL is not valid.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = trim($parts['host'], '[]');
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $isLoopbackLiteral = in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
        if (defined('RIVETIT_CHAT_ALLOW_LOCAL_HTTP') && RIVETIT_CHAT_ALLOW_LOCAL_HTTP === true && $isLoopbackLiteral && $scheme === 'http') {
            return ['ok' => true, 'error' => null, 'ips' => [$host === 'localhost' ? '127.0.0.1' : $host], 'host' => $host, 'port' => $port];
        }

        if ($scheme !== 'https') {
            return $fail('URL must start with https://');
        }

        if (!function_exists('rivetWebhookResolveTarget')) {
            require_once __DIR__ . '/../../includes/event_bus.php';
        }
        // The app's existing SSRF guard: every resolved address must be public (no private, loopback, link-local, reserved).
        $target = rivetWebhookResolveTarget($url);
        if ($target === null) {
            return $fail('URL must point to a public address (internal, loopback and link-local addresses are not allowed).');
        }

        return ['ok' => true, 'error' => null, 'ips' => $target['ips'], 'host' => $target['host'], 'port' => $target['port']];
    }

    /**
     * POST a JSON body to a vetted destination.
     *
     * @return array{status:?int,body:?string,error:?string}
     */
    public static function post(string $url, string $json): array
    {
        $vet = self::vetUrl($url);
        if (!$vet['ok']) {
            return ['status' => null, 'body' => null, 'error' => $vet['error']];
        }

        // curl must be given the URL built from the vetted host, the same spelling the CURLOPT_RESOLVE pin below is keyed on
        // (lower-case, no trailing dot). With the raw URL a host such as "example.com." skips the pin and curl resolves the
        // name itself, which reopens DNS rebinding for this path. Fails closed when RivetCore cannot build that URL.
        if (!method_exists(\RivetCore\Webhooks\WebhookDispatcher::class, 'pinnedUrl')) {
            return ['status' => null, 'body' => null, 'error' => 'Delivery is unavailable: RivetCore is too old to pin the connection.'];
        }
        $pinned_url = \RivetCore\Webhooks\WebhookDispatcher::pinnedUrl($url, $vet);
        $ch = curl_init($pinned_url);
        if ($ch === false) {
            return ['status' => null, 'body' => null, 'error' => 'curl_init failed'];
        }
        $resolve = [];
        foreach ($vet['ips'] as $ip) {
            $resolve[] = $vet['host'] . ':' . $vet['port'] . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip);
        }
        $body = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8', 'User-Agent: RivetIT-Chat-Webhook'],
            CURLOPT_RESOLVE => $resolve,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($c, string $chunk) use (&$body): int {
                if (strlen($body) < self::MAX_RESPONSE_BYTES) {
                    $body .= substr($chunk, 0, self::MAX_RESPONSE_BYTES - strlen($body));
                }

                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            return ['status' => null, 'body' => null, 'error' => self::redact('Could not deliver: ' . ($err !== '' ? $err : 'curl error ' . $errno), $url)];
        }

        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    /** Remove the secret URL (and its path/query) from any text before it is stored or shown. */
    public static function redact(string $text, string $url): string
    {
        $text = str_replace($url, '[webhook url]', $text);
        $p = parse_url($url);
        foreach ([$p['path'] ?? '', $p['query'] ?? ''] as $secret) {
            if (strlen($secret) > 3) {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return $text;
    }

    /** Public https://host/path form of a destination for the lists: scheme and host only, the secret path is hidden. */
    public static function maskUrl(string $url): string
    {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) {
            return '(hidden)';
        }

        return strtolower($p['scheme'] ?? 'https') . '://' . $p['host'] . '/…hidden';
    }

    /** Base options for the formatter from the current request/cron globals. */
    public static function formatterOptions(array $data): array
    {
        global $config_base_url;
        $opts = ['app_name' => defined('APP_NAME') ? (string) APP_NAME : 'RivetIT'];
        $base = trim((string) ($config_base_url ?? ''));
        if ($base !== '' && isset($data['ticket_id']) && (int) $data['ticket_id'] > 0) {
            $base = preg_match('#^https?://#i', $base) ? rtrim($base, '/') : 'https://' . rtrim($base, '/');
            $opts['ticket_url'] = $base . '/agent/ticket.php?ticket_id=' . (int) $data['ticket_id'];
        }

        return $opts;
    }

    /**
     * Deliver one event to a chat destination row and log the attempt.
     *
     * @param array<string,mixed> $row  a `webhooks` row (webhook_url is the encrypted/plain stored value)
     * @return array{ok:bool,skipped:bool,http_status:?int,duration_ms:int,error:?string}
     */
    public static function deliverRow(\mysqli $mysqli, array $row, string $event, array $data, int $attempt = 1, bool $test = false): array
    {
        $type = ChatFormatter::normalizeType($row['webhook_type'] ?? '');
        if (!ChatFormatter::isChatType($type)) {
            return ['ok' => false, 'skipped' => false, 'http_status' => null, 'duration_ms' => 0, 'error' => 'not a chat destination'];
        }
        if (!$test && !ChatFormatter::shouldDeliver($row, $event, $data)) {
            return ['ok' => true, 'skipped' => true, 'http_status' => null, 'duration_ms' => 0, 'error' => null];
        }

        $url = function_exists('decryptSetting') ? decryptSetting((string) $row['webhook_url']) : (string) $row['webhook_url'];
        $payload = ChatFormatter::format($type, $event, $data, self::formatterOptions($data) + ['test' => $test]);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        $start = microtime(true);
        $r = $json === false ? ['status' => null, 'body' => null, 'error' => 'could not encode message'] : self::post($url, $json);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $ok = $r['error'] === null && $r['status'] !== null && $r['status'] >= 200 && $r['status'] < 300;
        $snippet = $r['error'] ?? ($r['body'] !== null ? substr(self::redact(preg_replace('/[\x00-\x08\x0B-\x1F]/', '', $r['body']) ?? '', $url), 0, 300) : null);
        $error = $r['error'] ?? ($ok ? null : 'HTTP ' . $r['status']);

        try {
            $stmt = mysqli_prepare($mysqli, 'INSERT INTO webhook_deliveries (webhook_id, event_type, http_status, duration_ms, attempt_number, request_payload_json, response_body_snippet) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $wid = (int) $row['webhook_id'];
            $evLog = $test ? 'test.' . $type : $event;
            $status = $r['status'];
            $payloadLog = (string) $json;
            mysqli_stmt_bind_param($stmt, 'isiiiss', $wid, $evLog, $status, $ms, $attempt, $payloadLog, $snippet);
            mysqli_stmt_execute($stmt);
        } catch (\Throwable $e) {
            // a logging failure must never break delivery
        }

        return ['ok' => $ok, 'skipped' => false, 'http_status' => $r['status'], 'duration_ms' => $ms, 'error' => $error];
    }
}
