<?php

namespace ITFlow\Training\Kiosk\Core;

use ITFlow\Training\Core\Db;

/**
 * Kiosk CSRF (P3 spec §3.1, §4.1 step 6, §8 "CSRF and fetch metadata"). The header
 * X-Kiosk-Token is required on EVERY non-anonymous request, GET included, so a same-origin
 * fetch() from injected script cannot read data without first reading a page.
 *
 * A token is an HMAC (KioskKeys::csrf) over the sha256 of a cookie token - never the cookie
 * itself:
 *   'dev' + kiosk_token_hash  - device routes (pre-auth sign-in pages)
 *   'ks'  + ksess_token_hash  - session routes; device routes also accept it when the session
 *                               is a live ksess of THIS device (signed-in pages call search)
 *   'vid' + ksess hash|run|lesson - lesson_video.php only; checkVideo() limits it to a fixed set of
 *                               POST actions for that one run and lesson
 */
final class KioskCsrf
{
    public const HEADER = 'HTTP_X_KIOSK_TOKEN';
    /** Header the video page adds: "<run_id>:<lesson_uid>" - the pair its restricted token is bound to. */
    public const VIDEO_HEADER = 'HTTP_X_KIOSK_VIDEO';
    public const VIDEO_ACTIONS = ['lesson_open', 'lesson_tick', 'lesson_complete', 'video_duration', 'lesson_error', 'heartbeat', 'end'];

    /**
     * Acceptable tokens for this request. $routeAuth is the route's auth list.
     *
     * @return list<string>
     */
    public static function accepted(KioskCtx $k, array $routeAuth): array
    {
        $out = [];
        $sessionRoute = array_intersect($routeAuth, ['learner', 'trainer', 'checkin', 'handoff', 'video']) !== [];
        if ($k->ksess !== null && isset($k->ksess['ksess_token_hash']) && ($sessionRoute || in_array('device', $routeAuth, true))) {
            $out[] = $k->keys->csrf('ks', (string) $k->ksess['ksess_token_hash']);
        }
        if (in_array('device', $routeAuth, true) && $k->device !== null) {
            $out[] = $k->keys->csrf('dev', (string) $k->device['kiosk_token_hash']);
            if ($k->ksess === null) {
                $live = self::liveKsessHash($k);
                if ($live !== null) {
                    $out[] = $k->keys->csrf('ks', $live);
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** The token a page embeds in k-page-data: the session token when signed in, else the device token, else ''. */
    public static function pageToken(KioskCtx $k): string
    {
        if ($k->ksess !== null && isset($k->ksess['ksess_token_hash'])) {
            return $k->keys->csrf('ks', (string) $k->ksess['ksess_token_hash']);
        }
        if ($k->device !== null) {
            return $k->keys->csrf('dev', (string) $k->device['kiosk_token_hash']);
        }
        return '';
    }

    /**
     * The restricted video token: only the VIDEO_ACTIONS (POST), and when the input names a
     * run_id / lesson_uid they must equal the token's pair. Never valid for GET routes.
     */
    public static function checkVideo(KioskCtx $k, string $given, array $input, string $action): bool
    {
        if ($given === '' || $k->ksess === null || !in_array($action, self::VIDEO_ACTIONS, true)
            || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST' || (string) $k->ksess['ksess_role'] !== 'learner') {
            return false;
        }
        $pair = (string) ($_SERVER[self::VIDEO_HEADER] ?? '');
        if (preg_match('/^([1-9][0-9]{0,9}):([0-9a-z]{12})$/D', $pair, $m) !== 1) {
            return false;
        }
        $runId = (int) $m[1];
        $uid = $m[2];
        if (array_key_exists('run_id', $input) && (string) $input['run_id'] !== (string) $runId) {
            return false;
        }
        if (array_key_exists('lesson_uid', $input) && (string) $input['lesson_uid'] !== $uid) {
            return false;
        }
        if (!in_array($action, ['heartbeat', 'end'], true) && (!array_key_exists('run_id', $input) || !array_key_exists('lesson_uid', $input))) {
            return false;
        }
        return hash_equals($k->keys->videoCsrf((string) $k->ksess['ksess_token_hash'], $runId, $uid), $given);
    }

    /** The given header value ('' when absent). */
    public static function given(): string
    {
        $v = $_SERVER[self::HEADER] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    /** sha256 of the ksess cookie when it is an open session of THIS device, else null (no write, no touch). */
    private static function liveKsessHash(KioskCtx $k): ?string
    {
        $tok = $_COOKIE[KioskAuth::SESS_COOKIE] ?? null;
        if (!is_string($tok) || preg_match(KioskAuth::TOKEN_RE, $tok) !== 1 || $k->device === null) {
            return null;
        }
        $h = KioskAuth::tokenHash($tok);
        $row = Db::one($k->db(), 'SELECT ksess_id FROM training_kiosk_sessions WHERE ksess_token_hash = ? AND ksess_kiosk_id = ? AND ksess_ended_at_utc IS NULL',
            'si', [$h, (int) $k->device['kiosk_id']]);
        return $row === null ? null : $h;
    }
}
