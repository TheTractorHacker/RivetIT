<?php

namespace ITFlow\Quality;

/**
 * Events the QMS sends to RivetIT (POST /api/v1/integrations/qms/events, route in api/v1/quality_events.php).
 *
 * Layers, in order, each failing closed with one uniform answer:
 *   1. the integration access token holds the scope quality:events (route layer);
 *   2. the Quality module is on and inbound events are switched on (503 module_disabled otherwise: the route is "rejected server-side");
 *   3. X-Rivet-Signature-V2: t=<unix>,v1=hex(hmac_sha256(inbound secret, "<t>.<body>")), compared in constant time, timestamp within 5 minutes (a copy of an old
 *      request is useless) and not more than 1 minute in the future;
 *   4. the body is a JSON object of at most 64 KiB with id, type, schema_version, occurred_at and data;
 *   5. replay: UNIQUE (source, event id) in quality_inbound_events. A duplicate with the same body is acknowledged without being processed again; the same id with a
 *      different body is refused (409 event_id_reuse).
 *
 * Supported types (schema_version 1): qms.employee.mapped, qms.department.mapped (store the QMS's own id for a person or department, matched only by the Odoo id or
 * the RivetIT id, never by name), qms.sync.requested (queue a full resync of named streams), qms.requirement.changed (a hint; recorded, nothing to do because RivetIT
 * polls). An unknown type is recorded as ignored and acknowledged, so a newer QMS does not break an older RivetIT.
 */
final class InboundEvents
{
    public const MAX_BODY = 65536;
    public const TOLERANCE = 300;
    public const SUPPORTED_SCHEMA = 1;
    public const TYPES = ['qms.employee.mapped', 'qms.department.mapped', 'qms.sync.requested', 'qms.requirement.changed'];

