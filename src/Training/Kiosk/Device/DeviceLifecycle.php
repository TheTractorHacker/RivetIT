<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Revoking and expiring training devices (DB 2.6.94, owner request 2026-09-25).
 *
 * TEMPORARY DEVICES. A device may carry kiosk_expires_at_utc (UTC, DATETIME(3)). Enforcement is
 * server-side only:
 *   - KioskAuth::device() treats an active device whose time is up as invalid ('expired') and calls
 *     expire() at once, so its very next request ends like a revoked device's: the row is revoked
 *     (reason EXPIRED_REASON), its token stops working (the start URL too) and any open kiosk
 *     session is ended 'revoked';
 *   - cron/training_kiosk_cron.php calls sweep() every 10 minutes for devices nobody touched.
 * Both write exactly what a manual revoke writes (kiosk.revoked + ksession.end ledger events, a
 * training.kiosk_revoked audit row), with the system as the actor.
 *
 * PRESETS (expiryFor()): 'keep' (no expiry), 'today' (23:59:59 local), '4h', '8h', '24h', and 'until'
 * with a local 'YYYY-MM-DDTHH:MM' at least MIN_LEAD_S ahead and at most MAX_DAYS ahead. "Local" is
 * the app's time zone (settings.config_timezone, America/Chicago here). The same presets extend a
 * temporary device; 'keep' makes it permanent.
 *
 * Every comparison binds a UTC literal computed here in PHP (§0.11).
 */
final class DeviceLifecycle
{
    public const PRESETS = ['keep', 'today', '4h', '8h', '24h', 'until'];
    public const EXPIRED_REASON = 'Temporary device expired';
    public const ENDED_REASON = 'Temporary device ended early';
    public const MAX_DAYS = 30;
    public const MIN_LEAD_S = 300;
    private const HOURS = ['4h' => 4, '8h' => 8, '24h' => 24];
    private const SYSTEM_BASE = ['actor_type' => 'system', 'user_agent' => KioskCtx::SYSTEM_UA];

    /** True when a stored expiry is set and not in the future. */
    public static function isExpired(?string $expiresUtc): bool
    {
        return $expiresUtc !== null && $expiresUtc !== '' && !KTime::isFuture($expiresUtc);
    }

