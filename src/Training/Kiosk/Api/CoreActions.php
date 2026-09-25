<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
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
        if ($id !== null && in_array($k->role(), ['checkin', 'handoff'], true)) {
            // Check-in and hand-off are left only with the trainer PIN (T-3, T-5: checkin_exit,
            // handoff_cancel). The only other way out is idling, and only once the server agrees
            // the device really sat idle (the router does not let this request refresh last_seen).
            if ($reason !== 'idle' || !self::idleEnough($k->ksess)) {
                throw new ApiException(403, 'wrong_role', 'That is not available in this mode.');
            }
        }
        if ($id !== null) {
            KioskAuth::endSession($k, $id, $reason);
        }
        KioskAuth::clearSessionCookie();
        // The next person starts on the device's default language, not the last person's (they get their own saved one at sign-in).
        KioskAuth::clearLangCookie();
        return ['next' => '/kiosk/'];
    }

    /**
     * Has this (check-in / hand-off) session been quiet long enough for the client's idle timer
     * to be genuine? The server's last_seen can trail the last tap by up to one heartbeat (60 s)
     * plus the 15 s touch throttle, so the bar is the idle limit minus that slack, and never less
     * than half the limit.
     */
    private static function idleEnough(?array $ksess): bool
    {
        if ($ksess === null) {
            return false;
        }
        $limit = max(1, (int) ($ksess['ksess_idle_limit_s'] ?? 0));
        $need = max((int) ceil($limit / 2), $limit - 90);
        $seen = KTime::epoch($ksess['ksess_last_seen_at_utc'] ?? null);
        return $seen !== null && microtime(true) - $seen >= $need;
    }

    /**
     * POST set_language {lang} (device, learner, trainer, checkin, handoff). Signed in: the
     * person's own preference (training_learner_prefs, plan A3) and this session's language;
     * pre-auth: the device's language cookie (1 year). An open run keeps the language it started in
     * once anything is recorded; a run with nothing done yet follows the new language (RunService::followLanguage).
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
        if ($k->role() === 'learner') {
            // A course started by mistake in the other language, with nothing done yet, follows the new choice.
            (new \ITFlow\Training\Kiosk\Learn\RunService($k))->followLanguage($lang);
        }
        return ['lang' => $lang];
    }
}
