<?php

namespace ITFlow\EndpointAgent;

/**
 * One authorization decision for the web handlers AND the REST API, so the two cannot drift apart.
 *
 * Viewing, running code, rebooting and remote access are INDEPENDENT grants built on the existing RMM permission modules (no new
 * permission keys, so the role editor needs no change):
 *
 *   view devices / jobs         module_rmm >= 1
 *   reboot, collect, saved scripts   module_rmm_scripts >= 2  (and view)
 *   free-form PowerShell        module_rmm_scripts >= 3  (and view)   - the level that may author scripts
 *   remote session (MeshCentral) module_rmm_remote_connect >= 1 (and view)
 *   administration              role_is_admin
 *
 * Module-only ("limited") logins may VIEW (like every other RMM page they hold) but can never run, reboot or open a remote
 * session on an agent device. Department access: administrators, department 0 and users without any user_client_permissions row
 * pass; everyone else needs a row for the device's department.
 */
final class Authz
{
    public const VIEW = 'view';
    public const RUN_SCRIPT = 'run_script';
    public const RUN_SAVED = 'run_saved';
    public const REBOOT = 'reboot';
    public const REMOTE = 'remote';
    public const ADMIN = 'admin';

    /** @return string|null null when allowed, otherwise the reason (safe to show). */
    public static function check(int $userId, string $action, int $clientId): ?string
    {
        if (!Config::enabled()) {
            return 'The endpoint agent is not enabled.';
        }
        $active = Db::one('SELECT user_status, user_archived_at, user_type FROM users WHERE user_id = ?', [$userId]);
        if (!$active || (int) $active['user_status'] !== 1 || $active['user_archived_at'] !== null || (int) $active['user_type'] !== 1) {
            return 'Your account is not active.';
        }
        $p = itflow_user_access_profile($userId);
        if ($action === self::ADMIN) {
            return !empty($p['admin']) ? null : 'Administrator access is required.';
        }
        if (itflow_profile_level($p, 'module_rmm') < 1) {
            return 'Your role cannot view RMM devices.';
        }
        if (!self::clientOk($userId, $p, $clientId)) {
            return 'You do not have access to this device\'s department.';
        }
        if ($action === self::VIEW) {
            return null;
        }
        if (itflow_profile_is_limited($p)) {
            return 'Module-only logins cannot run jobs or open remote sessions on agent devices.';
        }
        switch ($action) {
            case self::RUN_SCRIPT:
                return itflow_profile_level($p, 'module_rmm_scripts') >= 3 ? null : 'Your role cannot run free-form PowerShell.';
            case self::RUN_SAVED:
            case self::REBOOT:
                return itflow_profile_level($p, 'module_rmm_scripts') >= 2 ? null : 'Your role cannot run jobs on devices.';
            case self::REMOTE:
                return itflow_profile_level($p, 'module_rmm_remote_connect') >= 1 ? null : 'Your role cannot open remote sessions.';
        }
        return 'Unknown action.';
    }

    public static function clientOk(int $userId, array $profile, int $clientId): bool
    {
        if (!empty($profile['admin']) || $clientId === 0) {
            return true;
        }
        if (Db::val('SELECT 1 FROM user_client_permissions WHERE user_id = ? LIMIT 1', [$userId]) === null) {
            return true;
        }
        return Db::val('SELECT 1 FROM user_client_permissions WHERE user_id = ? AND client_id = ? LIMIT 1', [$userId, $clientId]) !== null;
    }

    /** SQL fragment + params restricting a device list to the caller's departments. */
    public static function clientScopeSql(int $userId, string $column): array
    {
        $p = itflow_user_access_profile($userId);
        if (!empty($p['admin']) || Db::val('SELECT 1 FROM user_client_permissions WHERE user_id = ? LIMIT 1', [$userId]) === null) {
            return ['1 = 1', []];
        }
        return ["($column = 0 OR $column IN (SELECT client_id FROM user_client_permissions WHERE user_id = ?))", [$userId]];
    }
}
