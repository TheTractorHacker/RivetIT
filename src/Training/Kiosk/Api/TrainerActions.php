<?php

namespace ITFlow\Training\Kiosk\Api;

use ITFlow\Training\Api\ApiContext;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Trainer\CheckinService;
use ITFlow\Training\Kiosk\Trainer\EvaluateService;
use ITFlow\Training\Kiosk\Trainer\TrainerPin;
use ITFlow\Training\Kiosk\Trainer\TrainerService;

/**
 * Trainer-mode kiosk actions (P3 spec §4.2 K5 rows; lane K5). The router has already checked
 * the role (trainer / checkin / handoff), the kiosk CSRF token and the request shape. Every
 * handler that verifies a PIN pads its response to >= 800 ms in a finally (§0.6), error paths
 * included, and never keeps the PIN past the service call.
 */
final class TrainerActions
{
    /** GET trainer_courses: courses with a session or practical part this trainer covers, plus their departments. */
    public static function courses(KioskCtx $k, ApiContext $a): array
    {
        $svc = new TrainerService($k);
        return ['courses' => $svc->courses()] + $svc->departments();
    }

    /** POST session_start {course_id, topic?, location?, client_id?} */
    public static function sessionStart(KioskCtx $k, ApiContext $a): array
    {
        return (new TrainerService($k))->startSession(
            (int) $a->int('course_id', true, 1),
            $a->str('topic', 200, false, true),
            $a->str('location', 200, false, true),
            (int) ($a->int('client_id', false, 0) ?? 0)
        );
    }

    /** GET session_get {tsession_id} - trainer: roster + suggested; check-in: the bound session only (the id is ignored). */
    public static function sessionGet(KioskCtx $k, ApiContext $a): array
    {
        if ($k->role() === 'checkin') {
            return (new CheckinService($k))->view();
        }
        return (new TrainerService($k))->session((int) $a->int('tsession_id', true, 1));
    }

    /** POST checkin_enter {tsession_id} */
    public static function checkinEnter(KioskCtx $k, ApiContext $a): array
    {
        return (new TrainerService($k))->checkinEnter((int) $a->int('tsession_id', true, 1));
    }

    /** POST checkin_attendee {contact_id, sig, pin, signature_png?} (check-in role) */
    public static function checkinAttendee(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new CheckinService($k))->checkIn(
                (int) $a->int('contact_id', true, 1),
                (string) $a->str('sig', 32),
                $pin,
                $a->input['signature_png'] ?? null
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] T-6 GET checkin_sessions (device, pre-auth): open kiosk sessions for parallel check-in. */
    public static function checkinSessions(KioskCtx $k, ApiContext $a): array
    {
        return (new CheckinService($k))->openSessions();
    }

