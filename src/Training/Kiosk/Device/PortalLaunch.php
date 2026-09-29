<?php

namespace ITFlow\Training\Kiosk\Device;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Starts training for a department user who is already signed in to the client portal: no device
 * setup, no name search. The portal (client/training_start.php) has authenticated the person, so
 * this mints - for that portal login only - a 'portal' device (one row per login, re-used and
 * re-keyed on every launch, 12 hours) and a learner kiosk session on it whose source is 'portal'.
 *
 * The training PIN is NOT skipped where it counts: signing and acknowledgements re-verify it through
 * PinGate::stepUp, which derives the PIN source itself, so the evidence is exactly as strong as on a
 * kiosk. A portal device can never sign anyone in by name (KioskRouter allows only end/set_language
 * without a session) and its stray-cookie sweep is skipped (kiosk/includes/bootstrap.php), so the
 * portal login itself is never disturbed.
 */
final class PortalLaunch
{
    public const DEVICE_HOURS = 12;

    /**
     * @return array{device_token:string, session_token:string}  set the two cookies AFTER this returns (it has committed)
     */
    public static function start(\mysqli $db, KioskSettings $ks, int $portalUserId, int $contactId, ?string $userAgent): array
    {
        if ($portalUserId < 1 || $contactId < 1) {
            throw new ApiException(403, 'forbidden', 'Sign in to the portal first.');
        }
        if (!Eligibility::isEligible($db, $contactId)) {
            throw new ApiException(403, 'forbidden', 'Training is not set up for your login. Ask your supervisor.');
        }
        $contact = Db::one($db, 'SELECT contact_name, contact_client_id FROM contacts WHERE contact_id = ? AND contact_archived_at IS NULL', 'i', [$contactId]);
        if ($contact === null) {
            throw new ApiException(403, 'forbidden', 'Training is not set up for your login. Ask your supervisor.');
        }
        $label = 'Portal: ' . mb_substr(trim((string) $contact['contact_name']), 0, 80, 'UTF-8');
        $clientId = max(0, (int) $contact['contact_client_id']);
        $deviceToken = KioskAuth::newToken();
        $hash = KioskAuth::tokenHash($deviceToken);
        $base = ['actor_type' => 'user', 'actor_user_id' => $portalUserId, 'user_agent' => $userAgent];

        $started = Db::tx($db, function () use ($db, $ks, $portalUserId, $contactId, $label, $clientId, $hash, $base, $userAgent): array {
            $now = KTime::now();
            $expires = KTime::plus(self::DEVICE_HOURS * 3600);
            $row = Db::one($db, "SELECT kiosk_id FROM training_kiosks WHERE kiosk_enroll_method = 'portal' AND kiosk_created_by = ? ORDER BY kiosk_id DESC LIMIT 1 FOR UPDATE",
                'i', [$portalUserId]);
            if ($row !== null) {
                $kioskId = (int) $row['kiosk_id'];
                DeviceLifecycle::endOpenSessions($db, $kioskId, 'replaced', $base);
                Db::exec($db, "UPDATE training_kiosks SET kiosk_status = 'active', kiosk_label = ?, kiosk_default_client_id = ?, kiosk_token_hash = ?,
                        kiosk_token_issued_at_utc = ?, kiosk_enrolled_at_utc = ?, kiosk_enrolled_by = ?, kiosk_expires_at_utc = ?,
                        kiosk_revoked_at_utc = NULL, kiosk_revoked_by = NULL, kiosk_revoke_reason = NULL, kiosk_hidden_at_utc = NULL,
                        kiosk_cooldown_until_utc = NULL, kiosk_cooldown_reason = NULL
                    WHERE kiosk_id = ?", 'sisssisi', [$label, $clientId, $hash, $now, $now, $portalUserId, $expires, $kioskId]);
            } else {
                $kioskId = Db::insert($db, "INSERT INTO training_kiosks (kiosk_asset_id, kiosk_asset_type, kiosk_asset_serial, kiosk_personal_contact_id, kiosk_label,
                        kiosk_default_client_id, kiosk_status, kiosk_enroll_method, kiosk_token_hash, kiosk_token_issued_at_utc, kiosk_enrolled_at_utc,
                        kiosk_enrolled_by, kiosk_expires_at_utc, kiosk_created_by)
                    VALUES (NULL, NULL, NULL, NULL, ?, ?, 'active', 'portal', ?, ?, ?, ?, ?, ?)", 'sisssisi',
                    [$label, $clientId, $hash, $now, $now, $portalUserId, $expires, $portalUserId]);
            }
            Ledger::append($db, array_merge($base, [
                'type' => 'kiosk.enrolled', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId,
                'payload' => ['asset_id' => null, 'asset_type' => null, 'label' => $label, 'method' => 'portal', 'personal' => false,
                              'unlisted' => true, 'expires_at_utc' => $expires],
            ]));
            $s = KioskAuth::startSession($db, ['kiosk_id' => $kioskId], $contactId, 'learner', ['ks' => $ks, 'source' => 'portal', 'lang' => 'en']);
            foreach (KioskAuth::startEvents($s, $userAgent) as $ev) {
                Ledger::append($db, $ev);
            }
            return $s;
        });

        return ['device_token' => $deviceToken, 'session_token' => (string) $started['token']];
    }
}
