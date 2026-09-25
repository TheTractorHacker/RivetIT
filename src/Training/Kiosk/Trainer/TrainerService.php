<?php

namespace ITFlow\Training\Kiosk\Trainer;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Text;
use ITFlow\Training\Kiosk\Bridge\SessionBridge;
use ITFlow\Training\Kiosk\Bridge\TrainerBridge;
use ITFlow\Training\Kiosk\Core\Eligibility;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskStrings;

/**
 * Trainer mode, trainer role (P3 spec §3.6, §5.7; v0 §6.4 T1-T6; lane K5).
 *
 * Every call re-reads the trainer row (TrainerBridge::trainer) - a trainer deactivated in the
 * office loses these powers on the next request - and checks the flag the action needs
 * (can_train / can_evaluate) plus course and department scope. Every tsession_id / attendee_id
 * must belong to an OPEN session whose trainer is this contact, else 404 (never "someone
 * else's session"). Every record-creating action re-enters the trainer PIN (§8 "Trainer power").
 */
final class TrainerService
{
    public const ATTEST_REASONS = ['no_pin' => 'No PIN yet', 'forgot_pin' => 'Forgot PIN', 'other' => null];

    public function __construct(private readonly KioskCtx $k)
    {
    }

    /** The signed-in trainer (active), or 403 not_trainer. */
    public function me(): array
    {
        $t = (new TrainerBridge($this->k->db()))->trainer($this->k->contactId());
        if ($t === null || !$t['active'] || (!$t['can_train'] && !$t['can_evaluate'])) {
            throw new ApiException(403, 'not_trainer', 'You are not set up as a trainer.');
        }
        return $t;
    }

    /** What the trainer home shows: which tiles, from flags and bridge availability. */
    public function home(): array
    {
        $t = $this->me();
        $db = $this->k->db();
        $sessions = $t['can_train'] && SessionBridge::available($db) && TrainerSig::available() && TrainerPin::available();
        $evaluate = $t['can_evaluate'] && \ITFlow\Training\Kiosk\Bridge\EvaluationBridge::available($db) && TrainerSig::available() && TrainerPin::available();
        return [
            'can_train' => $t['can_train'],
            'can_evaluate' => $t['can_evaluate'],
            'can_view_team' => $t['can_view_team'],
            'sessions' => $sessions,
            'evaluate' => $evaluate,
            'team' => $t['can_view_team'] && TeamService::available($db),
            'open_sessions' => $sessions ? $this->openSessions() : [],
        ];
    }

