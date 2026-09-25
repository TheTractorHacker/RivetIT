<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskAlerts;
use ITFlow\Training\Kiosk\Core\KioskAuth;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KioskStrings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * PIN sign-in and step-up (plan A1 "reserve, then verify"; P3 spec §3.2). Frozen for K3/K5:
 * stepUp(), pad(), TRAINER_PURPOSES.
 *
 *   0  validate BEFORE any bookkeeping: $pin is a PHP string ^[0-9]{4,12}$ (anything else =>
 *      'format', no charge, no connector call); the contact is eligible; the source comes from
 *      CredentialRepo (unknown => 'unavailable', no charge); an Odoo source that is re-point
 *      blocked => 'unavailable'/link_check; a local source with no hash => one dummy verify, then
 *      'setup_needed'
 *   1  non-blocking named lock tpin:<cid> ('busy'), released in finally
 *   2  RESERVE (short committed tx): global pause / kiosk cooldown (not for TRAINER_PURPOSES),
 *      credential FOR UPDATE (hard lock / timed lock), CHARGE failed_count+1, ledger pin.attempt
 *   3  VERIFY with no transaction and no row locks (bcrypt, or the Odoo call)
 *   4  SETTLE (tx, credential FOR UPDATE again): match => reset counters (+ Odoo confirmation and
 *      fingerprint), notices, pin.ok, and for a login the kiosk session (ksession.end/start);
 *      wrong => keep the charge, lock transitions, pin.fail; no answer => refund, pin.unavailable
 *   5  after COMMIT: caps (PinCaps) on a failure, fixed-text alerts, a hard lock on the session's
 *      own step-up ends that session, a login reconciles assignments
 * Never writes `logs` with log_type 'Login'. Never logs, stores or returns the PIN.
 *
 * Every PIN-verifying handler wraps its body in try { … } finally { PinService::pad($k->startedNs); }
 * so every path - errors included - takes at least MIN_RESPONSE_MS (the time doesn't tell which
 * source a person uses, or whether they have a PIN).
 */
final class PinService
{
    public const MIN_RESPONSE_MS = 800;
    public const TRAINER_PURPOSES = ['trainer_exit', 'trainer_finalize', 'trainer_action', 'evaluator', 'handoff_cancel'];
    public const PIN_RE = '/^[0-9]{4,12}$/D';
    public const SETUP_CODE_RE = '/^[0-9]{8}$/D';
    public const SETUP_TOKEN_TTL_S = 300;

    private readonly PinHasher $hasher;
    private readonly CredentialRepo $creds;
    private ?OdooPinVerifier $odoo;

    public function __construct(private readonly KioskCtx $k, ?OdooPinVerifier $odoo = null)
    {
        $this->hasher = new PinHasher($k->keys);
        $this->odoo = $odoo;
        $this->creds = new CredentialRepo($k, $odoo);
    }

    /**
     * Sign in on this device. $role 'learner' | 'trainer' (a trainer must be an active trainer:
     * the caller checks and answers 403 not_trainer). Returns ['result' => PinResult, 'token' => ?string];
     * the caller sets the session cookie from the token.
     */
    public function login(int $contactId, mixed $pin, string $role): array
    {
        if (!in_array($role, ['learner', 'trainer'], true)) {
            throw new \InvalidArgumentException('PinService::login: bad role');
        }
        $started = null;
        $r = $this->check($contactId, $pin, 'login', 'pin', $role, $started);
        unset($pin);
        return ['result' => $r, 'token' => $r->ok() && $started !== null ? $started['token'] : null];
    }

    /** Re-enter a PIN for an action (attest, ack, check-in, trainer actions). Never touches the ksess on success. */
    public function stepUp(int $contactId, mixed $pin, string $purpose): PinResult
    {
        if (preg_match('/^[a-z_]{3,30}$/D', $purpose) !== 1 || $purpose === 'login' || $purpose === 'setup_code') {
            throw new \InvalidArgumentException('PinService::stepUp: bad purpose');
        }
        $started = null;
        $r = $this->check($contactId, $pin, $purpose, 'pin', null, $started);
        unset($pin);
        return $r;
    }

