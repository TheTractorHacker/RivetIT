<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;

/**
 * RivetIT's clients ("departments"), locations and per-user client scope for the RMM module. The scope rule is the one of the old
 * ITFlow\EndpointAgent\Authz::clientScopeSql(): administrators and users without any user_client_permissions row see every client;
 * anyone else sees the clients listed for them.
 */
final class EndpointTenancy implements RmmTenancyInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function visibleClientIds(int $userId): ?array
    {
        if ($userId <= 0) {
            return [];
        }
        $profile = itflow_user_access_profile($userId);
        if (!empty($profile['admin'])) {
            return null;
        }
        $rows = $this->database->fetchAll('SELECT client_id FROM user_client_permissions WHERE user_id = ?', [$userId]);
        if ($rows === []) {
            return null;
        }

        return array_values(array_map(static fn (array $r): int => (int) $r['client_id'], $rows));
    }

    public function clientName(int $clientId): ?string
    {
        $row = $this->database->fetchOne('SELECT client_name FROM clients WHERE client_id = ?', [$clientId]);

        return $row === null ? null : (string) $row['client_name'];
    }

    public function locationInClient(int $locationId, int $clientId): bool
    {
        return $this->database->fetchOne('SELECT location_id FROM locations WHERE location_id = ? AND location_client_id = ?', [$locationId, $clientId]) !== null;
    }
}
