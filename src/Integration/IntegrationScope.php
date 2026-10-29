<?php

namespace ITFlow\Integration;

/**
 * Department scope of an integration client (least privilege; OWASP API1 BOLA).
 *
 * A "department" is clients.client_id, exactly as in the Training module (a person's department is contacts.contact_client_id; 0 = none).
 * RivetIT stores no separate Odoo-side department for a person, so the Odoo link (contact_odoo_links) does not widen or narrow the scope:
 * an employee is in scope when their contact row is in an allowed department, whichever id the caller used to name them.
 *
 * The setting is explicit and has no silent default:
 *   integration_clients.client_all_departments = 1   every department (also people with no department)
 *   integration_clients.client_all_departments = 0   ONLY the departments listed in integration_client_departments; no rows = nothing at all
 *
 * The client row is read afresh on every request (IntegrationAuth::validateBearer joins it), and the rows are read here per call, so an
 * admin change applies to tokens that are already issued at their next request.
 */
final class IntegrationScope
{
    /**
     * The departments a client may see: null = all departments, [] = none, otherwise a sorted list of clients.client_id.
     * A client row that lacks the column (a database that has not been migrated) is treated as restricted with no departments.
     *
     * @param array<string,mixed> $client an integration_clients row
     * @return list<int>|null
     */
    public static function allowedDepartmentIds(\mysqli $db, array $client): ?array
    {
        if ((int) ($client['client_all_departments'] ?? 0) === 1) {
            return null;
        }
        $cid = (int) ($client['client_id'] ?? 0);
        if ($cid < 1) {
            return [];
        }
        $st = $db->prepare('SELECT department_id FROM integration_client_departments WHERE client_id = ? ORDER BY department_id');
        $st->bind_param('i', $cid);
        $st->execute();
        $ids = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'department_id'));
        $st->close();
        return array_values(array_unique($ids));
    }

    /** Replaces a client's department rows (the caller validated the ids and the all-departments choice). */
    public static function save(\mysqli $db, int $clientId, bool $all, array $departmentIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $all ? [] : $departmentIds)));
        $flag = $all ? 1 : 0;
        $db->begin_transaction();
        try {
            $st = $db->prepare('UPDATE integration_clients SET client_all_departments = ? WHERE client_id = ?');
            $st->bind_param('ii', $flag, $clientId);
            $st->execute();
            $st->close();
            $st = $db->prepare('DELETE FROM integration_client_departments WHERE client_id = ?');
            $st->bind_param('i', $clientId);
            $st->execute();
            $st->close();
            $st = $db->prepare('INSERT INTO integration_client_departments (client_id, department_id) VALUES (?, ?)');
            foreach ($ids as $d) {
                $st->bind_param('ii', $clientId, $d);
                $st->execute();
            }
            $st->close();
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Validates posted department ids against the non-archived departments. Returns the clean list, or null when any posted value is not
     * the id of a non-archived department (unknown, archived, malformed or duplicate-looking input is refused, never silently dropped).
     *
     * @return list<int>|null
     */
    public static function validIds(\mysqli $db, mixed $posted): ?array
    {
        if (!is_array($posted)) {
            return null;
        }
        $ids = [];
        foreach ($posted as $v) {
            if ((!is_string($v) && !is_int($v)) || preg_match('/^[1-9][0-9]{0,9}$/D', (string) $v) !== 1) {
                return null;
            }
            $ids[(int) $v] = true;
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $db->prepare("SELECT client_id FROM clients WHERE client_archived_at IS NULL AND client_id IN ($in)");
        $st->bind_param(str_repeat('i', count($ids)), ...$ids);
        $st->execute();
        $found = array_map('intval', array_column($st->get_result()->fetch_all(MYSQLI_ASSOC), 'client_id'));
        $st->close();
        sort($ids);
        sort($found);
        return $found === $ids ? $ids : null;
    }
}
