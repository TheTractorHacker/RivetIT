<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Bridge\SessionBridge;
use ITFlow\Training\Kiosk\Bridge\TrainerBridge;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskStrings;

/**
 * Pass-the-iPad check-in (P3 spec §3.6, §5.7; v0 §6.4 T3; lane K5).
 *
 * The check-in role is a ksess of the TRAINER bound to one session (ksess_tsession_id). Only
 * that id is ever used: a tsession_id sent by the client is ignored, so a check-in screen can
 * never write another session. Each person proves who they are with their own PIN (and a
 * finger signature when the course asks for one); no learner ksess is ever created. Leaving
 * check-in needs the trainer's PIN (the iPad is in someone else's hands).
 */
final class CheckinService
{
    /** [S] T-6: parallel check-in accepts sessions started within this many hours. */
    public const SELF_MAX_AGE_H = 12;

    public function __construct(private readonly KioskCtx $k)
    {
    }

    /** The session this check-in ksess is bound to (open, led by the ksess contact), or 409/404. */
    public function boundSession(): array
    {
        $tsid = (int) ($this->k->ksess['ksess_tsession_id'] ?? 0);
        if ($tsid < 1 || !SessionBridge::available($this->k->db())) {
            throw ApiException::notFound('That session was not found.');
        }
        $s = (new SessionBridge($this->k->core))->row($tsid);
        if ($s === null || $s['tsession_trainer_contact_id'] !== $this->k->contactId()) {
            throw ApiException::notFound('That session was not found.');
        }
        if ($s['tsession_status'] !== 'open') {
            throw new ApiException(409, 'session_closed', 'This session is already finished.');
        }
        return $s;
    }

    /**
     * GET session_get for the check-in role: the course, who is already in (first name and
     * department only) and the expected people not yet in (name + department, never due dates).
     */
    public function view(): array
    {
        $s = $this->boundSession();
        $db = $this->k->db();
        $sb = new SessionBridge($this->k->core);
        $course = CourseInfo::atRevision($db, $s['tsession_course_id'], (int) $s['tsession_revision_id'], $this->k->lang);
        $g = $sb->get($s['tsession_id']);
        $in = [];
        foreach ($g['attendees'] as $a) {
            if ($a['removed']) {
                continue;
            }
            $in[] = ['name' => $a['name'], 'first' => $a['first'], 'initials' => $a['initials'], 'dept' => $a['dept']];
        }
        $expected = [];
        foreach ($sb->suggested($s['tsession_course_id'], $s['tsession_client_id'] > 0 ? [$s['tsession_client_id']] : null, $s['tsession_id'], 60) as $p) {
            if ($p['contact_id'] === $this->k->contactId()) {
                continue;
            }
            $expected[] = ['contact_id' => $p['contact_id'], 'sig' => $this->k->keys->pickSig($this->k->kioskId(), $p['contact_id']),
                           'name' => $p['name'], 'first' => $p['first'], 'initials' => $p['initials'], 'dept' => $p['dept']];
        }
        return [
            'session' => [
                'tsession_id' => $s['tsession_id'],
                'course' => $course['name'] ?? '',
                'trainer' => (string) $s['tsession_trainer_name'],
                'trainer_first' => KioskAuth::firstName((string) $s['tsession_trainer_name']),
                'location' => $s['tsession_location'] === null ? null : (string) $s['tsession_location'],
                'requires_signature' => (bool) ($course['requires_signature'] ?? true),
            ],
            'attendees' => $in,
            'suggested' => $expected,
        ];
    }

    /**
     * POST checkin_attendee {contact_id, sig, pin, signature_png?}. The person picked their name
     * (sig = the kiosk's pick signature for that id), drew their signature when the course needs
     * one (checked BEFORE the PIN so a bad drawing never costs a PIN try) and entered their PIN.
     * Duplicate => {already:true} and nothing is written.
     */
    public function checkIn(int $contactId, string $sig, mixed $pin, mixed $sigDataUrl): array
    {
        $s = $this->boundSession();
        if (!$this->trainerStillActive($this->k->contactId())) {
            throw new ApiException(403, 'not_trainer', 'The trainer of this session is no longer set up to run sessions.');
        }
        return $this->record($s, $contactId, $sig, $pin, $sigDataUrl);
    }

    /**
     * [S] T-6 GET checkin_sessions (device, pre-auth): open kiosk sessions started in the last
     * 12 hours whose trainer is still an active trainer - for parallel check-in on another device.
     */
    public function openSessions(): array
    {
        $db = $this->k->db();
        if (!SessionBridge::available($db)) {
            return ['sessions' => []];
        }
        $out = [];
        foreach ((new SessionBridge($this->k->core))->listOpen(null, self::SELF_MAX_AGE_H) as $row) {
            if (!$this->trainerStillActive($row['trainer_contact_id'])) {
                continue;
            }
            $c = CourseInfo::current($db, $row['course_id'], $this->k->lang);
            $out[] = ['tsession_id' => $row['tsession_id'], 'course' => $c['name'] ?? '', 'trainer' => $row['trainer_name'],
                      'started_at' => \ITFlow\Training\Core\Clock::toIso($row['started_at_utc'], true), 'location' => $row['location'],
                      'requires_signature' => (bool) ($c['requires_signature'] ?? true)];
        }
        return ['sessions' => $out];
    }

