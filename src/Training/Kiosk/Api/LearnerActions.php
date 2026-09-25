<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Learn\AttemptService;
use ITFlow\Training\Kiosk\Learn\AttestService;
use ITFlow\Training\Kiosk\Learn\PinGate;
use ITFlow\Training\Kiosk\Learn\RunService;

/**
 * Learner kiosk actions (P3 spec §4.2 K3 rows). The learner is always the kiosk session's
 * contact; ids from the request are checked against it inside the services. Actions that verify a
 * PIN (ack_sign, attest) pad the response to >= 800 ms in a finally and unset the PIN; the
 * router logs only the exception class for requests carrying a `pin` key (§0.6, §0.12).
 */
final class LearnerActions
{
    private const UID_RE = '/^[a-z][0-9a-z]{11}$/D';

    /** POST run_start {course_id} => RunState */
    public static function runStart(KioskCtx $k, ApiContext $a): array
    {
        return (new RunService($k))->start((int) $a->int('course_id', true, 1));
    }

    /** POST lesson_open {run_id, lesson_uid} => Gate */
    public static function lessonOpen(KioskCtx $k, ApiContext $a): array
    {
        return (new RunService($k))->lessonOpen(self::runId($a), self::uid($a));
    }

    /** POST lesson_tick {run_id, lesson_uid, position_s?, pages_seen?, playing, visible, active, video_id?} => Gate */
    public static function lessonTick(KioskCtx $k, ApiContext $a): array
    {
        return (new RunService($k))->tick(self::runId($a), self::uid($a), self::sample($a->input));
    }

    /** POST lesson_complete {run_id, lesson_uid, evidence:{position_s?, pages_seen?, video_id?}} */
    public static function lessonComplete(KioskCtx $k, ApiContext $a): array
    {
        $ev = $a->arr('evidence', false);
        return (new RunService($k))->lessonComplete(self::runId($a), self::uid($a), self::sample($ev));
    }

    /** POST video_duration {run_id, lesson_uid, duration_s} => {} (409 video_changed) */
    public static function videoDuration(KioskCtx $k, ApiContext $a): array
    {
        (new RunService($k))->videoDuration(self::runId($a), self::uid($a), (int) $a->int('duration_s', true, 0, 86400));
        return [];
    }

    /** POST lesson_error {run_id, lesson_uid, provider, code} => {} */
    public static function lessonError(KioskCtx $k, ApiContext $a): array
    {
        $provider = (string) $a->enum('provider', RunService::VIDEO_PROVIDERS);
        $code = (string) $a->str('code', 40);
        if (preg_match('/^[A-Za-z0-9_.-]{1,40}$/D', $code) !== 1) {
            throw ApiException::validation(['code' => 'Not a valid error code.']);
        }
        (new RunService($k))->videoError(self::runId($a), self::uid($a), $provider, $code);
        return [];
    }

    /** POST ack_sign {run_id, lesson_uid, signature_png?, pin?} => {progress_pct, run_status, receipt} */
    public static function ackSign(KioskCtx $k, ApiContext $a): array
    {
        $pin = $a->input['pin'] ?? null;
        unset($a->input['pin']);
        try {
            $sig = $a->input['signature_png'] ?? null;
            return (new RunService($k))->ackSign(self::runId($a), self::uid($a), is_string($sig) ? $sig : null, $pin);
        } finally {
            unset($pin);
            PinGate::pad($k);
        }
    }

    /** POST exam_start {run_id, lesson_uid} => kiosk_quiz_start */
    public static function examStart(KioskCtx $k, ApiContext $a): array
    {
        return (new AttemptService($k))->start(self::runId($a), self::uid($a));
    }

    /** POST answer_save {attempt_id, question_uid, option_uids:[]} => {saved_at} */
    public static function answerSave(KioskCtx $k, ApiContext $a): array
    {
        $q = (string) $a->str('question_uid', 12);
        if (preg_match(self::UID_RE, $q) !== 1) {
            throw ApiException::validation(['question_uid' => 'Not a valid question.']);
        }
        $opts = $a->input['option_uids'] ?? [];
        if (!is_array($opts)) {
            throw ApiException::validation(['option_uids' => 'Send a list of choices.']);
        }
        return (new AttemptService($k))->saveAnswer((int) $a->int('attempt_id', true, 1), $q, $opts);
    }

    /** POST exam_submit {attempt_id, answers:{qUid:[oUid]}} => kiosk_quiz_submit */
    public static function examSubmit(KioskCtx $k, ApiContext $a): array
    {
        $answers = $a->input['answers'] ?? [];
        if (!is_array($answers)) {
            throw ApiException::validation(['answers' => 'Send the answers keyed by question.']);
        }
        return (new AttemptService($k))->submit((int) $a->int('attempt_id', true, 1), $answers);
    }

    /** POST attest {run_id, signature_png?, pin} => Receipt */
    public static function attest(KioskCtx $k, ApiContext $a): array
    {
        $pin = $a->input['pin'] ?? null;
        unset($a->input['pin']);
        try {
            $sig = $a->input['signature_png'] ?? null;
            return (new AttestService($k))->attest(self::runId($a), is_string($sig) ? $sig : null, $pin);
        } finally {
            unset($pin);
            PinGate::pad($k);
        }
    }

    // ---- input helpers ---------------------------------------------------------------------------

    private static function runId(ApiContext $a): int
    {
        return (int) $a->int('run_id', true, 1);
    }

    private static function uid(ApiContext $a): string
    {
        $u = (string) $a->str('lesson_uid', 12);
        if (preg_match(self::UID_RE, $u) !== 1) {
            throw ApiException::validation(['lesson_uid' => 'Not a valid lesson.']);
        }
        return $u;
    }

    /**
     * A tick sample with only well-typed fields: position_s (number 0..86400), pages_seen (list of
     * ints, at most 150), playing/visible/active (bool), video_id (string <= 64, [A-Za-z0-9_-]).
     */
    public static function sample(array $in): array
    {
        $out = [];
        $p = $in['position_s'] ?? null;
        if ((is_int($p) || is_float($p)) && is_finite((float) $p) && $p >= 0 && $p <= 86400) {
            $out['position_s'] = $p;
        }
        $pages = $in['pages_seen'] ?? null;
        if (is_array($pages) && array_is_list($pages)) {
            $out['pages_seen'] = array_slice(array_values(array_filter($pages, static fn($x) => is_int($x) && $x >= 1 && $x <= 150)), 0, 150);
        }
        foreach (['playing', 'visible', 'active'] as $b) {
            $out[$b] = ($in[$b] ?? false) === true;
        }
        $vid = $in['video_id'] ?? null;
        if (is_string($vid) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $vid) === 1) {
            $out['video_id'] = $vid;
        }
        return $out;
    }
}
