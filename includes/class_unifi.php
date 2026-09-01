<?php
/*
 * UnifiClient — server-side service for UniFi OS (UOS) Network Controller API calls.
 *
 * UniFi OS 5.x API:
 *   Base URL : https://{host}:{port}
 *   Auth     : X-API-KEY: {api_key}
 *   Sites    : GET /proxy/network/api/self/sites
 *   Devices  : GET /proxy/network/api/s/{site_id}/stat/device
 *   WLANs    : GET /proxy/network/api/s/{site_id}/rest/wlanconf
 *   Networks : GET /proxy/network/api/s/{site_id}/rest/networkconf
 */

class UnifiClient {

    private string $base_url;
    private string $api_key;
    private bool   $verify_ssl;
    private int    $integration_id;

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM unifi_integrations WHERE id=$id AND enabled=1 AND (type='local' OR type='')"
        ));
        if (!$row) {
            throw new RuntimeException("UniFi integration $id not found, disabled, or not a local controller");
        }
        $this->integration_id = $id;
        $this->base_url       = "https://" . rtrim($row['host'], '/') . ":" . intval($row['port']);
        $this->verify_ssl     = (bool) $row['verify_ssl'];
        $this->api_key        = decryptSetting($row['api_key_enc'] ?? '');
        if (empty($this->api_key)) {
            throw new RuntimeException("UniFi integration $id has no decryptable API key");
        }
    }

    private function get(string $endpoint): array {
        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => $this->verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $this->verify_ssl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->api_key,
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("UniFi API cURL error: $err");
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException("UniFi API: HTTP $status Unauthorized — check API key");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("UniFi API HTTP $status: " . substr((string) $raw, 0, 200));
        }
        $decoded = json_decode((string) $raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON from UniFi API: " . substr((string) $raw, 0, 200));
        }
        return $decoded['data'] ?? [];
    }

    // -----------------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------------

    public function testConnection(): bool {
        $this->getSites();
        return true;
    }

    // -----------------------------------------------------------------------
    // Endpoints
    // -----------------------------------------------------------------------

    public function getSites(): array {
        return $this->get('/proxy/network/api/self/sites');
    }

    public function getDevices(string $site_id): array {
        return $this->get("/proxy/network/api/s/$site_id/stat/device");
    }

    public function getWlans(string $site_id): array {
        return $this->get("/proxy/network/api/s/$site_id/rest/wlanconf");
    }

    public function getNetworkConf(string $site_id): array {
        return $this->get("/proxy/network/api/s/$site_id/rest/networkconf");
    }
}

/*
 * UnifiCloudClient — connects to the UniFi Site Manager API (api.ui.com).
 *
 * Reference: https://developer.ui.com/site-manager/v1.0.0/
 *
 * API (stable v1):
 *   Base URL : https://api.ui.com
 *   Auth     : X-API-KEY header (generated at unifi.ui.com → Settings → API Keys)
 *   Hosts    : GET /v1/hosts        — one UDM/gateway per client site
 *   Devices  : GET /v1/devices      — nested: data[].devices[] per host
 *
 * getSites() calls /v1/hosts and normalises each host to {name, desc, hostId}
 * so UnifiSyncMapper::syncSiteMappings() works without modification.
 *
 * getDevicesByHost() calls /v1/devices?hostIds[]={hostId} and flattens the
 * nested response (data[].hostId + data[].devices[]) into a flat device list
 * with consistent field names for syncCloudDevices().
 *
 * Device response fields (v1): id, mac (no colons), name, model, shortname,
 *   ip, productLine, status (online/offline), version, firmwareStatus,
 *   isConsole, isManaged, startupTime, adoptionTime, note
 *
 * NOTE: WiFi credentials and network config are not exposed by the Site Manager
 * API. A separate local controller integration is required for those.
 */
class UnifiCloudClient {

    const BASE_URL = 'https://api.ui.com';