    /**
     * [S] T-6 POST checkin_self {tsession_id, contact_id, sig, pin, signature_png?} (device,
     * pre-auth): the same check-in as the pass-the-iPad screen, on another enrolled device, into
     * an open kiosk session started within 12 hours. No ksess is created.
     */
    public function selfCheckIn(int $tsessionId, int $contactId, string $sig, mixed $pin, mixed $sigDataUrl): array
    {
        $db = $this->k->db();
        if ($this->k->device === null || !SessionBridge::available($db)) {
            throw ApiException::notFound('That session was not found.');
        }
        $s = (new SessionBridge($this->k->core))->row($tsessionId);
        $since = \ITFlow\Training\Kiosk\Core\KTime::plus(-self::SELF_MAX_AGE_H * 3600);
        if ($s === null || $s['tsession_status'] !== 'open' || $s['tsession_channel'] !== 'kiosk' || $s['tsession_trainer_contact_id'] === null
            || $s['tsession_started_at_utc'] === null || (string) $s['tsession_started_at_utc'] < $since
            || !$this->trainerStillActive((int) $s['tsession_trainer_contact_id'])) {
            throw ApiException::notFound('That session was not found.');
        }
        return $this->record($s, $contactId, $sig, $pin, $sigDataUrl);
    }

    /** Shared check-in: pick signature, eligibility, duplicate, signature BEFORE the PIN, the person's PIN, then one tx. */
    private function record(array $s, int $contactId, string $sig, mixed $pin, mixed $sigDataUrl): array
    {
        $db = $this->k->db();
        $trainerId = (int) $s['tsession_trainer_contact_id'];
        if (!hash_equals($this->k->keys->pickSig($this->k->kioskId(), $contactId), $sig)) {
            throw ApiException::notFound('That person was not found.');
        }
        if ($contactId === $trainerId) {
            throw ApiException::validation(['contact_id' => 'The trainer leads this session and does not check in.']);
        }
        $person = (new TrainerService($this->k))->person($contactId);
        $sb = new SessionBridge($this->k->core, $this->actorFor($contactId));
        if ($sb->isCheckedIn($s['tsession_id'], $contactId)) {
            unset($pin);
            return ['first' => $person['first'], 'already' => true];
        }
        $course = CourseInfo::atRevision($db, $s['tsession_course_id'], (int) $s['tsession_revision_id'], $this->k->lang);
        $needSig = (bool) ($course['requires_signature'] ?? true);
        $prep = TrainerSig::prepare($sigDataUrl, $needSig);
        TrainerPin::require($this->k, $contactId, $pin, 'checkin');
        unset($pin);
        $k = $this->k;
        $tsid = $s['tsession_id'];
        $statement = KioskStrings::t($this->k->lang, 'trn.checkin_statement', ['course' => (string) ($course['name'] ?? '')]);
        $actor = $this->actorFor($contactId);
        $r = Db::tx($db, static function () use ($db, $k, $sb, $prep, $tsid, $trainerId, $contactId, $person, $statement, $actor): array {
            $sb->lockOpen($tsid, $trainerId);
            if ($sb->isCheckedIn($tsid, $contactId)) {
                return ['already' => true];
            }
            $tsigId = null;
            $sigEvent = null;
            if ($prep !== null) {
                $sig = TrainerSig::insert($k, $prep, [
                    'purpose' => 'attendee', 'contact_id' => $contactId, 'signer_name' => $person['name'],
                    'statement_sha256' => TrainerSig::statementSha($statement), 'tsession_id' => $tsid,
                ]);
                $tsigId = $sig['id'];
                $sigEvent = array_merge($sig['event'], $actor);
            }
            $r = $sb->checkIn($tsid, $contactId, $prep !== null ? 'self_pin_signature' : 'self_pin', $tsigId, null, $k->kioskId(), $contactId);
            if ($sigEvent !== null) {
                Ledger::append($db, $sigEvent);
            }
            foreach ($r['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $r;
        });
        return ['first' => $person['first'], 'already' => (bool) $r['already']];
    }

    /**
     * POST checkin_exit {pin}: the trainer takes the iPad back. Trainer PIN (purpose
     * trainer_exit - exempt from the kiosk cooldown and the global pause, never from the
     * trainer's own lock), then the check-in ksess ends 'checkin_exit' and a new trainer ksess
     * starts. Returns {next} and sets the new cookie.
     */
    public function exit(mixed $trainerPin): array
    {
        $tsid = (int) ($this->k->ksess['ksess_tsession_id'] ?? 0);
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_exit');
        unset($trainerPin);
        $db = $this->k->db();
        $k = $this->k;
        $sw = Db::tx($db, static function () use ($db, $k): array {
            $sw = RoleSwitch::switch($k, 'checkin_exit', 'trainer', $k->contactId(), null);
            foreach ($sw['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $sw;
        });
        KioskAuth::setSessionCookie($sw['token']);
        return ['next' => $tsid > 0 ? '/kiosk/session.php?s=' . $tsid : '/kiosk/trainer.php'];
    }

    /** The ledger actor of a check-in: the person who proved it with their PIN, on this kiosk and ksess (§0.9). */
    private function actorFor(int $contactId): array
    {
        return array_merge($this->k->eventBase(), ['actor_type' => 'contact', 'actor_contact_id' => $contactId]);
    }

    /** Is the trainer of this session still an active trainer who may train? (Re-read per request, v0 §6.4 T1.) */
    public function trainerStillActive(int $trainerContactId): bool
    {
        $t = (new TrainerBridge($this->k->db()))->trainer($trainerContactId);
        return $t !== null && $t['active'] && $t['can_train'];
    }
}
