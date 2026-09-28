<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskAlerts;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Trainer PIN sign-in and step-up (2.6.104): a PARALLEL, purpose-built copy of Pin\PinService's
 * reserve/verify/settle state machine, scoped entirely to training_trainers' trainer_pin_*
 * columns (Pin\TrainerCredentialRepo) instead of training_learner_credentials. Built separate on
 * purpose - a shared implementation with a mode flag threaded through it would risk the exact
 * cross-contamination the owner asked to eliminate (a trainer's own learner/Odoo PIN sign-in must
 * stay completely untouched by anything here).
 *
 * Same discipline as PinService, deliberately kept identical:
 *   0  validate BEFORE any bookkeeping: $pin is ^[0-9]{4,12}$ (PinService::PIN_RE, unchanged);
 *      the contact is an active trainer (Seam::isActiveTrainer, defense in depth - every caller
 *      already checked this too); no hash yet => one dummy verify, then 'setup_needed'
 *   1  non-blocking named lock 'ttpin:<cid>' ('busy'), released in finally - its own namespace,
 *      never the learner flow's 'tpin:<cid>'
 *   2  RESERVE (short tx): global pause / kiosk cooldown (skipped for PinService::TRAINER_PURPOSES,
 *      exactly like the learner flow - a mid-session re-entry never gets shut out by the pause/
 *      cooldown a LOGIN would respect), row FOR UPDATE (hard lock / timed lock), CHARGE
 *      failed_count+1, ledger pin.attempt {scope:'trainer'}
 *   3  VERIFY with no transaction and no row locks (PinHasher, the SAME hasher/pepper/policy the
 *      learner local PIN uses - the separation here is the STORAGE, not the crypto material)
 *   4  SETTLE (tx, row FOR UPDATE again): match => reset counters, ledger pin.ok, and for a login
 *      the kiosk session (KioskAuth::startSession, role 'trainer'); wrong => keep the charge, lock
 *      transitions (same KioskSettings thresholds as the learner flow), ledger pin.fail
 *   5  after COMMIT: PinCaps::afterFailure (kiosk-wide anti-spray, unchanged - it counts pin.fail
 *      ledger events regardless of which credential produced them), a hard lock's fixed-text
 *      alert, a hard lock on the CURRENT session's own step-up ends that session
 * Never writes `logs` with log_type 'Login'. Never logs, stores or returns the PIN. Every
 * PIN-verifying handler still wraps its body in try { … } finally { PinService::pad(...) } -
 * MIN_RESPONSE_MS is a pure function of elapsed time, so there is nothing to fork; the SAME
 * PinService::pad() paces this flow too (Trainer\TrainerPin::pad() already calls it).
 */
final class TrainerPinService
{
    private readonly PinHasher $hasher;

    public function __construct(private readonly KioskCtx $k)
    {
        $this->hasher = new PinHasher($k->keys);
    }

    /** Trainer sign-in on this device. Returns ['result' => PinResult, 'token' => ?string]; the caller sets the session cookie from the token. */
    public function login(int $contactId, mixed $pin): array
    {
        $started = null;
        $r = $this->check($contactId, $pin, 'login', 'trainer', $started);
        unset($pin);
        return ['result' => $r, 'token' => $r->ok() && $started !== null ? $started['token'] : null];
    }

    /** Re-enter the trainer PIN mid-session (PinService::TRAINER_PURPOSES only). Never touches the ksess on success. */
    public function stepUp(int $contactId, mixed $pin, string $purpose): PinResult
    {
        if (!in_array($purpose, PinService::TRAINER_PURPOSES, true)) {
            throw new \InvalidArgumentException('TrainerPinService::stepUp: bad purpose');
        }
        $started = null;
        $r = $this->check($contactId, $pin, $purpose, null, $started);
        unset($pin);
        return $r;
    }

    // ------------------------------------------------------------------------------------------

