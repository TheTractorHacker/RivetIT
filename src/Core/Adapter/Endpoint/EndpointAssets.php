<?php

declare(strict_types=1);

namespace ITFlow\Core\Adapter\Endpoint;

use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Contracts\RmmAssetNamesInterface;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;

/** The asset side of RMM identity matching and linking, over RivetIT's `assets` and `asset_interfaces` tables. */
final class EndpointAssets implements RmmAssetsInterface, RmmAssetNamesInterface
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function findBySerial(string $serial, int $limit = 10): array
    {
        return $this->shape($this->database->fetchAll(
            'SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE asset_serial = ? AND asset_archived_at IS NULL LIMIT ' . max(1, $limit),
            [$serial]
        ));
    }

    public function findByMacs(array $macs, int $limit = 20): array
    {
        if ($macs === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($macs), '?'));

        return $this->shape($this->database->fetchAll(
            "SELECT DISTINCT a.asset_id, a.asset_name, a.asset_client_id, a.asset_serial FROM assets a JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id
             WHERE LOWER(REPLACE(ai.interface_mac, '-', ':')) IN ($in) AND a.asset_archived_at IS NULL LIMIT " . max(1, $limit),
            array_values($macs)
        ));
    }

    public function findByHostname(string $hostname, int $limit = 10): array
    {
        return $this->shape($this->database->fetchAll(
            'SELECT asset_id, asset_name, asset_client_id, asset_serial FROM assets WHERE LOWER(asset_name) = LOWER(?) AND asset_archived_at IS NULL LIMIT ' . max(1, $limit),
            [$hostname]
        ));
    }

    public function assetNames(array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $names = [];
        foreach ($this->database->fetchAll("SELECT asset_id, asset_name FROM assets WHERE asset_id IN ($in)", $ids) as $r) {
            $names[(int) $r['asset_id']] = (string) $r['asset_name'];
        }

        return $names;
    }

    public function find(int $assetId): ?array
    {
        $r = $this->database->fetchOne('SELECT asset_id, asset_client_id, asset_archived_at FROM assets WHERE asset_id = ?', [$assetId]);

        return $r === null ? null : ['asset_id' => (int) $r['asset_id'], 'client_id' => (int) $r['asset_client_id'], 'archived' => $r['asset_archived_at'] !== null];
    }

    public function createForDevice(array $device, int $clientId, int $locationId): int
    {
        $isServer = stripos((string) $device['os_version'], 'server') !== false;
        $res = $this->database->execute(
            "INSERT INTO assets (asset_type, asset_name, asset_make, asset_model, asset_serial, asset_os, asset_status, asset_client_id, asset_location_id, asset_created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Active', ?, ?, NOW())",
            [$isServer ? 'Server' : 'Laptop', $device['hostname'], (string) ($device['manufacturer'] ?? ''), $device['model'], $device['serial'], 'Windows ' . $device['os_version'], $clientId, $locationId]
        );

        return (int) $res->insertId;
    }

    public function fillBlanks(int $assetId, array $facts): void
    {
        // Fill blanks only; never overwrite what a human typed.
        $this->database->execute(
            "UPDATE assets SET asset_serial = IF(asset_serial IS NULL OR asset_serial = '', ?, asset_serial), asset_model = IF(asset_model IS NULL OR asset_model = '', ?, asset_model),
             asset_make = IF(asset_make = '', ?, asset_make), asset_os = IF(asset_os IS NULL OR asset_os = '', ?, asset_os) WHERE asset_id = ?",
            [$facts['serial'], $facts['model'], (string) $facts['manufacturer'], $facts['os'], $assetId]
        );
    }

    public function moveToClient(int $assetId, int $clientId, int $locationId): void
    {
        $this->database->execute('UPDATE assets SET asset_client_id = ?, asset_location_id = ? WHERE asset_id = ?', [$clientId, $locationId, $assetId]);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    private function shape(array $rows): array
    {
        return array_map(static fn (array $a): array => [
            'asset_id' => (int) $a['asset_id'],
            'asset_name' => (string) $a['asset_name'],
            'client_id' => (int) $a['asset_client_id'],
            'serial' => $a['asset_serial'] === null ? null : (string) $a['asset_serial'],
        ], $rows);
    }
}
