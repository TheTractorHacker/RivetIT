<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Testing\AccessPolicyConformanceTestCase;

/**
 * EndpointAccessPolicy: the nine rmm.* abilities over RivetIT's roles. Roles as in tests/endpoint_agent_lib.php: an administrator, a full technician
 * (rmm 3, scripts 3, remote 1), a viewer (rmm 1), a reboot-only technician (scripts 2) and a module-only login (rmm 3, scripts 3, remote 1 but no client/support/assets).
 */
final class EndpointAccessPolicyConformanceTest extends AccessPolicyConformanceTestCase
{
    private const USERS = ['admin' => 940001, 'tech' => 940002, 'viewer' => 940003, 'reboot' => 940004, 'moduleonly' => 940005, 'disabled' => 940006];

    protected function policy(): AccessPolicyInterface
    {
        $db = EndpointKit::db();
        foreach (['module_rmm' => 940101, 'module_rmm_scripts' => 940102, 'module_rmm_remote_connect' => 940103, 'module_client' => 940104] as $name => $id) {
            $db->execute("INSERT INTO modules SET module_id = ?, module_name = ? ON DUPLICATE KEY UPDATE module_name = VALUES(module_name)", [$id, $name]);
        }
        // role id => [name, admin, [module id => level]]
        $roles = [940201 => ['Admin', 1, []], 940202 => ['Tech', 0, [940101 => 3, 940102 => 3, 940103 => 1, 940104 => 3]], 940203 => ['Viewer', 0, [940101 => 1, 940104 => 3]],
            940204 => ['RebootOnly', 0, [940101 => 1, 940102 => 2, 940104 => 3]], 940205 => ['ModuleOnly', 0, [940101 => 3, 940102 => 3, 940103 => 1]]];
        foreach ($roles as $rid => [$name, $adm, $levels]) {
            $db->execute("INSERT INTO user_roles SET role_id = ?, role_name = ?, role_is_admin = ?, role_type = 1 ON DUPLICATE KEY UPDATE role_is_admin = VALUES(role_is_admin)", [$rid, 'conf-' . $name, $adm]);
            $db->execute('DELETE FROM user_role_permissions WHERE user_role_id = ?', [$rid]);
            foreach ($levels as $mid => $lvl) {
                $db->execute('INSERT INTO user_role_permissions SET user_role_id = ?, module_id = ?, user_role_permission_level = ?', [$rid, $mid, $lvl]);
            }
        }
        $users = ['admin' => [940201, 1], 'tech' => [940202, 1], 'viewer' => [940203, 1], 'reboot' => [940204, 1], 'moduleonly' => [940205, 1], 'disabled' => [940202, 0]];
        foreach ($users as $key => [$rid, $status]) {
            $db->execute("INSERT INTO users SET user_id = ?, user_name = ?, user_email = ?, user_password = 'x', user_type = 1, user_status = ?, user_role_id = ?
                ON DUPLICATE KEY UPDATE user_status = VALUES(user_status), user_role_id = VALUES(user_role_id), user_archived_at = NULL, user_type = 1",
                [self::USERS[$key], "conf-$key", "conf-$key@example.test", $status, $rid]);
        }

        return new \ITFlow\Core\Adapter\Endpoint\EndpointAccessPolicy($db);
    }

    protected function declaresDenyByDefault(): bool
    {
        return true;
    }

    protected function knownAllowed(): array
    {
        $u = self::USERS;

        return [
            [$u['admin'], RmmAbility::ADMIN, 'client', 0], [$u['admin'], RmmAbility::BINARY_PUBLISH, 'client', 3], [$u['admin'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3],
            [$u['tech'], RmmAbility::DEVICE_VIEW, 'client', 3], [$u['tech'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['tech'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['tech'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['viewer'], RmmAbility::DEVICE_VIEW, 'client', 3], [$u['reboot'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['reboot'], RmmAbility::JOB_RUN_SAVED, 'client', 3],
            [$u['moduleonly'], RmmAbility::DEVICE_VIEW, 'client', 3],
        ];
    }

    protected function knownDenied(): array
    {
        $u = self::USERS;

        return [
            [$u['tech'], RmmAbility::ADMIN, 'client', 0], [$u['tech'], RmmAbility::DEVICE_MANAGE, 'client', 3], [$u['tech'], RmmAbility::TOKEN_MANAGE, 'client', 3], [$u['tech'], RmmAbility::BINARY_PUBLISH, 'client', 3],
            [$u['viewer'], RmmAbility::JOB_RUN_SAVED, 'client', 3], [$u['viewer'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['viewer'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['reboot'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['reboot'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['moduleonly'], RmmAbility::JOB_RUN_SAVED, 'client', 3], [$u['moduleonly'], RmmAbility::JOB_REBOOT, 'client', 3], [$u['moduleonly'], RmmAbility::JOB_RUN_SCRIPT, 'client', 3], [$u['moduleonly'], RmmAbility::REMOTE_LAUNCH, 'client', 3],
            [$u['disabled'], RmmAbility::DEVICE_VIEW, 'client', 3], [$u['disabled'], RmmAbility::JOB_RUN_SAVED, 'client', 3],
            [null, RmmAbility::DEVICE_VIEW, 'client', 3], [999999, RmmAbility::DEVICE_VIEW, 'client', 3],
        ];
    }
}
