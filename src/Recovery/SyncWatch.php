<?php

namespace ITFlow\Recovery;

/**
 * Alerts for integration syncs that stopped running or keep failing: RMM (rmm_sync_log), Intune (intune_sync_log) and UniFi
 * (unifi_sync_log). A source is "stale" when it has no run in 3x its interval and "failing" when its last N runs all errored
 * (N = recovery setting sync_error_threshold, default 3). One alert per integration and kind per 6 hours (RecoveryAlerts).
 *
 * assess() and inferInterval() are pure so the rules are tested without a database.
 */
final class SyncWatch
{
    public const STALE_FACTOR = 3;
    private const STUCK_RUNNING_SECONDS = 3600;

    /** Pure. A run is an error when it failed, or has been "running" for over an hour with no finish. */
    public static function runErrored(array $run, int $nowTs): bool
    {
        $status = strtolower((string) ($run['status'] ?? ''));
        if (in_array($status, ['failed', 'error', 'errored'], true)) {
            return true;
        }

        return $status === 'running' && empty($run['finished']) && ($nowTs - (int) ($run['started'] ?? $nowTs)) > self::STUCK_RUNNING_SECONDS;
    }

    /**
     * Pure. Median gap in minutes between scheduled runs, from run start times (newest first), or null when there are too few
     * to tell. Floored at 5 minutes.
     *
     * @param list<int> $startsDesc
     */
    public static function inferInterval(array $startsDesc): ?int
    {
        $startsDesc = array_values($startsDesc);
        if (count($startsDesc) < 3) {
            return null;
        }
        $gaps = [];
        for ($i = 0; $i + 1 < count($startsDesc); $i++) {
            $gaps[] = max(0, $startsDesc[$i] - $startsDesc[$i + 1]);
        }
        sort($gaps);
        $median = $gaps[intdiv(count($gaps), 2)];

        return max(5, (int) round($median / 60));
    }

    /**
     * Pure.
     *
     * @param list<array{status:string,started:int,finished:?int}> $runs newest first
     * @return array{state:string,detail:string} state: ok | stale | failing
     */
    public static function assess(array $runs, ?int $createdTs, int $nowTs, ?int $intervalMin, int $errorThreshold): array
    {
        $errorThreshold = max(1, $errorThreshold);
        if (count($runs) >= $errorThreshold) {
            $allBad = true;
            for ($i = 0; $i < $errorThreshold; $i++) {
                if (!self::runErrored($runs[$i], $nowTs)) {
                    $allBad = false;
                    break;
                }
            }
            if ($allBad) {
                return ['state' => 'failing', 'detail' => "the last $errorThreshold runs all ended in an error"];
            }
        }
        if ($intervalMin === null || $intervalMin <= 0) {
            return ['state' => 'ok', 'detail' => 'staleness watch off'];
        }
        $limit = self::STALE_FACTOR * $intervalMin * 60;
        if ($runs === []) {
            if ($createdTs !== null && ($nowTs - $createdTs) > $limit) {
                return ['state' => 'stale', 'detail' => 'it has never run since it was set up'];
            }

            return ['state' => 'ok', 'detail' => 'no runs yet'];
        }
        $lastStart = (int) $runs[0]['started'];
        if (($nowTs - $lastStart) > $limit) {
            return ['state' => 'stale', 'detail' => 'no run for ' . self::human($nowTs - $lastStart) . ' (expected every ' . $intervalMin . ' min)'];
        }

        return ['state' => 'ok', 'detail' => 'last run ' . self::human($nowTs - $lastStart) . ' ago'];
    }

    public static function human(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 5400) {
            return max(1, (int) round($seconds / 60)) . ' min';
        }

        return $seconds < 172800 ? round($seconds / 3600, 1) . ' h' : round($seconds / 86400, 1) . ' days';
    }

    /**
     * @return list<array{source:string,id:int,name:string,state:string,detail:string,alerted:bool}> only the problem ones
     */
    public static function run(\mysqli $db, bool $rmmModuleOn = true): array
    {
        $res = @mysqli_query($db, 'SELECT UNIX_TIMESTAMP() AS n');
        $nowTs = $res ? (int) (mysqli_fetch_assoc($res)['n'] ?? time()) : time();
        $threshold = RecoverySettings::int($db, 'sync_error_threshold');

        $sources = [
            ['rmm', 'RMM', 'rmm_sync_log', 'integration_id',
                $rmmModuleOn ? "SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM rmm_integrations WHERE enabled = 1 AND type <> 'rivetit_agent'" : null],
            ['intune', 'Intune', 'intune_sync_log', 'microsoft_integration_id',
                "SELECT microsoft_integration_id AS id, 'Microsoft 365' AS name, NULL AS created FROM microsoft_integrations WHERE enabled = 1 AND intune_sync_enabled = 1"],
            ['unifi', 'UniFi', 'unifi_sync_log', 'integration_id',
                'SELECT id, name, UNIX_TIMESTAMP(created_at) AS created FROM unifi_integrations WHERE enabled = 1'],
        ];
        $problems = [];
        foreach ($sources as [$key, $label, $logTable, $idCol, $listSql]) {
            if ($listSql === null) {
                continue;
            }
            $list = @mysqli_query($db, $listSql);
            while ($list && ($intg = mysqli_fetch_assoc($list))) {
                $id = (int) $intg['id'];
                $runs = [];
                $q = @mysqli_query($db, "SELECT status, UNIX_TIMESTAMP(started_at) AS s, UNIX_TIMESTAMP(finished_at) AS f FROM `$logTable` WHERE `$idCol` = $id ORDER BY id DESC LIMIT 10");
                while ($q && ($r = mysqli_fetch_assoc($q))) {
                    $runs[] = ['status' => (string) $r['status'], 'started' => (int) $r['s'], 'finished' => $r['f'] === null ? null : (int) $r['f']];
                }
                $setting = RecoverySettings::get($db, 'sync_interval_' . $key);
                if ($setting === 'auto') {
                    $interval = self::inferInterval(array_column($runs, 'started'));
                } else {
                    $interval = (int) $setting;
                }
                $a = self::assess($runs, $intg['created'] === null ? null : (int) $intg['created'], $nowTs, $interval, $threshold);
                $alertKey = "sync.$key.$id.";
                if ($a['state'] === 'ok') {
                    RecoveryAlerts::clear($db, $alertKey . 'failing');
                    RecoveryAlerts::clear($db, $alertKey . 'stale');
                    continue;
                }
                $name = (string) $intg['name'];
                $subject = "$label sync " . ($a['state'] === 'failing' ? 'is failing' : 'has stopped') . " ($name): " . $a['detail'];
                $sent = RecoveryAlerts::raise($db, $alertKey . $a['state'], substr($subject, 0, 220),
                    $subject . ".\n\nOpen Admin > Integrations and run a manual sync to see the error. If the cron job itself stopped, check Admin > Server Status.",
                    $a['state'] === 'failing' ? 'integration.sync_failed' : 'integration.sync_stale',
                    ['source' => $key, 'integration_id' => $id, 'name' => $name, 'detail' => $a['detail']], '/admin/settings_integrations.php');
                $problems[] = ['source' => $key, 'id' => $id, 'name' => $name, 'state' => $a['state'], 'detail' => $a['detail'], 'alerted' => $sent];
            }
        }

        return $problems;
    }
}
