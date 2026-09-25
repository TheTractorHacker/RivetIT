<?php

namespace ITFlow\Training\Achievements;

use ITFlow\Training\Api\ApiException;
use ITFlow\Training\Core\Ctx;
use ITFlow\Training\Core\Db;
use ITFlow\Training\Core\SystemCtx;
use ITFlow\Training\Kiosk\Bridge\RecordsBridge;

/**
 * Who an agent may see and award, and who the nightly jobs look at (P3 spec §4.3, §8 "Agent side").
 *
 * Department scope goes through K3's RecordsBridge (assertContactInScope / scopeClientIds, which
 * wrap Phase 2's fail-closed department scope): an admin or module_training level 3 sees every
 * department; anyone else sees only the departments in their user_client_permissions rows, and
 * no rows means nobody.
 * Contacts outside the scope are a 404, never a 403, so an id cannot be probed.
 *
 * Eligible contacts (nightly backfill and streaks) come from RecordsBridge::eligibleSql() when
 * the bridge is available, else "not archived and in a department".
 */
final class AwardScope
{
    /** @return list<int>|null null = every department, [] = none */
    public static function clientIds(Ctx $c): ?array
    {
        $ids = (new RecordsBridge($c))->scopeClientIds($c);
        return $ids === null ? null : array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 404 unless the contact exists and is in the agent's scope (archived contacts are allowed:
     * their history stays visible). @return array{contact_id:int, name:string, client_id:int, archived:bool}
     */
    public static function assertContact(Ctx $c, int $contactId): array
    {
        if ($contactId < 1) {
            throw ApiException::notFound('That person was not found.');
        }
        (new RecordsBridge($c))->assertContactInScope($c, $contactId);
        $row = Db::one($c->db, 'SELECT contact_id, contact_name, contact_client_id, contact_archived_at FROM contacts WHERE contact_id = ?', 'i', [$contactId]);
        if ($row === null) {
            throw ApiException::notFound('That person was not found.');
        }
        return [
            'contact_id' => (int) $row['contact_id'],
            'name' => (string) $row['contact_name'],
            'client_id' => (int) $row['contact_client_id'],
            'archived' => $row['contact_archived_at'] !== null,
        ];
    }

    /**
     * People an agent can pick in the "Award manually" form: eligible contacts in scope, by name.
     *
     * @return list<array{id:int, name:string, dept:?string}>
     */
    public static function people(Ctx $c, int $limit = 3000): array
    {
        $scope = self::clientIds($c);
        if ($scope === []) {
            return [];
        }
        [$join, $where, $types, $params] = self::eligible($c->db, 'c');
        if ($scope !== null) {
            $where .= ' AND c.contact_client_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $types .= str_repeat('i', count($scope));
            $params = array_merge($params, $scope);
        }
        $rows = Db::all(
            $c->db,
            "SELECT c.contact_id, c.contact_name, cl.client_name
               FROM contacts c $join
               LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
              WHERE $where
              ORDER BY c.contact_name, c.contact_id
              LIMIT " . max(1, min(5000, $limit)),
            $types,
            $params
        );
        return array_map(static fn($r) => [
            'id' => (int) $r['contact_id'],
            'name' => (string) $r['contact_name'],
            'dept' => $r['client_name'] === null ? null : (string) $r['client_name'],
        ], $rows);
    }

    /** Every eligible contact id (nightly jobs). @return list<int> */
    public static function eligibleContactIds(\mysqli $db): array
    {
        [$join, $where, $types, $params] = self::eligible($db, 'c');
        return array_map(static fn($r) => (int) $r['contact_id'], Db::all(
            $db,
            "SELECT c.contact_id FROM contacts c $join WHERE $where ORDER BY c.contact_id",
            $types,
            $params
        ));
    }

    /** @return array{0:string, 1:string, 2:string, 3:array} join, where, types, params for contacts alias $a */
    private static function eligible(\mysqli $db, string $a): array
    {
        $fallback = ['', "$a.contact_archived_at IS NULL AND $a.contact_client_id > 0", '', []];
        try {
            $sql = (new RecordsBridge(SystemCtx::make($db, 0, 'training_awards')))->eligibleSql($a);
            if ($sql['where'] !== '') {
                return [$sql['join'], $sql['where'], $sql['types'], array_values($sql['params'])];
            }
        } catch (\Throwable $e) {
            error_log('Training awards: eligibility bridge failed: ' . get_class($e));
        }
        return $fallback;
    }
}
