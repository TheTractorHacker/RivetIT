<?php
/*
 * LevelRmmClient — server-side service for Level.io RMM API calls.
 *
 * Level.io API:
 *   Base URL : https://api.level.io/v2   (api_url = https://api.level.io)
 *   Auth     : Authorization: {api_key}  (no Bearer prefix)
 *   Devices  : GET /v2/devices
 *   Alerts   : GET /v2/alerts
 *   Scripts  : GET /v2/scripts
 *
 * Device status is a boolean field "online" (true/false), not a string.
 * Client mapping uses device field "group_name" → ITFlow client name.
 */

class LevelRmmClient {

    private string $base_url;
    private string $org_id;
    private string $api_key;
    private int    $integration_id;

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli,
            "SELECT * FROM rmm_integrations WHERE id=$id AND enabled=1 AND type='level'"
        ));
        if (!$row) {
            throw new RuntimeException("Level RMM integration $id not found or disabled");
        }
        $this->integration_id = $id;
        $this->base_url       = rtrim($row['api_url'], '/') . '/v2';
        $this->org_id         = trim($row['web_url'] ?? '');
        $this->api_key        = decryptSetting($row['api_key_enc'] ?? '');
        if (empty($this->api_key)) {
            throw new RuntimeException("Level RMM integration $id has no decryptable API key");
        }
    }

    private function get(string $endpoint): array {
        return $this->request('GET', $endpoint);
    }

    private function post(string $endpoint, array $body = []): array {
        return $this->request('POST', $endpoint, $body);
    }

    private function request(string $method, string $endpoint, array $body = []): array {
        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: ' . $this->api_key,   // no Bearer prefix
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $raw           = curl_exec($ch);
        $status        = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $err           = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("Level API cURL error: $err");
        }
        if (strpos($raw, '<!DOCTYPE') !== false || strpos($raw, '<html') !== false) {
            throw new RuntimeException(
                "Level API URL is pointing to the web UI. Set API URL to: https://api.level.io"
            );
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException("Level API: HTTP $status Unauthorized — check API key");
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($raw, true);
            $msg     = $decoded['error'] ?? $decoded['message'] ?? substr($raw, 0, 200);
            throw new RuntimeException("Level API HTTP $status: $msg");
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON from Level API: " . substr($raw, 0, 200));
        }
        return $decoded ?? [];
    }

    // -----------------------------------------------------------------------
    // Connection test
    // -----------------------------------------------------------------------

    public function testConnection(): bool {
        $result = $this->get('/devices?per_page=1');
        return isset($result['data']) || is_array($result);
    }

    // -----------------------------------------------------------------------
    // Agents / Devices
    // -----------------------------------------------------------------------

    public function getAgents(): array {
        $all      = [];
        $page     = 1;
        $per_page = 100;
        do {
            $result = $this->get("/devices?per_page=$per_page&page=$page");
            $items  = $result['data'] ?? [];
            foreach ($items as $dev) {
                $all[] = $this->normalizeDevice($dev);
            }
            $meta  = $result['meta'] ?? [];
            $more  = !empty($meta['next_page']) || !empty($meta['next_cursor']);
            $page++;
        } while ($more && count($items) >= $per_page);
        return $all;
    }

    public function getAgent(string $agent_id): array {
        $result = $this->get('/devices/' . urlencode($agent_id));
        $dev    = $result['data'] ?? $result;
        return $this->normalizeDevice($dev);
    }

    public function getAgentSoftware(string $agent_id): array {
        try {
            $result = $this->get('/devices/' . urlencode($agent_id) . '/software');
            return $result['data'] ?? [];
        } catch (RuntimeException $e) { return []; }
    }

    public function getAgentServices(string $agent_id): array {
        try {
            $result = $this->get('/devices/' . urlencode($agent_id) . '/services');
            return $result['data'] ?? [];
        } catch (RuntimeException $e) { return []; }
    }

    public function getAgentWmi(string $agent_id): array {
        try {
            return $this->getAgent($agent_id)['hardware'] ?? [];
        } catch (RuntimeException $e) { return []; }
    }

    // -----------------------------------------------------------------------
    // Alerts
    // -----------------------------------------------------------------------

    public function getAlerts(bool $resolved = false): array {
        try {
            $all      = [];
            $page     = 1;
            $per_page = 100;
            $status   = $resolved ? '' : '&status=active';
            do {
                $result = $this->get("/alerts?per_page=$per_page&page=$page" . $status);
                $items  = $result['data'] ?? [];
                foreach ($items as $alert) {
                    $all[] = $alert;
                }
                $meta = $result['meta'] ?? [];
                $more = !empty($meta['next_page']) || !empty($meta['next_cursor']);
                $page++;
            } while ($more && count($items) >= $per_page);
            return $all;
        } catch (RuntimeException $e) { return []; }
    }

    public function getAgentAlerts(string $agent_id): array {
        try {
            $result = $this->get('/alerts?device_id=' . urlencode($agent_id));
            return $result['data'] ?? [];
        } catch (RuntimeException $e) { return []; }
    }

    /**
     * Level.io's public v2 API is read-only for alerts — there is no documented
     * endpoint to acknowledge or resolve an alert. These throw a clear message
     * (mirroring reboot()/runCommand()) so the caller logs it and the local
     * ITFlow action still succeeds.
     */
    public function ackAlert(string $alert_id): array {
        throw new RuntimeException('Level.io does not support acknowledging alerts via the API. Acknowledge the alert from the Level web app.');
    }

    public function resolveAlert(string $alert_id): array {
        throw new RuntimeException('Level.io does not support resolving alerts via the API. Resolve the alert from the Level web app.');
    }

    // -----------------------------------------------------------------------
    // Scripts
    // -----------------------------------------------------------------------

    public function getScripts(): array {
        try {
            $result = $this->get('/scripts');
            return $result['data'] ?? [];
        } catch (RuntimeException $e) { return []; }
    }

    public function runScript(string $agent_id, int $script_id, int $timeout = 120): array {
        return $this->post('/devices/' . urlencode($agent_id) . '/scripts/' . $script_id . '/run', [
            'timeout' => $timeout,
        ]);
    }

    // -----------------------------------------------------------------------
    // URL helpers
    // -----------------------------------------------------------------------

    public function buildDeviceUrl(string $agent_id): string {
        return 'https://app.level.io/device/' . urlencode($agent_id);
    }

    /**
     * Resolve the remote-connect URL for a Level device. Level opens remote
     * sessions from its own web app, so this simply returns the device URL.
     *
     * @return array ['url' => string, 'type' => string]
     */
    public function buildRemoteUrl(string $agent_id, string $mode = ''): array {
        return ['url' => $this->buildDeviceUrl($agent_id), 'type' => 'level'];
    }

    /**
     * Fetch a script run's result by job id. Level.io's v2 API does not
     * currently expose per-run script stdout/return-code keyed by a job id,
     * so this is a best-effort stub that reports "not found".
     *
     * TODO: When Level exposes a run-result endpoint (e.g. GET
     * /v2/devices/{id}/scripts/runs/{job_id}), fetch stdout/retcode here and
     * return ['found' => true, 'stdout' => .., 'stderr' => .., 'retcode' => ..].
     */
    public function getScriptRunResult(string $agent_id, string $job_id): array {
        return ['found' => false];
    }

    // -----------------------------------------------------------------------
    // Monitoring (Level's "monitor" concept)
    //
    // Level.io's public v2 API does not currently expose a monitors endpoint
    // (GET /v2/monitors and /v2/devices/{id}/monitors both return 404 as of
    // this writing). These are best-effort: they probe the endpoint and return
    // [] cleanly when it is absent, so the UI renders a "not supported" state.
    //
    // TODO: When Level exposes monitors, map each result to the shape used by
    // the Monitoring tab: ['name','status'(passing/failing),'more_info'].
    // -----------------------------------------------------------------------

    public function getMonitors(): array {
        try {
            $result = $this->get('/monitors?per_page=100');
            return $result['data'] ?? [];
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function getDeviceMonitors(string $agent_id): array {
        try {
            $result = $this->get('/devices/' . urlencode($agent_id) . '/monitors');
            return $result['data'] ?? [];
        } catch (RuntimeException $e) {
            return [];
        }
    }

    // Level's public API does not expose per-device patch/OS-update status.
    // TODO: implement when Level publishes a patch endpoint.
    public function getAgentPatches(string $agent_id): array {
        return [];
    }

    // -----------------------------------------------------------------------
    // Live actions
    //
    // Level.io drives remote reboots and command execution through its own web
    // app / scripts, not the public REST API, so these throw a clear message
    // rather than silently failing.
    // -----------------------------------------------------------------------

    public function reboot(string $agent_id): array {
        throw new RuntimeException('Level.io does not support remote reboot via the API. Reboot the device from the Level web app.');
    }

    public function runCommand(string $agent_id, string $cmd, string $shell = 'cmd', int $timeout = 30): array {
        throw new RuntimeException('Level.io does not support running raw commands via the API. Use a Level script instead.');
    }

    public function getIntegrationId(): int {
        return $this->integration_id;
    }

    // -----------------------------------------------------------------------
    // Field normalisation
    // Level device fields: id, hostname, nickname, role, group_name, group_id,
    //   tags (array), online (bool), platform, notes, maintenance_mode
    // -----------------------------------------------------------------------

    private function normalizeDevice(array $dev): array {
        // "online" is a boolean in Level v2 API
        $online = $dev['online'] ?? false;

        // Use nickname as display name when set, otherwise hostname
        $hostname    = $dev['hostname'] ?? '';
        $display     = ($dev['nickname'] ?? '') ?: $hostname;

        // Map Level role to a readable OS/type hint
        $platform    = $dev['platform'] ?? '';

        // Coerce any scalar-or-array value to a plain string.
        $flat = function ($v): string {
            if (is_array($v))  return implode(', ', array_filter(array_map('strval', $v)));
            if (is_bool($v))   return $v ? '1' : '0';
            return (string) ($v ?? '');
        };

        // Hardware details may be nested under a "hardware"/"system" object or
        // live at the top level depending on the Level payload — be defensive.
        $hw  = is_array($dev['hardware'] ?? null) ? $dev['hardware'] : [];
        $sys = is_array($dev['system'] ?? null)   ? $dev['system']   : [];

        $cpu          = $flat($dev['cpu']          ?? $hw['cpu']          ?? $hw['processor']     ?? $dev['processor'] ?? '');
        $ram          = $flat($dev['memory']       ?? $dev['ram']        ?? $hw['memory']        ?? $hw['ram']        ?? $hw['total_memory'] ?? '');
        $serial       = $flat($dev['serial_number'] ?? $dev['serial']    ?? $hw['serial_number'] ?? $hw['serial']     ?? '');
        $manufacturer = $flat($dev['manufacturer'] ?? $hw['manufacturer'] ?? $hw['vendor']       ?? '');
        $model        = $flat($dev['model']        ?? $hw['model']       ?? '') ?: ($dev['role'] ?? '');
        $os_name      = $flat($dev['operating_system'] ?? $dev['os']     ?? $sys['os']           ?? $platform);
        $os_version   = $flat($dev['os_version']   ?? $dev['operating_system_version'] ?? $sys['os_version'] ?? '');
        $logged_user  = $flat($dev['last_user']    ?? $dev['logged_in_user'] ?? $dev['last_logged_in_user'] ?? $dev['current_user'] ?? '');
        $agent_ver    = $flat($dev['agent_version'] ?? $dev['version']    ?? '');

        // IP addresses may be a list or a single string.
        $ips_raw = $dev['ip_addresses'] ?? $dev['ips'] ?? $dev['ip_address'] ?? $dev['local_ips'] ?? [];
        $local_ips = is_array($ips_raw) ? $ips_raw : array_filter(array_map('trim', explode(',', (string) $ips_raw)));

        // Build a wmi_detail.network_config array so the mapper can persist
        // interfaces and match assets by MAC, mirroring the Tactical shape.
        $ifaces = $dev['network_interfaces'] ?? $dev['interfaces'] ?? $dev['nics'] ?? [];
        $nics   = [];
        if (is_array($ifaces)) {
            foreach ($ifaces as $if) {
                if (!is_array($if)) continue;
                $mac = $if['mac_address'] ?? $if['mac'] ?? $if['MACAddress'] ?? '';
                $ip  = $if['ip_addresses'] ?? $if['ip_address'] ?? $if['ip'] ?? $if['IPAddress'] ?? null;
                if (empty($mac) && empty($ip)) continue;
                $nics[] = [
                    'MACAddress'  => $mac,
                    'IPAddress'   => is_array($ip) ? $ip : ($ip !== null && $ip !== '' ? [$ip] : null),
                    'Description' => $if['name'] ?? $if['description'] ?? '',
                ];
            }
        }
        $wmi_detail = $nics ? ['network_config' => $nics] : (is_array($dev['wmi_detail'] ?? null) ? $dev['wmi_detail'] : []);

        return [
            'id'               => $dev['id'] ?? '',
            'agent_id'         => $dev['id'] ?? '',
            'hostname'         => $hostname,
            'description'      => $dev['notes'] ?? '',
            'status'           => $online ? 'online' : 'offline',
            'last_seen'        => $dev['last_seen'] ?? null,
            'logged_in_user'   => $logged_user,
            'operating_system' => $os_name,
            'os_version'       => $os_version,
            'plat'             => $platform,
            'manufacturer'     => $manufacturer,
            'model'            => $model,
            'cpu'              => $cpu,
            'ram'              => $ram,
            'serial_number'    => $serial,
            'agent_version'    => $agent_ver,
            'local_ips'        => $local_ips,
            'wmi_detail'       => $wmi_detail,
            'tags'             => $dev['tags'] ?? [],
            'group_name'       => $dev['group_name'] ?? '',
            'display_name'     => $display,
            // Live-health hints the asset mapper persists onto asset_rmm_links.
            // Level exposes maintenance mode and last reboot time but not live
            // CPU/RAM/disk usage percentages, so those stay NULL.
            'maintenance_mode' => !empty($dev['maintenance_mode']) ? 1 : 0,
            'last_reboot_time' => $dev['last_reboot_time'] ?? $dev['last_reboot'] ?? null,
            '_provider'        => 'level',
            '_raw'             => $dev,
        ];
    }
}
