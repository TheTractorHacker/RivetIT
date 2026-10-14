<?php

namespace ITFlow\Integrations\ManageEngine;

use ITFlow\Integrations\ConnectionResult;

/**
 * What the MDM sync needs from a device-management API: read-only, no write method exists. ManageEngineMdmClient is the one
 * implementation (on-premises API key and cloud Zoho OAuth); tests may substitute a fake.
 */
interface MdmClient
{
    public const SCOPE_INVENTORY = 'MDMOnDemand.MDMInventory.READ';
    public const SCOPE_DEVICE_MGMT = 'MDMOnDemand.MDMDeviceMgmt.READ';
    /** MSP edition only: lists the customers (GET customers needs MDMOnDemand.MDMAdmin.READ in the docs). */
    public const SCOPE_ADMIN = 'MDMOnDemand.MDMAdmin.READ';

    /** Proves the credentials and reads the device count. Never throws; the result carries the error. */
    public function testConnection(): ConnectionResult;

    /** @return list<array<string,mixed>> every device row of the list endpoint, paged */
    public function listAllDevices(): array;

    /** @return array<string,mixed> */
    public function getDevice(string $deviceId): array;

    /** @return array<string,mixed> */
    public function getDeviceSummary(string $deviceId): array;

    /**
     * MSP edition: the customers of this server/account. @return list<array{customer_id:string,customer_name:string}>
     */
    public function listCustomers(): array;

    /** MSP edition: scope every following request to one customer (the X-CUSTOMER header); null clears it. */
    public function setCustomer(?string $customerId): void;

    /** @return array{requests:int,retries:int,token_fetches:int,token_cache_hits:int,pages:int} */
    public function stats(): array;
}
