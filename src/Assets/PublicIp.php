<?php

declare(strict_types=1);

namespace ITFlow\Assets;

/**
 * The public (WAN) address a device reaches the internet from. Two sources: the RMM sync (Tactical reports `public_ip`, stored on
 * assets.asset_public_ip) and the RivetIT agent (the address its last check-in came from, endpoint_agent_devices.last_ip). Only a
 * public address counts: a private/reserved one (a server on the same LAN as the agent) says nothing about the internet side.
 */
final class PublicIp
{
    /** An agent that has not checked in for this long no longer says where the site is today. */
    private const AGENT_FRESH_DAYS = 30;

    public static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return array{ip:string,source:string}|null */
    public static function forAsset(\mysqli $db, int $assetId): ?array
    {
        $r = mysqli_query($db, "SELECT asset_public_ip FROM assets WHERE asset_id = $assetId");
        $ip = (string) (mysqli_fetch_assoc($r)['asset_public_ip'] ?? '');
        if ($ip !== '' && self::isPublic($ip)) {
            return ['ip' => $ip, 'source' => 'RMM'];
        }
        $d = mysqli_query($db, "SELECT last_ip FROM endpoint_agent_devices WHERE asset_id = $assetId AND revoked_at IS NULL AND retired_at IS NULL
            AND last_checkin_at > DATE_SUB(NOW(), INTERVAL " . self::AGENT_FRESH_DAYS . " DAY) ORDER BY last_checkin_at DESC LIMIT 1");
        $ip = (string) (($d ? mysqli_fetch_assoc($d) : null)['last_ip'] ?? '');
        if ($ip !== '' && self::isPublic($ip)) {
            return ['ip' => $ip, 'source' => 'agent'];
        }

        return null;
    }

    /**
     * Public addresses the devices at a location report, most common first.
     * @return array<string,int> ip => number of devices
     */
    public static function seenAtLocation(\mysqli $db, int $locationId): array
    {
        $ids = [];
        $r = mysqli_query($db, "SELECT asset_id FROM assets WHERE asset_location_id = $locationId AND asset_archived_at IS NULL");
        while ($r && ($row = mysqli_fetch_assoc($r))) {
            $ids[] = (int) $row['asset_id'];
        }
        $seen = [];
        foreach ($ids as $id) {
            $p = self::forAsset($db, $id);
            if ($p !== null) {
                $seen[$p['ip']] = ($seen[$p['ip']] ?? 0) + 1;
            }
        }
        arsort($seen);

        return $seen;
    }

    /** @return list<string> the addresses typed into a location's WAN IP field */
    public static function parseList(string $stored): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $stored))));
    }
}
