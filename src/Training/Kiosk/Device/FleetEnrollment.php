<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Audit\AuditService;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;
use ITFlow\Training\Kiosk\Core\RateLimiter;

/**
 * The device side of a fleet link (DB 2.6.151, issue #43; the agent side is FleetLinks): POST enroll_fleet
 * {token, sn?} (anon), called by js/training_kiosk_signin.js when the tablet opens /kiosk/#e=<token>&sn=<serial>
 * (the kiosk redirects /kiosk/?e=...&sn=... there first, so neither is ever in an access log).
 *
 *   - a device already set up on this browser is left alone (state 'enrolled'); a Web Clip opens the fleet URL
 *     every time it is tapped, so this is the normal path after the first launch. A fleet device still waiting
 *     for approval answers state 'pending' (nothing is created, no use is counted).
 *   - the serial, when given, must be 3-64 of A-Z a-z 0-9 . _ - (an MDM macro that was not expanded is refused,
 *     422 fleet_serial_invalid, and costs nothing).
 *   - the token is checked against sha256 in training_fleet_links; unknown, revoked, expired and used-up links
 *     all answer the same 403 fleet_invalid (the reason is only in the ledger / audit trail). Such misses count
 *     in the bucket 'fleet:bad' (more than 20 a minute => 429); a valid token is never throttled by that bucket
 *     (an anonymous flood must not stop the real fleet) but each link has its own ceiling, 'fleet:ok:<id>'.
 *   - one enrollment per serial: a serial that already has a pending or active fleet device is refused
 *     (409 fleet_already_enrolled) until an admin revokes that device, so holding a link and knowing a serial
 *     never takes a working tablet over.
 *   - the serial is matched to Assets (asset_serial) inside the link's department, device types only,
 *     not archived. Exactly one match binds the asset (name, type, serial). Zero or several matches, or an
 *     asset that already has a training device, leave the row unlisted/pending with a note for the approver.
 *   - the row is ACTIVE at once only for link approval 'auto_match' with a clean single match; every other case
 *     is PENDING: KioskAuth::device() refuses a pending row, so no kiosk data and no sign-in until approved.
 *     Either way the tablet gets its device cookie now, so approval needs nothing more on the device.
 * Events: kiosk.enrolled (active) / kiosk.fleet_pending (actor 'kiosk'), kiosk.enroll_failed; one audit row each.
 */
final class FleetEnrollment
{
    public const BAD_PER_MIN = 20;
    public const TRY_PER_MIN = 300;
    public const LINK_PER_MIN = 120;

