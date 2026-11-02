<?php

namespace ITFlow\Quality;

/**
 * The integration error log (Administration > Quality). One row per failure, no secrets, tokens, bodies or personal data: an area, a short code,
 * a bounded message, the correlation id (the same id the QMS logged), the HTTP status and, for a portal request, the contact id (never the name).
 */
final class QualityLog
{
    public const AREAS = ['client', 'outbox', 'inbound', 'portal', 'sync', 'admin'];
    public const KEEP_DAYS = 30;
    public const KEEP_ROWS = 5000;

    public static function record(\mysqli $db, string $area, string $code, string $message, ?string $correlationId = null, ?int $http = null, ?int $contactId = null, ?string $resource = null): void
    {
        try {
            $area = in_array($area, self::AREAS, true) ? $area : 'client';
            $code = substr(preg_replace('/[^a-z0-9_.:-]/', '_', strtolower($code)) ?? 'error', 0, 60);
            $message = self::scrub($message);
            $correlationId = $correlationId !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $correlationId) === 1 ? $correlationId : null;
            $resource = $resource === null ? null : substr(preg_replace('/[^A-Za-z0-9_.:\/-]/', '_', $resource) ?? '', 0, 80);
            $st = $db->prepare('INSERT INTO quality_error_log (qel_area, qel_code, qel_message, qel_correlation_id, qel_http_status, qel_contact_id, qel_resource) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $st->bind_param('ssssiis', $area, $code, $message, $correlationId, $http, $contactId, $resource);
            $st->execute();
            $st->close();
        } catch (\Throwable $e) {
            error_log('QualityLog: ' . get_class($e));   // the log must never break the request it describes
        }
    }

    /** Removes anything that looks like a secret or a URL query, and bounds the length. */
    public static function scrub(string $message): string
    {
        $message = preg_replace('#(?i)(bearer|basic)\s+[A-Za-z0-9._~+/=-]{8,}#', '$1 [redacted]', $message) ?? $message;
        $message = preg_replace('~(?i)(secret|token|password|signature|authorization)(["\']?\s*[:=]\s*["\']?)[^\s"\',;&]{4,}~', '$1$2[redacted]', $message) ?? $message;
        $message = preg_replace('~(https?://[^\s?#]+)[?#]\S*~', '$1', $message) ?? $message;
        $message = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }

    /** Keeps the table bounded: older than KEEP_DAYS, and never more than KEEP_ROWS. */
    public static function prune(\mysqli $db): void
    {
        try {
            $db->query('DELETE FROM quality_error_log WHERE qel_at < (NOW() - INTERVAL ' . self::KEEP_DAYS . ' DAY)');
            $row = $db->query('SELECT qel_id FROM quality_error_log ORDER BY qel_id DESC LIMIT 1 OFFSET ' . self::KEEP_ROWS)->fetch_row();
            if ($row) {
                $db->query('DELETE FROM quality_error_log WHERE qel_id <= ' . (int) $row[0]);
            }
        } catch (\Throwable $e) {
            error_log('QualityLog::prune: ' . get_class($e));
        }
    }

    /** @return list<array<string,mixed>> newest first */
    public static function recent(\mysqli $db, int $limit = 100, ?string $area = null): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM quality_error_log' . ($area !== null ? ' WHERE qel_area = ?' : '') . ' ORDER BY qel_id DESC LIMIT ' . $limit;
        $st = $db->prepare($sql);
        if ($area !== null) {
            $st->bind_param('s', $area);
        }
        $st->execute();
        $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
        $st->close();
        return $rows;
    }
}