    private string $api_key;
    private int    $integration_id;

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM unifi_integrations WHERE id=$id AND enabled=1 AND type='cloud'"
        ));
        if (!$row) {
            throw new RuntimeException("UniFi Cloud integration $id not found or disabled");
        }
        $this->integration_id = $id;
        $this->api_key        = decryptSetting($row['api_key_enc'] ?? '');
        if (empty($this->api_key)) {
            throw new RuntimeException("UniFi Cloud integration $id has no decryptable API key");
        }
    }

    private function get(string $endpoint, string $rawQuery = ''): array {
        $url = self::BASE_URL . $endpoint;
        if ($rawQuery !== '') {
            $url .= '?' . $rawQuery;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->api_key,
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("UniFi Cloud API cURL error: $err");
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException("UniFi Cloud API: HTTP $status Unauthorized — check API key");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("UniFi Cloud API HTTP $status: " . substr((string) $raw, 0, 200));
        }
        $decoded = json_decode((string) $raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON from UniFi Cloud API: " . substr((string) $raw, 0, 200));
        }
        return $decoded['data'] ?? [];
    }

    public function testConnection(): bool {
        $this->getSites();
        return true;
    }

    // Returns hosts normalised to {name, desc, hostId, directDomain, hasNetwork}.
    //
    // /v1/hosts response fields used:
    //   id                             — unique host identifier
    //   reportedState.name             — human-readable name (UniFi OS 4.x+)
    //   reportedState.hostname         — fallback display name
    //   reportedState.directConnectDomain — *.id.ui.direct proxy domain; resolves to
    //                                     the local LAN IP when queried from the same
    //                                     network, or to a cloud relay when remote
    //                                     access is enabled
    //   userData.controllers[]         — list of running apps; "network" means the
    //                                     Network controller is active
    //
    // Note: reportedState structure varies by firmware and may be null on some hosts.
    public function getSites(): array {
        $raw = $this->get('/v1/hosts');
        return array_values(array_filter(array_map(function ($s) {
            $id       = $s['id'] ?? '';
            $reported = is_array($s['reportedState'] ?? null) ? $s['reportedState'] : [];
            $userData = is_array($s['userData'] ?? null) ? $s['userData'] : [];
            $name     = $reported['name'] ?? ($reported['hostname'] ?? $id);

            $directDomain = $reported['directConnectDomain'] ?? '';
            $hasNetwork   = in_array('network', $userData['controllers'] ?? [], true);

            if ($id === '') return null;
            return [
                'name'         => $id,
                'desc'         => $name,
                'hostId'       => $id,
                'directDomain' => $directDomain,
                'hasNetwork'   => $hasNetwork,
            ];
        }, $raw)));
    }

    // Returns a flat list of devices for a given host.
    // /v1/devices response nests devices inside host groups:
    //   data: [{hostId, hostName, devices: [{id, mac, name, model, ip, ...}]}]
    // We flatten that here so syncCloudDevices() sees a simple device array.
    public function getDevicesByHost(string $hostId): array {
        $hostGroups = $this->get('/v1/devices', 'hostIds[]=' . rawurlencode($hostId));
        $devices = [];
        foreach ($hostGroups as $group) {
            foreach (($group['devices'] ?? []) as $device) {
                $devices[] = $device;
            }
        }
        return $devices;
    }

    // Fetch local Network controller data via the host's *.id.ui.direct domain.
    // These domains resolve to the device's LAN IP (when queried from the same
    // network) or to Ubiquiti's cloud relay (when remote access is enabled).
    // The same account API key is accepted by the local controller via this proxy.
    private function proxyGet(string $directDomain, string $endpoint): array {
        $url = 'https://' . $directDomain . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false, // local controllers often use self-signed certs
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->api_key,
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("cURL: $err");
        if ($status === 401 || $status === 403) throw new RuntimeException("HTTP $status — key not accepted by local controller");
        if ($status < 200 || $status >= 300) throw new RuntimeException("HTTP $status");
        $decoded = json_decode((string) $raw, true);
        if ($decoded === null && !empty($raw)) throw new RuntimeException("Invalid JSON");
        return $decoded['data'] ?? [];
    }

    public function getLocalSites(string $directDomain): array {
        return $this->proxyGet($directDomain, '/proxy/network/api/self/sites');
    }

    public function getLocalWlans(string $directDomain, string $siteId): array {
        return $this->proxyGet($directDomain, "/proxy/network/api/s/$siteId/rest/wlanconf");
    }

    public function getLocalNetworks(string $directDomain, string $siteId): array {
        return $this->proxyGet($directDomain, "/proxy/network/api/s/$siteId/rest/networkconf");
    }

    public function getLocalDevices(string $directDomain, string $siteId): array {
        return $this->proxyGet($directDomain, "/proxy/network/api/s/$siteId/stat/device");
    }
}
