<?php

namespace ITFlow\Oidc;

/**
 * The single question the SSO provider asks before it signs anybody in to the QMS: "may this person use Quality?"
 *
 * It is asked at three points (authorize, token exchange and userinfo), always against current database state, so disabling a person,
 * their department or the provider takes effect at the next call, not when a token expires.
 *
 * CURRENT RULE (this PR): the provider is switched on AND the contact is an eligible department-portal person:
 *   - a contact that exists, is not archived and has contact_employment_status = 'active' (not terminated, not on a leave status);
 *   - linked to a portal login (contacts.contact_user_id -> users) that is type 2 (department/portal), enabled and not archived;
 *   - its department (clients row) is not archived and is Active;
 *   - the department portal itself is switched on (a switched-off portal also ends portal sessions);
 *   - not blocked at the portal's own "enroll two-factor first" gate (an administrator-required 2FA that is not set up yet).
 *
 * PHASE 1 HOOK (not built yet - do NOT invent these settings here): when the Quality module lands (docs/integrations/qms-overview.md,
 * "Module switch" and "Who can use it") this function is the ONE place to add:
 *   1. the module switch  settings.config_module_enable_quality = 1       -> reason 'module_disabled'
 *   2. the department list quality_departments contains the contact's department (contacts.contact_client_id)  -> reason 'department_not_enabled'
 *   3. the portal role gate  contacts.contact_portal_role / the feature flag, if Quality is restricted to roles   -> reason 'role_not_allowed'
 * Nothing else needs to change: authorize, token and userinfo all call allows() and audit whatever reason it returns.
 * The marked block below is where those checks go.
 *
 * Reasons are short codes for the audit log; the person only ever sees a generic message.
 */