    /** Verifies the signature header against the body. */
    public static function signatureValid(string $header, string $body, string $secret, ?int $now = null): bool
    {
        $now ??= time();
        if ($secret === '' || preg_match('/^t=(\d{9,11}),v1=([0-9a-f]{64})$/D', trim($header), $m) !== 1) {
            return false;
        }
        $t = (int) $m[1];
        if ($t < $now - self::TOLERANCE || $t > $now + 60) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $t . '.' . $body, $secret), $m[2]);
    }

    /**
     * @return array{http:int, body:array<string,mixed>}
     */
    public static function handle(\mysqli $db, string $rawBody, string $signatureHeader, string $source = 'qms'): array
    {
        $deny = static fn(int $http, string $code, string $msg): array => ['http' => $http, 'body' => ['error' => ['code' => $code, 'message' => $msg]]];
        if (!QualityConfig::moduleOn($db)) {
            return $deny(503, 'module_disabled', 'The Quality module is not enabled on this server.');
        }
        $row = QualityConfig::load($db);
        if ((int) $row['qs_inbound_enabled'] !== 1) {
            return $deny(503, 'module_disabled', 'Inbound Quality events are not enabled on this server.');
        }
        if (strlen($rawBody) > self::MAX_BODY) {
            return $deny(413, 'payload_too_large', 'The event is too large.');
        }
        $secret = QualityConfig::secret($row, 'qs_inbound_secret_enc');
        if (!self::signatureValid($signatureHeader, $rawBody, $secret)) {
            QualityLog::record($db, 'inbound', 'bad_signature', 'An inbound event was refused: missing, malformed, stale or wrong signature.');
            return $deny(401, 'invalid_signature', 'The signature is missing, wrong or too old.');
        }
        $j = json_decode($rawBody, true);
        if (!is_array($j) || array_is_list($j)) {
            return $deny(400, 'invalid_event', 'The body must be a JSON object.');
        }
        $id = $j['id'] ?? null;
        $type = $j['type'] ?? null;
        $ver = $j['schema_version'] ?? null;
        if (!is_string($id) || preg_match('/^[A-Za-z0-9._:-]{8,64}$/D', $id) !== 1 || !is_string($type) || preg_match('/^[a-z0-9_.]{3,60}$/D', $type) !== 1
            || !is_int($ver) || !isset($j['occurred_at']) || !is_string($j['occurred_at']) || !is_array($j['data'] ?? null)) {
            return $deny(400, 'invalid_event', 'The event needs id, type, schema_version, occurred_at and data.');
        }
        if ($ver > self::SUPPORTED_SCHEMA || $ver < 1) {
            return $deny(422, 'unsupported_schema_version', 'This ' . (defined('APP_NAME') ? APP_NAME : 'RivetIT') . ' understands schema_version 1.');
        }
        $hash = hash('sha256', $rawBody);

        $st = $db->prepare('INSERT IGNORE INTO quality_inbound_events (qi_source, qi_event_id, qi_type, qi_payload_hash) VALUES (?, ?, ?, ?)');
        $st->bind_param('ssss', $source, $id, $type, $hash);
        $st->execute();
        $inserted = $st->affected_rows === 1;
        $st->close();
        if (!$inserted) {
            $st = $db->prepare('SELECT qi_payload_hash, qi_status FROM quality_inbound_events WHERE qi_source = ? AND qi_event_id = ?');
            $st->bind_param('ss', $source, $id);
            $st->execute();
            $prev = $st->get_result()->fetch_assoc();
            $st->close();
            if ($prev && !hash_equals((string) $prev['qi_payload_hash'], $hash)) {
                QualityLog::record($db, 'inbound', 'event_id_reuse', 'An inbound event id was reused with a different body.', null, null, null, $id);
                return $deny(409, 'event_id_reuse', 'That event id was already used with a different body.');
            }
            return ['http' => 200, 'body' => ['status' => 'duplicate', 'id' => $id]];
        }

        [$status, $err] = self::process($db, $type, $j['data']);
        $st = $db->prepare('UPDATE quality_inbound_events SET qi_status = ?, qi_error = ?, qi_processed_at = NOW() WHERE qi_source = ? AND qi_event_id = ?');
        $st->bind_param('ssss', $status, $err, $source, $id);
        $st->execute();
        $st->close();
        if ($status === 'failed') {
            QualityLog::record($db, 'inbound', 'event_failed', 'Inbound event ' . $type . ' could not be applied: ' . $err, null, null, null, $id);
            return $deny(422, 'event_rejected', 'The event could not be applied.');
        }
        return ['http' => 200, 'body' => ['status' => $status, 'id' => $id]];
    }

    /** @return array{0:string,1:?string} [status, error] */
    private static function process(\mysqli $db, string $type, array $data): array
    {
        try {
            return match ($type) {
                'qms.employee.mapped' => self::mapEmployee($db, $data),
                'qms.department.mapped' => self::mapDepartment($db, $data),
                'qms.sync.requested' => self::syncRequested($db, $data),
                'qms.requirement.changed' => ['processed', null],
                default => ['ignored', null],
            };
        } catch (\Throwable $e) {
            return ['failed', substr(get_class($e), 0, 100)];
        }
    }

    /** @return array{0:string,1:?string} */
    private static function mapEmployee(\mysqli $db, array $d): array
    {
        $qmsId = self::qmsId($d['qms_id'] ?? null);
        if ($qmsId === null) {
            return ['failed', 'qms_id missing or invalid'];
        }
        $contactId = null;
        if (isset($d['rivetit_id']) && is_scalar($d['rivetit_id']) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $d['rivetit_id']) === 1) {
            $contactId = (int) $d['rivetit_id'];
            $st = $db->prepare('SELECT contact_id FROM contacts WHERE contact_id = ?');
            $st->bind_param('i', $contactId);
            $st->execute();
            $ok = $st->get_result()->num_rows === 1;
            $st->close();
            if (!$ok) {
                return ['failed', 'unknown rivetit_id'];
            }
        } elseif (isset($d['odoo_employee_id']) && is_scalar($d['odoo_employee_id']) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $d['odoo_employee_id']) === 1) {
            $odoo = (int) $d['odoo_employee_id'];
            $st = $db->prepare('SELECT DISTINCT contact_id FROM contact_odoo_links WHERE odoo_employee_id = ?');
            $st->bind_param('i', $odoo);
            $st->execute();
            $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
            $st->close();
            if (count($rows) !== 1) {
                return ['failed', count($rows) === 0 ? 'no employee has that odoo_employee_id' : 'odoo_employee_id is ambiguous; send rivetit_id'];
            }
            $contactId = (int) $rows[0]['contact_id'];
        } else {
            return ['failed', 'rivetit_id or odoo_employee_id required (people are never matched by name)'];
        }
        return self::storeMap($db, 'employee', $contactId, $qmsId);
    }

    /** @return array{0:string,1:?string} */
    private static function mapDepartment(\mysqli $db, array $d): array
    {
        $qmsId = self::qmsId($d['qms_id'] ?? null);
        if ($qmsId === null) {
            return ['failed', 'qms_id missing or invalid'];
        }
        if (!isset($d['rivetit_id']) || !is_scalar($d['rivetit_id']) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $d['rivetit_id']) !== 1) {
            return ['failed', 'rivetit_id required'];
        }
        $id = (int) $d['rivetit_id'];
        $st = $db->prepare('SELECT client_id FROM clients WHERE client_id = ?');
        $st->bind_param('i', $id);
        $st->execute();
        $ok = $st->get_result()->num_rows === 1;
        $st->close();
        return $ok ? self::storeMap($db, 'department', $id, $qmsId) : ['failed', 'unknown rivetit_id'];
    }

    private static function qmsId(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $v) === 1 ? $v : null;
    }

    /** @return array{0:string,1:?string} */
    private static function storeMap(\mysqli $db, string $entity, int $rivetitId, string $qmsId): array
    {
        // One QMS id belongs to one RivetIT record: refuse a second claim instead of silently re-pointing it.
        $st = $db->prepare('SELECT qm_rivetit_id FROM quality_id_map WHERE qm_entity = ? AND qm_qms_id = ?');
        $st->bind_param('ss', $entity, $qmsId);
        $st->execute();
        $taken = $st->get_result()->fetch_assoc();
        $st->close();
        if ($taken && (int) $taken['qm_rivetit_id'] !== $rivetitId) {
            return ['failed', 'that qms_id is already mapped to another record'];
        }
        $odoo = null;
        if ($entity === 'employee') {
            $st = $db->prepare('SELECT MIN(odoo_employee_id) AS o FROM contact_odoo_links WHERE contact_id = ?');
        } else {
            $st = $db->prepare('SELECT MIN(odoo_department_id) AS o FROM client_odoo_links WHERE client_id = ?');
        }
        $st->bind_param('i', $rivetitId);
        $st->execute();
        $o = $st->get_result()->fetch_assoc();
        $st->close();
        if ($o && $o['o'] !== null) {
            $odoo = (string) (int) $o['o'];
        }
        $st = $db->prepare('INSERT INTO quality_id_map (qm_entity, qm_rivetit_id, qm_odoo_id, qm_qms_id) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE qm_qms_id = VALUES(qm_qms_id), qm_odoo_id = VALUES(qm_odoo_id)');
        $st->bind_param('siss', $entity, $rivetitId, $odoo, $qmsId);
        $st->execute();
        $st->close();
        return ['processed', null];
    }

    /** @return array{0:string,1:?string} */
    private static function syncRequested(\mysqli $db, array $d): array
    {
        $streams = $d['streams'] ?? QualityConfig::STREAMS;
        if (!is_array($streams) || array_diff($streams, QualityConfig::STREAMS) !== []) {
            return ['failed', 'unknown stream'];
        }
        $mark = 'F' . gmdate('Y-m-d\TH:i:s.v\Z') . '|0';
        foreach (array_unique($streams) as $s) {
            $st = $db->prepare('INSERT INTO quality_sync_state (qss_stream, qss_cursor) VALUES (?, ?) ON DUPLICATE KEY UPDATE qss_cursor = VALUES(qss_cursor)');
            $st->bind_param('ss', $s, $mark);
            $st->execute();
            $st->close();
        }
        return ['processed', null];
    }

    public static function prune(\mysqli $db): void
    {
        $db->query('DELETE FROM quality_inbound_events WHERE qi_received_at < (NOW() - INTERVAL 30 DAY)');
    }

    /** @return list<array<string,mixed>> */
    public static function recent(\mysqli $db, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $r = $db->query('SELECT qi_id, qi_source, qi_event_id, qi_type, qi_status, qi_error, qi_received_at FROM quality_inbound_events ORDER BY qi_id DESC LIMIT ' . $limit);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }
}
