<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Turns ManageEngine MDM device records into rows of asset_mdm_links (matched to an asset) or mdm_unmatched_devices (a review list).
 * Mirrors Microsoft\IntuneAssetMapper, with two deliberate differences:
 *   - a device that matches no asset (or more than one) is NEVER auto-created as an asset: it waits in the review list, where a
 *     person picks "Create asset" or "Link to existing asset" (createAssetFor / linkUnmatched below);
 *   - a sync never writes the asset's own fields (make, model, OS, contact ...): it only reads assets and writes the link tables.
 *
 * Match priority (first key that finds any asset decides):
 *   1. mdm_device_id already in asset_mdm_links (already linked: just refresh the row)
 *   2. serial number  = assets.asset_serial
 *   3. IMEI           = assets.asset_serial (people record a phone's IMEI as its serial)
 *   4. UDID           = assets.asset_serial
 * More than one asset for a key, one asset claimed by two live devices, or one serial on two live devices = ambiguous: unmatched.
 * Wi-Fi MAC is not returned by the documented device list/detail/summary endpoints, so it is not used.
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
    ];
    private const MAXLEN = ['device_name' => 200, 'platform' => 30, 'os_version' => 100, 'model' => 200, 'product_name' => 200, 'serial_number' => 200,
        'udid' => 100, 'imei' => 100, 'enrollment_status' => 20, 'owned_by' => 20, 'user_name' => 200, 'user_email' => 200, 'group_names' => 500];

    public function __construct(private \mysqli $db, private int $integrationId, private int $triggeredBy = 0)
    {
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
        // ManageEngine sends this as the STRING "false" (seen on a live tenant): empty() would call every device removed.
        $removed = $bool($list['is_removed'] ?? null) === 1;
        $unregistered = isset($detail['unregistered_time']) && is_numeric($detail['unregistered_time']) && (float) $detail['unregistered_time'] > 0;

        return [
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
     * @return array{total:int,updated:int,matched:int,unmatched:int,skipped:int,errors:list<string>}
     */
    public function syncDevices(array $devices): array
    {
        $stats = ['total' => count($devices), 'updated' => 0, 'matched' => 0, 'unmatched' => 0, 'skipped' => 0, 'errors' => []];

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

            return 'updated';
        }
        if ($d['enrollment_status'] === 'removed') {
            $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");

            return 'skipped';
        }

        $found = $this->findAsset($d, $live, $keyCount);
        if ($found['asset_id'] === 0) {
            $this->upsertUnmatched($d, $found['reason'], $found['candidates']);

            return 'unmatched';
        }

        $this->linkAsset($found['asset_id'], $d, $found['method']);
        $this->query("DELETE FROM mdm_unmatched_devices WHERE integration_id=$iid AND mdm_device_id='$idEsc'");

        return 'matched';
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
        $name = $u['device_name'] !== '' ? $u['device_name'] : ($u['serial_number'] !== '' ? $u['serial_number'] : 'MDM device ' . $u['mdm_device_id']);
        $hay = strtolower($u['model'] . ' ' . $u['product_name']);
        $type = (str_contains($hay, 'ipad') || str_contains($hay, 'tab') || str_contains($hay, 'tablet')) ? 'Tablet' : 'Mobile Phone';
        $platform = strtolower($u['platform']);
        $make = str_contains($platform, 'ios') || str_contains($hay, 'ipad') || str_contains($hay, 'iphone') ? 'Apple' : '';
        $os = trim($u['platform'] . ' ' . $u['os_version']);
        $serial = $u['serial_number'] !== '' ? $u['serial_number'] : $u['imei'];
        $this->query(
            "INSERT INTO assets SET asset_type='" . $this->esc($type) . "', asset_name='" . $this->esc(mb_substr($name, 0, 200)) . "', asset_serial='" . $this->esc($serial) . "',"
            . " asset_make='" . $this->esc($make) . "', asset_model='" . $this->esc($u['model'] !== '' ? $u['model'] : $u['product_name']) . "', asset_os='" . $this->esc(mb_substr($os, 0, 200)) . "',"
            . " asset_status='Active', asset_client_id=" . max(0, $clientId) . ', asset_created_at=NOW()'
        );
        $assetId = (int) mysqli_insert_id($this->db);
        $this->linkAsset($assetId, $u, 'created');
        $this->query('DELETE FROM mdm_unmatched_devices WHERE id=' . (int) $u['id']);

        return ['ok' => true, 'message' => 'Asset created and linked.', 'asset_id' => $assetId];
    }

    // ---- sync log ---------------------------------------------------------------------------------------------------------

    public function startSyncLog(): int
    {
        $this->query("INSERT INTO mdm_sync_log SET integration_id={$this->integrationId}, triggered_by={$this->triggeredBy}");

        return (int) mysqli_insert_id($this->db);
    }

    /** @param array{total:int,updated:int,matched:int,unmatched:int,skipped:int,errors:list<string>,notes?:list<string>,warnings?:list<string>} $stats */
    public function finishSyncLog(int $logId, array $stats): void
    {
        $status = empty($stats['errors']) ? 'success' : 'failed';
        $errors = $this->esc(substr(implode('; ', array_merge(
            $stats['errors'],
            array_map(static fn (string $n): string => 'note: ' . $n, $stats['notes'] ?? []),
            array_map(static fn (string $w): string => 'warning: ' . $w, $stats['warnings'] ?? [])
        )), 0, 4000));
        $this->query(
            "UPDATE mdm_sync_log SET finished_at=NOW(), status='$status', devices_total={$stats['total']}, devices_updated={$stats['updated']},"
            . " devices_matched={$stats['matched']}, devices_unmatched={$stats['unmatched']}, devices_skipped={$stats['skipped']}, errors='$errors' WHERE id=$logId"
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
