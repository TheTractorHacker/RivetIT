<?php

namespace ITFlow\Workflow\Connectors;

/**
 * The identity connectors lifecycle actions can call. Out of the box every system is a NotConfiguredConnector stub (UNVERIFIED LIVE:
 * no real Active Directory, VPN, Okta or Google Workspace write code exists yet); register() swaps in a real or a mock implementation.
 */
final class ConnectorRegistry
{
    public const SYSTEMS = ['ad' => 'Active Directory', 'vpn' => 'VPN', 'okta' => 'Okta', 'google' => 'Google Workspace'];

    /** @var array<string,IdentityConnector> */
    private array $connectors = [];

    public function __construct()
    {
        foreach (self::SYSTEMS as $key => $label) {
            $this->connectors[$key] = new NotConfiguredConnector($key, $label);
        }
    }

    public function register(IdentityConnector $connector): void
    {
        if (!isset(self::SYSTEMS[$connector->key()])) {
            throw new \InvalidArgumentException('Unknown connector key ' . $connector->key());
        }
        $this->connectors[$connector->key()] = $connector;
    }

    public function get(string $key): IdentityConnector
    {
        if (!isset($this->connectors[$key])) {
            throw new \RuntimeException('Unknown identity system "' . $key . '".');
        }

        return $this->connectors[$key];
    }

    /** @return array<string,string> key => label */
    public static function labels(): array
    {
        return self::SYSTEMS;
    }
}
