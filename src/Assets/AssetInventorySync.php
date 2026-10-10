<?php

declare(strict_types=1);

namespace ITFlow\Assets;

/**
 * Writes what an inventory source (the built-in agent, a vendor RMM, Intune) reports onto the asset's own fields, without ever
 * overwriting something a person typed.
 *
 * Per field the rule is: write the reported value when the field is blank, or when it still holds exactly the value this sync wrote
 * last time (so a hardware change flows through); leave it alone when it holds anything else (a human edit). What was last written is
 * kept per asset in asset_sync_state (JSON), so a human edit is never mistaken for an old sync value and the next sync leaves it.
 *
 * Fields: make, model, os, cpu, ram (assets.asset_make / asset_model / asset_os / asset_cpu / asset_ram) and the primary network
 * interface (asset_interfaces.interface_ip / interface_mac of the primary row). Intune additionally assigns the asset's contact.
 */
final class AssetInventorySync
{
    /** fact key => assets column */
    public const ASSET_FIELDS = ['make' => 'asset_make', 'model' => 'asset_model', 'os' => 'asset_os', 'cpu' => 'asset_cpu', 'ram' => 'asset_ram'];
    private const MAX = ['make' => 200, 'model' => 200, 'os' => 200, 'cpu' => 300, 'ram' => 50, 'nic_ip' => 200, 'nic_mac' => 200];

    public function __construct(private \mysqli $db)
    {
    }

    /**
     * The decision for one field. Pure.
     *
     * @return 'write'|'record'|'keep' write: set the field; record: it already holds the value, just remember it; keep: a human value, leave it
     */
    public static function decide(string $current, ?string $lastSynced, string $reported): string
    {
        $current = trim($current);
        if ($reported === '') {
            return 'keep';                      // the source knows nothing: never blank a field
        }
        if ($current === $reported) {
            return 'record';
        }
        if ($current === '' || ($lastSynced !== null && $current === $lastSynced)) {
            return 'write';
        }

        return 'keep';
    }

    /**
     * @param array<string,mixed> $facts any of make, model, os, cpu, ram, nic_ip, nic_mac (blank or missing = not reported)
     * @return array{written:array<string,string>,kept:list<string>,skipped:bool}
     */
    public function apply(int $assetId, array $facts, string $source = ''): array
    {
        $clean = [];
        foreach (self::MAX as $k => $max) {
            $v = isset($facts[$k]) ? trim(strip_tags((string) $facts[$k])) : '';
            if ($v !== '') {
                $clean[$k] = mb_substr($v, 0, $max);
            }
        }
        $out = ['written' => [], 'kept' => [], 'skipped' => false];
        if ($assetId < 1 || $clean === []) {
            $out['skipped'] = true;

            return $out;
        }
        $state = $this->loadState($assetId);
        $fp = md5(json_encode($clean));
        if (($state['_fp'] ?? '') === $fp) {
            $out['skipped'] = true;             // same report as last time: nothing to compare (keeps check-ins cheap)

            return $out;
        }
        $res = $this->db->query('SELECT ' . implode(', ', self::ASSET_FIELDS) . ' FROM assets WHERE asset_id = ' . $assetId);
        $asset = $res ? $res->fetch_assoc() : null;
        if (!$asset) {
            $out['skipped'] = true;

            return $out;
        }
        $sets = [];
        foreach (self::ASSET_FIELDS as $k => $col) {
            if (!isset($clean[$k])) {
                continue;
            }
            $action = self::decide((string) ($asset[$col] ?? ''), $state[$k] ?? null, $clean[$k]);
            if ($action === 'write') {
                $sets[] = $col . " = '" . $this->db->real_escape_string($clean[$k]) . "'";
                $out['written'][$k] = $clean[$k];
            }
            if ($action === 'keep') {
                $out['kept'][] = $k;
            } else {
                $state[$k] = $clean[$k];
            }
        }
        if ($sets) {
            $this->db->query('UPDATE assets SET ' . implode(', ', $sets) . ', asset_updated_at = asset_updated_at WHERE asset_id = ' . $assetId);
        }
        if (isset($clean['nic_ip']) || isset($clean['nic_mac'])) {
            $nic = $this->applyPrimaryNic($assetId, $clean['nic_ip'] ?? '', $clean['nic_mac'] ?? '', $state);
            $out['written'] += $nic['written'];
            $out['kept'] = array_merge($out['kept'], $nic['kept']);
        }
        // Remember what was reported, so an identical report (every agent check-in) costs one read. A person who clears a field later gets the
        // value back on the next CHANGED report.
        $state['_fp'] = $fp;
        $this->saveState($assetId, $state);

        return $out;
    }

