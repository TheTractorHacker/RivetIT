<?php

declare(strict_types=1);

namespace ITFlow\Integrations\ManageEngine;

use ITFlow\Assets\AssetInventorySync;

/**
 * Writes the MDM values an administrator chose onto the linked asset's own columns (field mapping). One planner serves both the
 * dry-run PREVIEW and the real run, so what the preview shows is exactly what a run does:
 *
 *   plan()   reads asset_mdm_links + assets and returns one entry per field that WOULD change. No write, no side effect.
 *   apply()  re-plans, then writes: only when the master switch (mdm_mapping_settings.allow_asset_writes) is on AND the integration is
 *            enabled. Each changed field gets an asset_history row, an mdm_field_write_log row (field, column, mode, old and new value)
 *            and an audit event. Device attributes only; no secret ever reaches this class (the link tables hold none).
 *
 * Modes: fill_empty writes only when the asset's own value is empty; overwrite writes whenever it differs. Serial and name only
 * ever fill. A blank MDM value never blanks a field. A serial already on another live asset is skipped (it would make matching ambiguous).
 * Only devices still enrolled are used: a removed or unenrolled device's last data is stale.
 */
final class MdmFieldWriter
{
    public function __construct(private \mysqli $db, private int $integrationId)
    {
    }

    /**
     * @param array<string,mixed> $settings MdmMappingSettings::load() shape (a preview passes the unsaved form values)
     * @return array{changes:list<array<string,mixed>>,skipped:list<array<string,mixed>>,examined:int}
     */
    public function plan(array $settings): array
    {
        $out = ['changes' => [], 'skipped' => [], 'examined' => 0];
        $map = MdmMappingSettings::cleanFieldMap($settings['field_map'] ?? []);
        if ($map === []) {
            return $out;
        }
        $res = $this->db->query(
            'SELECT l.id AS link_id, l.asset_id, l.device_name, l.platform, l.os_version, l.model, l.product_name, l.serial_number, a.asset_name, a.asset_model, a.asset_os, a.asset_serial, a.asset_client_id
             FROM asset_mdm_links l JOIN assets a ON a.asset_id = l.asset_id
             WHERE l.integration_id = ' . $this->integrationId . " AND a.asset_archived_at IS NULL AND l.enrollment_status = 'enrolled' ORDER BY l.asset_id"
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $out['examined']++;
            foreach ($map as $key => $cfg) {
                $def = MdmMappingSettings::FIELDS[$key];
                $col = (string) $def['asset'];
                $new = $this->clean($this->mdmValue($key, $r), (int) $def['max']);
                $old = trim((string) ($r[$col] ?? ''));
                $entry = ['asset_id' => (int) $r['asset_id'], 'asset_name' => (string) $r['asset_name'], 'client_id' => (int) $r['asset_client_id'], 'field' => $key, 'label' => $def['label'],
                    'column' => $col, 'mode' => $cfg['mode'], 'old' => $old, 'new' => $new];
                if ($new === '' || $new === $old) {
                    continue;
                }
                if ($cfg['mode'] === MdmMappingSettings::MODE_FILL && $old !== '') {
                    continue;
                }
                if ($col === 'asset_serial' && $this->serialUsedElsewhere($new, (int) $r['asset_id'])) {
                    $out['skipped'][] = $entry + ['reason' => 'Another asset already has this serial number'];
                    continue;
                }
                $out['changes'][] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{written:int,assets:int,skipped:int,blocked:string}  blocked is non-empty when nothing was written because a switch is off
     */
    public function apply(array $settings, int $syncLogId, int $actorUserId): array
    {
        $out = ['written' => 0, 'assets' => 0, 'skipped' => 0, 'blocked' => ''];
        if (empty($settings['allow_asset_writes'])) {
            $out['blocked'] = 'The master switch "Allow MDM to write asset fields" is off.';

            return $out;
        }
        $ir = $this->db->query('SELECT enabled FROM manageengine_mdm_integrations WHERE id = ' . $this->integrationId);
        $int = $ir ? $ir->fetch_assoc() : null;
        if (!$int || (int) $int['enabled'] !== 1) {
            $out['blocked'] = 'The integration is disabled.';

            return $out;
        }
        $plan = $this->plan($settings);
        $out['skipped'] = count($plan['skipped']);
        $touched = [];
        foreach ($plan['changes'] as $c) {
            $col = $c['column'];
            if (!in_array($col, ['asset_name', 'asset_model', 'asset_os', 'asset_serial'], true)) {
                continue;   // defence in depth: the planner can only produce these four
            }
            // The update itself re-checks the planner's reason (a fill only touches an empty column), so a value a person typed
            // between planning and writing is never replaced.
            $guard = $c['mode'] === MdmMappingSettings::MODE_FILL ? " AND (`$col` IS NULL OR TRIM(`$col`) = '')" : '';
            $stmt = $this->db->prepare("UPDATE assets SET `$col` = ? WHERE asset_id = ? AND asset_archived_at IS NULL$guard");
            $new = (string) $c['new'];
            $aid = (int) $c['asset_id'];
            $stmt->bind_param('si', $new, $aid);
            $stmt->execute();
            $changed = $stmt->affected_rows > 0;
            $stmt->close();
            if (!$changed) {
                continue;
            }
            $out['written']++;
            $touched[$aid] = true;
            $this->record($c, $syncLogId, $actorUserId);
        }
        $out['assets'] = count($touched);

        return $out;
    }

    /** @param array<string,mixed> $c */
    private function record(array $c, int $syncLogId, int $actor): void
    {
        $oldLog = mb_substr((string) $c['old'], 0, 255);
        $newLog = mb_substr((string) $c['new'], 0, 255);
        $stmt = $this->db->prepare('INSERT INTO mdm_field_write_log (integration_id, sync_log_id, asset_id, field_key, asset_column, mode, old_value, new_value, written_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $aid = (int) $c['asset_id'];
        $stmt->bind_param('iiisssssi', $this->integrationId, $syncLogId, $aid, $c['field'], $c['column'], $c['mode'], $oldLog, $newLog, $actor);
        $stmt->execute();
        $stmt->close();

        $desc = mb_substr('ManageEngine MDM sync set ' . strtolower((string) $c['label']) . " from '" . mb_substr((string) $c['old'], 0, 60) . "' to '" . mb_substr((string) $c['new'], 0, 80) . "' (" . ($c['mode'] === MdmMappingSettings::MODE_FILL ? 'fill when empty' : 'overwrite') . ')', 0, 255);
        $stmt = $this->db->prepare("INSERT INTO asset_history (asset_history_status, asset_history_description, asset_history_asset_id) VALUES ('Updated', ?, ?)");
        $stmt->bind_param('si', $desc, $aid);
        $stmt->execute();
        $stmt->close();

        try {
            \ITFlow\Audit\AuditService::record('integration.manageengine_mdm.asset_field_write', $actor > 0 ? $actor : null, 'asset', $aid, 'success', $desc,
                ['field' => $c['field'], 'column' => $c['column'], 'mode' => $c['mode'], 'old' => $oldLog, 'new' => $newLog]);
        } catch (\Throwable $e) {
            // the per-field rows above already hold the record; an audit sink problem must not stop a sync
        }
    }

    /** The MDM value of a field, as the text that would be written. @param array<string,mixed> $r */
    private function mdmValue(string $key, array $r): string
    {
        return match ($key) {
            'device_name' => (string) $r['device_name'],
            'model' => trim((string) $r['model']) !== '' ? (string) $r['model'] : (string) $r['product_name'],
            'os' => AssetInventorySync::osLabel((string) $r['platform'], (string) $r['os_version']),
            'serial_number' => (string) $r['serial_number'],
            default => '',
        };
    }

    private function clean(string $v, int $max): string
    {
        $v = trim(strip_tags($v));

        return $max > 0 ? mb_substr($v, 0, $max) : $v;
    }

    private function serialUsedElsewhere(string $serial, int $assetId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM assets WHERE asset_archived_at IS NULL AND asset_id <> ? AND LOWER(asset_serial) = LOWER(?) LIMIT 1');
        $stmt->bind_param('is', $assetId, $serial);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $found;
    }
}
