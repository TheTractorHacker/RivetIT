<?php

namespace ITFlow\Integrations\Microsoft;

/**
 * IntuneAssetMapper — matches Microsoft Intune managed devices (pulled via
 * GraphClient::listAllManagedDevices()) to ITFlow assets. Mirrors
 * includes/class_rmm_asset_mapper.php's structure/conventions closely, just
 * for the Microsoft Graph device source instead of Tactical/Level/Action1.
 *
 * Match priority:
 *   1. intune_device_id already in asset_intune_links (already linked)
 *   2. asset_serial match
 *   3. Case-insensitive hostname match on asset_name (only if unique)
 *
 * Unlike the RMM sources, Intune devices carry no per-device department/
 * client signal — a newly created asset is left unassigned (asset_client_id
 * default 0) for a human to assign later.
 */
class IntuneAssetMapper {

    private $mysqli;
    private int $microsoftIntegrationId;
    private int $triggeredBy;

    public function __construct($mysqli, int $microsoftIntegrationId, int $triggeredBy = 0) {
        $this->mysqli                = $mysqli;
        $this->microsoftIntegrationId = $microsoftIntegrationId;
        $this->triggeredBy           = $triggeredBy;
    }

    public function syncDevices(array $devices): array {
        $stats = ['created' => 0, 'updated' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($devices as $device) {
            try {
                $result = $this->syncDevice($device);
                $stats[$result]++;
            } catch (\Exception $e) {
                $stats['errors'][] = ($device['deviceName'] ?? 'unknown') . ': ' . $e->getMessage();
                $stats['skipped']++;
            }
        }
        return $stats;
    }

    private function syncDevice(array $device): string {
        $m       = $this->mysqli;
        $intg_id = $this->microsoftIntegrationId;

        $intune_device_id = trim((string) ($device['id'] ?? ''));
        $device_name      = trim((string) ($device['deviceName'] ?? ''));

        if ($intune_device_id === '' || $device_name === '') {
            return 'skipped';
        }

        $intune_device_id_esc  = mysqli_real_escape_string($m, $intune_device_id);
        $azure_ad_device_id    = trim((string) ($device['azureADDeviceId'] ?? ''));
        $azure_ad_device_id_esc = mysqli_real_escape_string($m, $azure_ad_device_id);
        $hostname_esc          = mysqli_real_escape_string($m, $device_name);
        $serial                = trim((string) ($device['serialNumber'] ?? ''));
        $serial_esc            = mysqli_real_escape_string($m, $serial);
        $os_name               = trim((string) ($device['operatingSystem'] ?? ''));
        $os_name_esc           = mysqli_real_escape_string($m, $os_name);
        $os_version            = trim((string) ($device['osVersion'] ?? ''));
        $os_version_esc        = mysqli_real_escape_string($m, $os_version);
        $manufacturer          = trim((string) ($device['manufacturer'] ?? ''));
        $manufacturer_esc      = mysqli_real_escape_string($m, $manufacturer);
        $model                 = trim((string) ($device['model'] ?? ''));
        $model_esc             = mysqli_real_escape_string($m, $model);
        $management_agent      = trim((string) ($device['managementAgent'] ?? ''));
        $management_agent_esc  = mysqli_real_escape_string($m, $management_agent);
        $compliance_state      = trim((string) ($device['complianceState'] ?? ''));
        $compliance_state_esc  = mysqli_real_escape_string($m, $compliance_state);
        $is_encrypted          = !empty($device['isEncrypted']) ? 1 : 0;
        $primary_user_upn      = trim((string) ($device['userPrincipalName'] ?? ''));
        $primary_user_upn_esc  = mysqli_real_escape_string($m, $primary_user_upn);

        $enrolled_at_val        = $this->toDatetimeSql($device['enrolledDateTime'] ?? null);
        $intune_last_sync_val   = $this->toDatetimeSql($device['lastSyncDateTime'] ?? null);

        $raw_json_esc = mysqli_real_escape_string($m, json_encode($device));

        // Shared SET fragment for the link row (used by both UPDATE paths and INSERT below)
        $link_fields_sql =
            "intune_device_id='$intune_device_id_esc', " .
            "azure_ad_device_id='$azure_ad_device_id_esc', " .
            "hostname='$hostname_esc', " .
            "serial_number='$serial_esc', " .
            "os_name='$os_name_esc', " .
            "os_version='$os_version_esc', " .
            "manufacturer='$manufacturer_esc', " .
            "model='$model_esc', " .
            "management_agent='$management_agent_esc', " .
            "compliance_state='$compliance_state_esc', " .
            "is_encrypted=$is_encrypted, " .
            "primary_user_upn='$primary_user_upn_esc', " .
            "enrolled_at=$enrolled_at_val, " .
            "intune_last_sync_at=$intune_last_sync_val, " .
            "last_sync=NOW(), " .
            "raw_data_json='$raw_json_esc'";

        // ----- Step 1: Check existing link -----
        $existing = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id, asset_id FROM asset_intune_links
             WHERE microsoft_integration_id=$intg_id AND intune_device_id='$intune_device_id_esc' LIMIT 1"
        ));