    /**
     * Published, non-archived courses this trainer covers that have a session or practical part.
     *
     * @return list<array{id:int, name:string, needs_session:bool, needs_practical:bool, needs_online:bool, allow_trainer_attest:bool, requires_signature:bool}>
     */
    public function courses(): array
    {
        $t = $this->me();
        $tb = new TrainerBridge($this->k->db());
        $out = [];
        foreach (CourseInfo::publishedIds($this->k->db()) as $id) {
            if (!$tb->canCourse($t, $id)) {
                continue;
            }
            $c = CourseInfo::current($this->k->db(), $id, $this->k->lang);
            if ($c === null || (!$c['needs_session'] && !$c['needs_practical'])) {
                continue;
            }
            $out[] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'needs_session' => $c['needs_session'],
                'needs_practical' => $c['needs_practical'],
                'needs_online' => $c['needs_online'],
                'allow_trainer_attest' => $c['allow_trainer_attest'],
                'requires_signature' => $c['requires_signature'],
            ];
        }
        return $out;
    }

    /**
     * Departments the trainer may run a session for, with the default (the kiosk's default
     * department when it is in scope, else the only one).
     *
     * @return array{departments:list<array{id:int, name:string}>, default_id:int}
     */
    public function departments(): array
    {
        $t = $this->me();
        $scope = (new TrainerBridge($this->k->db()))->scopeClientIds($t);
        $sql = 'SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL';
        $types = '';
        $p = [];
        if ($scope !== null) {
            if ($scope === []) {
                return ['departments' => [], 'default_id' => 0];
            }
            $sql .= ' AND client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types = str_repeat('i', count($scope));
            $p = $scope;
        }
        $rows = Db::all($this->k->db(), $sql . ' ORDER BY client_name, client_id LIMIT 300', $types, $p);
        $list = array_map(static fn($r) => ['id' => (int) $r['client_id'], 'name' => (string) $r['client_name']], $rows);
        $ids = array_column($list, 'id');
        $def = (int) ($this->k->device['kiosk_default_client_id'] ?? 0);
        if (!in_array($def, $ids, true)) {
            $def = count($ids) === 1 ? $ids[0] : 0;
        }
        return ['departments' => $list, 'default_id' => $def];
    }

    /** POST session_start. Opens a kiosk session for a course with a session part. */
    public function startSession(int $courseId, ?string $topic, ?string $location, int $clientId): array
    {
        $t = $this->me();
        if (!$t['can_train']) {
            throw new ApiException(403, 'not_trainer', 'You are not set up to run sessions.');
        }
        $db = $this->k->db();
        if (!SessionBridge::available($db)) {
            throw new ApiException(503, 'records_unavailable', "Group sessions aren't available yet.");
        }
        $tb = new TrainerBridge($db);
        $course = CourseInfo::current($db, $courseId, $this->k->lang);
        if ($course === null || !$tb->canCourse($t, $courseId)) {
            throw ApiException::notFound('That course was not found.');
        }
        if (!$course['needs_session']) {
            throw ApiException::validation(['course_id' => 'This course has no group session.']);
        }
        if ($clientId <= 0) {
            $clientId = $this->departments()['default_id'];
        }
        if ($clientId <= 0 || !$tb->inScope($t, $clientId)
            || Db::one($db, 'SELECT client_id FROM clients WHERE client_id = ? AND client_archived_at IS NULL', 'i', [$clientId]) === null) {
            throw ApiException::validation(['client_id' => 'Choose one of your departments.']);
        }
        $location = $location === null || trim($location) === '' ? (string) ($this->k->device['kiosk_label'] ?? '') : trim($location);
        $name = $this->contactName($this->k->contactId());
        $id = (new SessionBridge($this->k->core, $this->k->eventBase()))->open([
            'course_id' => $courseId,
            'revision_id' => $course['revision_id'],
            'client_id' => $clientId,
            'location' => Text::clip($location, 200),
            'topic' => $topic === null || trim($topic) === '' ? null : Text::clip(trim($topic), 200),
            'trainer_contact_id' => $this->k->contactId(),
            'trainer_name' => $name,
            'kiosk_id' => $this->k->kioskId(),
        ]);
        return ['tsession_id' => $id];
    }

    /** This trainer's open kiosk sessions (newest first, last 24 h). */
    public function openSessions(): array
    {
        $db = $this->k->db();
        if (!SessionBridge::available($db)) {
            return [];
        }
        $out = [];
        foreach ((new SessionBridge($this->k->core))->listOpen(null, 24) as $s) {
            if ($s['trainer_contact_id'] !== $this->k->contactId()) {
                continue;
            }
            $c = CourseInfo::current($db, $s['course_id'], $this->k->lang);
            $out[] = ['tsession_id' => $s['tsession_id'], 'course' => $c['name'] ?? '', 'started_at' => \ITFlow\Training\Core\Clock::toIso($s['started_at_utc'], true),
                      'topic' => $s['topic'], 'location' => $s['location']];
        }
        return $out;
    }

    /** GET session_get (trainer). Roster + suggested people. */
    public function session(int $tsessionId): array
    {
        $t = $this->me();
        $db = $this->k->db();
        $sb = new SessionBridge($this->k->core);
        $s = $this->ownOpen($sb, $tsessionId);
        $g = $sb->get($tsessionId);
        $course = CourseInfo::atRevision($db, $s['tsession_course_id'], (int) $s['tsession_revision_id'], $this->k->lang);
        $tb = new TrainerBridge($db);
        $scope = $s['tsession_client_id'] > 0 ? [$s['tsession_client_id']] : $tb->scopeClientIds($t);
        $suggested = [];
        foreach ($sb->suggested($s['tsession_course_id'], $scope, $tsessionId) as $p) {
            if ($p['contact_id'] === $this->k->contactId()) {
                continue;
            }
            $p['sig'] = $this->k->keys->pickSig($this->k->kioskId(), $p['contact_id']);
            unset($p['client_id']);
            $suggested[] = $p;
        }
        $dept = $s['tsession_client_id'] > 0 ? Db::one($db, 'SELECT client_name FROM clients WHERE client_id = ?', 'i', [$s['tsession_client_id']]) : null;
        return [
            'session' => $this->sessionShape($s, $course, (string) ($dept['client_name'] ?? '')),
            'attendees' => $g['attendees'],
            'suggested' => $suggested,
        ];
    }

    /** POST checkin_enter: the trainer hands the iPad around. Ends the trainer ksess ('checkin_enter') and starts a checkin ksess bound to the session. */
    public function checkinEnter(int $tsessionId): array
    {
        $t = $this->me();
        if (!$t['can_train']) {
            throw new ApiException(403, 'not_trainer', 'You are not set up to run sessions.');
        }
        $db = $this->k->db();
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $this->ownOpen($sb, $tsessionId);
        $k = $this->k;
        $sw = Db::tx($db, static function () use ($db, $k, $sb, $tsessionId): array {
            $sb->lockOpen($tsessionId, $k->contactId());
            $sw = RoleSwitch::switch($k, 'checkin_enter', 'checkin', $k->contactId(), $tsessionId);
            foreach ($sw['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $sw;
        });
        KioskAuth::setSessionCookie($sw['token']);
        return ['next' => '/kiosk/session.php'];
    }

    /** [S] POST attendee_attest: "Mark present (no PIN)" - course must allow it; reason + trainer PIN. */
    public function attest(int $tsessionId, int $contactId, string $reasonCode, ?string $reasonText, mixed $trainerPin): array
    {
        $t = $this->me();
        $db = $this->k->db();
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $s = $this->ownOpen($sb, $tsessionId);
        $course = CourseInfo::atRevision($db, $s['tsession_course_id'], (int) $s['tsession_revision_id']);
        if ($course === null || !$course['allow_trainer_attest']) {
            throw new ApiException(403, 'forbidden', 'This course needs each person to check in with their own PIN.');
        }
        if (!array_key_exists($reasonCode, self::ATTEST_REASONS)) {
            throw ApiException::validation(['reason' => 'Choose a reason.']);
        }
        $reason = self::ATTEST_REASONS[$reasonCode];
        if ($reason === null) {
            $reason = $reasonText === null ? '' : trim($reasonText);
            if (mb_strlen($reason, 'UTF-8') < 5) {
                throw ApiException::validation(['reason_text' => 'Say why (at least 5 characters).']);
            }
        }
        $person = $this->person($contactId);
        if ($contactId === $this->k->contactId()) {
            throw ApiException::validation(['contact_id' => 'You lead this session, so you are not an attendee.']);
        }
        if (!(new TrainerBridge($db))->inScope($t, $person['client_id'])) {
            throw ApiException::notFound('That person is not in your departments.');
        }
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_action');
        unset($trainerPin);
        $k = $this->k;
        $r = Db::tx($db, static function () use ($db, $k, $sb, $tsessionId, $contactId, $reason): array {
            $sb->lockOpen($tsessionId, $k->contactId());
            $r = $sb->checkIn($tsessionId, $contactId, 'trainer_attested', null, (string) Text::clip($reason, 255), $k->kioskId(), $k->contactId());
            foreach ($r['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $r;
        });
        return ['already' => $r['already'], 'first' => $person['first']] + $this->session($tsessionId);
    }

    /** [S] POST attendee_mark: attendance / practical / notes with the trainer PIN. */
    public function mark(int $attendeeId, ?string $attendance, ?string $practical, ?string $notes, mixed $trainerPin): array
    {
        $this->me();
        $db = $this->k->db();
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $s = $sb->sessionOfAttendee($attendeeId);
        if ($s === null) {
            throw ApiException::notFound('That person is not on this session.');
        }
        $s = $this->ownOpen($sb, $s['tsession_id']);
        $course = CourseInfo::atRevision($db, $s['tsession_course_id'], (int) $s['tsession_revision_id']);
        if ($practical !== null && ($course === null || !$course['needs_practical'])) {
            throw ApiException::validation(['practical' => 'This course has no practical part.']);
        }
        if ($attendance === null && $practical === null && $notes === null) {
            throw ApiException::validation(['attendance' => 'Nothing to change.']);
        }
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_action');
        unset($trainerPin);
        $sb->mark($attendeeId, $attendance, $practical, $notes, $this->k->contactId());
        return $this->session($s['tsession_id']);
    }

    /** [S] POST attendee_remove: soft remove with a reason and the trainer PIN. */
    public function remove(int $attendeeId, string $reason, mixed $trainerPin): array
    {
        $this->me();
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $s = $sb->sessionOfAttendee($attendeeId);
        if ($s === null) {
            throw ApiException::notFound('That person is not on this session.');
        }
        $s = $this->ownOpen($sb, $s['tsession_id']);
        if (mb_strlen(trim($reason), 'UTF-8') < 3) {
            throw ApiException::validation(['reason' => 'Say why (at least 3 characters).']);
        }
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_action');
        unset($trainerPin);
        $sb->remove($attendeeId, trim($reason), $this->k->contactId());
        return $this->session($s['tsession_id']);
    }

    /**
     * POST session_finalize: the trainer ticks the attestation, signs and re-enters their PIN;
     * one transaction freezes the session and issues the records (SessionBridge::finalize).
     */
    public function finalize(int $tsessionId, mixed $trainerPin, mixed $sigDataUrl, bool $confirm): array
    {
        $t = $this->me();
        if (!$confirm) {
            throw ApiException::validation(['confirm' => 'Tick the box to confirm.']);
        }
        $db = $this->k->db();
        if (!SessionBridge::available($db)) {
            throw new ApiException(503, 'records_unavailable', "Training records aren't available right now.");
        }
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $s = $this->ownOpen($sb, $tsessionId);
        $present = Db::one($db, "SELECT COUNT(*) AS n FROM training_session_attendees WHERE tattendee_tsession_id = ? AND tattendee_removed_at_utc IS NULL
            AND tattendee_attendance = 'present'", 'i', [$tsessionId]);
        if ((int) ($present['n'] ?? 0) < 1) {
            throw new ApiException(422, 'validation', 'Check in at least one person, or mark someone present, before finishing.', ['attendees' => 'Nobody is present.']);
        }
        $prep = TrainerSig::prepare($sigDataUrl, true);
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_finalize');
        unset($trainerPin);
        $k = $this->k;
        $name = $this->contactName($k->contactId());
        $statement = KioskStrings::t($k->lang, 'trn.finalize_attest');
        $r = Db::tx($db, static function () use ($db, $k, $sb, $prep, $tsessionId, $name, $statement): array {
            $sb->lockOpen($tsessionId, $k->contactId());
            $sig = TrainerSig::insert($k, $prep, [
                'purpose' => 'trainer', 'contact_id' => $k->contactId(), 'signer_name' => $name,
                'statement_sha256' => TrainerSig::statementSha($statement), 'tsession_id' => $tsessionId,
            ]);
            $r = $sb->finalize($tsessionId, $k->contactId(), $sig['id'], $k->kioskId());
            Ledger::append($db, $sig['event']);
            foreach ($r['events'] as $e) {
                Ledger::append($db, $e);
            }
            return $r;
        });
        $sb->afterCommit($r);
        self::settle($k, $r['opaque']['present'] ?? []);
        $list = [];
        foreach ($r['completions'] as $c) {
            $list[] = ['name' => $c['name'], 'status' => 'recorded', 'cert_number' => $c['cert_number'], 'reason' => null];
        }
        foreach ($r['pending'] as $p) {
            $list[] = ['name' => $p['name'], 'status' => 'pending', 'cert_number' => null, 'reason' => $p['reason']];
        }
        usort($list, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return ['completions' => count($r['completions']), 'pending' => count($r['pending']), 'list' => $list, 'course' => $this->courseName($s)];
    }

    /** [S] POST session_cancel with a reason and the trainer PIN. */
    public function cancel(int $tsessionId, string $reason, mixed $trainerPin): void
    {
        $this->me();
        $sb = new SessionBridge($this->k->core, $this->k->eventBase());
        $this->ownOpen($sb, $tsessionId);
        if (mb_strlen(trim($reason), 'UTF-8') < 5) {
            throw ApiException::validation(['reason' => 'Say why (at least 5 characters).']);
        }
        TrainerPin::require($this->k, $this->k->contactId(), $trainerPin, 'trainer_action');
        unset($trainerPin);
        $sb->cancel($tsessionId, trim($reason), $this->k->contactId());
    }

    // ---------------------------------------------------------------------------------------

    /** The session when it is OPEN and led by this trainer; 404 otherwise (never "someone else's"). */
    public function ownOpen(SessionBridge $sb, int $tsessionId): array
    {
        $s = $sb->row($tsessionId);
        if ($s === null || $s['tsession_trainer_contact_id'] !== $this->k->contactId() || $s['tsession_status'] !== 'open') {
            throw ApiException::notFound('That session was not found.');
        }
        return $s;
    }

    public function sessionShape(array $s, ?array $course, string $dept): array
    {
        return [
            'tsession_id' => $s['tsession_id'],
            'course_id' => $s['tsession_course_id'],
            'course' => $course['name'] ?? '',
            'status' => (string) $s['tsession_status'],
            'held_on' => (string) $s['tsession_held_on'],
            'started_at' => \ITFlow\Training\Core\Clock::toIso($s['tsession_started_at_utc'] === null ? null : (string) $s['tsession_started_at_utc'], true),
            'location' => $s['tsession_location'] === null ? null : (string) $s['tsession_location'],
            'topic' => $s['tsession_topic'] === null ? null : (string) $s['tsession_topic'],
            'dept' => $dept,
            'trainer' => (string) $s['tsession_trainer_name'],
            'needs_practical' => (bool) ($course['needs_practical'] ?? false),
            'needs_online' => (bool) ($course['needs_online'] ?? false),
            'allow_trainer_attest' => (bool) ($course['allow_trainer_attest'] ?? false),
            'requires_signature' => (bool) ($course['requires_signature'] ?? true),
        ];
    }

    /** A person picked on this kiosk: must exist, not be archived and be eligible. */
    public function person(int $contactId): array
    {
        $r = Db::one($this->k->db(), 'SELECT c.contact_id, c.contact_name, c.contact_client_id, c.contact_archived_at, cl.client_name
            FROM contacts c LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ?', 'i', [$contactId]);
        if ($r === null || $r['contact_archived_at'] !== null || !Eligibility::isEligible($this->k->db(), $contactId, $this->k->core)) {
            throw ApiException::notFound('That person was not found.');
        }
        $name = trim((string) $r['contact_name']);
        return ['contact_id' => $contactId, 'name' => $name, 'first' => KioskAuth::firstName($name), 'initials' => KioskAuth::initials($name),
                'client_id' => (int) $r['contact_client_id'], 'dept' => (string) ($r['client_name'] ?? '')];
    }

    public function contactName(int $contactId): string
    {
        $r = Db::one($this->k->db(), 'SELECT contact_name FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        return trim((string) ($r['contact_name'] ?? ''));
    }

    private function courseName(array $s): string
    {
        $c = CourseInfo::atRevision($this->k->db(), $s['tsession_course_id'], (int) $s['tsession_revision_id'], $this->k->lang);
        return $c['name'] ?? '';
    }

    /** After a session or evaluation commit: K3's RunService::settleAwaiting for each affected person (closes awaiting_* runs). Best-effort. */
    public static function settle(KioskCtx $k, array $contactIds): void
    {
        $cls = '\\ITFlow\\Training\\Kiosk\\Learn\\RunService';
        if (!class_exists($cls) || !method_exists($cls, 'settleAwaiting')) {
            return;
        }
        foreach (array_values(array_unique(array_map('intval', $contactIds))) as $cid) {
            if ($cid < 1) {
                continue;
            }
            try {
                (new $cls($k))->settleAwaiting($cid);
            } catch (\Throwable $e) {
                error_log('Kiosk trainer settleAwaiting: ' . get_class($e));
            }
        }
    }
}
