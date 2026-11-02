<?php

namespace ITFlow\Quality;

/**
 * Events RivetIT sends to the QMS: a durable queue (quality_outbox) with HMAC-signed, versioned deliveries.
 *
 *  - Envelope (schema_version 1): {"id","type","schema_version","occurred_at","source":"rivetit","resource":{"type","id"},"data":{...}}. The data are identifiers,
 *    status and timestamps only (no names, e-mail, phone or PIN). The receiver treats an event as a HINT and re-fetches the resource from the LMS API.
 *  - The id is deterministic (type + resource + revision), so producing the same change twice queues it once (UNIQUE qo_event_id). The QMS also dedupes on it.
 *  - Signature header X-Rivet-Signature-V2: t=<unix>,v1=hex(hmac_sha256(secret, "<t>.<body>")); the receiver checks it in constant time and rejects a timestamp
 *    more than 5 minutes off (replay protection).
 *  - Delivery: a 2xx is delivered. 408, 429, 5xx and a network failure are retried with backoff (1 min, 5 min, 15 min, 1 h, 4 h, 12 h, 24 h); after 8 attempts, or at
 *    once for any other 4xx (the receiver says the event itself is wrong), the event is dead-lettered and waits for an administrator to replay it.
 */
final class Outbox
{
    public const SCHEMA_VERSION = 1;
    public const MAX_ATTEMPTS = 8;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 14400, 43200, 86400];
    public const PATH = '/integrations/rivetit/events';
    public const TYPES = ['lms.assignment.created', 'lms.assignment.updated', 'lms.completion.recorded', 'lms.completion.corrected', 'employee.updated', 'employee.deactivated', 'department.updated'];

    public static function eventId(string $type, string $resourceType, string $resourceId, string $revisionKey): string
    {
        $h = hash('sha256', $type . '|' . $resourceType . '|' . $resourceId . '|' . $revisionKey);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), '5' . substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)) . substr($h, 17, 3), substr($h, 20, 12));
    }

    /**
     * Queues an event once. Returns true when it was new.
     *
     * @param array<string,mixed> $data
     */
    public static function enqueue(\mysqli $db, string $type, string $resourceType, string $resourceId, string $revisionKey, array $data, ?string $occurredAt = null): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown event type');
        }
        $id = self::eventId($type, $resourceType, $resourceId, $revisionKey);
        $revision = ctype_digit($revisionKey) ? (int) $revisionKey : 0;
        $envelope = ['id' => $id, 'type' => $type, 'schema_version' => self::SCHEMA_VERSION, 'occurred_at' => $occurredAt ?? gmdate('Y-m-d\TH:i:s.v\Z'), 'source' => 'rivetit',
            'resource' => ['type' => $resourceType, 'id' => $resourceId], 'data' => $data];
        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $st = $db->prepare('INSERT IGNORE INTO quality_outbox (qo_event_id, qo_type, qo_resource_type, qo_resource_id, qo_revision, qo_payload) VALUES (?, ?, ?, ?, ?, ?)');
        $st->bind_param('ssssis', $id, $type, $resourceType, $resourceId, $revision, $json);
        $st->execute();
        $new = $st->affected_rows === 1;
        $st->close();
        return $new;
    }

    /**
     * Sends due events, oldest first. Stops at the batch size or the time budget, or when the module is switched off meanwhile.
     *
     * @return array{sent:int, retry:int, dead:int, skipped:string|null}
     */
    public static function deliverDue(\mysqli $db, QmsClient $client, string $secret, int $batch = 50, int $budgetSeconds = 45): array
    {
        $out = ['sent' => 0, 'retry' => 0, 'dead' => 0, 'skipped' => null];
        if ($secret === '') {
            $out['skipped'] = 'no_signing_secret';
            return $out;
        }
        $lock = (int) ($db->query("SELECT GET_LOCK('rivetit_quality_outbox', 0) AS l")->fetch_assoc()['l'] ?? 0);
        if ($lock !== 1) {
            $out['skipped'] = 'busy';
            return $out;
        }
        try {
            $started = microtime(true);
            $r = $db->query("SELECT qo_id, qo_event_id, qo_type, qo_payload, qo_attempts FROM quality_outbox WHERE qo_status = 'pending' AND qo_next_attempt_at <= NOW() ORDER BY qo_id LIMIT " . max(1, min(500, $batch)));
            $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
            foreach ($rows as $row) {
                if (microtime(true) - $started > $budgetSeconds || !QualityConfig::moduleOn($db)) {
                    break;
                }
                $res = $client->postSigned(self::PATH, (string) $row['qo_payload'], $secret, (string) $row['qo_event_id'], (string) $row['qo_type']);
                $attempts = (int) $row['qo_attempts'] + 1;
                $id = (int) $row['qo_id'];
                $http = $res['status'] > 0 ? $res['status'] : null;
                if ($res['errno'] === 0 && $res['status'] >= 200 && $res['status'] < 300) {
                    $st = $db->prepare("UPDATE quality_outbox SET qo_status = 'delivered', qo_attempts = ?, qo_delivered_at = NOW(), qo_last_http_status = ?, qo_last_error = NULL WHERE qo_id = ?");
                    $st->bind_param('iii', $attempts, $http, $id);
                    $st->execute();
                    $st->close();
                    $out['sent']++;
                    continue;
                }
                $retryable = $res['errno'] !== 0 || in_array($res['status'], [408, 429, 500, 502, 503, 504], true) || $res['status'] >= 500;
                $err = $res['errno'] !== 0 ? 'network: ' . $res['error'] : 'HTTP ' . $res['status'];
                $dead = !$retryable || $attempts >= self::MAX_ATTEMPTS;
                if ($dead) {
                    $st = $db->prepare("UPDATE quality_outbox SET qo_status = 'dead', qo_attempts = ?, qo_last_http_status = ?, qo_last_error = ? WHERE qo_id = ?");
                    $st->bind_param('iisi', $attempts, $http, $err, $id);
                    $out['dead']++;
                    QualityLog::record($db, 'outbox', 'dead_letter', 'Event ' . $row['qo_type'] . ' could not be delivered (' . $err . '); it waits for a replay.', null, $http, null, (string) $row['qo_event_id']);
                } else {
                    $delay = self::BACKOFF_SECONDS[min($attempts - 1, count(self::BACKOFF_SECONDS) - 1)];
                    $jitter = random_int(0, (int) ($delay / 5));
                    $st = $db->prepare('UPDATE quality_outbox SET qo_attempts = ?, qo_last_http_status = ?, qo_last_error = ?, qo_next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE qo_id = ?');
                    $total = $delay + $jitter;
                    $st->bind_param('iisii', $attempts, $http, $err, $total, $id);
                    $out['retry']++;
                }
                $st->execute();
                $st->close();
                if ($res['errno'] !== 0) {
                    break;   // the QMS is unreachable: do not hammer it with the rest of the batch
                }
            }
        } finally {
            $db->query("DO RELEASE_LOCK('rivetit_quality_outbox')");
        }
        return $out;
    }

    /** Puts dead events (one, or all) back in the queue for an immediate retry. Returns how many. */
    public static function replay(\mysqli $db, ?int $id = null): int
    {
        if ($id === null) {
            $db->query("UPDATE quality_outbox SET qo_status = 'pending', qo_attempts = 0, qo_next_attempt_at = NOW(), qo_last_error = NULL WHERE qo_status = 'dead'");
        } else {
            $st = $db->prepare("UPDATE quality_outbox SET qo_status = 'pending', qo_attempts = 0, qo_next_attempt_at = NOW(), qo_last_error = NULL WHERE qo_status = 'dead' AND qo_id = ?");
            $st->bind_param('i', $id);
            $st->execute();
            $n = $st->affected_rows;
            $st->close();
            return max(0, $n);
        }
        return max(0, $db->affected_rows);
    }

    /** @return array{pending:int, delivered:int, dead:int, oldest_pending_seconds:?int, last_delivered_at:?string} */
    public static function counts(\mysqli $db): array
    {
        $out = ['pending' => 0, 'delivered' => 0, 'dead' => 0, 'oldest_pending_seconds' => null, 'last_delivered_at' => null];
        $r = $db->query("SELECT qo_status, COUNT(*) n FROM quality_outbox GROUP BY qo_status");
        while ($r && ($x = $r->fetch_assoc())) {
            $out[$x['qo_status']] = (int) $x['n'];
        }
        $o = $db->query("SELECT TIMESTAMPDIFF(SECOND, MIN(qo_created_at), NOW()) AS s FROM quality_outbox WHERE qo_status = 'pending'")->fetch_assoc();
        $out['oldest_pending_seconds'] = $o && $o['s'] !== null ? max(0, (int) $o['s']) : null;
        $d = $db->query("SELECT MAX(qo_delivered_at) AS d FROM quality_outbox")->fetch_assoc();
        $out['last_delivered_at'] = $d['d'] ?? null;
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function recent(\mysqli $db, ?string $status = null, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT qo_id, qo_event_id, qo_type, qo_resource_type, qo_resource_id, qo_revision, qo_status, qo_attempts, qo_next_attempt_at, qo_last_http_status, qo_last_error, qo_created_at, qo_delivered_at FROM quality_outbox'
            . ($status !== null ? ' WHERE qo_status = ?' : '') . ' ORDER BY qo_id DESC LIMIT ' . $limit;
        $st = $db->prepare($sql);
        if ($status !== null) {
            $st->bind_param('s', $status);
        }
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }

    public static function prune(\mysqli $db): void
    {
        $db->query("DELETE FROM quality_outbox WHERE qo_status = 'delivered' AND qo_delivered_at < (NOW() - INTERVAL 90 DAY)");
    }
}
