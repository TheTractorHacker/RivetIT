<?php

namespace ITFlow\Training\Directory;

use ITFlow\Audit\AuditService;
use ITFlow\Integrations\Odoo\OdooClient;
use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Clock;
use ITFlow\Training\Core\Db;

/**
 * "Check now" and the per-link resolution actions (M15, S6; Phase 2 spec §3.4). Admin-only at the
 * edge (admin/post/settings_training_compliance.php, via admin/post/settings_training.php); every change is audited
 * (training.odoo_link_changed / training.odoo_target_accepted).
 *
 * Frozen transitions: repointed -> ok only by confirm or relink; relink only to the checked
 * suggestion and never to an employee linked to another contact; unlink only in missing/mismatch.
 * After a relink or confirm the target is accepted automatically when nothing is flagged any more
 * (missing links that still exist count as flagged) and every ok link was checked against the current target.
 * Check now accepts a changed target only when every link checked out ok (none missing): a different
 * database shows up as all-missing, and accepting it would duplicate contacts on the next sync.
 */
final class OdooLinkChecker
{
    private array $row;

    /**
     * @param array|null $integrationRow the odoo_integrations row; null = the latest one (same selection as the sync)
     * @throws ApiException 404 not_found when no Odoo integration is configured (the admin page catches it and shows the
     *         "not configured" state). Callers: run() for "Check now", status() for the page, relink/unlink/confirm for S6.
     */
    public function __construct(private readonly \mysqli $db, ?array $integrationRow = null)
    {
        $row = $integrationRow ?? self::latestIntegration($db);
        if ($row === null) {
            throw new ApiException(404, 'not_found', 'No Odoo integration is configured.');
        }
        $this->row = $row;
    }