    /** @return array{state:string, label:string, next:string, already?:bool} state: enrolled | active | pending */
    public static function redeem(KioskCtx $k, mixed $token, mixed $serial): array
    {
        $db = $k->db();
        $pause = Db::one($db, 'SELECT config_training_enroll_pause_until_utc AS p FROM settings WHERE company_id = 1');
        if (KTime::isFuture($pause['p'] ?? null)) {
            throw new ApiException(429, 'enroll_paused', 'Device setup is paused for a few minutes.', [], ['minutes' => KTime::minutesUntil($pause['p'])]);
        }
        if (!RateLimiter::hit($db, 'fleet_try:all', 60, self::TRY_PER_MIN)) {
            throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
        }
        $sn = null;
        if ($serial !== null && $serial !== '') {
            if (!is_string($serial) || preg_match(FleetLinks::SERIAL_RE, $serial) !== 1) {
                throw new ApiException(422, 'fleet_serial_invalid', 'This link did not receive a valid serial number from the device manager.', ['sn' => 'Invalid.']);
            }
            $sn = $serial;
        }
        // Already set up (the Web Clip re-opens the fleet URL on every launch): nothing to do.
        if ($k->device !== null) {
            return ['state' => 'enrolled', 'label' => (string) $k->device['kiosk_label'], 'next' => '/kiosk/', 'already' => true];
        }
        $cookie = $_COOKIE[KioskAuth::DEV_COOKIE] ?? null;
        if (is_string($cookie) && preg_match(KioskAuth::TOKEN_RE, $cookie) === 1) {
            $wait = Db::one($db, "SELECT kiosk_label FROM training_kiosks WHERE kiosk_token_hash = ? AND kiosk_status = 'pending' AND kiosk_enroll_method = 'fleet'", 's', [KioskAuth::tokenHash($cookie)]);
            if ($wait !== null) {
                return ['state' => 'pending', 'label' => (string) $wait['kiosk_label'], 'next' => '/kiosk/', 'already' => true];
            }
        }
        $link = is_string($token) && preg_match(FleetLinks::TOKEN_RE, $token) === 1
            ? Db::one($db, 'SELECT fleet_id FROM training_fleet_links WHERE fleet_token_hash = ?', 's', [FleetLinks::tokenHash($token)]) : null;
        unset($token);
        if ($link === null) {
            self::miss($k, null, 'unknown');
        }
        $fleetId = (int) $link['fleet_id'];
        if (!RateLimiter::hit($db, 'fleet:ok:' . $fleetId, 60, self::LINK_PER_MIN)) {
            throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
        }
        $newToken = KioskAuth::newToken();
        $base = ['actor_type' => 'kiosk', 'user_agent' => $k->core->userAgent];
        $r = Db::tx($db, static function () use ($k, $db, $fleetId, $sn, $newToken, $base): array {
            $l = Db::one($db, 'SELECT f.*, c.client_name FROM training_fleet_links f LEFT JOIN clients c ON c.client_id = f.fleet_client_id WHERE f.fleet_id = ? FOR UPDATE', 'i', [$fleetId]);
            $state = $l === null ? 'unknown' : FleetLinks::state($l);
            if ($l === null || $state !== 'active') {
                return ['fail' => $state];
            }
            if ($sn !== null && Db::one($db, "SELECT kiosk_id FROM training_kiosks WHERE kiosk_fleet_serial = ? AND kiosk_status IN ('pending','active') FOR UPDATE", 's', [$sn]) !== null) {
                return ['dup' => true];
            }
            $clientId = (int) $l['fleet_client_id'];
            $asset = null;
            $note = $sn === null ? 'no_serial' : 'no_match';
            if ($sn !== null) {
                $types = KioskSettings::ASSET_TYPES;
                $in = implode(',', array_fill(0, count($types), '?'));
                $hits = Db::all($db, 'SELECT asset_id, asset_name, asset_type, asset_serial, asset_contact_id FROM assets
                    WHERE TRIM(asset_serial) = ? AND asset_client_id = ? AND asset_archived_at IS NULL AND asset_type IN (' . $in . ') ORDER BY asset_id LIMIT 3 FOR UPDATE',
                    'si' . str_repeat('s', count($types)), array_merge([$sn, $clientId], $types));
                if (count($hits) === 1) {
                    $asset = $hits[0];
                    $note = 'matched';
                } elseif (count($hits) > 1) {
                    $note = 'ambiguous';
                } elseif (Db::one($db, 'SELECT asset_id FROM assets WHERE TRIM(asset_serial) = ? AND asset_archived_at IS NULL LIMIT 1', 's', [$sn]) !== null) {
                    $note = 'other_department';
                }
            }
            $active = false;
            $personal = null;
            if ($asset !== null) {
                $busy = Db::all($db, "SELECT kiosk_id, kiosk_status FROM training_kiosks WHERE kiosk_asset_id = ? AND kiosk_status IN ('active','pending') FOR UPDATE", 'i', [(int) $asset['asset_id']]);
                $activeKiosk = array_filter($busy, static fn($b) => $b['kiosk_status'] === 'active');
                if ($activeKiosk !== []) {
                    $note = 'asset_in_use';
                } elseif ($l['fleet_approval'] === 'auto_match') {
                    $active = true;
                    foreach ($busy as $b) {
                        DeviceLifecycle::revokeLocked($db, (int) $b['kiosk_id'], 're-enrolled', null, $base);   // an unredeemed setup code for the same asset
                    }
                    $cid = (int) ($asset['asset_contact_id'] ?? 0);
                    if ($cid > 0 && Eligibility::isEligible($db, $cid, $k->core)) {
                        $personal = $cid;
                    }
                    $note = 'auto';
                }
            }
            $label = $asset !== null ? trim((string) $asset['asset_name']) : '';
            if ($label === '') {
                $label = trim((string) ($l['client_name'] ?? '')) . ' device ' . ($sn ?? ('new ' . substr(bin2hex(random_bytes(3)), 0, 5)));
            }
            $label = mb_substr(trim($label), 0, FleetLinks::LABEL_MAX, 'UTF-8');
            $now = KTime::now();
            $id = Db::insert($db, 'INSERT INTO training_kiosks (kiosk_asset_id, kiosk_asset_type, kiosk_asset_serial, kiosk_personal_contact_id, kiosk_label,
                    kiosk_default_client_id, kiosk_status, kiosk_enroll_method, kiosk_token_hash, kiosk_token_issued_at_utc, kiosk_enrolled_at_utc,
                    kiosk_created_by, kiosk_fleet_id, kiosk_fleet_serial, kiosk_fleet_note, kiosk_last_user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, \'fleet\', ?, ?, ?, ?, ?, ?, ?, ?)',
                'issisissssiisss',
                [$asset === null ? null : (int) $asset['asset_id'], $asset === null ? null : (string) $asset['asset_type'], $asset['asset_serial'] ?? null, $personal, $label,
                 $clientId, $active ? 'active' : 'pending', KioskAuth::tokenHash($newToken), $now, $active ? $now : null,
                 (int) $l['fleet_created_by'], $fleetId, $sn, $note, \ITFlow\Training\Core\Text::clip($_SERVER['HTTP_USER_AGENT'] ?? null, 255)]);
            Db::exec($db, 'UPDATE training_fleet_links SET fleet_use_count = fleet_use_count + 1, fleet_last_used_at_utc = ? WHERE fleet_id = ?', 'si', [$now, $fleetId]);
            Ledger::append($db, array_merge($base, [
                'type' => $active ? 'kiosk.enrolled' : 'kiosk.fleet_pending', 'kiosk_id' => $id, 'entity_type' => 'kiosk', 'entity_id' => $id,
                'payload' => ['asset_id' => $asset === null ? null : (int) $asset['asset_id'], 'asset_type' => $asset === null ? null : (string) $asset['asset_type'], 'label' => $label,
                              'method' => 'fleet', 'personal' => $personal !== null, 'unlisted' => $asset === null, 'expires_at_utc' => null, 'fleet_id' => $fleetId, 'note' => $note],
            ]));
            return ['id' => $id, 'label' => $label, 'active' => $active, 'note' => $note, 'link_label' => (string) $l['fleet_label']];
        });
        if (isset($r['fail'])) {
            unset($newToken);
            self::miss($k, $fleetId, (string) $r['fail']);
        }
        if (isset($r['dup'])) {
            unset($newToken);
            throw new ApiException(409, 'fleet_already_enrolled', 'This device is already set up or waiting for approval. Ask an admin.');
        }
        KioskAuth::clearSessionCookie();
        KioskAuth::setDeviceCookie($newToken);
        unset($newToken);
        $summary = ($r['active'] ? 'Enrolled' : 'Requested enrollment of') . ' training device "' . $r['label'] . '" with fleet link "' . $r['link_label'] . '"';
        try {
            (new AuditService($db))->log('training.kiosk_fleet_enrolled', null, 'training_kiosk', (int) $r['id'], 'fleet_enroll', $summary,
                ['fleet_id' => $fleetId, 'serial' => $sn, 'state' => $r['active'] ? 'active' : 'pending', 'note' => $r['note']]);
        } catch (\Throwable $e) {
            error_log('Training kiosk audit: ' . get_class($e));
        }
        if (function_exists('logAction')) {
            try {
                logAction('Training', 'Device', $summary, 0, (int) $r['id']);
            } catch (\Throwable $e) {
                error_log('Training kiosk logAction: ' . get_class($e));
            }
        }
        return ['state' => $r['active'] ? 'active' : 'pending', 'label' => $r['label'], 'next' => '/kiosk/'];
    }

