<?php

namespace ITFlow\Integrations\ManageEngine;

/**
 * Read-side helpers for the ManageEngine MDM link rows: one place decides what "not seen", "not compliant" and the badge colour mean,
 * so the devices page, the asset panel, the assets list and the kiosk device list always agree.
 */
final class MdmDeviceStatus
{
    /**
     * @param array<string,mixed> $row an asset_mdm_links (or mdm_unmatched_devices) row
     * @return array{state:string,label:string,class:string,lost:bool,noncompliant:bool,stale:bool,removed:bool}
     *   state: ok | lost | noncompliant | stale | removed | unknown (never reported a contact time)
     */
    public static function classify(array $row, int $staleDays = 7, ?int $now = null): array
    {
        $now ??= time();
        $removed = in_array((string) ($row['enrollment_status'] ?? ''), ['removed', 'unenrolled'], true);
        $lost = (string) ($row['is_lost_mode'] ?? '') === '1';
        $noncompliant = (string) ($row['passcode_compliant'] ?? '') === '0' || (string) ($row['is_rooted'] ?? '') === '1';
        $contact = !empty($row['last_contact_at']) ? strtotime((string) $row['last_contact_at']) : false;
        $stale = $contact !== false && $contact < $now - max(1, $staleDays) * 86400;

        if ($removed) {
            [$state, $label, $class] = ['removed', 'Not enrolled', 'secondary'];
        } elseif ($lost) {
            [$state, $label, $class] = ['lost', 'Lost mode', 'danger'];
        } elseif ($noncompliant) {
            [$state, $label, $class] = ['noncompliant', 'Not compliant', 'danger'];
        } elseif ($stale) {
            [$state, $label, $class] = ['stale', 'Not seen ' . $staleDays . '+ days', 'warning'];
        } elseif ($contact === false) {
            [$state, $label, $class] = ['unknown', 'Enrolled', 'secondary'];
        } else {
            [$state, $label, $class] = ['ok', 'OK', 'success'];
        }

        return ['state' => $state, 'label' => $label, 'class' => $class, 'lost' => $lost, 'noncompliant' => $noncompliant, 'stale' => $stale, 'removed' => $removed];
    }

    /** Free storage as a whole percent, or null when the device did not report both numbers (the unit is not needed, only the ratio). */
    public static function storageFreePercent(array $row): ?int
    {
        $total = (float) ($row['storage_total'] ?? 0);
        $free = $row['storage_available'] ?? null;
        if ($total <= 0 || $free === null || $free === '') {
            return null;
        }

        return (int) max(0, min(100, round((float) $free / $total * 100)));
    }

    /**
     * A small, user-free status summary per asset id for places that only want to show "how is this device doing" (the training kiosk device
     * list). Defensive: any failure (tables not installed, integration off) is an empty result, never an error.
     *
     * @param list<int> $assetIds
     * @return array<int,array{state:string,label:string,class:string,battery:?int,last_contact:?string,platform:string}>
     */
    public static function forAssets(\mysqli $db, array $assetIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        try {
            $res = mysqli_query($db, 'SELECT l.asset_id, l.platform, l.is_lost_mode, l.passcode_compliant, l.is_rooted, l.enrollment_status, l.last_contact_at, l.battery_level, i.stale_days
                FROM asset_mdm_links l JOIN manageengine_mdm_integrations i ON i.id = l.integration_id AND i.enabled = 1 WHERE l.asset_id IN (' . implode(',', $ids) . ')');
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        while ($res && ($r = mysqli_fetch_assoc($res))) {
            $st = self::classify($r, (int) $r['stale_days']);
            $out[(int) $r['asset_id']] = ['state' => $st['state'], 'label' => $st['label'], 'class' => $st['class'],
                'battery' => $r['battery_level'] === null ? null : (int) $r['battery_level'], 'last_contact' => $r['last_contact_at'], 'platform' => (string) $r['platform']];
        }

        return $out;
    }
}
