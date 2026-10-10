<?php

namespace ITFlow\Mcp\OAuth;

/**
 * Small HTTP helpers shared by the endpoint scripts in /oauth/. Every response is uncacheable. No CORS headers are sent:
 * MCP clients (Claude connectors, ToolHive) call these endpoints from a server or a native app, never from a page.
 */
final class OAuthHttp
{
    /** Emit a JSON response and stop. */
    public static function json(int $status, array $body, array $headers = []): never
    {
        self::commonHeaders();
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        // An empty result is {} (RFC 7009 success), never [].
        echo $body === [] ? '{}' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function commonHeaders(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    /** Refuse any method outside $allowed (there is no CORS preflight to answer). */
    public static function method(array $allowed): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, $allowed, true)) {
            self::json(405, ['error' => 'invalid_request', 'error_description' => 'Method not allowed.'], ['Allow' => implode(', ', $allowed)]);
        }
    }

    /** 404 with no body: the server is not acting as an authorization server (feature off or killed). */
    public static function notFound(): never
    {
        self::commonHeaders();
        http_response_code(404);
        exit;
    }

    public static function mediaType(): string
    {
        return strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    }

    /** The request body, or null when it exceeds $max bytes. */
    public static function body(int $max): ?string
    {
        $in = fopen('php://input', 'rb');
        $body = is_resource($in) ? stream_get_contents($in, $max + 1) : '';
        if (is_resource($in)) {
            fclose($in);
        }

        return is_string($body) && strlen($body) <= $max ? $body : null;
    }

    public static function clientIp(): string
    {
        $ip = function_exists('getIP') ? getIP() : ($_SERVER['REMOTE_ADDR'] ?? '');

        return is_string($ip) && $ip !== '' ? substr($ip, 0, 64) : 'unknown';
    }

    /** Fixed-window limit on the shared Redis; allowed when Redis is down (an abuse guard, not an access control). */
    public static function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        try {
            $limiter = new \RivetCore\Redis\RateLimiter(new \ITFlow\Core\Adapter\Redis\GlobalRedisClientProvider(), 'rivetit:');
            $hit = $limiter->hit($bucket, $limit, $windowSeconds);
            if (!$hit['allowed']) {
                header('Retry-After: ' . $hit['retry_after']);
            }

            return $hit['allowed'];
        } catch (\Throwable) {
            return true;
        }
    }

    public static function tooMany(): never
    {
        self::json(429, ['error' => 'temporarily_unavailable', 'error_description' => 'Too many requests. Slow down and retry.']);
    }

    public static function serverError(\Throwable $e): never
    {
        // The class only: a driver message can carry SQL, and nothing here may echo a request value into the log.
        error_log('MCP OAuth server error: ' . $e::class);
        self::json(500, ['error' => 'server_error', 'error_description' => 'The server could not complete the request.']);
    }
}
