<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Authz\RmmAbility;

/**
 * Maps the rmm.* abilities onto RivetIT's role grants, with the SAME rules the old ITFlow\EndpointAgent\Authz applied (Administrator and
 * Technician behaviour does not change):
 *
 *   rmm.device.view                          module_rmm >= 1
 *   rmm.job.run_saved, rmm.job.reboot        view + module_rmm_scripts >= 2, and not a module-only login
 *   rmm.job.run_script                       view + module_rmm_scripts >= 3, and not a module-only login
 *   rmm.remote.launch                        view + module_rmm_remote_connect >= 1, and not a module-only login
 *   rmm.device.manage, rmm.token.manage, rmm.binary.publish, rmm.admin    role_is_admin
 *   rmm.job.approve (RMM Phase 2)            role_is_admin; plus a role with module_rmm_scripts >= 3 ONLY while the administrator has switched on
 *                                            "RMM scripts level 3 users may approve" (settings.config_rmm_approve_scripts_lvl3, off by default: the point
 *                                            of an approval is a second person, so it is not handed to everyone who may run scripts). Never a module-only login.
 *   rmm.alert.manage (RMM Phase 3)           role_is_admin, or module_rmm >= 2 (write) and not a module-only login: acknowledge/resolve alerts, maintenance windows
 *                                            of one department or device, a device's parent. A module-only login keeps view only.
 *
 *   rmm.remote.manage (RMM Phase 6)          role_is_admin; plus a role with module_rmm_scripts >= 3 AND module_rmm_remote_connect >= 1 ONLY while the administrator has switched on
 *                                            "RMM level 3 users with remote access may use the remote tools" (settings.config_rmm_remote_manage_lvl3, off by default):
 *                                            the service and process managers, downloading a file from a device and the Mesh terminal and files views. A stronger
 *                                            grant than rmm.remote.launch, so it is not handed to everyone who may open a desktop session. Never a module-only login.
 *                                            Sending a file TO a device and the remote-tools settings stay rmm.admin (administrators only, as always).
 *
 * An inactive account (disabled, archived, or not an agent user) is denied everything. The client scope (user_client_permissions) is the
 * tenancy adapter's job, not this policy's; an ability the policy does not know is denied.
 */
final class EndpointAccessPolicy implements AccessPolicyInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function can(?int $userId, string $ability, ?string $subjectType = null, string|int|null $subjectId = null, array $context = []): bool
    {
        try {
            return $this->decide($userId, $ability);
        } catch (\Throwable) {
            return false;
        }
    }

    private function decide(?int $userId, string $ability): bool
    {
        if ($userId === null || $userId <= 0 || !in_array($ability, RmmAbility::all(), true)) {
            return false;
        }
        $u = $this->database->fetchOne('SELECT user_status, user_archived_at, user_type FROM users WHERE user_id = ?', [$userId]);
        if ($u === null || (int) $u['user_status'] !== 1 || $u['user_archived_at'] !== null || (int) $u['user_type'] !== 1) {
            return false;
        }
        $p = itflow_user_access_profile($userId);
        if (RmmAbility::isAdministrative($ability)) {
            return !empty($p['admin']);
        }
        if (($ability === RmmAbility::JOB_APPROVE || $ability === RmmAbility::REMOTE_MANAGE) && !empty($p['admin'])) {
            return true;   // an administrator approves and uses the remote tools without needing the module grant of the technician roles
        }
        if (itflow_profile_level($p, 'module_rmm') < 1) {
            return false;
        }
        if ($ability === RmmAbility::DEVICE_VIEW) {
            return true;
        }
        if (itflow_profile_is_limited($p)) {
            return false;   // module-only logins may view, but never run, reboot or open a remote session
        }

        return match ($ability) {
            RmmAbility::JOB_RUN_SCRIPT => itflow_profile_level($p, 'module_rmm_scripts') >= 3,
            RmmAbility::JOB_RUN_SAVED, RmmAbility::JOB_REBOOT => itflow_profile_level($p, 'module_rmm_scripts') >= 2,
            RmmAbility::REMOTE_LAUNCH => itflow_profile_level($p, 'module_rmm_remote_connect') >= 1,
            RmmAbility::ALERT_MANAGE => itflow_profile_level($p, 'module_rmm') >= 2,
            RmmAbility::REMOTE_MANAGE => itflow_profile_level($p, 'module_rmm_scripts') >= 3 && itflow_profile_level($p, 'module_rmm_remote_connect') >= 1 && $this->level3MayManageRemote(),
            RmmAbility::JOB_APPROVE => !empty($p['admin']) || (itflow_profile_level($p, 'module_rmm_scripts') >= 3 && $this->level3MayApprove()),
            default => false,
        };
    }

    /** The administrator's switch for the remote tools (Administration > Endpoint agent > Remote tools). A missing column (code newer than the schema) means off. */
    private function level3MayManageRemote(): bool
    {
        $r = $this->database->fetchOne('SELECT config_rmm_remote_manage_lvl3 AS v FROM settings WHERE company_id = 1');

        return $r !== null && (int) $r['v'] === 1;
    }

    /** The administrator's switch for level 3 script users (Administration > Endpoint agent > Approvals). A missing column (code newer than the schema) means off. */
    private function level3MayApprove(): bool
    {
        $r = $this->database->fetchOne('SELECT config_rmm_approve_scripts_lvl3 AS v FROM settings WHERE company_id = 1');

        return $r !== null && (int) $r['v'] === 1;
    }
}