    /** [S] T-6 POST checkin_self {tsession_id, contact_id, sig, pin, signature_png?} (device, pre-auth) */
    public static function checkinSelf(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new CheckinService($k))->selfCheckIn(
                (int) $a->int('tsession_id', true, 1),
                (int) $a->int('contact_id', true, 1),
                (string) $a->str('sig', 32),
                $pin,
                $a->input['signature_png'] ?? null
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** POST checkin_exit {pin} (check-in role) */
    public static function checkinExit(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new CheckinService($k))->exit($pin);
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** POST session_finalize {tsession_id, pin, signature_png, confirm:true} */
    public static function sessionFinalize(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new TrainerService($k))->finalize(
                (int) $a->int('tsession_id', true, 1),
                $pin,
                $a->input['signature_png'] ?? null,
                ($a->input['confirm'] ?? null) === true
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] POST attendee_attest {tsession_id, contact_id, sig, reason, reason_text?, pin} - "Mark present (no PIN)". */
    public static function attendeeAttest(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            $cid = (int) $a->int('contact_id', true, 1);
            if (!hash_equals($k->keys->pickSig($k->kioskId(), $cid), (string) $a->str('sig', 32))) {
                throw ApiException::notFound('That person was not found.');
            }
            return (new TrainerService($k))->attest(
                (int) $a->int('tsession_id', true, 1),
                $cid,
                (string) $a->enum('reason', array_keys(TrainerService::ATTEST_REASONS)),
                $a->str('reason_text', 255, false, true),
                $pin
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] A-5 GET trainer_badges: the manual badges a trainer can give. */
    public static function trainerBadges(KioskCtx $k, ApiContext $a): array
    {
        return (new TrainerService($k))->badges();
    }

    /** [S] A-5 POST trainer_award {achievement_id, contact_id, sig, reason, pin} */
    public static function trainerAward(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            $cid = (int) $a->int('contact_id', true, 1);
            if (!hash_equals($k->keys->pickSig($k->kioskId(), $cid), (string) $a->str('sig', 32))) {
                throw ApiException::notFound('That person was not found.');
            }
            return (new TrainerService($k))->award(
                (int) $a->int('achievement_id', true, 1),
                $cid,
                (string) $a->str('reason', 500),
                $pin
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] POST attendee_mark {attendee_id, attendance?, practical?, notes?, pin} */
    public static function attendeeMark(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new TrainerService($k))->mark(
                (int) $a->int('attendee_id', true, 1),
                $a->enum('attendance', ['present', 'partial', 'absent'], false),
                $a->enum('practical', ['not_evaluated', 'pass', 'fail'], false),
                $a->str('notes', 500, false, true),
                $pin
            );
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] POST attendee_remove {attendee_id, reason, pin} */
    public static function attendeeRemove(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new TrainerService($k))->remove((int) $a->int('attendee_id', true, 1), (string) $a->str('reason', 255), $pin);
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** [S] POST session_cancel {tsession_id, reason, pin} */
    public static function sessionCancel(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            (new TrainerService($k))->cancel((int) $a->int('tsession_id', true, 1), (string) $a->str('reason', 255), $pin);
            return ['next' => '/kiosk/trainer.php'];
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** GET evaluate_candidates {course_id} */
    public static function evaluateCandidates(KioskCtx $k, ApiContext $a): array
    {
        return (new EvaluateService($k))->candidates((int) $a->int('course_id', true, 1));
    }

    /** POST evaluate_handoff {course_id, contact_id, sig, results:[], equipment?, notes?} */
    public static function evaluateHandoff(KioskCtx $k, ApiContext $a): array
    {
        $results = $a->input['results'] ?? null;
        if (!is_array($results)) {
            throw new ApiException(422, 'checklist_mismatch', 'Mark every item pass or fail.', ['results' => 'Mark every item.']);
        }
        return (new EvaluateService($k))->handoff(
            (int) $a->int('course_id', true, 1),
            (int) $a->int('contact_id', true, 1),
            (string) $a->str('sig', 32),
            $results,
            $a->str('equipment', 200, false, true),
            $a->str('notes', 2000, false, true)
        );
    }

    /** GET evaluate_state {eval_token} (hand-off role): the summary the employee signs. */
    public static function evaluateState(KioskCtx $k, ApiContext $a): array
    {
        return (new EvaluateService($k))->state((string) $a->str('eval_token', 32));
    }

    /** POST evaluate_evaluatee {eval_token, pin, signature_png} (hand-off role) */
    public static function evaluateEvaluatee(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new EvaluateService($k))->evaluatee((string) $a->str('eval_token', 32), $pin, $a->input['signature_png'] ?? null);
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** POST evaluate_submit {eval_token, pin, signature_png} (hand-off role) */
    public static function evaluateSubmit(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new EvaluateService($k))->submit((string) $a->str('eval_token', 32), $pin, $a->input['signature_png'] ?? null);
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }

    /** POST handoff_cancel {pin, eval_token?} (hand-off role) */
    public static function handoffCancel(KioskCtx $k, ApiContext $a): array
    {
        try {
            $pin = $a->input['pin'] ?? null;
            return (new EvaluateService($k))->cancelHandoff($pin, $a->str('eval_token', 32, false, true));
        } finally {
            unset($pin);
            TrainerPin::pad($k);
        }
    }
}