    /** Remember the values an INSERT wrote, so the next sync is allowed to refresh them. */
    public function recordWritten(int $assetId, array $values): void
    {
        $state = $this->loadState($assetId);
        foreach (self::MAX as $k => $max) {
            $v = isset($values[$k]) ? trim((string) $values[$k]) : '';
            if ($v !== '') {
                $state[$k] = mb_substr($v, 0, $max);
            }
        }
        unset($state['_fp']);
        $this->saveState($assetId, $state);
    }

    /**
     * @param array<string,mixed> $state updated in place
     * @return array{written:array<string,string>,kept:list<string>}
     */
    private function applyPrimaryNic(int $assetId, string $ip, string $mac, array &$state): array
    {
        $out = ['written' => [], 'kept' => []];
        $mac = strtolower(str_replace('-', ':', $mac));
        $res = $this->db->query("SELECT interface_id, interface_ip, interface_mac FROM asset_interfaces WHERE interface_asset_id = $assetId AND interface_primary = 1 AND interface_archived_at IS NULL ORDER BY interface_id LIMIT 1");
        $row = $res ? $res->fetch_assoc() : null;
        if (!$row) {
            // No primary interface: promote one that already carries this MAC (a vendor RMM may have created it), else create "Primary".
            $iid = 0;
            if ($mac !== '') {
                $m = $this->db->real_escape_string($mac);
                $r = $this->db->query("SELECT interface_id FROM asset_interfaces WHERE interface_asset_id = $assetId AND LOWER(interface_mac) = '$m' AND interface_archived_at IS NULL ORDER BY interface_id LIMIT 1");
                $iid = $r && ($x = $r->fetch_row()) ? (int) $x[0] : 0;
            }
            if ($iid > 0) {
                $this->db->query("UPDATE asset_interfaces SET interface_primary = 1 WHERE interface_id = $iid");
                $row = ['interface_id' => $iid, 'interface_ip' => $this->scalar("SELECT interface_ip FROM asset_interfaces WHERE interface_id = $iid"), 'interface_mac' => $mac];
            } else {
                $this->db->query("INSERT INTO asset_interfaces SET interface_asset_id = $assetId, interface_name = 'Primary', interface_type = 'Ethernet', interface_primary = 1,
                    interface_ip = '" . $this->db->real_escape_string($ip) . "', interface_mac = '" . $this->db->real_escape_string($mac) . "'");
                if ($ip !== '') { $state['nic_ip'] = $ip; $out['written']['nic_ip'] = $ip; }
                if ($mac !== '') { $state['nic_mac'] = $mac; $out['written']['nic_mac'] = $mac; }

                return $out;
            }
        }
        $iid = (int) $row['interface_id'];
        $sets = [];
        foreach (['nic_ip' => ['interface_ip', $ip], 'nic_mac' => ['interface_mac', $mac]] as $k => [$col, $val]) {
            if ($val === '') {
                continue;
            }
            $action = self::decide((string) ($row[$col] ?? ''), $state[$k] ?? null, $val);
            if ($action === 'write') {
                $sets[] = "$col = '" . $this->db->real_escape_string($val) . "'";
                $out['written'][$k] = $val;
            }
            if ($action === 'keep') {
                $out['kept'][] = $k;
            } else {
                $state[$k] = $val;
            }
        }
        if ($sets) {
            $this->db->query('UPDATE asset_interfaces SET ' . implode(', ', $sets) . ' WHERE interface_id = ' . $iid);
        }

        return $out;
    }

    /**
     * Intune: the device's primary user (UPN) becomes the asset's contact when exactly one non-archived contact of the asset's
     * department has that address. Never replaces a contact a person chose; follows the primary user while the contact is still the one set here.
     *
     * @return 'assigned'|'unchanged'|'no_match'|'ambiguous'|'kept_human'|'no_department'|'no_upn'
     */
    public function applyPrimaryUser(int $assetId, string $upn, ?int $changedByUserId = null): string
    {
        $upn = strtolower(trim($upn));
        if ($upn === '' || !str_contains($upn, '@')) {
            return 'no_upn';
        }
        $res = $this->db->query("SELECT asset_client_id, asset_contact_id FROM assets WHERE asset_id = $assetId");
        $asset = $res ? $res->fetch_assoc() : null;
        if (!$asset || (int) $asset['asset_client_id'] < 1) {
            return 'no_department';             // "in that client": an asset with no department has no contact list to match in
        }
        $client = (int) $asset['asset_client_id'];
        $u = $this->db->real_escape_string($upn);
        $r = $this->db->query("SELECT contact_id FROM contacts WHERE contact_client_id = $client AND contact_archived_at IS NULL AND LOWER(TRIM(contact_email)) = '$u' LIMIT 2");
        $ids = [];
        while ($r && ($x = $r->fetch_row())) {
            $ids[] = (int) $x[0];
        }
        if (count($ids) === 0) {
            return 'no_match';
        }
        if (count($ids) > 1) {
            return 'ambiguous';
        }
        $contact = $ids[0];
        $current = (int) $asset['asset_contact_id'];
        $state = $this->loadState($assetId);
        $last = isset($state['contact_id']) ? (int) $state['contact_id'] : 0;
        if ($current === $contact) {
            if ($last !== $contact) { $state['contact_id'] = $contact; $this->saveState($assetId, $state); }

            return 'unchanged';
        }
        if ($current !== 0 && $current !== $last) {
            return 'kept_human';
        }
        $this->db->query("UPDATE assets SET asset_contact_id = $contact, asset_updated_at = asset_updated_at WHERE asset_id = $assetId");
        (new AssetAssignmentService($this->db))->recordChangeIfNeeded($assetId, $contact, $changedByUserId);
        $state['contact_id'] = $contact;
        $this->saveState($assetId, $state);

        return 'assigned';
    }

    /** @return array<string,mixed> */
    public function loadState(int $assetId): array
    {
        $res = $this->db->query("SELECT state_json FROM asset_sync_state WHERE asset_id = $assetId");
        $row = $res ? $res->fetch_row() : null;
        $j = $row && $row[0] !== null ? json_decode((string) $row[0], true) : null;

        return is_array($j) ? $j : [];
    }

    /** @param array<string,mixed> $state */
    private function saveState(int $assetId, array $state): void
    {
        $json = $this->db->real_escape_string(json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->db->query("INSERT INTO asset_sync_state (asset_id, state_json) VALUES ($assetId, '$json') ON DUPLICATE KEY UPDATE state_json = VALUES(state_json)");
    }

    private function scalar(string $sql): string
    {
        $r = $this->db->query($sql);
        $x = $r ? $r->fetch_row() : null;

        return $x ? (string) ($x[0] ?? '') : '';
    }

    // ------------------------------------------------------------------------------------------------------ fact helpers

    /** "Windows" + "Windows 11 23H2" -> "Windows 11 23H2" (not "Windows Windows 11 23H2"); "Windows" + "10.0.22631" -> "Windows 10.0.22631". */
    public static function osLabel(string $name, string $version): string
    {
        $name = trim($name);
        $version = trim($version);
        if ($name === '' || ($version !== '' && stripos($version, $name) === 0)) {
            return $version;
        }

        return trim($name . ' ' . $version);
    }

    /** "16" / "15.8" (GB) / "16 GB" -> "16 GB" */
    public static function ramLabel(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (is_numeric($raw)) {
            $n = (float) $raw;

            return ($n == floor($n) ? (string) (int) $n : rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.')) . ' GB';
        }

        return $raw;
    }

    /**
     * The first adapter that looks like the machine's real network connection.
     *
     * @param list<array{name?:?string,mac?:?string,ips?:list<string>}> $nics
     * @return array{nic_ip:string,nic_mac:string}
     */
    public static function primaryNic(array $nics): array
    {
        foreach ($nics as $n) {
            $name = (string) ($n['name'] ?? '');
            if ($name !== '' && preg_match('/vmware|hyper-v|virtual|vethernet|docker|loopback|tunnel|tap-windows|wintun|miniport|bluetooth|vpn|^lo$|^veth|^br-|^virbr/i', $name)) {
                continue;
            }
            foreach ((array) ($n['ips'] ?? []) as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with((string) $ip, '169.254.') && !str_starts_with((string) $ip, '127.')) {
                    return ['nic_ip' => (string) $ip, 'nic_mac' => strtolower(str_replace('-', ':', (string) ($n['mac'] ?? '')))];
                }
            }
        }

        return ['nic_ip' => '', 'nic_mac' => ''];
    }
}
