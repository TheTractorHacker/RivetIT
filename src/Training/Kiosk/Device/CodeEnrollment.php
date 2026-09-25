<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Core\RateLimiter;

/**
 * [S] D-5: the setup-code fallback on the device itself (pre-auth, POST enroll_code). A pending
 * kiosk row made by DeviceEnrollment::issueCode() holds sha256 of a 10-character code
 * (ABCDEFGHJKMNPQRSTUVWXYZ23456789, shown XXXXX-XXXXX) that expires in 15 minutes.
 *
 *   - a global enroll pause (config_training_enroll_pause_until_utc) refuses everything: 429 enroll_paused
 *   - the input is normalised (upper case, '-' and spaces removed) and must be 10 alphabet characters
 *   - a miss adds a failure to every pending, unexpired row (burned after 5: its code is nulled) and
 *     counts in the bucket 'enroll:all' (600 s); more than 10 misses there => enroll pause 30 minutes
 *   - a hit makes the row active, issues the device token and sets the device cookie
 * Events: kiosk.enrolled (actor 'kiosk', method setup_code) / kiosk.enroll_failed.
 */
final class CodeEnrollment
{
    public const CODE_RE = '/^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{10}$/D';
    public const MAX_FAILURES = 5;
    public const BUCKET_CAP = 10;
    public const PAUSE_S = 1800;

    public static function redeem(KioskCtx $k, mixed $code): array
    {
        $db = $k->db();
        $pause = Db::one($db, 'SELECT config_training_enroll_pause_until_utc AS p FROM settings WHERE company_id = 1');
        if (KTime::isFuture($pause['p'] ?? null)) {
            throw new ApiException(429, 'enroll_paused', 'Device setup is paused for a few minutes.', [], ['minutes' => KTime::minutesUntil($pause['p'])]);
        }
        $norm = is_string($code) ? strtoupper((string) preg_replace('/[\s-]+/', '', $code)) : '';
        unset($code);
        $hash = preg_match(self::CODE_RE, $norm) === 1 ? hash('sha256', $norm) : null;
        $norm = '';
        $now = KTime::now();
        $base = ['actor_type' => 'kiosk', 'user_agent' => $k->core->userAgent];
        $token = KioskAuth::newToken();
        $hit = $hash === null ? null : Db::tx($db, static function () use ($db, $hash, $now, $token, $base): ?array {
            // A temporary device whose time is already up can't be redeemed (the cron revokes it).
            $row = Db::one($db, "SELECT kiosk_id, kiosk_asset_id, kiosk_asset_type, kiosk_label, kiosk_personal_contact_id, kiosk_expires_at_utc FROM training_kiosks
                WHERE kiosk_enroll_code_hash = ? AND kiosk_status = 'pending' AND kiosk_enroll_expires_at_utc > ?
                  AND (kiosk_expires_at_utc IS NULL OR kiosk_expires_at_utc > ?) FOR UPDATE", 'sss', [$hash, $now, $now]);
            if ($row === null) {
                return null;
            }
            $id = (int) $row['kiosk_id'];
            Db::exec($db, "UPDATE training_kiosks SET kiosk_status = 'active', kiosk_enroll_code_hash = NULL, kiosk_enroll_expires_at_utc = NULL,
                    kiosk_token_hash = ?, kiosk_token_issued_at_utc = ?, kiosk_enrolled_at_utc = ? WHERE kiosk_id = ?",
                'sssi', [KioskAuth::tokenHash($token), $now, $now, $id]);
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.enrolled', 'kiosk_id' => $id, 'entity_type' => 'kiosk', 'entity_id' => $id,
                'payload' => ['asset_id' => $row['kiosk_asset_id'] === null ? null : (int) $row['kiosk_asset_id'],
                              'asset_type' => $row['kiosk_asset_type'] === null ? null : (string) $row['kiosk_asset_type'], 'label' => (string) $row['kiosk_label'],
                              'method' => 'setup_code', 'personal' => $row['kiosk_personal_contact_id'] !== null,
                              'unlisted' => $row['kiosk_asset_id'] === null, 'expires_at_utc' => $row['kiosk_expires_at_utc']],
            ]));
            return ['label' => (string) $row['kiosk_label']];
        });
        if ($hit !== null) {
            KioskAuth::clearSessionCookie();
            KioskAuth::setDeviceCookie($token);
            unset($token);
            return ['next' => '/kiosk/', 'label' => $hit['label']];
        }
        unset($token);
        // A miss: every live pending code takes a failure (the code is unknown, so all are at risk equally).
        Db::tx($db, static function () use ($db, $now, $base): void {
            $rows = Db::all($db, "SELECT kiosk_id, kiosk_enroll_failures FROM training_kiosks WHERE kiosk_status = 'pending' AND kiosk_enroll_code_hash IS NOT NULL
                AND kiosk_enroll_expires_at_utc > ? FOR UPDATE", 's', [$now]);
            foreach ($rows as $r) {
                $n = (int) $r['kiosk_enroll_failures'] + 1;
                Db::exec($db, 'UPDATE training_kiosks SET kiosk_enroll_failures = ?' . ($n >= self::MAX_FAILURES ? ', kiosk_enroll_code_hash = NULL, kiosk_enroll_expires_at_utc = NULL' : '')
                    . ' WHERE kiosk_id = ?', 'ii', [min(255, $n), (int) $r['kiosk_id']]);
            }
            Ledger::append($db, array_merge($base, ['type' => 'kiosk.enroll_failed', 'payload' => ['reason' => 'code_invalid']]));
        });
        if (!RateLimiter::hit($db, 'enroll:all', 600, self::BUCKET_CAP)) {
            Db::exec($db, 'UPDATE settings SET config_training_enroll_pause_until_utc = ? WHERE company_id = 1', 's', [KTime::plus(self::PAUSE_S)]);
        }
        throw new ApiException(422, 'code_invalid', "That setup code didn't work. Check it and try again.");
    }
}
