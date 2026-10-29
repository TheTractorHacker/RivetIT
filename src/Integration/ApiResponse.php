<?php

namespace ITFlow\Integration;

/**
 * JSON responses for the machine API (/api/v1/oauth/token and /api/v1/lms/*): the structured error
 * {"error":{"code","message","correlation_id"}} and the X-Correlation-Id header the QMS contract asks for.
 * The id is the caller's X-Correlation-Id when it is a sane token, else a generated one; it is echoed on every response and error.
 */
final class ApiResponse
{
    private static ?string $correlation = null;

    public static function correlationId(): string
    {
        if (self::$correlation === null) {
            $in = (string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? '');
            self::$correlation = preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $in) === 1 ? $in : bin2hex(random_bytes(12));
        }
        return self::$correlation;
    }

    public static function reset(): void
    {
        self::$correlation = null;
    }

    /** @param array<string,string> $headers */
    public static function json(int $status, array $body, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Correlation-Id: ' . self::correlationId());
        header('Cache-Control: no-store');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /** @param array<string,string> $headers */
    public static function error(int $status, string $code, string $message, array $headers = []): never
    {
        if ($status === 401) {
            $headers += ['WWW-Authenticate' => 'Bearer realm="rivetit-api"'];
        }
        self::json($status, ['error' => ['code' => $code, 'message' => $message, 'correlation_id' => self::correlationId()]], $headers);
    }
}
