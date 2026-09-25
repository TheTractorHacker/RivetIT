<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Hands the kiosk from one trainer-mode role to the next (P3 spec §3.6): trainer -> checkin
 * (pass the iPad), checkin -> trainer (exit with the trainer PIN), trainer -> handoff (the
 * evaluatee signs), handoff -> trainer (submit or cancel). The session being left ends with
 * its own reason (checkin_enter, checkin_exit, handoff_enter, handoff_exit - never 'replaced'),
 * then a NEW ksess with a new token starts, so a cookie copied during check-in or hand-off can
 * never act as the trainer.
 *
 * Runs INSIDE the caller's Db::tx in the §0.8 lock order (kiosk row -> ksess rows); returns the
 * ksession.end/start events for the caller to append LAST and the new plain token for
 * KioskAuth::setSessionCookie() after COMMIT.
 */
final class RoleSwitch
{
    /**
     * @return array{started:array, token:string, ksess_id:int, events:list<array>}
     */
    public static function switch(KioskCtx $k, string $reason, string $role, int $contactId, ?int $tsessionId): array
    {
        $db = $k->db();
        if (Db::depth() < 1) {
            throw new \LogicException('RoleSwitch::switch must run inside Db::tx');
        }
        $from = $k->ksessId();
        if ($from === null || $k->device === null) {
            throw new ApiException(401, 'session_ended', 'Your session ended.', [], ['reason' => 'missing']);
        }
        $kioskId = $k->kioskId();
        if (Db::one($db, "SELECT kiosk_id FROM training_kiosks WHERE kiosk_id = ? AND kiosk_status = 'active' FOR UPDATE", 'i', [$kioskId]) === null) {
            throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.');
        }
        $cur = Db::one($db, 'SELECT ksess_id, ksess_ended_at_utc, ksess_pin_source FROM training_kiosk_sessions WHERE ksess_id = ? AND ksess_kiosk_id = ? FOR UPDATE',
            'ii', [$from, $kioskId]);
        if ($cur === null || $cur['ksess_ended_at_utc'] !== null) {
            // A double tap: the first request already switched roles.
            throw new ApiException(401, 'session_ended', 'Your session ended.', [], ['reason' => 'ended']);
        }
        Db::exec($db, 'UPDATE training_kiosk_sessions SET ksess_ended_at_utc = ?, ksess_end_reason = ?, ksess_open_guard = NULL WHERE ksess_id = ?',
            'ssi', [KTime::now(), $reason, $from]);
        $started = KioskAuth::startSession($db, $k->device, $contactId, $role, [
            'source' => 'local',
            'lang' => $k->lang,
            'ks' => $k->ks,
            'tsession_id' => $tsessionId,
        ]);
        $events = [array_merge($k->eventBase(), [
            'type' => 'ksession.end',
            'ksess_id' => $from,
            'subject_contact_id' => $k->contactId() > 0 ? $k->contactId() : null,
            'entity_type' => 'ksess',
            'entity_id' => $from,
            'payload' => ['reason' => $reason],
        ])];
        foreach (KioskAuth::startEvents($started, $k->core->userAgent) as $e) {
            $events[] = $e;
        }
        return ['started' => $started, 'token' => (string) $started['token'], 'ksess_id' => (int) $started['row']['ksess_id'], 'events' => $events];
    }
}
