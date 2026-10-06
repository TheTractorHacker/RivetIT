<?php

namespace ITFlow\Webhooks;

use ITFlow\Core\Adapter\Webhooks\FixedSubscription;
use RivetCore\Webhooks\PayloadTemplate;

/**
 * "Send test" and "Replay" for webhooks, through the REAL delivery path: the same RivetCore dispatcher, body format, auth headers,
 * signing, URL policy and delivery log as a live event. Only the subscription lookup is replaced, so an unsaved form can be tried
 * before it is saved. A test leaves exactly one delivery-log row, labelled test.<event>; a test of an unsaved webhook leaves none.
 */
final class WebhookTester
{
    public const TEST_EVENTS = ['ticket.created', 'ticket.replied', 'ticket.resolved', 'auth.login_failed', 'asset.created', 'backup.failed'];

    private const SECRET_KEY = '/pass(word|wd)?|secret|token|api[_-]?key|authorization|cookie|credential_value|private|signature|bearer|otp|totp|session/i';

    /**
     * @param array<string,mixed> $row a webhooks row, or a draft from DestinationConfig::draftRow()
     * @return array{ok:bool,http_status:?int,duration_ms:int,error:?string,response:?string,delivery_id:?int}
     */
    public static function send(\mysqli $mysqli, array $row, string $event = 'ticket.created'): array
    {
        if (!function_exists('rivetWebhookDispatcher')) {
            require_once __DIR__ . '/../../includes/event_bus.php';
        }
        if (!preg_match('/^[a-z0-9_.]{1,100}$/', $event)) {
            $event = 'ticket.created';
        }
        $wid = (int) ($row['webhook_id'] ?? 0);
        $before = self::lastDeliveryId($mysqli, $wid);
        $type = ChatFormatter::normalizeType($row['webhook_type'] ?? '');
        $logEvent = $event;

        if (ChatFormatter::isChatType($type)) {
            $r = ChatDelivery::deliverRow($mysqli, $row, 'test.message', ['summary' => 'This is a test message. Nothing is wrong.'], 1, true);
            $logEvent = 'test.' . $type;
        } else {
            $data = PayloadTemplate::sampleContext($event)['data'];
            $opts = DestinationConfig::options($row, $event, $data, true);
            $dispatcher = rivetWebhookDispatcher($mysqli, new FixedSubscription(DestinationConfig::subscription($row)));
            $r = $dispatcher->deliverTo($wid, $event, $data, 1, null, time(), $opts === [] ? null : $opts);
        }

        $snippet = null;
        $deliveryId = null;
        $res = mysqli_query($mysqli, 'SELECT delivery_id, response_body_snippet FROM webhook_deliveries WHERE webhook_id = ' . $wid . ' AND delivery_id > ' . $before . ' ORDER BY delivery_id DESC LIMIT 1');
        $log = $res ? mysqli_fetch_assoc($res) : null;
        if ($log) {
            $deliveryId = (int) $log['delivery_id'];
            $snippet = $log['response_body_snippet'];
            if ($wid === 0) {
                mysqli_query($mysqli, 'DELETE FROM webhook_deliveries WHERE delivery_id = ' . $deliveryId);
                $deliveryId = null;
            } elseif (!str_starts_with($logEvent, 'test.')) {
                $stmt = mysqli_prepare($mysqli, 'UPDATE webhook_deliveries SET event_type = ? WHERE delivery_id = ?');
                $label = substr('test.' . $logEvent, 0, 150);
                mysqli_stmt_bind_param($stmt, 'si', $label, $deliveryId);
                mysqli_stmt_execute($stmt);
            }
        }

        return [
            'ok' => (bool) ($r['ok'] ?? false),
            'http_status' => isset($r['http_status']) ? (int) $r['http_status'] ?: null : null,
            'duration_ms' => (int) ($r['duration_ms'] ?? 0),
            'error' => $r['error'] ?? null,
            'response' => $snippet !== null ? substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $snippet) ?? '', 0, 500) : null,
            'delivery_id' => $deliveryId,
        ];
    }

    /** The event a stored delivery can be replayed as, or null. Only the standard JSON envelope (and our own tests) can be rebuilt from the log. */
    public static function replayable(array $delivery, array $webhook): ?array
    {
        $event = (string) ($delivery['event_type'] ?? '');
        if (str_starts_with($event, 'test.')) {
            return ['kind' => 'test', 'event' => substr($event, 5)];
        }
        if (ChatFormatter::isChatType(ChatFormatter::normalizeType($webhook['webhook_type'] ?? ''))) {
            return null;
        }
        $body = json_decode((string) ($delivery['request_payload_json'] ?? ''), true);
        if (!is_array($body) || array_keys($body) !== ['event', 'timestamp', 'data'] || !is_array($body['data']) || $body['event'] !== $event) {
            return null;
        }
        $d = DestinationConfig::destination($webhook);
        $format = $d !== null ? ((string) ($webhook['webhook_format'] ?? '') ?: $d->format) : 'json';

        return $format === 'json' ? ['kind' => 'event', 'event' => $event, 'data' => $body['data']] : null;
    }

    /** @return array{ok:bool,http_status:?int,duration_ms:int,error:?string,response:?string,delivery_id:?int} */
    public static function replay(\mysqli $mysqli, int $deliveryId): array
    {
        $fail = static fn (string $e): array => ['ok' => false, 'http_status' => null, 'duration_ms' => 0, 'error' => $e, 'response' => null, 'delivery_id' => null];
        $d = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT * FROM webhook_deliveries WHERE delivery_id = ' . $deliveryId . ' LIMIT 1'));
        if (!$d) {
            return $fail('That delivery no longer exists.');
        }
        $w = mysqli_fetch_assoc(mysqli_query($mysqli, 'SELECT * FROM webhooks WHERE webhook_id = ' . (int) $d['webhook_id'] . ' LIMIT 1'));
        if (!$w) {
            return $fail('The webhook was deleted.');
        }
        $plan = self::replayable($d, $w);
        if ($plan === null) {
            return $fail('This delivery cannot be replayed: its body is not stored in a form that can be rebuilt. Use Send test instead.');
        }
        if ($plan['kind'] === 'test') {
            return self::send($mysqli, $w, ChatFormatter::isChatType(ChatFormatter::normalizeType($w['webhook_type'] ?? '')) ? 'ticket.created' : (string) $plan['event']);
        }
        if (!function_exists('rivetWebhookDispatcher')) {
            require_once __DIR__ . '/../../includes/event_bus.php';
        }
        $opts = DestinationConfig::options($w, $plan['event'], $plan['data']);
        $r = rivetWebhookDispatcher($mysqli, new FixedSubscription(DestinationConfig::subscription($w)))
            ->deliverTo((int) $w['webhook_id'], $plan['event'], $plan['data'], 1, null, time(), $opts === [] ? null : $opts);

        return ['ok' => (bool) $r['ok'], 'http_status' => $r['http_status'] ?? null, 'duration_ms' => (int) $r['duration_ms'], 'error' => $r['error'] ?? null, 'response' => null, 'delivery_id' => null];
    }

    /** A stored request body made safe to display: secret-looking keys masked, long text cut. */
    public static function redactBody(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $mask = static function (array $v) use (&$mask): array {
                foreach ($v as $k => $x) {
                    if (is_string($k) && preg_match(self::SECRET_KEY, $k) === 1 && !is_array($x)) {
                        $v[$k] = '[redacted]';
                    } elseif (is_array($x)) {
                        $v[$k] = $mask($x);
                    }
                }

                return $v;
            };

            return (string) json_encode($mask($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $body) ?? '';
        $text = preg_replace('/((?:pass(?:word)?|secret|token|api[_-]?key)=)[^&\s]+/i', '$1[redacted]', $text) ?? $text;

        return mb_strlen($text) > 6000 ? mb_substr($text, 0, 6000) . "\n... (cut)" : $text;
    }

    private static function lastDeliveryId(\mysqli $mysqli, int $wid): int
    {
        $res = mysqli_query($mysqli, 'SELECT COALESCE(MAX(delivery_id), 0) FROM webhook_deliveries');

        return (int) ($res ? (mysqli_fetch_row($res)[0] ?? 0) : 0);
    }
}
