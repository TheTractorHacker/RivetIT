<?php

declare(strict_types=1);

require_once __DIR__ . '/EndpointKit.php';

use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Testing\RmmAssetsConformanceTestCase;

/** EndpointAssets over assets and asset_interfaces. */
final class EndpointAssetsConformanceTest extends RmmAssetsConformanceTestCase
{
    protected function assets(): RmmAssetsInterface
    {
        return new \ITFlow\Core\Adapter\Endpoint\EndpointAssets(EndpointKit::db());
    }

    protected function createAsset(array $asset): int
    {
        $id = (int) EndpointKit::db()->execute(
            "INSERT INTO assets SET asset_type = 'Laptop', asset_name = ?, asset_make = ?, asset_model = ?, asset_serial = ?, asset_os = ?, asset_status = 'Active',
             asset_client_id = ?, asset_archived_at = ?, asset_created_at = NOW()",
            [$asset['name'], $asset['make'], $asset['model'], $asset['serial'], $asset['os'], $asset['client_id'], $asset['archived'] ? '2026-01-01 00:00:00' : null]
        )->insertId;
        foreach ($asset['macs'] as $mac) {
            EndpointKit::db()->execute("INSERT INTO asset_interfaces SET interface_name = 'nic', interface_mac = ?, interface_asset_id = ?, interface_created_at = NOW()", [$mac, $id]);
        }

        return $id;
    }

    protected function readAsset(int $assetId): ?array
    {
        $r = EndpointKit::db()->fetchOne('SELECT * FROM assets WHERE asset_id = ?', [$assetId]);

        return $r === null ? null : [
            'name' => (string) $r['asset_name'], 'client_id' => (int) $r['asset_client_id'], 'location_id' => (int) $r['asset_location_id'],
            'serial' => $r['asset_serial'] === null ? null : (string) $r['asset_serial'], 'model' => (string) $r['asset_model'], 'make' => (string) $r['asset_make'],
            'os' => (string) $r['asset_os'], 'type' => (string) $r['asset_type'], 'status' => (string) $r['asset_status'],
        ];
    }

    /** RivetIT-specific: RmmAssetNamesInterface (RmmReadModel::listDevices() supplies asset_name from it). */
    public function testAssetNamesIsOneBatchedLookup(): void
    {
        $adapter = $this->assets();
        $this->assertInstanceOf(\RivetCore\Rmm\Contracts\RmmAssetNamesInterface::class, $adapter);
        $a = $this->createAsset(['name' => 'names-a-' . bin2hex(random_bytes(3)), 'make' => '', 'model' => '', 'serial' => null, 'os' => '', 'client_id' => 0, 'archived' => false, 'macs' => []]);
        $b = $this->createAsset(['name' => 'names-b-' . bin2hex(random_bytes(3)), 'make' => '', 'model' => '', 'serial' => null, 'os' => '', 'client_id' => 0, 'archived' => false, 'macs' => []]);
        $names = $adapter->assetNames([$a, $b, 0, 999999999, $a]);
        $this->assertCount(2, $names);
        $this->assertStringStartsWith('names-a-', $names[$a]);
        $this->assertStringStartsWith('names-b-', $names[$b]);
        $this->assertSame([], $adapter->assetNames([]));
    }
}
