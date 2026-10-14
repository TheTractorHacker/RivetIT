<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Turns ManageEngine MDM device records into rows of asset_mdm_links (matched to an asset) or mdm_unmatched_devices (a review list).
 * Mirrors Microsoft\IntuneAssetMapper and the RMM mapper (ITFlow\Integrations\ClientMap, ITFlow\Assets\AssetInventorySync).
 *
 * Match priority (first key that finds any asset decides):
 *   1. mdm_device_id already in asset_mdm_links (already linked: refresh the row)
 *   2. serial number  = assets.asset_serial
 *   3. IMEI           = assets.asset_serial (people record a phone's IMEI as its serial)
 *   4. UDID           = assets.asset_serial
 *   5. a UNIQUE asset with the same name in the device's department (needs a department: see below)
 * More than one asset for a key, one asset claimed by two live devices, or one serial on two live devices = ambiguous: unmatched.
 *
 * Department: MSP edition = the device's customer name through ClientMap (a saved mapping, an exact department-name match, else the
 * "needs mapping" queue; a mapping marked ignored skips the device). Non-MSP = the connection's default department, else one queue entry for the
 * whole account. With no department a new asset is never created: the device waits in the review list ("needs_mapping").
 *
 * No match and a department: with "create assets" on, one asset is created (type Phone / Tablet / by platform); otherwise the device waits in the
 * review list for Create asset / Link to existing asset / Ignore.
 *
 * Asset fields: only through ITFlow\Assets\AssetInventorySync (make, model, OS) and applyPrimaryUser (the contact): a field a person edited is never
 * overwritten (asset_sync_state). Wi-Fi MAC is not returned by the documented device endpoints, so it is not used.
 */
final class MdmDeviceMapper
{
    /** column => type (s string, b bool/null, i int/null, d datetime/null) of the link and unmatched tables' shared device columns. */
    public const FIELDS = [
        'device_name' => 's', 'platform' => 's', 'os_version' => 's', 'model' => 's', 'product_name' => 's',
        'serial_number' => 's', 'udid' => 's', 'imei' => 's', 'enrollment_status' => 's',
        'is_supervised' => 'b', 'passcode_compliant' => 'b', 'is_lost_mode' => 'b', 'is_rooted' => 'b',
        'owned_by' => 's', 'user_name' => 's', 'user_email' => 's', 'group_names' => 's',
        'battery_level' => 'i', 'storage_total' => 'i', 'storage_available' => 'i',
        'registered_at' => 'd', 'last_contact_at' => 'd',
        'customer_id' => 's', 'customer_name' => 's', 'raw_json' => 's',
    ];
    /** Label of the single "client" an account-wide (non-MSP) connection queues for mapping. */
    public const ACCOUNT_LABEL = 'All devices (ManageEngine MDM account)';
    /** integration_client_map.integration_id for MDM is the NEGATIVE connection id, so it can never collide with an rmm_integrations id. */
    public static function mapKey(int $integrationId): int
    {
        return -abs($integrationId);
    }
    private const MAXLEN = ['device_name' => 200, 'platform' => 30, 'os_version' => 100, 'model' => 200, 'product_name' => 200, 'serial_number' => 200,
        'udid' => 100, 'imei' => 100, 'enrollment_status' => 20, 'owned_by' => 20, 'user_name' => 200, 'user_email' => 200, 'group_names' => 500,
        'customer_id' => 40, 'customer_name' => 200, 'raw_json' => 4000];

    private ?\ITFlow\Integrations\ClientMap $clientMap = null;
    private ?\ITFlow\Assets\AssetInventorySync $assetSync = null;

    /**
     * @param array{auto_create?:bool,default_client_id?:int,msp_mode?:bool} $config the connection's mapping settings
     */
    public function __construct(private \mysqli $db, private int $integrationId, private int $triggeredBy = 0, private array $config = [])
    {
    }

