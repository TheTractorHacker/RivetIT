<?php

namespace ITFlow\Integration;

/**
 * Idempotency-Key store for machine POSTs (api_idempotency, kept 24 hours).
 *
 * begin() claims (client, key) with the request's sha256. Outcomes:
 *   new     - the claim is ours: run the request, then finish() with the response (2xx only) or release() on any other result;
 *   replay  - same key and same request: send the stored status and body again;
 *   reuse   - same key, different request: answer 422 idempotency_key_reuse;
 *   busy    - the same request is still running in another process: answer 409 and retry.
 * Only successful results are stored, so a client that fixes a 4xx and retries with the same key is not stuck with the old failure.
 * A claim that never finished (crashed worker) is treated as abandoned after two minutes.
 */
final class Idempotency
{
    public const TTL_HOURS = 24;
    private const STALE_CLAIM_SECONDS = 120;

    public static function keyValid(string $key): bool
    {
        return preg_match('/^[A-Za-z0-9._:~=-]{8,128}$/D', $key) === 1;
    }

    /** sha256 of the decoded body with object keys sorted recursively, so key order and whitespace do not matter. */
    public static function requestHash(string $method, string $path, mixed $body): string
    {
        return hash('sha256', $method . ' ' . $path . "\n" . json_encode(self::canonical($body), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        if (array_is_list($v)) {
            return array_map([self::class, 'canonical'], $v);
        }
        ksort($v);
        return array_map([self::class, 'canonical'], $v);
    }

    /** @return array{state:string, status?:int, body?:string} */
    public static function begin(\mysqli $db, int $clientId, string $key, string $hash): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $st = $db->prepare('INSERT INTO api_idempotency (idem_client_id, idem_key, idem_request_sha256, idem_expires_at)
                    VALUES (?, ?, ?, NOW() + INTERVAL ' . self::TTL_HOURS . ' HOUR)');
                $st->bind_param('iss', $clientId, $key, $hash);
                $st->execute();
                $st->close();
                return ['state' => 'new'];
            } catch (\mysqli_sql_exception $e) {
                if ((int) $e->getCode() !== 1062) {
                    throw $e;
                }
            }
            $st = $db->prepare('SELECT idem_request_sha256, idem_response_status, idem_response_body,
                    (idem_expires_at < NOW()) AS expired, (idem_created_at < NOW() - INTERVAL ' . self::STALE_CLAIM_SECONDS . ' SECOND) AS stale
                FROM api_idempotency WHERE idem_client_id = ? AND idem_key = ?');
            $st->bind_param('is', $clientId, $key);
            $st->execute();
            $row = $st->get_result()->fetch_assoc();
            $st->close();
            if ($row === null) {
                continue;   // deleted between our insert and select: claim again
            }
            $unfinished = (int) $row['idem_response_status'] === 0;
            if ((int) $row['expired'] === 1 || ($unfinished && (int) $row['stale'] === 1)) {
                self::release($db, $clientId, $key);
                continue;
            }
            if (!hash_equals((string) $row['idem_request_sha256'], $hash)) {
                return ['state' => 'reuse'];
            }
            if ($unfinished) {
                return ['state' => 'busy'];
            }
            return ['state' => 'replay', 'status' => (int) $row['idem_response_status'], 'body' => (string) $row['idem_response_body']];
        }
        return ['state' => 'busy'];
    }

    public static function finish(\mysqli $db, int $clientId, string $key, int $status, string $body): void
    {
        $st = $db->prepare('UPDATE api_idempotency SET idem_response_status = ?, idem_response_body = ? WHERE idem_client_id = ? AND idem_key = ?');
        $st->bind_param('isis', $status, $body, $clientId, $key);
        $st->execute();
        $st->close();
    }

    public static function release(\mysqli $db, int $clientId, string $key): void
    {
        $st = $db->prepare('DELETE FROM api_idempotency WHERE idem_client_id = ? AND idem_key = ?');
        $st->bind_param('is', $clientId, $key);
        $st->execute();
        $st->close();
    }
}
