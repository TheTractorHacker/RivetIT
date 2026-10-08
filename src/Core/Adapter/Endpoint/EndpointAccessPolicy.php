<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Authz\RmmAbility;

/**
 * Maps the nine rmm.* abilities onto RivetIT's role grants, with the SAME rules the old ITFlow\EndpointAgent\Authz applied (Administrator and
 * Technician behaviour does not change):
 *
 *   rmm.device.view                          module_rmm >= 1
 *   rmm.job.run_saved, rmm.job.reboot        view + module_rmm_scripts >= 2, and not a module-only login
 *   rmm.job.run_script                       view + module_rmm_scripts >= 3, and not a module-only login
 *   rmm.remote.launch                        view + module_rmm_remote_connect >= 1, and not a module-only login
 *   rmm.device.manage, rmm.token.manage, rmm.binary.publish, rmm.admin    role_is_admin
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
            default => false,
        };
    }
}