    /** @return array{auto_create:bool,default_client_id:int,msp_mode:bool} from a manageengine_mdm_integrations row */
    public static function configFromRow(array $row): array
    {
        return ['auto_create' => (int) ($row['auto_create_assets'] ?? 0) === 1, 'default_client_id' => (int) ($row['default_client_id'] ?? 0), 'msp_mode' => (int) ($row['msp_mode'] ?? 0) === 1];
    }

    /**
     * One normalized device from the three documented responses. Pure.
     *
     * @param array<string,mixed> $list    row of GET /api/v1/mdm/devices
     * @param array<string,mixed>|null $detail   GET /api/v1/mdm/devices/{id} (battery, storage, supervised, security ...), or null
     * @param array<string,mixed>|null $summary  GET /api/v1/mdm/devices/{id}/summary (last_contact_time, group[]), or null
     * @return array<string,mixed> mdm_device_id plus every FIELDS key (null = not reported, left as it was on update)
     */
    public static function normalize(array $list, ?array $detail = null, ?array $summary = null): array
    {
        $str = static fn ($v): string => is_scalar($v) ? trim(strip_tags((string) $v)) : '';
        $bool = static fn ($v): ?int => $v === null ? null : (filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        $num = static fn ($v): ?int => (is_numeric($v) && $v >= 0) ? (int) $v : null;
        $ms = static function ($v): ?string {
            return (is_numeric($v) && (float) $v > 0) ? date('Y-m-d H:i:s', (int) floor((float) $v / 1000)) : null;
        };

        $detail ??= [];
        $summary ??= [];
        $user = is_array($list['user'] ?? null) ? $list['user'] : [];
        $security = is_array($detail['security'] ?? null) ? $detail['security'] : [];
        $imei = $str($list['imei'] ?? '');
        if ($imei === '' && is_array($detail['sims'] ?? null) && is_array($detail['sims'][0] ?? null)) {
            $imei = $str($detail['sims'][0]['imei'] ?? '');
        }
        $groups = [];
        foreach (is_array($summary['group'] ?? null) ? $summary['group'] : [] as $g) {
            if (is_array($g) && $str($g['group_name'] ?? '') !== '') {
                $groups[] = $str($g['group_name']);
            }
        }
        $owned = $list['owned_by'] ?? ($detail['owned_by'] ?? null);
        $removed = !empty($list['is_removed']);
        $unregistered = isset($detail['unregistered_time']) && is_numeric($detail['unregistered_time']) && (float) $detail['unregistered_time'] > 0;

        $raw = array_filter([
            'device_id' => $str($list['device_id'] ?? ''), 'platform_type' => $str($list['platform_type'] ?? ''), 'platform_type_id' => $list['platform_type_id'] ?? null,
            'os_version' => $str($list['os_version'] ?? ''), 'model' => $str($list['model'] ?? ''), 'product_name' => $str($list['product_name'] ?? ''),
            'owned_by' => $list['owned_by'] ?? null, 'is_removed' => $list['is_removed'] ?? null, 'is_lost_mode_enabled' => $list['is_lost_mode_enabled'] ?? null,
            'is_supervised' => $detail['is_supervised'] ?? null, 'battery_level' => $detail['battery_level'] ?? null,
            'registered_time' => $detail['registered_time'] ?? null, 'unregistered_time' => $detail['unregistered_time'] ?? null,
            'last_contact_time' => $summary['last_contact_time'] ?? null, 'summary' => is_array($list['summary'] ?? null) ? $list['summary'] : null,
        ], static fn ($v) => $v !== null && $v !== '');

        return [
            'raw_json' => (string) json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'customer_id' => null, 'customer_name' => null,
            'mdm_device_id' => $str($list['device_id'] ?? ''),
            'device_name' => $str($list['device_name'] ?? ($detail['device_name'] ?? ($summary['device_name'] ?? ''))),
            'platform' => $str($list['platform_type'] ?? ($summary['platform_type'] ?? '')),
            'os_version' => $str($list['os_version'] ?? ($detail['os']['os_version'] ?? '')),
            'model' => $str($list['model'] ?? ($detail['model'] ?? '')),
            'product_name' => $str($list['product_name'] ?? ($detail['product_name'] ?? '')),
            'serial_number' => $str($list['serial_number'] ?? ($detail['serial_number'] ?? '')),
            'udid' => $str($list['udid'] ?? ($detail['udid'] ?? '')),
            'imei' => $imei,
            'enrollment_status' => $removed ? 'removed' : ($unregistered ? 'unenrolled' : 'enrolled'),
            'is_supervised' => $bool($detail['is_supervised'] ?? null),
            // The docs spell this field "passcode_complaint" (sic); the corrected spelling is accepted too.
            'passcode_compliant' => $bool($security['passcode_complaint'] ?? ($security['passcode_compliant'] ?? null)),
            'is_lost_mode' => $bool($list['is_lost_mode_enabled'] ?? ($detail['is_lost_mode_enabled'] ?? null)),
            'is_rooted' => $bool($security['device_rooted'] ?? null),
            'owned_by' => $owned === null ? '' : (((string) $owned === '1') ? 'corporate' : (((string) $owned === '2') ? 'personal' : $str($owned))),
            'user_name' => $str($user['user_name'] ?? ''),
            'user_email' => $str($user['user_email'] ?? ''),
            'group_names' => $groups ? implode(', ', $groups) : (array_key_exists('group', $summary) ? '' : null),
            'battery_level' => isset($detail['battery_level']) ? $num($detail['battery_level']) : null,
            'storage_total' => isset($detail['device_capacity']) ? $num($detail['device_capacity']) : null,
            'storage_available' => isset($detail['available_device_capacity']) ? $num($detail['available_device_capacity']) : null,
            'registered_at' => $ms($detail['registered_time'] ?? null),
            'last_contact_at' => $ms($summary['last_contact_time'] ?? null),
        ];
    }

    /**
     * @param list<array<string,mixed>> $devices normalized devices
     * @return array{total:int,updated:int,matched:int,created:int,unmatched:int,skipped:int,errors:list<string>}
     */
    public function syncDevices(array $devices): array
    {
        $stats = ['total' => count($devices), 'updated' => 0, 'matched' => 0, 'created' => 0, 'unmatched' => 0, 'skipped' => 0, 'errors' => []];

        // Which ids are live, and how many live devices share each match key (a key on two live devices is ambiguous for both).
        $live = [];
        $keyCount = [];
        foreach ($devices as $d) {
            if ($d['mdm_device_id'] === '' || $d['enrollment_status'] === 'removed') {
                continue;
            }
            $live[$d['mdm_device_id']] = true;
            foreach (['serial_number', 'imei', 'udid'] as $k) {
                $key = self::matchKey($d, $k);
                if ($key !== '') {
                    $keyCount[$k . ':' . $key] = ($keyCount[$k . ':' . $key] ?? 0) + 1;
                }
            }
        }

        foreach ($devices as $d) {
            try {
                $stats[$this->syncDevice($d, $live, $keyCount)]++;
            } catch (\Throwable $e) {
                $stats['errors'][] = ($d['device_name'] ?: 'device') . ': ' . $e->getMessage();
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    /** @param array<string,true> $live @param array<string,int> $keyCount */
    private function syncDevice(array $d, array $live, array $keyCount): string
    {
        $id = $d['mdm_device_id'];
        if ($id === '') {
            return 'skipped';
        }
        $idEsc = $this->esc($id);
        $iid = $this->integrationId;

        $existing = $this->one("SELECT id, asset_id FROM asset_mdm_links WHERE integration_id=$iid AND mdm_device_id='$idEsc' LIMIT 1");
        if ($existing) {
            $this->query('UPDATE asset_mdm_links SET ' . $this->setSql($d, true) . ', last_sync=NOW() WHERE id=' . (int) $existing['id']);
            $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");
            if ($d['enrollment_status'] !== 'removed') {
                $this->syncAssetFields((int) $existing['asset_id'], $d);
            }

            return 'updated';
        }
        if ($d['enrollment_status'] === 'removed') {
            $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");

            return 'skipped';
        }

        $found = $this->findAsset($d, $live, $keyCount);
        $client = $this->resolveClient($d);
        if ($found['asset_id'] === 0 && $found['reason'] === 'no_match' && $client['client_id'] > 0) {
            $byName = $this->findByName($d, $client['client_id'], $live);
            if ($byName > 0) {
                $found = ['asset_id' => $byName, 'method' => 'name', 'reason' => '', 'candidates' => [$byName]];
            } elseif ($byName < 0) {
                $found = ['asset_id' => 0, 'method' => 'name', 'reason' => 'ambiguous', 'candidates' => []];   // two assets carry this name: a person decides
            }
        }
        if ($found['asset_id'] === 0) {
            if ($found['reason'] === 'no_match') {
                if ($client['source'] === 'ignored') {
                    $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");

                    return 'skipped';   // an administrator told the mapping queue to ignore this customer / account
                }
                if (!empty($this->config['auto_create']) && $client['client_id'] < 1) {
                    $this->upsertUnmatched($d, 'needs_mapping', []);

                    return 'unmatched';
                }
                if (!empty($this->config['auto_create'])) {
                    $assetId = $this->insertAsset($d, $client['client_id']);
                    $this->linkAsset($assetId, $d, 'created');
                    $this->syncAssetFields($assetId, $d);
                    $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");
                    $this->auditCreate($assetId, $d, $client['client_id']);

                    return 'created';
                }
            }
            $this->upsertUnmatched($d, $found['reason'], $found['candidates']);

            return 'unmatched';
        }

        $this->linkAsset($found['asset_id'], $d, $found['method']);
        $this->syncAssetFields($found['asset_id'], $d);
        $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");

        return 'matched';
    }

    /**
     * The department a NEW asset for this device belongs in. @return array{client_id:int,source:string} source: mapped | name | default | ignored | pending
     */
    private function resolveClient(array $d): array
    {
        $default = (int) ($this->config['default_client_id'] ?? 0);
        if ($default > 0 && !$this->clientExists($default)) {
            $default = 0;
        }
        if (empty($this->config['msp_mode'])) {
            if ($default > 0) {
                return ['client_id' => $default, 'source' => 'default'];
            }
            $name = self::ACCOUNT_LABEL;
        } else {
            $name = trim((string) ($d['customer_name'] ?? ''));
            if ($name === '') {
                return $default > 0 ? ['client_id' => $default, 'source' => 'default'] : ['client_id' => 0, 'source' => 'pending'];
            }
        }
        if (empty($this->config['auto_create'])) {
            // Nothing will be created, so nothing needs the queue: a saved mapping or an exact department name still helps the name match.
            $map = $this->one('SELECT client_id, map_status FROM integration_client_map WHERE integration_id=' . self::mapKey($this->integrationId) . " AND external_name='" . $this->esc($name) . "' LIMIT 1");
            if ($map) {
                return $map['map_status'] === 'mapped' && $this->clientExists((int) $map['client_id']) ? ['client_id' => (int) $map['client_id'], 'source' => 'mapped'] : ['client_id' => 0, 'source' => $map['map_status'] === 'ignored' ? 'ignored' : 'pending'];
            }
            $byName = $this->one("SELECT client_id FROM clients WHERE LOWER(client_name)=LOWER('" . $this->esc($name) . "') AND client_archived_at IS NULL LIMIT 1");

            return $byName ? ['client_id' => (int) $byName['client_id'], 'source' => 'name'] : ($default > 0 ? ['client_id' => $default, 'source' => 'default'] : ['client_id' => 0, 'source' => 'pending']);
        }
        $this->clientMap ??= new \ITFlow\Integrations\ClientMap($this->db);
        $r = $this->clientMap->resolve(self::mapKey($this->integrationId), $name, (string) ($d['device_name'] ?? ''));
        if ($r['source'] === 'pending' && $default > 0) {
            return ['client_id' => $default, 'source' => 'default'];
        }

        return $r;
    }

    private function clientExists(int $clientId): bool
    {
        return (bool) $this->one("SELECT client_id FROM clients WHERE client_id=$clientId AND client_archived_at IS NULL LIMIT 1");
    }

    /**
     * Name fallback: exactly one non-archived asset with this name (case-insensitive) in the department, not already linked to another live
     * device of this connection. Returns 0 when there is none, -1 when more than one asset carries the name.
     *
     * @param array<string,true> $live
     */
    private function findByName(array $d, int $clientId, array $live): int
    {
        $name = trim((string) ($d['device_name'] ?? ''));
        if (mb_strlen($name) < 3) {
            return 0;
        }
        $ids = [];
        $res = $this->query('SELECT asset_id FROM assets WHERE asset_archived_at IS NULL AND asset_client_id=' . $clientId . " AND LOWER(asset_name)=LOWER('" . $this->esc($name) . "') LIMIT 3");
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $ids[] = (int) $r['asset_id'];
        }
        if (count($ids) > 1) {
            return -1;
        }
        if (count($ids) === 0) {
            return 0;
        }
        $other = $this->one('SELECT mdm_device_id FROM asset_mdm_links WHERE integration_id=' . $this->integrationId . ' AND asset_id=' . $ids[0] . " AND mdm_device_id<>'" . $this->esc($d['mdm_device_id']) . "' LIMIT 1");
        if ($other && isset($live[(string) $other['mdm_device_id']])) {
            return 0;
        }

        return $ids[0];
    }

    /** Phone / Tablet by what the MDM reports; other platforms by the existing asset-type names. */
    public static function assetType(array $d): string
    {
        $hay = strtolower(($d['model'] ?? '') . ' ' . ($d['product_name'] ?? ''));   // never the device name: it is free text
        $platform = strtolower((string) ($d['platform'] ?? ''));
        $tablet = str_contains($hay, 'ipad') || str_contains($hay, 'tablet') || preg_match('/\b(tab|pad)\b|sm-[tx]\d|\bkindle\b|\bfire\s?hd\b/', $hay) === 1;
        if (str_contains($platform, 'ipad')) {
            $tablet = true;
        }
        if ($tablet) {
            return 'Tablet';
        }
        if (str_contains($platform, 'ios') || str_contains($platform, 'android') || str_contains($hay, 'iphone')) {
            return 'Phone';
        }
        if (str_contains($platform, 'windows') || str_contains($platform, 'mac') || str_contains($platform, 'chrome')) {
            return 'Laptop';
        }
        if (str_contains($platform, 'tv')) {
            return 'Display';
        }

        return 'Other';
    }

    /** @return array{make:string,model:string,os:string} what an asset should show for this device */
    public static function assetFacts(array $d): array
    {
        $hay = strtolower(($d['model'] ?? '') . ' ' . ($d['product_name'] ?? ''));
        $platform = strtolower((string) ($d['platform'] ?? ''));
        $make = '';
        if (str_contains($platform, 'ios') || str_contains($platform, 'mac') || str_contains($hay, 'ipad') || str_contains($hay, 'iphone')) {
            $make = 'Apple';
        } elseif (preg_match('/^(samsung|google|lenovo|huawei|xiaomi|motorola|zebra|oneplus|nokia|sony|lg|honeywell|oppo|amazon|microsoft|asus|acer|hp|dell)\b/i', trim((string) ($d['product_name'] ?? '')), $m)) {
            $make = ucfirst(strtolower($m[1]));
        }

        return [
            'make' => $make,
            'model' => (string) (($d['model'] ?? '') !== '' ? $d['model'] : ($d['product_name'] ?? '')),
            'os' => \ITFlow\Assets\AssetInventorySync::osLabel((string) ($d['platform'] ?? ''), (string) ($d['os_version'] ?? '')),
        ];
    }

    /** Make / model / OS onto the asset and the user as its contact, never over a human edit. A failure never fails the device. */
    private function syncAssetFields(int $assetId, array $d): void
    {
        try {
            $this->assetSync ??= new \ITFlow\Assets\AssetInventorySync($this->db);
            $this->assetSync->apply($assetId, self::assetFacts($d), 'manageengine_mdm');
            $this->assetSync->applyPrimaryUser($assetId, (string) ($d['user_email'] ?? ''), null);
        } catch (\Throwable $e) {
            error_log('ManageEngine MDM asset sync skipped: ' . $e->getMessage());
        }
    }

    private function insertAsset(array $d, int $clientId): int
    {
        $facts = self::assetFacts($d);
        $name = ($d['device_name'] ?? '') !== '' ? $d['device_name'] : (($d['serial_number'] ?? '') !== '' ? $d['serial_number'] : 'MDM device ' . $d['mdm_device_id']);
        $serial = ($d['serial_number'] ?? '') !== '' ? $d['serial_number'] : ($d['imei'] ?? '');
        $this->query(
            "INSERT INTO assets SET asset_type='" . $this->esc(self::assetType($d)) . "', asset_name='" . $this->esc(mb_substr((string) $name, 0, 200)) . "', asset_serial='" . $this->esc((string) $serial) . "',"
            . " asset_make='" . $this->esc($facts['make']) . "', asset_model='" . $this->esc($facts['model']) . "', asset_os='" . $this->esc(mb_substr($facts['os'], 0, 200)) . "',"
            . " asset_status='Active', asset_client_id=" . max(0, $clientId) . ', asset_created_at=NOW()'
        );
        $assetId = (int) mysqli_insert_id($this->db);
        try {
            $this->assetSync ??= new \ITFlow\Assets\AssetInventorySync($this->db);
            $this->assetSync->recordWritten($assetId, $facts);   // the values this sync wrote: a later sync may refresh them, a human edit it may not
        } catch (\Throwable) {
        }

        return $assetId;
    }

    private function auditCreate(int $assetId, array $d, int $clientId): void
    {
        try {
            \ITFlow\Audit\AuditService::record('asset.created_from_mdm', $this->triggeredBy > 0 ? $this->triggeredBy : null, 'asset', $assetId, 'create',
                'Asset created from a ManageEngine MDM device', ['client_id' => $clientId, 'mdm_device_id' => $d['mdm_device_id']]);
        } catch (\Throwable) {
            // auditing never stops the sync
        }
    }

    /**
     * @param array<string,true> $live
     * @param array<string,int> $keyCount
     * @return array{asset_id:int,method:string,reason:string,candidates:list<int>}
     */
    private function findAsset(array $d, array $live, array $keyCount): array
    {
        $none = ['asset_id' => 0, 'method' => '', 'reason' => 'no_match', 'candidates' => []];
        foreach (['serial_number' => 'serial', 'imei' => 'imei', 'udid' => 'udid'] as $field => $method) {
            $key = self::matchKey($d, $field);
            if ($key === '') {
                continue;
            }
            if (($keyCount[$field . ':' . $key] ?? 0) > 1) {
                return ['asset_id' => 0, 'method' => $method, 'reason' => 'ambiguous', 'candidates' => []] ;
            }
            $ids = [];
            $res = $this->query("SELECT asset_id FROM assets WHERE asset_archived_at IS NULL AND asset_serial='" . $this->esc($key) . "' LIMIT 5");
            while ($res && ($r = mysqli_fetch_assoc($res))) {
                $ids[] = (int) $r['asset_id'];
            }
            if ($ids === []) {
                continue;
            }
            if (count($ids) > 1) {
                return ['asset_id' => 0, 'method' => $method, 'reason' => 'ambiguous', 'candidates' => $ids];
            }
            // The one asset is already claimed by a different device that is still live in this run: two devices, one asset.
            $other = $this->one('SELECT mdm_device_id FROM asset_mdm_links WHERE integration_id=' . $this->integrationId . ' AND asset_id=' . $ids[0] . " AND mdm_device_id<>'" . $this->esc($d['mdm_device_id']) . "' LIMIT 1");
            if ($other && isset($live[(string) $other['mdm_device_id']])) {
                return ['asset_id' => 0, 'method' => $method, 'reason' => 'ambiguous', 'candidates' => $ids];
            }

            return ['asset_id' => $ids[0], 'method' => $method, 'reason' => '', 'candidates' => $ids];
        }

        return $none;
    }

    /** A usable match key, or '' for blank and placeholder values ("unknown", "0", "n/a" ...). */
    private static function matchKey(array $d, string $field): string
    {
        $v = trim((string) ($d[$field] ?? ''));
        if (strlen($v) < 4 || in_array(strtolower($v), ['unknown', 'n/a', 'none', 'null', '0000', 'not available', 'not applicable', 'default string'], true)) {
            return '';
        }

        return strtolower($v);
    }

    /** Insert, or update in place when the asset already carries a link row for this integration (a re-enrolled device has a new id). */
    private function linkAsset(int $assetId, array $d, string $method): void
    {
        $iid = $this->integrationId;
        $set = $this->setSql($d, false) . ", mdm_device_id='" . $this->esc($d['mdm_device_id']) . "', match_method='" . $this->esc($method) . "', last_sync=NOW()";
        $row = $this->one("SELECT id FROM asset_mdm_links WHERE asset_id=$assetId AND integration_id=$iid LIMIT 1");
        if ($row) {
            $this->query("UPDATE asset_mdm_links SET $set WHERE id=" . (int) $row['id']);
        } else {
            $this->query("INSERT INTO asset_mdm_links SET asset_id=$assetId, integration_id=$iid, $set");
        }
    }

    /** @param list<int> $candidates */
    private function upsertUnmatched(array $d, string $reason, array $candidates): void
    {
        $iid = $this->integrationId;
        $idEsc = $this->esc($d['mdm_device_id']);
        $set = $this->setSql($d, false) . ", reason='" . $this->esc($reason) . "', candidate_asset_ids='" . $this->esc(implode(',', $candidates)) . "', last_seen_at=NOW()";
        $row = $this->one("SELECT id FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc' LIMIT 1");
        if ($row) {
            $this->query("UPDATE mdm_unmatched_devices SET $set WHERE id=" . (int) $row['id']);
        } else {
            $this->query("INSERT INTO mdm_unmatched_devices SET integration_id=$iid, mdm_device_id='$idEsc', first_seen_at=NOW(), $set");
        }
    }

    /**
     * Removes unmatched rows for devices that were not in a COMPLETE device list (they left ManageEngine). Call only after a full run.
     *
     * @param list<string> $seenIds
     */
    public function pruneUnmatched(array $seenIds): int
    {
        $iid = $this->integrationId;
        if ($seenIds === []) {
            $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid");
        } else {
            $in = "'" . implode("','", array_map([$this, 'esc'], $seenIds)) . "'";
            $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id NOT IN ($in)");
        }

        return mysqli_affected_rows($this->db);
    }

    // ---- review-list actions (called from agent/post/mdm_device.php after permission and CSRF checks) ----------------------

    /** Link an unmatched device to an existing, non-archived asset. @return array{ok:bool,message:string} */
    public function linkUnmatched(int $unmatchedId, int $assetId): array
    {
        $u = $this->unmatchedRow($unmatchedId);
        if (!$u) {
            return ['ok' => false, 'message' => 'That device is no longer in the review list.'];
        }
        if (!$this->one("SELECT asset_id FROM assets WHERE asset_id=$assetId AND asset_archived_at IS NULL LIMIT 1")) {
            return ['ok' => false, 'message' => 'That asset does not exist or is archived.'];
        }
        $iid = $this->integrationId;
        $linked = $this->one("SELECT id, mdm_device_id FROM asset_mdm_links WHERE asset_id=$assetId AND integration_id=$iid LIMIT 1");
        if ($linked && $linked['mdm_device_id'] !== $u['mdm_device_id']) {
            return ['ok' => false, 'message' => 'That asset is already linked to another ManageEngine device. Unlink it first.'];
        }
        $this->linkAsset($assetId, $u, 'manual');
        $this->syncAssetFields($assetId, $u);
        $this->query('DELETE FROM mdm_unmatched_devices WHERE id=' . (int) $u['id']);

        return ['ok' => true, 'message' => 'Linked.'];
    }

    /** Create a new asset from an unmatched device and link it. @return array{ok:bool,message:string,asset_id:int} */
    public function createAssetFor(int $unmatchedId, int $clientId): array
    {
        $u = $this->unmatchedRow($unmatchedId);
        if (!$u) {
            return ['ok' => false, 'message' => 'That device is no longer in the review list.', 'asset_id' => 0];
        }
        $assetId = $this->insertAsset($u, $clientId);
        $this->linkAsset($assetId, $u, 'created');
        $this->syncAssetFields($assetId, $u);
        $this->query('DELETE FROM mdm_unmatched_devices WHERE id=' . (int) $u['id']);

        return ['ok' => true, 'message' => 'Asset created and linked.', 'asset_id' => $assetId];
    }

    // ---- sync log ---------------------------------------------------------------------------------------------------------

    public function startSyncLog(): int
    {
        $this->query("INSERT INTO mdm_sync_log SET integration_id={$this->integrationId}, triggered_by={$this->triggeredBy}");

        return (int) mysqli_insert_id($this->db);
    }

    /** @param array{total:int,updated:int,matched:int,unmatched:int,skipped:int,errors:list<string>} $stats */
    public function finishSyncLog(int $logId, array $stats): void
    {
        $status = empty($stats['errors']) ? 'success' : 'failed';
        $errors = $this->esc(substr(implode('; ', array_merge($stats['errors'], array_map(static fn (string $w): string => 'warning: ' . $w, $stats['warnings'] ?? []))), 0, 4000));
        $this->query(
            "UPDATE mdm_sync_log SET finished_at=NOW(), status='$status', devices_total={$stats['total']}, devices_updated={$stats['updated']},"
            . " devices_matched={$stats['matched']}, devices_created={$stats['created']}, devices_unmatched={$stats['unmatched']}, devices_skipped={$stats['skipped']}, errors='$errors' WHERE id=$logId"
        );
        $this->query("UPDATE manageengine_mdm_integrations SET last_sync_at=NOW() WHERE id={$this->integrationId}");
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function unmatchedRow(int $id): ?array
    {
        return $this->one("SELECT * FROM mdm_unmatched_devices WHERE id=$id AND integration_id={$this->integrationId} LIMIT 1");
    }

    /** SET fragment of the shared device columns. $skipNull: a null (not reported) leaves the stored value alone. */
    private function setSql(array $d, bool $skipNull): string
    {
        $parts = [];
        foreach (self::FIELDS as $col => $type) {
            $v = $d[$col] ?? null;
            if ($v === null) {
                if ($skipNull) {
                    continue;
                }
                $parts[] = "`$col`=" . ($type === 's' ? "''" : 'NULL');
                continue;
            }
            $parts[] = "`$col`=" . match ($type) {
                's' => "'" . $this->esc(mb_substr((string) $v, 0, self::MAXLEN[$col] ?? 255)) . "'",
                'b', 'i' => (string) (int) $v,
                'd' => "'" . $this->esc((string) $v) . "'",
            };
        }

        return implode(', ', $parts);
    }

    private function esc(string $v): string
    {
        return mysqli_real_escape_string($this->db, $v);
    }

    /** @return \mysqli_result|bool */
    private function query(string $sql)
    {
        $r = mysqli_query($this->db, $sql);
        if ($r === false) {
            throw new \RuntimeException('Database error: ' . mysqli_error($this->db));
        }

        return $r;
    }

    /** @return array<string,mixed>|null */
    private function one(string $sql): ?array
    {
        $r = $this->query($sql);

        return $r instanceof \mysqli_result ? (mysqli_fetch_assoc($r) ?: null) : null;
    }
}
