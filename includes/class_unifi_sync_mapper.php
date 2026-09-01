<?php
/*
 * UnifiSyncMapper — syncs a UniFi controller's sites to ITFlow.
 *
 * For each UniFi site, the site's display name (`desc`) is matched
 * case-insensitively to an ITFlow client name. Sites with no matching
 * client are skipped entirely.
 *
 * For matched sites:
 *   - UAP/USW devices  -> assets (+ primary asset_interfaces entry)
 *   - WLANs with a PSK -> credentials named "Wi-Fi: {ssid}"
 *   - networkconf      -> networks (one UniFi network -> one ITFlow network row)
 */

class UnifiSyncMapper {

    private $mysqli;
    private int $integration_id;
    private int $triggered_by;

    // UniFi device type prefix -> ITFlow asset_type. Must be 'Firewall/Router'
    // (not 'Firewall') to match the string agent/network.php and every other
    // Firewalls-list consumer actually filters assets.asset_type on.
    private const TYPE_MAP = [
        'UAP' => 'Access Point',
        'USW' => 'Switch',
        'UGW' => 'Firewall/Router', // USG (older "UniFi Security Gateway")
        'UDM' => 'Firewall/Router', // Dream Machine / Dream Machine Pro / SE
        'UXG' => 'Firewall/Router', // Next-Gen Gateway (Lite/Pro/Fiber)
        'UDR' => 'Firewall/Router', // Dream Router
        'UCG' => 'Firewall/Router', // Cloud Gateway (Ultra/Fiber/Max)
    ];

    // Device types this sync imports as assets. Gateways are included so a
    // local-controller-connected UDM/USG/UXG/UDR/UCG (e.g. a Dream Machine
    // acting as the site's own controller) shows up as a Firewall/Router
    // asset - previously only UAP/USW synced locally, so a gateway on a
    // local integration was silently skipped even though the same device
    // synced fine via a cloud integration.
    private const SYNC_DEVICE_TYPES = ['UAP', 'USW', 'UGW', 'UDM', 'UXG', 'UDR', 'UCG'];

    public function __construct($mysqli, int $integration_id, int $triggered_by = 0) {
        $this->mysqli         = $mysqli;
        $this->integration_id = $integration_id;
        $this->triggered_by   = $triggered_by;
    }

    // -------------------------------------------------------------------
    // Cloud (api.ui.com) sync
    //
    // Devices come from /v1/devices (cloud API — always available).
    //
    // WiFi credentials and networks require the local Network controller API.
    // We reach it via the host's directConnectDomain (*.id.ui.direct), which
    // resolves to the device's LAN IP when queried from the same network, or
    // routes through Ubiquiti's cloud relay when remote access is enabled.
    // If the domain doesn't resolve or the controller rejects the key, WiFi/
    // network sync is skipped for that host and the error is logged.
    // -------------------------------------------------------------------

