<?php
/*
 * SophosCentralRmmClient — server-side service for the Sophos Central API,
 * scoped to Firewall inventory + alerts.
 *
 * Auth and endpoint paths below are confirmed against Sophos's own
 * sophos-central-api-connector reference implementation (github.com/sophos/
 * sophos-central-api-connector), since developer.sophos.com is a JS-rendered
 * site that can't be statically fetched:
 *
 *   Token   : POST https://id.sophos.com/api/v2/oauth2/token
 *             (grant_type=client_credentials, scope=token)
 *   Whoami  : GET  https://api.central.sophos.com/whoami/v1
 *             -> {id, idType: tenant|organization|partner, apiHosts.dataRegion}
 *   Firewalls: GET {dataRegion}/firewall/v1/firewalls
 *   Alerts   : GET {dataRegion}/common/v1/alerts
 *
 * This client only supports a single-tenant ("idType": "tenant") credential
 * — i.e. a non-MSP Sophos Central account. Partner/Organization credentials
 * (which cover multiple customer tenants under one login) aren't handled;
 * whoami() throws a clear error if the credential resolves to one of those.
 *
 * NOTE: the exact JSON field names in the firewall/alert response bodies
 * (model, serial, health status, alert severity strings, etc.) could not be
 * verified against a live tenant — Sophos's API reference site renders
 * client-side and returned no content to static fetches. Field access below
 * is defensive (checks several plausible key names, same convention already
 * used in class_action1_rmm.php / class_level_rmm.php for similar
 * uncertainty), and the full raw payload is preserved in raw_data_json on
 * both assets and alerts so any mismatches can be diagnosed and corrected
 * quickly from real data rather than guessed twice.
 */

class SophosCentralRmmClient {

    private string $client_id;
    private string $client_secret;
    private int    $integration_id;
    private ?string $token        = null;
    private ?string $tenant_id    = null;
    private ?string $data_region  = null;

