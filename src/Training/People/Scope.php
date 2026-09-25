<?php

namespace ITFlow\Training\People;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;

/**
 * Which people (departments = clients) a caller may see in Training. FAIL-CLOSED (Phase 2 spec
 * §0 #3, v0 §12), deliberately unlike the app-wide "no rows = every department" rule:
 *   - admins and module_training level 3 see every department, including contact_client_id = 0;
 *   - everyone else sees only the departments in their user_client_permissions rows;
 *     no rows = no people data (pages show a banner);
 *   - a refusal is always 404 not_found, never 403.
 * Services never authorize; Actions and pages call forCtx() then assertContact() per person.
 */
final class Scope
{
    /** @param list<int>|null $ids null = all departments */
    private function __construct(private readonly ?array $ids)
    {
    }

    public static function forCtx(Ctx $c): self
    {
        if ($c->isAdmin || $c->level >= 3) {
            return self::all();
        }
        if ($c->userId <= 0) {
            return new self([]);
        }
        $rows = Db::all($c->db, 'SELECT client_id FROM user_client_permissions WHERE user_id = ? ORDER BY client_id', 'i', [$c->userId]);
        $ids = [];
        foreach ($rows as $r) {
            $id = (int) $r['client_id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return new self(array_values($ids));
    }

    /** Cron, CLI, kiosk, snapshot. */
    public static function all(): self
    {
        return new self(null);
    }

    /** @param list<int> $clientIds (tests, and callers that computed a scope elsewhere) */
    public static function of(array $clientIds): self
    {
        $ids = [];
        foreach ($clientIds as $id) {
            if ((int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        ksort($ids);
        return new self(array_values($ids));
    }

    public function isAll(): bool
    {
        return $this->ids === null;
    }

    public function isNone(): bool
    {
        return $this->ids === [];
    }

    /** @return list<int> the allowed department ids ([] for all-scope; check isAll() first) */
    public function clientIds(): array
    {
        return $this->ids ?? [];
    }

    /**
     * [sql, types, params] to append to a WHERE: all => ['', '', []]; none => [' AND 0=1', '', []];
     * else [" AND $column IN (?,…)", 'i…', ids]. $column must be an identifier (asserted).
     *
     * @return array{0:string, 1:string, 2:list<int>}
     */
    public function sqlIn(string $column): array
    {
        if (preg_match('/^[a-z_.]+$/D', $column) !== 1) {
            throw new \InvalidArgumentException('Scope::sqlIn: bad column identifier');
        }
        if ($this->ids === null) {
            return ['', '', []];
        }
        if ($this->ids === []) {
            return [' AND 0=1', '', []];
        }
        return [' AND ' . $column . ' IN (' . implode(',', array_fill(0, count($this->ids), '?')) . ')', str_repeat('i', count($this->ids)), $this->ids];
    }

    /** Department 0 ("no department") only with all-scope. */
    public function allows(int $clientId): bool
    {
        if ($this->ids === null) {
            return true;
        }
        return $clientId > 0 && in_array($clientId, $this->ids, true);
    }

    /**
     * The contact, or 404 not_found when it is missing or outside the scope (archived contacts allowed).
     *
     * @return array{contact_id:int, contact_name:string, contact_client_id:int, contact_user_id:int, contact_archived_at:?string}
     */
    public function assertContact(\mysqli $db, int $contactId): array
    {
        $row = $contactId > 0 ? Db::one($db, 'SELECT contact_id, contact_name, contact_client_id, contact_user_id, contact_archived_at
            FROM contacts WHERE contact_id = ?', 'i', [$contactId]) : null;
        if ($row === null || !$this->allows((int) $row['contact_client_id'])) {
            throw ApiException::notFound('That person was not found.');
        }
        return [
            'contact_id' => (int) $row['contact_id'],
            'contact_name' => (string) $row['contact_name'],
            'contact_client_id' => (int) $row['contact_client_id'],
            'contact_user_id' => (int) $row['contact_user_id'],
            'contact_archived_at' => $row['contact_archived_at'] === null ? null : (string) $row['contact_archived_at'],
        ];
    }

    /**
     * Every id must be an in-scope contact (bulk requests): returns the rows keyed by id, or 404.
     *
     * @param list<int> $contactIds
     * @return array<int, array>
     */
    public function assertContacts(\mysqli $db, array $contactIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        if ($ids === []) {
            return [];
        }
        if (min($ids) <= 0) {
            throw ApiException::notFound('That person was not found.');
        }
        $rows = Db::all($db, 'SELECT contact_id, contact_name, contact_client_id, contact_user_id, contact_archived_at FROM contacts
            WHERE contact_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', str_repeat('i', count($ids)), $ids);
        $out = [];
        foreach ($rows as $row) {
            if ($this->allows((int) $row['contact_client_id'])) {
                $out[(int) $row['contact_id']] = [
                    'contact_id' => (int) $row['contact_id'],
                    'contact_name' => (string) $row['contact_name'],
                    'contact_client_id' => (int) $row['contact_client_id'],
                    'contact_user_id' => (int) $row['contact_user_id'],
                    'contact_archived_at' => $row['contact_archived_at'] === null ? null : (string) $row['contact_archived_at'],
                ];
            }
        }
        if (count($out) !== count($ids)) {
            throw ApiException::notFound('One of those people was not found.');
        }
        return $out;
    }
}