    /** The same row the admin sync handler picks (latest id); an explicit column list, no SELECT * under src/Training. */
    public static function latestIntegration(\mysqli $db): ?array
    {
        $res = $db->query('SELECT odoo_integration_id, base_url, database_name, username, api_key_enc, api_protocol, enabled,
            last_test_at, last_test_success, last_test_error, last_sync_at, created_at, updated_at
            FROM odoo_integrations ORDER BY odoo_integration_id DESC LIMIT 1');
        $row = $res->fetch_assoc();
        $res->free();
        return $row ?: null;
    }

    /**
     * Reads the Odoo employee list (network, outside any transaction, under `trodoo`), applies the
     * link rules, stamps config_training_odoo_link_checked_at_utc and accepts a changed target
     * automatically only when at least one link is ok and none is missing, mismatched or re-pointed.
     *
     * @return array{checked:int, ok:int, mismatch:int, missing:int, repointed:int, new:int, accepted_now:bool,
     *               target:array{current:string, accepted:?string, pending:bool}, rows:list<array>}
     */
    public function run(OdooClient $client, array $integrationRow, int $userId): array
    {
        if (Db::depth() !== 0) {
            throw new \LogicException('OdooLinkChecker::run must not run inside a transaction');
        }
        $this->row = $integrationRow;
        if (!Db::lock($this->db, 'trodoo', 0)) {
            throw new ApiException(409, 'busy', 'Another Odoo job is running. Try again in a minute.');
        }
        try {
            $employees = $client->listEmployees();
            $iid = (int) $integrationRow['odoo_integration_id'];
            $current = OdooTarget::sha($integrationRow['base_url'] ?? null, $integrationRow['database_name'] ?? null);
            $st = LinkStates::apply($this->db, $iid, $current, $employees);
            Db::exec($this->db, 'UPDATE settings SET config_training_odoo_link_checked_at_utc = ? WHERE company_id = 1', 's', [Clock::nowUtc()]);
            $accepted = OdooTarget::accepted($this->db);
            $acceptedNow = false;
            // A different database with other employee ids shows up as every link "missing" (not mismatched):
            // accepting it would let the next sync create a duplicate contact for every employee it cannot match
            // by email. So a changed target is accepted automatically only when every link checked out ok.
            if ($accepted !== false && $accepted !== $current && $st['mismatch'] === 0 && $st['repointed'] === 0
                && $st['missing'] === 0 && $st['ok'] > 0) {
                $this->acceptTarget($userId, $accepted, $current, 'Link check found every link ok (none missing, mismatched or re-pointed)');
                $acceptedNow = true;
            }
        } finally {
            Db::unlock($this->db, 'trodoo');
        }
        $status = $this->status();
        return [
            'checked' => $st['ok'] + $st['mismatch'] + $st['missing'] + $st['repointed'],
            'ok' => $st['ok'], 'mismatch' => $st['mismatch'], 'missing' => $st['missing'], 'repointed' => $st['repointed'], 'new' => $st['new'],
            'accepted_now' => $acceptedNow,
            'target' => $status['target'],
            'rows' => $status['rows'],
        ];
    }

    /**
     * The admin page's view without calling Odoo: target state, counts per state, last check, and one
     * row per link (flagged first).
     *
     * @return array{target:array{current:string, accepted:?string, pending:bool}, counts:array<string,int>, checked_at_utc:?string, rows:list<array>}
     */
    public function status(): array
    {
        $iid = (int) $this->row['odoo_integration_id'];
        $current = OdooTarget::sha($this->row['base_url'] ?? null, $this->row['database_name'] ?? null);
        $accepted = OdooTarget::accepted($this->db);
        $accepted = $accepted === false ? null : $accepted;
        $rows = Db::all($this->db, "SELECT l.contact_id, c.contact_name, l.odoo_employee_id, a.coattr_link_state, a.coattr_link_detail, a.coattr_link_seen_name,
                a.coattr_link_suggested_employee_id, a.coattr_odoo_name, a.coattr_link_checked_at_utc
            FROM contact_odoo_links l JOIN contacts c ON c.contact_id = l.contact_id
            LEFT JOIN contact_odoo_attributes a ON a.coattr_contact_id = l.contact_id
            WHERE l.odoo_integration_id = ?
            UNION ALL
            SELECT a.coattr_contact_id, c.contact_name, a.coattr_odoo_employee_id, a.coattr_link_state, a.coattr_link_detail, a.coattr_link_seen_name,
                a.coattr_link_suggested_employee_id, a.coattr_odoo_name, a.coattr_link_checked_at_utc
            FROM contact_odoo_attributes a JOIN contacts c ON c.contact_id = a.coattr_contact_id
            WHERE a.coattr_link_state = 'missing' AND NOT EXISTS (SELECT 1 FROM contact_odoo_links l2 WHERE l2.contact_id = a.coattr_contact_id AND l2.odoo_integration_id = ?)",
            'ii', [$iid, $iid]);
        $order = ['repointed' => 0, 'mismatch' => 1, 'missing' => 2, 'unchecked' => 3, 'ok' => 4];
        $counts = ['ok' => 0, 'mismatch' => 0, 'missing' => 0, 'repointed' => 0, 'unchecked' => 0];
        $out = [];
        foreach ($rows as $r) {
            $state = (string) ($r['coattr_link_state'] ?? 'unchecked');
            $counts[$state] = ($counts[$state] ?? 0) + 1;
            $sid = $r['coattr_link_suggested_employee_id'] === null ? null : (int) $r['coattr_link_suggested_employee_id'];
            $out[] = [
                'contact_id' => (int) $r['contact_id'],
                'contact_name' => (string) $r['contact_name'],
                'odoo_employee_id' => (int) $r['odoo_employee_id'],
                'state' => $state,
                'detail' => $r['coattr_link_detail'],
                'seen_name' => $r['coattr_link_seen_name'],
                'confirmed_name' => $r['coattr_odoo_name'],
                'suggestion' => $sid === null ? null : ['id' => $sid, 'name' => $r['coattr_odoo_name']],
                'checked_at_utc' => $r['coattr_link_checked_at_utc'],
            ];
        }
        usort($out, static fn($a, $b) => [$order[$a['state']] ?? 9, $a['contact_name']] <=> [$order[$b['state']] ?? 9, $b['contact_name']]);
        $checked = Db::one($this->db, 'SELECT config_training_odoo_link_checked_at_utc AS t FROM settings WHERE company_id = 1');
        return [
            'target' => ['current' => $current, 'accepted' => $accepted, 'pending' => $accepted !== null && $accepted !== $current],
            'counts' => $counts,
            'checked_at_utc' => $checked['t'] ?? null,
            'rows' => $out,
        ];
    }

    /** Points a flagged link at the checked suggestion. */
    public function relink(int $contactId, int $odooEmployeeId, int $userId): void
    {
        $iid = (int) $this->row['odoo_integration_id'];
        $current = OdooTarget::sha($this->row['base_url'] ?? null, $this->row['database_name'] ?? null);
        $from = Db::tx($this->db, function () use ($contactId, $odooEmployeeId, $userId, $iid, $current): int {
            $a = $this->lockAttr($contactId, ['mismatch', 'missing', 'repointed']);
            if ($a['coattr_link_suggested_employee_id'] === null || (int) $a['coattr_link_suggested_employee_id'] !== $odooEmployeeId) {
                throw ApiException::validation(['odoo_employee_id' => 'Relink only to the suggested Odoo employee (run Check now first).']);
            }
            $other = Db::one($this->db, 'SELECT contact_id FROM contact_odoo_links WHERE odoo_integration_id = ? AND odoo_employee_id = ? AND contact_id <> ? LIMIT 1 FOR UPDATE',
                'iii', [$iid, $odooEmployeeId, $contactId]);
            if ($other !== null) {
                throw new ApiException(409, 'odoo_employee_linked', 'That Odoo employee is already linked to another person.');
            }
            $link = Db::one($this->db, 'SELECT id, odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ? FOR UPDATE', 'ii', [$contactId, $iid]);
            if ($link === null) {
                Db::insert($this->db, 'INSERT INTO contact_odoo_links (contact_id, odoo_integration_id, odoo_employee_id) VALUES (?, ?, ?)', 'iii',
                    [$contactId, $iid, $odooEmployeeId]);
                $from = 0;
            } else {
                $from = (int) $link['odoo_employee_id'];
                Db::exec($this->db, 'UPDATE contact_odoo_links SET odoo_employee_id = ? WHERE id = ?', 'ii', [$odooEmployeeId, (int) $link['id']]);
            }
            Db::exec($this->db, "UPDATE contact_odoo_attributes SET coattr_odoo_integration_id = ?, coattr_odoo_employee_id = ?, coattr_link_state = 'ok',
                    coattr_link_detail = ?, coattr_odoo_target_sha = ?, coattr_link_suggested_employee_id = NULL,
                    coattr_link_confirmed_by = ?, coattr_link_confirmed_at_utc = ?, coattr_job_id = NULL, coattr_job_name = NULL,
                    coattr_work_location_id = NULL, coattr_work_location_name = NULL, coattr_odoo_pin_ok = NULL
                WHERE coattr_contact_id = ?", 'iissisi',
                [$iid, $odooEmployeeId, 'Relinked to Odoo employee #' . $odooEmployeeId . ' on ' . Clock::todayLocal(), $current, $userId, Clock::nowUtc(), $contactId]);
            return $from;
        });
        $this->audit($userId, $contactId, 'relink', "Relinked contact #$contactId to Odoo employee #$odooEmployeeId", ['from' => $from, 'to' => $odooEmployeeId]);
        $this->maybeAccept($userId);
    }

    /** Removes a missing or mismatched link. The next directory sync creates a new contact for that Odoo employee. */
    public function unlink(int $contactId, int $userId): void
    {
        $iid = (int) $this->row['odoo_integration_id'];
        $userName = (string) (Db::one($this->db, 'SELECT user_name FROM users WHERE user_id = ?', 'i', [$userId])['user_name'] ?? ('user #' . $userId));
        $from = Db::tx($this->db, function () use ($contactId, $userId, $iid, $userName): ?int {
            $this->lockAttr($contactId, ['missing', 'mismatch']);
            $link = Db::one($this->db, 'SELECT id, odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ? FOR UPDATE', 'ii', [$contactId, $iid]);
            if ($link !== null) {
                Db::exec($this->db, 'DELETE FROM contact_odoo_links WHERE id = ?', 'i', [(int) $link['id']]);
            }
            Db::exec($this->db, "UPDATE contact_odoo_attributes SET coattr_link_state = 'missing', coattr_link_detail = ?, coattr_link_suggested_employee_id = NULL,
                    coattr_odoo_pin_ok = NULL WHERE coattr_contact_id = ?", 'si',
                [mb_substr('Unlinked by ' . $userName . ' on ' . Clock::todayLocal(), 0, 255), $contactId]);
            return $link === null ? null : (int) $link['odoo_employee_id'];
        });
        $this->audit($userId, $contactId, 'unlink', "Unlinked contact #$contactId from Odoo employee #" . ($from ?? 0), ['from' => $from]);
        $this->maybeAccept($userId);   // missing links now block the auto-accept, so resolving the last one by Unlink counts too
    }

    /** Accepts the link as it is now: baseline := the linked employee and the name Odoo showed at the last check. */
    public function confirm(int $contactId, int $userId): void
    {
        $iid = (int) $this->row['odoo_integration_id'];
        $current = OdooTarget::sha($this->row['base_url'] ?? null, $this->row['database_name'] ?? null);
        $meta = Db::tx($this->db, function () use ($contactId, $userId, $iid, $current): array {
            $a = $this->lockAttr($contactId, ['mismatch', 'repointed']);
            $link = Db::one($this->db, 'SELECT odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ? FOR UPDATE', 'ii', [$contactId, $iid]);
            if ($link === null) {
                throw new ApiException(409, 'link_state', 'This person is no longer linked to Odoo.');
            }
            $to = (int) $link['odoo_employee_id'];
            $name = $a['coattr_link_seen_name'] ?? $a['coattr_odoo_name'];
            Db::exec($this->db, "UPDATE contact_odoo_attributes SET coattr_odoo_integration_id = ?, coattr_odoo_employee_id = ?, coattr_odoo_name = ?,
                    coattr_link_state = 'ok', coattr_link_detail = ?, coattr_odoo_target_sha = ?, coattr_link_suggested_employee_id = NULL,
                    coattr_link_confirmed_by = ?, coattr_link_confirmed_at_utc = ?
                WHERE coattr_contact_id = ?", 'iisssisi',
                [$iid, $to, $name, 'Confirmed on ' . Clock::todayLocal(), $current, $userId, Clock::nowUtc(), $contactId]);
            return ['from_state' => (string) $a['coattr_link_state'], 'from_employee' => (int) $a['coattr_odoo_employee_id'], 'to_employee' => $to,
                    'from_name' => $a['coattr_odoo_name'], 'to_name' => $name];
        });
        $this->audit($userId, $contactId, 'confirm', "Confirmed the Odoo link of contact #$contactId (employee #{$meta['to_employee']})", $meta);
        $this->maybeAccept($userId);
    }

    // ------------------------------------------------------------------------------------------

    private function lockAttr(int $contactId, array $allowed): array
    {
        $a = Db::one($this->db, 'SELECT coattr_contact_id, coattr_odoo_employee_id, coattr_odoo_name, coattr_link_state, coattr_link_seen_name,
                coattr_link_suggested_employee_id
            FROM contact_odoo_attributes WHERE coattr_contact_id = ? FOR UPDATE', 'i', [$contactId]);
        if ($a === null) {
            throw ApiException::notFound('That person has no Odoo link.');
        }
        if (!in_array((string) $a['coattr_link_state'], $allowed, true)) {
            throw new ApiException(409, 'link_state', 'This action is not available for a link in state "' . $a['coattr_link_state'] . '".');
        }
        return $a;
    }

    /** Accepts the current target when nothing is flagged (missing included), at least one link is ok and every ok link was checked against it. */
    private function maybeAccept(int $userId): void
    {
        $accepted = OdooTarget::accepted($this->db);
        $current = OdooTarget::sha($this->row['base_url'] ?? null, $this->row['database_name'] ?? null);
        if ($accepted === false || $accepted === null || $accepted === $current) {
            return;
        }
        $iid = (int) $this->row['odoo_integration_id'];
        // 'missing' counts while the link row still exists: it is resolved by Unlink (the admin's explicit choice) or Relink.
        $left = Db::one($this->db, "SELECT
                SUM(a.coattr_link_state IN ('mismatch', 'repointed', 'missing', 'unchecked')) AS flagged,
                SUM(a.coattr_link_state = 'ok' AND (a.coattr_odoo_target_sha IS NULL OR a.coattr_odoo_target_sha <> ?)) AS stale,
                SUM(a.coattr_link_state = 'ok') AS ok
            FROM contact_odoo_links l JOIN contact_odoo_attributes a ON a.coattr_contact_id = l.contact_id
            WHERE l.odoo_integration_id = ?", 'si', [$current, $iid]);
        if ((int) ($left['flagged'] ?? 0) === 0 && (int) ($left['stale'] ?? 0) === 0 && (int) ($left['ok'] ?? 0) > 0) {
            $this->acceptTarget($userId, $accepted, $current, 'All links resolved');
        }
    }

    private function acceptTarget(int $userId, ?string $from, string $to, string $reason): void
    {
        Db::exec($this->db, 'UPDATE settings SET config_training_odoo_target_sha = ? WHERE company_id = 1', 's', [$to]);
        try {
            (new AuditService($this->db))->log('training.odoo_target_accepted', $userId > 0 ? $userId : null, 'odoo_integration',
                (int) $this->row['odoo_integration_id'], 'accept', mb_substr('Accepted Odoo target ' . OdooTarget::describe($this->row) . ' automatically: ' . $reason, 0, 500),
                ['from' => $from, 'to' => $to, 'reason' => $reason, 'automatic' => true]);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }

    private function audit(int $userId, int $contactId, string $action, string $summary, array $meta): void
    {
        try {
            (new AuditService($this->db))->log('training.odoo_link_changed', $userId > 0 ? $userId : null, 'contact', $contactId, $action, mb_substr($summary, 0, 500), $meta);
        } catch (\Throwable $e) {
            error_log('Training: audit failed: ' . $e->getMessage());
        }
    }
}