    private function check(int $contactId, mixed $pin, string $purpose, ?string $role, ?array &$started): PinResult
    {
        $db = $this->k->db();
        if (!is_string($pin) || preg_match(PinService::PIN_RE, $pin) !== 1) {
            return new PinResult('format');
        }
        $contactId = intval($contactId);
        if ($contactId < 1 || !Seam::isActiveTrainer($db, $contactId)) {
            throw new ApiException(403, 'not_trainer', 'Only active trainers can sign in here.');
        }
        if ($role !== null && $this->k->device === null) {
            throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.');
        }
        $cred = TrainerCredentialRepo::load($db, $contactId);
        if ($cred === null) {
            throw new \RuntimeException('TrainerPinService: trainer row missing');
        }
        if (!$cred['has_pin']) {
            $this->hasher->dummyVerify();
            return new PinResult('setup_needed');
        }

        $lockName = 'ttpin:' . $contactId;
        if (!Db::lock($db, $lockName, 0)) {
            return new PinResult('busy');
        }
        try {
            $base = $this->k->eventBase();
            $trainerPurpose = in_array($purpose, PinService::TRAINER_PURPOSES, true);
            $kioskId = $this->k->kioskId();

            // ---- reserve ----
            $reserved = Db::tx($db, function () use ($db, $contactId, $trainerPurpose, $kioskId, $purpose, $base): array {
                if (!$trainerPurpose) {
                    $s = Db::one($db, 'SELECT config_training_pin_pause_until_utc AS p FROM settings WHERE company_id = 1');
                    if (KTime::isFuture($s['p'] ?? null)) {
                        return ['stop' => new PinResult('paused', lockMinutes: KTime::minutesUntil($s['p']))];
                    }
                    if ($kioskId > 0) {
                        $kr = Db::one($db, 'SELECT kiosk_cooldown_until_utc AS c FROM training_kiosks WHERE kiosk_id = ?', 'i', [$kioskId]);
                        if (KTime::isFuture($kr['c'] ?? null)) {
                            return ['stop' => new PinResult('cooldown', lockMinutes: KTime::minutesUntil($kr['c']))];
                        }
                    }
                }
                $row = TrainerCredentialRepo::load($db, $contactId, true);
                if ($row === null) {
                    throw new \RuntimeException('TrainerPinService: trainer row missing');
                }
                if ((int) $row['trainer_pin_hard_locked'] === 1) {
                    return ['stop' => new PinResult('locked_hard')];
                }
                if (KTime::isFuture($row['trainer_pin_locked_until_utc'])) {
                    return ['stop' => new PinResult('locked', lockMinutes: KTime::minutesUntil($row['trainer_pin_locked_until_utc']))];
                }
                Db::exec($db, 'UPDATE training_trainers SET trainer_pin_failed_count = trainer_pin_failed_count + 1 WHERE trainer_contact_id = ?', 'i', [$contactId]);
                Ledger::append($db, array_merge($base, [
                    'type' => 'pin.attempt',
                    'subject_contact_id' => $contactId,
                    'payload' => ['source' => 'local', 'purpose' => $purpose, 'scope' => 'trainer'],
                ]));
                return ['row' => $row];
            });
            if (isset($reserved['stop'])) {
                return $reserved['stop'];
            }
            $row = $reserved['row'];

            // ---- verify (no transaction, no row locks) ----
            $outcome = $this->hasher->verifyPin($contactId, $pin, $row['trainer_pin_hash']) ? 'match' : 'nomatch';
            unset($pin);

            // ---- settle ----
            $ks = $this->k->ks;
            $device = $this->k->device;
            $ua = $this->k->core->userAgent;
            $lang = $this->k->lang;
            $settled = Db::tx($db, function () use ($db, $contactId, $outcome, $purpose, $base, $ks, $device, $role, $ua, $lang): array {
                $row = TrainerCredentialRepo::load($db, $contactId, true);
                if ($row === null) {
                    throw new \RuntimeException('TrainerPinService: trainer row missing');
                }
                $now = KTime::now();
                if ($outcome === 'match') {
                    Db::exec($db, 'UPDATE training_trainers SET trainer_pin_failed_count = 0, trainer_pin_locked_until_utc = NULL,
                            trainer_pin_last_success_at_utc = ? WHERE trainer_contact_id = ?', 'si', [$now, $contactId]);
                    $s = null;
                    if ($role !== null) {
                        $s = KioskAuth::startSession($db, $device, $contactId, $role, ['ks' => $ks, 'source' => 'local', 'lang' => $lang]);
                    }
                    Ledger::append($db, array_merge($base, [
                        'type' => 'pin.ok',
                        'subject_contact_id' => $contactId,
                        'payload' => ['source' => 'local', 'purpose' => $purpose, 'scope' => 'trainer'],
                    ]));
                    if ($s !== null) {
                        foreach (KioskAuth::startEvents($s, $ua) as $e) {
                            Ledger::append($db, $e);
                        }
                    }
                    return ['result' => new PinResult('ok', source: 'local'), 'started' => $s];
                }
                // nomatch (dummyVerify never lands here - a null hash returned 'setup_needed' earlier)
                $n = (int) $row['trainer_pin_failed_count'];
                $hard = false;
                $until = null;
                if ($n >= $ks->pinHard) {
                    $hard = true;
                    Db::exec($db, 'UPDATE training_trainers SET trainer_pin_hard_locked = 1 WHERE trainer_contact_id = ?', 'i', [$contactId]);
                    Ledger::append($db, array_merge($base, ['type' => 'pin.locked', 'subject_contact_id' => $contactId, 'payload' => ['hard' => true, 'until_utc' => null, 'scope' => 'trainer']]));
                } elseif ($n % $ks->pinSoft === 0) {
                    $mult = 2 ** max(0, min(10, intdiv($n, $ks->pinSoft) - 1));
                    $until = KTime::plus($ks->pinLockMin * $mult * 60);
                    Db::exec($db, 'UPDATE training_trainers SET trainer_pin_locked_until_utc = ? WHERE trainer_contact_id = ?', 'si', [$until, $contactId]);
                    Ledger::append($db, array_merge($base, ['type' => 'pin.locked', 'subject_contact_id' => $contactId, 'payload' => ['hard' => false, 'until_utc' => $until, 'scope' => 'trainer']]));
                }
                Ledger::append($db, array_merge($base, [
                    'type' => 'pin.fail',
                    'subject_contact_id' => $contactId,
                    'payload' => ['source' => 'local', 'purpose' => $purpose, 'failed_count' => $n, 'scope' => 'trainer'],
                ]));
                if ($hard) {
                    return ['result' => new PinResult('locked_hard', source: 'local'), 'hard' => true];
                }
                if ($until !== null) {
                    return ['result' => new PinResult('locked', lockMinutes: KTime::minutesUntil($until), source: 'local')];
                }
                $left = $ks->pinSoft - ($n % $ks->pinSoft);
                return ['result' => new PinResult('wrong', triesLeft: $n >= 3 ? $left : null, source: 'local')];
            });
        } finally {
            try {
                Db::unlock($db, $lockName);
            } catch (\Throwable $e) {
                error_log('Kiosk trainer PIN unlock: ' . get_class($e));
            }
        }

        // ---- after commit ----
        /** @var PinResult $result */
        $result = $settled['result'];
        if (in_array($result->status, ['wrong', 'locked', 'locked_hard'], true)) {
            try {
                PinCaps::afterFailure($this->k, $this->k->kioskId(), $contactId);
            } catch (\Throwable $e) {
                error_log('Kiosk trainer PIN caps: ' . get_class($e));
            }
        }
        if (!empty($settled['hard'])) {
            KioskAlerts::notify($db, 'trainer_pin_hard_lock', $contactId);
            if ($role === null && $this->k->contactId() === $contactId && $this->k->ksessId() !== null) {
                KioskAuth::endSession($this->k, (int) $this->k->ksessId(), 'pin_locked');
            }
        }
        if ($result->ok()) {
            $started = $settled['started'] ?? null;
        }
        return $result;
    }
}
