<?php
/*
 * TacticalRmmClient — server-side service for Tactical RMM API calls.
 * API key is decrypted once in the constructor and never exposed to the browser.
 */

class TacticalRmmClient {

    private string $base_url;
    private string $web_url;
    private string $api_key;
    private int $integration_id;

    public function __construct(int $integration_id) {
        global $mysqli;
        $id  = intval($integration_id);
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM rmm_integrations WHERE id=$id AND enabled=1"));
        if (!$row) {
            throw new RuntimeException("RMM integration $id not found or disabled");
        }
        $this->integration_id = $id;
        $this->base_url = rtrim($row['api_url'], '/');
        $this->web_url  = rtrim($row['web_url'] ?? $row['api_url'], '/');
        $this->api_key  = decryptSetting($row['api_key_enc'] ?? '');
        if (empty($this->api_key)) {
            throw new RuntimeException("RMM integration $id has no decryptable API key");
        }
    }

    private function get(string $endpoint): array {
        return $this->request('GET', $endpoint);
    }

    private function post(string $endpoint, array $body = []): array {
        return $this->request('POST', $endpoint, $body);
    }

    private function patch(string $endpoint, array $body = []): array {
        return $this->request('PATCH', $endpoint, $body);
    }

    private function request(string $method, string $endpoint, array $body = []): array {
        $url = $this->base_url . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'X-API-KEY: ' . $this->api_key,
                'Content-Type: application/json',
            ],
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method !== 'GET') {
            // PATCH / PUT / DELETE — send the JSON body via a custom verb.
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        }
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL error: $err");
        }
        if ($status === 401) {
            throw new RuntimeException("Tactical RMM API: 401 Unauthorized — check API key");
        }
        if ($status < 200 || $status >= 300) {
            $detail = '';
            $decoded_err = json_decode($raw, true);
            if (is_array($decoded_err)) {
                $detail = implode('; ', array_map(function ($v) {
                    return is_array($v) ? json_encode($v) : (string) $v;
                }, $decoded_err));
            } elseif (is_string($decoded_err)) {
                $detail = $decoded_err;
            }
            throw new RuntimeException("Tactical RMM API returned HTTP $status" . ($detail !== '' ? ": $detail" : ""));
        }
        $decoded = json_decode($raw, true);
        if ($decoded === null && !empty($raw)) {
            throw new RuntimeException("Invalid JSON response from Tactical RMM");
        }
        if (!is_array($decoded)) {
            return ['message' => $decoded];
        }
        return $decoded ?? [];
    }

    public function testConnection(): bool {
        // Let exceptions propagate — callers handle error reporting
        $result = $this->get('/agents/');
        return is_array($result);
    }

    public function getAgents(): array {
        return $this->get('/agents/');
    }

    public function getAgent(string $agent_id): array {
        return $this->get('/agents/' . urlencode($agent_id) . '/');
    }

    public function getAgentWmi(string $agent_id): array {
        // Tactical RMM has no GET /agents/{id}/wmi/ endpoint — hardware
        // details are embedded in the agent detail response.
        try {
            $agent = $this->getAgent($agent_id);
        } catch (RuntimeException $e) {
            return [];
        }
        return [
            'make_model'     => $agent['make_model'] ?? '',
            'cpu_model'      => $agent['cpu_model'] ?? [],
            'total_ram'      => $agent['total_ram'] ?? null,
            'disks'          => $agent['disks'] ?? [],
            'physical_disks' => $agent['physical_disks'] ?? [],
            'graphics'       => $agent['graphics'] ?? '',
            'local_ips'      => $agent['local_ips'] ?? '',
            'wmi_detail'     => $agent['wmi_detail'] ?? [],
        ];
    }

    public function getAgentSoftware(string $agent_id): array {
        try {
            $result = $this->get('/software/' . urlencode($agent_id) . '/');
        } catch (RuntimeException $e) {
            return [];
        }
        return $result['software'] ?? [];
    }

    public function getAgentServices(string $agent_id): array {
        try {
            return $this->get('/services/' . urlencode($agent_id) . '/');
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function getAlerts(bool $resolved = false): array {
        $param = $resolved ? '' : '?resolved=false';
        try {
            return $this->get('/alerts/' . $param);
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function getAgentAlerts(string $agent_id): array {
        try {
            return $this->get('/alerts/?agent=' . urlencode($agent_id));
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function getScripts(): array {
        try {
            return $this->get('/scripts/');
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function runScript(string $agent_id, int $tactical_script_id, int $timeout = 120): array {
        return $this->post('/agents/' . urlencode($agent_id) . '/runscript/', [
            'script'  => $tactical_script_id,
            'timeout' => $timeout,
            'run_as_user' => false,
        ]);
    }

    /**
     * Fetch the result of a previously-queued script run from the agent's
     * command history (GET /agents/{agent_id}/history/). Matches the stored
     * job/task id against each history entry; if no job id is available it
     * falls back to the most recent script-run entry (best effort).
     *
     * Returns:
     *   ['found' => false]                                   — no result yet
     *   ['found' => true, 'stdout' => .., 'stderr' => .., 'retcode' => int|null]
     */
    public function getScriptRunResult(string $agent_id, string $job_id): array {
        try {
            $history = $this->get('/agents/' . urlencode($agent_id) . '/history/');
        } catch (RuntimeException $e) {
            return ['found' => false];
        }
        if (!is_array($history)) {
            return ['found' => false];
        }

        $best_fallback = null;   // most-recent script-run entry, used if job_id can't match
        foreach ($history as $h) {
            if (!is_array($h)) continue;

            $hid = (string) ($h['id'] ?? $h['pk'] ?? '');
            if ($job_id !== '' && $hid === (string) $job_id) {
                return $this->parseHistoryEntry($h);
            }

            // Track a fallback: the first entry that carries script results.
            if ($best_fallback === null && !empty($h['script_results'])) {
                $best_fallback = $h;
            }
        }

        // No job_id match — return the most recent script result if we found one
        // and no job id was stored to match against.
        if ($job_id === '' && $best_fallback !== null) {
            return $this->parseHistoryEntry($best_fallback);
        }
        return ['found' => false];
    }

    private function parseHistoryEntry(array $h): array {
        $sr      = $h['script_results'] ?? [];
        $stdout  = '';
        $stderr  = '';
        $retcode = null;
        if (is_array($sr)) {
            $stdout  = (string) ($sr['stdout'] ?? '');
            $stderr  = (string) ($sr['stderr'] ?? '');
            if (array_key_exists('retcode', $sr) && $sr['retcode'] !== null) {
                $retcode = intval($sr['retcode']);
            }
        }
        // Plain command runs store their output in "results" instead.
        if ($stdout === '' && !empty($h['results'])) {
            $stdout = is_array($h['results']) ? json_encode($h['results']) : (string) $h['results'];
        }
        return [
            'found'   => true,
            'stdout'  => $stdout,
            'stderr'  => $stderr,
            'retcode' => $retcode,
        ];
    }


    // -----------------------------------------------------------------------
    // Check management
    // -----------------------------------------------------------------------

    public function getAgentChecks(string $agent_id): array {
        try {
            return $this->get('/agents/' . urlencode($agent_id) . '/checks/');
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function createCheck(string $agent_id, array $payload): array {
        $payload['agent'] = $agent_id;
        $this->post('/checks/', $payload);
        // The create endpoint only returns a confirmation message, so look up
        // the newly created check on the agent to get its id.
        return $this->findCheck($agent_id, $payload) ?? [];
    }

    /**
     * Find an existing check on an agent matching the given check payload
     * (by check_type plus the field that distinguishes multiple checks of
     * the same type, e.g. disk letter, service name, or ping target).
     */
    public function findCheck(string $agent_id, array $payload): ?array {
        foreach ($this->getAgentChecks($agent_id) as $c) {
            if (($c['check_type'] ?? null) !== ($payload['check_type'] ?? null)) {
                continue;
            }
            switch ($payload['check_type']) {
                case 'diskspace':
                    if (($c['disk'] ?? null) !== ($payload['disk'] ?? null)) continue 2;
                    break;
                case 'winsvc':
                    if (($c['svc_name'] ?? null) !== ($payload['svc_name'] ?? null)) continue 2;
                    break;
                case 'ping':
                    if (($c['ip'] ?? null) !== ($payload['ip'] ?? null)) continue 2;
                    break;
            }
            return $c;
        }
        return null;
    }

    public function deleteCheck(int $check_id): bool {
        $url = $this->base_url . '/checks/' . $check_id . '/';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_HTTPHEADER     => ['X-API-KEY: ' . $this->api_key, 'Content-Type: application/json'],
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300;
    }

    public function getAgentMesh(string $agent_id): array {
        try {
            return $this->get('/agents/' . urlencode($agent_id) . '/meshcentral/');
        } catch (RuntimeException $e) {
            return [];
        }
    }

    public function buildDeviceUrl(string $agent_id): string {
        // Tactical RMM "Take Control" route — opens a MeshCentral remote
        // desktop session for the agent directly.
        $base = $this->web_url ?: $this->base_url;
        return $base . '/takecontrol/' . urlencode($agent_id);
    }

    public function buildMeshUrl(string $mesh_node_id): string {
        // Extracts host from base_url for MeshCentral (Tactical embeds Mesh at same host by default)
        $parsed = parse_url($this->base_url);
        $host   = $parsed['scheme'] . '://' . $parsed['host'];
        return $host . '/mesh/action.ashx?nodeid=' . urlencode($mesh_node_id) . '&arg=remotedesktop';
    }

    /**
     * Resolve the remote-connect URL for an agent. Prefers a MeshCentral
     * one-time login link (control session) so the technician doesn't have to
     * log in to Tactical RMM or MeshCentral; falls back to the persistent mesh
     * action URL when a node id is exposed, then to Tactical's Take Control.
     *
     * @param string $agent_id Tactical agent id
     * @param string $mode     '' / 'tactical' (default) or 'mesh'
     * @return array ['url' => string, 'type' => string]
     */
    public function buildRemoteUrl(string $agent_id, string $mode = ''): array {
        $mesh = $this->getAgentMesh($agent_id);
        if (!empty($mesh['control'])) {
            return ['url' => $mesh['control'], 'type' => 'meshcentral'];
        }
        // Explicit MeshCentral request with a resolvable node id → persistent URL.
        if ($mode === 'mesh') {
            $node_id = $mesh['node_id'] ?? $mesh['meshnode'] ?? '';
            if (!empty($node_id)) {
                return ['url' => $this->buildMeshUrl((string) $node_id), 'type' => 'meshcentral'];
            }
        }
        return ['url' => $this->buildDeviceUrl($agent_id), 'type' => 'tactical'];
    }

    // -----------------------------------------------------------------------
    // Live actions
    // -----------------------------------------------------------------------

    /**
     * Reboot an agent now. POST /agents/{id}/reboot/
     * Returns the raw API response; throws RuntimeException on failure.
     */
    public function reboot(string $agent_id): array {
        return $this->post('/agents/' . urlencode($agent_id) . '/reboot/', []);
    }

    // -----------------------------------------------------------------------
    // Alert write-back (vendor-side ack/resolve)
    // -----------------------------------------------------------------------

    /**
     * Acknowledge an alert at Tactical RMM. Tactical has no dedicated
     * "acknowledged" state, so the closest vendor-side equivalent is to snooze
     * the alert — mark it seen and quiet re-alerting for a day. PATCH
     * /alerts/{id}/. Throws RuntimeException on failure so the caller can log
     * it (the local ITFlow ack still stands).
     */
    public function ackAlert(string $alert_id): array {
        return $this->patch('/alerts/' . urlencode($alert_id) . '/', [
            'snoozed'      => true,
            'snooze_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
        ]);
    }

    /**
     * Resolve an alert at Tactical RMM. PATCH /alerts/{id}/ with resolved=true.
     * Throws RuntimeException on failure so the caller can log it (the local
     * ITFlow resolve still stands).
     */
    public function resolveAlert(string $alert_id): array {
        return $this->patch('/alerts/' . urlencode($alert_id) . '/', [
            'resolved'    => true,
            'resolved_on' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Run a raw command on an agent. POST /agents/{id}/cmd/
     * Tactical runs the command and returns its stdout as a plain string
     * (surfaced by request() as ['message' => output]).
     *
     * @param string $shell 'cmd' or 'powershell'
     */
    public function runCommand(string $agent_id, string $cmd, string $shell = 'cmd', int $timeout = 30): array {
        $shell = in_array($shell, ['cmd', 'powershell'], true) ? $shell : 'cmd';
        return $this->post('/agents/' . urlencode($agent_id) . '/cmd/', [
            'shell'   => $shell,
            'cmd'     => $cmd,
            'timeout' => $timeout,
            'custom_shell' => null,
        ]);
    }

    // -----------------------------------------------------------------------
    // Patch / Windows Update management
    // -----------------------------------------------------------------------

    /**
     * List Windows Updates known to an agent. GET /winupdate/{id}/
     * Returns the full list (installed + pending); callers filter on
     * the 'installed' flag for pending updates. Best-effort — [] on error.
     */
    public function getAgentPatches(string $agent_id): array {
        try {
            $result = $this->get('/winupdate/' . urlencode($agent_id) . '/');
            return is_array($result) ? $result : [];
        } catch (RuntimeException $e) {
            return [];
        }
    }

    /**
     * Queue a Windows Update scan on an agent. POST /winupdate/{id}/scan/
     */
    public function scanPatches(string $agent_id): array {
        return $this->post('/winupdate/' . urlencode($agent_id) . '/scan/', []);
    }

    /**
     * Install pending Windows Updates on an agent. POST /winupdate/{id}/install/
     * Tactical installs all approved/pending updates for the agent; the optional
     * $guids argument is accepted for API symmetry but not required by Tactical.
     */
    public function installPatches(string $agent_id, array $guids = []): array {
        return $this->post('/winupdate/' . urlencode($agent_id) . '/install/', []);
    }

    public function getIntegrationId(): int {
        return $this->integration_id;
    }
}