    /**
     * The UTC expiry ('Y-m-d H:i:s.v') for a preset, or null for 'keep'. $untilLocal is the
     * datetime-local value ('YYYY-MM-DDTHH:MM[:SS]') for 'until', in the app's time zone.
     * Invalid input throws ApiException validation on 'expires' / 'expires_until'.
     */
    public static function expiryFor(string $preset, ?string $untilLocal = null, ?float $now = null): ?string
    {
        $now ??= microtime(true);
        if ($preset === 'keep') {
            return null;
        }
        if (isset(self::HOURS[$preset])) {
            return KTime::fromEpoch($now + self::HOURS[$preset] * 3600);
        }
        $tz = new \DateTimeZone(date_default_timezone_get());
        if ($preset === 'today') {
            $end = (new \DateTimeImmutable('@' . (int) floor($now)))->setTimezone($tz)->setTime(23, 59, 59);
            return KTime::fromEpoch((float) $end->getTimestamp());
        }
        if ($preset !== 'until') {
            throw ApiException::validation(['expires' => 'Pick how long the device stays set up.']);
        }
        $v = trim((string) $untilLocal);
        $at = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/D', $v) === 1) {
            $at = \DateTimeImmutable::createFromFormat(strlen($v) === 16 ? '!Y-m-d\TH:i' : '!Y-m-d\TH:i:s', $v, $tz);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($at === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                $at = null;
            }
        }
        if ($at === null) {
            throw ApiException::validation(['expires_until' => 'Pick a date and time.']);
        }
        $t = (float) $at->getTimestamp();
        if ($t < $now + self::MIN_LEAD_S) {
            throw ApiException::validation(['expires_until' => 'Pick a time at least 5 minutes from now.']);
        }
        if ($t > $now + self::MAX_DAYS * 86400) {
            throw ApiException::validation(['expires_until' => 'A temporary device can stay set up for at most ' . self::MAX_DAYS . ' days.']);
        }
        return KTime::fromEpoch($t);
    }

    /**
     * INSIDE the caller's Db::tx with the kiosk row locked: revoked, token + code NULL, open
     * sessions ended 'revoked', kiosk.revoked {reason}. $byUserId is null for the system.
     */
    public static function revokeLocked(\mysqli $db, int $kioskId, string $reason, ?int $byUserId, array $base): void
    {
        Db::exec($db, "UPDATE training_kiosks SET kiosk_status = 'revoked', kiosk_token_hash = NULL, kiosk_enroll_code_hash = NULL, kiosk_enroll_expires_at_utc = NULL,
                kiosk_revoked_at_utc = ?, kiosk_revoked_by = ?, kiosk_revoke_reason = ? WHERE kiosk_id = ?",
            'sisi', [KTime::now(), $byUserId, mb_substr($reason, 0, 255, 'UTF-8'), $kioskId]);
        self::endOpenSessions($db, $kioskId, 'revoked', $base);
        Ledger::append($db, array_merge($base, [
            'type' => 'kiosk.revoked', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId, 'payload' => ['reason' => $reason],
        ]));
    }

    /** Ends the kiosk's open session (if any) inside the caller's tx (kiosk row already locked); ksession.end. */
    public static function endOpenSessions(\mysqli $db, int $kioskId, string $reason, array $base): void
    {
        foreach (Db::all($db, 'SELECT ksess_id, ksess_contact_id FROM training_kiosk_sessions WHERE ksess_kiosk_id = ? AND ksess_ended_at_utc IS NULL FOR UPDATE', 'i', [$kioskId]) as $s) {
            Db::exec($db, 'UPDATE training_kiosk_sessions SET ksess_ended_at_utc = ?, ksess_end_reason = ?, ksess_open_guard = NULL WHERE ksess_id = ?',
                'ssi', [KTime::now(), $reason, (int) $s['ksess_id']]);
            Ledger::append($db, array_merge($base, [
                'type' => 'ksession.end', 'kiosk_id' => $kioskId, 'ksess_id' => (int) $s['ksess_id'], 'subject_contact_id' => (int) $s['ksess_contact_id'],
                'entity_type' => 'ksess', 'entity_id' => (int) $s['ksess_id'], 'payload' => ['reason' => $reason],
            ]));
        }
    }

    /**
     * Own transaction: revokes one device whose temporary time is up (re-checked under the row
     * lock, so concurrent requests and the cron revoke it once). True when this call revoked it.
     * Must not run inside another transaction (the audit row is written after COMMIT).
     */
    public static function expire(\mysqli $db, int $kioskId): bool
    {
        if (Db::depth() > 0) {
            throw new \LogicException('DeviceLifecycle::expire must not run inside a transaction');
        }
        $label = Db::tx($db, static function () use ($db, $kioskId): ?string {
            $k = Db::one($db, 'SELECT kiosk_id, kiosk_label, kiosk_status, kiosk_expires_at_utc FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($k === null || $k['kiosk_status'] === 'revoked' || !self::isExpired($k['kiosk_expires_at_utc'])) {
                return null;
            }
            self::revokeLocked($db, $kioskId, self::EXPIRED_REASON, null, self::SYSTEM_BASE);
            return (string) $k['kiosk_label'];
        });
        if ($label === null) {
            return false;
        }
        $summary = 'Revoked training device "' . $label . '"';
        try {
            (new AuditService($db))->log('training.kiosk_revoked', null, 'training_kiosk', $kioskId, 'revoke', $summary, ['reason' => self::EXPIRED_REASON]);
        } catch (\Throwable $e) {
            error_log('Training kiosk audit: ' . get_class($e));
        }
        if (function_exists('logAction')) {
            try {
                logAction('Training', 'Device', $summary . ' (' . self::EXPIRED_REASON . ')', 0, $kioskId);
            } catch (\Throwable $e) {
                error_log('Training kiosk logAction: ' . get_class($e));
            }
        }
        return true;
    }

    /** Cron: revokes every active or pending device whose temporary time is up (oldest first). Returns how many. */
    public static function sweep(\mysqli $db, int $limit = 200): int
    {
        $rows = Db::all($db, "SELECT kiosk_id FROM training_kiosks WHERE kiosk_status IN ('active','pending') AND kiosk_expires_at_utc IS NOT NULL
            AND kiosk_expires_at_utc <= ? ORDER BY kiosk_expires_at_utc, kiosk_id LIMIT ?", 'si', [KTime::now(), max(1, $limit)]);
        $n = 0;
        foreach ($rows as $r) {
            if (self::expire($db, (int) $r['kiosk_id'])) {
                $n++;
            }
        }
        return $n;
    }
}
