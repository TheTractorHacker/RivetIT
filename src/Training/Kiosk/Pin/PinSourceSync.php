<?php

namespace ITFlow\Training\Kiosk\Pin;

use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;
use ITFlow\Training\Kiosk\Core\KTime;

/**
 * [S★] Re-decides every credential's PIN source from ONE ids-only Odoo read (plan A1 "One PIN
 * source per person"; P3 spec §3.2, I-6). Run nightly by cron/training_kiosk_cron.php and by the
 * agent's "Refresh PIN sources".
 *
 *   - a no-op while config_training_odoo_pin_enabled is off (and when there is no integration);
 *   - never touches a tcred_source_pinned row;
 *   - (2.6.104) a person's LEARNER credential is decided purely by their own Odoo employee
 *     link/usable-PIN status now, independent of whether they are also an active trainer - a
 *     trainer's kiosk sign-in uses a completely separate credential (training_trainers'
 *     trainer_pin_* columns, Pin\TrainerPinService), which has no Odoo counterpart to sync from
 *     and is always local by its own construction, so it needs no special case here at all;
 *   - local -> odoo when the contact's current link has a usable Odoo PIN: the local hash is
 *     cleared (kept as prev_pin_hash, so a later slip can't reuse it) and any pending setup code dies;
 *   - odoo -> local when it doesn't: the local hash stays null, so the person needs a slip
 *     (listed in needs_slip).
 * Existing rows only; people without a row are decided on their first sign-in (CredentialRepo).
 * Odoo not answering throws a RuntimeException with a fixed message (no connector text).
 *
 * @return array{odoo:int, local:int, changed:int, needs_slip:list<int>, skipped?:string}
 */
final class PinSourceSync
{
    public static function run(\mysqli $db, OdooPinVerifier $v, array $actor): array
    {
        $out = ['odoo' => 0, 'local' => 0, 'changed' => 0, 'needs_slip' => []];
        $switch = Db::one($db, 'SELECT config_training_odoo_pin_enabled AS s FROM settings WHERE company_id = 1');
        if ((int) ($switch['s'] ?? 0) !== 1) {
            return $out + ['skipped' => 'off'];
        }
        $cur = OdooIntegration::current($db);
        if ($cur === null) {
            return $out + ['skipped' => 'no_integration'];
        }
        $intId = (int) $cur['odoo_integration_id'];
        $ids = $v->idsWithUsablePin();
        if ($ids === null) {
            throw new \RuntimeException('Odoo did not answer the PIN source check.');
        }
        $usable = array_fill_keys($ids, true);
        $links = [];
        foreach (Db::all($db, 'SELECT contact_id, odoo_employee_id FROM contact_odoo_links WHERE odoo_integration_id = ?', 'i', [$intId]) as $l) {
            $links[(int) $l['contact_id']] = (int) $l['odoo_employee_id'];
        }
        $actor = array_intersect_key($actor, array_flip(['actor_type', 'actor_user_id', 'actor_contact_id', 'kiosk_id', 'ksess_id', 'user_agent']));

        $after = 0;
        do {
            $rows = Db::all($db, 'SELECT tcred_contact_id, tcred_source, tcred_source_pinned, tcred_pin_hash FROM training_learner_credentials
                WHERE tcred_contact_id > ? ORDER BY tcred_contact_id LIMIT 500', 'i', [$after]);
            foreach ($rows as $r) {
                $cid = (int) $r['tcred_contact_id'];
                $after = $cid;
                $source = (string) $r['tcred_source'];
                if ((int) $r['tcred_source_pinned'] === 1) {
                    $out[$source === 'odoo' ? 'odoo' : 'local']++;
                    if ($source === 'local' && $r['tcred_pin_hash'] === null) {
                        $out['needs_slip'][] = $cid;
                    }
                    continue;
                }
                $emp = $links[$cid] ?? null;
                $want = ($emp !== null && isset($usable[$emp])) ? 'odoo' : 'local';
                if ($want !== $source && self::flip($db, $cid, $source, $want, $want === 'odoo' ? $intId : null, $emp, $actor)) {
                    $out['changed']++;
                    $source = $want;
                }
                $out[$source]++;
                if ($source === 'local' && $r['tcred_pin_hash'] === null) {
                    $out['needs_slip'][] = $cid;   // odoo -> local keeps the (null) local hash
                }
            }
        } while (count($rows) === 500);

        Db::exec($db, 'UPDATE settings SET config_training_pin_sources_synced_at_utc = ? WHERE company_id = 1', 's', [KTime::now()]);
        return $out;
    }

    private static function flip(\mysqli $db, int $cid, string $from, string $to, ?int $intId, ?int $emp, array $actor): bool
    {
        return Db::tx($db, static function () use ($db, $cid, $from, $to, $intId, $emp, $actor): bool {
            $row = CredentialRepo::load($db, $cid, true);
            if ($row === null || (string) $row['tcred_source'] !== $from || (int) $row['tcred_source_pinned'] === 1) {
                return false;
            }
            $hadLocal = $row['tcred_pin_hash'] !== null;
            if ($to === 'odoo') {
                Db::exec($db, "UPDATE training_learner_credentials SET tcred_source = 'odoo', tcred_odoo_integration_id = ?, tcred_odoo_employee_id = ?,
                        tcred_odoo_blocked = 0, tcred_odoo_fp_hash = NULL, tcred_prev_pin_hash = COALESCE(tcred_pin_hash, tcred_prev_pin_hash), tcred_pin_hash = NULL,
                        tcred_setup_code_hash = NULL, tcred_setup_code_expires_at_utc = NULL, tcred_setup_token_hash = NULL, tcred_setup_token_expires_at_utc = NULL
                    WHERE tcred_contact_id = ?", 'iii', [$intId, $emp, $cid]);
            } else {
                Db::exec($db, "UPDATE training_learner_credentials SET tcred_source = 'local' WHERE tcred_contact_id = ?", 'i', [$cid]);
            }
            Ledger::append($db, array_merge($actor, [
                'type' => 'pin.source_changed',
                'subject_contact_id' => $cid,
                'payload' => ['from' => $from, 'to' => $to, 'odoo_employee_id' => $emp, 'cleared_local' => $to === 'odoo' && $hadLocal, 'pinned' => false],
            ]));
            return true;
        });
    }
}