    /** The 8-digit code from a setup slip. Same charge/settle counters as a PIN. Returns ['result', 'setup_token' => ?string]. */
    public function setupCodeVerify(int $contactId, mixed $code): array
    {
        $started = null;
        $token = null;
        $r = $this->check($contactId, $code, 'setup_code', 'setup', null, $started, $token);
        unset($code);
        return ['result' => $r, 'setup_token' => $r->ok() ? $token : null];
    }

    /**
     * New local PIN from a verified setup code (single-use token, 5 minutes), then sign in like login().
     * Throws ApiException for a bad/used token (422 validation setup_token), a mismatch (422
     * pin_mismatch) or a policy failure (422 pin_policy {rule}).
     */
    public function createFromSetup(int $contactId, string $setupToken, mixed $pin, mixed $pin2, string $role): array
    {
        if (!in_array($role, ['learner', 'trainer'], true)) {
            throw new \InvalidArgumentException('PinService::createFromSetup: bad role');
        }
        $db = $this->k->db();
        $device = $this->k->device;
        if ($device === null) {
            throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.');
        }
        if (!Seam::isEligible($db, $contactId, $this->k->core)) {
            throw ApiException::notFound('That person was not found.');
        }
        if (preg_match(KioskAuth::TOKEN_RE, $setupToken) !== 1) {
            throw new ApiException(422, 'validation', 'Start again with your setup code.', ['setup_token' => 'Expired.']);
        }
        $tokHash = hash('sha256', $setupToken);
        $cur = CredentialRepo::load($db, $contactId);
        if ($cur === null || !self::setupTokenValid($cur, $tokHash)) {
            throw new ApiException(422, 'validation', 'Start again with your setup code.', ['setup_token' => 'Expired.']);
        }
        if (!is_string($pin) || !is_string($pin2)) {
            throw new ApiException(422, 'pin_format', 'Enter your PIN with the number keys.');
        }
        if (!hash_equals($pin, $pin2)) {
            throw new ApiException(422, 'pin_mismatch', "The two PINs don't match.");
        }
        $rule = PinPolicy::check($contactId, $pin, $this->hasher, $cur['tcred_prev_pin_hash']);
        if ($rule !== null) {
            throw new ApiException(422, 'pin_policy', 'Pick a different PIN.', [], ['rule' => $rule]);
        }
        $newHash = $this->hasher->hashPin($contactId, $pin);   // bcrypt BEFORE the transaction
        unset($pin, $pin2);

        $base = $this->k->eventBase();
        $lang = $this->langFor($contactId);
        $ks = $this->k->ks;
        $ua = $this->k->core->userAgent;
        $started = Db::tx($db, static function () use ($db, $contactId, $tokHash, $newHash, $base, $device, $role, $lang, $ks, $ua): array {
            $row = CredentialRepo::load($db, $contactId, true);
            if ($row === null || !self::setupTokenValid($row, $tokHash)) {
                throw new ApiException(422, 'validation', 'Start again with your setup code.', ['setup_token' => 'Expired.']);
            }
            $now = KTime::now();
            Db::exec($db, "UPDATE training_learner_credentials SET tcred_source = 'local', tcred_pin_hash = ?, tcred_set_method = 'setup_code', tcred_set_at_utc = ?,
                    tcred_set_by_trainer_contact_id = NULL, tcred_setup_code_hash = NULL, tcred_setup_code_expires_at_utc = NULL,
                    tcred_setup_token_hash = NULL, tcred_setup_token_expires_at_utc = NULL, tcred_failed_count = 0, tcred_locked_until_utc = NULL,
                    tcred_last_success_at_utc = ?, tcred_reset_notice = 0
                WHERE tcred_contact_id = ?", 'sssi', [$newHash, $now, $now, $contactId]);
            $s = KioskAuth::startSession($db, $device, $contactId, $role, ['ks' => $ks, 'source' => 'local', 'lang' => $lang]);
            Ledger::append($db, array_merge($base, ['type' => 'pin.set', 'subject_contact_id' => $contactId, 'payload' => ['method' => 'setup_code']]));
            Ledger::append($db, array_merge($base, ['type' => 'pin.ok', 'subject_contact_id' => $contactId,
                'payload' => ['source' => 'local', 'purpose' => 'login', 'odoo_employee_id' => null, 'notice_flags' => []]]));
            foreach (KioskAuth::startEvents($s, $ua) as $e) {
                Ledger::append($db, $e);
            }
            return $s;
        });
        Seam::reconcileContact($this->k->core, $contactId);
        return ['result' => new PinResult('ok', source: 'local'), 'token' => $started['token']];
    }

    /** At least MIN_RESPONSE_MS since $startedNs (hrtime at bootstrap). Called in every PIN handler's finally. */
    public static function pad(int $startedNs): void
    {
        $elapsedMs = (hrtime(true) - $startedNs) / 1e6;
        $left = self::MIN_RESPONSE_MS - $elapsedMs;
        if ($left > 0) {
            usleep((int) ceil($left * 1000));
        }
    }

    // ------------------------------------------------------------------------------------------

    /**
     * The shared reserve-verify-settle. $kind 'pin' (local PIN or Odoo PIN) | 'setup' (8-digit
     * setup code). $role non-null = a login (starts the kiosk session; $started receives it).
     * $setupToken receives a fresh single-use token on a successful setup-code check.
     */
    private function check(int $contactId, mixed $secret, string $purpose, string $kind, ?string $role, ?array &$started, ?string &$setupToken = null): PinResult
    {
        $db = $this->k->db();
        // ---- 0. validate before any bookkeeping ----
        $re = $kind === 'setup' ? self::SETUP_CODE_RE : self::PIN_RE;
        if (!is_string($secret) || preg_match($re, $secret) !== 1) {
            return new PinResult('format');
        }
        $contactId = intval($contactId);
        if ($contactId < 1 || !Seam::isEligible($db, $contactId, $this->k->core)) {
            throw ApiException::notFound('That person was not found.');
        }
        if ($role !== null && $this->k->device === null) {
            throw new ApiException(403, 'device_not_enrolled', 'This device is not set up for training.');
        }
        $cred = $this->creds->forPick($contactId);
        if (!empty($cred['unavailable'])) {
            return new PinResult('unavailable', reason: 'odoo');
        }
        $source = $kind === 'setup' ? 'local' : (string) $cred['effective_source'];
        $empId = $source === 'odoo' && $cred['tcred_odoo_employee_id'] !== null ? (int) $cred['tcred_odoo_employee_id'] : null;
        if ($source === 'odoo') {
            if ($this->creds->repointBlocked($cred, $contactId)) {
                return new PinResult('unavailable', reason: 'link_check', source: 'odoo', odooEmployeeId: $empId);
            }
            if ($empId === null) {
                return new PinResult('unavailable', reason: 'link_check', source: 'odoo');
            }
        } elseif ($kind === 'pin' && !$cred['has_pin']) {
            $this->hasher->dummyVerify();
            return new PinResult('setup_needed', source: 'local');
        }

        // ---- 1. named lock ----
        $lockName = 'tpin:' . $contactId;
        if (!Db::lock($db, $lockName, 0)) {
            return new PinResult('busy');
        }
        try {
            $base = $this->k->eventBase();
            $trainerPurpose = in_array($purpose, self::TRAINER_PURPOSES, true);
            $kioskId = $this->k->kioskId();

            // ---- 2. reserve ----
            $reserved = Db::tx($db, function () use ($db, $contactId, $trainerPurpose, $kioskId, $source, $purpose, $empId, $base): array {
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
                $row = CredentialRepo::load($db, $contactId, true);
                if ($row === null) {
                    throw new \RuntimeException('PinService: credential row missing');
                }
                if ((int) $row['tcred_hard_locked'] === 1) {
                    return ['stop' => new PinResult('locked_hard', source: $source)];
                }
                if (KTime::isFuture($row['tcred_locked_until_utc'])) {
                    return ['stop' => new PinResult('locked', lockMinutes: KTime::minutesUntil($row['tcred_locked_until_utc']), source: $source)];
                }
                Db::exec($db, 'UPDATE training_learner_credentials SET tcred_failed_count = tcred_failed_count + 1 WHERE tcred_contact_id = ?', 'i', [$contactId]);
                Ledger::append($db, array_merge($base, [
                    'type' => 'pin.attempt',
                    'subject_contact_id' => $contactId,
                    'payload' => ['source' => $source, 'purpose' => $purpose, 'odoo_employee_id' => $empId],
                ]));
                return ['row' => $row];
            });
            if (isset($reserved['stop'])) {
                return $reserved['stop'];
            }
            $row = $reserved['row'];

            // ---- 3. verify (no transaction, no row locks) ----
            $outcome = 'nomatch';
            $fpChanged = false;
            $newFp = null;
            $breakerOpened = false;
            if ($kind === 'setup') {
                $codeLive = $row['tcred_setup_code_hash'] !== null && KTime::isFuture($row['tcred_setup_code_expires_at_utc']);
                $ok = $this->hasher->verifySetup($contactId, $secret, $codeLive ? (string) $row['tcred_setup_code_hash'] : null);
                $outcome = $ok ? 'match' : 'nomatch';
            } elseif ($source === 'local') {
                $outcome = $this->hasher->verifyPin($contactId, $secret, $row['tcred_pin_hash']) ? 'match' : 'nomatch';
            } else {
                $v = $this->odoo ??= new OdooPinVerifier($db, $this->k->ks);
                $outcome = $v->check((int) $empId, $secret);
                $breakerOpened = $v->breakerOpened();
                if ($outcome === 'match') {
                    $fp = $row['tcred_odoo_fp_hash'];
                    $fpChanged = $fp !== null && $fp !== '' && !$this->hasher->verifyFp($contactId, $secret, (string) $fp);
                    $newFp = $this->hasher->hashFp($contactId, $secret);
                }
            }
            unset($secret);

            // ---- 4. settle ----
            $ks = $this->k->ks;
            $device = $this->k->device;
            $lang = $role !== null ? $this->langFor($contactId) : null;
            $ua = $this->k->core->userAgent;
            $tokenPlain = $kind === 'setup' && $outcome === 'match' ? KioskAuth::newToken() : null;
            $settled = Db::tx($db, function () use ($db, $contactId, $outcome, $source, $purpose, $empId, $base, $fpChanged, $newFp, $role, $device, $ks, $lang, $ua, $tokenPlain, $kind): array {
                $row = CredentialRepo::load($db, $contactId, true);
                if ($row === null) {
                    throw new \RuntimeException('PinService: credential row missing');
                }
                $now = KTime::now();
                if ($outcome === 'match') {
                    $notices = [];
                    if ((int) $row['tcred_reset_notice'] === 1 && $kind === 'pin') {
                        $notices['reset'] = ['at_utc' => $row['tcred_reset_notice_at_utc'], 'by' => $row['tcred_reset_by_label']];
                    }
                    $sql = 'UPDATE training_learner_credentials SET tcred_failed_count = 0, tcred_locked_until_utc = NULL, tcred_last_success_at_utc = ?';
                    $types = 's';
                    $params = [$now];
                    if ($kind === 'pin') {
                        $sql .= ', tcred_reset_notice = 0';
                    }
                    if ($source === 'odoo') {
                        $sql .= ', tcred_odoo_confirmed_at_utc = ?, tcred_odoo_fp_hash = ?';
                        $types .= 'ss';
                        $params[] = $now;
                        $params[] = $newFp;
                        if ($fpChanged) {
                            $sql .= ', tcred_odoo_fp_changed_at_utc = ?';
                            $types .= 's';
                            $params[] = $now;
                            $notices['fp_changed'] = ['at_utc' => $now];
                        }
                    }
                    if ($tokenPlain !== null) {
                        $sql .= ', tcred_setup_token_hash = ?, tcred_setup_token_expires_at_utc = ?';
                        $types .= 'ss';
                        $params[] = hash('sha256', $tokenPlain);
                        $params[] = KTime::plus(self::SETUP_TOKEN_TTL_S);
                    }
                    $types .= 'i';
                    $params[] = $contactId;
                    Db::exec($db, $sql . ' WHERE tcred_contact_id = ?', $types, $params);
                    $s = null;
                    if ($role !== null) {
                        $s = KioskAuth::startSession($db, $device, $contactId, $role, ['ks' => $ks, 'source' => $source, 'emp_id' => $empId, 'lang' => $lang]);
                    }
                    Ledger::append($db, array_merge($base, [
                        'type' => 'pin.ok',
                        'subject_contact_id' => $contactId,
                        'payload' => ['source' => $source, 'purpose' => $purpose, 'odoo_employee_id' => $empId, 'notice_flags' => array_keys($notices)],
                    ]));
                    if ($s !== null) {
                        foreach (KioskAuth::startEvents($s, $ua) as $e) {
                            Ledger::append($db, $e);
                        }
                    }
                    return ['result' => new PinResult('ok', notices: $notices, source: $source, odooEmployeeId: $empId), 'started' => $s];
                }
                if ($outcome === 'nomatch') {
                    $n = (int) $row['tcred_failed_count'];
                    $hard = false;
                    $until = null;
                    if ($n >= $ks->pinHard) {
                        $hard = true;
                        Db::exec($db, 'UPDATE training_learner_credentials SET tcred_hard_locked = 1 WHERE tcred_contact_id = ?', 'i', [$contactId]);
                        Ledger::append($db, array_merge($base, ['type' => 'pin.locked', 'subject_contact_id' => $contactId, 'payload' => ['hard' => true, 'until_utc' => null]]));
                    } elseif ($n % $ks->pinSoft === 0) {
                        $mult = 2 ** max(0, min(10, intdiv($n, $ks->pinSoft) - 1));
                        $until = KTime::plus($ks->pinLockMin * $mult * 60);
                        Db::exec($db, 'UPDATE training_learner_credentials SET tcred_locked_until_utc = ? WHERE tcred_contact_id = ?', 'si', [$until, $contactId]);
                        Ledger::append($db, array_merge($base, ['type' => 'pin.locked', 'subject_contact_id' => $contactId, 'payload' => ['hard' => false, 'until_utc' => $until]]));
                    }
                    Ledger::append($db, array_merge($base, [
                        'type' => 'pin.fail',
                        'subject_contact_id' => $contactId,
                        'payload' => ['source' => $source, 'purpose' => $purpose, 'failed_count' => $n, 'odoo_employee_id' => $empId],
                    ]));
                    if ($hard) {
                        return ['result' => new PinResult('locked_hard', source: $source, odooEmployeeId: $empId), 'hard' => true];
                    }
                    if ($until !== null) {
                        return ['result' => new PinResult('locked', lockMinutes: KTime::minutesUntil($until), source: $source, odooEmployeeId: $empId)];
                    }
                    $left = $ks->pinSoft - ($n % $ks->pinSoft);
                    return ['result' => new PinResult('wrong', triesLeft: $n >= 3 ? $left : null, source: $source, odooEmployeeId: $empId)];
                }
                // unavailable / auth_error / disabled: refund the charge
                Db::exec($db, 'UPDATE training_learner_credentials SET tcred_failed_count = GREATEST(CAST(tcred_failed_count AS SIGNED) - 1, 0) WHERE tcred_contact_id = ?', 'i', [$contactId]);
                $reason = match ($outcome) { 'auth_error' => 'odoo_auth', 'disabled' => 'disabled', default => 'odoo' };
                Ledger::append($db, array_merge($base, [
                    'type' => 'pin.unavailable',
                    'subject_contact_id' => $contactId,
                    'payload' => ['source' => $source, 'reason' => $reason, 'odoo_employee_id' => $empId],
                ]));
                return ['result' => new PinResult('unavailable', reason: $reason, source: $source, odooEmployeeId: $empId)];
            });
        } finally {
            try {
                Db::unlock($db, $lockName);
            } catch (\Throwable $e) {
                error_log('Kiosk PIN unlock: ' . get_class($e));
            }
        }

        // ---- 5. after commit ----
        /** @var PinResult $result */
        $result = $settled['result'];
        if (in_array($result->status, ['wrong', 'locked', 'locked_hard'], true)) {
            try {
                PinCaps::afterFailure($this->k, $this->k->kioskId(), $contactId);
            } catch (\Throwable $e) {
                error_log('Kiosk PIN caps: ' . get_class($e));
            }
        }
        if (!empty($settled['hard'])) {
            KioskAlerts::notify($db, 'pin_hard_lock', $contactId);
            if ($role === null && $this->k->contactId() === $contactId && $this->k->ksessId() !== null) {
                KioskAuth::endSession($this->k, (int) $this->k->ksessId(), 'pin_locked');
            }
        }
        if ($outcome === 'auth_error') {
            KioskAlerts::notify($db, 'odoo_auth');
        }
        if ($breakerOpened) {
            KioskAlerts::notify($db, 'odoo_down');
        }
        if ($result->ok()) {
            $started = $settled['started'] ?? null;
            if ($tokenPlain !== null) {
                $setupToken = $tokenPlain;
            }
            if ($role !== null) {
                Seam::reconcileContact($this->k->core, $contactId);
            }
        }
        return $result;
    }

    /**
     * [S] I-8: the sign-in notices of a kiosk session, for the next page's server data (the
     * Learning Center shows them once): the notice_flags of the pin.ok event that opened $ksessId,
     * with their dates. [['type' => 'reset', 'at_utc' => …, 'by' => …] | ['type' => 'fp_changed', 'at_utc' => …]]
     */
    public static function noticesForKsess(\mysqli $db, int $ksessId, int $contactId): array
    {
        $start = Db::one($db, "SELECT tevent_seq FROM training_events WHERE tevent_type = 'ksession.start' AND tevent_entity_type = 'ksess' AND tevent_entity_id = ?
            ORDER BY tevent_seq DESC LIMIT 1", 'i', [$ksessId]);
        if ($start === null) {
            return [];
        }
        $ok = Db::one($db, "SELECT tevent_payload_json FROM training_events WHERE tevent_type = 'pin.ok' AND tevent_subject_contact_id = ? AND tevent_seq < ? AND tevent_seq >= ?
            ORDER BY tevent_seq DESC LIMIT 1", 'iii', [$contactId, (int) $start['tevent_seq'], max(0, (int) $start['tevent_seq'] - 3)]);
        $flags = $ok === null ? [] : (array) ((json_decode((string) $ok['tevent_payload_json'], true)['notice_flags'] ?? []));
        if ($flags === []) {
            return [];
        }
        $cred = CredentialRepo::load($db, $contactId);
        $out = [];
        if (in_array('reset', $flags, true) && $cred !== null) {
            $out[] = ['type' => 'reset', 'at_utc' => $cred['tcred_reset_notice_at_utc'], 'by' => $cred['tcred_reset_by_label']];
        }
        if (in_array('fp_changed', $flags, true) && $cred !== null) {
            $out[] = ['type' => 'fp_changed', 'at_utc' => $cred['tcred_odoo_fp_changed_at_utc']];
        }
        return $out;
    }

    private static function setupTokenValid(array $row, string $tokHash): bool
    {
        return is_string($row['tcred_setup_token_hash']) && hash_equals($row['tcred_setup_token_hash'], $tokHash)
            && KTime::isFuture($row['tcred_setup_token_expires_at_utc']);
    }

    /** The person's saved language (training_learner_prefs, plan A3), else the device's current UI language. */
    private function langFor(int $contactId): string
    {
        $r = Db::one($this->k->db(), 'SELECT tpref_language FROM training_learner_prefs WHERE tpref_contact_id = ?', 'i', [$contactId]);
        return KioskStrings::lang($r['tpref_language'] ?? $this->k->lang);
    }
}
