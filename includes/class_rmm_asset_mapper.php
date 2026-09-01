<?php
/*
 * RmmAssetMapper — matches RMM agents (Tactical or Level) to ITFlow assets.
 *
 * Match priority:
 *   1. tactical_agent_id already in asset_rmm_links (already linked)
 *   2. asset_serial match
 *   3. MAC address match via asset_interfaces
 *   4. Case-insensitive hostname match on asset_name (only if unique)
 */

class RmmAssetMapper {

    private $mysqli;
    private int $integration_id;
    private int $triggered_by;
    private $rmmClient;

    public function __construct($mysqli, int $integration_id, int $triggered_by = 0, $rmmClient = null) {
        $this->mysqli         = $mysqli;
        $this->integration_id = $integration_id;
        $this->triggered_by   = $triggered_by;
        $this->rmmClient      = $rmmClient;
    }

    public function syncAgents(array $agents): array {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($agents as $agent) {
            try {
                $result = $this->syncAgent($agent);
                $stats[$result]++;
            } catch (Exception $e) {
                $stats['errors'][] = ($agent['hostname'] ?? 'unknown') . ': ' . $e->getMessage();
                $stats['skipped']++;
            }
        }
        return $stats;
    }

    // Pulls alerts from the RMM (Tactical/Level — Action1 returns none) into
    // rmm_alerts. New, unresolved alerts are inserted as status='new'; alerts
    // the RMM now reports resolved have their matching row marked resolved.
    public function syncAlerts(): array {
        $stats = ['created' => 0, 'resolved' => 0, 'skipped' => 0, 'errors' => []];

        if (!$this->rmmClient) {
            return $stats;
        }

        $alerts = $this->rmmClient->getAlerts(true);

        foreach ($alerts as $alert) {
            try {
                $stats[$this->syncAlert($alert)]++;
            } catch (Exception $e) {
                $stats['errors'][] = $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function syncAlert(array $alert): string {
        $m       = $this->mysqli;
        $intg_id = $this->integration_id;

        $tactical_id = trim((string) ($alert['id'] ?? $alert['alert_id'] ?? ''));
        if ($tactical_id === '') {
            return 'skipped';
        }
        $tactical_id_esc = mysqli_real_escape_string($m, $tactical_id);

        $resolved = !empty($alert['resolved']) || strtolower((string) ($alert['status'] ?? '')) === 'resolved';

        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id, status, ticket_id FROM rmm_alerts WHERE integration_id=$intg_id AND tactical_alert_id='$tactical_id_esc' LIMIT 1"
        ));

        if ($existing) {
            if ($resolved && $existing['status'] !== 'resolved') {
                $existing_id = intval($existing['id']);
                mysqli_query($m, "UPDATE rmm_alerts SET status='resolved', resolved_at=NOW() WHERE id=$existing_id");
                // Vendor cleared the alert — auto-close the linked ticket (gated
                // by config, and only when the ticket has no human activity).
                if (!empty($existing['ticket_id'])) {
                    $this->autoCloseAlertTicket(intval($existing['ticket_id']), $existing_id);
                }
                return 'resolved';
            }
            return 'skipped';
        }

        // Don't import alerts that are already resolved by the time we first see them
        if ($resolved) {
            return 'skipped';
        }

        $agent_id = trim((string) ($alert['agent_id'] ?? $alert['agent'] ?? $alert['device_id'] ?? ''));
        $hostname = trim((string) ($alert['hostname'] ?? $alert['agent_hostname'] ?? ''));

        $asset_id  = 0;
        $client_id = 0;

        if ($agent_id !== '') {
            $agent_id_esc = mysqli_real_escape_string($m, $agent_id);
            $link = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT asset_id FROM asset_rmm_links WHERE integration_id=$intg_id AND tactical_agent_id='$agent_id_esc' LIMIT 1"
            ));
            if ($link) {
                $asset_id = intval($link['asset_id']);
            }
        }