    const AUTH_URL   = 'https://id.sophos.com/api/v2/oauth2/token';
    const WHOAMI_URL = 'https://api.central.sophos.com/whoami/v1';

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM rmm_integrations WHERE id=$id AND enabled=1 AND type='sophos_central'"
        ));
        if (!$row) {
            throw new RuntimeException("Sophos Central integration $id not found or disabled");
        }
        $this->integration_id = $id;

        $creds = json_decode(decryptSetting($row['api_key_enc'] ?? ''), true);
        $this->client_id     = $creds['client_id'] ?? '';
        $this->client_secret = $creds['client_secret'] ?? '';
        if (empty($this->client_id) || empty($this->client_secret)) {
            throw new RuntimeException("Sophos Central integration $id has no decryptable credentials");
        }
    }

    private function getToken(): string {
        if ($this->token !== null) {
            return $this->token;
        }
        $ch = curl_init(self::AUTH_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'scope'         => 'token',
            ]),
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Sophos OAuth cURL error: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Sophos OAuth returned HTTP $status — check Client ID/Secret");
        }
        $decoded = json_decode($raw, true);
        $token   = $decoded['access_token'] ?? null;
        if (!$token) {
            throw new RuntimeException("Sophos OAuth response missing access_token");
        }
        $this->token = $token;
        return $token;
    }

    // Resolves the tenant ID + regional API host for this credential.
    // Required before any /firewall or /common call — Sophos splits tenant
    // data across regional hosts (e.g. api-us01.central.sophos.com) rather
    // than serving it from the global api.central.sophos.com entry point.
    private function resolveTenant(): void {
        if ($this->data_region !== null) return;

        $ch = curl_init(self::WHOAMI_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->getToken()],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Sophos whoami cURL error: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Sophos whoami returned HTTP $status");
        }
        $decoded = json_decode($raw, true);
        $id_type = $decoded['idType'] ?? '';

        if ($id_type !== 'tenant') {
            throw new RuntimeException(
                "This Sophos credential is a '$id_type' account, not a single tenant. " .
                "Partner/Organization (multi-customer MSP) credentials aren't supported by this integration yet."
            );
        }

        $this->tenant_id   = $decoded['id'] ?? '';
        $this->data_region = rtrim($decoded['apiHosts']['dataRegion'] ?? '', '/');
        if (empty($this->tenant_id) || empty($this->data_region)) {
            throw new RuntimeException("Sophos whoami response missing tenant id or data region");
        }
    }

    private function get(string $endpoint, array $params = []): array {
        $this->resolveTenant();
        $url = $this->data_region . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->getToken(),
                'X-Tenant-ID: ' . $this->tenant_id,
                'Accept: application/json',
            ],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Sophos API cURL error: $err");
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException("Sophos API: HTTP $status Unauthorized — check Client ID/Secret and that the Auth Role is enabled for this credential");
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $msg     = $decoded['message'] ?? substr((string) $raw, 0, 200);
            throw new RuntimeException("Sophos API HTTP $status: $msg");
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON from Sophos API: " . substr($raw, 0, 200));
        }
        return $decoded ?? [];
    }

    private function paginate(string $endpoint, array $params = []): array {
        $items  = [];
        $params['pageSize'] = $params['pageSize'] ?? 100;
        $cursor = null;
        do {
            if ($cursor) { $params['pageFromKey'] = $cursor; }
            $data    = $this->get($endpoint, $params);
            $batch   = $data['items'] ?? $data['data'] ?? [];
            $items   = array_merge($items, $batch);
            $cursor  = $data['pages']['nextKey'] ?? $data['pages']['fromKey'] ?? null;
        } while (!empty($cursor) && !empty($batch));
        return $items;
    }

    // -----------------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------------

    public function testConnection(): bool {
        $this->resolveTenant();
        return !empty($this->tenant_id);
    }

    // -----------------------------------------------------------------------
    // Firewalls (treated as "agents" so RmmAssetMapper can sync them like
    // any other RMM-managed device)
    // -----------------------------------------------------------------------

    public function getAgents(): array {
        $firewalls = $this->paginate('/firewall/v1/firewalls');
        return array_map([$this, 'normalizeFirewall'], $firewalls);
    }

    public function getAgent(string $agent_id): array {
        $fw = $this->get('/firewall/v1/firewalls/' . urlencode($agent_id));
        return $this->normalizeFirewall($fw);
    }

    private function normalizeFirewall(array $fw): array {
        // status may be a string ("good"/"bad") or an object {"connected":true,"ipAddress":"..."}
        $status_field = $fw['status'] ?? $fw['health'] ?? $fw['healthStatus'] ?? '';
        $ip_from_status = '';
        if (is_array($status_field)) {
            $ip_from_status = (string) ($status_field['ipAddress'] ?? '');
            $status_raw     = !empty($status_field['connected']) ? 'connected' : 'disconnected';
        } else {
            $status_raw = strtolower((string) $status_field);
        }

        $status = match (true) {
            str_contains($status_raw, 'good'), str_contains($status_raw, 'online'), str_contains($status_raw, 'connected') => 'online',
            str_contains($status_raw, 'bad'), str_contains($status_raw, 'offline'), str_contains($status_raw, 'disconnected') => 'offline',
            default => 'unknown',
        };

        $ip = $ip_from_status ?: (string) ($fw['ipAddress'] ?? $fw['wanIp'] ?? '');

        return [
            'agent_id'         => (string) ($fw['id'] ?? ''),
            'hostname'         => (string) ($fw['name'] ?? $fw['hostname'] ?? $fw['id'] ?? ''),
            'operating_system' => 'Sophos Firewall OS',
            'os_version'       => (string) ($fw['firmwareVersion'] ?? $fw['firmware'] ?? ''),
            'manufacturer'     => 'Sophos',
            'make_model'       => 'Sophos',
            'model'            => (string) ($fw['model'] ?? ''),
            'serial_number'    => (string) ($fw['serialNumber'] ?? $fw['serial'] ?? ''),
            'status'           => $status,
            'last_seen'        => (string) ($fw['lastSeenAt'] ?? $fw['lastSeen'] ?? ''),
            'local_ips'        => $ip,
        ];
    }

    // -----------------------------------------------------------------------
    // Hardware/software/services detail — not applicable to firewalls
    // -----------------------------------------------------------------------

    public function getAgentWmi(string $agent_id): array {
        return [];
    }

    public function getAgentSoftware(string $agent_id): array {
        return [];
    }

    public function getAgentServices(string $agent_id): array {
        return [];
    }

    // -----------------------------------------------------------------------
    // Alerts
    // -----------------------------------------------------------------------

    // Returns tenant-wide alerts filtered to ones we can tie back to a known
    // firewall ID (from getAgents()) — Sophos Central's alert feed spans all
    // products (endpoint, email, firewall, etc.), and this integration is
    // scoped to firewalls only.
    public function getAlerts(bool $resolved = false): array {
        try {
            $items = $this->paginate('/common/v1/alerts');
            $out   = [];
            foreach ($items as $a) {
                $device_id = (string) (
                    $a['managedAgent']['id'] ?? $a['source']['id'] ?? $a['asset']['id'] ?? $a['id'] ?? ''
                );

                $sev = strtolower((string) ($a['severity'] ?? 'info'));
                $out[] = [
                    'id'          => (string) ($a['id'] ?? ''),
                    'agent_id'    => $device_id,
                    'device_id'   => $device_id,
                    'severity'    => in_array($sev, ['critical', 'error', 'warning', 'info']) ? $sev : 'info',
                    'message'     => (string) ($a['description'] ?? $a['message'] ?? 'Sophos Central Alert'),
                    'resolved'    => !empty($a['acknowledged']) || strtolower((string) ($a['status'] ?? '')) === 'resolved',
                ];
            }
            return $out;
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function getAgentAlerts(string $agent_id): array {
        return array_values(array_filter($this->getAlerts(), fn($a) => $a['agent_id'] === $agent_id));
    }

    // -----------------------------------------------------------------------
    // URL helpers
    // -----------------------------------------------------------------------

    public function buildDeviceUrl(string $agent_id): string {
        return 'https://cloud.sophos.com/manage/network-protection/firewall/devices/' . urlencode($agent_id);
    }
}
