<?php
/*
 * Action1RmmClient — server-side service for the Action1 patch management API.
 * OAuth2 client credentials are decrypted once in the constructor and never
 * exposed to the browser.
 *
 * Action1's API has no concept of a single "agent" lookup — endpoints only
 * exist within an organization's endpoint groups. We therefore use a
 * composite agent_id of "{org_id}:{endpoint_id}" so the rest of ITFlow can
 * treat Action1 endpoints the same as Tactical/Level agents.
 */

class Action1RmmClient {

    private string $base_url;
    private string $web_url;
    private string $client_id;
    private string $client_secret;
    private int $integration_id;
    private ?string $token = null;

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM rmm_integrations WHERE id=$id AND enabled=1"));
        if (!$row) {
            throw new RuntimeException("RMM integration $id not found or disabled");
        }
        $this->integration_id = $id;
        $this->base_url = rtrim($row['api_url'] ?: 'https://app.action1.com/api/3.0', '/');
        $this->web_url  = rtrim($row['web_url'] ?: 'https://app.action1.com', '/');

        $creds = json_decode(decryptSetting($row['api_key_enc'] ?? ''), true);
        $this->client_id     = $creds['client_id'] ?? '';
        $this->client_secret = $creds['client_secret'] ?? '';
        if (empty($this->client_id) || empty($this->client_secret)) {
            throw new RuntimeException("RMM integration $id has no decryptable Action1 credentials");
        }
    }

    private function getToken(): string {
        if ($this->token !== null) {
            return $this->token;
        }
        $ch = curl_init($this->base_url . '/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
            ]),
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL error: $err");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Action1 OAuth returned HTTP $status");
        }
        $decoded = json_decode($raw, true);
        $token   = $decoded['access_token'] ?? null;
        if (!$token) {
            throw new RuntimeException("Action1 OAuth response missing access_token");
        }
        $this->token = $token;
        return $token;
    }

    private function get(string $endpoint, array $params = []): array {
        $url = $this->base_url . $endpoint;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->getToken()],
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL error: $err");
        }
        if ($status === 401) {
            throw new RuntimeException("Action1 API: 401 Unauthorized — check client ID/secret");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Action1 API returned HTTP $status");
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON response from Action1");
        }
        return $decoded ?? [];
    }

    private function paginate(string $endpoint, array $params = []): array {
        $items  = [];
        $params['from']  = 0;
        $params['limit'] = 50;
        while (true) {
            $data  = $this->get($endpoint, $params);
            $batch = $data['items'] ?? [];
            $items = array_merge($items, $batch);
            if (!empty($data['next_page'])) {
                $params['from'] += $params['limit'];
            } else {
                break;
            }
        }
        return $items;
    }

    public function testConnection(): bool {
        $result = $this->get('/organizations', ['from' => 0, 'limit' => 1]);
        return isset($result['items']);
    }

    public function getAgents(): array {
        $agents = [];
        foreach ($this->paginate('/organizations') as $org) {
            $org_id = $org['id'] ?? null;
            if (!$org_id) continue;

            foreach ($this->paginate("/endpoints/groups/$org_id") as $group) {
                $group_id   = $group['id'] ?? null;
                $group_name = trim($group['name'] ?? '');
                if (!$group_id) continue;
                if (in_array(strtolower($group_name), ['all', 'new endpoints'])) continue;

                foreach ($this->paginate("/endpoints/groups/$org_id/$group_id/contents", ['fields' => '*']) as $ep) {
                    $agents[] = $this->normalizeEndpoint($ep, (string) $org_id, $group_name);
                }
            }
        }
        return $agents;
    }

    private function normalizeEndpoint(array $ep, string $org_id, string $group_name): array {
        $status = strtolower($ep['status'] ?? '');
        $status = in_array($status, ['online', 'offline']) ? $status : 'unknown';

        return [
            'agent_id'         => $org_id . ':' . ($ep['id'] ?? ''),
            'hostname'         => $ep['name'] ?? '',
            'operating_system' => $ep['OS'] ?? ($ep['platform'] ?? ''),
            'status'           => $status,
            'last_seen'        => $ep['last_seen'] ?? '',
            'logged_in_user'   => $ep['logged_on_user'] ?? ($ep['logged_on_users'] ?? ''),
            'local_ips'        => $ep['address'] ?? '',
            'group_name'       => $group_name,
            'org_id'           => $org_id,
        ];
    }

    private function splitAgentId(string $agent_id): array {
        $parts = explode(':', $agent_id, 2);
        return [$parts[0] ?? '', $parts[1] ?? ''];
    }

    // -----------------------------------------------------------------------
    // Hardware / software / services — not exposed by the basic Action1 API
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
    // Alerts — Action1 patch-compliance data is surfaced via sync, not alerts
    // -----------------------------------------------------------------------

    public function getAlerts(bool $resolved = false): array {
        return [];
    }

    public function getAgentAlerts(string $agent_id): array {
        return [];
    }

    // -----------------------------------------------------------------------
    // Scripts / checks — not supported via Action1
    // -----------------------------------------------------------------------

    public function getScripts(): array {
        return [];
    }

    public function runScript(string $agent_id, int $script_id, int $timeout = 120): array {
        throw new RuntimeException('Running scripts is not supported for Action1 integrations');
    }

    public function getAgentChecks(string $agent_id): array {
        return [];
    }

    public function createCheck(string $agent_id, array $payload): array {
        throw new RuntimeException('Check policies are not supported for Action1 integrations');
    }

    public function deleteCheck(int $check_id): bool {
        return false;
    }

    // -----------------------------------------------------------------------
    // URL helpers
    // -----------------------------------------------------------------------

    public function buildDeviceUrl(string $agent_id): string {
        [$org_id, $ep_id] = $this->splitAgentId($agent_id);
        if ($org_id && $ep_id) {
            return $this->web_url . '/#/organizations/' . urlencode($org_id) . '/endpoints/' . urlencode($ep_id);
        }
        return $this->web_url;
    }

    public function buildMeshUrl(string $mesh_node_id): string {
        return $this->web_url;
    }

    public function getIntegrationId(): int {
        return $this->integration_id;
    }
}
