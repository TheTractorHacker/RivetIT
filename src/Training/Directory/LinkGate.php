<?php

namespace ITFlow\Training\Directory;

use ITFlow\Training\Core\Db;

/**
 * May this contact sign in on the kiosk with their Odoo PIN? The Phase 3 contract (Phase 2 spec
 * §1.5, M9): allowed only when
 *   - an Odoo integration exists and is enabled, and its target (base_url|database) is the accepted one;
 *   - the contact has a link for that integration, and no other contact shares its employee id;
 *   - the link's baseline is state `ok`, for the same employee id, checked against the accepted target.
 * Read-only and unscoped (the kiosk resolves the contact itself).
 */
final class LinkGate
{
    /**
     * @return array{allowed:bool, reason:?string, integration_id:?int, odoo_employee_id:?int}
     *         reason: no_integration | target_changed | not_linked | shared_employee | link_unchecked | link_mismatch | link_repointed | link_missing
     */
    public static function odooPinAllowed(\mysqli $db, int $contactId): array
    {
        $deny = static fn(string $reason, ?int $iid = null, ?int $eid = null) => ['allowed' => false, 'reason' => $reason, 'integration_id' => $iid, 'odoo_employee_id' => $eid];
        try {
            $row = OdooLinkChecker::latestIntegration($db);
        } catch (\mysqli_sql_exception) {
            return $deny('no_integration');
        }
        if ($row === null || empty($row['enabled'])) {
            return $deny('no_integration');
        }
        $iid = (int) $row['odoo_integration_id'];
        $accepted = OdooTarget::accepted($db);
        $current = OdooTarget::sha($row['base_url'] ?? null, $row['database_name'] ?? null);
        if ($accepted === false || $accepted === null || !hash_equals($accepted, $current)) {
            return $deny('target_changed', $iid);
        }
        $link = Db::one($db, 'SELECT odoo_employee_id FROM contact_odoo_links WHERE contact_id = ? AND odoo_integration_id = ?', 'ii', [$contactId, $iid]);
        if ($link === null) {
            return $deny('not_linked', $iid);
        }
        $eid = (int) $link['odoo_employee_id'];
        $n = (int) (Db::one($db, 'SELECT COUNT(*) AS n FROM contact_odoo_links WHERE odoo_integration_id = ? AND odoo_employee_id = ?', 'ii', [$iid, $eid])['n'] ?? 0);
        if ($n !== 1) {
            return $deny('shared_employee', $iid, $eid);
        }
        $a = Db::one($db, 'SELECT coattr_odoo_employee_id, coattr_link_state, coattr_odoo_target_sha FROM contact_odoo_attributes WHERE coattr_contact_id = ?', 'i', [$contactId]);
        if ($a === null) {
            return $deny('link_unchecked', $iid, $eid);
        }
        $state = (string) $a['coattr_link_state'];
        if ($state !== 'ok') {
            return $deny(match ($state) {
                'mismatch' => 'link_mismatch',
                'repointed' => 'link_repointed',
                'missing' => 'link_missing',
                default => 'link_unchecked',
            }, $iid, $eid);
        }
        if ((int) $a['coattr_odoo_employee_id'] !== $eid) {
            return $deny('link_repointed', $iid, $eid);
        }
        if ($a['coattr_odoo_target_sha'] === null || !hash_equals($accepted, (string) $a['coattr_odoo_target_sha'])) {
            return $deny('link_unchecked', $iid, $eid);
        }
        return ['allowed' => true, 'reason' => null, 'integration_id' => $iid, 'odoo_employee_id' => $eid];
    }
}