        if ($existing) {
            mysqli_query($m, "UPDATE asset_intune_links SET $link_fields_sql WHERE id=" . intval($existing['id']));
            return 'updated';
        }

        // ----- Step 2: Try to match an existing ITFlow asset -----
        $asset_id = 0;

        // 2a: serial number
        if (!$asset_id && $serial !== '') {
            $row = mysqli_fetch_assoc(mysqli_query($m,
                "SELECT asset_id FROM assets WHERE asset_serial='$serial_esc' AND asset_archived_at IS NULL LIMIT 1"
            ));
            if ($row) { $asset_id = intval($row['asset_id']); }
        }

        // 2b: hostname match (unique only)
        if (!$asset_id) {
            $cnt = intval(mysqli_fetch_assoc(mysqli_query($m,
                "SELECT COUNT(*) as c FROM assets WHERE LOWER(asset_name)=LOWER('$hostname_esc') AND asset_archived_at IS NULL"
            ))['c']);
            if ($cnt === 1) {
                $row = mysqli_fetch_assoc(mysqli_query($m,
                    "SELECT asset_id FROM assets WHERE LOWER(asset_name)=LOWER('$hostname_esc') AND asset_archived_at IS NULL LIMIT 1"
                ));
                if ($row) { $asset_id = intval($row['asset_id']); }
            }
        }

        // ----- Step 3: Create new ITFlow asset if no match -----
        if (!$asset_id) {
            $asset_type = $this->guessAssetType($os_name);
            $os_combined_esc = mysqli_real_escape_string($m, trim("$os_name $os_version"));
            mysqli_query($m,
                "INSERT INTO assets SET
                 asset_type='$asset_type',
                 asset_name='$hostname_esc',
                 asset_serial='$serial_esc',
                 asset_os='$os_combined_esc',
                 asset_make='$manufacturer_esc',
                 asset_status='Active',
                 asset_created_at=NOW()"
            );
            $asset_id = intval(mysqli_insert_id($m));
            $outcome  = 'created';
        } else {
            $outcome = 'matched';
        }

        // ----- Step 4: Insert or update the link row -----
        // asset_intune_links has a UNIQUE KEY on (asset_id, microsoft_integration_id) —
        // the asset we just matched/created may already carry a link row for this
        // integration under a *different* intune_device_id (e.g. re-enrolled in
        // Intune and issued a new device id). Update that row in place instead of
        // blind-inserting, which would otherwise throw a duplicate-key error and
        // abort the sync for this device.
        $existing_link_for_asset = mysqli_fetch_assoc(mysqli_query($m,
            "SELECT id FROM asset_intune_links WHERE asset_id=$asset_id AND microsoft_integration_id=$intg_id LIMIT 1"
        ));

        if ($existing_link_for_asset) {
            mysqli_query($m, "UPDATE asset_intune_links SET $link_fields_sql WHERE id=" . intval($existing_link_for_asset['id']));
        } else {
            mysqli_query($m, "INSERT INTO asset_intune_links SET asset_id=$asset_id, microsoft_integration_id=$intg_id, $link_fields_sql");
        }

        return $outcome;
    }

    // Converts a Graph ISO8601 timestamp string into a SQL datetime literal
    // (or the literal NULL when empty/unparseable) for direct embedding in a
    // SET/WHERE clause.
    private function toDatetimeSql(?string $iso): string {
        if (empty($iso)) {
            return 'NULL';
        }
        $ts = strtotime($iso);
        if (!$ts) {
            return 'NULL';
        }
        return "'" . date('Y-m-d H:i:s', $ts) . "'";
    }

    private function guessAssetType(string $os_name): string {
        $os = strtolower($os_name);
        if (str_contains($os, 'ios') || str_contains($os, 'ipad') || str_contains($os, 'android')) return 'Mobile';
        if (str_contains($os, 'macos') || str_contains($os, 'mac os')) return 'Desktop';
        if (str_contains($os, 'windows server')) return 'Server';
        return 'Desktop';
    }

    public function startSyncLog(): int {
        $m = $this->mysqli;
        mysqli_query($m,
            "INSERT INTO intune_sync_log SET microsoft_integration_id={$this->microsoftIntegrationId}, triggered_by={$this->triggeredBy}"
        );
        return intval(mysqli_insert_id($m));
    }

    public function finishSyncLog(int $logId, array $stats): void {
        $m      = $this->mysqli;
        $status = empty($stats['errors']) ? 'success' : 'failed';
        $errors = mysqli_real_escape_string($m, implode('; ', $stats['errors']));
        mysqli_query($m,
            "UPDATE intune_sync_log SET
             finished_at=NOW(), status='$status',
             devices_created={$stats['created']}, devices_updated={$stats['updated']},
             devices_matched={$stats['matched']}, devices_skipped={$stats['skipped']},
             errors='$errors'
             WHERE id=$logId"
        );

        mysqli_query($m,
            "UPDATE microsoft_integrations SET last_sync_at = NOW() WHERE microsoft_integration_id = {$this->microsoftIntegrationId}"
        );
    }
}
