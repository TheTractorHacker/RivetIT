<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Session-lifecycle actions every kiosk page uses (P3 spec §4.2 K1 rows): heartbeat, end, set_language.
 */
final class CoreActions
{
    /**
     * POST heartbeat {} (learner, trainer, checkin, handoff, video). The Router's session() call
     * already refreshed last_seen (throttled); the client sends one only after real input.
     */
    public static function heartbeat(KioskCtx $k, ApiContext $a): array
    {
        $s = $k->ksess;
        return [
            'idle_s' => (int) ($s['ksess_idle_limit_s'] ?? $k->ks->idleS),
            'absolute_left_s' => max(0, (int) floor(KTime::secondsUntil($s['ksess_absolute_until_utc'] ?? null) ?? 0)),
        ];
    }

    /**
     * POST end {reason:'done'|'idle'} (device and every session role, video). Ends the open ksess
     * (ksession.end), clears the session cookie, and sends the client home. With no session it
     * only clears the cookie - Done is always safe to press twice.
     */
    public static function end(KioskCtx $k, ApiContext $a): array
    {
        $reason = $a->enum('reason', ['done', 'idle'], false) ?? 'done';
        $id = $k->ksessId();
        if ($id !== null) {
            KioskAuth::endSession($k, $id, $reason);
        }
        KioskAuth::clearSessionCookie();
        return ['next' => '/kiosk/'];
    }

    /**
     * POST set_language {lang} (device, learner, trainer, checkin, handoff). Signed in: the
     * person's own preference (training_learner_prefs, plan A3) and this session's language;
     * pre-auth: the device's language cookie (1 year). An open run keeps the language it started in.
     */
    public static function setLanguage(KioskCtx $k, ApiContext $a): array
    {
        $lang = $a->enum('lang', KioskStrings::LANGS);
        $id = $k->ksessId();
        if ($id === null) {
            KioskAuth::setLangCookie($lang);
            return ['lang' => $lang];
        }
        $db = $k->db();
        $cid = $k->contactId();
        $now = KTime::now();
        Db::tx($db, static function () use ($db, $cid, $id, $lang, $now, $k): void {
            if (in_array($k->role(), ['learner', 'trainer'], true)) {
                Db::exec($db, 'INSERT INTO training_learner_prefs (tpref_contact_id, tpref_language, tpref_updated_at_utc) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE tpref_language = VALUES(tpref_language), tpref_updated_at_utc = VALUES(tpref_updated_at_utc)',
                    'iss', [$cid, $lang, $now]);
            }
            Db::exec($db, 'UPDATE training_kiosk_sessions SET ksess_language = ? WHERE ksess_id = ? AND ksess_ended_at_utc IS NULL', 'si', [$lang, $id]);
        });
        return ['lang' => $lang];
    }
}
