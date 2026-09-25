<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KioskAlerts;
use ITFlow\Training\Kiosk\Core\KioskCtx;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * training_learner_credentials: one row per person, never deleted (P3 spec §2.1, §3.2).
 *
 * forPick() loads the row, or lazily creates it, and decides the EFFECTIVE PIN source:
 *   1. an active trainer or evaluator is always LOCAL (plan A1: trainers keep working when Odoo
 *      is down, and Odoo HR can't impersonate a trainer); a stored odoo row switches to local and
 *      its (null) local hash means "setup needed";
 *   2. the Odoo-PIN switch OFF, or tcred_source_pinned => LOCAL (the stored row is kept; while the
 *      switch is off an odoo-source row is simply treated as local, so nobody is ever sent to a
 *      disabled Odoo check - "with the switch off, pick is always local/setup_needed");
 *   3. [S★] a NEW row, switch on: a linked employee on the current integration with a usable
 *      Odoo PIN => odoo; no link or no usable PIN => local; Odoo unknown => ['unavailable'=>true]
 *      and nothing is created. Existing rows keep their stored source: PinSourceSync (nightly and
 *      "Refresh PIN sources") re-decides them with one ids-only read.
 * There is never a fallback from odoo to local at sign-in time (A1).
 *
 * repointBlocked() is the A1/A22 re-point block [S★].
 */
final class CredentialRepo
{
    public const COLUMNS = 'tcred_contact_id, tcred_source, tcred_source_pinned, tcred_odoo_integration_id, tcred_odoo_employee_id,
        tcred_odoo_confirmed_at_utc, tcred_odoo_blocked, tcred_odoo_fp_hash, tcred_odoo_fp_changed_at_utc, tcred_pin_hash, tcred_prev_pin_hash,
        tcred_pepper_version, tcred_set_method, tcred_set_at_utc, tcred_setup_code_hash, tcred_setup_code_expires_at_utc,
        tcred_setup_code_issued_at_utc, tcred_setup_token_hash, tcred_setup_token_expires_at_utc, tcred_failed_count, tcred_locked_until_utc,
        tcred_hard_locked, tcred_last_success_at_utc, tcred_reset_notice, tcred_reset_notice_at_utc, tcred_reset_by_label';

    public const BAD_LINK_STATES = ['repointed', 'mismatch', 'missing'];

    public function __construct(private readonly KioskCtx $k, private readonly ?OdooPinVerifier $odoo = null)
    {
    }

    public static function load(\mysqli $db, int $contactId, bool $forUpdate = false): ?array
    {
        return Db::one($db, 'SELECT ' . self::COLUMNS . ' FROM training_learner_credentials WHERE tcred_contact_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), 'i', [$contactId]);
    }

    /**
     * The credential row plus 'effective_source' ('odoo'|'local') and 'is_trainer', or
     * ['unavailable' => true] when a new person's source can't be decided because Odoo did not answer.
     */
    public function forPick(int $contactId): array
    {
        $db = $this->k->db();
        $isTrainer = Seam::isActiveTrainer($db, $contactId);
        $row = self::load($db, $contactId);

        if ($row === null) {
            $source = 'local';
            $empId = null;
            $integrationId = null;
            if (!$isTrainer && $this->k->ks->odooPinEnabled) {
                $cur = OdooIntegration::current($db);
                $emp = $cur === null ? null : OdooIntegration::linkedEmployee($db, $contactId, (int) $cur['odoo_integration_id']);
                if ($emp !== null) {
                    $usable = ($this->odoo ?? new OdooPinVerifier($db, $this->k->ks))->hasUsablePin($emp);
                    if ($usable === null) {
                        return ['unavailable' => true];
                    }
                    if ($usable) {
                        $source = 'odoo';
                        $empId = $emp;
                        $integrationId = (int) $cur['odoo_integration_id'];
                    }
                }
            }
            $row = $this->create($contactId, $source, $integrationId, $empId);
        } elseif ($isTrainer && $row['tcred_source'] === 'odoo') {
            $row = $this->switchToLocal($contactId, 'trainer');
        }
        return self::decorate($row, $this->k->ks->odooPinEnabled, $isTrainer);
    }

    /** Adds effective_source / is_trainer / has_pin to a raw row. */
    public static function decorate(array $row, bool $odooOn, bool $isTrainer): array
    {
        $stored = (string) $row['tcred_source'];
        $row['effective_source'] = ($stored === 'odoo' && $odooOn && !$isTrainer && (int) $row['tcred_source_pinned'] === 0) ? 'odoo' : 'local';
        $row['is_trainer'] = $isTrainer;
        $row['has_pin'] = $row['tcred_pin_hash'] !== null && $row['tcred_pin_hash'] !== '';
        return $row;
    }

    /**
     * [S★] Odoo source only. Blocked when the current integration or the contact's link differs
     * from the confirmed baseline, or P2's link check says repointed/mismatch/missing. Blocking is
     * a transaction (row FOR UPDATE, tcred_odoo_blocked = 1, ledger pin.odoo_blocked) and an
     * 'odoo_repoint' alert after commit. True when blocked (also when it already was).
     */
    public function repointBlocked(array $cred, int $contactId): bool
    {
        if (($cred['effective_source'] ?? $cred['tcred_source']) !== 'odoo') {
            return false;
        }
        if ((int) $cred['tcred_odoo_blocked'] === 1) {
            return true;
        }
        $db = $this->k->db();
        $cur = OdooIntegration::current($db);
        if ($cur === null) {
            return false;   // no integration: the verifier answers 'disabled' (sign-in unavailable), not a re-point
        }
        $newInt = (int) $cur['odoo_integration_id'];
        $newEmp = OdooIntegration::linkedEmployee($db, $contactId, $newInt);
        $oldInt = $cred['tcred_odoo_integration_id'] === null ? null : (int) $cred['tcred_odoo_integration_id'];
        $oldEmp = $cred['tcred_odoo_employee_id'] === null ? null : (int) $cred['tcred_odoo_employee_id'];
        $state = Seam::odooLinkState($this->k->core, $contactId);
        if ($newInt === $oldInt && $newEmp !== null && $newEmp === $oldEmp && !in_array($state, self::BAD_LINK_STATES, true)) {
            return false;
        }
        $base = $this->k->eventBase();
        $blockedNow = Db::tx($db, static function () use ($db, $contactId, $oldInt, $newInt, $oldEmp, $newEmp, $base): bool {
            $row = self::load($db, $contactId, true);
            if ($row === null || (int) $row['tcred_odoo_blocked'] === 1) {
                return false;
            }
            Db::exec($db, 'UPDATE training_learner_credentials SET tcred_odoo_blocked = 1 WHERE tcred_contact_id = ?', 'i', [$contactId]);
            Ledger::append($db, array_merge($base, [
                'type' => 'pin.odoo_blocked',
                'subject_contact_id' => $contactId,
                'payload' => ['old_integration_id' => $oldInt, 'new_integration_id' => $newInt, 'old_employee_id' => $oldEmp, 'new_employee_id' => $newEmp],
            ]));
            return true;
        });
        if ($blockedNow) {
            KioskAlerts::notify($db, 'odoo_repoint', $contactId);
        }
        return true;
    }

    private function create(int $contactId, string $source, ?int $integrationId, ?int $empId): array
    {
        $db = $this->k->db();
        $base = $this->k->eventBase();
        $now = KTime::now();
        try {
            Db::tx($db, static function () use ($db, $contactId, $source, $integrationId, $empId, $base, $now): void {
                Db::exec($db, 'INSERT INTO training_learner_credentials (tcred_contact_id, tcred_source, tcred_odoo_integration_id, tcred_odoo_employee_id,
                        tcred_odoo_confirmed_at_utc) VALUES (?, ?, ?, ?, ?)',
                    'isiis', [$contactId, $source, $integrationId, $empId, $source === 'odoo' ? $now : null]);
                Ledger::append($db, array_merge($base, [
                    'type' => 'pin.source_changed',
                    'subject_contact_id' => $contactId,
                    'payload' => ['from' => null, 'to' => $source, 'odoo_employee_id' => $empId, 'cleared_local' => false, 'pinned' => false],
                ]));
            });
        } catch (\mysqli_sql_exception $e) {
            if ((int) $e->getCode() !== 1062) {
                throw $e;
            }
            // Created by a concurrent request: use theirs.
        }
        $row = self::load($db, $contactId);
        if ($row === null) {
            throw new \RuntimeException('CredentialRepo: credential row missing after create');
        }
        return $row;
    }

    /** A stored odoo row of a (new) trainer becomes local; its local hash stays as it is (null => setup needed). */
    private function switchToLocal(int $contactId, string $why): array
    {
        $db = $this->k->db();
        $base = $this->k->eventBase();
        Db::tx($db, static function () use ($db, $contactId, $base): void {
            $row = self::load($db, $contactId, true);
            if ($row === null || $row['tcred_source'] !== 'odoo') {
                return;
            }
            Db::exec($db, "UPDATE training_learner_credentials SET tcred_source = 'local' WHERE tcred_contact_id = ?", 'i', [$contactId]);
            Ledger::append($db, array_merge($base, [
                'type' => 'pin.source_changed',
                'subject_contact_id' => $contactId,
                'payload' => ['from' => 'odoo', 'to' => 'local', 'odoo_employee_id' => $row['tcred_odoo_employee_id'] === null ? null : (int) $row['tcred_odoo_employee_id'],
                              'cleared_local' => false, 'pinned' => false],
            ]));
        });
        return self::load($db, $contactId) ?? throw new \RuntimeException('CredentialRepo: credential row vanished');
    }
}