final class QualityAccessPolicy
{
    /** @return array{ok:bool, reason:string} */
    public static function allows(int $contactId, ?\mysqli $db = null): array
    {
        $db ??= $GLOBALS['mysqli'] ?? null;
        if (!$db instanceof \mysqli) {
            return ['ok' => false, 'reason' => 'no_database'];
        }
        if (!OidcConfig::load($db)['enabled']) {
            return ['ok' => false, 'reason' => 'provider_disabled'];
        }

        // An administrator signs in as themselves, not as a department person (see allowsAdministrator()).
        if ($contactId < 0) {
            return self::allowsAdministrator(self::administratorUserId($contactId), $db);
        }

        // ---- PHASE 1 HOOK: Quality module switch and per-department gate go here (see the class comment). --------------------------

        if ($contactId <= 0) {
            return ['ok' => false, 'reason' => 'contact_not_found'];
        }
        $st = $db->prepare('SELECT c.contact_archived_at, c.contact_employment_status, c.contact_user_id,
                u.user_id, u.user_type, u.user_status, u.user_archived_at, u.user_token,
                cl.client_archived_at, cl.client_status,
                (SELECT us.user_config_force_mfa FROM user_settings us WHERE us.user_id = u.user_id LIMIT 1) AS force_mfa,
                (SELECT s.config_client_portal_enable FROM settings s WHERE s.company_id = 1 LIMIT 1) AS portal_on
            FROM contacts c
            LEFT JOIN users u ON u.user_id = c.contact_user_id
            LEFT JOIN clients cl ON cl.client_id = c.contact_client_id
            WHERE c.contact_id = ?');
        $st->bind_param('i', $contactId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();

        $no = static fn(string $why): array => ['ok' => false, 'reason' => $why];
        if (!$r) {
            return $no('contact_not_found');
        }
        if ($r['contact_archived_at'] !== null) {
            return $no('contact_archived');
        }
        if ((string) $r['contact_employment_status'] !== 'active') {
            return $no('contact_not_active');
        }
        if ((int) $r['contact_user_id'] <= 0 || $r['user_id'] === null || (int) $r['user_type'] !== 2) {
            return $no('not_portal_user');
        }
        if ((int) $r['user_status'] !== 1 || $r['user_archived_at'] !== null) {
            return $no('login_disabled');
        }
        if ($r['client_archived_at'] !== null || (string) $r['client_status'] !== 'Active') {
            return $no('department_inactive');
        }
        if ((int) $r['portal_on'] !== 1) {
            return $no('portal_disabled');
        }
        if ((int) ($r['force_mfa'] ?? 0) === 1 && empty($r['user_token'])) {
            return $no('mfa_enrollment_required');
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** The subject and display facts for an eligible contact: sub, rivetit_id, name. @return array{sub:string, rivetit_id:string, name:string, user_id:int, role?:string}|null */
    public static function subject(\mysqli $db, int $contactId): ?array
    {
        if ($contactId < 0) {
            return self::administratorSubject($db, self::administratorUserId($contactId));
        }
        $st = $db->prepare('SELECT c.contact_name, c.contact_user_id, (SELECT MIN(l.odoo_employee_id) FROM contact_odoo_links l WHERE l.contact_id = c.contact_id) AS odoo_id
            FROM contacts c WHERE c.contact_id = ?');
        $st->bind_param('i', $contactId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r) {
            return null;
        }
        return [
            // sub: the Odoo employee ID when linked (the shared person key across Odoo, RivetIT and the QMS); otherwise "rivetit:<contact id>".
            'sub' => $r['odoo_id'] !== null && (int) $r['odoo_id'] > 0 ? (string) (int) $r['odoo_id'] : 'rivetit:' . $contactId,
            'rivetit_id' => (string) $contactId,
            'name' => (string) $r['contact_name'],
            'user_id' => (int) $r['contact_user_id'],
        ];
    }

    // ---- administrators ----------------------------------------------------------------------------------------------------------
    //
    // An administrator is not a department person: there is no contact. The rest of the provider stores one integer "contact id" per code,
    // token and audit row, so an administrator is that column holding the NEGATIVE of their user id (-7 = user 7). No real contact id is ever
    // negative, so the two can never collide, and every existing check (code, token, userinfo, audit) goes through allows() / subject() and
    // so applies the administrator rule without any other code knowing about it.

    /** The value stored where a contact id would be, for an administrator's user id. */
    public static function administratorPrincipal(int $userId): int
    {
        return -abs($userId);
    }

    /** The user id behind an administrator principal (0 for anything that is not one). */
    public static function administratorUserId(int $principal): int
    {
        return $principal < 0 ? -$principal : 0;
    }

    /**
     * May this user open Quality as an administrator? The provider must be on (checked by the caller) and the login must be a live agent
     * (type 1) on an administrator role, not disabled, not archived, and not stuck at the "enroll two-factor first" gate.
     * @return array{ok:bool, reason:string}
     */
    private static function allowsAdministrator(int $userId, \mysqli $db): array
    {
        $no = static fn(string $why): array => ['ok' => false, 'reason' => $why];
        if ($userId <= 0) {
            return $no('admin_not_found');
        }
        if (!OidcConfig::load($db)['allow_admins']) {
            return $no('admins_not_allowed');
        }
        $st = $db->prepare('SELECT u.user_type, u.user_status, u.user_archived_at, u.user_token, r.role_is_admin, r.role_archived_at,
                (SELECT us.user_config_force_mfa FROM user_settings us WHERE us.user_id = u.user_id LIMIT 1) AS force_mfa
            FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id WHERE u.user_id = ?');
        $st->bind_param('i', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r) {
            return $no('admin_not_found');
        }
        if ((int) $r['user_type'] !== 1 || (int) ($r['role_is_admin'] ?? 0) !== 1 || $r['role_archived_at'] !== null) {
            return $no('not_administrator');
        }
        if ((int) $r['user_status'] !== 1 || $r['user_archived_at'] !== null) {
            return $no('login_disabled');
        }
        if ((int) ($r['force_mfa'] ?? 0) === 1 && empty($r['user_token'])) {
            return $no('mfa_enrollment_required');
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    /** @return array{sub:string, rivetit_id:string, name:string, user_id:int, role:string}|null */
    private static function administratorSubject(\mysqli $db, int $userId): ?array
    {
        $st = $db->prepare('SELECT user_name FROM users WHERE user_id = ?');
        $st->bind_param('i', $userId);
        $st->execute();
        $r = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$r) {
            return null;
        }
        return [
            // Never an Odoo employee number: an administrator may not be an employee, and a number-shaped sub could collide with a real person.
            'sub' => 'rivetit-admin:' . $userId,
            'rivetit_id' => 'admin:' . $userId,
            'name' => (string) $r['user_name'],
            'user_id' => $userId,
            'role' => 'administrator',
        ];
    }
}