    public function syncCloud(UnifiCloudClient $client): array {
        $stats = [
            'devices_created' => 0, 'devices_updated' => 0, 'devices_matched' => 0, 'devices_skipped' => 0,
            'wifi_created' => 0, 'wifi_updated' => 0, 'wifi_skipped' => 0,
            'networks_created' => 0, 'networks_updated' => 0, 'networks_skipped' => 0,
            'errors' => [],
        ];

        $sites = $client->getSites();
        if (empty($sites)) {
            $stats['errors'][] = 'No UniFi hosts returned from cloud API';
            return $stats;
        }

        $canonical_key = getCanonicalVaultKey($this->mysqli);
        $synced_hosts  = [];

        foreach ($sites as $site) {
            $site_id      = $site['name'] ?? '';
            $site_display = $site['desc'] ?? $site_id;
            $host_id      = $site['hostId'] ?? '';
            $direct_domain = $site['directDomain'] ?? '';
            $has_network  = $site['hasNetwork'] ?? false;

            if ($site_id === '' || $host_id === '') continue;

            $this->ensureSiteMapping($site_id, $site_display);
            $client_id = $this->resolveSiteClientId($site_id, $site_display);
            if (!$client_id) continue;

            if (isset($synced_hosts[$host_id])) continue;
            $synced_hosts[$host_id] = true;

            // Devices via cloud API (always)
            try {
                $devices = $client->getDevicesByHost($host_id);
                $this->syncCloudDevices($devices, $client_id, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = "Site $site_display devices: " . $e->getMessage();
            }

            // WiFi + networks via local controller proxy (when directDomain is available)
            if ($direct_domain !== '' && $has_network) {
                try {
                    $localSites   = $client->getLocalSites($direct_domain);
                    $localSiteId  = $localSites[0]['name'] ?? 'default';

                    $wlans = $client->getLocalWlans($direct_domain, $localSiteId);
                    $this->syncWlans($wlans, $client_id, $site_display, $canonical_key, $stats);

                    $networks = $client->getLocalNetworks($direct_domain, $localSiteId);
                    $this->syncNetworks($networks, $client_id, $stats);
                } catch (\Throwable $e) {
                    // Silently skip — proxy unreachable means controller is at a
                    // different location or doesn't have remote access enabled.
                }
            }
        }

        return $stats;
    }

    private function syncCloudDevices(array $devices, int $client_id, array &$stats): void {
        $m = $this->mysqli;

        $existing_assets = [];
        $res = mysqli_query($m, "SELECT asset_id, asset_serial, asset_name FROM assets WHERE asset_client_id=$client_id AND asset_archived_at IS NULL");
        while ($row = mysqli_fetch_assoc($res)) {
            $existing_assets[] = $row;
        }

        foreach ($devices as $device) {
            // v1 API fields: shortname (e.g. UDMPROSE, UAP6PRO), productLine (network/protect/etc.)
            // Use shortname for type detection, falling back to productLine.
            $raw_type   = strtoupper((string) ($device['shortname'] ?? $device['productLine'] ?? ''));
            $asset_type = 'Network Device';
            foreach (self::TYPE_MAP as $prefix => $label) {
                if ($raw_type === $prefix || str_starts_with($raw_type, $prefix)) {
                    $asset_type = $label;
                    break;
                }
            }

            // shortname is known to lag for newly-released Cloud Gateway SKUs,
            // and productLine ("network") is shared by APs/switches/gateways
            // alike, so if neither resolved a specific type, fall back to
            // `model` (the marketing name, e.g. "UCG-Max", "UDM-SE") which
            // Ubiquiti populates more reliably/promptly for new hardware.
            if ($asset_type === 'Network Device') {
                $raw_model = strtoupper((string) ($device['model'] ?? ''));
                if ($raw_model !== '') {
                    foreach (self::TYPE_MAP as $prefix => $label) {
                        if ($raw_model === $prefix || str_starts_with($raw_model, $prefix)) {
                            $asset_type = $label;
                            break;
                        }
                    }
                }
            }

            // isConsole is a positive, naming-independent signal from the Site
            // Manager API that this device IS the site's console/gateway.
            // Trust it outright — it forces the classification even if
            // shortname/model/productLine gave no usable signal above.
            if (!empty($device['isConsole'])) {
                $asset_type = 'Firewall/Router';
            }

            $name       = (string) ($device['name'] ?? $device['mac'] ?? 'Unknown');
            $model      = (string) ($device['model'] ?? $device['shortname'] ?? '');
            // MAC comes without colons from v1 API (e.g. "F4E2C6C23F13")
            $mac_raw    = strtoupper((string) ($device['mac'] ?? ''));
            $mac_colon  = preg_replace('/([0-9A-F]{2})(?=[0-9A-F])/', '$1:', $mac_raw);
            $mac_norm   = $mac_raw; // already normalised (no colons, uppercase)
            $ip         = (string) ($device['ip'] ?? '');
            $serial     = (string) ($device['serial'] ?? '');
            $firmware   = (string) ($device['version'] ?? '');
            $status_raw = (string) ($device['status'] ?? 'unknown');
            $status     = $status_raw === 'online' ? 'Deployed' : 'Spare';

            $notes = sprintf(
                "UniFi Cloud | MAC: %s | Model: %s | Firmware: %s | Status: %s | Last synced: %s",
                $mac_colon, $model, $firmware, $status_raw, date('Y-m-d H:i')
            );

            $existing = $this->findExistingAsset($existing_assets, $serial, $name, $mac_norm);

            $name_esc   = mysqli_real_escape_string($m, sanitizeInput($name));
            $type_esc   = mysqli_real_escape_string($m, $asset_type);
            $model_esc  = mysqli_real_escape_string($m, sanitizeInput($model));
            $serial_esc = mysqli_real_escape_string($m, sanitizeInput($serial));
            $notes_esc  = mysqli_real_escape_string($m, $notes);
            $mac_esc    = mysqli_real_escape_string($m, $mac_colon); // store with colons for readability
            $ip_esc     = mysqli_real_escape_string($m, $ip);

            if ($existing) {
                $asset_id = intval($existing['asset_id']);
                mysqli_query($m,
                    "UPDATE assets SET
                     asset_name='$name_esc', asset_type='$type_esc', asset_make='Ubiquiti',
                     asset_model='$model_esc', asset_serial='$serial_esc',
                     asset_status='$status', asset_notes='$notes_esc'
                     WHERE asset_id=$asset_id"
                );
                $this->upsertPrimaryInterface($asset_id, $mac_esc, $ip_esc);
                $stats['devices_updated']++;
            } else {
                mysqli_query($m,
                    "INSERT INTO assets SET
                     asset_name='$name_esc', asset_type='$type_esc', asset_make='Ubiquiti',
                     asset_model='$model_esc', asset_serial='$serial_esc',
                     asset_status='$status', asset_notes='$notes_esc',
                     asset_client_id=$client_id, asset_created_at=NOW()"
                );
                $asset_id = intval(mysqli_insert_id($m));
                $this->upsertPrimaryInterface($asset_id, $mac_esc, $ip_esc);
                $existing_assets[] = ['asset_id' => $asset_id, 'asset_serial' => $serial, 'asset_name' => $name];
                $stats['devices_created']++;
            }
        }
    }

    // -------------------------------------------------------------------
    // Local controller sync
    // -------------------------------------------------------------------

    public function sync(UnifiClient $client): array {
        $stats = [
            'devices_created' => 0, 'devices_updated' => 0, 'devices_matched' => 0, 'devices_skipped' => 0,
            'wifi_created' => 0, 'wifi_updated' => 0, 'wifi_skipped' => 0,
            'networks_created' => 0, 'networks_updated' => 0, 'networks_skipped' => 0,
            'errors' => [],
        ];

        $sites = $client->getSites();
        if (empty($sites)) {
            $stats['errors'][] = 'No UniFi sites returned';
            return $stats;
        }

        $canonical_key = getCanonicalVaultKey($this->mysqli);

        foreach ($sites as $site) {
            $site_id      = $site['name'] ?? '';
            $site_display = $site['desc'] ?? $site_id;
            if (empty($site_id)) continue;

            $this->ensureSiteMapping($site_id, $site_display);
            $client_id = $this->resolveSiteClientId($site_id, $site_display);
            if (!$client_id) {
                continue;
            }

            try {
                $devices = $client->getDevices($site_id);
                $this->syncDevices($devices, $client_id, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = "Site $site_display devices: " . $e->getMessage();
            }

            try {
                $wlans = $client->getWlans($site_id);
                $this->syncWlans($wlans, $client_id, $site_display, $canonical_key, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = "Site $site_display WLANs: " . $e->getMessage();
            }

            try {
                $networks = $client->getNetworkConf($site_id);
                $this->syncNetworks($networks, $client_id, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = "Site $site_display networks: " . $e->getMessage();
            }
        }

        return $stats;
    }

    // -------------------------------------------------------------------
    // Site -> Client matching
    // -------------------------------------------------------------------

    private function resolveClientIdByName(string $site_display): int {
        $m   = $this->mysqli;
        $esc = mysqli_real_escape_string($m, trim($site_display));
        if ($esc === '') return 0;
        $row = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM clients WHERE LOWER(client_name)=LOWER('$esc') AND client_archived_at IS NULL LIMIT 1"
        ));
        return $row ? intval($row['client_id']) : 0;
    }

    // Creates the unifi_site_mappings row for this site if it doesn't exist
    // yet (client_id starts as NULL, meaning "auto-match by name").
    private function ensureSiteMapping(string $site_id, string $site_display): void {
        $m            = $this->mysqli;
        $site_id_esc  = mysqli_real_escape_string($m, $site_id);
        $name_esc     = mysqli_real_escape_string($m, $site_display);
        mysqli_query($m,
            "INSERT INTO unifi_site_mappings (integration_id, unifi_site_id, unifi_site_name) VALUES ({$this->integration_id}, '$site_id_esc', '$name_esc')
             ON DUPLICATE KEY UPDATE unifi_site_name='$name_esc'"
        );
    }

    // Resolves the ITFlow client for a UniFi site: an explicit mapping
    // (including an explicit "skip" of client_id=0) takes priority, falling
    // back to a case-insensitive name match if no mapping has been set.
    private function resolveSiteClientId(string $site_id, string $site_display): int {
        $m           = $this->mysqli;
        $site_id_esc = mysqli_real_escape_string($m, $site_id);
        $row = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM unifi_site_mappings WHERE integration_id={$this->integration_id} AND unifi_site_id='$site_id_esc' LIMIT 1"
        ));
        if ($row && $row['client_id'] !== null) {
            return intval($row['client_id']);
        }
        return $this->resolveClientIdByName($site_display);
    }