        if (!$asset_id && $hostname !== '') {
            $hostname_esc = mysqli_real_escape_string($m, $hostname);
            $link = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT asset_id FROM asset_rmm_links WHERE integration_id=$intg_id AND LOWER(hostname)=LOWER('$hostname_esc') LIMIT 1"
            ));
            if ($link) {
                $asset_id = intval($link['asset_id']);
            }
        }

        if ($asset_id) {
            $asset = mysqli_fetch_assoc(mysqli_query($m, "SELECT asset_client_id FROM assets WHERE asset_id=$asset_id"));
            $client_id = $asset ? intval($asset['asset_client_id']) : 0;
        }

        $severity = strtolower((string) ($alert['severity'] ?? $alert['priority'] ?? 'info'));
        if (!in_array($severity, ['info', 'warning', 'error', 'critical'])) {
            $severity = 'info';
        }

        $message = (string) ($alert['message'] ?? $alert['description'] ?? $alert['alert_type'] ?? 'RMM Alert');
        $message_esc  = mysqli_real_escape_string($m, substr($message, 0, 2000));
        $severity_esc = mysqli_real_escape_string($m, $severity);
        $raw_json_esc = mysqli_real_escape_string($m, json_encode($alert));

        mysqli_query($m,
            "INSERT INTO rmm_alerts SET
             integration_id=$intg_id,
             asset_id=" . ($asset_id ?: 'NULL') . ",
             client_id=" . ($client_id ?: 'NULL') . ",
             tactical_alert_id='$tactical_id_esc',
             severity='$severity_esc',
             status='new',
             message='$message_esc',
             raw_data_json='$raw_json_esc'"
        );

        return 'created';
    }

    /**
     * When an RMM alert clears at the vendor, close the ITFlow ticket that was
     * auto-created from it — but conservatively:
     *   - honours the config_rmm_auto_close_on_clear toggle (default on);
     *   - only touches a ticket that is still open;
     *   - if a human has worked the ticket (any non-System reply exists), it is
     *     LEFT OPEN and only a note is added; otherwise it is closed (status 5)
     *     with a system-attributed note "Auto-closed: RMM alert cleared".
     */
    private function autoCloseAlertTicket(int $ticket_id, int $alert_id): void {
        $m = $this->mysqli;
        if ($ticket_id <= 0) {
            return;
        }

        // Config gate (default on when the column/row is missing).
        $cfg = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT config_rmm_auto_close_on_clear FROM settings WHERE company_id=1 LIMIT 1"
        ));
        if ($cfg && array_key_exists('config_rmm_auto_close_on_clear', $cfg)
            && intval($cfg['config_rmm_auto_close_on_clear']) === 0) {
            return;
        }

        // Only act on a ticket that is still open.
        $ticket = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT ticket_id, ticket_prefix, ticket_number, ticket_client_id, ticket_asset_id
             FROM tickets
             WHERE ticket_id=$ticket_id AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL
             LIMIT 1"
        ));
        if (!$ticket) {
            return; // already resolved/closed, merged, or deleted
        }

        $client_id = intval($ticket['ticket_client_id']);
        $asset_id  = intval($ticket['ticket_asset_id']);
        $tref      = $ticket['ticket_prefix'] . $ticket['ticket_number'];

        // Conservative guard: has anyone actually worked this ticket? Any reply
        // that isn't a System note counts as human activity.
        $non_system = intval(mysqli_fetch_assoc(mysqli_query($m,
            "SELECT COUNT(*) AS c FROM ticket_replies
             WHERE ticket_reply_ticket_id=$ticket_id
               AND ticket_reply_type <> 'System'
               AND ticket_reply_archived_at IS NULL"
        ))['c']);

        if ($non_system > 0) {
            // Leave it open — just record that the underlying alert cleared.
            mysqli_query($m,
                "INSERT INTO ticket_replies SET
                 ticket_reply = 'RMM alert cleared at the vendor. Ticket left open because it has other activity.',
                 ticket_reply_type = 'System',
                 ticket_reply_time_worked = '00:00:00',
                 ticket_reply_by = 0,
                 ticket_reply_ticket_id = $ticket_id"
            );
            logAction('RMM', 'Alert Cleared', "RMM alert ID $alert_id cleared; ticket $tref left open (has activity)", $client_id, $asset_id);
            return;
        }

        // No human activity → auto-close (status 5 = Closed), system-attributed.
        mysqli_query($m,
            "UPDATE tickets SET
             ticket_status = 5,
             ticket_resolved_at = NOW(),
             ticket_closed_at = NOW(),
             ticket_closed_by = 0
             WHERE ticket_id = $ticket_id
               AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL"
        );
        mysqli_query($m,
            "INSERT INTO ticket_replies SET
             ticket_reply = 'Auto-closed: RMM alert cleared',
             ticket_reply_type = 'System',
             ticket_reply_time_worked = '00:00:00',
             ticket_reply_by = 0,
             ticket_reply_ticket_id = $ticket_id"
        );
        logAction('RMM', 'Ticket Auto-Closed', "Ticket $tref auto-closed: RMM alert ID $alert_id cleared", $client_id, $asset_id);
    }

    private function syncAgent(array $agent): string {
        $m       = $this->mysqli;
        $intg_id = $this->integration_id;

        // Coerce any field that might be an array (e.g. local_ips, tags) to a plain string
        $str = function ($v): string {
            if (is_array($v))  return implode(', ', array_filter(array_map('strval', $v)));
            if (is_bool($v))   return $v ? '1' : '0';
            return (string) ($v ?? '');
        };

        $agent_id     = sanitizeInput($str($agent['agent_id'] ?? $agent['id'] ?? ''));
        $hostname     = sanitizeInput($str($agent['hostname'] ?? ''));
        $serial       = sanitizeInput($str($agent['serial_number'] ?? ''));
        $os_name      = sanitizeInput($str($agent['operating_system'] ?? ''));
        $os_version   = sanitizeInput($str($agent['os_version'] ?? $agent['os_build_number'] ?? ''));
        $manufacturer = sanitizeInput($str($agent['manufacturer'] ?? $agent['make_model'] ?? ''));
        $model        = sanitizeInput($str($agent['model'] ?? ''));
        $cpu          = sanitizeInput($str($agent['cpu'] ?? $agent['cpu_model'] ?? ''));
        $ram_gb       = sanitizeInput($str($agent['ram'] ?? $agent['total_ram'] ?? ''));
        $mesh_node_id = sanitizeInput($str($agent['mesh_node_id'] ?? ''));
        $last_seen    = sanitizeInput($str($agent['last_seen'] ?? ''));
        $logged_user  = sanitizeInput($str($agent['logged_in_user'] ?? $agent['logged_in_username'] ?? ''));

        $_s     = $agent['status'] ?? 'offline';
        $status = in_array($_s, ['online', 'offline', 'unknown']) ? $_s : 'offline';

        $client_id = 0;
        $raw_json  = mysqli_real_escape_string($m, json_encode($agent));

        // Map the RMM-side client/group name (Tactical: client_name, Level/Action1: group_name)
        // to an ITFlow client by exact (case-insensitive) name match.
        $resolved_client_id = $this->resolveClientId($agent);

        if (empty($agent_id) || empty($hostname)) {
            return 'skipped';
        }

        // Normalize last_seen to MySQL datetime
        $last_seen_val = 'NULL';
        if (!empty($last_seen)) {
            $ts = strtotime($last_seen);
            if ($ts) { $last_seen_val = "'" . date('Y-m-d H:i:s', $ts) . "'"; }
        }

        // Resolve the vendor detail once — the wmi_detail feeds interface sync,
        // and the live-health snapshot (cpu/ram/disk %, needs_reboot, boot time,
        // maintenance mode, pending patches) is persisted onto the link row.
        $detail_bundle = $this->resolveDetailBundle($agent, $agent_id);
        $wmi_detail    = $detail_bundle['wmi_detail'];
        $health_sql    = $this->healthSetSql($detail_bundle['health']);

        // ----- Step 1: Check existing link -----
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT arl.id, arl.asset_id, arl.rmm_status, a.asset_client_id FROM asset_rmm_links arl
             JOIN assets a ON a.asset_id = arl.asset_id
             WHERE arl.integration_id=$intg_id AND arl.tactical_agent_id='$agent_id'"
        ));

        if ($existing) {
            $this->updateLink($existing['id'], $existing['rmm_status'], $status, $last_seen_val, $os_name, $os_version,
                $manufacturer, $model, $cpu, $ram_gb, $logged_user, $mesh_node_id, $raw_json, $health_sql);
            $this->syncInterfaces(intval($existing['asset_id']), $wmi_detail);
            $this->backfillClientId(intval($existing['asset_id']), intval($existing['asset_client_id']), $resolved_client_id);
            return 'updated';
        }

        // ----- Step 2: Try to match an existing ITFlow asset -----
        $asset_id = 0;

        // 2a: serial number
        if (!$asset_id && !empty($serial)) {
            $row = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT asset_id FROM assets WHERE asset_serial='$serial' AND asset_archived_at IS NULL LIMIT 1"
            ));
            if ($row) { $asset_id = intval($row['asset_id']); }
        }

        // 2b: MAC address
        if (!$asset_id) {
            foreach ($this->extractMacs($agent) as $mac) {
                if (empty($mac)) continue;
                $mac_esc = mysqli_real_escape_string($m, strtolower($mac));
                $row = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT a.asset_id FROM assets a
                     JOIN asset_interfaces ai ON ai.interface_asset_id = a.asset_id
                     WHERE LOWER(ai.interface_mac)='$mac_esc' AND a.asset_archived_at IS NULL LIMIT 1"
                ));
                if ($row) { $asset_id = intval($row['asset_id']); break; }
            }
        }

        // 2c: hostname match (unique only)
        if (!$asset_id && !empty($hostname)) {
            $h    = mysqli_real_escape_string($m, $hostname);
            $cnt  = intval(mysqli_fetch_assoc(mysqli_query($m,
                "SELECT COUNT(*) as c FROM assets WHERE LOWER(asset_name)=LOWER('$h') AND asset_archived_at IS NULL"
            ))['c']);
            if ($cnt === 1) {
                $row = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT asset_id FROM assets WHERE LOWER(asset_name)=LOWER('$h') AND asset_archived_at IS NULL LIMIT 1"
                ));
                if ($row) { $asset_id = intval($row['asset_id']); }
            }
        }

        // ----- Step 3: Create new ITFlow asset if no match -----
        if (!$asset_id) {
            // Only skip when the agent carries a named client/group that we
            // couldn't match — it belongs to someone, we just don't know who.
            // Integrations with no per-device client name (e.g. Sophos Central)
            // should still create the asset unowned so the user can assign it.
            $has_named_client = trim((string) ($agent['client_name'] ?? $agent['group_name'] ?? '')) !== '';
            if ($has_named_client && !$resolved_client_id) {
                return 'skipped';
            }

            $asset_type = $this->guessAssetType($os_name);
            $h  = mysqli_real_escape_string($m, $hostname);
            $s  = mysqli_real_escape_string($m, $serial);
            $o  = mysqli_real_escape_string($m, trim("$os_name $os_version"));
            $mk = mysqli_real_escape_string($m, $manufacturer);
            $client_set = $resolved_client_id ? "asset_client_id=$resolved_client_id," : '';
            mysqli_query($m,
                "INSERT INTO assets SET
                 asset_type='$asset_type',
                 asset_name='$h',
                 asset_serial='$s',
                 asset_os='$o',
                 asset_make='$mk',
                 asset_status='Active',
                 $client_set
                 asset_created_at=NOW()"
            );
            $asset_id = intval(mysqli_insert_id($m));
            $outcome  = 'created';
        } else {
            $outcome = 'matched';
            $matched_row = mysqli_fetch_assoc(mysqli_query($m, "SELECT asset_client_id FROM assets WHERE asset_id=$asset_id"));
            $this->backfillClientId($asset_id, intval($matched_row['asset_client_id']), $resolved_client_id);
        }

        // ----- Step 4: Insert or update the link row -----
        // The asset we matched (by serial/MAC/hostname) may already have a
        // link row for this integration under a *different* tactical_agent_id
        // — e.g. the RMM agent was reinstalled/re-enrolled on the same
        // machine and got a new agent ID. asset_rmm_links has a UNIQUE KEY on
        // (asset_id, integration_id), so a blind INSERT here would throw a
        // duplicate-key error and abort the sync for that device. Update the
        // existing row in place instead.
        $existing_link_for_asset = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id FROM asset_rmm_links WHERE asset_id=$asset_id AND integration_id=$intg_id LIMIT 1"
        ));

        if ($existing_link_for_asset) {
            mysqli_query($m,
                "UPDATE asset_rmm_links SET
                 tactical_agent_id='$agent_id',
                 hostname='$hostname',
                 mesh_node_id='$mesh_node_id',
                 rmm_status='$status',
                 $health_sql
                 last_seen=$last_seen_val,
                 os_name='$os_name',
                 os_version='$os_version',
                 manufacturer='$manufacturer',
                 model='$model',
                 cpu='$cpu',
                 ram_gb='$ram_gb',
                 logged_in_user='$logged_user',
                 last_sync=NOW(),
                 raw_data_json='$raw_json'
                 WHERE id=" . intval($existing_link_for_asset['id'])
            );
        } else {
            mysqli_query($m,
                "INSERT INTO asset_rmm_links SET
                 asset_id=$asset_id,
                 integration_id=$intg_id,
                 tactical_agent_id='$agent_id',
                 hostname='$hostname',
                 mesh_node_id='$mesh_node_id',
                 rmm_status='$status',
                 $health_sql
                 last_seen=$last_seen_val,
                 os_name='$os_name',
                 os_version='$os_version',
                 manufacturer='$manufacturer',
                 model='$model',
                 cpu='$cpu',
                 ram_gb='$ram_gb',
                 logged_in_user='$logged_user',
                 last_sync=NOW(),
                 raw_data_json='$raw_json'"
            );
        }

        $this->syncInterfaces($asset_id, $wmi_detail);

        return $outcome;
    }

    private function updateLink(int $link_id, ?string $old_status, string $status, string $last_seen_val,
        string $os_name, string $os_version, string $manufacturer, string $model,
        string $cpu, string $ram_gb, string $logged_user, string $mesh_node_id, string $raw_json,
        string $health_sql = ''): void
    {
        $m = $this->mysqli;
        $status_changed_sql = ($old_status !== null && $old_status !== $status)
            ? "rmm_status_changed_at=NOW(),"
            : "";
        mysqli_query($m,
            "UPDATE asset_rmm_links SET
             rmm_status='$status',
             $status_changed_sql
             $health_sql
             last_seen=$last_seen_val,
             os_name='$os_name',
             os_version='$os_version',
             manufacturer='$manufacturer',
             model='$model',
             cpu='$cpu',
             ram_gb='$ram_gb',
             logged_in_user='$logged_user',
             mesh_node_id='$mesh_node_id',
             last_sync=NOW(),
             raw_data_json='$raw_json'
             WHERE id=$link_id"
        );
    }

    private function resolveWmiDetail(array $agent, string $agent_id): array {
        if (!empty($agent['wmi_detail'])) {
            return $agent['wmi_detail'];
        }
        // The /agents/ list endpoint doesn't include wmi_detail — fetch agent detail
        if ($this->rmmClient) {
            try {
                $detail = $this->rmmClient->getAgentWmi($agent_id);
                return $detail['wmi_detail'] ?? [];
            } catch (\Throwable $e) {
                return [];
            }
        }
        return [];
    }

    /**
     * Fetch the vendor detail once and return both the wmi_detail (for
     * interface sync) and a normalized live-health snapshot (for the link row).
     * For Tactical, the /agents/ list payload lacks wmi_detail and usage %, so
     * we pull the full agent detail; for Level the normalized device already
     * carries what the API exposes and no extra call is made.
     *
     * @return array{wmi_detail: array, health: array}
     */
    private function resolveDetailBundle(array $agent, string $agent_id): array {
        $wmi    = is_array($agent['wmi_detail'] ?? null) ? $agent['wmi_detail'] : [];
        $detail = null;

        // Only Tactical exposes a richer per-agent detail worth a second fetch.
        if (empty($wmi) && $this->rmmClient
            && $this->rmmClient instanceof TacticalRmmClient) {
            try {
                $detail = $this->rmmClient->getAgent($agent_id);
            } catch (\Throwable $e) {
                $detail = null;
            }
            if (is_array($detail) && !empty($detail['wmi_detail']) && is_array($detail['wmi_detail'])) {
                $wmi = $detail['wmi_detail'];
            }
        }

        // Merge so the detail wins for shared keys but list-only fields (e.g.
        // has_patches_pending) from the original payload survive.
        $src = is_array($detail) ? array_merge($agent, $detail) : $agent;

        return ['wmi_detail' => $wmi, 'health' => $this->computeHealth($src, $wmi)];
    }

    /**
     * Extract a live-health snapshot from a vendor agent payload. Values that
     * aren't exposed by the vendor stay null (rendered as "—" in the UI).
     */
    private function computeHealth(array $agent, array $wmi): array {
        $h = [
            'cpu_percent'      => null,
            'ram_percent'      => null,
            'disk_percent'     => null,
            'needs_reboot'     => 0,
            'last_boot'        => null,
            'maintenance_mode' => 0,
            'patches_pending'  => null,
        ];

        if (array_key_exists('needs_reboot', $agent))        $h['needs_reboot']     = !empty($agent['needs_reboot']) ? 1 : 0;
        if (array_key_exists('maintenance_mode', $agent))    $h['maintenance_mode'] = !empty($agent['maintenance_mode']) ? 1 : 0;
        if (array_key_exists('has_patches_pending', $agent)) $h['patches_pending']  = !empty($agent['has_patches_pending']) ? 1 : 0;

        // Boot time: Tactical exposes a unix ts (boot_time); Level an ISO string.
        $bt = $agent['boot_time'] ?? $agent['last_reboot_time'] ?? null;
        if (!empty($bt)) {
            if (is_numeric($bt)) {
                $h['last_boot'] = date('Y-m-d H:i:s', intval($bt));
            } elseif (($ts = strtotime((string) $bt))) {
                $h['last_boot'] = date('Y-m-d H:i:s', $ts);
            }
        }

        // Direct usage percentages when a vendor supplies them.
        if (isset($agent['cpu_load']) && is_numeric($agent['cpu_load'])) $h['cpu_percent'] = max(0, min(100, intval(round((float) $agent['cpu_load']))));
        if (isset($agent['mem'])      && is_numeric($agent['mem']))      $h['ram_percent'] = max(0, min(100, intval(round((float) $agent['mem']))));

        // Disk %: worst (highest used %) fixed volume from the disks array.
        if (!empty($agent['disks']) && is_array($agent['disks'])) {
            $maxpct = null;
            foreach ($agent['disks'] as $dk) {
                if (is_array($dk) && isset($dk['percent']) && is_numeric($dk['percent'])) {
                    $maxpct = max($maxpct ?? 0, intval($dk['percent']));
                }
            }
            if ($maxpct !== null) $h['disk_percent'] = max(0, min(100, $maxpct));
        }

        // Tactical fallback: derive CPU/RAM % from WMI when not given directly.
        if ($h['cpu_percent'] === null) $h['cpu_percent'] = $this->wmiCpuPercent($wmi);
        if ($h['ram_percent'] === null) $h['ram_percent'] = $this->wmiRamPercent($wmi);

        return $h;
    }

    // Average LoadPercentage across CPU packages in a Tactical wmi_detail.
    private function wmiCpuPercent(array $wmi): ?int {
        $cpus = $wmi['cpu'] ?? [];
        if (!is_array($cpus)) return null;
        $vals = [];
        foreach ($cpus as $grp) {
            $c = (is_array($grp) && isset($grp[0]) && is_array($grp[0])) ? $grp[0] : $grp;
            if (is_array($c) && isset($c['LoadPercentage']) && is_numeric($c['LoadPercentage'])) {
                $vals[] = (float) $c['LoadPercentage'];
            }
        }
        if (!$vals) return null;
        return max(0, min(100, intval(round(array_sum($vals) / count($vals)))));
    }

    // Used-memory % from a Tactical wmi_detail OS block (values in KB).
    private function wmiRamPercent(array $wmi): ?int {
        $o = $wmi['os'] ?? [];
        while (is_array($o) && isset($o[0]) && is_array($o[0])) { $o = $o[0]; }
        if (!is_array($o)) return null;
        $total = $o['TotalVisibleMemorySize'] ?? null;
        $free  = $o['FreePhysicalMemory'] ?? null;
        if (!is_numeric($total) || !is_numeric($free) || $total <= 0) return null;
        return max(0, min(100, intval(round((($total - $free) / $total) * 100))));
    }

    /**
     * Build the SET fragment (trailing comma included) that persists a health
     * snapshot onto asset_rmm_links. Safe to embed directly in an UPDATE/INSERT.
     */
    private function healthSetSql(array $h): string {
        $m    = $this->mysqli;
        $cpu  = $h['cpu_percent']  === null ? 'NULL' : intval($h['cpu_percent']);
        $ram  = $h['ram_percent']  === null ? 'NULL' : intval($h['ram_percent']);
        $disk = $h['disk_percent'] === null ? 'NULL' : intval($h['disk_percent']);
        $reboot = !empty($h['needs_reboot']) ? 1 : 0;
        $maint  = !empty($h['maintenance_mode']) ? 1 : 0;
        $boot   = empty($h['last_boot']) ? 'NULL' : "'" . mysqli_real_escape_string($m, $h['last_boot']) . "'";
        $patch  = $h['patches_pending'] === null ? 'NULL' : intval($h['patches_pending']);
        return "rmm_cpu_percent=$cpu, rmm_ram_percent=$ram, rmm_disk_percent=$disk, "
             . "rmm_needs_reboot=$reboot, rmm_maintenance_mode=$maint, rmm_last_boot=$boot, "
             . "rmm_patches_pending=$patch, rmm_health_updated_at=NOW(),";
    }

    private function syncInterfaces(int $asset_id, array $wmiDetail): void {
        $m = $this->mysqli;
        $netConfig = $wmiDetail['network_config'] ?? [];

        foreach ($netConfig as $entry) {
            // WMI returns each adapter wrapped in its own single-element array
            $nic = (is_array($entry) && isset($entry[0]) && is_array($entry[0])) ? $entry[0] : $entry;
            if (!is_array($nic)) continue;

            $ips = $nic['IPAddress'] ?? null;
            if (empty($ips)) continue; // skip adapters with no configured IP

            $caption = (string) ($nic['Description'] ?? $nic['Caption'] ?? '');
            $mac     = strtolower(str_replace('-', ':', (string) ($nic['MACAddress'] ?? '')));

            if (str_contains(strtolower($caption), 'bluetooth')) continue;

            if (preg_match('/wi-?fi|wireless/i', $caption)) {
                $type = 'WiFi';
            } elseif (preg_match('/vmware|hyper-v|virtual|tap-windows|tunnel|wintun|miniport|kernel debug|ras async|loopback/i', $caption)) {
                $type = 'Virtual';
            } else {
                $type = 'Ethernet';
            }

            $ipv4 = ''; $ipv6 = '';
            foreach ((array) $ips as $ip) {
                if (str_contains((string) $ip, ':')) {
                    if (!$ipv6) $ipv6 = $ip;
                } else {
                    if (!$ipv4) $ipv4 = $ip;
                }
            }

            $name = preg_replace('/^\[\d+\]\s*/', '', $caption);
            $name = mysqli_real_escape_string($m, substr($name, 0, 200));
            $type_esc = mysqli_real_escape_string($m, $type);
            $mac_esc  = mysqli_real_escape_string($m, $mac);
            $ipv4_esc = mysqli_real_escape_string($m, $ipv4);
            $ipv6_esc = mysqli_real_escape_string($m, $ipv6);

            $existing = null;
            if ($mac) {
                $existing = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT interface_id FROM asset_interfaces
                     WHERE interface_asset_id=$asset_id AND LOWER(interface_mac)='$mac_esc' LIMIT 1"
                ));
            }
            if (!$existing) {
                $existing = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT interface_id FROM asset_interfaces
                     WHERE interface_asset_id=$asset_id AND interface_name='$name' AND interface_archived_at IS NULL LIMIT 1"
                ));
            }

            if ($existing) {
                mysqli_query($m,
                    "UPDATE asset_interfaces SET
                     interface_type='$type_esc',
                     interface_mac='$mac_esc',
                     interface_ip='$ipv4_esc',
                     interface_ipv6='$ipv6_esc'
                     WHERE interface_id={$existing['interface_id']}"
                );
            } else {
                mysqli_query($m,
                    "INSERT INTO asset_interfaces SET
                     interface_asset_id=$asset_id,
                     interface_name='$name',
                     interface_type='$type_esc',
                     interface_mac='$mac_esc',
                     interface_ip='$ipv4_esc',
                     interface_ipv6='$ipv6_esc',
                     interface_primary=0"
                );
            }
        }
    }

    // Maps the RMM-side client/group name to an existing ITFlow client by
    // exact (case-insensitive) name match. Returns 0 if no match is found.
    private function resolveClientId(array $agent): int {
        $m    = $this->mysqli;
        $name = trim((string) ($agent['client_name'] ?? $agent['group_name'] ?? ''));
        if ($name === '') {
            // Single-tenant integrations (e.g. Sophos Central without a
            // Partner/Organization credential) have no per-device client/
            // group name to match against — fall back to the integration's
            // configured default client instead of skipping the device.
            return $this->getIntegrationDefaultClientId();
        }

        $esc = mysqli_real_escape_string($m, $name);
        $row = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT client_id FROM clients WHERE LOWER(client_name)=LOWER('$esc') AND client_archived_at IS NULL LIMIT 1"
        ));
        return $row ? intval($row['client_id']) : 0;
    }

    private function getIntegrationDefaultClientId(): int {
        static $cache = [];
        if (array_key_exists($this->integration_id, $cache)) {
            return $cache[$this->integration_id];
        }
        $row = mysqli_fetch_assoc(mysqli_query($this->mysqli,
            "SELECT default_client_id FROM rmm_integrations WHERE id={$this->integration_id}"
        ));
        return $cache[$this->integration_id] = intval($row['default_client_id'] ?? 0);
    }

    // Assigns an asset to its resolved client if it doesn't already have one.
    private function backfillClientId(int $asset_id, int $current_client_id, int $resolved_client_id): void {
        if ($current_client_id !== 0 || $resolved_client_id === 0) return;
        mysqli_query($this->mysqli, "UPDATE assets SET asset_client_id=$resolved_client_id WHERE asset_id=$asset_id");
    }

    private function extractMacs(array $agent): array {
        $macs = [];
        if (!empty($agent['wmi_detail']['network_config'])) {
            foreach ($agent['wmi_detail']['network_config'] as $nic) {
                if (!empty($nic['MACAddress'])) {
                    $macs[] = strtolower(str_replace('-', ':', $nic['MACAddress']));
                }
            }
        }
        return $macs;
    }

    private function guessAssetType(string $os_name): string {
        $os = strtolower($os_name);
        if (str_contains($os, 'firewall') || str_contains($os, 'sfos')) return 'Firewall/Router';
        if (str_contains($os, 'server')) return 'Server';
        if (str_contains($os, 'linux'))  return 'Server';
        return 'Desktop';
    }

    public function startSyncLog(): int {
        global $mysqli;
        mysqli_query($mysqli,
            "INSERT INTO rmm_sync_log SET integration_id={$this->integration_id}, triggered_by={$this->triggered_by}"
        );
        return intval(mysqli_insert_id($mysqli));
    }

    public function finishSyncLog(int $log_id, array $stats): void {
        global $mysqli;
        $status  = empty($stats['errors']) ? 'success' : 'failed';
        $errors  = mysqli_real_escape_string($mysqli, implode('; ', $stats['errors']));
        mysqli_query($mysqli,
            "UPDATE rmm_sync_log SET
             finished_at=NOW(), status='$status',
             assets_created={$stats['created']}, assets_updated={$stats['updated']},
             assets_matched={$stats['matched']}, assets_skipped={$stats['skipped']},
             errors='$errors'
             WHERE id=$log_id"
        );
    }
}
