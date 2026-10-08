<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Testing\RmmTenancyConformanceTestCase;

/** EndpointTenancy over clients, locations and user_client_permissions. */
final class EndpointTenancyConformanceTest extends RmmTenancyConformanceTestCase
{
    protected function tenancy(): RmmTenancyInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointTenancy(EndpointKit::db());
    }

    protected function createClient(string $name): int
    {
        return EndpointKit::client($name);
    }

    protected function createLocation(int $clientId): int
    {
        return (int) EndpointKit::db()->execute("INSERT INTO locations SET location_name = 'conformance', location_client_id = ?, location_created_at = NOW()", [$clientId])->insertId;
    }

    protected function restrictUser(int $userId, ?array $clientIds): void
    {
        EndpointKit::db()->execute('DELETE FROM user_client_permissions WHERE user_id = ?', [$userId]);
        if ($clientIds === []) {
            // RivetIT cannot say "restricted to nothing" with zero rows (no rows = unrestricted): a row for client 0 is how it says it.
            $clientIds = [0];
        }
        foreach ($clientIds ?? [] as $id) {
            EndpointKit::db()->execute('INSERT INTO user_client_permissions SET user_id = ?, client_id = ?', [$userId, $id]);
        }
    }
}