    /** A refused link: the ledger records why, the bucket throttles repeats, the device always sees the same 403. */
    private static function miss(KioskCtx $k, ?int $fleetId, string $reason): never
    {
        $db = $k->db();
        if (!RateLimiter::hit($db, 'fleet:bad', 60, self::BAD_PER_MIN)) {
            throw new ApiException(429, 'rate_limited', 'Too many tries. Wait a minute.');
        }
        $base = ['actor_type' => 'kiosk', 'user_agent' => $k->core->userAgent];
        Db::tx($db, static function () use ($db, $base, $fleetId, $reason): void {
            Ledger::append($db, array_merge($base, ['type' => 'kiosk.enroll_failed', 'payload' => ['reason' => 'fleet_' . $reason, 'fleet_id' => $fleetId]]));
        });
        if ($fleetId !== null) {
            try {
                (new AuditService($db))->log('training.kiosk_fleet_refused', null, 'training_fleet_link', $fleetId, 'fleet_refused', 'A device used a training fleet link that is ' . str_replace('_', ' ', $reason), ['reason' => $reason]);
            } catch (\Throwable $e) {
                error_log('Training kiosk audit: ' . get_class($e));
            }
        }
        throw new ApiException(403, 'fleet_invalid', 'This setup link is no longer valid. Ask an admin for a new one.');
    }
}
