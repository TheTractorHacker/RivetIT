<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Assign\AssignmentService;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\Ledger;

/**
 * Training eligibility (v0 §8.1, Phase 2 spec §3.2). A contact is on the training roster when it is
 * not archived AND either it has a department (client_id > 0) and is not excluded, or it has no
 * department and was explicitly included. JOIN/ELIGIBLE use the table alias `c` for contacts
 * (a frozen contract for Lanes C/D and Phase 3).
 */
final class Roster
{
    public const JOIN = 'LEFT JOIN training_roster tr_r ON tr_r.roster_contact_id = c.contact_id';
    public const ELIGIBLE = "c.contact_archived_at IS NULL AND ((c.contact_client_id > 0 AND COALESCE(tr_r.roster_state,'auto') <> 'exclude')"
                          . " OR (c.contact_client_id = 0 AND COALESCE(tr_r.roster_state,'auto') = 'include'))";

    public const STATES = ['auto', 'include', 'exclude'];
    public const PAGE = 50;

    public function __construct(private readonly Ctx $c)
    {
    }

    public static function isEligible(\mysqli $db, int $contactId): bool
    {
        $row = Db::one($db, 'SELECT (' . self::ELIGIBLE . ') AS e FROM contacts c ' . self::JOIN . ' WHERE c.contact_id = ?', 'i', [$contactId]);
        return $row !== null && (int) $row['e'] === 1;
    }

    /**
     * Sets a contact's roster state. tx: upsert ; Ledger roster.changed ; commit ; reconcile([$id], 'roster').
     *
     * @return array{person:array, changed:bool, reconcile:?array}
     */
    public function set(int $contactId, string $state, ?string $reason): array
    {
        if (!in_array($state, self::STATES, true)) {
            throw ApiException::validation(['state' => 'Not a valid choice.']);
        }
        $reason = $reason === null ? null : trim($reason);
        if ($reason !== null && mb_strlen($reason, 'UTF-8') > 255) {
            throw ApiException::validation(['reason' => 'Too long (at most 255 characters).']);
        }
        if ($state !== 'auto' && ($reason === null || mb_strlen($reason, 'UTF-8') < 3)) {
            throw ApiException::validation(['reason' => 'Say why (at least 3 characters).']);
        }
        $db = $this->c->db;
        $changed = Db::tx($db, function () use ($db, $contactId, $state, $reason): bool {
            $contact = Db::one($db, 'SELECT contact_id FROM contacts WHERE contact_id = ? FOR UPDATE', 'i', [$contactId]);
            if ($contact === null) {
                throw ApiException::notFound('That person was not found.');
            }
            $cur = Db::one($db, 'SELECT roster_state, roster_reason FROM training_roster WHERE roster_contact_id = ? FOR UPDATE', 'i', [$contactId]);
            $from = $cur === null ? 'auto' : (string) $cur['roster_state'];
            Db::exec($db, 'INSERT INTO training_roster (roster_contact_id, roster_state, roster_reason, roster_updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE roster_state = VALUES(roster_state), roster_reason = VALUES(roster_reason), roster_updated_by = VALUES(roster_updated_by)',
                'issi', [$contactId, $state, $reason, $this->c->userId]);
            if ($from === $state) {
                return false;
            }
            Ledger::append($db, [
                'type' => 'roster.changed',
                'actor_type' => 'user',
                'actor_user_id' => $this->c->userId,
                'subject_contact_id' => $contactId,
                'entity_type' => 'roster',
                'entity_id' => $contactId,
                'payload' => ['from' => $from, 'to' => $state, 'reason' => $reason],
                'user_agent' => $this->c->userAgent,
            ]);
            return true;
        });
        $reconcile = $changed ? AssignmentService::safeReconcile($this->c, [$contactId], 'roster') : null;
        $person = Directory::load($db, Scope::all(), [$contactId], true)[$contactId] ?? null;
        return ['person' => $person === null ? null : self::row($person), 'changed' => $changed, 'reconcile' => $reconcile];
    }

    /**
     * The People › Roster list: every non-archived contact in scope (eligible or not).
     * f: q, client_id, state ('auto'|'include'|'exclude'|'eligible'|'ineligible'|'all'), page (1-based).
     *
     * @return array{people:list<array>, total:int, page:int, per_page:int}
     */
    public function list(array $f, Scope $s): array
    {
        $db = $this->c->db;
        if ($s->isNone()) {
            return ['people' => [], 'total' => 0, 'page' => 1, 'per_page' => self::PAGE];
        }
        [$scopeSql, $types, $params] = $s->sqlIn('c.contact_client_id');
        $where = 'WHERE c.contact_archived_at IS NULL' . $scopeSql;
        if (isset($f['client_id']) && $f['client_id'] !== null) {
            $where .= ' AND c.contact_client_id = ?';
            $types .= 'i';
            $params[] = (int) $f['client_id'];
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (c.contact_name LIKE ? OR c.contact_title LIKE ? OR c.contact_employee_id = ?)';
            $types .= 'sss';
            array_push($params, $like, $like, $q);
        }
        $state = (string) ($f['state'] ?? 'all');
        if (in_array($state, self::STATES, true)) {
            $where .= " AND COALESCE(tr_r.roster_state, 'auto') = ?";
            $types .= 's';
            $params[] = $state;
        } elseif ($state === 'eligible') {
            $where .= ' AND ' . self::ELIGIBLE;
        } elseif ($state === 'ineligible') {
            $where .= ' AND NOT (' . self::ELIGIBLE . ')';
        }
        $total = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM contacts c ' . self::JOIN . " $where", $types, $params)['n'] ?? 0);
        $page = max(1, (int) ($f['page'] ?? 1));
        $rows = Db::all($db, 'SELECT c.contact_id FROM contacts c ' . self::JOIN . " $where ORDER BY c.contact_name, c.contact_id LIMIT ? OFFSET ?",
            $types . 'ii', array_merge($params, [self::PAGE, ($page - 1) * self::PAGE]));
        $ids = array_map(static fn($r) => (int) $r['contact_id'], $rows);
        $people = Directory::load($db, $s, $ids, true);
        $out = [];
        foreach ($ids as $id) {
            if (isset($people[$id])) {
                $out[] = self::row($people[$id]);
            }
        }
        return ['people' => $out, 'total' => $total, 'page' => $page, 'per_page' => self::PAGE];
    }

    /** PersonRef + roster fields. */
    public static function row(array $p): array
    {
        return Directory::ref($p) + [
            'eligible' => (bool) $p['eligible'],
            'roster_state' => (string) $p['roster_state'],
            'roster_reason' => $p['roster_reason'],
            'link_state' => $p['link_state'],
            'odoo_linked' => (bool) $p['odoo_linked'],
        ];
    }
}
