<?php

declare(strict_types=1);

namespace ITFlow\Integrations\ManageEngine;

/**
 * The mapping options of the ManageEngine MDM integration (table mdm_mapping_settings, one row per integration):
 *   - assign_contact / assign_contact_overwrite: set the asset's assigned contact from the device's MDM user (default OFF / OFF);
 *   - allow_asset_writes: the master "Allow MDM to write asset fields" switch (default OFF);
 *   - field_map: per MDM field, "panel only" (default, no asset write) or "asset" with a mode.
 * With no row, or with the table missing (database update pending), every option is off. Nothing here reads or writes ManageEngine.
 */
final class MdmMappingSettings
{
    public const MODE_FILL = 'fill_empty';
    public const MODE_OVERWRITE = 'overwrite';

    /**
     * The MDM fields the field-mapping table lists. link = column of asset_mdm_links, asset = the assets column a field may be written to
     * (null: there is no matching asset column, the field is shown in the MDM panel only), modes = the write modes allowed for it
     * (serial and name are only ever filled when the asset's own value is empty).
     */
    public const FIELDS = [
        'device_name'        => ['label' => 'Device name', 'link' => 'device_name', 'asset' => 'asset_name', 'asset_label' => 'Asset name', 'modes' => [self::MODE_FILL], 'max' => 200],
        'model'              => ['label' => 'Model', 'link' => 'model', 'asset' => 'asset_model', 'asset_label' => 'Asset model', 'modes' => [self::MODE_FILL, self::MODE_OVERWRITE], 'max' => 200],
        'os'                 => ['label' => 'OS and version', 'link' => 'os_version', 'asset' => 'asset_os', 'asset_label' => 'Asset OS', 'modes' => [self::MODE_FILL, self::MODE_OVERWRITE], 'max' => 200],
        'serial_number'      => ['label' => 'Serial number', 'link' => 'serial_number', 'asset' => 'asset_serial', 'asset_label' => 'Asset serial', 'modes' => [self::MODE_FILL], 'max' => 200],
        'imei'               => ['label' => 'IMEI', 'link' => 'imei', 'asset' => null, 'modes' => [], 'max' => 100],
        'udid'               => ['label' => 'UDID', 'link' => 'udid', 'asset' => null, 'modes' => [], 'max' => 100],
        'battery_level'      => ['label' => 'Battery level', 'link' => 'battery_level', 'asset' => null, 'modes' => [], 'max' => 0],
        'storage'            => ['label' => 'Storage (total / free)', 'link' => 'storage_total', 'asset' => null, 'modes' => [], 'max' => 0],
        'last_contact_at'    => ['label' => 'Last contact', 'link' => 'last_contact_at', 'asset' => null, 'modes' => [], 'max' => 0],
        'passcode_compliant' => ['label' => 'Passcode compliant', 'link' => 'passcode_compliant', 'asset' => null, 'modes' => [], 'max' => 0],
        'is_supervised'      => ['label' => 'Supervised', 'link' => 'is_supervised', 'asset' => null, 'modes' => [], 'max' => 0],
        'group_names'        => ['label' => 'Group', 'link' => 'group_names', 'asset' => null, 'modes' => [], 'max' => 500],
    ];

    /** @return array{assign_contact:bool,assign_contact_overwrite:bool,allow_asset_writes:bool,field_map:array<string,array{target:string,mode:string}>,exists:bool} */
    public static function defaults(): array
    {
        return ['assign_contact' => false, 'assign_contact_overwrite' => false, 'allow_asset_writes' => false, 'field_map' => [], 'exists' => false];
    }

    /** @return array{assign_contact:bool,assign_contact_overwrite:bool,allow_asset_writes:bool,field_map:array<string,array{target:string,mode:string}>,exists:bool} */
    public static function load(\mysqli $db, int $integrationId): array
    {
        $out = self::defaults();
        try {
            $res = @$db->query('SELECT * FROM mdm_mapping_settings WHERE integration_id = ' . $integrationId . ' LIMIT 1');
            $row = $res ? $res->fetch_assoc() : null;
        } catch (\Throwable $e) {
            return $out;   // table not installed yet: everything stays off
        }
        if (!$row) {
            return $out;
        }
        $out['exists'] = true;
        $out['assign_contact'] = (int) $row['assign_contact'] === 1;
        $out['assign_contact_overwrite'] = (int) $row['assign_contact_overwrite'] === 1;
        $out['allow_asset_writes'] = (int) $row['allow_asset_writes'] === 1;
        $out['field_map'] = self::cleanFieldMap(json_decode((string) ($row['field_map'] ?? ''), true));

        return $out;
    }

    /** Whether the three mapping tables exist (the 2.6.169 database update has run). */
    public static function schemaReady(\mysqli $db): bool
    {
        try {
            foreach (['mdm_mapping_settings', 'mdm_user_contact_map', 'mdm_field_write_log'] as $t) {
                $r = @$db->query("SHOW TABLES LIKE '$t'");
                if (!$r || $r->num_rows === 0) {
                    return false;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }

    /**
     * Only known fields, only a field that has an asset column can have target "asset", only an allowed mode (the first allowed one when
     * the mode is missing or not allowed). Anything else is dropped, so a stored or posted map can never name a column that is not in FIELDS.
     *
     * @param mixed $raw
     * @return array<string,array{target:string,mode:string}>
     */
    public static function cleanFieldMap($raw): array
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (self::FIELDS as $key => $def) {
            $v = $raw[$key] ?? null;
            if (!is_array($v) || ($v['target'] ?? '') !== 'asset' || $def['asset'] === null) {
                continue;   // anything but an explicit, allowed "asset" stays panel only
            }
            $mode = (string) ($v['mode'] ?? '');
            $out[$key] = ['target' => 'asset', 'mode' => in_array($mode, $def['modes'], true) ? $mode : $def['modes'][0]];
        }

        return $out;
    }

    /**
     * Save (upsert) the options. $allowWrites is applied exactly as given: the caller (admin only) decides.
     *
     * @param array<string,mixed> $fieldMap
     */
    public static function save(\mysqli $db, int $integrationId, bool $assign, bool $overwrite, bool $allowWrites, array $fieldMap, int $userId): void
    {
        $json = json_encode(self::cleanFieldMap($fieldMap), JSON_UNESCAPED_SLASHES);
        $stmt = $db->prepare(
            'INSERT INTO mdm_mapping_settings (integration_id, assign_contact, assign_contact_overwrite, allow_asset_writes, field_map, updated_by) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE assign_contact = VALUES(assign_contact), assign_contact_overwrite = VALUES(assign_contact_overwrite), allow_asset_writes = VALUES(allow_asset_writes), field_map = VALUES(field_map), updated_by = VALUES(updated_by)'
        );
        $a = $assign ? 1 : 0;
        $o = $overwrite ? 1 : 0;
        $w = $allowWrites ? 1 : 0;
        $stmt->bind_param('iiiisi', $integrationId, $a, $o, $w, $json, $userId);
        $stmt->execute();
        $stmt->close();
    }

    /** Turns the master write switch off (used when the integration is disabled). Harmless when there is no row or no table. */
    public static function switchWritesOff(\mysqli $db, int $integrationId): void
    {
        try {
            @$db->query('UPDATE mdm_mapping_settings SET allow_asset_writes = 0 WHERE integration_id = ' . $integrationId);
        } catch (\Throwable $e) {
            // table not installed yet: nothing to switch off
        }
    }

    /** Which MDM fields are set to be written to the asset. @return list<string> */
    public static function writableKeys(array $settings): array
    {
        return array_keys($settings['field_map'] ?? []);
    }
}
