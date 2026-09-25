<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Core\Scratch;
use ITFlow\Training\Kiosk\Core\KioskKeys;
use ITFlow\Training\Kiosk\Core\KioskSettings;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * Agent-side PIN administration (P3 spec §3.2, §4.3), run with the signed-in agent's Ctx.
 * Authorization (kiosk level, per-target scope, the trainer rule) is the Actions layer's job;
 * audit and logAction run there too, after commit. Ledger actor: 'user'.
 *
 * Setup slips [M]: an 8-digit code per person, hashed BEFORE the transaction; per person one
 * transaction (credential FOR UPDATE): the current local PIN moves to prev_pin_hash (so the new
 * PIN can't repeat it) and is cleared, the code hash/expiry/issuer are stored, the reset notice is
 * raised, and any lock is cleared (a slip is an admin reset - a locked person could not use it
 * otherwise). An Odoo-source person needs $switchToLocal (source local + pinned: the nightly sync
 * never flips them back). The batch [{cid,name,dept,code,expires_on}] is then sealed with
 * sodium_crypto_secretbox under KioskKeys::slipKey() and kept in Core\Scratch for 10 minutes
 * (the ONE place a code is stored anywhere, §0.6); the plaintext is wiped.
 */
final class PinAdmin
{
    public const SLIP_TTL_S = 600;
    public const MAX_SLIPS = 96;
    public const SCRATCH_KIND = 'slips';

    private readonly PinHasher $hasher;

    public function __construct(private readonly Ctx $c, private readonly KioskKeys $keys)
    {
        $this->hasher = new PinHasher($keys);
    }

    /**
     * @param list<int> $contactIds already scope-checked by the caller
     * @return string the Scratch token for agent/training_pin_slips.php?t=
     */
    public function issueSlips(array $contactIds, bool $switchToLocal): string
    {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn(int $i) => $i > 0)));
        if ($contactIds === []) {
            throw ApiException::validation(['contact_ids' => 'Pick at least one person.']);
        }
        if (count($contactIds) > self::MAX_SLIPS) {
            throw ApiException::validation(['contact_ids' => 'At most ' . self::MAX_SLIPS . ' slips at a time.']);
        }
        $db = $this->c->db;
        $ks = KioskSettings::fromDb($db);
        $people = [];
        $odooPeople = [];
        foreach ($contactIds as $cid) {
            $p = Db::one($db, 'SELECT c.contact_id, c.contact_name, c.contact_archived_at, cl.client_name FROM contacts c
                LEFT JOIN clients cl ON cl.client_id = c.contact_client_id WHERE c.contact_id = ?', 'i', [$cid]);
            if ($p === null || $p['contact_archived_at'] !== null) {
                throw ApiException::notFound('That person was not found.');
            }
            $cred = CredentialRepo::load($db, $cid);
            if ($cred !== null && $cred['tcred_source'] === 'odoo' && (int) $cred['tcred_source_pinned'] === 0 && !$switchToLocal
                && $ks->odooPinEnabled && !Seam::isActiveTrainer($db, $cid)) {
                $odooPeople[] = ['id' => $cid, 'name' => (string) $p['contact_name']];
            }
            $people[$cid] = ['name' => trim((string) $p['contact_name']), 'dept' => trim((string) ($p['client_name'] ?? ''))];
        }
        if ($odooPeople !== []) {
            throw new ApiException(422, 'odoo_source', count($odooPeople) === 1
                ? $odooPeople[0]['name'] . ' signs in with their Odoo PIN. Tick "Switch to a training PIN" to issue a slip.'
                : count($odooPeople) . ' of these people sign in with their Odoo PIN. Tick "Switch to a training PIN" to issue slips.',
                [], ['odoo_contacts' => $odooPeople]);
        }

        $label = $this->userName();
        $expiresUtc = KTime::plus($ks->setupCodeDays * 86400);
        $expiresOn = Clock::localDate($expiresUtc);
        $base = $this->eventBase();
        $batch = [];
        foreach ($people as $cid => $p) {
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $hash = $this->hasher->hashSetup($cid, $code);
            Db::tx($db, function () use ($db, $cid, $hash, $expiresUtc, $expiresOn, $label, $base, $switchToLocal): void {
                $created = Db::exec($db, "INSERT IGNORE INTO training_learner_credentials (tcred_contact_id, tcred_source) VALUES (?, 'local')", 'i', [$cid]) > 0;
                $row = CredentialRepo::load($db, $cid, true);
                if ($row === null) {
                    throw new \RuntimeException('PinAdmin: credential row missing');
                }
                if ($created) {
                    Ledger::append($db, array_merge($base, ['type' => 'pin.source_changed', 'subject_contact_id' => $cid,
                        'payload' => ['from' => null, 'to' => 'local', 'odoo_employee_id' => null, 'cleared_local' => false, 'pinned' => false]]));
                }
                $switch = $row['tcred_source'] === 'odoo';
                $wasLocked = (int) $row['tcred_hard_locked'] === 1 || KTime::isFuture($row['tcred_locked_until_utc']) || (int) $row['tcred_failed_count'] > 0;
                $now = KTime::now();
                Db::exec($db, "UPDATE training_learner_credentials SET tcred_source = 'local', tcred_source_pinned = IF(?, 1, tcred_source_pinned),
                        tcred_prev_pin_hash = COALESCE(tcred_pin_hash, tcred_prev_pin_hash), tcred_pin_hash = NULL,
                        tcred_setup_code_hash = ?, tcred_setup_code_expires_at_utc = ?, tcred_setup_code_issued_by = ?, tcred_setup_code_issued_at_utc = ?,
                        tcred_setup_token_hash = NULL, tcred_setup_token_expires_at_utc = NULL,
                        tcred_failed_count = 0, tcred_locked_until_utc = NULL, tcred_hard_locked = 0,
                        tcred_reset_notice = 1, tcred_reset_notice_at_utc = ?, tcred_reset_by_label = ?
                    WHERE tcred_contact_id = ?", 'isssissi',
                    [$switch ? 1 : 0, $hash, $expiresUtc, $this->c->userId, $now, $now, $label, $cid]);
                if ($switch) {
                    Ledger::append($db, array_merge($base, ['type' => 'pin.source_changed', 'subject_contact_id' => $cid,
                        'payload' => ['from' => 'odoo', 'to' => 'local', 'odoo_employee_id' => $row['tcred_odoo_employee_id'] === null ? null : (int) $row['tcred_odoo_employee_id'],
                                      'cleared_local' => false, 'pinned' => true]]));
                }
                if ($wasLocked) {
                    Ledger::append($db, array_merge($base, ['type' => 'pin.unlocked', 'subject_contact_id' => $cid, 'payload' => ['reason' => 'Setup slip issued']]));
                }
                Ledger::append($db, array_merge($base, ['type' => 'pin.setup_code_issued', 'subject_contact_id' => $cid, 'payload' => ['expires_on' => $expiresOn]]));
            });
            $batch[] = ['cid' => $cid, 'name' => $p['name'], 'dept' => $p['dept'], 'code' => $code, 'expires_on' => $expiresOn];
            unset($code);
        }

        $json = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach ($batch as $i => $b) {
            $batch[$i]['code'] = '';
        }
        unset($batch);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = $this->keys->slipKey();
        $box = sodium_crypto_secretbox($json, $nonce, $key);
        sodium_memzero($json);
        sodium_memzero($key);
        return Scratch::put(self::SCRATCH_KIND, $this->c->userId, ['n' => base64_encode($nonce), 'c' => base64_encode($box)], self::SLIP_TTL_S);
    }

    /**
     * The decrypted slip batch for the issuing user (Scratch::get, NOT take: a reload within the TTL
     * reprints the same slips), or null when missing, expired, another user's or undecryptable.
     *
     * @return list<array{cid:int,name:string,dept:string,code:string,expires_on:string}>|null
     */
    public static function openSlips(Ctx $c, KioskKeys $keys, string $token): ?array
    {
        $rec = Scratch::get(self::SCRATCH_KIND, $token, $c->userId);
        if ($rec === null || !is_string($rec['n'] ?? null) || !is_string($rec['c'] ?? null)) {
            return null;
        }
        $nonce = base64_decode($rec['n'], true);
        $box = base64_decode($rec['c'], true);
        if ($nonce === false || $box === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $key = $keys->slipKey();
        $plain = sodium_crypto_secretbox_open($box, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) {
            return null;
        }
        $list = json_decode($plain, true);
        sodium_memzero($plain);
        return is_array($list) && array_is_list($list) ? $list : null;
    }

    /** "Done - clear these slips": the single take(). */
    public static function clearSlips(Ctx $c, string $token): bool
    {
        return Scratch::take(self::SCRATCH_KIND, $token, $c->userId) !== null;
    }

    public function unlock(int $contactId, string $reason): bool
    {
        $db = $this->c->db;
        $base = $this->eventBase();
        return Db::tx($db, static function () use ($db, $contactId, $reason, $base): bool {
            $row = CredentialRepo::load($db, $contactId, true);
            if ($row === null) {
                return false;
            }
            Db::exec($db, 'UPDATE training_learner_credentials SET tcred_failed_count = 0, tcred_locked_until_utc = NULL, tcred_hard_locked = 0 WHERE tcred_contact_id = ?',
                'i', [$contactId]);
            Ledger::append($db, array_merge($base, ['type' => 'pin.unlocked', 'subject_contact_id' => $contactId, 'payload' => ['reason' => $reason]]));
            return true;
        });
    }

    /**
     * [S★] Confirms a re-pointed Odoo link: the current integration and link become the new
     * baseline, the fingerprint is dropped, the block is lifted. Refuses (422 link_not_ok) while
     * P2's link check still says repointed/mismatch/missing, or there is no link at all.
     *
     * @return array{old_integration_id:?int,new_integration_id:int,old_employee_id:?int,new_employee_id:int}
     */
    public function unblockOdoo(int $contactId, string $reason): array
    {
        $db = $this->c->db;
        $state = Seam::odooLinkState($this->c, $contactId);
        if (in_array($state, CredentialRepo::BAD_LINK_STATES, true)) {
            throw new ApiException(422, 'link_not_ok', "This person's Odoo link still needs fixing. Run \"Check employee links\" first.");
        }
        $cur = OdooIntegration::current($db);
        $emp = $cur === null ? null : OdooIntegration::linkedEmployee($db, $contactId, (int) $cur['odoo_integration_id']);
        if ($cur === null || $emp === null) {
            throw new ApiException(422, 'link_not_ok', 'This person has no Odoo link on the current integration.');
        }
        $newInt = (int) $cur['odoo_integration_id'];
        $base = $this->eventBase();
        return Db::tx($db, static function () use ($db, $contactId, $reason, $newInt, $emp, $base): array {
            $row = CredentialRepo::load($db, $contactId, true);
            if ($row === null) {
                throw ApiException::notFound('That person has no training PIN record yet.');
            }
            $old = ['old_integration_id' => $row['tcred_odoo_integration_id'] === null ? null : (int) $row['tcred_odoo_integration_id'],
                    'new_integration_id' => $newInt,
                    'old_employee_id' => $row['tcred_odoo_employee_id'] === null ? null : (int) $row['tcred_odoo_employee_id'],
                    'new_employee_id' => $emp];
            Db::exec($db, 'UPDATE training_learner_credentials SET tcred_odoo_integration_id = ?, tcred_odoo_employee_id = ?, tcred_odoo_fp_hash = NULL,
                    tcred_odoo_blocked = 0 WHERE tcred_contact_id = ?', 'iii', [$newInt, $emp, $contactId]);
            Ledger::append($db, array_merge($base, ['type' => 'pin.odoo_unblocked', 'subject_contact_id' => $contactId, 'payload' => ['reason' => $reason] + $old]));
            return $old;
        });
    }

    /** [S] Unblocks every blocked person whose P2 link state is 'ok'. @return array{unblocked:int, skipped:int} */
    public function bulkUnblockOdoo(string $reason): array
    {
        $n = 0;
        $skipped = 0;
        foreach (Db::all($this->c->db, 'SELECT tcred_contact_id FROM training_learner_credentials WHERE tcred_odoo_blocked = 1 ORDER BY tcred_contact_id') as $r) {
            $cid = (int) $r['tcred_contact_id'];
            if (Seam::odooLinkState($this->c, $cid) !== 'ok') {
                $skipped++;
                continue;
            }
            try {
                $this->unblockOdoo($cid, $reason);
                $n++;
            } catch (ApiException) {
                $skipped++;
            }
        }
        return ['unblocked' => $n, 'skipped' => $skipped];
    }

    /** [S★] "Refresh PIN sources" = PinSourceSync::run as this user. */
    public function refreshSources(): array
    {
        $db = $this->c->db;
        return PinSourceSync::run($db, new OdooPinVerifier($db, KioskSettings::fromDb($db)), $this->eventBase());
    }

    /** [M] Ends a kiosk cooldown now. False when there was none. */
    public function clearCooldown(int $kioskId, string $reason): bool
    {
        $db = $this->c->db;
        $base = $this->eventBase();
        return Db::tx($db, static function () use ($db, $kioskId, $reason, $base): bool {
            $row = Db::one($db, 'SELECT kiosk_id, kiosk_cooldown_until_utc FROM training_kiosks WHERE kiosk_id = ? FOR UPDATE', 'i', [$kioskId]);
            if ($row === null) {
                throw ApiException::notFound('That device was not found.');
            }
            if ($row['kiosk_cooldown_until_utc'] === null) {
                return false;
            }
            Db::exec($db, 'UPDATE training_kiosks SET kiosk_cooldown_until_utc = NULL, kiosk_cooldown_reason = NULL WHERE kiosk_id = ?', 'i', [$kioskId]);
            Ledger::append($db, array_merge($base, ['type' => 'kiosk.cooldown_cleared', 'kiosk_id' => $kioskId, 'entity_type' => 'kiosk', 'entity_id' => $kioskId,
                'payload' => ['reason' => $reason]]));
            return true;
        });
    }

    /** [M] Ends the system-wide sign-in pause now. False when there was none. */
    public function clearPause(string $reason): bool
    {
        $db = $this->c->db;
        $base = $this->eventBase();
        return Db::tx($db, static function () use ($db, $reason, $base): bool {
            $s = Db::one($db, 'SELECT config_training_pin_pause_until_utc AS p FROM settings WHERE company_id = 1 FOR UPDATE');
            if (($s['p'] ?? null) === null) {
                return false;
            }
            Db::exec($db, 'UPDATE settings SET config_training_pin_pause_until_utc = NULL WHERE company_id = 1');
            Ledger::append($db, array_merge($base, ['type' => 'pin.pause_cleared', 'payload' => ['reason' => $reason]]));
            return true;
        });
    }

    private function eventBase(): array
    {
        return ['actor_type' => 'user', 'actor_user_id' => $this->c->userId, 'user_agent' => $this->c->userAgent];
    }

    private function userName(): string
    {
        $r = Db::one($this->c->db, 'SELECT user_name FROM users WHERE user_id = ?', 'i', [$this->c->userId]);
        $n = trim((string) ($r['user_name'] ?? ''));
        return mb_substr($n === '' ? 'An administrator' : $n, 0, 200, 'UTF-8');
    }
}