    // Used by the admin UI to (re)populate site mappings from the live
    // controller and return the current mapping + auto-match for each site.
    public function syncSiteMappings(array $sites): array {
        foreach ($sites as $site) {
            $site_id      = $site['name'] ?? '';
            $site_display = $site['desc'] ?? $site_id;
            if (empty($site_id)) continue;
            $this->ensureSiteMapping($site_id, $site_display);
        }

        $m   = $this->mysqli;
        $res = mysqli_query($m,
            "SELECT sm.unifi_site_id, sm.unifi_site_name, sm.client_id, c.client_name AS mapped_client_name,
                    ac.client_id AS auto_client_id, ac.client_name AS auto_client_name
             FROM unifi_site_mappings sm
             LEFT JOIN clients c ON c.client_id = sm.client_id
             LEFT JOIN clients ac ON LOWER(ac.client_name) = LOWER(sm.unifi_site_name) AND ac.client_archived_at IS NULL
             WHERE sm.integration_id={$this->integration_id}
             ORDER BY sm.unifi_site_name ASC"
        );
        $out = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $out[] = $row;
        }
        return $out;
    }

    // -------------------------------------------------------------------
    // Devices -> assets
    // -------------------------------------------------------------------

    private function syncDevices(array $devices, int $client_id, array &$stats): void {
        $m = $this->mysqli;

        $existing_assets = [];
        $res = mysqli_query($m, "SELECT asset_id, asset_serial, asset_name FROM assets WHERE asset_client_id=$client_id AND asset_archived_at IS NULL");
        while ($row = mysqli_fetch_assoc($res)) {
            $existing_assets[] = $row;
        }

        foreach ($devices as $device) {
            $raw_type = strtoupper((string) ($device['type'] ?? ''));
            $is_sync_type = false;
            foreach (self::SYNC_DEVICE_TYPES as $prefix) {
                if ($raw_type === $prefix || str_starts_with($raw_type, $prefix)) {
                    $is_sync_type = true;
                    break;
                }
            }
            if (!$is_sync_type) {
                $stats['devices_skipped']++;
                continue;
            }

            $asset_type = 'Network Device';
            foreach (self::TYPE_MAP as $prefix => $label) {
                if ($raw_type === $prefix || str_starts_with($raw_type, $prefix)) {
                    $asset_type = $label;
                    break;
                }
            }

            $name      = (string) ($device['name'] ?? $device['mac'] ?? 'Unknown');
            $model     = (string) ($device['model_name'] ?? $device['model'] ?? '');
            $mac_raw   = (string) ($device['mac'] ?? '');
            $mac_norm  = strtoupper(str_replace(':', '', $mac_raw));
            $ip        = (string) ($device['ip'] ?? '');
            $serial    = (string) ($device['serial'] ?? '');
            $firmware  = (string) ($device['version'] ?? '');
            $state     = intval($device['state'] ?? 0);
            $status    = $state === 1 ? 'Deployed' : 'Spare';

            $uptime_sec = intval($device['uptime'] ?? 0);
            $uptime_str = $uptime_sec ? (intdiv($uptime_sec, 86400) . 'd') : 'unknown';

            $notes = sprintf(
                "UniFi Device | MAC: %s | Firmware: %s | Uptime: %s | State: %s | Last synced: %s",
                $mac_raw, $firmware, $uptime_str, $state === 1 ? 'Connected' : 'Disconnected', date('Y-m-d H:i')
            );

            $existing = $this->findExistingAsset($existing_assets, $serial, $name, $mac_norm);

            $name_esc   = mysqli_real_escape_string($m, sanitizeInput($name));
            $type_esc   = mysqli_real_escape_string($m, $asset_type);
            $model_esc  = mysqli_real_escape_string($m, sanitizeInput($model));
            $serial_esc = mysqli_real_escape_string($m, sanitizeInput($serial));
            $notes_esc  = mysqli_real_escape_string($m, $notes);
            $mac_esc    = mysqli_real_escape_string($m, $mac_raw);
            $ip_esc     = mysqli_real_escape_string($m, $ip);

            if ($existing) {
                $asset_id = intval($existing['asset_id']);
                mysqli_query($m,
                    "UPDATE assets SET
                     asset_name='$name_esc', asset_type='$type_esc', asset_make='Ubiquiti',
                     asset_model='$model_esc', asset_serial='$serial_esc',
                     asset_status='$status', asset_notes='$notes_esc'
                     WHERE asset_id=$asset_id"
                );
                $this->upsertPrimaryInterface($asset_id, $mac_esc, $ip_esc);
                $stats['devices_updated']++;
            } else {
                mysqli_query($m,
                    "INSERT INTO assets SET
                     asset_name='$name_esc', asset_type='$type_esc', asset_make='Ubiquiti',
                     asset_model='$model_esc', asset_serial='$serial_esc',
                     asset_status='$status', asset_notes='$notes_esc',
                     asset_client_id=$client_id, asset_created_at=NOW()"
                );
                $asset_id = intval(mysqli_insert_id($m));
                $this->upsertPrimaryInterface($asset_id, $mac_esc, $ip_esc);
                $existing_assets[] = ['asset_id' => $asset_id, 'asset_serial' => $serial, 'asset_name' => $name];
                $stats['devices_created']++;
            }
        }
    }

    private function findExistingAsset(array $existing_assets, string $serial, string $name, string $mac_norm): ?array {
        $serial = trim(strtoupper($serial));
        $name   = trim(strtolower($name));

        if ($serial !== '') {
            foreach ($existing_assets as $a) {
                if (trim(strtoupper((string) ($a['asset_serial'] ?? ''))) === $serial) {
                    return $a;
                }
            }
        }

        if ($mac_norm !== '') {
            $m   = $this->mysqli;
            $row = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT a.asset_id, a.asset_serial, a.asset_name FROM assets a
                 JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id
                 WHERE REPLACE(UPPER(ai.interface_mac), ':', '')='$mac_norm' AND a.asset_archived_at IS NULL LIMIT 1"
            ));
            if ($row) return $row;
        }

        if ($name !== '') {
            foreach ($existing_assets as $a) {
                if (trim(strtolower((string) ($a['asset_name'] ?? ''))) === $name) {
                    return $a;
                }
            }
        }

        return null;
    }

    private function upsertPrimaryInterface(int $asset_id, string $mac_esc, string $ip_esc): void {
        $m = $this->mysqli;
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT interface_id FROM asset_interfaces WHERE interface_asset_id=$asset_id AND interface_primary=1 LIMIT 1"
        ));
        if ($existing) {
            mysqli_query($m,
                "UPDATE asset_interfaces SET interface_mac='$mac_esc', interface_ip='$ip_esc' WHERE interface_id={$existing['interface_id']}"
            );
        } else {
            mysqli_query($m,
                "INSERT INTO asset_interfaces SET interface_name='1', interface_mac='$mac_esc', interface_ip='$ip_esc', interface_primary=1, interface_asset_id=$asset_id"
            );
        }
    }

    // -------------------------------------------------------------------
    // WLANs -> credentials
    // -------------------------------------------------------------------

    private function syncWlans(array $wlans, int $client_id, string $site_display, ?string $canonical_key, array &$stats): void {
        $m = $this->mysqli;

        if (empty($wlans)) return;

        if ($canonical_key === null) {
            $stats['errors'][] = "Site $site_display: no canonical vault key established — skipping Wi-Fi credential sync (set this up in Admin > Settings > Security)";
            $stats['wifi_skipped'] += count($wlans);
            return;
        }

        foreach ($wlans as $wlan) {
            $ssid       = trim((string) ($wlan['name'] ?? ''));
            $passphrase = trim((string) ($wlan['x_passphrase'] ?? ''));
            $enabled    = (bool) ($wlan['enabled'] ?? true);

            if ($ssid === '' || $passphrase === '' || !$enabled) {
                $stats['wifi_skipped']++;
                continue;
            }

            $security = (string) ($wlan['security'] ?? 'wpapsk');
            $vlan_id  = $wlan['vlan'] ?? '';

            $notes = sprintf(
                "UniFi SSID | Site: %s | Security: %s | VLAN: %s | Last synced: %s",
                $site_display, strtoupper($security), $vlan_id !== '' ? $vlan_id : 'None', date('Y-m-d H:i')
            );

            $cred_name = "Wi-Fi: $ssid";
            $name_esc  = mysqli_real_escape_string($m, $cred_name);
            $ssid_esc  = mysqli_real_escape_string($m, sanitizeInput($ssid));
            $notes_esc = mysqli_real_escape_string($m, $notes);

            $existing = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT credential_id, credential_password FROM credentials
                 WHERE credential_client_id=$client_id AND credential_name='$name_esc' AND credential_archived_at IS NULL LIMIT 1"
            ));

            if ($existing) {
                $current_pw = $existing['credential_password'] !== null
                    ? decryptCredentialEntryWithKey($existing['credential_password'], $canonical_key)
                    : '';
                if ($current_pw === $passphrase) {
                    $stats['wifi_skipped']++;
                    continue;
                }
                $enc_pw = mysqli_real_escape_string($m, encryptCredentialEntryWithKey($passphrase, $canonical_key));
                mysqli_query($m,
                    "UPDATE credentials SET credential_username='$ssid_esc', credential_password='$enc_pw', credential_note='$notes_esc', credential_password_changed_at=NOW()
                     WHERE credential_id={$existing['credential_id']}"
                );
                $stats['wifi_updated']++;
            } else {
                $enc_pw = mysqli_real_escape_string($m, encryptCredentialEntryWithKey($passphrase, $canonical_key));
                mysqli_query($m,
                    "INSERT INTO credentials SET
                     credential_name='$name_esc', credential_username='$ssid_esc', credential_password='$enc_pw',
                     credential_note='$notes_esc', credential_client_id=$client_id"
                );
                $stats['wifi_created']++;
            }
        }
    }

    // -------------------------------------------------------------------
    // networkconf -> networks
    // -------------------------------------------------------------------

    private function syncNetworks(array $networks, int $client_id, array &$stats): void {
        $m = $this->mysqli;

        foreach ($networks as $net) {
            $purpose = (string) ($net['purpose'] ?? '');
            if (in_array($purpose, ['wan', 'vpn'], true)) {
                $stats['networks_skipped']++;
                continue;
            }

            $name      = trim((string) ($net['name'] ?? ''));
            $ip_subnet = trim((string) ($net['ip_subnet'] ?? ''));
            if ($name === '' || $ip_subnet === '' || !str_contains($ip_subnet, '/')) {
                $stats['networks_skipped']++;
                continue;
            }

            [$gateway, $prefix] = explode('/', $ip_subnet, 2);
            $cidr = $this->computeNetworkCidr($gateway, (int) $prefix);

            $vlan = (!empty($net['vlan_enabled']) && isset($net['vlan'])) ? intval($net['vlan']) : 'NULL';

            $dhcp_range = '';
            if (!empty($net['dhcpd_enabled']) && !empty($net['dhcpd_start']) && !empty($net['dhcpd_stop'])) {
                $dhcp_range = $net['dhcpd_start'] . '-' . $net['dhcpd_stop'];
            }

            $primary_dns   = '';
            $secondary_dns = '';
            if (!empty($net['dhcpd_dns_enabled'])) {
                $primary_dns   = (string) ($net['dhcpd_dns_1'] ?? '');
                $secondary_dns = (string) ($net['dhcpd_dns_2'] ?? '');
            }

            $name_esc        = mysqli_real_escape_string($m, sanitizeInput($name));
            $cidr_esc        = mysqli_real_escape_string($m, $cidr);
            $gateway_esc     = mysqli_real_escape_string($m, $gateway);
            $dhcp_range_esc  = mysqli_real_escape_string($m, $dhcp_range);
            $primary_dns_esc = mysqli_real_escape_string($m, $primary_dns);
            $secondary_dns_esc = mysqli_real_escape_string($m, $secondary_dns);
            $notes_esc       = mysqli_real_escape_string($m, "Synced from UniFi controller. Last synced: " . date('Y-m-d H:i'));

            $existing = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT network_id FROM networks WHERE network_client_id=$client_id AND network_name='$name_esc' AND network_archived_at IS NULL LIMIT 1"
            ));

            if ($existing) {
                mysqli_query($m,
                    "UPDATE networks SET
                     network_vlan=$vlan, network='$cidr_esc', network_gateway='$gateway_esc',
                     network_primary_dns='$primary_dns_esc', network_secondary_dns='$secondary_dns_esc',
                     network_dhcp_range='$dhcp_range_esc', network_notes='$notes_esc'
                     WHERE network_id={$existing['network_id']}"
                );
                $stats['networks_updated']++;
            } else {
                mysqli_query($m,
                    "INSERT INTO networks SET
                     network_name='$name_esc', network_vlan=$vlan, network='$cidr_esc', network_gateway='$gateway_esc',
                     network_primary_dns='$primary_dns_esc', network_secondary_dns='$secondary_dns_esc',
                     network_dhcp_range='$dhcp_range_esc', network_notes='$notes_esc', network_client_id=$client_id"
                );
                $stats['networks_created']++;
            }
        }
    }

    // -------------------------------------------------------------------
    // Sync log
    // -------------------------------------------------------------------

    public function startSyncLog(): int {
        $m = $this->mysqli;
        mysqli_query($m,
            "INSERT INTO unifi_sync_log SET integration_id={$this->integration_id}, triggered_by={$this->triggered_by}"
        );
        return intval(mysqli_insert_id($m));
    }

    public function finishSyncLog(int $log_id, array $stats): void {
        $m      = $this->mysqli;
        $status = empty($stats['errors']) ? 'success' : 'failed';
        $errors = mysqli_real_escape_string($m, implode('; ', $stats['errors']));
        mysqli_query($m,
            "UPDATE unifi_sync_log SET
             finished_at=NOW(), status='$status',
             devices_created={$stats['devices_created']}, devices_updated={$stats['devices_updated']},
             devices_matched={$stats['devices_matched']}, devices_skipped={$stats['devices_skipped']},
             wifi_created={$stats['wifi_created']}, wifi_updated={$stats['wifi_updated']}, wifi_skipped={$stats['wifi_skipped']},
             networks_created={$stats['networks_created']}, networks_updated={$stats['networks_updated']}, networks_skipped={$stats['networks_skipped']},
             errors='$errors'
             WHERE id=$log_id"
        );
    }

    // UniFi's ip_subnet is "{gateway_ip}/{prefix}" — compute the network
    // address (e.g. 192.168.1.1/24 -> 192.168.1.0/24) for IPv4. IPv6 and
    // unparsable values are passed through unchanged.
    private function computeNetworkCidr(string $gateway, int $prefix): string {
        $long = ip2long($gateway);
        if ($long === false || $prefix < 0 || $prefix > 32) {
            return "$gateway/$prefix";
        }
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
        $network = long2ip($long & $mask);
        return "$network/$prefix";
    }
}
