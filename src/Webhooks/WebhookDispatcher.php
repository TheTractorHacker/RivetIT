<?php

namespace ITFlow\Webhooks;

/**
 * Synchronous, in-request webhook delivery (master plan Section 35). Distinct
 * from the existing queueWebhookEvent()/webhook_queue/cron.php path (which is
 * async with retry backoff, currently wired to ticket.* events only): this
 * fires immediately via curl and logs one row per attempt to
 * webhook_deliveries, for callers that want to know the outcome right away
 * (e.g. a future audit-event subscriber) without waiting on the next cron
 * tick. Both paths read the same `webhooks` table and never throw - a
 * webhook failure must never break the action that triggered it.
 */
class WebhookDispatcher
{
    private const DEFAULT_TIMEOUT_SECONDS = 10;

    private \mysqli $mysqli;

    public function __construct(\mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    /**
     * @return array<int,array{webhook_id:int,http_status:?int,duration_ms:int,error:?string}>
     */
    public function deliver(string $eventType, array $payload): array
    {
        $results = [];

        $stmt = $this->mysqli->prepare(
            "SELECT webhook_id, webhook_url, webhook_secret
             FROM webhooks
             WHERE webhook_enabled = 1
               AND FIND_IN_SET(?, REPLACE(webhook_events, ', ', ','))"
        );
        $stmt->bind_param('s', $eventType);
        $stmt->execute();
        $rows = $stmt->get_result();
        $webhooks = $rows ? $rows->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        if (empty($webhooks)) {
            return $results;
        }

        $body = json_encode([
            'event'     => $eventType,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'data'      => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($webhooks as $webhook) {
            $results[] = $this->sendOne(
                (int) $webhook['webhook_id'],
                $eventType,
                (string) $webhook['webhook_url'],
                decryptSetting((string) $webhook['webhook_secret']),
                $body
            );
        }

        return $results;
    }

    private function sendOne(int $webhookId, string $eventType, string $url, string $secret, string $body): array
    {
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);
        // X-ITFlow-* are the header names existing receivers verify: keep them. X-RivetIT-* carry the
        // same values so new receivers can use the product's name (cron/cron.php sends the same four).
        $headers = [
            'Content-Type: application/json',
            'X-ITFlow-Signature: ' . $signature,
            'X-ITFlow-Event: ' . $eventType,
            'X-RivetIT-Signature: ' . $signature,
            'X-RivetIT-Event: ' . $eventType,
        ];

        $httpStatus = null;
        $error = null;
        $responseSnippet = null;
        $start = microtime(true);

        $ch = curl_init($url);
        if ($ch === false) {
            $error = 'curl_init failed';
        } else {
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::DEFAULT_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => min(self::DEFAULT_TIMEOUT_SECONDS, 5),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch) ?: 'unknown curl error';
            } else {
                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $responseSnippet = substr((string) $response, 0, 1000);
            }
            curl_close($ch);
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $this->logDelivery($webhookId, $eventType, $httpStatus, $durationMs, $body, $error ?? $responseSnippet);

        return [
            'webhook_id'  => $webhookId,
            'http_status' => $httpStatus,
            'duration_ms' => $durationMs,
            'error'       => $error,
        ];
    }

    private function logDelivery(int $webhookId, string $eventType, ?int $httpStatus, int $durationMs, string $requestPayloadJson, ?string $responseSnippet): void
    {
        $stmt = $this->mysqli->prepare(
            "INSERT INTO webhook_deliveries
                (webhook_id, event_type, http_status, duration_ms, attempt_number, request_payload_json, response_body_snippet)
             VALUES (?, ?, ?, ?, 1, ?, ?)"
        );
        $stmt->bind_param('isiiss', $webhookId, $eventType, $httpStatus, $durationMs, $requestPayloadJson, $responseSnippet);
        $stmt->execute();
        $stmt->close();
    }
}
