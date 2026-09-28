<?php

namespace ITFlow\Security;

/**
 * Thin wrapper over the existing permission model - NOT a replacement.
 *
 * RivetIT keeps two existing, working layers as-is:
 *   1. Role/module permissions: lookupUserPermission($module) in functions.php,
 *      backed by user_roles / user_role_permissions / modules.
 *   2. Per-department access scoping: enforceClientAccess($client_id) in
 *      functions.php, backed by user_client_permissions. The master plan
 *      (Section 6.4) explicitly wants this kept - department privacy
 *      (HR/Finance walled off from general staff) is a real requirement,
 *      not MSP-customer-isolation leftover to be removed.
 *
 * This class exists so new /src services have one typed entry point to
 * depend on instead of calling the legacy global functions directly -
 * see Phase 0 exit criteria in PROGRESS.md. It intentionally does not
 * reimplement the permission logic.
 */
class AuthorizationService
{
    /**
     * @return int Permission level: 0 none, 1 read, 2 write/full - matches
     *             lookupUserPermission()'s existing return values.
     */
    public function permissionLevel(string $module): int
    {
        return lookupUserPermission($module);
    }

    public function hasAtLeast(string $module, int $level): bool
    {
        return $this->permissionLevel($module) >= $level;
    }

    /**
     * Redirects and exits (matching enforceClientAccess()'s existing
     * behavior) if the current session isn't allowed to access this
     * department/client. Admins always pass.
     */
    public function requireDepartmentAccess(?int $clientId = null): void
    {
        enforceClientAccess($clientId);
    }
}
