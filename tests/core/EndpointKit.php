<?php

declare(strict_types=1);

require_once __DIR__ . '/KitSupport.php';

/**
 * Plumbing for the conformance tests of the RMM module adapters (src/Core/Adapter/Endpoint): the app functions those adapters call, as far as they can
 * be loaded without a whole app bootstrap, and a logAction() stand-in that writes the same `logs` row the real one does.
 */
require_once dirname(__DIR__, 2) . '/includes/module_access.php';   // itflow_user_access_profile() and friends (no side effect on include)

if (!function_exists('logAction')) {
    function logAction($type, $action, $description, $client_id = 0, $entity_id = 0): void
    {
        KitSupport::db()->execute('INSERT INTO logs SET log_type = ?, log_action = ?, log_description = ?, log_ip = ?, log_user_agent = ?, log_client_id = ?, log_user_id = 0, log_entity_id = ?',
            [substr((string) $type, 0, 200), substr((string) $action, 0, 255), substr((string) $description, 0, 1000), 'conformance', 'conformance', (int) $client_id, (int) $entity_id]);
    }
}

final class EndpointKit
{
    public static function db(): \ITFlow\Core\Adapter\Database\MysqliDatabaseAdapter
    {
        return KitSupport::db();
    }

    public static function mysqli(): \mysqli
    {
        self::db();

        return $GLOBALS['mysqli'];
    }

    public static function client(string $name = 'conformance'): int
    {
        return (int) self::db()->execute('INSERT INTO clients SET client_name = ?, client_created_at = NOW()', [$name])->insertId;
    }
}
